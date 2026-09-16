<?php
/**
 * 設定項註冊與 sanitize callback
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * `wc_discount_rules_settings` 的 register_setting() sanitize callback（S9 修補）。
 *
 * 這個 option 平常只透過 twshop_ajax_save_rule()（約 4650 行起）等 AJAX handler
 * 讀寫，那支函式已有完整的欄位驗證；但 register_setting() 一旦註冊了這個 group，
 * 具 manage_options 權限者理論上仍可直接 POST 到 wp-admin/options.php 寫入任意巢狀
 * 陣列，繞過 AJAX 端的驗證（即使目前沒有任何 <form action="options.php"> 會送出
 * wc_discount_rules_group）。這裡比照 twshop_ajax_save_rule() 的白名單欄位與型別，
 * 作為最後一道防線：不是陣列就回空陣列，逐條規則只保留已知欄位，未知欄位丟棄。
 */
function twshop_sanitize_discount_rules_settings( $input ) {
    if ( ! is_array( $input ) ) return array();

    $sanitized = array();
    foreach ( $input as $rule ) {
        if ( ! is_array( $rule ) ) continue;

        $rule_id = sanitize_text_field( $rule['rule_id'] ?? '' );
        if ( empty( $rule_id ) ) $rule_id = uniqid( 'rule_' );

        $condition_type = sanitize_text_field( $rule['condition_type'] ?? '' );
        if ( ! in_array( $condition_type, array( 'product', 'category', 'tag' ), true ) ) $condition_type = '';
        $raw_condition_values = is_array( $rule['condition_values'] ?? null ) ? $rule['condition_values'] : array();
        if ( 'product' === $condition_type ) {
            $condition_values = array_map( 'absint', $raw_condition_values );
        } elseif ( in_array( $condition_type, array( 'category', 'tag' ), true ) ) {
            $condition_values = twshop_sanitize_term_slugs( $raw_condition_values, 'category' === $condition_type ? 'product_cat' : 'product_tag' );
        } else {
            $condition_values = array();
        }
        $condition_values = array_values( array_filter( $condition_values ) );
        if ( empty( $condition_values ) ) $condition_type = '';

        $shipping_methods = array_map( 'sanitize_text_field', is_array( $rule['shipping_methods'] ?? null ) ? $rule['shipping_methods'] : array() );
        $shipping_methods = array_values( array_filter( $shipping_methods ) );

        $tiers = array();
        foreach ( (array) ( $rule['tiers'] ?? array() ) as $tier ) {
            if ( ! is_array( $tier ) ) continue;
            $discount_type = in_array( $tier['discount_type'] ?? '', array( 'percent', 'fixed' ), true ) ? $tier['discount_type'] : 'fixed';
            $tiers[] = array(
                'min_amount'    => floatval( $tier['min_amount'] ?? 0 ),
                'discount_type' => $discount_type,
                'value'         => floatval( $tier['value'] ?? 0 ),
            );
        }

        $sanitized[] = array(
            'rule_id'           => $rule_id,
            'name'              => sanitize_text_field( $rule['name'] ?? '' ),
            'role'              => sanitize_text_field( $rule['role'] ?? '' ),
            'type'              => sanitize_text_field( $rule['type'] ?? '' ),
            'value'             => floatval( $rule['value'] ?? 0 ),
            'gift_product_id'   => absint( $rule['gift_product_id'] ?? 0 ),
            'shipping_methods'  => $shipping_methods,
            'logic'             => sanitize_text_field( $rule['logic'] ?? '' ),
            'condition_type'    => $condition_type,
            'condition_values'  => $condition_values,
            'min_amount'        => floatval( $rule['min_amount'] ?? 0 ),
            'usage_limit'       => absint( $rule['usage_limit'] ?? 0 ),
            'user_limit'        => absint( $rule['user_limit'] ?? 0 ),
            'start_time'        => sanitize_text_field( $rule['start_time'] ?? '' ),
            'end_time'          => sanitize_text_field( $rule['end_time'] ?? '' ),
            'enabled'           => sanitize_text_field( $rule['enabled'] ?? 'no' ),
            'stack_exclusive'   => sanitize_text_field( $rule['stack_exclusive'] ?? 'no' ),
            'buy_qty'           => absint( $rule['buy_qty'] ?? 0 ),
            'free_qty'          => absint( $rule['free_qty'] ?? 0 ),
            'tiers'             => $tiers,
        );
    }

    return $sanitized;
}

