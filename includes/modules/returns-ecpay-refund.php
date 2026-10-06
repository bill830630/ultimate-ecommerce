<?php
/**
 * 退換貨：綠界信用卡退刷（v25.8.153）。
 *
 * 建立 WooCommerce 退款後，若訂單是綠界信用卡（一次付清／分期）付款，就呼叫綠界
 * CreditDetail/DoAction 同步退刷。結果以 array( 'status' => 'skipped|ok|failed', 'message' => … )
 * 回傳給 twshop_returns_do_refund()，失敗不影響 WC 退款記錄，只在備註提示手動處理。
 *
 * 動作（Action）：N 放棄授權（未關帳、全額）／R 退刷（已關帳）／C 關帳。
 * 不查交易狀態（查詢 API 需要廠商後台的 CreditCheckCode），改依序嘗試：
 *   全額：N → R；部分：R → C（關帳）後再 R。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** 支援自動退刷的綠界 gateway id → 是否為分期（分期只能全額退）。 */
function twshop_returns_ecpay_card_gateways() {
    return array(
        'Wooecpay_Gateway_Credit'             => false,
        'Wooecpay_Gateway_Credit_Installment' => true,
    );
}

/**
 * 取得這張訂單的信用卡退刷所需資料；不是綠界信用卡訂單回傳 null。
 * TradeNo／MerchantTradeNo 存在綠界外掛自訂表，不在訂單 meta。
 */
function twshop_returns_ecpay_card_context( $order ) {
    if ( ! $order instanceof WC_Order ) return null;
    $gateways = twshop_returns_ecpay_card_gateways();
    $method   = $order->get_payment_method();
    if ( ! isset( $gateways[ $method ] ) ) return null;
    if ( ! class_exists( '\Helpers\Payment\Wooecpay_Payment_Helper' ) || ! class_exists( '\Ecpay\Sdk\Factories\Factory' ) ) {
        return array( 'error' => '找不到綠界外掛（或已停用），無法自動退刷。' );
    }

    $helper = new \Helpers\Payment\Wooecpay_Payment_Helper();
    $trade_no = (string) $helper->get_ecpay_order_payment_field( $order->get_id(), 'TradeNo' );
    $mtn      = (string) $helper->get_ecpay_order_payment_field( $order->get_id(), 'MerchantTradeNo' );
    if ( '' === $trade_no || '' === $mtn ) return array( 'error' => '訂單上找不到綠界交易編號（TradeNo），無法自動退刷。' );

    $info = $helper->get_ecpay_payment_api_info();
    if ( empty( $info['merchant_id'] ) || empty( $info['hashKey'] ) || empty( $info['hashIv'] ) ) return array( 'error' => '綠界金流憑證未設定。' );

    return array(
        'merchant_id' => $info['merchant_id'],
        'hash_key'    => $info['hashKey'],
        'hash_iv'     => $info['hashIv'],
        'trade_no'    => $trade_no,
        'mtn'         => $mtn,
        'installment' => $gateways[ $method ],
        'stage'       => 'yes' === get_option( 'wooecpay_enabled_payment_stage', 'yes' ),
    );
}

/** 呼叫一次 DoAction。回傳 array( ok, code, msg )。 */
function twshop_returns_ecpay_do_action( array $ctx, $action, $amount ) {
    // 讓測試或其他實作在送出前攔截（回傳 array( ok, code, msg ) 就不打 API）。
    $pre = apply_filters( 'twshop_returns_ecpay_pre_action', null, $ctx, $action, $amount );
    if ( is_array( $pre ) ) return $pre;

    $url   = ( $ctx['stage'] ? 'https://payment-stage.ecpay.com.tw' : 'https://payment.ecpay.com.tw' ) . '/CreditDetail/DoAction';
    $input = array(
        'MerchantID'      => $ctx['merchant_id'],
        'MerchantTradeNo' => $ctx['mtn'],
        'TradeNo'         => $ctx['trade_no'],
        'Action'          => $action,
        'TotalAmount'     => (int) round( $amount ),
    );
    try {
        $factory = new \Ecpay\Sdk\Factories\Factory( array( 'hashKey' => $ctx['hash_key'], 'hashIv' => $ctx['hash_iv'] ) );
        $res     = $factory->create( 'PostWithCmvEncodedStrResponseService' )->post( $input, $url );
    } catch ( \Throwable $e ) {
        return array( 'ok' => false, 'code' => '', 'msg' => '連線或簽章失敗：' . $e->getMessage() );
    }
    $code = isset( $res['RtnCode'] ) ? (string) $res['RtnCode'] : '';
    $msg  = isset( $res['RtnMsg'] ) ? (string) $res['RtnMsg'] : '（無回應訊息）';
    return array( 'ok' => '1' === $code, 'code' => $code, 'msg' => $msg );
}

/** 台北時間 20:15–20:30 是綠界每日自動關帳，官方建議避開。 */
function twshop_returns_ecpay_in_settlement_window() {
    $now = (int) wp_date( 'Hi', null, new DateTimeZone( 'Asia/Taipei' ) );
    return $now >= 2015 && $now <= 2030;
}

/**
 * 預設退款執行器（掛在 twshop_returns_refund_executor）。
 * $result 已被別的執行器處理過就原樣放行。
 */
function twshop_returns_ecpay_refund_executor( $result, $order, $amount, $refund ) {
    if ( null !== $result ) return $result;
    $ctx = twshop_returns_ecpay_card_context( $order );
    if ( null === $ctx ) return array( 'status' => 'skipped', 'message' => '' );
    if ( isset( $ctx['error'] ) ) return array( 'status' => 'failed', 'message' => $ctx['error'] );

    $total   = (float) $order->get_total();
    $prior   = (float) $order->get_total_refunded() - (float) $amount; // 這次之前已退的金額
    $is_full = abs( $amount - $total ) < 0.5 && $prior < 0.5;

    if ( $ctx['installment'] && ! $is_full ) {
        return array( 'status' => 'failed', 'message' => '分期付款訂單只能全額退刷，無法部分退刷。' );
    }
    if ( twshop_returns_ecpay_in_settlement_window() ) {
        return array( 'status' => 'failed', 'message' => '現在是綠界每日關帳時段（20:15–20:30），為避免出錯未自動退刷。' );
    }

    $trace = array();
    $try   = function ( $action, $amt ) use ( $ctx, &$trace ) {
        $r = twshop_returns_ecpay_do_action( $ctx, $action, $amt );
        $trace[] = sprintf( '%s：%s%s', $action, $r['ok'] ? '成功' : '失敗', $r['msg'] ? '（' . $r['msg'] . '）' : '' );
        return $r['ok'];
    };

    $ok = false;
    if ( $is_full ) {
        $ok = $try( 'N', $total ) || $try( 'R', $amount );
    } else {
        $ok = $try( 'R', $amount );
        if ( ! $ok && $try( 'C', $total ) ) $ok = $try( 'R', $amount );
    }

    $detail = implode( '；', $trace );
    return $ok
        ? array( 'status' => 'ok', 'message' => sprintf( '綠界信用卡已退刷 %s（%s）。', twshop_plain_price( $amount ), $detail ) )
        : array( 'status' => 'failed', 'message' => '綠界信用卡退刷失敗（' . $detail . '）。' );
}
add_filter( 'twshop_returns_refund_executor', 'twshop_returns_ecpay_refund_executor', 10, 4 );
