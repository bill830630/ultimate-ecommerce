<?php
/**
 * 1. 初始化與 Endpoint 註冊
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 1. 初始化與 Endpoint 註冊
// =========================================================================
add_action( 'init', 'twshop_core_init_registration' );
// v25.5.86：短代碼系統（[visual_coupon]/[auto_visual_coupons]/[twshop_points_redemption]/
// [twshop_cart_addons]/[twshop_cart_progress] 五個短代碼＋「短代碼說明」後台頁）整個移除，
// 購物車/結帳頁的自動顯示走的是「傳統購物車自動注入」那組獨立 hook
// （twshop_classic_cart_addons()/twshop_classic_cart_points()/twshop_classic_cart_coupons()/
// twshop_classic_cart_progress()，見下方），不依賴短代碼，故移除短代碼本身不影響現有的
// 自動顯示行為。此函式原本在 add_rewrite_endpoint() 之後還有一道 twshop_license_is_active()
// 判斷只用來擋短代碼註冊，短代碼移除後這道判斷已無對象可擋，一併移除。
function twshop_core_init_registration() {
    twshop_register_order_statuses();

    add_rewrite_endpoint( 'my-coupons', EP_ROOT | EP_PAGES );
    add_rewrite_endpoint( 'my-membership', EP_ROOT | EP_PAGES );
    // my-wallet（儲值金，v25.8.61 新增）刻意無條件註冊，不看 wallet 模組開關——
    // rewrite endpoint 只是「讓這個網址被解析出對應 query var」，跟模組開關無關，
    // 真正決定要不要輸出內容的是 woocommerce_account_my-wallet_endpoint 這個 action
    // 有沒有掛（掛在模組開關底下，見下方）。這裡若也看開關，會在「開了又關」的情境下
    // 讓已經被搜尋引擎收錄或加入書籤的網址結構跟著開關忽有忽無，沒有必要。
    add_rewrite_endpoint( 'my-wallet', EP_ROOT | EP_PAGES );
}

add_filter( 'woocommerce_get_query_vars', function($vars) { $vars['my-coupons'] = 'my-coupons'; $vars['my-membership'] = 'my-membership'; $vars['my-wallet'] = 'my-wallet'; return $vars; }, 0 );
register_activation_hook( TWSHOP_PLUGIN_FILE, 'twshop_flush_rewrite_rules_on_activation' );
function twshop_flush_rewrite_rules_on_activation() { twshop_core_init_registration(); flush_rewrite_rules(); }

/**
 * my-wallet 是 v25.8.61 才新增的 rewrite endpoint。activation hook 只在「外掛從沒啟用
 * 到啟用」那一刻觸發一次，既有站台這次只是更新版本，不會重新觸發，既有的 rewrite rules
 * 快取裡不會有這個 endpoint——會員中心的「儲值金」頁籤即使正確顯示在選單裡，點進去也會
 * 404。比照 wallet 資料表的 admin_init 版本比對慣例，這裡也用一個一次性 option 旗標，
 * 確保只要網站曾經升級到含這個 endpoint 的版本，就會在下一次後台請求時補跑一次
 * flush_rewrite_rules()，不需要管理員自己手動去「設定 ▸ 固定網址」重新儲存一次。
 */
function twshop_wallet_maybe_flush_rewrite_rules() {
    if ( ! get_option( 'twshop_wallet_endpoint_flushed' ) ) {
        flush_rewrite_rules();
        update_option( 'twshop_wallet_endpoint_flushed', '1' );
    }
}
add_action( 'admin_init', 'twshop_wallet_maybe_flush_rewrite_rules' );

add_action( 'plugins_loaded', 'twshop_membership_init' );
add_action( 'admin_notices', 'twshop_woocommerce_missing_notice' );

// 宣告本外掛與 HPOS（高效能訂單儲存）相容——這個 hook 早於 plugins_loaded，必須獨立掛在
// 檔案最上層，不能延後到 twshop_membership_init()。原本掛在組合商品模組（wc-bundle-products
// 併入時沿用的既有寫法，用 WCBP_PLUGIN_FILE 常數，其值本來就等於 __FILE__）底下，25.5.82
// 移除組合商品模組時發現這其實是本外掛**唯一**的 HPOS 相容宣告（整支 twshop.php 只有這一處），
// 移除組合商品程式碼時保留下來、改直接用 __FILE__，避免整個外掛失去 HPOS 相容宣告。
add_action(
    'before_woocommerce_init',
    function () {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TWSHOP_PLUGIN_FILE, true );
        }
    }
);

// Requires Plugins 標頭只擋得住「啟用當下」WooCommerce 未安裝的情況；若站台之後把 WooCommerce 停用，
// 本外掛的 hooks 會在 twshop_membership_init() 直接 return（不執行任何動作、也沒有任何提示），
// 這裡補一個後台通知，讓管理員知道所有功能已停擺、需要重新啟用 WooCommerce。
function twshop_woocommerce_missing_notice() {
    if ( class_exists( 'WooCommerce' ) ) return;
    if ( ! current_user_can( 'activate_plugins' ) ) return;
    echo '<div class="notice notice-error"><p><strong>終極電商</strong>需要先安裝並啟用 <strong>WooCommerce</strong> 外掛才能運作，目前所有功能（會員分級、折扣規則、優惠券、點數等）皆已停用。</p></div>';
}

