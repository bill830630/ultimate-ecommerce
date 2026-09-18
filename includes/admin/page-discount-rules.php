<?php
/**
 * 介面 3：折扣與贈品管理（獨立 AJAX 儲存與拖曳排序）
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// -------------------------------------------------------------------------
// 介面 3：折扣與贈品管理 (獨立 AJAX 儲存與拖曳排序)
// -------------------------------------------------------------------------
function twshop_marketing_rules_tab() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足。' );
    $rules = twshop_get_rules(); // 用這個而非直接 get_option()，確保缺 rule_id 的舊規則已補上唯一值（見 twshop_backfill_missing_rule_ids()）
    $tiers = get_option( 'wc_member_tiers_settings', array() );

    $product_cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
    $product_tags = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
    if ( is_wp_error( $product_cats ) ) $product_cats = array();
    if ( is_wp_error( $product_tags ) ) $product_tags = array();

    ?>
        <?php echo twshop_render_rule_overlap_warnings( $rules ); ?>

        <p class="twshop-rule-status" id="twshop-rule-toolbar-status" aria-live="polite"></p>

        <div id="discount-repeater-container" style="margin-top:10px;">
            <?php foreach ( $rules as $rule ) echo twshop_get_rule_row_html( $rule, $tiers, $product_cats, $product_tags ); ?>
        </div>

        <?php // 必須是 <template>，不能是隱藏的 <div>：隱藏 div 仍是真的 DOM，頁面載入時 WooCommerce 的
        // wc-enhanced-select.js 會先對範本裡的下拉選單套 selectWoo（加 enhanced class＋插入 .select2-container），
        // 「新增規則表單」複製 innerHTML 時連這些一起複製，新卡片的選單被當成已初始化而跳過，點了沒反應。 ?>
        <template id="discount-rule-template"><?php echo twshop_get_rule_row_html(array(), $tiers, $product_cats, $product_tags); ?></template>
        <template id="twshop-tier-row-template"><?php echo twshop_get_rule_tier_row_html(); ?></template>

        <p><button type="button" class="button" id="add-rule-row">新增規則表單</button></p>
    <?php twshop_render_chip_field_assets(); ?>
    <?php twshop_enqueue_asset_script( 'admin/discount-rules', array(
        'twshopDiscountRules' => array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'twshop_admin_action' ),
        ),
    ) ); ?>
    <?php
}

function twshop_get_rule_row_html( $r = array(), $tiers = array(), $cats = array(), $tags = array() ) {
    $r_id = $r['rule_id'] ?? ''; $name = $r['name'] ?? ''; $role = $r['role'] ?? 'all'; $type = $r['type'] ?? 'percent';
    $val = $r['value'] ?? ''; $gift_id = $r['gift_product_id'] ?? ''; $logic = $r['logic'] ?? 'and';
    $min = $r['min_amount'] ?? '';
    $limit = $r['usage_limit'] ?? ''; $u_limit = $r['user_limit'] ?? '';
    $s_time = $r['start_time'] ?? ''; $e_time = $r['end_time'] ?? '';
    $enabled = $r['enabled'] ?? 'yes'; $stack_exclusive = $r['stack_exclusive'] ?? 'no';
    $buy_qty = $r['buy_qty'] ?? ''; $free_qty = $r['free_qty'] ?? '';
    // 注意：命名為 $rule_tiers 以跟本函式第二參數 $tiers（會員等級清單，供「套用對象」下拉使用）區分開來。
    $rule_tiers = is_array( $r['tiers'] ?? null ) ? $r['tiers'] : array();
    $shipping_methods = $r['shipping_methods'] ?? array();
    if ( ! is_array( $shipping_methods ) ) $shipping_methods = array();
    $shipping_method_options = twshop_get_shipping_method_options();

    // 限制條件：類型 (單一商品/商品分類/商品標籤) + 該類型底下的複選項目
    $cond_type = $r['condition_type'] ?? '';
    $cond_values = $r['condition_values'] ?? array();
    if ( empty( $cond_type ) && ! empty( $r['category'] ) ) { $cond_type = 'category'; $cond_values = array( $r['category'] ); }
    if ( empty( $cond_type ) && ! empty( $r['tag'] ) )      { $cond_type = 'tag'; $cond_values = array( $r['tag'] ); }
    if ( ! is_array( $cond_values ) ) $cond_values = array();
    $cond_products = ( $cond_type === 'product' ) ? array_map( 'strval', $cond_values ) : array();
    $cond_cats     = ( $cond_type === 'category' ) ? $cond_values : array();
    $cond_tags     = ( $cond_type === 'tag' ) ? $cond_values : array();

    $cat_options = array();
    foreach ( $cats as $term ) { $cat_options[ $term->slug ] = $term->name; }
    $tag_options = array();
    foreach ( $tags as $term ) { $tag_options[ $term->slug ] = $term->name; }

    ob_start();
    ?>
    <?php // novalidate：卡片裡有依類型隱藏的欄位（例如買N送N 數量存著 0），瀏覽器內建驗證會因為隱藏欄位不合格而靜默擋下送出、又無法顯示提示；改由 discount-rules.js 自行檢查。 ?>
    <form novalidate class="twshop-rule-form twshop-rule-card<?php echo $enabled === 'no' ? ' twshop-rule-disabled' : ''; ?>" data-rule-name="<?php echo esc_attr( $name ); ?>" data-rule-type="<?php echo esc_attr( $type ); ?>" data-rule-enabled="<?php echo esc_attr( $enabled ); ?>" style="background:#fff; border:1px solid #ccd0d4; margin-bottom:20px; border-radius:5px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <input type="hidden" name="rule_id" value="<?php echo esc_attr($r_id); ?>" />
        <?php wp_nonce_field( 'twshop_admin_action', 'twshop_nonce' ); ?>

        <div class="twshop-card-header">
            <span class="drag-handle" title="拖曳排序（越前面越先套用）"><?php echo twshop_get_account_tab_icon_svg( 'grip-vertical' ); ?></span>
            <span class="twshop-card-header-controls" title="切換後立即生效">
                <label class="twshop-switch">
                    <input type="checkbox" class="twshop-rule-enabled-toggle" name="enabled" value="yes" <?php checked( $enabled, 'yes' ); ?> />
                    <span class="twshop-switch-slider" aria-hidden="true"></span>
                    <span class="twshop-switch-text"><?php echo $enabled === 'no' ? '停用' : '啟用'; ?></span>
                </label>
            </span>
            <input type="text" name="name" class="twshop-rule-name-input" value="<?php echo esc_attr( $name ); ?>" placeholder="規則名稱（依設定自動產生）" aria-label="規則名稱" title="依下方設定自動產生，可直接修改；修改後不再自動更新，清空即恢復自動命名" required />
            <span class="twshop-badge twshop-badge--warn twshop-rule-dirty-badge" style="display:none;">未儲存</span>
            <span class="twshop-card-header-controls twshop-header-schedule" title="選好或清除後立即生效">
                <span class="twshop-rule-schedule">
                    <label>開始 <input type="text" class="twshop-datetime-picker" name="start_time" value="<?php echo esc_attr( $s_time ); ?>" placeholder="立即" /></label>
                    <label>結束 <input type="text" class="twshop-datetime-picker" name="end_time" value="<?php echo esc_attr( $e_time ); ?>" placeholder="不限" /></label>
                    <a href="#" class="twshop-clear-datetime" title="清除開始與結束時間">清除</a>
                </span>
            </span>
            <span class="twshop-rule-status" aria-live="polite"></span>
            <span class="twshop-card-toggle-icon" title="點擊收合或展開"><?php echo twshop_get_account_tab_icon_svg( 'chevron-down' ); ?></span>
        </div>

        <div class="twshop-card-body" style="display:none;">

            <section class="twshop-rule-section">
                <h4 class="twshop-rule-section-title">1. 規則類型與折扣</h4>
                <div class="twshop-rule-grid">
                    <div class="twshop-rule-field">
                        <label class="twshop-rule-label">規則分類</label>
                        <?php
                        // 純前端篩選用，不送出（沒有 name）：實際型別仍是下面那個 <select name="type">，
                        // 這裡只是縮小它的選項範圍，見 discount-rules.js 的 filterTypeOptions()。
                        // 分類 => 哪個 type 屬於它，唯一登記處是每個 <option> 的 data-group，
                        // JS 直接讀屬性、不在 JS 裡另外維護一份對照表。
                        ?>
                        <select class="twshop-rule-type-group">
                            <option value="product">商品</option>
                            <option value="cart">購物車</option>
                            <option value="gift">贈品加購</option>
                        </select>
                    </div>
                    <div class="twshop-rule-field">
                        <label class="twshop-rule-label">折扣與贈品類型</label>
                        <select name="type" class="twshop-rule-type">
                            <option value="percent" data-group="product" <?php selected($type, 'percent'); ?>>打折 (%)</option>
                            <option value="fixed_product" data-group="product" <?php selected($type, 'fixed_product'); ?>>折抵 ($)</option>
                            <option value="cart_percent" data-group="cart" <?php selected($type, 'cart_percent'); ?>>打折 (%)</option>
                            <option value="cart_discount" data-group="cart" <?php selected($type, 'cart_discount'); ?>>折抵 ($)</option>
                            <option value="free_shipping" data-group="cart" <?php selected($type, 'free_shipping'); ?>>免運費</option>
                            <option value="tiered_cart" data-group="cart" <?php selected($type, 'tiered_cart'); ?>>階梯式折扣 (多門檻)</option>
                            <option value="free_gift" data-group="gift" <?php selected($type, 'free_gift'); ?>>滿額/條件贈品 (自動加入購物車)</option>
                            <option value="addon_product" data-group="gift" <?php selected($type, 'addon_product'); ?>>加購商品 (特價購買)</option>
                            <option value="buy_x_get_y" data-group="gift" <?php selected($type, 'buy_x_get_y'); ?>>買N送N (最便宜M件免費)</option>
                        </select>
                    </div>
                    <div class="twshop-rule-field rule-value-wrap">
                        <label class="twshop-rule-label rule-value-label">折扣數值</label>
                        <input type="number" step="any" name="value" class="twshop-rule-value" value="<?php echo esc_attr( $val ); ?>" />
                        <span class="twshop-rule-value-hint"></span>
                    </div>
                    <div class="twshop-rule-field rule-gift-wrap" style="display:none;">
                        <label class="twshop-rule-label">指定商品 <small>(贈品/加購品)</small></label>
                        <?php // 排除可變商品：free_gift 型別是直接 WC()->cart->add_to_cart( $id, 1, 0, ... )
                        // （不含 variation_id）自動加入購物車，選到可變商品的父商品會讓贈品必定加不進去
                        // （核心要求可變商品一定要指定規格），且這段是掛在 woocommerce_before_calculate_totals，
                        // 失敗時完全沒有任何錯誤訊息浮現，管理員很難發現。addon_product 型別雖然不是主動
                        // 加入購物車，但同一個欄位語意是「指定商品」，一併排除避免混淆。
                        echo twshop_render_product_search_field( 'gift_product_id', $gift_id ? array( $gift_id ) : array(), false, '— 請選擇商品 —', array( 'variable', 'wallet_credit' ) ); ?>
                    </div>
                    <div class="twshop-rule-field rule-bxgy-wrap" style="display:none;">
                        <label class="twshop-rule-label">買滿件數 (N)</label>
                        <input type="number" step="1" name="buy_qty" value="<?php echo esc_attr( $buy_qty ); ?>" />
                    </div>
                    <div class="twshop-rule-field rule-bxgy-wrap" style="display:none;">
                        <label class="twshop-rule-label">送出件數 (M) <small>須小於 N</small></label>
                        <input type="number" step="1" name="free_qty" value="<?php echo esc_attr( $free_qty ); ?>" />
                    </div>
                    <p class="twshop-rule-hint is-full rule-bxgy-wrap" style="display:none;">數量以「適用範圍」選的商品/分類/標籤為準（此類型必填），每筆訂單最多套用一次。</p>

                    <div class="twshop-rule-field is-full rule-tiers-wrap" style="display:none;">
                        <label class="twshop-rule-label">門檻階梯 <small>消費滿多少 → 打折/折抵多少，套用符合的最高門檻</small></label>
                        <div class="twshop-tiers-rows">
                            <?php foreach ( $rule_tiers as $tier ) echo twshop_get_rule_tier_row_html( $tier ); ?>
                        </div>
                        <button type="button" class="button twshop-add-tier-row">新增階梯</button>
                    </div>

                    <div class="twshop-rule-field is-full rule-shipping-methods-wrap" style="display:none;">
                        <label class="twshop-rule-label">適用運送方式 <small>都不勾 = 全部運送方式皆免運</small></label>
                        <div class="twshop-rule-checklist">
                            <?php if ( empty( $shipping_method_options ) ) : ?>
                                <span class="twshop-rule-hint">尚未設定任何運送方式（請先至 WooCommerce → 設定 → 運送 建立運送區域與方式）</span>
                            <?php else : foreach ( $shipping_method_options as $sm_key => $sm_label ) : ?>
                                <label><input type="checkbox" name="shipping_methods[]" value="<?php echo esc_attr( $sm_key ); ?>" <?php checked( in_array( $sm_key, $shipping_methods, true ) ); ?> /> <?php echo esc_html( $sm_label ); ?></label>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="twshop-rule-section rule-scope-section">
                <h4 class="twshop-rule-section-title">2. 套用對象與適用範圍</h4>
                <div class="twshop-rule-grid twshop-condition-scope">
                    <div class="twshop-rule-field">
                        <label class="twshop-rule-label">套用對象</label>
                        <select name="role"><option value="all" <?php selected($role, 'all'); ?>>所有顧客</option><?php foreach($tiers as $tier): ?><option value="<?php echo esc_attr($tier['slug']); ?>" <?php selected($role, $tier['slug']); ?>><?php echo esc_html($tier['name']); ?></option><?php endforeach; ?></select>
                    </div>
                    <p class="twshop-rule-hint is-full rule-condition-hint rule-scope-toggle"></p>
                    <div class="twshop-rule-field rule-scope-toggle">
                        <label class="twshop-rule-label">適用範圍</label>
                        <select name="condition_type" class="twshop-condition-type">
                            <option value="">不限商品</option>
                            <option value="product" <?php selected($cond_type, 'product'); ?>>指定商品</option>
                            <option value="category" <?php selected($cond_type, 'category'); ?>>指定商品分類</option>
                            <option value="tag" <?php selected($cond_type, 'tag'); ?>>指定商品標籤</option>
                        </select>
                    </div>
                    <div class="twshop-rule-field is-wide condition-values-wrap condition-values-product rule-scope-toggle" style="display:none;">
                        <label class="twshop-rule-label">選擇商品 <small>可複選</small></label>
                        <?php echo twshop_render_product_search_field( 'condition_values_product', $cond_products, true, '搜尋商品名稱或商品編號…' ); ?>
                    </div>
                    <div class="twshop-rule-field is-wide condition-values-wrap condition-values-category rule-scope-toggle" style="display:none;">
                        <label class="twshop-rule-label">選擇商品分類 <small>可複選</small></label>
                        <?php echo twshop_render_chip_field( 'condition_values_category', $cond_cats, $cat_options ); ?>
                    </div>
                    <div class="twshop-rule-field is-wide condition-values-wrap condition-values-tag rule-scope-toggle" style="display:none;">
                        <label class="twshop-rule-label">選擇商品標籤 <small>可複選</small></label>
                        <?php echo twshop_render_chip_field( 'condition_values_tag', $cond_tags, $tag_options ); ?>
                    </div>
                    <div class="twshop-rule-field is-row-start rule-min-amount-wrap rule-scope-toggle">
                        <label class="twshop-rule-label">訂單小計滿 ($) <small>留空不限</small></label>
                        <input type="number" step="any" name="min_amount" class="twshop-rule-min-amount" value="<?php echo esc_attr( $min ); ?>" />
                    </div>
                    <div class="twshop-rule-field is-wide twshop-rule-logic-wrap rule-scope-toggle">
                        <label class="twshop-rule-label">範圍與滿額要</label>
                        <div class="twshop-rule-checklist">
                            <label><input type="radio" name="logic" value="and" <?php checked($logic, 'and'); ?>> 兩者都符合</label>
                            <label><input type="radio" name="logic" value="or" <?php checked($logic, 'or'); ?>> 符合其中一個即可</label>
                        </div>
                    </div>
                </div>
            </section>

            <section class="twshop-rule-section">
                <h4 class="twshop-rule-section-title">3. 使用次數</h4>
                <div class="twshop-rule-grid">
                    <div class="twshop-rule-field">
                        <label class="twshop-rule-label">總共可使用次數 <small>留空不限</small></label>
                        <input type="number" step="1" name="usage_limit" value="<?php echo esc_attr( $limit ); ?>" />
                    </div>
                    <div class="twshop-rule-field">
                        <label class="twshop-rule-label">每位會員限用次數 <small>留空不限</small></label>
                        <input type="number" step="1" name="user_limit" value="<?php echo esc_attr( $u_limit ); ?>" />
                    </div>
                </div>
            </section>


            <div class="twshop-rule-footer">
                <span class="twshop-rule-footer-group">
                    <button type="button" class="button twshop-duplicate-rule" <?php disabled( '' === $r_id ); ?> title="<?php echo '' === $r_id ? '請先儲存規則' : '複製一份（預設停用）'; ?>">複製規則</button>
                    <button type="button" class="button remove-rule-row twshop-button-danger">刪除規則</button>
                </span>
                <span class="twshop-rule-footer-group">
                    <span class="twshop-rule-status twshop-rule-footer-status" aria-live="polite"></span>
                    <label class="rule-stack-wrap" title="勾選後，排序在這條規則後面的同類折扣規則不會再套用">
                        <input type="checkbox" name="stack_exclusive" value="yes" <?php checked( $stack_exclusive, 'yes' ); ?> /> 不與同類折扣疊加
                    </label>
                    <button type="submit" class="button button-primary save-rule-btn">儲存規則</button>
                </span>
            </div>
        </div>
    </form>
    <?php return ob_get_clean();
}

/**
 * 階梯式折扣的單一門檻列。PHP 渲染既有列與 JS「新增階梯」共用同一份 HTML（JS 從
 * 頁面上的範本讀取），避免兩邊各寫一份、欄位或樣式漂移。
 */
