<?php
/**
 * 6.1 購物車滿額/滿件進度提示條
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 6.1 購物車滿額/滿件進度提示條
// =========================================================================

/**
 * 收集購物車頁「滿額/滿件進度提示」資料：掃描 free_gift／tiered_cart 兩種
 * 型別的規則，用 twshop_is_discount_rule_valid( $rule, $user_roles, PHP_INT_MAX, 0 ) 驗證
 * 「除了金額門檻以外」的其餘條件（角色/日期區間/使用次數等）是否成立——傳入一個大到不可能
 * 達不到的購物車金額，讓函式內部的 min_amount 判斷必定通過，藉此重用同一支驗證函式，不需要
 * 另外重寫一份忽略金額門檻的驗證邏輯。回傳依門檻金額由小到大排序的陣列，每筆含
 * threshold/remaining/percent/achieved，供 twshop_render_cart_progress() 呈現。
 */
/**
 * 把「threshold/title/achieved_title」格式的原始項目補上 achieved/remaining/percent，
 * 依門檻由小到大排序。抽成共用函式（v25.5.94），讓 twshop_get_cart_progress_items()
 * 與 mini cart 摘要（twshop_render_mini_cart_progress()）共用同一套計算，
 * 不必各自重寫一次幾乎一樣的排序/百分比邏輯。
 */
function twshop_finalize_progress_items( array $items, $cart_total ) {
    usort( $items, function( $a, $b ) { return $a['threshold'] <=> $b['threshold']; } );

    foreach ( $items as &$item ) {
        $item['achieved']  = $cart_total >= $item['threshold'];
        $item['remaining'] = max( 0, $item['threshold'] - $cart_total );
        $item['percent']   = $item['threshold'] > 0 ? min( 100, round( ( $cart_total / $item['threshold'] ) * 100 ) ) : 100;
    }
    unset( $item );

    return $items;
}

/**
 * 掃描目前「除了金額以外」條件都成立、但購物車尚未達到最低消費門檻的視覺化優惠券
 * （WooCommerce 原生優惠券；折扣規則衍生的卡券已於 v25.8.50 移除），組成跟折扣規則進度項目相同格式的原始項目（尚未補上
 * achieved/remaining/percent，見 twshop_finalize_progress_items()），供「滿額進度提示」
 * 區塊與 mini cart 摘要共用（v25.5.94）。已使用完（個人使用上限）／已過期／全站已達使用上限／
 * 僅供手動輸入的優惠券不列入，排除邏輯比照 twshop_auto_display_coupons() 但刻意簡化
 * （這裡只是提示用的摘要清單，不需要 shop_url／discount_text 等卡片專用欄位）。
 */
/**
 * 這支函式的輸出（優惠券清單本身與各自的最低消費門檻）完全不依賴購物車小計——「是否已達標」
 * 是呼叫端 twshop_get_cart_progress_items() 最後透過 twshop_finalize_progress_items( $items,
 * $cart_total ) 才另外算出來的，不在這裡。因此可以安全地只用
 * 「當前使用者」當 key（user_id + roles，guest 一律 user_id=0，同一批 guest 共用同一份結果，
 * 語意等價，因為 guest 之間 email/roles 本來就相同、都會走同一組判斷分支）。
 */
