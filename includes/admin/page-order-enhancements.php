<?php
/** WooCommerce 原生設定入口與方式名稱補充。沿用既有 option，不改寫歷史訂單。 */
if ( ! defined( 'ABSPATH' ) ) exit;

function twshop_order_wc_settings_init() {
    add_filter( 'woocommerce_settings_tabs_array', function ( $tabs ) { $tabs['twshop-orders'] = '訂單強化'; return $tabs; }, 49 );
    add_action( 'woocommerce_settings_twshop-orders', function () { woocommerce_admin_fields( twshop_order_wc_fields() ); } );
    add_action( 'woocommerce_update_options_twshop-orders', function () { twshop_order_wc_save( twshop_order_wc_fields() ); } );
    add_filter( 'woocommerce_get_sections_shipping', function ( $sections ) { $sections['twshop-logistics'] = '物流與訂單狀態'; return $sections; } );
    add_filter( 'woocommerce_get_settings_shipping', function ( $settings, $section ) { return 'twshop-logistics' === $section ? twshop_order_logistics_fields() : $settings; }, 10, 2 );
    add_action( 'admin_init', 'twshop_order_register_title_fields', 20 );
}

function twshop_order_settings_description() {
    $text = '';
    if ( ! twshop_module_enabled( 'order_checkout_enhancements' ) ) $text .= ' 訂單強化主開關目前關閉，新功能暫不執行；既有資料仍可查看與處理。';
    return trim( $text );
}

function twshop_order_wc_fields() {
    return array(
        array( 'title' => '訂單強化', 'type' => 'title', 'id' => 'twshop_order_display_section', 'desc' => twshop_order_settings_description() ),
        array( 'title' => '地址與結帳強化', 'id' => 'twshop_order_address_enabled', 'type' => 'checkbox', 'default' => 'yes', 'desc' => '啟用台灣地址連動、超商免填地址與單一國家欄位簡化（各運送方式另行設定）' ),
        array( 'title' => '訂單列表單號', 'id' => 'twshop_order_columns_enabled', 'type' => 'checkbox', 'default' => 'yes', 'desc' => '顯示金流單號、物流單號與地址電話' ),
        array( 'title' => '後台物流面板', 'id' => 'twshop_order_admin_logistics_enabled', 'type' => 'checkbox', 'default' => 'yes', 'desc' => '在原生訂單編輯頁顯示綠界物流資訊' ),
        array( 'title' => '顧客物流資訊', 'id' => 'twshop_order_customer_logistics_enabled', 'type' => 'checkbox', 'default' => 'yes', 'desc' => '在顧客訂單明細顯示物流資訊' ),
        array( 'type' => 'sectionend', 'id' => 'twshop_order_display_section' ),
    );
}

function twshop_order_logistics_fields() {
    return array(
        array( 'title' => '物流與訂單狀態', 'type' => 'title', 'id' => 'twshop_order_logistics_section', 'desc' => twshop_order_settings_description() ),
        array( 'title' => '物流自動更新', 'id' => 'twshop_order_auto_status_enabled', 'type' => 'checkbox', 'default' => 'yes', 'desc' => '依綠界物流備註更新已出貨、配送中、未取件退回與已完成狀態。完成時會觸發消費點數、推薦獎勵、會員等級與退換貨期限；未取件退回不自動取消或退款。關閉後仍可手動操作既有狀態。' ),

        array( 'type' => 'sectionend', 'id' => 'twshop_order_logistics_section' ),
    );
}

function twshop_order_wc_save( $fields ) {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足。' );
    check_admin_referer( 'woocommerce-settings' );
    woocommerce_update_options( $fields );
}

/** 有原生 title 欄位時優先使用；舊補充名稱保留至管理員清空，避免升級改名。 */
function twshop_order_title_field( $fields, $which, $key ) {
    $titles = get_option( 'shipping' === $which ? 'wc_shipping_method_titles' : 'wc_payment_method_titles', array() );
    $old = is_array( $titles ) && isset( $titles[$key] ) && is_scalar( $titles[$key] ) ? (string) $titles[$key] : '';
    if ( isset( $fields['title'] ) && '' === $old ) return $fields;
    $fields['twshop_display_title'] = array(
        'title' => '結帳顯示名稱（補充）', 'type' => 'text', 'default' => $old,
        'description' => isset( $fields['title'] ) ? '沿用先前設定的補充名稱；清空後使用上方原生名稱。只影響新訂單。' : '此方式未提供原生名稱欄位，可在此補充；留空沿用原名稱。只影響新訂單。',
    );
    return $fields;
}