function twshop_get_rule_tier_row_html( $tier = array() ) {
    ob_start();
    ?>
    <div class="twshop-tier-row">
        <div class="twshop-rule-field"><label class="twshop-rule-label">消費滿 ($)</label><input type="number" step="any" name="tiers_min[]" value="<?php echo esc_attr( $tier['min_amount'] ?? '' ); ?>" /></div>
        <div class="twshop-rule-field"><label class="twshop-rule-label">折扣類型</label><select name="tiers_type[]" class="twshop-tier-type"><option value="percent" <?php selected( $tier['discount_type'] ?? '', 'percent' ); ?>>打折 (%)</option><option value="fixed" <?php selected( $tier['discount_type'] ?? '', 'fixed' ); ?>>折抵 ($)</option></select></div>
        <div class="twshop-rule-field"><label class="twshop-rule-label">數值</label><input type="number" step="any" name="tiers_value[]" class="twshop-tier-value" value="<?php echo esc_attr( $tier['value'] ?? '' ); ?>" /><span class="twshop-rule-value-hint twshop-tier-hint"></span></div>
        <button type="button" class="button twshop-remove-tier-row twshop-button-danger">移除</button>
    </div>
    <?php
    return ob_get_clean();
}

function twshop_ajax_save_rule() {
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error();
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );
    $rule_id = sanitize_text_field( wp_unslash( $_POST['rule_id'] ?? '' ) );
    if(empty($rule_id)) $rule_id = uniqid('rule_');

    $condition_type = sanitize_text_field( wp_unslash( $_POST['condition_type'] ?? '' ) );
    if ( ! in_array( $condition_type, array( 'product', 'category', 'tag' ), true ) ) $condition_type = '';
    if ( $condition_type === 'product' ) {
        $condition_values = array_map( 'absint', (array) ( $_POST['condition_values_product'] ?? array() ) );
    } elseif ( $condition_type === 'category' ) {
        $condition_values = twshop_sanitize_term_slugs( wp_unslash( (array) ( $_POST['condition_values_category'] ?? array() ) ), 'product_cat' );
    } elseif ( $condition_type === 'tag' ) {
        $condition_values = twshop_sanitize_term_slugs( wp_unslash( (array) ( $_POST['condition_values_tag'] ?? array() ) ), 'product_tag' );
    } else {
        $condition_values = array();
    }
    $condition_values = array_values( array_filter( $condition_values ) );
    if ( empty( $condition_values ) ) $condition_type = '';

    $shipping_methods = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['shipping_methods'] ?? array() ) ) );
    $shipping_methods = array_values( array_filter( $shipping_methods ) );

    // 階梯式訂單折扣（tiered_cart）：三個平行陣列（同 index 對應同一組門檻）組回結構化陣列。
    $tiers_min = (array) ( $_POST['tiers_min'] ?? array() );
    $tiers_type = (array) ( $_POST['tiers_type'] ?? array() );
    $tiers_value = (array) ( $_POST['tiers_value'] ?? array() );
    $tiers = array();
    foreach ( $tiers_min as $i => $tier_min ) {
        $discount_type = in_array( $tiers_type[ $i ] ?? '', array( 'percent', 'fixed' ), true ) ? $tiers_type[ $i ] : 'fixed';
        $tiers[] = array(
            'min_amount'    => floatval( $tier_min ),
            'discount_type' => $discount_type,
            'value'         => floatval( $tiers_value[ $i ] ?? 0 ),
        );
    }

    $new_rule = array(
        'rule_id'           => $rule_id,
        'name'              => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
        'role'              => sanitize_text_field( wp_unslash( $_POST['role'] ?? '' ) ),
        'type'              => sanitize_text_field( wp_unslash( $_POST['type'] ?? '' ) ),
        'value'             => floatval( wp_unslash( $_POST['value'] ?? 0 ) ),
        'gift_product_id'   => absint( wp_unslash( $_POST['gift_product_id'] ?? 0 ) ),
        'shipping_methods'  => $shipping_methods,
        'logic'             => sanitize_text_field( wp_unslash( $_POST['logic'] ?? '' ) ),
        'condition_type'    => $condition_type,
        'condition_values'  => $condition_values,
        'min_amount'        => floatval( wp_unslash( $_POST['min_amount'] ?? 0 ) ),
        'usage_limit'       => absint( wp_unslash( $_POST['usage_limit'] ?? 0 ) ),
        'user_limit'        => absint( wp_unslash( $_POST['user_limit'] ?? 0 ) ),
        'start_time'        => sanitize_text_field( wp_unslash( $_POST['start_time'] ?? '' ) ),
        'end_time'          => sanitize_text_field( wp_unslash( $_POST['end_time'] ?? '' ) ),
        'enabled'           => sanitize_text_field( wp_unslash( $_POST['enabled'] ?? 'no' ) ),
        'stack_exclusive'   => sanitize_text_field( wp_unslash( $_POST['stack_exclusive'] ?? 'no' ) ),
        'buy_qty'           => absint($_POST['buy_qty'] ?? 0),
        'free_qty'          => absint($_POST['free_qty'] ?? 0),
        'tiers'             => $tiers,
    );

    $rules = twshop_get_rules();

    // 儲值金商品不能設成贈品/加購品（v25.8.79 新增）：入帳邏輯只認商品的
    // _twshop_wallet_credit_amount 面額，跟贈品/加購這裡把售價歸零或打到象徵性低價
    // 完全無關，顧客實付 $0~$1 卻仍能拿到完整面額，等於系統本身沒有防呆地讓儲值金
    // 商品被誤用成印錢工具。buy_x_get_y 沒有固定的「目標商品」（靠限制條件動態決定
    // 範圍），沒辦法在這裡擋，改在 twshop_auto_manage_gifts_and_addons()（discount-engine.php）
    // 選擇最便宜 M 件時跳過儲值金商品項目。
    if ( in_array( $new_rule['type'], array( 'free_gift', 'addon_product' ), true ) && $new_rule['gift_product_id'] > 0 ) {
        $gift_product = wc_get_product( $new_rule['gift_product_id'] );
        if ( $gift_product && twshop_is_wallet_credit_product( $gift_product ) ) {
            wp_send_json_error( array( 'msg' => '「贈品」／「加購品」不能設定為儲值金商品。' ) );
        }
    }

    // 買N送N：限制條件範圍必填（決定哪些商品的購買數量算進 N），且 M 必須小於 N。
    if ( 'buy_x_get_y' === $new_rule['type'] ) {
        if ( empty( $new_rule['condition_type'] ) || empty( $new_rule['condition_values'] ) ) {
            wp_send_json_error( array( 'msg' => '「買N送N」規則必須在限制條件區塊選擇商品/分類/標籤範圍。' ) );
        }
        if ( $new_rule['buy_qty'] < 1 ) {
            wp_send_json_error( array( 'msg' => '買滿件數 (N) 必須至少為 1。' ) );
        }
        if ( $new_rule['free_qty'] < 1 || $new_rule['free_qty'] >= $new_rule['buy_qty'] ) {
            wp_send_json_error( array( 'msg' => '送出件數 (M) 必須至少為 1，且必須小於買滿件數 (N)。' ) );
        }
    }

    // 階梯式訂單折扣：至少一組門檻，且每組門檻與數值都必須大於 0。
    if ( 'tiered_cart' === $new_rule['type'] ) {
        if ( empty( $new_rule['tiers'] ) ) {
            wp_send_json_error( array( 'msg' => '「階梯式訂單折扣」規則至少需要一組門檻。' ) );
        }
        foreach ( $new_rule['tiers'] as $tier ) {
            if ( $tier['min_amount'] <= 0 || $tier['value'] <= 0 ) {
                wp_send_json_error( array( 'msg' => '每組門檻的「消費滿」與「數值」都必須大於 0。' ) );
            }
        }
    }

    $updated = false;
    foreach($rules as $k => $r) {
        if($r['rule_id'] === $rule_id) { $rules[$k] = $new_rule; $updated = true; break; }
    }
    if(!$updated) $rules[] = $new_rule;
    update_option('wc_discount_rules_settings', $rules);
    twshop_get_rules( true );
    wp_send_json_success(['rule_id' => $rule_id, 'msg' => '儲存成功']);
}

