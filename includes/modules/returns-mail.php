<?php
/**
 * 退換貨通知信（v25.8.152）。比照外掛既有慣例：純文字 wp_mail＋字串組裝（沒有 WC_Email 子類別的先例）。
 * 顧客：已收到申請／已核准（帶寄回地址與說明）／已拒絕（帶理由）／已收到退貨／換貨完成。
 * 管理員：有新申請。開關與管理員信箱在 WooCommerce → 設定 → 「退換貨」。
 * **退款完成不寄信**：建立 WooCommerce 退款時，WooCommerce 原生的「已退款的訂單」信件會寄給顧客，再寄一封內容重疊（v25.8.152 拿掉）。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** 顧客在會員中心看這筆申請的網址 */
function twshop_returns_customer_view_url( $return_id ) {
    return add_query_arg( 'view', (int) $return_id, wc_get_endpoint_url( 'returns', '', wc_get_page_permalink( 'myaccount' ) ) );
}

function twshop_returns_items_text( array $row ) {
    $lines = array();
    foreach ( $row['items'] as $it ) $lines[] = sprintf( '・%s × %d', $it['name'] ?? '', (int) ( $it['qty'] ?? 0 ) );
    return implode( "\n", $lines );
}

function twshop_returns_send( $to, $subject, $body ) {
    if ( ! is_email( $to ) ) return false;
    $subject = sprintf( '[%s] %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $subject );
    return wp_mail( $to, $subject, $body );
}

/**
 * 寄信。$event：submitted、approved、rejected、received、exchanged。
 * submitted 同時寄顧客確認與管理員通知；其餘只寄顧客。
 */
function twshop_returns_send_mail( $return_id, $event ) {
    $row = twshop_returns_get( $return_id );
    if ( ! $row ) return;
    $order = wc_get_order( $row['order_id'] );
    if ( ! $order instanceof WC_Order ) return;

    $type_label = twshop_returns_type_label( $row['type'] );
    $name       = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: '顧客';
    $order_no   = $order->get_order_number();
    $items_text = twshop_returns_items_text( $row );
    $view_url   = twshop_returns_customer_view_url( $return_id );

    if ( 'submitted' === $event && 'yes' === twshop_option( 'wc_returns_notify_admin' ) ) {
        $admin_to = trim( (string) twshop_option( 'wc_returns_admin_email' ) ) ?: get_option( 'admin_email' );
        twshop_returns_send(
            $admin_to,
            sprintf( '新的%s申請 #%d（訂單 #%s）', $type_label, $return_id, $order_no ),
            sprintf(
                "有一筆新的%s申請：\n\n申請編號：#%d\n訂單：#%s\n顧客：%s\n原因：%s\n商品：\n%s\n\n請到訂單頁審核：\n%s",
                $type_label, $return_id, $order_no, $name, $row['reason'], $items_text, $order->get_edit_order_url()
            )
        );
    }

    if ( 'yes' !== twshop_option( 'wc_returns_notify_customer' ) ) return;

    $customer_to = $order->get_billing_email();
    $head        = sprintf( "%s 您好，\n\n", $name );
    $foot        = sprintf( "\n\n查看申請進度：\n%s", $view_url );

    switch ( $event ) {
        case 'submitted':
            $subject = sprintf( '已收到您的%s申請 #%d', $type_label, $return_id );
            $body    = $head . sprintf( "我們已收到您對訂單 #%s 的%s申請，審核後會再通知您。\n\n申請商品：\n%s", $order_no, $type_label, $items_text ) . $foot;
            break;
        case 'approved':
            $subject = sprintf( '您的%s申請 #%d 已核准', $type_label, $return_id );
            $body    = $head . sprintf( "您對訂單 #%s 的%s申請已核准。", $order_no, $type_label );
            if ( '' !== trim( (string) $row['admin_note'] ) ) $body .= "\n\n店家回覆：\n" . $row['admin_note'];
            $instructions = trim( (string) twshop_option( 'wc_returns_instructions' ) );
            if ( '' !== $instructions ) $body .= "\n\n寄回地址與說明：\n" . $instructions;
            $body .= "\n\n寄出商品後，請到下方連結填寫物流公司與單號。" . $foot;
            break;
        case 'rejected':
            $subject = sprintf( '您的%s申請 #%d 未能核准', $type_label, $return_id );
            $body    = $head . sprintf( "很抱歉，您對訂單 #%s 的%s申請未能核准。", $order_no, $type_label );
            if ( '' !== trim( (string) $row['reject_reason'] ) ) $body .= "\n\n原因：\n" . $row['reject_reason'];
            $body .= $foot;
            break;
        case 'received':
            $subject = sprintf( '已收到您寄回的商品（申請 #%d）', $return_id );
            $body    = $head . sprintf( "我們已收到您寄回的商品，確認後會盡快為您處理%s。", 'exchange' === $row['type'] ? '換貨' : '退款' ) . $foot;
            break;
        case 'exchanged':
            $subject = sprintf( '您的換貨已處理（申請 #%d）', $return_id );
            $body    = $head . sprintf( "您對訂單 #%s 的換貨申請已處理完成。", $order_no );
            if ( '' !== trim( (string) $row['admin_note'] ) ) $body .= "\n\n店家回覆：\n" . $row['admin_note'];
            $body .= $foot;
            break;
        default:
            return;
    }
    twshop_returns_send( $customer_to, $subject, $body );
}

add_action( 'twshop_returns_created', function ( $id ) { twshop_returns_send_mail( $id, 'submitted' ); } );
add_action( 'twshop_returns_status_changed', function ( $id, $to ) {
    if ( in_array( $to, array( 'approved', 'rejected', 'received', 'exchanged' ), true ) ) twshop_returns_send_mail( $id, $to );
}, 10, 2 );