function twshop_order_save_title( $settings, $which, $key, $posted_key, $posted_data = null ) {
    $data = null === $posted_data ? $_POST : $posted_data;
    if ( ! is_array( $data ) || ! isset( $data[$posted_key] ) || ! is_string( $data[$posted_key] ) || ! current_user_can( 'manage_woocommerce' ) ) return $settings;
    // 運送彈窗使用 WC set_post_data()，欄位不在頂層 POST；以 WC 已驗證的 setting 寫回。
    $value = null === $posted_data ? wp_unslash( $data[$posted_key] ) : ( $settings['twshop_display_title'] ?? null );
    if ( ! is_string( $value ) ) return $settings;
    $option = 'shipping' === $which ? 'wc_shipping_method_titles' : 'wc_payment_method_titles';
    $titles = get_option( $option, array() );
    if ( ! is_array( $titles ) ) $titles = array();
    $value = sanitize_text_field( $value );
    if ( '' === $value ) unset( $titles[$key] );
    else $titles[$key] = $value;
    update_option( $option, $titles );
    unset( $settings['twshop_display_title'] );
    return $settings;
}

function twshop_order_register_title_fields() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    static $shipping_registered = array(), $payment_registered = array();
    foreach ( twshop_registered_shipping_method_ids() as $id ) {
        if ( isset( $shipping_registered[$id] ) ) continue;
        $shipping_registered[$id] = true;
        add_filter( 'woocommerce_shipping_instance_form_fields_' . $id, function ( $fields ) use ( $id ) {
            $raw_instance = $_REQUEST['instance_id'] ?? 0;
            $instance = is_scalar( $raw_instance ) ? absint( $raw_instance ) : 0;
            if ( $instance ) return twshop_order_title_field( $fields, 'shipping', $id . ':' . $instance );
            // 運送區域會預先產生所有方式的彈窗，當下沒有 instance_id。
            // 欄位先註冊，值由 instance_option 依實際物件讀取，避免混用其他區域的名稱。
            $titles = get_option( 'wc_shipping_method_titles', array() );
            $has_legacy = false;
            foreach ( is_array( $titles ) ? $titles : array() as $key => $value ) {
                if ( str_starts_with( (string) $key, $id . ':' ) && is_scalar( $value ) && '' !== (string) $value ) { $has_legacy = true; break; }
            }
            if ( isset( $fields['title'] ) && ! $has_legacy ) return $fields;
            $fields = twshop_order_title_field( $fields, 'shipping', $id . ':0' );
            if ( ! isset( $fields['twshop_display_title'] ) ) {
                $fields['twshop_display_title'] = array( 'title' => '結帳顯示名稱（補充）', 'type' => 'text', 'default' => '', 'description' => '清空後沿用原生名稱；只影響新訂單。' );
            }
            $fields['twshop_display_title']['default'] = '';
            return $fields;
        }, 20 );
        add_filter( 'woocommerce_shipping_' . $id . '_instance_option', function ( $value, $key, $instance ) {
            if ( 'twshop_display_title' !== $key ) return $value;
            $titles = get_option( 'wc_shipping_method_titles', array() );
            $saved = is_array( $titles ) ? ( $titles[$instance->id . ':' . $instance->instance_id] ?? '' ) : '';
            return is_scalar( $saved ) ? (string) $saved : '';
        }, 20, 3 );
        add_filter( 'woocommerce_shipping_' . $id . '_instance_settings_values', function ( $settings, $instance ) {
            return twshop_order_save_title( $settings, 'shipping', $instance->id . ':' . $instance->instance_id, $instance->get_field_key( 'twshop_display_title' ), $instance->get_post_data() );
        }, 20, 2 );
    }
    foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
        if ( isset( $payment_registered[$gateway->id] ) ) continue;
        $payment_registered[$gateway->id] = true;
        add_filter( 'woocommerce_settings_api_form_fields_' . $gateway->id, function ( $fields ) use ( $gateway ) { return twshop_order_title_field( $fields, 'payment', $gateway->id ); }, 20 );
        add_filter( 'woocommerce_settings_api_sanitized_fields_' . $gateway->id, function ( $settings ) use ( $gateway ) { return twshop_order_save_title( $settings, 'payment', $gateway->id, $gateway->get_field_key( 'twshop_display_title' ), $gateway->get_post_data() ); }, 20 );
    }
}