function twshop_register_settings() {
    register_setting( 'wc_member_tiers_group', 'wc_member_tiers_settings', 'twshop_sanitize_tiers' );
    register_setting( 'wc_member_tiers_group', 'wc_birthday_validity_days', 'absint' );
    register_setting( 'wc_member_tiers_group', 'wc_birthday_email_subject', 'sanitize_text_field' );
    register_setting( 'wc_member_tiers_group', 'wc_upgrade_validity_days', 'absint' );
    register_setting( 'wc_member_tiers_group', 'wc_upgrade_email_subject', 'sanitize_text_field' );

    register_setting( 'wc_member_tiers_group', 'wc_birthday_email_body', 'sanitize_textarea_field' );
    register_setting( 'wc_member_tiers_group', 'wc_upgrade_email_body_gift', 'sanitize_textarea_field' );
    register_setting( 'wc_member_tiers_group', 'wc_upgrade_email_subject_no_gift', 'sanitize_text_field' );
    register_setting( 'wc_member_tiers_group', 'wc_upgrade_email_body_no_gift', 'sanitize_textarea_field' );
    register_setting( 'wc_member_tiers_group', 'wc_tier_change_email_subject', 'sanitize_text_field' );
    register_setting( 'wc_member_tiers_group', 'wc_tier_change_email_body', 'sanitize_textarea_field' );

    register_setting( 'wc_member_tiers_group', 'wc_tier_max_reached_text', 'sanitize_text_field' );
    register_setting( 'wc_member_tiers_group', 'wc_tier_not_configured_text', 'sanitize_text_field' );

    // 紅利點數頁（v25.8.25 起改成 4 個真正的頁籤，各自獨立 <form>）：group 依頁籤拆開，
    // 理由與下方 v25.5.58 教訓相同——避免存一個頁籤把其餘頁籤的欄位清空。

    // 頁籤：點數規則設定
    register_setting( 'wc_points_rules_group', 'wc_points_term_name', 'sanitize_text_field' );
    register_setting( 'wc_points_rules_group', 'wc_points_base_rate', 'absint' );
    register_setting( 'wc_points_rules_group', 'wc_points_earn_restrict_type', 'twshop_sanitize_cat_tag_type' );
    register_setting( 'wc_points_rules_group', 'wc_points_earn_restrict_values', 'twshop_sanitize_id_array' );
    register_setting( 'wc_points_rules_group', 'wc_points_redemption_rate', 'absint' );
    register_setting( 'wc_points_rules_group', 'wc_points_max_percent', 'absint' );
    register_setting( 'wc_points_rules_group', 'wc_points_min_cart_amount', 'floatval' );
    register_setting( 'wc_points_rules_group', 'wc_points_redeem_restrict_type', 'twshop_sanitize_cat_tag_type' );
    register_setting( 'wc_points_rules_group', 'wc_points_redeem_restrict_values', 'twshop_sanitize_id_array' );
    register_setting( 'wc_points_rules_group', 'wc_points_expiry_days', 'absint' );
    register_setting( 'wc_points_rules_group', 'wc_points_expiry_notify_days', 'absint' );
    register_setting( 'wc_points_rules_group', 'wc_points_expiry_notify_subject', 'sanitize_text_field' );
    register_setting( 'wc_points_rules_group', 'wc_points_expiry_notify_body', 'sanitize_textarea_field' );

    // 頁籤：點數提示文字
    register_setting( 'wc_points_texts_group', 'wc_points_ui_heading', 'sanitize_text_field' );
    register_setting( 'wc_points_texts_group', 'wc_points_balance_text', 'sanitize_text_field' );
    register_setting( 'wc_points_texts_group', 'wc_points_expiry_soon_text', 'sanitize_text_field' );
    register_setting( 'wc_points_texts_group', 'wc_points_input_placeholder', 'sanitize_text_field' );
    register_setting( 'wc_points_texts_group', 'wc_points_btn_apply_text', 'sanitize_text_field' );
    register_setting( 'wc_points_texts_group', 'wc_points_btn_update_text', 'sanitize_text_field' );
    register_setting( 'wc_points_texts_group', 'wc_points_applied_text', 'sanitize_text_field' );
    register_setting( 'wc_points_texts_group', 'wc_points_no_balance_text', 'sanitize_text_field' );
    register_setting( 'wc_points_texts_group', 'wc_points_min_cart_text', 'sanitize_text_field' );
    register_setting( 'wc_points_texts_group', 'wc_points_restricted_text', 'sanitize_text_field' );

    // 頁籤：點數發放與退還時機
    register_setting( 'wc_points_award_group', 'wc_points_award_statuses', 'twshop_sanitize_order_status_array' );
    register_setting( 'wc_points_award_group', 'wc_points_revoke_statuses', 'twshop_sanitize_order_status_array' );

    // 頁籤：點數兌換商品
    register_setting( 'wc_points_redeem_group', 'wc_points_redeemable_products', 'twshop_sanitize_points_redeemable_products' );

    // v25.5.58 修正：這 5 組原本全部共用 wc_general_settings_group，但實際渲染在 5 個不同頁籤
    // （即 5 個獨立 <form>）。options.php 儲存時是依「整個 group 底下註冊過的所有 option」逐一比對
    // $_POST，任一個 option 沒出現在當次送出的表單裡就會被當成空值寫回（見 wp-admin/options.php：
    // `$value = null; if (isset($_POST[$option])) {...}; update_option($option, $value);`——沒出現在
    // POST 裡的一律 update_option($option, null)）。結果是：只要儲存其中一個頁籤，其餘 4 個頁籤的
    // 欄位全部被清空成空字串，而空字串會讓 get_option($key, '預設值') 的預設值失效（get_option 只有
    // 在 option 完全不存在時才會回傳第二參數，值是空字串一樣視為「已設定」）。修法是每個頁籤各自
    // 用獨立的 settings group，跟 wc_member_tiers_group（本來就只有一個頁籤/一個 <form>）以及上面
    // 拆開後的 4 個 wc_points_*_group 看齊。

    // 行銷 ▸ 優惠券
    register_setting( 'wc_marketing_coupons_group', 'wc_general_coupon_noun', 'sanitize_text_field' );
    register_setting( 'wc_marketing_coupons_group', 'wc_general_no_coupon_msg', 'sanitize_text_field' );
    register_setting( 'wc_marketing_coupons_group', 'wc_general_coupon_page_title', 'sanitize_text_field' );
    register_setting( 'wc_marketing_coupons_group', 'wc_general_coupon_page_desc', 'sanitize_textarea_field' );
    register_setting( 'wc_marketing_coupons_group', 'wc_coupon_btn_used_text', 'sanitize_text_field' );
    register_setting( 'wc_marketing_coupons_group', 'wc_coupon_btn_shop_text', 'sanitize_text_field' );
    register_setting( 'wc_marketing_coupons_group', 'wc_coupon_btn_unavailable_text', 'sanitize_text_field' );
    register_setting( 'wc_marketing_coupons_group', 'wc_coupon_btn_remove_text', 'sanitize_text_field' );
    register_setting( 'wc_marketing_coupons_group', 'wc_coupon_btn_apply_text', 'sanitize_text_field' );
    register_setting( 'wc_marketing_coupons_group', 'wc_coupon_dialog_trigger_none_text', 'sanitize_text_field' );
    register_setting( 'wc_marketing_coupons_group', 'wc_coupon_dialog_trigger_applied_text', 'sanitize_text_field' );
    register_setting( 'wc_marketing_coupons_group', 'wc_coupon_dialog_heading', 'sanitize_text_field' );

    // 系統設定 ▸ 一般
    // 加購商品顯示文字（v25.8.57 起從「折扣規則」頁移過來，跟其他一般設定收在同一個
    // <form> 裡；改動時務必連 settings_fields() 的 group 一起改，不能只搬 HTML——見
    // CLAUDE.md「已知踩坑：跨頁籤共用 settings group」。
    register_setting( 'wc_system_general_group', 'wc_addon_section_title', 'sanitize_text_field' );
    register_setting( 'wc_system_general_group', 'wc_addon_btn_add_text', 'sanitize_text_field' );
    register_setting( 'wc_system_general_group', 'wc_addon_btn_incart_text', 'sanitize_text_field' );
    register_setting( 'wc_system_general_group', 'wc_login_btn_text', 'sanitize_text_field' );
    register_setting( 'wc_system_general_group', 'wc_register_btn_text', 'sanitize_text_field' );
    register_setting( 'wc_system_general_group', 'wc_badge_enabled', 'twshop_sanitize_yes_no' );
    register_setting( 'wc_system_general_group', 'wc_badge_text_template', 'sanitize_text_field' );
    register_setting( 'wc_system_general_group', 'wc_product_slug_use_id', 'twshop_sanitize_yes_no' );
    register_setting( 'wc_system_general_group', 'wc_classic_cart_show_coupons', 'twshop_sanitize_yes_no' );
    register_setting( 'wc_system_general_group', 'wc_classic_cart_show_addons', 'twshop_sanitize_yes_no' );
    register_setting( 'wc_system_general_group', 'wc_classic_cart_show_progress', 'twshop_sanitize_yes_no' );
    register_setting( 'wc_system_general_group', 'wc_classic_cart_show_points', 'twshop_sanitize_yes_no' );
    register_setting( 'wc_system_general_group', 'wc_classic_cart_show_wallet', 'twshop_sanitize_yes_no' );
    register_setting( 'wc_system_general_group', 'wc_shipping_method_titles', 'twshop_sanitize_method_titles' );
    register_setting( 'wc_system_general_group', 'wc_payment_method_titles', 'twshop_sanitize_method_titles' );

    // 儲值金 ▸ 設定
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_tier_spend_full_amount', 'twshop_sanitize_yes_no' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_topup_email_enabled', 'twshop_sanitize_yes_no' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_topup_email_subject', 'sanitize_text_field' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_topup_email_body', 'sanitize_textarea_field' );
    // 儲值金 ▸ 設定：使用限制與提示文字（v25.8.76 起效仿點數規則/提示文字設定新增）
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_min_cart_amount', 'floatval' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_restrict_type', 'twshop_sanitize_cat_tag_type' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_restrict_values', 'twshop_sanitize_id_array' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_ui_heading', 'sanitize_text_field' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_balance_text', 'sanitize_text_field' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_input_placeholder', 'sanitize_text_field' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_btn_apply_text', 'sanitize_text_field' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_btn_update_text', 'sanitize_text_field' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_applied_text', 'sanitize_text_field' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_no_balance_text', 'sanitize_text_field' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_min_cart_text', 'sanitize_text_field' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_restricted_text', 'sanitize_text_field' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_topup_restricted_text', 'sanitize_text_field' );
    register_setting( 'wc_wallet_settings_group', 'wc_wallet_topup_allowed_gateways', 'twshop_sanitize_wallet_allowed_gateways' );

    // 會員 ▸ 頁籤管理
    register_setting( 'wc_member_tabs_group', 'wc_membership_tab_name', 'sanitize_text_field' );
    register_setting( 'wc_member_tabs_group', 'wc_general_tab_name', 'sanitize_text_field' );
    register_setting( 'wc_member_tabs_group', 'wc_account_tabs_settings', 'twshop_sanitize_account_tabs' );
    register_setting( 'wc_member_tabs_group', 'wc_account_tab_names', 'twshop_sanitize_account_tab_names' );
    register_setting( 'wc_member_tabs_group', 'wc_account_tab_icons', 'twshop_sanitize_account_tab_icons' );
    register_setting( 'wc_member_tabs_group', 'wc_account_tab_mobile_scroll', 'twshop_sanitize_yes_no' );

    register_setting( 'wc_discount_rules_group', 'wc_discount_rules_settings', 'twshop_sanitize_discount_rules_settings' );

    // 蝦皮串接：auth／sync 兩個頁籤各自獨立 <form>，各自獨立 settings group
    // （mapping／log 頁籤純 AJAX，無對應 group）。
    register_setting( 'twshop_shopee_credentials_group', 'twshop_shopee_credentials', 'twshop_sanitize_shopee_credentials' );
    register_setting( 'twshop_shopee_sync_group', 'twshop_shopee_sync_settings', 'twshop_sanitize_shopee_sync_settings' );
    // 蝦皮串接總開關（v25.8.65 新增，見 page-shopee.php 檔頭說明）——獨立 group，因為它是
    // 「系統設定 ▸ 蝦皮串接」頁籤最上面那個獨立 <form>，跟下面授權/同步設定各自的
    // <form> 不是同一個送出動作。
    register_setting( 'wc_shopee_enable_group', 'wc_shopee_sync_enabled', 'twshop_sanitize_yes_no' );
}

