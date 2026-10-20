<?php
/**
 * 退換貨通知信。內容以純文字組裝，寄送時轉為 HTML，套用 WooCommerce 信件範本與內嵌樣式。
 * 顧客：已收到申請／已核准（帶寄回地址與說明）／已拒絕（帶理由）／已收到退貨／換貨完成。
 * 管理員：有新申請。各封開關與管理員信箱在 WooCommerce → 設定 → 電子郵件。
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

function twshop_returns_send( $to, $subject, $body, array $context = array() ) {
    $subject = sprintf( '[%s] %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $subject );
    // 舊版直接呼叫此 helper 的程式維持相容；各實際退換貨事件皆有獨立 email_id。
    if ( empty( $context['email_id'] ) ) return twshop_send_email( $to, $subject, $body, $context );
    return twshop_send_notification( $context['email_id'], $to, $subject, $body, $context );
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
    $mail_context = array(
        'email_id'     => 'twshop_returns_' . $event,
        'order_id'     => $order->get_id(),
        'variables'    => array(
            'name' => $name, 'type' => $type_label, 'return_id' => $return_id, 'order_number' => $order_no, 'items' => $items_text,
            'reason' => $row['reason'], 'admin_note' => $row['admin_note'], 'reject_reason' => $row['reject_reason'],
            'instructions' => twshop_option( 'wc_returns_instructions' ), 'view_url' => $view_url,
        ),
        'title'        => $type_label . '申請通知',
        'details'      => array( '申請編號' => '#' . $return_id, '訂單編號' => '#' . $order_no, '申請類型' => $type_label ),
        'action_label' => '查看申請進度',
        'action_url'   => $view_url,
    );

    if ( 'submitted' === $event ) {
        $admin_to = trim( (string) twshop_option( 'wc_returns_admin_email' ) ) ?: get_option( 'admin_email' );
        twshop_returns_send(
            $admin_to,
            sprintf( '新的%s申請 #%d（訂單 #%s）', $type_label, $return_id, $order_no ),
            sprintf(
                "有一筆新的%s申請：\n\n申請編號：#%d\n訂單：#%s\n顧客：%s\n原因：%s\n商品：\n%s\n\n請到訂單頁審核：\n%s",
                $type_label, $return_id, $order_no, $name, $row['reason'], $items_text, $order->get_edit_order_url()
            ),
            array_merge( $mail_context, array( 'email_id' => 'twshop_returns_admin', 'action_label' => '前往訂單審核', 'action_url' => $order->get_edit_order_url() ) )
        );
    }

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
            if ( 'cancel' === $row['type'] ) $body .= "\n\n訂單出貨、收到商品後，您可以改申請退換貨。";
            $body .= $foot;
            break;
        case 'cancel_done':
            $subject = sprintf( '您的訂單 #%s 已取消', $order_no );
            $body    = $head . sprintf( "您申請取消的訂單 #%s 已核准並取消，款項會退回原付款方式（實際入帳時間依付款方式而定）。", $order_no ) . $foot;
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
    twshop_returns_send( $customer_to, $subject, $body, $mail_context );
}

/**
 * 這筆取消申請核准時，WooCommerce 原生的「已退款的訂單」信件會不會寄給顧客（有建立退款，且該封信件有開啟）。
 * 會寄的話就不再另外寄「訂單已取消」，兩封講的是同一件事。
 */
function twshop_returns_wc_refund_mail_covers( array $row ) {
    if ( empty( $row['refund_id'] ) ) return false;
    $emails = function_exists( 'WC' ) && WC()->mailer() ? WC()->mailer()->get_emails() : array();
    return isset( $emails['WC_Email_Customer_Refunded_Order'] ) && $emails['WC_Email_Customer_Refunded_Order']->is_enabled();
}

add_action( 'twshop_returns_created', function ( $id ) { twshop_returns_send_mail( $id, 'submitted' ); } );
add_action( 'twshop_returns_status_changed', function ( $id, $to ) {
    if ( 'refunded' === $to ) { // 退貨的退款由 WooCommerce 原生信件通知；取消訂單只在原生退款信不會寄時（沒有退款、或信件被關閉）補寄「訂單已取消」
        $r = twshop_returns_get( $id );
        if ( $r && 'cancel' === $r['type'] && ! twshop_returns_wc_refund_mail_covers( $r ) ) twshop_returns_send_mail( $id, 'cancel_done' );
        return;
    }
    if ( in_array( $to, array( 'approved', 'rejected', 'received', 'exchanged' ), true ) ) twshop_returns_send_mail( $id, $to );
}, 10, 2 );
