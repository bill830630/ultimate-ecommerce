<?php
/**
 * 退換貨：取消訂單時一併取消綠界 7-ELEVEN C2C 物流單（v25.8.156）。
 *
 * 綠界逆物流 API 只支援 B2C 超商與宅配，C2C 超商沒有逆物流，C2C 只有「取消訂單」（限 7-11，
 * CancelC2COrder）。這裡只處理取消訂單申請核准時的 7-11 C2C 物流單；其他物流單（全家／萊爾富／OK／宅配／B2C）
 * 無法自動取消，由 twshop_returns_do_cancel() 在訂單備註提醒管理員到綠界後台處理。
 *
 * API：POST https://logistics{-stage}.ecpay.com.tw/Express/CancelC2COrder（md5 檢查碼，與建立物流單同一組憑證），
 * 回應純文字「1|OK」成功。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 這張訂單的綠界物流單狀況：
 *   none         沒有物流單
 *   supported    7-ELEVEN C2C 物流單，可自動取消（附 logistics_id／payment_no／validation_no 與憑證）
 *   unsupported  有物流單但無法自動取消（reason 說明）
 */
function twshop_returns_ecpay_shipment_info( $order ) {
    if ( ! $order instanceof WC_Order ) return array( 'state' => 'none' );
    $logistics_id = (string) $order->get_meta( '_wooecpay_logistic_AllPayLogisticsID' );
    if ( '' === $logistics_id ) return array( 'state' => 'none' );

    if ( ! class_exists( '\Helpers\Logistic\Wooecpay_Logistic_Helper' ) || ! class_exists( '\Ecpay\Sdk\Factories\Factory' ) ) {
        return array( 'state' => 'unsupported', 'reason' => '找不到綠界外掛' );
    }
    $helper = new \Helpers\Logistic\Wooecpay_Logistic_Helper();
    $method = '';
    foreach ( $order->get_items( 'shipping' ) as $ship ) { $method = $ship->get_method_id(); break; }
    $sub = (string) ( $helper->get_logistics_sub_type( $method )['sub_type'] ?? '' );
    if ( 'UNIMARTC2C' !== $sub ) return array( 'state' => 'unsupported', 'reason' => '不是 7-ELEVEN C2C 物流單' );

    $payment_no    = (string) $order->get_meta( '_wooecpay_logistic_CVSPaymentNo' );
    $validation_no = (string) $order->get_meta( '_wooecpay_logistic_CVSValidationNo' );
    $api           = $helper->get_ecpay_logistic_api_info();
    if ( '' === $payment_no || empty( $api['merchant_id'] ) || empty( $api['hashKey'] ) || empty( $api['hashIv'] ) ) {
        return array( 'state' => 'unsupported', 'reason' => '缺少寄貨編號或物流憑證' );
    }

    return array(
        'state'         => 'supported',
        'logistics_id'  => $logistics_id,
        'payment_no'    => $payment_no,
        'validation_no' => $validation_no,
        'merchant_id'   => $api['merchant_id'],
        'hash_key'      => $api['hashKey'],
        'hash_iv'       => $api['hashIv'],
        'stage'         => 'yes' === get_option( 'wooecpay_enabled_logistic_stage', 'yes' ),
    );
}

/**
 * 取消 7-ELEVEN C2C 物流單。$info 來自 twshop_returns_ecpay_shipment_info()（state=supported）。
 * 回傳 array( status => ok|failed, message )。已經取消過的（meta _twshop_ecpay_shipment_cancelled）直接視為成功。
 */
function twshop_returns_ecpay_cancel_shipment( $order, array $info ) {
    if ( (string) $order->get_meta( '_twshop_ecpay_shipment_cancelled' ) === $info['logistics_id'] ) {
        return array( 'status' => 'ok', 'message' => '綠界物流單先前已取消。' );
    }

    $input = array(
        'MerchantID'        => $info['merchant_id'],
        'AllPayLogisticsID' => $info['logistics_id'],
        'CVSPaymentNo'      => $info['payment_no'],
        'CVSValidationNo'   => $info['validation_no'],
    );

    // 測試接縫：回傳字串（如「1|OK」）就不打 API
    $pre = apply_filters( 'twshop_returns_ecpay_pre_shipment_cancel', null, $order, $input );
    if ( is_string( $pre ) ) {
        $raw = $pre;
    } else {
        $url = ( $info['stage'] ? 'https://logistics-stage.ecpay.com.tw' : 'https://logistics.ecpay.com.tw' ) . '/Express/CancelC2COrder';
        try {
            $factory = new \Ecpay\Sdk\Factories\Factory( array( 'hashKey' => $info['hash_key'], 'hashIv' => $info['hash_iv'], 'hashMethod' => 'md5' ) );
            $res     = $factory->create( 'PostWithCmvStrResponseService' )->post( $input, $url );
            // SDK 回傳 array( 'body' => '1|OK' )（stage 實測）；保險起見也接受純字串
            $raw     = is_array( $res ) ? (string) ( $res['body'] ?? '' ) : (string) $res;
        } catch ( \Throwable $e ) {
            return array( 'status' => 'failed', 'message' => '連線或簽章失敗：' . $e->getMessage() );
        }
    }

    if ( '1|OK' === trim( $raw ) ) {
        $order->update_meta_data( '_twshop_ecpay_shipment_cancelled', $info['logistics_id'] );
        $order->save();
        return array( 'status' => 'ok', 'message' => sprintf( '已取消綠界 7-ELEVEN 物流單（%s）。', $info['logistics_id'] ) );
    }
    // 管理員已經先在綠界後台取消過：綠界回「…取消訂單失敗(訂單已取消)」，這種情況目的已達成，視為成功
    if ( false !== strpos( $raw, '訂單已取消' ) ) {
        $order->update_meta_data( '_twshop_ecpay_shipment_cancelled', $info['logistics_id'] );
        $order->save();
        return array( 'status' => 'ok', 'message' => sprintf( '綠界 7-ELEVEN 物流單先前已取消（%s）。', $info['logistics_id'] ) );
    }
    return array( 'status' => 'failed', 'message' => '綠界回應：' . mb_substr( trim( $raw ), 0, 120 ) );
}