/**
 * partner_key 是密鑰，後台輸入框已設定過時顯示 TWSHOP_SHOPEE_KEY_MASK 遮罩字串。
 * 送出的仍是這個遮罩字串（使用者沒有更改）或整段清空時，沿用舊值、不覆寫；
 * 只有送出「非遮罩、非空」的新字串時才真的覆寫，避免使用者不小心把已設定的金鑰清空。
 */
function twshop_sanitize_shopee_credentials( $input ) {
    $old = get_option( 'twshop_shopee_credentials', array() );

    $partner_key = isset( $input['partner_key'] ) ? (string) wp_unslash( $input['partner_key'] ) : '';
    if ( '' === $partner_key || TWSHOP_SHOPEE_KEY_MASK === $partner_key ) {
        $partner_key = $old['partner_key'] ?? '';
    }

    $env = in_array( $input['env'] ?? '', array( 'live', 'sandbox' ), true ) ? $input['env'] : 'sandbox';

    return array(
        'partner_id'  => sanitize_text_field( wp_unslash( $input['partner_id'] ?? '' ) ),
        'partner_key' => $partner_key,
        'env'         => $env,
    );
}

/**
 * 訂單匯入狀態白名單比對蝦皮官方訂單狀態列舉；Woo 訂單初始狀態白名單比對
 * wc_get_order_statuses()（現存狀態，含 twshop 自訂的 wc-twshop-in-transit/wc-twshop-shipped）。
 */