/**
 * 複製一條已儲存的規則：新 rule_id、名稱加「（複本）」、預設停用，
 * 插在原規則後面，回傳新卡片 HTML 供前端直接插入。
 */
function twshop_ajax_duplicate_rule() {
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error();
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );
    $rule_id = sanitize_text_field( wp_unslash( $_POST['rule_id'] ?? '' ) );
    $rules   = twshop_get_rules();

    $new_rules = array();
    $copy      = null;
    foreach ( $rules as $r ) {
        $new_rules[] = $r;
        if ( null === $copy && $r['rule_id'] === $rule_id ) {
            $copy = $r;
            $copy['rule_id'] = uniqid( 'rule_' );
            $copy['name']    = ( $r['name'] ?? '' ) . '（複本）';
            $copy['enabled'] = 'no';
            $new_rules[]     = $copy;
        }
    }
    if ( null === $copy ) wp_send_json_error( array( 'msg' => '找不到要複製的規則，請重新整理頁面。' ) );

    update_option( 'wc_discount_rules_settings', $new_rules );
    twshop_get_rules( true );

    $product_cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
    $product_tags = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
    wp_send_json_success( array(
        'rule_id' => $copy['rule_id'],
        'html'    => twshop_get_rule_row_html(
            $copy,
            get_option( 'wc_member_tiers_settings', array() ),
            is_wp_error( $product_cats ) ? array() : $product_cats,
            is_wp_error( $product_tags ) ? array() : $product_tags
        ),
    ) );
}

