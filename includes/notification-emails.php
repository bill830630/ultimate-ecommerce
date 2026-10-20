<?php
/** WooCommerce 通知信註冊與舊設定讀取相容層；讀取不寫入資料庫。 */
if ( ! defined( 'ABSPATH' ) ) exit;

function twshop_notification_definitions() {
    $definitions = array(
        'twshop_birthday' => array( 'title' => '生日禮通知', 'subject_key' => 'wc_birthday_email_subject', 'body_key' => 'wc_birthday_email_body', 'body_default' => "親愛的 {name}：\n\n生日快樂！這是系統為您生成的生日禮包：\n{codes}\n\n有效期限為 {days} 天，請至網站查看！", 'variables' => '{name}、{codes}、{days}' ),
        'twshop_upgrade_gift' => array( 'title' => '會員升級禮通知', 'subject_key' => 'wc_upgrade_email_subject', 'body_key' => 'wc_upgrade_email_body_gift', 'body_default' => "恭喜升級！這是您的專屬升級禮包：\n{codes}\n\n請至會員中心查看。", 'variables' => '{codes}' ),
        'twshop_upgrade' => array( 'title' => '會員升級通知（無贈禮）', 'subject_key' => 'wc_upgrade_email_subject_no_gift', 'body_key' => 'wc_upgrade_email_body_no_gift', 'variables' => '{tier}' ),
        'twshop_tier_change' => array( 'title' => '會員等級調整通知', 'subject_key' => 'wc_tier_change_email_subject', 'body_key' => 'wc_tier_change_email_body', 'variables' => '{tier}' ),
        'twshop_points_expiry' => array( 'title' => '點數到期提醒', 'subject_key' => 'wc_points_expiry_notify_subject', 'subject_default' => '您的' . twshop_points_term() . '即將到期', 'body_key' => 'wc_points_expiry_notify_body', 'body_default' => "親愛的 {name}：\n\n您有 {amount} {term}將於 {date} 到期，請把握時間使用！", 'variables' => '{name}、{amount}、{term}、{date}' ),
        'twshop_wallet_topup' => array( 'title' => '儲值成功通知', 'subject_key' => 'wc_wallet_topup_email_subject', 'body_key' => 'wc_wallet_topup_email_body', 'body_default' => "親愛的 {name}：\n\n您的儲值已完成！\n\n本次儲值：NT{amount}\n目前餘額：NT{balance}\n\n感謝您的支持！", 'enabled_key' => 'wc_wallet_topup_email_enabled', 'variables' => '{name}、{amount}、{balance}、{order_id}' ),
    );
    $returns = array(
        'admin'       => array( '新退換貨／取消申請（管理員）', '[{site_title}] 新的{type}申請 #{return_id}（訂單 #{order_number}）' ),
        'submitted'   => array( '退換貨／取消申請已收到', '[{site_title}] 已收到您的{type}申請 #{return_id}' ),
        'approved'    => array( '退換貨申請已核准', '[{site_title}] 您的{type}申請 #{return_id} 已核准' ),
        'rejected'    => array( '退換貨／取消申請未核准', '[{site_title}] 您的{type}申請 #{return_id} 未能核准' ),
        'received'    => array( '退貨商品已收到', '[{site_title}] 已收到您寄回的商品（申請 #{return_id}）' ),
        'exchanged'   => array( '換貨已完成', '[{site_title}] 您的換貨已處理（申請 #{return_id}）' ),
        'cancel_done' => array( '訂單取消完成（補充通知）', '[{site_title}] 您的訂單 #{order_number} 已取消' ),
    );
    foreach ( $returns as $event => $labels ) {
        $definitions[ 'twshop_returns_' . $event ] = array(
            'title' => $labels[0], 'subject_default' => $labels[1], 'body_default' => '{message}',
            'enabled_key' => 'admin' === $event ? 'wc_returns_notify_admin' : 'wc_returns_notify_customer',
            'admin' => 'admin' === $event,
            'variables' => '{message}（系統通知全文）、{name}、{type}、{return_id}、{order_number}、{items}、{reason}、{admin_note}、{reject_reason}、{instructions}、{view_url}',
        );
    }
    return $definitions;
}