function twshop_sanitize_shopee_sync_settings( $input ) {
    $valid_shopee_statuses = array( 'UNPAID', 'READY_TO_SHIP', 'PROCESSED', 'SHIPPED', 'COMPLETED', 'CANCELLED', 'TO_RETURN', 'INVOICE_PENDING' );
    $order_import_status   = array_values( array_intersect( (array) ( $input['order_import_status'] ?? array() ), $valid_shopee_statuses ) );
    if ( empty( $order_import_status ) ) $order_import_status = array( 'READY_TO_SHIP', 'PROCESSED' );

    $valid_wc_statuses = array_map( function ( $key ) { return str_replace( 'wc-', '', $key ); }, array_keys( wc_get_order_statuses() ) );
    $default_status    = in_array( $input['default_order_status'] ?? '', $valid_wc_statuses, true ) ? $input['default_order_status'] : 'processing';

    return array(
        'stock_push_enabled'   => ( 'yes' === ( $input['stock_push_enabled'] ?? '' ) ) ? 'yes' : 'no',
        'price_push_enabled'   => ( 'yes' === ( $input['price_push_enabled'] ?? '' ) ) ? 'yes' : 'no',
        'order_import_enabled' => ( 'yes' === ( $input['order_import_enabled'] ?? '' ) ) ? 'yes' : 'no',
        'order_import_status'  => $order_import_status,
        'default_order_status' => $default_status,
        'stock_buffer'         => max( 0, absint( $input['stock_buffer'] ?? 0 ) ),
        'log_retention_days'   => max( 1, absint( $input['log_retention_days'] ?? 30 ) ),
    );
}

