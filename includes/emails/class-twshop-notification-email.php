<?php
/** 獨立 ID／設定的 WooCommerce 原生通知信，共用安全的 HTML 範本。 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Twshop_Notification_Email extends WC_Email {
    protected const NOTIFICATION_ID = '';
    private $definition;
    private $notification_body = '';
    private $notification_context = array();
    private $has_notification = false;

    public function __construct( $id = null, array $definition = array() ) {
        $id = $id ?? static::NOTIFICATION_ID;
        if ( ! $definition ) $definition = twshop_notification_definitions()[ $id ];
        $this->id = $id;
        $this->definition = $definition;
        $this->title = '終極電商｜' . $definition['title'];
        $this->description = '通知開關與信件文案在此統一管理。需啟用對應功能並符合通知條件才會寄送；贈禮、效期、點數提醒天數仍在終極電商設定。';
        if ( 'twshop_returns_cancel_done' === $id ) $this->description .= ' 僅在 WooCommerce 原生退款信不會寄出時補寄，避免重複通知。';
        $this->customer_email = empty( $definition['admin'] );
        $this->template_html = 'emails/notification.php';
        $this->template_base = TWSHOP_PLUGIN_DIR . 'templates/';
        $this->placeholders = array_fill_keys( array( '{name}', '{type}', '{return_id}', '{order_number}', '{items}', '{reason}', '{admin_note}', '{reject_reason}', '{instructions}', '{view_url}' ), '' );
        parent::__construct();
        $this->email_type = 'html';
        if ( ! $this->customer_email ) $this->recipient = $this->get_option( 'recipient', $this->legacy_recipient() );
    }

    private function legacy_recipient() {
        return trim( (string) get_option( 'wc_returns_admin_email', '' ) ) ?: get_option( 'admin_email' );
    }

    public function get_default_subject() {
        $defaults = twshop_get_option_defaults();
        return $this->definition['subject_default'] ?? ( $defaults[ $this->definition['subject_key'] ?? '' ] ?? $this->definition['title'] );
    }

    public function get_default_heading() { return $this->definition['title']; }

    public function get_theme_template_file( $template ) {
        return get_stylesheet_directory() . '/ultimate-ecommerce/' . $template;
    }

    public function admin_options() {
        $directory = static function ( $path, $template ) { return 'emails/notification.php' === $template ? 'ultimate-ecommerce' : $path; };
        add_filter( 'woocommerce_template_directory', $directory, 10, 2 );
        try { parent::admin_options(); }
        finally { remove_filter( 'woocommerce_template_directory', $directory, 10 ); }
    }

    public function init_form_fields() {
        $enabled_key = $this->definition['enabled_key'] ?? '';
        $this->form_fields = array(
            'enabled' => array( 'title' => '啟用／停用', 'type' => 'checkbox', 'label' => '啟用此電子郵件通知', 'default' => $enabled_key ? get_option( $enabled_key, 'yes' ) : 'yes' ),
            'subject' => array( 'title' => '主旨', 'type' => 'text', 'default' => twshop_notification_legacy_default( $this->definition, 'subject' ), 'placeholder' => $this->get_default_subject(), 'description' => '留空使用預設主旨。可用 {site_title}；退換貨信另可用 {type}、{return_id}、{order_number}。' ),
            'heading' => array( 'title' => '信件標題', 'type' => 'text', 'default' => '', 'placeholder' => $this->get_default_heading() ),
            'body' => array( 'title' => '信件內文', 'type' => 'textarea', 'css' => 'width:100%; min-height:160px;', 'default' => twshop_notification_legacy_default( $this->definition, 'body' ), 'description' => '以文字編輯，系統會套用 HTML 明細表與按鈕。可用變數：' . $this->definition['variables'] ),
        );
        if ( ! $this->customer_email ) {
            $this->form_fields['recipient'] = array( 'title' => '收件人', 'type' => 'text', 'default' => $this->legacy_recipient(), 'description' => '多個 Email 請用逗號分隔。' );
        }
    }

    public function trigger( $to, $subject, $body, array $context = array() ) {
        $this->setup_locale();
        try {
            // 每次重新載入，長時間執行的排程亦遵守最新停用設定。
            $this->init_settings();
            $this->enabled = $this->get_option( 'enabled', 'yes' );
            $this->recipient = $this->customer_email ? $to : $this->get_option( 'recipient', $this->legacy_recipient() );
            $this->notification_body = $body;
            $this->notification_context = $context;
            $this->has_notification = true;
            foreach ( array( 'name', 'type', 'return_id', 'order_number', 'items', 'reason', 'admin_note', 'reject_reason', 'instructions', 'view_url' ) as $key ) {
                $this->placeholders[ '{' . $key . '}' ] = (string) ( $context['variables'][ $key ] ?? '' );
            }
            $this->object = ! empty( $context['order_id'] ) ? wc_get_order( $context['order_id'] ) : false;
            if ( ! $this->is_enabled() ) return false;
            foreach ( array_map( 'trim', explode( ',', $this->get_recipient() ) ) as $address ) {
                if ( ! is_email( $address ) ) return false;
            }
            return $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content_html(), $this->get_headers(), $this->get_attachments() );
        } finally {
            $this->restore_locale();
        }
    }

    public function get_content_html() {
        $body = $this->notification_body;
        $context = $this->notification_context;
        if ( ! $this->has_notification ) {
            // 原生管理／預覽尚未觸發通知時，只使用明確的示範資料。
            $template = method_exists( $this, 'get_option_or_transient' ) ? $this->get_option_or_transient( 'body', '' ) : $this->get_option( 'body', '' );
            $body = strtr( $template, array(
                '{message}' => '這是通知信內容預覽，實際寄送時會帶入對應申請資訊。',
                '{name}' => '範例會員', '{codes}' => '【DEMO-COUPON】專屬優惠', '{days}' => '30',
                '{tier}' => '金卡會員', '{amount}' => '500', '{balance}' => '1,500',
                '{term}' => twshop_points_term(), '{date}' => wp_date( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ),
                '{order_id}' => 'DEMO-123', '{type}' => '退貨', '{return_id}' => '42',
                '{order_number}' => 'DEMO-123', '{items}' => '範例商品 × 1',
                '{reason}' => '範例申請原因', '{admin_note}' => '範例店家回覆', '{reject_reason}' => '範例未核准原因',
                '{instructions}' => '範例寄回地址與說明', '{view_url}' => wc_get_page_permalink( 'myaccount' ),
            ) );
            $context = array( 'title' => $this->definition['title'] );
        }
        if ( $this->has_notification && empty( $this->definition['body_key'] ) ) {
            $template = $this->get_option( 'body', '{message}' );
            $body = strtr( $template, array( '{message}' => $body ) + $this->placeholders );
        }
        return wc_get_template_html( $this->template_html, array(
            'email_heading' => $this->get_heading(), 'body' => $body, 'context' => $context, 'email' => $this,
        ), 'ultimate-ecommerce/', $this->template_base );
    }
}

// 獨立 class 讓原生預覽／測試寄送能選到正確通知，實際行為共用上述基底類別。
class Twshop_Email_Birthday extends Twshop_Notification_Email { protected const NOTIFICATION_ID = 'twshop_birthday'; }
class Twshop_Email_Upgrade_Gift extends Twshop_Notification_Email { protected const NOTIFICATION_ID = 'twshop_upgrade_gift'; }
class Twshop_Email_Upgrade extends Twshop_Notification_Email { protected const NOTIFICATION_ID = 'twshop_upgrade'; }
class Twshop_Email_Tier_Change extends Twshop_Notification_Email { protected const NOTIFICATION_ID = 'twshop_tier_change'; }
class Twshop_Email_Points_Expiry extends Twshop_Notification_Email { protected const NOTIFICATION_ID = 'twshop_points_expiry'; }
class Twshop_Email_Wallet_Topup extends Twshop_Notification_Email { protected const NOTIFICATION_ID = 'twshop_wallet_topup'; }
class Twshop_Email_Returns_Admin extends Twshop_Notification_Email { protected const NOTIFICATION_ID = 'twshop_returns_admin'; }
class Twshop_Email_Returns_Submitted extends Twshop_Notification_Email { protected const NOTIFICATION_ID = 'twshop_returns_submitted'; }
class Twshop_Email_Returns_Approved extends Twshop_Notification_Email { protected const NOTIFICATION_ID = 'twshop_returns_approved'; }
class Twshop_Email_Returns_Rejected extends Twshop_Notification_Email { protected const NOTIFICATION_ID = 'twshop_returns_rejected'; }
class Twshop_Email_Returns_Received extends Twshop_Notification_Email { protected const NOTIFICATION_ID = 'twshop_returns_received'; }
class Twshop_Email_Returns_Exchanged extends Twshop_Notification_Email { protected const NOTIFICATION_ID = 'twshop_returns_exchanged'; }
class Twshop_Email_Returns_Cancel_Done extends Twshop_Notification_Email { protected const NOTIFICATION_ID = 'twshop_returns_cancel_done'; }
