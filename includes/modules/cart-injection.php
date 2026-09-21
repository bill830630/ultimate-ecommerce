<?php
/**
 * 傳統 (Classic) 購物車/結帳頁自動注入
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 傳統 (Classic) 購物車/結帳頁自動注入
//
// Block-based 購物車/結帳不會觸發這些 WC 模板 hooks，只影響傳統 shortcode 版型
// （WooCommerce 官方術語：classic 版型 vs block 版型，跟本外掛已移除的短代碼系統無關）。
// 各函式檢查：對應模組已啟用、後台「傳統購物車自動顯示區塊」開關已勾選。
// =========================================================================

function twshop_classic_cart_coupons() {
    if ( ! twshop_module_enabled( 'visual_coupons' ) ) return;
    if ( 'yes' !== twshop_option( 'wc_classic_cart_show_coupons' ) ) return;
    twshop_auto_display_coupons( 'cart' );
}

// 購物車／結帳頁隱藏原生「已套用優惠券」列的 [Remove] 連結：優惠券移除一律走本外掛自己的
// 視覺化優惠券卡片／彈窗（`.twshop-coupon-btn-remove` → `remove_visual_coupon` AJAX），避免
// 顧客透過原生連結繞過本外掛的介面直接移除。classic 版型走 wc_cart_totals_coupon_html()
// （見 woocommerce/includes/wc-cart-functions.php，購物車與結帳頁共用同一支函式，用
// is_cart()/is_checkout() 涵蓋兩者）；block 版型的等效隱藏在 twshop-frontend.css 用 CSS 處理
// （見該檔案說明，PHP filter 對 Store API 純前端渲染的內容不起作用）。
function twshop_hide_cart_coupon_remove_link( $coupon_html, $coupon ) {
    if ( ! is_cart() && ! is_checkout() ) return $coupon_html;
    return preg_replace( '/\s*<a[^>]*class="woocommerce-remove-coupon"[^>]*>.*?<\/a>/i', '', $coupon_html );
}

// 統一取得「視覺化優惠券」的顯示標題，跟優惠券卡片（twshop_auto_display_coupons()）的
// `$display_title = $visual_title ?: $auto_title` 用同一套 fallback 邏輯：用
// metadata_exists() 而非 get_post_meta() 讀到的值判斷「是不是視覺化優惠券」——後台標題欄位
// 留空是常見情況（`_visual_coupon_title` meta key 仍存在，只是值是空字串），此時不能直接
// 當作「非視覺化優惠券」，而是要退回跟卡片一致的自動標題（依折扣類型/金額產生，例如
// 「折抵 NT$200」，見 twshop_format_wc_coupon_discount()）。連 meta key 都不存在，才是真正
// 從未透過本外掛建立的一般優惠券，回傳空字串讓呼叫端維持原本顯示代碼的行為。
function twshop_get_visual_coupon_display_title( $coupon_id, $coupon_obj = null ) {
    if ( ! metadata_exists( 'post', $coupon_id, '_visual_coupon_title' ) ) return '';

    $title = get_post_meta( $coupon_id, '_visual_coupon_title', true );
    if ( '' !== $title ) return $title;

    if ( ! $coupon_obj ) $coupon_obj = new WC_Coupon( $coupon_id );
    $discount_text = twshop_format_wc_coupon_discount( $coupon_obj->get_discount_type(), $coupon_obj->get_amount() );
    // wc_price() 依 WooCommerce 內建的貨幣符號表，貨幣符號本身就是用 HTML 數字實體儲存（例如
    // TWD 的 NT$ 存成 &#78;&#84;&#36;，設計上是給 HTML 直接輸出用）。這裡的呼叫端有兩種消費
    // 方式：PHP 端 esc_html() 印成 HTML（實體不受影響，能正確顯示）沒問題，但前端 JS 是用
    // jQuery .text() 寫入純文字節點（見 twshop-frontend.js 的 rewrite_block_coupon_labels()），
    // 不會解析 HTML 實體，會把 `&#78;` 這種字碼原封不動當文字顯示出來。用 html_entity_decode()
    // 統一還原成真正的純文字（例如「NT$200」），兩種消費端都能正確顯示。
    return html_entity_decode( wp_strip_all_tags( $discount_text ), ENT_QUOTES, 'UTF-8' );
}

// 購物車／結帳頁「已套用優惠券」列的標籤原本是 WooCommerce 自己內建、寫死翻譯的「折價券: 代碼」
// （wc_cart_totals_coupon_label() 裡 esc_html__('Coupon: %s', 'woocommerce')，跟著 WC 語言包
// 翻譯走，不受本外掛控制），改成前綴詞用「一般設定」頁設定的 `wc_general_coupon_noun`（前台統一
// 用詞，例如「優惠券」），跟本外掛自己其他地方（按鈕文字、頁面標題等）用詞一致，不再各講各的；
// 代碼部分改顯示跟卡片一致的標題（視覺化優惠券），非視覺化優惠券（一般優惠券）維持顯示原本的代碼。
function twshop_cart_coupon_label_use_title( $label, $coupon ) {
    $noun  = twshop_option( 'wc_general_coupon_noun' );
    $title = twshop_get_visual_coupon_display_title( $coupon->get_id(), $coupon );
    if ( '' === $title ) $title = $coupon->get_code();
    return esc_html( $noun ) . ': ' . esc_html( $title );
}

// 供前端 JS 使用：Blocks 購物車/結帳頁的「已套用優惠券」是 React 元件（Chip），內容來自 Store API
// 回應（CartCouponSchema 只回傳 `code`，沒有標題欄位），PHP filter 對它不起作用，只能在前端用 JS
// 依代碼對照這份標題清單改寫顯示文字（見 twshop-frontend.js）。key 統一轉大寫比對，因為 Blocks
// 版型的 Chip 文字固定顯示大寫代碼。
function twshop_get_visual_coupon_titles_map() {
    static $map = null;
    if ( null !== $map ) return $map;

    $map = array();
    $coupons = get_posts( array(
        'posts_per_page' => -1,
        'post_type'      => 'shop_coupon',
        'post_status'    => 'publish',
        'no_found_rows'  => true,
        'meta_query'     => array(
            array( 'key' => '_visual_coupon_title', 'compare' => 'EXISTS' ),
        ),
    ) );
    foreach ( $coupons as $c ) {
        $title = twshop_get_visual_coupon_display_title( $c->ID );
        if ( '' === $title ) continue;
        $map[ strtoupper( $c->post_title ) ] = $title;
    }
    return $map;
}

function twshop_classic_cart_addons() {
    if ( ! twshop_module_enabled( 'discount_rules' ) ) return;
    if ( 'yes' !== twshop_option( 'wc_classic_cart_show_addons' ) ) return;
    twshop_render_cart_addons();
}

function twshop_classic_cart_points() {
    if ( ! twshop_module_enabled( 'points' ) ) return;
    if ( 'yes' !== twshop_option( 'wc_classic_cart_show_points' ) ) return;
    twshop_render_points_redemption_ui();
}

// 「點數兌換商品」（v25.8.18 起獨立出來，見 twshop_render_points_redeemable_products_section()，
// includes/modules/points-engine.php）跟「點數折抵」（上面 twshop_classic_cart_points()）
// 沿用同一個後台顯示開關 wc_classic_cart_show_points——沒有另外拆一個開關，兩者一起開/關。
function twshop_classic_cart_redeem_products() {
    if ( ! twshop_module_enabled( 'points' ) ) return;
    if ( 'yes' !== twshop_option( 'wc_classic_cart_show_points' ) ) return;
    twshop_render_points_redeemable_products_section();
}

function twshop_ajax_refresh_components() {
    // S10：這是唯一沒有 nonce 檢查的前台 AJAX handler，未登入者可無限次呼叫觸發四段
    // 完整渲染（優惠券掃描、購物車重算）。比照姊妹 handler twshop_ajax_remove_addon()
    // 補上檢查；前端 twshop-frontend.js 的 refresh_twshop_components() 已同步補送
    // twshop_nonce 參數（見該檔案），否則這裡會直接把三個區塊的 AJAX 刷新弄壞。
    check_ajax_referer( 'twshop_frontend_action', 'twshop_nonce' );
    $location = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : 'cart';

    // 各區塊的渲染函式本身不會檢查模組開關（開關只管「傳統購物車自動注入」hook 有沒有掛載，
    // 見 twshop_classic_cart_addons() 等函式；短代碼系統已在 v25.5.86 移除），
    // 這裡呼叫端補上檢查，讓「即時刷新」跟「頁面第一次載入」的行為一致——模組關閉時
    // 對應區塊回傳空字串，前端才不會在模組關閉後的下一次刷新又把內容變回來。
    $coupons_html = '';
    if ( twshop_module_enabled( 'visual_coupons' ) ) {
        ob_start();
        twshop_auto_display_coupons( $location );
        $coupons_html = ob_get_clean();
    }

    $addons_html = '';
    $progress_html = '';
    if ( twshop_module_enabled( 'discount_rules' ) ) {
        ob_start();
        twshop_render_cart_addons();
        $addons_html = ob_get_clean();

        ob_start();
        twshop_render_cart_progress();
        $progress_html = ob_get_clean();
    }

    // 「點數折抵」與「點數兌換商品」v25.8.18 起分成兩個獨立區塊（各自的 DOM 位置不同，
    // 見 twshop_classic_cart_points()／twshop_classic_cart_redeem_products()），
    // AJAX 刷新也要各自回傳一組 HTML，前端才能各自找到對應的 wrapper 替換
    // （見 assets/js/twshop-frontend.js 的 refresh_twshop_components()）。
    $points_html = '';
    $redeem_products_html = '';
    if ( twshop_module_enabled( 'points' ) ) {
        ob_start();
        twshop_render_points_redemption_ui();
        $points_html = ob_get_clean();

        ob_start();
        twshop_render_points_redeemable_products_section();
        $redeem_products_html = ob_get_clean();
    }

    $wallet_html = '';
    if ( twshop_module_enabled( 'wallet' ) ) {
        ob_start();
        twshop_render_wallet_redemption_ui();
        $wallet_html = ob_get_clean();
    }

    wp_send_json_success( array(
        'coupons_html'         => $coupons_html,
        'addons_html'          => $addons_html,
        'progress_html'        => $progress_html,
        'points_html'          => $points_html,
        'redeem_products_html' => $redeem_products_html,
        'wallet_html'          => $wallet_html,
    ) );
}

function twshop_render_cart_addons() {
    if ( wp_script_is( 'wc-add-to-cart', 'registered' ) ) {
        wp_enqueue_script( 'wc-add-to-cart' );
    }

    if ( ! WC()->cart || WC()->cart->is_empty() ) {
        echo '<div class="twshop-cart-addons-wrapper"></div>';
        return;
    }

    $rules      = twshop_get_rules();
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array( 'customer' );

    $cart_total      = twshop_get_cart_threshold_total( WC()->cart );
    $addon_rules_in_cart = array();
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( isset( $cart_item['twshop_addon_rule_id'] ) ) $addon_rules_in_cart[] = $cart_item['twshop_addon_rule_id'];
    }

    $available_addons = array();
    foreach ( $rules as $rule ) {
        if ( $rule['type'] === 'addon_product' && ! empty( $rule['gift_product_id'] ) ) {
            $addon_id = (int) $rule['gift_product_id'];
            if ( isset( $available_addons[ $addon_id ] ) ) continue; // 同一商品多條加購規則，取排序最前面那條
            if ( twshop_is_discount_rule_valid( $rule, $user_roles, $cart_total, 0 ) ) {
                $available_addons[ $addon_id ] = array(
                    'product_id' => $addon_id,
                    'rule_id'    => $rule['rule_id'],
                    'price'      => floatval( $rule['value'] ),
                    'in_cart'    => in_array( $rule['rule_id'], $addon_rules_in_cart, true ),
                );
            }
        }
    }

    echo '<div class="twshop-cart-addons-wrapper">';
    if ( ! empty( $available_addons ) ) {
        // 建立以 product_id 為 key 的查詢 map
        $addon_map = $available_addons;

        // 用 WP_Query 建立真正的 loop，確保主題所有 hooks（Blocksy ct-media-container 等）正確觸發
        $addon_query = new WP_Query( array(
            'post_type'              => 'product',
            'post__in'               => array_keys( $addon_map ),
            'orderby'                => 'post__in',
            'posts_per_page'         => count( $addon_map ),
            'post_status'            => 'publish',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ) );

        if ( $addon_query->have_posts() ) {
            echo '<div class="twshop-cart-addons woocommerce">';
            echo '<h3 class="twshop-cart-addons-title">' . esc_html( twshop_option( 'wc_addon_section_title' ) ) . '</h3>';

            // 使用 WooCommerce 標準 loop 容器，主題的 hooks 會自動套用正確的 class 與屬性
            woocommerce_product_loop_start();

            while ( $addon_query->have_posts() ) {
                $addon_query->the_post();
                $pid         = get_the_ID();
                $product_obj = wc_get_product( $pid );
                if ( ! $product_obj ) continue;

                // 設定 global $product，讓 WooCommerce template 函式取得正確商品
                $GLOBALS['product'] = $product_obj;

                $addon       = $addon_map[ $pid ];
                $addon_price = $addon['price'];
                $in_cart     = $addon['in_cart'];

                // 覆蓋價格：顯示原價劃線 + 加購特價
                $price_filter = function( $price_html, $prod ) use ( $pid, $addon_price ) {
                    if ( (int) $prod->get_id() !== $pid ) return $price_html;
                    $regular = wc_get_price_to_display( $prod, array( 'price' => $prod->get_regular_price() ) );
                    $special = wc_get_price_to_display( $prod, array( 'price' => $addon_price ) );
                    return wc_format_sale_price( $regular, $special );
                };

                // 覆蓋按鈕：換成後台設定的文字（預設「加入加購」/「已在購物車」）
                $btn_add_text    = twshop_option( 'wc_addon_btn_add_text' );
                $btn_incart_text = twshop_option( 'wc_addon_btn_incart_text' );
                $addon_rule_id = $addon['rule_id'];
                $button_filter = function( $html, $prod, $args ) use ( $pid, $in_cart, $btn_add_text, $btn_incart_text, $addon_rule_id ) {
                    if ( (int) $prod->get_id() !== $pid ) return $html;
                    if ( $in_cart ) {
                        return sprintf(
                            '<button type="button" data-product_id="%d" class="button twshop-remove-addon-btn" style="background-color:#dc3232!important;color:#fff!important;border-color:#dc3232!important;">%s</button>',
                            esc_attr( $pid ),
                            esc_html( $btn_incart_text )
                        );
                    }
                    // data-twshop_addon 會被 WooCommerce add-to-cart.js 一起 POST，由 twshop_mark_addon_cart_item() 標記成加購項目。
                    return sprintf(
                        '<a href="%s" data-quantity="1" data-product_id="%d" data-twshop_addon="%s" class="button product_type_simple add_to_cart_button ajax_add_to_cart">%s</a>',
                        esc_url( add_query_arg( array( 'add-to-cart' => $pid, 'twshop_addon' => $addon_rule_id ), wc_get_cart_url() ) ),
                        esc_attr( $pid ),
                        esc_attr( $addon_rule_id ),
                        esc_html( $btn_add_text )
                    );
                };

                // 強制讓目錄可見性為「隱藏」的加購商品通過 content-product.php 的 is_visible() 檢查
                $visibility_filter = function( $visible, $product_id ) use ( $pid ) {
                    return ( (int) $product_id === $pid ) ? true : $visible;
                };

                add_filter( 'woocommerce_product_is_visible',    $visibility_filter, 999, 2 );
                add_filter( 'woocommerce_get_price_html',        $price_filter,      999, 2 );
                // WooCommerce 9.2+ 使用 woocommerce_loop_add_to_cart_link；舊版用 woocommerce_loop_add_to_cart_html
                add_filter( 'woocommerce_loop_add_to_cart_link', $button_filter,     999, 3 );
                add_filter( 'woocommerce_loop_add_to_cart_html', $button_filter,     999, 3 );

                // 使用 WooCommerce 標準商品模板，主題樣式（Blocksy ct-media-container 等）自動套用
                wc_get_template_part( 'content', 'product' );

                remove_filter( 'woocommerce_product_is_visible',    $visibility_filter, 999 );
                remove_filter( 'woocommerce_get_price_html',        $price_filter,      999 );
                remove_filter( 'woocommerce_loop_add_to_cart_link', $button_filter,     999 );
                remove_filter( 'woocommerce_loop_add_to_cart_html', $button_filter,     999 );
            }

            wp_reset_postdata();
            woocommerce_product_loop_end();
            echo '</div>';
        }
    }
    echo '</div>';
}

function twshop_ajax_remove_addon() {
    check_ajax_referer( 'twshop_frontend_action', 'twshop_nonce' );
    $product_id = absint( $_POST['product_id'] ?? 0 );
    if ( ! $product_id || ! WC()->cart ) {
        wp_send_json_error( array( 'message' => '發生錯誤' ) );
    }
    // 優先移除加購項目／點數兌換項目，找不到才退回移除同商品的一般項目（相容改版前加入、沒有標記的舊加購項目）。
    $fallback_key = null;
    foreach ( WC()->cart->get_cart() as $key => $item ) {
        if ( (int) $item['product_id'] !== $product_id || isset( $item['twshop_gift_rule_id'] ) || isset( $item['twshop_bxgy_rule_id'] ) ) continue;
        if ( isset( $item['twshop_addon_rule_id'] ) || isset( $item['twshop_points_redeem_product_id'] ) ) {
            WC()->cart->remove_cart_item( $key );
            wp_send_json_success( array( 'message' => '已移除' ) );
        }
        if ( null === $fallback_key ) $fallback_key = $key;
    }
    if ( null !== $fallback_key ) {
        WC()->cart->remove_cart_item( $fallback_key );
        wp_send_json_success( array( 'message' => '已移除' ) );
    }
    wp_send_json_error( array( 'message' => '商品不在購物車中' ) );
}

/**
 * 判斷目前頁面是否需要載入 twshop 前端資源（購物車/結帳/會員中心），避免全站每一頁都載入。
 */