function twshop_ajax_delete_rule() {
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error();
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );
    $rule_id = sanitize_text_field( wp_unslash( $_POST['rule_id'] ?? '' ) );
    $rules = twshop_get_rules();
    foreach($rules as $k => $r) { if($r['rule_id'] === $rule_id) unset($rules[$k]); }
    update_option('wc_discount_rules_settings', array_values($rules));
    twshop_get_rules( true );

    // 規則本身之外，還有兩筆用 rule_id 動態組 key 的使用次數統計，不會因為上面 update_option()
    // 而一併消失，須手動清掉，否則刪除後仍留著孤兒資料：
    // (1) 全站累計使用次數（option）(2) 每位會員的個人使用次數（user meta，跨所有會員）
    if ( '' !== $rule_id ) {
        twshop_delete_rule_usage_total( $rule_id );
        delete_metadata( 'user', 0, 'twshop_rule_usage_' . $rule_id, '', true );
    }

    wp_send_json_success();
}

/**
 * 批次啟用/停用/刪除多筆規則，以及卡片標題列啟用切換鈕（enable/disable）與
 * 開始/結束時間（schedule）的立即存檔。
 * 刪除時比照 twshop_ajax_delete_rule()，一併清理該規則的使用次數 option／user meta，
 * 避免又留下孤兒資料（兩處刪除邏輯刻意保持一致）。
 */