/**
 * 將會員中心頁籤排序、開關與名稱（含會員等級&積分／折價券的專屬名稱欄位）恢復為預設值。
 */
function twshop_maybe_reset_account_tabs() {
    if ( isset( $_GET['reset_account_tabs'] ) && current_user_can( 'manage_woocommerce' ) ) {
        check_admin_referer( 'twshop_reset_account_tabs' );
        delete_option( 'wc_account_tabs_settings' );
        delete_option( 'wc_account_tab_names' );
        delete_option( 'wc_account_tab_icons' );
        delete_option( 'wc_membership_tab_name' );
        delete_option( 'wc_general_tab_name' );
        // 導向乾淨網址，避免此觸發參數殘留在網址列上，導致下次在本頁儲存表單時（redirect 會帶回同一個網址）重複觸發、把剛存好的設定又清空一次
        wp_safe_redirect( admin_url( 'admin.php?page=twshop-system&tab=tabs&account_tabs_reset=1' ) );
        exit;
    }
}

/**
 * 清洗 b_gifts/u_gifts 這個 JSON 字串欄位（存的那半，S8 修補之一）。
 *
 * 這支函式只保護「之後才存進資料庫的」資料——它跑在 register_setting() 的 sanitize
 * callback 內，只有管理員下一次儲存「會員分級」表單時才會執行一次。已經寫進
 * wc_member_tiers_settings option 裡的舊資料（含這次修補之前就存在的惡意/畸形內容）
 * 不會被這支函式回溯清洗，必須靠輸出端（twshop_my_membership_endpoint_content()）
 * 對 $g['amount'] 補 esc_html() 才真正擋得住——兩半都要做，缺一半都不安全。
 *
 * @param string $json 前台送出的 JSON 字串（單一等級的 b_gifts 或 u_gifts 欄位）。
 * @return string 重新編碼後的 JSON 字串，結構保證是 [{type, amount}, ...]。
 */