function twshop_membership_init() {
    if ( ! class_exists( 'WooCommerce' ) ) return;

    // 自架更新通道，見 includes/class-twshop-updater.php。只在後台／排程／WP-CLI 註冊：
    // 前台訪客不需要更新檢查，而排除清單在 transient 過期時會同步打 GitHub API（最長 8 秒），
    // 掛在前台會偶發拖慢訪客頁面（v25.8.37）。
    if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
        TWSHOP_Updater::init();
    }

    // 點數兌換商品／贈品商品禁止顧客直接購買：跨 points／discount_rules 兩個模組共用，
    // 刻意不綁在任一模組的 if 區塊內——比照「跨模組共用的 AJAX action 不應該只綁在單一
    // 模組開關下」的既有教訓（見下方 twshop_ajax_refresh_components 註冊處），任一模組
    // 關閉時另一模組的限制仍要正常運作。callback 內部（includes/helpers.php）已經用
    // twshop_get_purchase_restricted_product_ids() 自行依模組開關決定要不要收集清單，
    // 兩個模組都關閉時清單是空陣列，filter 恆等於無操作。
    add_filter( 'woocommerce_is_purchasable', 'twshop_restrict_purchase_for_redeem_and_gift_products', 10, 2 );
    add_action( 'woocommerce_single_product_summary', 'twshop_render_purchase_restricted_notice', 25 );

    // 上面那支 filter 擋掉的是「一般顧客直接購買」，但 WC_Cart_Session::get_cart_from_session()
    // 在每次頁面載入從 session 還原購物車時，會透過另一支 filter（注意 hook 名稱不同：
    // woocommerce_cart_item_is_purchasable）對購物車裡「已經存在」的項目重新檢查一次
    // is_purchasable()，不通過就悄悄移除並顯示「已從您的購物車移除」——這會把 twshop 自己
    // 合法加入的兌換商品/贈品在下一次頁面載入時一併清掉。這支 filter 直接看購物車項目自己的
    // meta（twshop_points_redeem_product_id／twshop_gift_rule_id）放行，見 helpers.php 說明。
    add_filter( 'woocommerce_cart_item_is_purchasable', 'twshop_allow_purchasable_for_tracked_cart_items', 10, 3 );

    // --- 後台選單 ---
    add_action( 'admin_menu', 'twshop_register_menus' );
    add_action( 'wp_dashboard_setup', 'twshop_register_dashboard_widget' );
    add_action( 'admin_enqueue_scripts', 'twshop_admin_external_scripts' );

    add_action( 'admin_init', 'twshop_register_settings' );
    add_action( 'admin_init', 'twshop_maybe_migrate_removed_rule_coupons' );
    add_action( 'admin_init', 'twshop_maybe_reset_account_tabs' );

    // 運送／付款方式改名：不綁任何模組開關。這是純顯示偏好，跟 order_checkout_enhancements
    // 模組（地址欄位／超商取貨／物流資訊）沒有邏輯關聯——關掉那個模組的人不會預期
    // 付款方式名稱跟著變回「綠界信用卡」。沒有設定任何自訂名稱時兩支 callback 都會立刻 return，
    // 成本可忽略。
    // shipping 的 priority 20 必須早於 twshop_apply_free_shipping_rules() 的 100，理由見該函式。
    add_filter( 'woocommerce_package_rates', 'twshop_rename_shipping_rates', 20, 2 );
    add_filter( 'woocommerce_gateway_title', 'twshop_rename_gateway_title', 10, 2 );

    // 台灣地址的顯示格式與「縣市代碼 → 中文名稱」還原：**刻意不綁 order_checkout_enhancements
    // 模組開關**，比照上面的運送/付款方式改名與商品折扣徽章。理由是這三支處理的是**已經存進
    // 資料庫的既有資料**，不是一項可以開關的功能——連動模式下結帳的訂單，state 欄位存的是
    // `TYC` 這種代碼，關掉模組不會讓那些值消失。綁在模組開關下的後果是：管理員關掉結帳強化之後，
    // 所有歷史訂單的縣市在訂單詳情/通知信上退回顯示英文代碼，綠界宅配的收件地址也跟著變回
    // `TYC平鎮區…`（見 twshop_localize_order_shipping_state()）——而這兩件事都不會有任何錯誤訊息。
    add_filter( 'woocommerce_localisation_address_formats', 'twshop_taiwan_address_format' );
    add_filter( 'woocommerce_formatted_address_replacements', 'twshop_taiwan_formatted_address_replacements', 10, 2 );
    add_filter( 'woocommerce_order_get_billing_state', 'twshop_localize_order_billing_state', 10, 2 );
    add_filter( 'woocommerce_order_get_shipping_state', 'twshop_localize_order_shipping_state', 10, 2 );

    // --- 前端腳本（始終掛載）---
    add_action( 'wp_enqueue_scripts', 'twshop_global_frontend_js' );
    add_action( 'wp_footer', 'twshop_login_register_btn_text_inline_js' );
    add_filter( 'woocommerce_account_menu_items', 'twshop_modify_account_menu_items', 20 );
    add_filter( 'body_class', 'twshop_maybe_add_account_tab_mobile_scroll_class' );
    add_action( 'template_redirect', 'twshop_redirect_account_dashboard' );

    // --- 加購從購物車移除（折扣規則與視覺化優惠券共用，不受模組開關影響）---
    add_action( 'wp_ajax_twshop_remove_addon', 'twshop_ajax_remove_addon' );
    add_action( 'wp_ajax_nopriv_twshop_remove_addon', 'twshop_ajax_remove_addon' );

    // 購物車/結帳頁優惠券／加購／點數區塊在 AJAX 更新購物車後的即時刷新，三個區塊共用同一支
    // handler（twshop_ajax_refresh_components()），不應該只綁在 visual_coupons 模組開關下——
    // 否則停用 visual_coupons、只留 points 或 discount_rules 時，這兩個區塊的即時刷新會悄悄失效
    // （AJAX action 根本沒註冊，前端 $.post 收到 WordPress 的預設 "0" 回應，直接被 JS 的
    // `if (!res.success) return;` 吞掉，沒有任何錯誤提示）。
    add_action( 'wp_ajax_twshop_refresh_components', 'twshop_ajax_refresh_components' );
    add_action( 'wp_ajax_nopriv_twshop_refresh_components', 'twshop_ajax_refresh_components' );

    // 傳統購物車自動注入優惠券、加購、點數兌換商品、點數折抵區塊
    // （WC classic 版型的模板 hooks，block-based 版型不會觸發；
    // 各 callback 內部各自檢查模組開關、後台顯示開關）
    // 「點數兌換商品」v25.8.18 起改掛在商品列表下方（跟加購商品同一個 hook，緊接在後面），
    // 不再跟「點數折抵」一起塞進購物車總計區塊——語意上是「再選一項商品加入購物車」，
    // 放在商品列表旁邊比放進金額摘要區塊更直覺，見 twshop_classic_cart_redeem_products()。
    add_action( 'woocommerce_after_cart_table',    'twshop_classic_cart_addons' );
    add_action( 'woocommerce_after_cart_table',    'twshop_classic_cart_redeem_products' );
    add_action( 'woocommerce_before_cart_totals',  'twshop_classic_cart_points',  10 );
    add_action( 'woocommerce_before_cart_totals',  'twshop_classic_cart_wallet',  15 );
    add_action( 'woocommerce_before_cart_totals',  'twshop_classic_cart_coupons', 20 );

    // mini cart 優惠券「還差 $X」摘要（v25.5.94）：跟上面幾個一樣不受模組開關影響、統一在這裡
    // 註冊，函式內部依 twshop_get_coupon_progress_items() 各自判斷 visual_coupons/discount_rules
    // 模組狀態。標準 WooCommerce 掛載點，本站佈景主題 Blocksy 的 mini-cart 樣板也會觸發。
    add_action( 'woocommerce_widget_shopping_cart_before_buttons', 'twshop_render_mini_cart_progress' );

    // ── 會員分級 ──────────────────────────────────────────────────────────
    if ( twshop_module_enabled( 'member_tiers' ) ) {
        add_action( 'woocommerce_order_status_completed', 'twshop_trigger_on_order', 10, 1 );
        add_action( 'wc_membership_daily_downgrade_check', 'twshop_run_daily_check' );
        add_action( 'twshop_send_birthday_gift_notice', 'twshop_send_birthday_gift_notice_email', 10, 3 );
        add_action( 'woocommerce_account_my-membership_endpoint', 'twshop_my_membership_endpoint_content' );
        add_action( 'woocommerce_register_form', 'twshop_add_birthday_field_registration' );
        add_action( 'woocommerce_created_customer', 'twshop_save_birthday_field_registration' );
        add_action( 'woocommerce_edit_account_form', 'twshop_add_birthday_field_frontend' );
        add_action( 'woocommerce_save_account_details', 'twshop_save_birthday_field_frontend' );
        add_action( 'profile_personal_options', 'twshop_birthday_management_ui' );
        add_action( 'edit_user_profile', 'twshop_birthday_management_ui' );
        add_action( 'personal_options_update', 'twshop_save_birthday_management' );
        add_action( 'edit_user_profile_update', 'twshop_save_birthday_management' );
        add_action( 'admin_enqueue_scripts', 'twshop_enqueue_birthday_to_top_script' );
    }

    // ── 視覺化優惠券 ──────────────────────────────────────────────────────
    if ( twshop_module_enabled( 'visual_coupons' ) ) {
        add_action( 'woocommerce_coupon_options', 'twshop_add_visual_coupon_options', 10, 2 );
        add_action( 'woocommerce_coupon_options_save', 'twshop_save_visual_coupon_options', 10, 2 );
        add_action( 'wp_ajax_apply_visual_coupon', 'twshop_apply_visual_coupon' );
        add_action( 'wp_ajax_nopriv_apply_visual_coupon', 'twshop_apply_visual_coupon' );
        add_action( 'wp_ajax_remove_visual_coupon', 'twshop_remove_visual_coupon' );
        add_action( 'wp_ajax_nopriv_remove_visual_coupon', 'twshop_remove_visual_coupon' );
        add_action( 'woocommerce_account_my-coupons_endpoint', 'twshop_my_coupons_endpoint_content' );
        add_filter( 'woocommerce_coupon_message', '__return_empty_string' );
        add_filter( 'woocommerce_cart_totals_coupon_html', 'twshop_hide_cart_coupon_remove_link', 20, 2 );
        add_filter( 'woocommerce_cart_totals_coupon_label', 'twshop_cart_coupon_label_use_title', 10, 2 );
    }

    // ── 折扣規則、贈品與加購 ──────────────────────────────────────────────
    if ( twshop_module_enabled( 'discount_rules' ) ) {
        add_action( 'wp_ajax_twshop_save_rule', 'twshop_ajax_save_rule' );
        add_action( 'wp_ajax_twshop_duplicate_rule', 'twshop_ajax_duplicate_rule' );
        add_action( 'wp_ajax_twshop_delete_rule', 'twshop_ajax_delete_rule' );
        add_action( 'wp_ajax_twshop_reorder_rules', 'twshop_ajax_reorder_rules' );
        add_action( 'wp_ajax_twshop_batch_update_rules', 'twshop_ajax_batch_update_rules' );
        add_filter( 'woocommerce_product_get_price', 'twshop_apply_product_discount_rules', 99, 2 );
        // WC_Product_Variation::get_hook_prefix() 回傳的是 'woocommerce_product_variation_get_'，
        // 不是一般商品的 'woocommerce_product_get_'，規格（variation）物件呼叫 get_price() 觸發的
        // 是完全不同的 hook，上面那行只掛在一般商品的 hook 上，可變商品的規格價格完全不會被套用折扣規則
        // （已實測：加入購物車後價格仍是原價，未套用站上現有的全站折扣規則）。這裡另外掛一次同一個
        // callback 到規格專用的 hook 上，才能讓可變商品的每個規格都正確套用折扣規則。
        add_filter( 'woocommerce_product_variation_get_price', 'twshop_apply_product_discount_rules', 99, 2 );
        // 可變商品「選規格前」的價格區間摘要（例如「NT$400 – NT$500」）不是走上面兩個 get_price() filter，
        // 而是 WC_Product_Variable::get_price_html() → get_variation_prices()，這條路徑刻意讀取未過濾的
        // 原始價格、並存進 30 天的 transient 快取（wc_var_prices_{id}），繞過所有動態價格 filter。
        // WC 官方在 read_price_data() 的原始碼註解裡就寫明了這種情況的標準解法：
        // 掛 woocommerce_variation_prices_price 客製價格，並且「必須」同時掛
        // woocommerce_get_variation_prices_hash 把會影響價格的因子納入快取 key，否則不同使用者/情境
        // 會共用同一份快取、看到不該看到的價格。
        add_filter( 'woocommerce_variation_prices_price', 'twshop_apply_product_discount_rules', 99, 2 );
        add_filter( 'woocommerce_get_variation_prices_hash', 'twshop_add_discount_context_to_variation_price_hash', 10, 1 );
        add_filter( 'woocommerce_product_is_on_sale', 'twshop_product_is_on_sale', 99, 2 );
        // 傳統購物車頁「價格」欄原生只顯示單一數字，特價時看不出原價/折扣，見
        // twshop_cart_item_price_with_strike() 的說明。
        add_filter( 'woocommerce_cart_item_price', 'twshop_cart_item_price_with_strike', 10, 3 );
        add_action( 'woocommerce_cart_calculate_fees', 'twshop_apply_cart_discount_rules', 20, 1 );
        add_filter( 'woocommerce_package_rates', 'twshop_apply_free_shipping_rules', 100, 2 );
        add_filter( 'woocommerce_cart_shipping_packages', 'twshop_add_rules_context_to_shipping_packages' );
        add_action( 'woocommerce_checkout_create_order', 'twshop_store_applied_rule_ids_on_order', 10, 1 );
        add_action( 'woocommerce_checkout_order_processed', 'twshop_increment_rule_usage_limits', 10, 3 );
        add_action( 'woocommerce_before_calculate_totals', 'twshop_auto_manage_gifts_and_addons', 10, 1 );
        add_filter( 'woocommerce_add_cart_item_data', 'twshop_mark_addon_cart_item', 10, 3 );
        add_filter( 'woocommerce_cart_item_quantity', 'twshop_lock_addon_item_quantity', 10, 3 );
        add_action( 'woocommerce_before_cart_table', 'twshop_classic_cart_progress' );
    }

    // ── 紅利點數 ──────────────────────────────────────────────────────────
    if ( twshop_module_enabled( 'points' ) ) {
        foreach ( twshop_get_points_award_statuses() as $twshop_award_status ) {
            add_action( 'woocommerce_order_status_' . $twshop_award_status, 'twshop_award_points_on_order_complete', 15, 1 );
        }
        foreach ( twshop_get_points_revoke_statuses() as $twshop_revoke_status ) {
            add_action( 'woocommerce_order_status_' . $twshop_revoke_status, 'twshop_refund_points_on_order_cancel', 15, 1 );
        }
        add_action( 'woocommerce_order_refunded', 'twshop_handle_order_refund_points', 15, 2 );
        add_action( 'wp_ajax_twshop_apply_points', 'twshop_ajax_apply_points' );
        add_action( 'wp_ajax_nopriv_twshop_apply_points', 'twshop_ajax_apply_points' );
        add_action( 'woocommerce_cart_calculate_fees', 'twshop_apply_points_discount_fee', 25, 1 );
        add_action( 'woocommerce_checkout_create_order', 'twshop_store_points_cash_applied_on_order', 10, 1 );
        add_action( 'woocommerce_checkout_order_processed', 'twshop_deduct_points_on_checkout', 15, 3 );
        add_action( 'woocommerce_cart_emptied', 'twshop_clear_applied_points_on_cart_emptied' );
        // 點數兌換商品
        add_action( 'wp_ajax_twshop_redeem_points_product', 'twshop_ajax_redeem_points_product' );
        add_action( 'wp_ajax_nopriv_twshop_redeem_points_product', 'twshop_ajax_redeem_points_product' );
        // 紅利點數頁「匯入點數資料」，後台限定
        add_action( 'wp_ajax_twshop_import_points_csv', 'twshop_ajax_import_points_csv' );
        add_action( 'admin_post_twshop_download_points_import_template', 'twshop_download_points_import_template' );
        add_action( 'woocommerce_before_calculate_totals', 'twshop_zero_redeemed_product_price', 10, 1 );
        add_filter( 'woocommerce_cart_item_quantity', 'twshop_lock_redeemed_item_quantity', 10, 3 );
        add_action( 'woocommerce_checkout_create_order_line_item', 'twshop_save_points_redeem_order_item_meta', 10, 4 );
        add_action( 'woocommerce_after_checkout_validation', 'twshop_validate_points_redeem_balance', 10, 2 );
        add_action( 'woocommerce_cart_totals_after_order_total', 'twshop_display_points_used', 5 );
        add_action( 'woocommerce_cart_totals_after_order_total', 'twshop_display_estimated_points_earn' );
        add_action( 'woocommerce_review_order_after_order_total', 'twshop_display_points_used', 5 );
        add_action( 'woocommerce_review_order_after_order_total', 'twshop_display_estimated_points_earn' );
        add_action( 'wc_membership_daily_downgrade_check', 'twshop_points_daily_expiry_check' );
        add_action( 'twshop_send_points_expiry_notice', 'twshop_send_points_expiry_notice_email', 10, 3 );
    }

    // ── 儲值金（v25.8.61 第一階段：核心帳本；v25.8.62 第二階段：購物車折抵、結帳扣款、
    //    取消/退款自動退回、訂單 metabox；v25.8.63 第三階段：線上自助儲值；v25.8.64
    //    第四階段：後台列表頁、Email 通知。v25.8.66 起手動加扣搬到「儲值金 ▸ 會員餘額」
    //    頁籤；v25.8.67 起線上儲值改用「儲值金商品」，逐項處理取代整單處理；v25.8.68 起
    //    「儲值金商品」本身改成獨立的 WooCommerce 商品類型，見 CLAUDE.md「儲值金模組」一節）
    //    ───────────────────────────────────────
    if ( twshop_module_enabled( 'wallet' ) ) {
        // 「儲值金商品」的「加入購物車」按鈕掛模組開關——模組關閉時不該再讓顧客買得到，
        // 因為下方付款完成入帳的 hook（`twshop_wallet_credit_on_payment_complete()`）也在
        // 這個 if 區塊內，兩者必須同進退：只藏按鈕、入帳邏輯還在跑 vs. 按鈕還在、入帳邏輯
        // 沒在跑（顧客付了錢卻拿不到儲值金）兩種不一致狀態都不能接受。商品編輯頁的欄位／
        // 存檔／類型註冊本身則是「不受模組開關影響」的區塊，見下方「儲值金商品類型」
        // 一節——那些是編輯能力，跟「現在能不能被顧客買到」是兩回事，不應該因為暫時關掉
        // 模組就連既有商品的資料都編輯不了。
        add_action( 'woocommerce_wallet_credit_add_to_cart', 'woocommerce_simple_add_to_cart', 30 );

        add_action( 'woocommerce_account_my-wallet_endpoint', 'twshop_my_wallet_endpoint_content' );

        // 購物車折抵（twshop_classic_cart_wallet() 本身在上面「不受模組開關影響」的
        // 區塊已註冊，內部自行判斷模組狀態，這裡不重複註冊）
        add_action( 'wp_ajax_twshop_apply_wallet', 'twshop_ajax_apply_wallet' );
        add_action( 'woocommerce_cart_calculate_fees', 'twshop_apply_wallet_discount_fee', 30, 1 );
        add_action( 'woocommerce_checkout_create_order', 'twshop_store_wallet_applied_on_order', 10, 1 );
        add_action( 'woocommerce_after_checkout_validation', 'twshop_validate_wallet_balance', 10, 2 );
        add_action( 'woocommerce_checkout_order_processed', 'twshop_deduct_wallet_on_checkout', 15, 3 );
        add_action( 'woocommerce_cart_emptied', 'twshop_clear_applied_wallet_on_cart_emptied' );

        // 購物車含儲值金商品時限縮可用付款方式（v25.8.79 新增，「儲值中心 ▸ 設定 ▸
        // 允許使用的付款方式」，留空＝不限制）；跟上面加入購物車按鈕同一個 if 區塊，
        // 是業務層面的購買限制，不是商品類型註冊本身。
        add_filter( 'woocommerce_available_payment_gateways', 'twshop_restrict_wallet_credit_payment_gateways' );

        // 儲值金商品強制要求登入才能購買（v25.8.79 新增，見 wallet-topup.php 函式說明）：
        // (a) UX 層——商品不可購買 + 商品頁提示；(b) 結帳送出時的權威防線，跟上面
        // twshop_validate_wallet_balance() 同一個 hook。
        add_filter( 'woocommerce_is_purchasable', 'twshop_restrict_wallet_credit_purchase_for_guest', 10, 2 );
        add_action( 'woocommerce_single_product_summary', 'twshop_render_wallet_credit_login_required_notice', 26 );
        add_action( 'woocommerce_after_checkout_validation', 'twshop_validate_wallet_credit_guest_checkout', 10, 2 );

        // 取消/已退款/付款失敗：全額退回尚未退回的部分。刻意寫死這三個狀態，不像點數
        // 模組那樣走可設定的 wc_points_revoke_statuses——儲值金第一版還沒有自己的
        // 「發放與退還時機」設定頁，之後若要開放自訂再比照點數模組的既有寫法改成迴圈。
        foreach ( array( 'cancelled', 'refunded', 'failed' ) as $twshop_wallet_revoke_status ) {
            add_action( 'woocommerce_order_status_' . $twshop_wallet_revoke_status, 'twshop_refund_wallet_on_order_cancel', 15, 1 );
        }
        add_action( 'woocommerce_order_refunded', 'twshop_handle_order_refund_wallet', 15, 2 );

        // 訂單編輯頁 metabox（HPOS／legacy 兩種畫面，比照 twshop_register_order_logistics_metabox() 的既有寫法）
        add_action( 'add_meta_boxes_shop_order', 'twshop_register_order_wallet_metabox' );
        add_action( 'add_meta_boxes_woocommerce_page_wc-orders', 'twshop_register_order_wallet_metabox' );
        add_action( 'wp_ajax_twshop_wallet_manual_return', 'twshop_ajax_wallet_manual_return' );

        // 線上自助儲值（v25.8.67 起改用「儲值金商品」，wallet-topup.php）：付款完成時
        // 逐項掃描訂單裡的儲值金商品項目入帳 → 訂單取消/退款/失敗時逐項追回 → 部分退款時
        // 只追回被退款的那個項目。跟上面「購物車折抵」共用 cancelled／refunded／failed
        // 三個狀態，但各自的 callback 內部依訂單項目 meta（_twshop_wallet_topup_credited）
        // 或訂單 meta（_twshop_wallet_applied）互斥判斷，同一張訂單的兩組 hook 可以安全
        // 共存；`woocommerce_order_refunded` 同理，兩支各自處理不同方向的退回。
        add_action( 'woocommerce_payment_complete', 'twshop_wallet_credit_on_payment_complete', 10, 1 );
        foreach ( array( 'cancelled', 'refunded', 'failed' ) as $twshop_wallet_topup_revoke_status ) {
            add_action( 'woocommerce_order_status_' . $twshop_wallet_topup_revoke_status, 'twshop_wallet_revoke_topup_order', 15, 1 );
        }
        add_action( 'woocommerce_order_refunded', 'twshop_wallet_handle_topup_item_refund', 15, 2 );
    }

    // ── 結帳與訂單管理強化（台灣地址、超商取貨、訂單物流資訊、訂單管理後台強化，
    //    v25.5.83 統一併入單一模組開關 order_checkout_enhancements。原本這裡是
    //    結帳頁欄位客製化／metabox 各自獨立開關＋訂單物流資訊／訂單管理後台強化
    //    始終啟用，四種狀態並存，改成單一模組開關統一控制，簡化設定介面）────
    if ( twshop_module_enabled( 'order_checkout_enhancements' ) ) {
        // 台灣地址（縣市／鄉鎮市區皆改成下拉選單，不接受自由輸入）
        add_filter( 'woocommerce_states', 'twshop_add_taiwan_states' );
        add_filter( 'woocommerce_get_country_locale', 'twshop_taiwan_address_locale' );
        add_filter( 'woocommerce_billing_fields', 'twshop_taiwan_city_field_as_select', 10, 2 );
        add_filter( 'woocommerce_shipping_fields', 'twshop_taiwan_city_field_as_select', 10, 2 );

        // 郵遞區號改由縣市/鄉鎮市區自動決定：欄位隱藏但照常送出，值由前端 JS ＋ 下面兩道
        // 伺服器端補值共同保證（見 twshop_taiwan_hide_postcode_field() 的說明）
        add_filter( 'woocommerce_billing_fields', 'twshop_taiwan_hide_postcode_field', 10, 2 );
        add_filter( 'woocommerce_shipping_fields', 'twshop_taiwan_hide_postcode_field', 10, 2 );
        add_filter( 'woocommerce_checkout_posted_data', 'twshop_fill_taiwan_postcode_posted_data' );
        // 跨欄位驗證：WooCommerce 原生只驗必填/格式，不會發現「高雄市 ＋ 大安區」這種不存在的組合。
        // **priority 5 必須早於 twshop_cvs_remove_address_errors() 的 10**：那支在超商取貨時會
        // 把地址相關錯誤整批 remove 掉，錯誤要先加進去才清得掉；掛在它後面的話，超商取貨的顧客
        // 會被一個「本來就免填地址」的欄位擋住結帳。
        add_action( 'woocommerce_after_checkout_validation', 'twshop_validate_taiwan_district_match', 5, 2 );
        add_action( 'woocommerce_after_save_address_validation', 'twshop_fill_taiwan_postcode_on_save_address', 10, 4 );

        // 只有一個允許銷售/運送的國家時，隱藏「國家/地區」欄位（不是台灣地址專屬功能，
        // 但跟其他結帳欄位客製化放在同一個模組開關下，見 twshop_hide_single_country_field()）
        add_filter( 'woocommerce_billing_fields', 'twshop_hide_single_country_field' );
        add_filter( 'woocommerce_shipping_fields', 'twshop_hide_single_country_field' );

        // 超商取貨
        add_action( 'admin_init', 'twshop_register_cvs_field_for_shipping' );
        add_filter( 'woocommerce_checkout_fields', 'twshop_cvs_address_optional' );
        add_action( 'woocommerce_after_checkout_validation', 'twshop_cvs_remove_address_errors', 10, 2 );

        // 訂單物流資訊
        add_action( 'woocommerce_order_details_after_order_table', 'twshop_render_order_logistics_info' );
        add_filter( 'woocommerce_shop_order_search_fields', 'twshop_add_logistics_search_fields' );
        add_filter( 'woocommerce_order_table_search_query_meta_keys', 'twshop_add_logistics_search_fields' );
        add_action( 'add_meta_boxes_shop_order', 'twshop_register_order_logistics_metabox' );
        add_action( 'add_meta_boxes_woocommerce_page_wc-orders', 'twshop_register_order_logistics_metabox' );

        // 訂單管理後台強化
        add_filter( 'wc_order_statuses', 'twshop_add_custom_order_statuses' );
        add_filter( 'woocommerce_reports_order_statuses', 'twshop_add_custom_order_statuses' );
        add_filter( 'woocommerce_order_is_paid_statuses', 'twshop_add_custom_paid_statuses' );

        add_filter( 'manage_shop_order_posts_columns', 'twshop_order_list_columns', 11 );
        add_filter( 'manage_woocommerce_page_wc-orders_columns', 'twshop_order_list_columns', 11 );
        add_action( 'manage_shop_order_posts_custom_column', 'twshop_order_list_column_content', 11, 2 );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'twshop_order_list_column_content', 11, 2 );

        add_filter( 'bulk_actions-edit-shop_order', 'twshop_order_bulk_actions', 99 );
        add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'twshop_order_bulk_actions', 99 );
        add_filter( 'handle_bulk_actions-edit-shop_order', 'twshop_handle_order_bulk_status_update', 10, 3 );
        add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'twshop_handle_order_bulk_status_update', 10, 3 );
        add_action( 'admin_notices', 'twshop_order_bulk_admin_notice' );
        add_action( 'woocommerce_order_note_added', 'twshop_maybe_auto_complete_order_from_logistic_note', 10, 2 );
    }

    // ── 商品折扣徽章 ──────────────────────────────────────────────────────
    // 直接複用 WooCommerce 原生 woocommerce_sale_flash（商品彙整頁與單品頁的 span.onsale），
    // 不寫獨立標記/CSS。優先權刻意設很晚（999）：實測目前站台使用的 Blocksy 主題本身也掛了
    // 同一個 filter（優先權 10），且完全忽略前面 filter 傳入的 $html、直接依主題自訂工具設定
    // 重新組出整個 <span>（含 data-shape 等屬性）；若我們掛在更早的優先權，輸出會被主題整個蓋掉、
    // 客製文字完全不會生效（已實際測試驗證）。改成掛在最後，讀取主題處理過的 $html 只替換裡面的
    // 文字內容，保留主題自己加上的屬性/class，徽章外觀（顏色/位置/形狀）才會維持跟主題一致。
    // 不綁在 discount_rules 模組開關下：徽章依據的是最終售價（get_price(), 已套用 twshop 規則）
    // 與原價的落差，即使站台只用 WooCommerce 原生特價（sale_price）、沒有啟用 twshop 折扣規則，
    // 也應該正常顯示。
    add_filter( 'woocommerce_sale_flash', 'twshop_render_discount_badge', 999, 3 );
    add_action( 'woocommerce_product_options_general_product_data', 'twshop_add_badge_product_fields' );
    add_action( 'woocommerce_process_product_meta', 'twshop_save_badge_product_fields' );

    // ── 儲值金商品類型（v25.8.68 起，wallet-topup.php）────────────────────────────
    // 不綁 wallet 模組開關：這三個是 WooCommerce 認得「wallet_credit」這個商品類型本身
    // 需要的最基本註冊（下拉選單選項／PHP 類別解析／編輯頁頁籤可見性），不是「儲值金
    // 功能」這個業務邏輯——已經存在的儲值金商品，即使 wallet 模組被關掉，商品編輯頁的
    // 類型下拉選單仍必須認得這個選項，否則瀏覽器會因為找不到對應的 <option> 而預設選到
    // 第一個選項（通常是「簡單商品」），管理員只是開啟商品編輯頁存個檔（例如改個標題），
    // 就會在毫無警訊的情況下把商品類型悄悄轉成簡單商品；`woocommerce_product_class`
    // 沒有註冊的話，WooCommerce 想要在後台/前台任何地方把這個既有商品實例化時都會找不到
    // 對應類別而出錯。真正決定「這個商品能不能被買、買了會不會入帳」的是下方 wallet
    // 模組開關區塊裡的「加入購物車」按鈕與付款完成入帳 hook，那兩個才是業務邏輯，理所
    // 當然要跟著模組開關走。
    add_filter( 'product_type_selector', 'twshop_wallet_credit_register_product_type' );
    add_filter( 'woocommerce_product_class', 'twshop_wallet_credit_product_class', 10, 2 );
    add_filter( 'woocommerce_product_data_tabs', 'twshop_wallet_credit_product_data_tabs' );
    // 編輯頁欄位／存檔／JS 同樣不綁模組開關——道理跟上面三個一樣：這些是「編輯這個商品
    // 類型的能力」，不是「顧客現在買不買得到」，模組關閉不該連既有資料都動不了。真正的
    // 業務開關（加入購物車按鈕、付款完成入帳）見下方 wallet 模組區塊。
    add_action( 'woocommerce_product_options_general_product_data', 'twshop_add_wallet_credit_product_fields' );
    add_action( 'woocommerce_process_product_meta_wallet_credit', 'twshop_save_wallet_credit_product_fields' );
    add_action( 'admin_enqueue_scripts', 'twshop_enqueue_wallet_credit_product_admin_script' );

    // ── 商品網址（slug）改用商品編號 ────────────────────────────────────────
    // 跟商品折扣徽章一樣不綁模組開關：這是站台層級的網址設定，跟任何一個功能模組都沒有
    // 邏輯關聯——關掉「折扣規則」的人不會預期商品網址跟著變回中文。功能本身由
    // wc_product_slug_use_id 這個 option 控制（預設關閉），關閉時 twshop_enforce_product_id_slug()
    // 第一行就 return，成本可忽略。
    //
    // 三個 hook 都指向同一支冪等函式，理由見 includes/modules/product-slug.php 的
    // twshop_enforce_product_id_slug()：WooCommerce 的 data store 在 doing_action('save_post')
    // 時是直接用 $wpdb 寫 post 資料列、不觸發 wp_insert_post，只掛一組會有漏網之魚。
    add_action( 'wp_insert_post', 'twshop_enforce_product_id_slug', 10, 2 );
    add_action( 'woocommerce_new_product', 'twshop_enforce_product_id_slug' );
    add_action( 'woocommerce_update_product', 'twshop_enforce_product_id_slug' );
    add_filter( 'strict_redirect_guess_404_permalink', 'twshop_product_slug_strict_404_guess' );

    // AJAX action **一律註冊，不能包進任何開關判斷**：批次有「轉換」與「還原」兩個方向，
    // 開關關閉時要跑的正是還原那一邊。這也是 v25.5.20 那條踩坑的同一個道理——
    // action 沒註冊時 WordPress 回傳預設的 "0"，前端 `if (!res.success) return;` 會把它
    // 整個吞掉，使用者只看到按鈕沒反應、沒有任何錯誤訊息。
    add_action( 'wp_ajax_twshop_batch_product_slugs', 'twshop_ajax_batch_product_slugs' );

    // ── 蝦皮串接（v25.8.65 起改由「系統設定 ▸ 蝦皮串接」頁籤自己的 wc_shopee_sync_enabled
    //    開關控制，不再是「系統設定 ▸ 模組開關」裡的一個模組，見 CLAUDE.md「蝦皮串接模組」
    //    一節）─────────────────────────────────────────────────────────
    // 授權回呼／token 續期／建表升級校正／排程對帳這幾支不在這裡：它們刻意不受這顆開關
    // 影響，直接在 includes/modules/shopee-api.php 頂層註冊，理由見該檔案內註解。
    if ( twshop_shopee_sync_enabled() ) {
        // Woo → 蝦皮：庫存/價格異動進待推送佇列，不在 hook 內直接打 API（結帳流程中同步
        // 打外部 API 會拖慢結帳，蝦皮逾時還會讓下單卡住），交給 5 分鐘 cron 批次處理。
        add_action( 'woocommerce_product_set_stock', 'twshop_shopee_queue_push_from_hook' );
        add_action( 'woocommerce_variation_set_stock', 'twshop_shopee_queue_push_from_hook' );
        add_action( 'woocommerce_update_product', 'twshop_shopee_queue_push_from_hook' );
        add_action( 'woocommerce_update_product_variation', 'twshop_shopee_queue_push_from_hook' );

        add_action( 'twshop_shopee_push_queue', 'twshop_shopee_process_push_queue' );
        add_action( 'twshop_shopee_pull_orders', 'twshop_shopee_pull_orders' );
        add_action( 'twshop_shopee_cleanup_log', 'twshop_shopee_cleanup_log' );

        add_action( 'wp_ajax_twshop_shopee_fetch_items', 'twshop_ajax_shopee_fetch_items' );
        add_action( 'wp_ajax_twshop_shopee_link_item', 'twshop_ajax_shopee_link_item' );
        add_action( 'wp_ajax_twshop_shopee_push_now', 'twshop_ajax_shopee_push_now' );
        add_action( 'wp_ajax_twshop_shopee_pull_orders', 'twshop_ajax_shopee_pull_orders' );
        add_action( 'wp_ajax_twshop_shopee_clear_log', 'twshop_ajax_shopee_clear_log' );
    }
}