/** 原始預設值與舊站已儲存文案，直到各封原生設定第一次儲存前都會沿用。 */
function twshop_notification_legacy_default( array $definition, $field ) {
    $key = $definition[ $field . '_key' ] ?? '';
    $defaults = twshop_get_option_defaults();
    $fallback = $definition[ $field . '_default' ] ?? ( $defaults[ $key ] ?? '' );
    return $key ? get_option( $key, $fallback ) : $fallback;
}

add_filter( 'woocommerce_email_classes', function ( $emails ) {
    require_once TWSHOP_PLUGIN_DIR . 'includes/emails/class-twshop-notification-email.php';
    foreach ( twshop_notification_definitions() as $id => $definition ) {
        // WooCommerce 的預覽以 class 名稱識別信件，因此每封都需要獨立子類別。
        $class = 'Twshop_Email_' . implode( '_', array_map( 'ucfirst', explode( '_', substr( $id, 7 ) ) ) );
        $emails[ $id ] = new $class( $id, $definition );
    }
    return $emails;
} );

add_filter( 'woocommerce_email_preview_email_content_setting_ids', function ( $setting_ids, $email_id ) {
    if ( isset( twshop_notification_definitions()[ $email_id ] ) ) $setting_ids[] = 'woocommerce_' . $email_id . '_body';
    return $setting_ids;
}, 10, 2 );

/** 舊文案組裝程式及已存在的 option 使用新設定值，不維護兩份可編輯來源。 */
function twshop_register_notification_option_bridges() {
    foreach ( twshop_notification_definitions() as $id => $definition ) {
        foreach ( array( 'subject', 'body' ) as $field ) {
            if ( empty( $definition[ $field . '_key' ] ) ) continue;
            add_filter( 'pre_option_' . $definition[ $field . '_key' ], static function ( $pre ) use ( $id, $field, $definition ) {
                if ( false !== $pre ) return $pre;
                $settings = get_option( 'woocommerce_' . $id . '_settings', array() );
                if ( ! is_array( $settings ) || ! array_key_exists( $field, $settings ) ) return $pre;
                if ( 'subject' === $field && '' === $settings[ $field ] ) {
                    $defaults = twshop_get_option_defaults();
                    return $definition['subject_default'] ?? ( $defaults[ $definition['subject_key'] ] ?? '' );
                }
                return $settings[ $field ];
            } );
        }
    }
}
add_action( 'plugins_loaded', 'twshop_register_notification_option_bridges', 5 );

function twshop_send_notification( $id, $to, $subject, $body, array $context = array() ) {
    $emails = WC()->mailer()->get_emails();
    if ( ! isset( $emails[ $id ] ) || ! $emails[ $id ] instanceof Twshop_Notification_Email ) return false;
    return $emails[ $id ]->trigger( $to, $subject, $body, $context );
}

/** 舊設定頁只保留入口，不保留重複的信件欄位。 */
function twshop_notification_settings_link( $id ) {
    $definitions = twshop_notification_definitions();
    if ( ! isset( $definitions[ $id ] ) ) return;
    printf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&section=' . $id ) ), esc_html( $definitions[ $id ]['title'] ) );
}

function twshop_notification_settings_links( array $ids ) {
    echo '<ul>';
    foreach ( $ids as $id ) {
        echo '<li>';
        twshop_notification_settings_link( $id );
        echo '</li>';
    }
    echo '</ul>';
}

add_action( 'woocommerce_admin_field_twshop_notification_links', function () {
    echo '<tr><th scope="row">通知信管理</th><td>';
    $ids = array_filter( array_keys( twshop_notification_definitions() ), static function ( $id ) { return 0 === strpos( $id, 'twshop_returns_' ); } );
    twshop_notification_settings_links( $ids );
    echo '<p class="description">啟用與收件人設定已移至電子郵件管理；退款通知仍由原生「已退款的訂單」信件負責。</p></td></tr>';
} );