function twshop_get_coupon_progress_items() {
    $user_id    = get_current_user_id();
    $email      = is_user_logged_in() ? wp_get_current_user()->user_email : '';
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array( 'customer' );

    static $cache = array();
    $cache_key = $user_id . '|' . implode( ',', $user_roles );
    if ( array_key_exists( $cache_key, $cache ) ) return $cache[ $cache_key ];

    $items      = array();

    if ( twshop_module_enabled( 'visual_coupons' ) ) {
        $coupons = get_posts( array(
            'posts_per_page' => -1,
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'no_found_rows'  => true,
            'meta_query'     => array( array( 'key' => '_visual_coupon_title', 'compare' => 'EXISTS' ) ),
        ) );
        foreach ( $coupons as $c ) {
            if ( 'yes' === get_post_meta( $c->ID, '_visual_coupon_manual_only', true ) ) continue;
            $coupon_obj = new WC_Coupon( $c->ID );

            $expires = $coupon_obj->get_date_expires();
            if ( $expires && $expires < current_datetime() ) continue;

            $u_limit = $coupon_obj->get_usage_limit();
            if ( $u_limit > 0 && $coupon_obj->get_usage_count() >= $u_limit ) continue;

            if ( is_user_logged_in() ) {
                $used_by = $coupon_obj->get_used_by();
                $u_count = 0;
                if ( is_array( $used_by ) ) { foreach ( $used_by as $used ) { if ( strtolower( $used ) === strtolower( $email ) || (string) $used === (string) $user_id ) { $u_count++; } } }
                $p_limit = $coupon_obj->get_usage_limit_per_user();
                if ( $p_limit > 0 && $u_count >= $p_limit ) continue;
            }

            $restrictions = $coupon_obj->get_email_restrictions();
            if ( ! empty( $restrictions ) && ( ! is_user_logged_in() || ! in_array( $email, $restrictions ) ) ) continue;

            $min = floatval( $coupon_obj->get_minimum_amount() );
            if ( $min <= 0 ) continue;

            $title = get_post_meta( $c->ID, '_visual_coupon_title', true ) ?: wp_strip_all_tags( twshop_format_wc_coupon_discount( $coupon_obj->get_discount_type(), $coupon_obj->get_amount() ) );
            $items[] = array(
                'type'           => 'coupon',
                'threshold'      => $min,
                'title'          => '再消費 {amount} 即可使用「' . esc_html( $title ) . '」',
                'achieved_title' => '🎉 可使用「' . esc_html( $title ) . '」了',
            );
        }
    }


    $cache[ $cache_key ] = $items;
    return $items;
}

/**
 * 與上面 twshop_get_coupon_progress_items() 不同，這支函式的結果「是否已達標」直接依賴購物車
 * 小計（$cart_total 進了 twshop_finalize_progress_items()），而小計在同一次請求內可能因
 * calculate_totals() 被多次呼叫而改變（見檔頭需求說明）。因此 key 把 $cart_total 本身也納入——
 * 這樣無論同一次請求內小計變動幾次，每種小計組合都會各自對應到正確的快取結果，不會把不同
 * 小計下的「已達標」狀態混用。另外 tiered_cart 型別的「下一階門檻」判斷、free_gift 的贈品名稱
 * 皆與使用者角色/購物車內容有關，一併納入 user_id/roles。
 */