function twshop_sanitize_gifts_json( $json ) {
    $decoded = json_decode( is_string( $json ) ? $json : '', true );
    if ( ! is_array( $decoded ) ) return '[]';

    $allowed_types = array( 'percent', 'points', 'fixed' );
    $sanitized     = array();
    foreach ( $decoded as $item ) {
        if ( ! is_array( $item ) ) continue;
        $type = $item['type'] ?? '';
        // 不在白名單的 type 一律歸為固定金額（fixed），不整筆丟棄，避免管理員既有設定無聲消失。
        if ( ! in_array( $type, $allowed_types, true ) ) $type = 'fixed';
        $sanitized[] = array(
            'type'   => $type,
            'amount' => floatval( $item['amount'] ?? 0 ),
        );
    }

    return wp_json_encode( $sanitized );
}

function twshop_sanitize_tiers( $input ) {
    $sanitized_data = array();
    if ( is_array( $input ) && ! empty( $input['slug'] ) ) {
        for ( $i = 0; $i < count( $input['slug'] ); $i++ ) {
            if ( ! empty( $input['slug'][$i] ) ) {
                $sanitized_data[] = array(
                    'slug'             => sanitize_title( $input['slug'][$i] ),
                    'name'             => sanitize_text_field( $input['name'][$i] ),
                    'threshold'        => absint( $input['threshold'][$i] ),
                    'period'           => absint( $input['period'][$i] ),
                    'b_enable'         => sanitize_text_field( $input['b_enable'][$i] ?? 'no' ),
                    'b_gifts'          => twshop_sanitize_gifts_json( wp_unslash( $input['b_gifts'][$i] ?? '[]' ) ),
                    'u_enable'         => sanitize_text_field( $input['u_enable'][$i] ?? 'no' ),
                    'u_gifts'          => twshop_sanitize_gifts_json( wp_unslash( $input['u_gifts'][$i] ?? '[]' ) ),
                    'point_multiplier' => floatval( $input['point_multiplier'][$i] ?? 1 )
                );
                // add_role() 對已存在的 role slug 是 no-op（WordPress 核心行為，不會更新既有角色的
                // 顯示名稱／capabilities）——只改「顯示名稱」不改「等級識別碼」重新存檔時，角色會卡在
                // 建立當下的舊名稱，使用者管理頁的「變更使用者角色」下拉選單看到的還是舊名稱，容易
                // 誤以為改名/新建的等級「沒有出現」。改成先 remove_role() 清掉舊定義再 add_role()
                // 重建，確保名稱異動一定會同步；remove_role() 對不存在的 slug 是安全的 no-op（新等級
                // 第一次存檔時這行等於沒作用），且只刪除角色「定義」，不影響已持有該角色之會員的
                // user meta，同一次請求內立刻補回同一個 slug，實務上不會有角色暫時消失的空窗期。
                $role_slug = sanitize_title( $input['slug'][$i] );
                remove_role( $role_slug );
                add_role( $role_slug, sanitize_text_field( $input['name'][$i] ), array( 'read' => true ) );
            }
        }
    }
    return $sanitized_data;
}