function twshop_frontend_assets_needed() {
    if ( function_exists( 'is_cart' ) && is_cart() ) return true;
    if ( function_exists( 'is_checkout' ) && is_checkout() ) return true;
    if ( function_exists( 'is_account_page' ) && is_account_page() ) return true;

    return false;
}

/**
 * filemtime() 是一次檔案系統 stat，twshop_global_frontend_js() 內原本對 4 個不同檔案
 * 各呼叫一次；改用 static cache 依相對路徑存起來，同一次請求內若這支函式被觸發不只一次
 * （例如同一 pageload 中 wp_enqueue_scripts 被多次觸發的邊界情況），不會重複 stat 同一個檔案。
 */
function twshop_asset_version( $relative_path ) {
    static $cache = array();
    if ( ! isset( $cache[ $relative_path ] ) ) {
        $cache[ $relative_path ] = filemtime( TWSHOP_PLUGIN_DIR . $relative_path );
    }
    return $cache[ $relative_path ];
}

function twshop_global_frontend_js() {
    if ( ! function_exists( 'is_woocommerce' ) ) return;
    if ( ! twshop_frontend_assets_needed() ) return;

    wp_enqueue_style(
        'twshop-frontend',
        TWSHOP_PLUGIN_URL . 'assets/css/twshop-frontend.css',
        array(),
        twshop_asset_version( 'assets/css/twshop-frontend.css' )
    );

    wp_enqueue_script(
        'twshop-frontend',
        TWSHOP_PLUGIN_URL . 'assets/js/twshop-frontend.js',
        array( 'jquery' ),
        twshop_asset_version( 'assets/js/twshop-frontend.js' ),
        true
    );

    $needs_coupon_titles = twshop_module_enabled( 'visual_coupons' )
        && ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) );

    wp_localize_script( 'twshop-frontend', 'twshopData', array(
        'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
        'nonce'           => wp_create_nonce( 'twshop_frontend_action' ),
        // checkoutFieldCustomization：結帳頁欄位客製化總開關的即時狀態，twshop-frontend.js
        // 用它決定要不要執行「運送方式選單搬到地址欄位前」＋「超商取貨隱藏地址欄位」整段邏輯
        // （見 assets/js/twshop-frontend.js「CVS Shipping」區塊）。關閉時 PHP 端對應的 5 個
        // hook（台灣地址 2 個、超商取貨 3 個）也都沒有掛載，前端這段邏輯若還是照跑，會出現
        // 「畫面上把地址欄位藏起來/搬動選單，但伺服器其實不理會這些客製化規則」的不一致狀況。
        'checkoutFieldCustomization' => twshop_module_enabled( 'order_checkout_enhancements' ),
        // needsShipping：頁面載入當下購物車是否需要運送（WC_Cart::needs_shipping()，全部
        // 品項皆為虛擬商品——例如只買儲值金商品——時為 false）。twshopInitShippingPlaceholder()
        // 用它決定要不要插入「運送方式」placeholder 區塊：不需要運送時 WooCommerce 核心
        // 本來就不會輸出 #order_review ul#shipping_method，若沒有這道判斷，placeholder
        // 仍會被無條件插入，變成一個寫著「運送方式」卻永遠搬不到任何選項進去的空白區塊
        // （2026-09-16 使用者實測回報：購買儲值金商品時看到這個空區塊）。跟 cvsMethods 一樣
        // 只是頁面載入當下的快照——結帳頁本身不會讓顧客中途增減購物車內容，不需要動態更新。
        'needsShipping'   => (bool) ( function_exists( 'WC' ) && WC()->cart && WC()->cart->needs_shipping() ),
        // isCheckout：twshop-frontend.js 用它把「運送方式選單搬到地址欄位前」限定只在結帳頁執行。
        // twshop-frontend.js 也會在「我的帳號 ▸ 編輯地址」頁載入（is_account_page() 也在
        // twshop_frontend_assets_needed() 的條件內），但那個頁面的地址表單跟結帳頁共用同一套
        // #billing_postcode_field 等欄位 id，若不分頁面一律執行 twshopInitShippingPlaceholder()，
        // 會在編輯地址頁插入一個寫著「運送方式」的空白區塊——那裡從來就沒有運送方式可選
        // （#order_review ul#shipping_method 只存在於結帳頁），搬移邏輯永遠找不到東西可搬，
        // 留下的只有這個沒有內容、卻仍顯示標籤文字的空區塊。
        'isCheckout'      => ( function_exists( 'is_checkout' ) && is_checkout() ),
        // cvsMethods 只有在上面那個總開關開著時才有意義；關閉時保留回傳空陣列當第二層防線
        // （即使未來有其他程式碼忘記檢查 checkoutFieldCustomization 直接讀這個值，也不會誤判）。
        'cvsMethods'      => twshop_module_enabled( 'order_checkout_enhancements' ) ? twshop_get_cvs_method_strings() : array(),
        // addressLinkageMethods：勾了「台灣地址下拉選單連動」的運送方式清單，寫法跟
        // cvsMethods 對稱，供 twshop-tw-postcode.js 的 twshopToggleCityFieldType() 判斷
        // 使用者當下選的運送方式要不要把「鄉鎮市區」欄位換成下拉選單。
        'addressLinkageMethods' => twshop_module_enabled( 'order_checkout_enhancements' ) ? twshop_get_address_linkage_method_strings() : array(),
        'couponTitles'    => $needs_coupon_titles ? twshop_get_visual_coupon_titles_map() : array(),
        'couponNoun'      => twshop_option( 'wc_general_coupon_noun' ),
        'couponBtnRemoveText' => twshop_option( 'wc_coupon_btn_remove_text' ),
        'couponBtnApplyText'  => twshop_option( 'wc_coupon_btn_apply_text' ),
    ) );

    // 郵遞區號自動帶入縣市/鄉鎮市區：跟「結帳頁欄位客製化」同一個開關（該功能本來就是台灣地址
    // 客製化的延伸），只在結帳頁與「我的帳號」（含編輯地址頁）才會用到地址欄位，其餘頁面即使
    // 有載入 twshop-frontend.js（例如純粹因為購物車頁的優惠券/加購/點數區塊而載入）也不需要
    // 這份資料，額外拆成獨立檔案（而非塞進 twshop-frontend.js）就是為了讓這種情況不用多載入這份資料。
    if (
        twshop_module_enabled( 'order_checkout_enhancements' )
        && ( ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() ) )
    ) {
        wp_enqueue_script(
            'twshop-tw-postcode',
            TWSHOP_PLUGIN_URL . 'assets/js/twshop-tw-postcode.js',
            array( 'jquery' ),
            twshop_asset_version( 'assets/js/twshop-tw-postcode.js' ),
            true
        );
    }

    if ( function_exists( 'is_account_page' ) && is_account_page() ) {
        wp_enqueue_script(
            'twshop-account-nav',
            TWSHOP_PLUGIN_URL . 'assets/js/twshop-account-nav.js',
            array( 'jquery' ),
            twshop_asset_version( 'assets/js/twshop-account-nav.js' ),
            true
        );

        $tab_icons = get_option( 'wc_account_tab_icons', twshop_get_account_tab_icon_defaults() );
        $tab_icons = is_array( $tab_icons ) ? array_filter( $tab_icons ) : array();
        // 傳的是完整 SVG 原始碼（而非 icon slug），前台 JS 才不用另外打包一份圖示對照表；
        // SVG 內容全部來自外掛自己 assets/icons/ 下的固定檔案（見 twshop_get_account_tab_icon_svg()），
        // 不是使用者可自由輸入的文字，直接輸出無 XSS 疑慮。
        $tab_icon_svgs = array();
        foreach ( $tab_icons as $tab_slug => $icon_slug ) {
            $svg = twshop_get_account_tab_icon_svg( $icon_slug );
            if ( '' !== $svg ) $tab_icon_svgs[ $tab_slug ] = $svg;
        }
        wp_localize_script( 'twshop-account-nav', 'twshopAccountNavData', array(
            'tabIcons' => $tab_icon_svgs,
        ) );

        // 佈景主題（如 Blocksy）常會自行透過 a:before 幫每個頁籤加上圖示（含一個沒有特別 override 時套用的預設圖示），
        // 只要該頁籤設定了自訂圖示，就用 !important 蓋掉主題的 :before 內容，避免自訂圖示與主題圖示並排顯示兩個。
        if ( ! empty( $tab_icons ) ) {
            $selectors = array();
            foreach ( array_keys( $tab_icons ) as $tab_slug ) {
                $selectors[] = '.woocommerce-MyAccount-navigation-link--' . sanitize_html_class( $tab_slug ) . ' > a:before';
            }
            wp_add_inline_style( 'twshop-frontend', implode( ',', $selectors ) . '{content:none!important;}' );
        }
    }
}