function twshop_get_cart_progress_items() {
    if ( ! WC()->cart || WC()->cart->is_empty() ) return array();

    $user_id    = get_current_user_id();
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array( 'customer' );
    $cart_total = WC()->cart->get_subtotal();

    static $cache = array();
    $cache_key = $cart_total . '|' . $user_id . '|' . implode( ',', $user_roles );
    if ( array_key_exists( $cache_key, $cache ) ) return $cache[ $cache_key ];

    $rules = twshop_get_rules();
    $items = array();

    // N+1 修正：free_gift 規則的贈品標題透過 get_the_title() 逐一查詢，改成先收集所有可能用到
    // 的贈品商品 ID，用 _prime_post_caches() 一次預熱 post cache，後面迴圈內的 get_the_title()
    // 就不會各自觸發查詢。
    $gift_ids_to_prime = array();
    foreach ( $rules as $rule ) {
        if ( 'free_gift' === $rule['type'] && ! empty( $rule['min_amount'] ) && ! empty( $rule['gift_product_id'] ) ) {
            $gift_ids_to_prime[] = (int) $rule['gift_product_id'];
        }
    }
    if ( ! empty( $gift_ids_to_prime ) ) {
        _prime_post_caches( array_unique( $gift_ids_to_prime ), false, false );
    }

    foreach ( $rules as $rule ) {
        if ( ! twshop_is_discount_rule_valid( $rule, $user_roles, PHP_INT_MAX, 0 ) ) continue;

        if ( 'free_gift' === $rule['type'] && ! empty( $rule['min_amount'] ) && ! empty( $rule['gift_product_id'] ) ) {
            $gift_name = esc_html( get_the_title( $rule['gift_product_id'] ) );
            $items[] = array(
                'type'           => 'gift',
                'threshold'      => floatval( $rule['min_amount'] ),
                'title'          => '再消費 {amount} 即可獲得贈品「' . $gift_name . '」',
                'achieved_title' => '🎁 已獲得贈品「' . $gift_name . '」',
            );
        } elseif ( 'tiered_cart' === $rule['type'] ) {
            $tiers = is_array( $rule['tiers'] ?? null ) ? $rule['tiers'] : array();
            $next  = null;
            foreach ( $tiers as $tier ) {
                $t_min = floatval( $tier['min_amount'] ?? 0 );
                if ( $t_min <= 0 || $cart_total >= $t_min ) continue;
                if ( null === $next || $t_min < $next['min_amount'] ) {
                    $discount_text = twshop_format_rule_discount( ( ( $tier['discount_type'] ?? 'fixed' ) === 'percent' ) ? 'percent' : 'fixed_product', $tier['value'] ?? 0 );
                    $next = array( 'min_amount' => $t_min, 'discount_text' => $discount_text );
                }
            }
            if ( null !== $next ) {
                $items[] = array(
                    'type'           => 'tiered',
                    'threshold'      => $next['min_amount'],
                    'title'          => '再消費 {amount} 即可享「' . $next['discount_text'] . '」',
                    'achieved_title' => '',
                );
            }
        }
    }

    // v25.5.94：優惠券（未達最低消費）的進度項目併入同一個清單，一起排序/顯示，
    // 讓「滿額進度提示」區塊除了免運/贈品/階梯折扣，也能提示「還差多少可以用哪張優惠券」。
    $items = array_merge( $items, twshop_get_coupon_progress_items() );

    $result = twshop_finalize_progress_items( $items, $cart_total );
    $cache[ $cache_key ] = $result;
    return $result;
}

/**
 * 輸出一組進度項目（免運/贈品/階梯折扣/優惠券皆共用同一份標記，見 twshop_get_cart_progress_items()／
 * twshop_get_coupon_progress_items() 的 'type' 欄位）的 HTML 列表，供購物車頁上方區塊／
 * 購物車總計區塊的免運提示共用同一份渲染邏輯，避免各自重寫一次幾乎相同的迴圈（v25.5.96）。
 */
function twshop_render_progress_item_list( $items ) {
    if ( empty( $items ) ) return;
    echo '<div class="twshop-cart-progress-list">';
    foreach ( $items as $item ) {
        $achieved = $item['achieved'];
        echo '<div class="twshop-cart-progress-item' . ( $achieved ? ' is-achieved' : '' ) . '">';
        if ( $achieved ) {
            if ( '' !== $item['achieved_title'] ) {
                echo '<div class="twshop-cart-progress-text">' . esc_html( $item['achieved_title'] ) . '</div>';
            }
        } else {
            echo '<div class="twshop-cart-progress-text">' . str_replace( '{amount}', '<strong>' . wc_price( $item['remaining'] ) . '</strong>', $item['title'] ) . '</div>';
        }
        echo '<div class="twshop-cart-progress-track"><div class="twshop-cart-progress-fill" style="width:' . esc_attr( $item['percent'] ) . '%;"></div></div>';
        echo '</div>';
    }
    echo '</div>';
}

/**
 * 購物車頁「滿額/滿件進度」區塊：贈品／階梯折扣／優惠券三種購買誘因提示的進度條。
 *
 * 免運進度提示已於 2026-08-21 整個移除（v25.5.96 曾把它拆到「購物車總計」的運費列
 * 上方，該顯示位置與相關函式一併拿掉），因此這裡不再需要過濾任何型別——
 * twshop_get_cart_progress_items() 本來就不會再產出 free_shipping 項目。免運規則本身
 * （twshop_apply_free_shipping_rules()）不受影響，滿額仍照常免運，只是不再提示還差多少。
 *
 * 「限時優惠倒數」（商品頁／購物車頁的規則到期倒數計時）已於 v25.8.19 整個移除
 * （twshop_render_countdown()／twshop_render_product_countdown()／
 * twshop_get_nearest_deadline_for_product()／twshop_get_nearest_cart_deadline() 都已刪除），
 * 這個區塊原本兩者共用同一個 wrapper，現在只剩滿額/滿件進度，wrapper／option/開關
 * （wc_classic_cart_show_progress）都沿用原名沒有改，避免動到既有資料庫欄位。
 */