function twshop_ajax_batch_update_rules() {
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error();
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );

    $action_type = sanitize_text_field( wp_unslash( $_POST['action_type'] ?? '' ) );
    if ( ! in_array( $action_type, array( 'enable', 'disable', 'delete', 'schedule' ), true ) ) {
        wp_send_json_error( array( 'msg' => '不明的批次操作。' ) );
    }
    $rule_ids = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['rule_ids'] ?? array() ) ) );
    if ( empty( $rule_ids ) ) wp_send_json_error( array( 'msg' => '未選取任何規則。' ) );

    $rules = twshop_get_rules();

    if ( 'schedule' === $action_type ) {
        $start = sanitize_text_field( wp_unslash( $_POST['start_time'] ?? '' ) );
        $end   = sanitize_text_field( wp_unslash( $_POST['end_time'] ?? '' ) );
        foreach ( array( $start, $end ) as $time ) {
            if ( '' !== $time && false === strtotime( $time ) ) wp_send_json_error( array( 'msg' => '時間格式不正確。' ) );
        }
        if ( '' !== $start && '' !== $end && strtotime( $end ) <= strtotime( $start ) ) {
            wp_send_json_error( array( 'msg' => '結束時間必須晚於開始時間。' ) );
        }
        foreach ( $rules as $k => $r ) {
            if ( in_array( $r['rule_id'], $rule_ids, true ) ) {
                $rules[ $k ]['start_time'] = $start;
                $rules[ $k ]['end_time']   = $end;
            }
        }
        update_option( 'wc_discount_rules_settings', $rules );
        twshop_get_rules( true );
        wp_send_json_success();
    }

    if ( 'delete' === $action_type ) {
        foreach ( $rules as $k => $r ) {
            if ( in_array( $r['rule_id'], $rule_ids, true ) ) {
                twshop_delete_rule_usage_total( $r['rule_id'] );
                delete_metadata( 'user', 0, 'twshop_rule_usage_' . $r['rule_id'], '', true );
                unset( $rules[ $k ] );
            }
        }
        update_option( 'wc_discount_rules_settings', array_values( $rules ) );
    } else {
        $new_enabled = ( 'enable' === $action_type ) ? 'yes' : 'no';
        foreach ( $rules as $k => $r ) {
            if ( in_array( $r['rule_id'], $rule_ids, true ) ) {
                $rules[ $k ]['enabled'] = $new_enabled;
            }
        }
        update_option( 'wc_discount_rules_settings', $rules );
    }

    twshop_get_rules( true );
    wp_send_json_success();
}

function twshop_ajax_reorder_rules() {
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error();
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );
    $order = isset($_POST['order']) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['order'] ) ) : array();
    $rules = twshop_get_rules();
    $new_rules = array();
    foreach ( $order as $id ) {
        foreach ( $rules as $r ) {
            if ( $r['rule_id'] === $id ) {
                $new_rules[] = $r;
                break;
            }
        }
    }
    update_option('wc_discount_rules_settings', $new_rules);
    twshop_get_rules( true );
    wp_send_json_success();
}