/**
 * 會員中心頁籤排序/開關設定的儲存格式：依提交順序（拖曳排序後的 DOM 順序）記錄各頁籤 slug 與啟用狀態。
 * 啟用狀態透過隱藏欄位（由 JS 依核取方塊狀態同步）傳遞，避免核取方塊未勾選時不會提交、導致與 slug 陣列索引錯位。
 */
function twshop_sanitize_account_tabs( $input ) {
    $sanitized = array();
    if ( is_array( $input ) && ! empty( $input['slug'] ) ) {
        foreach ( $input['slug'] as $i => $slug ) {
            $slug = sanitize_key( $slug );
            if ( '' === $slug ) continue;
            $sanitized[] = array(
                'slug'    => $slug,
                'enabled' => ( 'no' === ( $input['enabled'][ $i ] ?? 'yes' ) ) ? 'no' : 'yes',
            );
        }
    }
    return $sanitized;
}

/**
 * 除「會員等級&積分」「折價券」外，其餘會員中心頁籤的自訂名稱（slug => 名稱）。
 * 只保留目前仍有註冊的 slug；欄位留空代表沿用 WC 核心／其他外掛提供的預設名稱，不寫入設定。
 */
function twshop_sanitize_account_tab_names( $input ) {
    $sanitized   = array();
    $valid_slugs = array_keys( twshop_get_all_registered_account_tabs() );

    if ( is_array( $input ) ) {
        foreach ( $input as $slug => $name ) {
            $slug = sanitize_key( $slug );
            $name = trim( sanitize_text_field( $name ) );
            if ( '' === $slug || '' === $name ) continue;
            if ( in_array( $slug, array( 'my-membership', 'my-coupons' ), true ) ) continue;
            if ( ! in_array( $slug, $valid_slugs, true ) ) continue;
            $sanitized[ $slug ] = $name;
        }
    }
    return $sanitized;
}