function twshop_render_cart_progress() {
    echo '<div class="twshop-cart-progress-wrapper">';
    if ( WC()->cart && ! WC()->cart->is_empty() ) {
        twshop_render_progress_item_list( twshop_get_cart_progress_items() );
    }
    echo '</div>';
}

function twshop_classic_cart_progress() {
    if ( ! twshop_module_enabled( 'discount_rules' ) ) return;
    if ( 'yes' !== twshop_option( 'wc_classic_cart_show_progress' ) ) return;
    twshop_render_cart_progress();
}

/**
 * v25.5.94：mini cart（頁首購物車小圖示的下拉選單，本站佈景主題 Blocksy 的
 * woocommerce/cart/mini-cart.php 覆寫樣板仍是標準 WooCommerce 掛載點，
 * `woocommerce_mini_cart()`／`WC_Widget_Cart` 都會觸發這裡）新增優惠券「還差 $X」摘要——
 * 只列優惠券項目，不含免運/贈品/階梯折扣（那些維持只在購物車頁「滿額進度提示」區塊顯示，
 * mini cart 空間有限，只放跟本次新增的優惠券提示最直接相關的內容）。
 *
 * v25.5.96 曾額外加入免運進度項目，2026-08-21 隨免運提示功能整個移除，回到只列優惠券。
 * 贈品/階梯折扣兩種類型從來就不列入 mini cart（空間有限，只放最直接相關的內容）。
 *
 * 不透過 twshop_frontend_assets_needed() 判斷是否載入 assets/css/twshop-frontend.css——
 * mini cart 是頁首元件，理論上任何頁面都可能觸發（該函式目前只在購物車/結帳/會員中心/
 * 商品頁等特定頁面才載入該檔案），改成這裡自己印一段自包含的 <style>，不依賴外部樣式表
 * 是否已載入。
 *
 * 掛載點不受任何模組開關限制（`twshop_get_cart_progress_items()` 內部已經分別判斷
 * visual_coupons／discount_rules 兩個模組），跟 twshop_classic_cart_addons() 等「傳統購物車
 * 自動注入」hook 一樣，統一在 twshop_membership_init() 裡不受模組開關影響的區塊註冊。
 */