/**
 * 登入／註冊按鈕文字：sitewide 版本，wp_footer 印一小段不依賴 jQuery 的原生 JS。
 * 兩個 option 都留空時完全不輸出（零成本，站台預設值就是空字串）。
 *
 * 不能只靠 twshop_global_frontend_js() 裡走 twshop-frontend.js 的舊做法（見 CLAUDE.md
 * 「前端 JS」章節）：那支 bundle 只在 twshop_frontend_assets_needed() 判定的頁面
 * （購物車/結帳/我的帳號）才會 enqueue，但佈景主題（Blocksy）
 * 頁首的「帳戶」彈出登入視窗可以從任何頁面開啟（首頁、商店、商品頁……），
 * 這些頁面完全沒載入 twshop-frontend.js。而且 Blocksy 彈窗本身是完全不同的表單 markup
 * （按鈕 class 是 .ct-account-login-submit / .ct-account-register-submit，不是 WC 核心
 * 模板的 .woocommerce-form-login__submit / .woocommerce-form-register__submit），整段
 * HTML 一開始放在 <template id="ct-account-modal-template"> 裡（inert，查詢不到），
 * 使用者點擊頁首帳戶圖示時才由 JS clone 進真正的 DOM
 * （blocksy-companion/framework/features/header/account-modal.php ＋
 * static/js/account.js 的 registerDynamicChunk('blocksy_account', ...)），
 * 就算選對了 class，document.ready 當下這個節點也還不存在。
 *
 * 用 MutationObserver 解決「節點晚出現」的問題：先套用一次現有節點，之後任何新插入的節點
 * （含 Blocksy 彈窗第一次被點開時整段 clone 進來的面板）都會再套用一次。
 *
 * 效能：監看目標從 document.body（subtree:true，等於全站每頁常駐監看整個頁面的 DOM 異動）
 * 收斂為 Blocksy 的 .ct-drawer-canvas——確認 blocksy-companion 原始碼（static/js/account.js
 * 的 registerDynamicChunk('blocksy_account', ...)）後得知，彈窗永遠是用
 * document.querySelector('.ct-drawer-canvas').insertAdjacentHTML('beforeend', ...) 插入這個
 * 固定容器，而 .ct-drawer-canvas 本身是佈景主題 blocksy_output_drawer_canvas()
 * （inc/footer.php）在頁尾伺服器端直接輸出的靜態 HTML、頁面載入當下就已存在於 DOM——
 * 只有彈窗「內容」本身延遲到點擊才 clone 進來，容器不是。因此把觀察範圍收斂到這個容器不會
 * 讓「彈窗第一次被點開時覆蓋不到按鈕文字」的問題重新出現，只是不再浪費資源監看容器以外、
 * 跟這個彈窗無關的頁面內容變動。找不到 .ct-drawer-canvas 時（理論上不應發生）退回監看
 * document.body，維持原本的保底行為。
 *
 * .ct-account-login-submit / .ct-account-register-submit 按鈕內含一個 SVG 送出中的
 * loading 圖示，不能像 WC 核心按鈕一樣直接整個 textContent 蓋掉，只替換按鈕內第一個
 * 文字節點，SVG 節點原封不動。
 */
function twshop_login_register_btn_text_inline_js() {
    $login_text    = get_option( 'wc_login_btn_text', '' );
    $register_text = get_option( 'wc_register_btn_text', '' );

    if ( '' === $login_text && '' === $register_text ) return;
    ?>
    <?php twshop_enqueue_asset_script( 'frontend/login-register-btn', array(
        'twshopLoginRegister' => array( 'loginText' => $login_text, 'registerText' => $register_text ),
    ), array() ); ?>
    <?php
}

/**
 * 手機版會員中心頁籤橫向滑動功能開關（預設啟用）。
 * 有勾選才加上 body class，twshop-frontend.css 的手機版樣式皆以此 class 為前提，
 * 沒有這個 class 時完全沿用佈景主題原本的直向清單。
 */
function twshop_maybe_add_account_tab_mobile_scroll_class( $classes ) {
    if ( function_exists( 'is_account_page' ) && is_account_page() && 'yes' === twshop_option( 'wc_account_tab_mobile_scroll' ) ) {
        $classes[] = 'twshop-account-tab-mobile-scroll';
    }
    return $classes;
}