/**
 * 運送／付款方式自訂名稱的 sanitize：id => 名稱 的對照表。
 *
 * key 是 WooCommerce 的 rate id（`flat_rate:3`）或 gateway id（`Wooecpay_Gateway_Credit`），
 * 兩者都只會出現英數、底線、連字號與冒號。**不用 sanitize_key()**：它會把大寫轉小寫、
 * 也會吃掉冒號，而綠界的 gateway id 帶大寫，轉小寫之後就對不上實際的 id 了。
 *
 * key 的處理是「含非法字元就整筆丟棄」，不是逐字剔除。逐字剔除會把
 * `有中文的key` 這種東西改寫成 `key` 存進資料庫——一個永遠對不上任何運送/付款方式、
 * 又不會有人發現的殘留項目。合法的 id 本來就不含那些字元，不會受影響。
 *
 * 這裡刻意**不去比對「這個 id 目前是否真的存在」**：某個金流/物流外掛暫時停用時，
 * 它的運送方式會從清單上消失，若拿現存清單當白名單，儲存一次一般設定就會把那筆改名
 * 靜靜刪掉，等外掛啟用回來名稱就變回去了，而且沒有任何提示。
 *
 * 空字串的項目直接丟掉不存：留空的語意是「沿用原生名稱」，存一堆空字串進資料庫只會讓
 * 之後讀取端每次都要再過濾一次。
 */
function twshop_sanitize_method_titles( $input ) {
    if ( ! is_array( $input ) ) return array();

    $clean = array();
    foreach ( $input as $id => $title ) {
        $id = (string) $id;
        if ( '' === $id || ! preg_match( '/^[A-Za-z0-9_:\-]+$/', $id ) ) continue;

        $title = sanitize_text_field( wp_unslash( (string) $title ) );
        if ( '' === $title ) continue;

        $clean[ $id ] = $title;
    }
    return $clean;
}

function twshop_sanitize_yes_no( $input ) {
    return ( 'yes' === $input ) ? 'yes' : 'no';
}

/**
 * 儲值金商品限定付款方式（v25.8.79 新增）：跟目前已註冊的 gateway id 取交集，
 * 不限「已啟用」——避免暫時停用的金流被存檔時悄悄清掉管理員原本的選擇。跟
 * twshop_sanitize_method_titles() 刻意保留陌生 id（改名工具）的理由方向相反：
 * 這裡的 id 若不是真實存在的 gateway，往後在 twshop_restrict_wallet_credit_
 * payment_gateways() 這個 filter 裡永遠不可能命中任何一個真實金流，留著沒有
 * 任何用處，丟棄才是正確的。
 */
function twshop_sanitize_wallet_allowed_gateways( $input ) {
    if ( ! is_array( $input ) || ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) return array();
    $registered = array_keys( WC()->payment_gateways()->payment_gateways() );
    return array_values( array_intersect( array_map( 'sanitize_key', $input ), $registered ) );
}

/**
 * 會員中心頁籤自訂圖示（slug => Lucide icon slug，如 'store'，對應 assets/icons/store.svg）。
 * 只保留目前仍有註冊的 slug；圖示值只接受 twshop_get_account_tab_icon_choices() 白名單內的值，
 * 避免存入未知字串（該值後續會用來讀取 assets/icons/{值}.svg 檔案，需嚴格驗證來源合法性）。
 */
function twshop_sanitize_account_tab_icons( $input ) {
    $sanitized   = array();
    $valid_slugs = array_keys( twshop_get_all_registered_account_tabs() );
    $choices     = twshop_get_account_tab_icon_choices();

    if ( is_array( $input ) ) {
        foreach ( $input as $slug => $icon ) {
            $slug = sanitize_key( $slug );
            $icon = sanitize_key( $icon );
            if ( '' === $slug || '' === $icon ) continue;
            if ( ! in_array( $slug, $valid_slugs, true ) ) continue;
            if ( ! isset( $choices[ $icon ] ) ) continue;
            $sanitized[ $slug ] = $icon;
        }
    }
    return $sanitized;
}