function twshop_render_mini_cart_progress() {
    if ( ! WC()->cart || WC()->cart->is_empty() ) return;

    // 只列優惠券項目。它不受 discount_rules 模組與 wc_classic_cart_show_progress 開關影響——
    // 那是獨立的 visual_coupons 模組功能（是否啟用已經在 twshop_get_coupon_progress_items()
    // 內部個別判斷過），沿革見上方 v25.5.94 說明。
    $items = array_values( array_filter( twshop_get_cart_progress_items(), function( $item ) {
        return 'coupon' === $item['type'];
    } ) );
    if ( empty( $items ) ) return;

    ?>
    <div class="twshop-minicart-progress">
        <?php foreach ( $items as $item ) : ?>
        <div class="twshop-minicart-progress-item<?php echo $item['achieved'] ? ' is-achieved' : ''; ?>">
            <?php if ( $item['achieved'] ) : ?>
                <?php if ( '' !== $item['achieved_title'] ) : ?>
                <div class="twshop-minicart-progress-text"><?php echo esc_html( $item['achieved_title'] ); ?></div>
                <?php endif; ?>
            <?php else : ?>
                <div class="twshop-minicart-progress-text"><?php echo str_replace( '{amount}', '<strong>' . wc_price( $item['remaining'] ) . '</strong>', $item['title'] ); ?></div>
            <?php endif; ?>
            <div class="twshop-minicart-progress-track"><div class="twshop-minicart-progress-fill" style="width:<?php echo esc_attr( $item['percent'] ); ?>%;"></div></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    /*
     * 這裡刻意每次呼叫都印出 <style>，不做「同一次請求只印一次」的 static 旗標優化。
     * 原因：WooCommerce 核心 WC_AJAX::get_refreshed_fragments() 會先自己呼叫一次
     * woocommerce_mini_cart()（組出預設的 div.widget_shopping_cart_content fragment，
     * 本站佈景主題 Blocksy 完全不使用這個 selector），接著才透過
     * woocommerce_add_to_cart_fragments filter 觸發 Blocksy 自己的
     * blocksy_header_cart_item_fragment()，這裡面又會再呼叫一次 woocommerce_mini_cart()
     * 組出真正顯示用的 .ct-cart-content fragment——同一次 AJAX 請求內這個 hook 因此會被
     * 觸發兩次。若用 static 旗標只印一次，「只印一次」的名額會被第一次（其實沒被使用）的
     * 呼叫用掉，導致真正顯示出來的第二個 fragment 反而拿不到樣式，畫面上進度條完全消失
     * （已用瀏覽器實測重現：DOM 裡有 .twshop-minicart-progress-track 但沒有對應的
     * <style>，height 顯示 0px）。內容本身只在購物車非空且有未達標優惠券時才輸出，
     * 重複印出的成本可忽略。
     *
     * 2026-08-21：本外掛其餘的內嵌 <script>/<style> 全部抽成 assets/ 底下的獨立檔案了，
     * **這一段是刻意留下的唯一例外**，不是漏網之魚。要外部化只有 sitewide 無條件
     * wp_enqueue_style() 一條路：AJAX fragment 回應沒有 wp_head/wp_footer 可掛，
     * 在這個函式裡 enqueue 是印不出來的；而「購物車非空時才 enqueue」也不行——
     * fragment 更新時頁面是更早以前載入的（那時購物車可能還是空的），樣式就會缺席，
     * 正是上面描述的那個 bug。無條件 sitewide 載入比現在「只在購物車非空且有未達標
     * 項目時才輸出這幾行」更貴，所以維持內嵌。
     */
    ?>
    <style>
        .twshop-minicart-progress{ margin:0 0 12px; padding:0 0 12px; border-bottom:1px solid rgba(0,0,0,.08); }
        .twshop-minicart-progress-item + .twshop-minicart-progress-item{ margin-top:10px; }
        .twshop-minicart-progress-text{ font-size:12px; margin-bottom:6px; color:#444; }
        .twshop-minicart-progress-item.is-achieved .twshop-minicart-progress-text{ color:#2a8a3e; font-weight:600; }
        .twshop-minicart-progress-track{ height:10px; border-radius:5px; background:rgba(0,0,0,.1); overflow:hidden; }
        .twshop-minicart-progress-fill{ height:100%; border-radius:5px; min-width:4px; background:var(--theme-palette-color-1, #2271b1); transition:width .3s ease; }
        .twshop-minicart-progress-item.is-achieved .twshop-minicart-progress-fill{ background:#2a8a3e; }
    </style>
    <?php
}

/**
 * 規則使用次數：只計「建立訂單當下實際套用到」的規則（twshop_store_applied_rule_ids_on_order() 存的
 * _twshop_applied_rule_ids）。訪客訂單也計入全站總次數，只是沒有個人次數可記（v25.8.35 修正：
 * 原本訪客直接略過、且用事後重算有效性判斷，沒套用的規則也會被計次）。
 */
function twshop_increment_rule_usage_limits( $order_id, $posted_data, $order ) {
    if ( ! $order instanceof WC_Order ) {
        $order = wc_get_order( $order_id );
    }
    if ( ! $order || $order->get_meta( '_twshop_rules_recorded' ) ) return;
    $order->update_meta_data( '_twshop_rules_recorded', 'yes' );
    $order->save();

    $rule_ids = $order->get_meta( '_twshop_applied_rule_ids' );
    if ( ! is_array( $rule_ids ) || empty( $rule_ids ) ) return;

    $user_id = $order->get_customer_id();
    foreach ( $rule_ids as $r_id ) {
        twshop_increment_rule_usage_total( $r_id );
        if ( $user_id ) {
            update_user_meta( $user_id, 'twshop_rule_usage_' . $r_id, intval( get_user_meta( $user_id, 'twshop_rule_usage_' . $r_id, true ) ) + 1 );
        }
    }
}


