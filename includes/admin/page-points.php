<?php
/**
 * 介面 2：紅利點數獨立設定頁面
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構）。v25.8.25 起改成 5 個真正的頁籤（比照
 * twshop_system_section() 的既有模式），原本單一函式 twshop_marketing_points_tab()
 * 依畫面區塊拆成 5 支獨立頁籤函式，settings group 同步拆開（見 includes/admin/settings.php）。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// -------------------------------------------------------------------------
// 介面 2：紅利點數獨立設定頁面
// -------------------------------------------------------------------------

function twshop_points_term() {
    $term = get_option( 'wc_points_term_name', '' );
    return $term !== '' ? $term : '點數';
}

/**
 * 依點數餘額排序的會員清單（前 $limit 名），供「會員餘額」頁籤的總覽表格使用。
 * 點數餘額存在 user meta（`twshop_reward_points`），不是自建資料表——跟儲值金模組的
 * `twshop_wallet_get_balances_overview()`（直接查自建 balances 表）不同，這裡改用
 * `WP_User_Query` 的 `meta_key`/`orderby=meta_value_num` 排序，是 WordPress 對「依
 * usermeta 數值排序找會員」的標準做法。
 */
function twshop_points_get_balances_overview( $limit = 50 ) {
    $query = new WP_User_Query( array(
        'meta_key'     => 'twshop_reward_points',
        'meta_value'   => 0,
        'meta_compare' => '>',
        'meta_type'    => 'SIGNED',
        'orderby'      => 'meta_value_num',
        'order'        => 'DESC',
        'number'       => $limit,
        'fields'       => array( 'ID', 'display_name', 'user_email' ),
    ) );
    return $query->get_results();
}

/**
 * 手動調整點數表單的 $_POST 處理，在 `twshop_points_balances_tab()` 輸出任何內容之前
 * 呼叫——跟「模組開關」頁（`twshop_system_modules_tab()`）同一套慣例：獨立 `<form>` +
 * 手動 `$_POST` 處理，不走 `options.php`（點數餘額不是註冊過的 option，沒有 group 可掛）。
 *
 * @return string 儲存結果訊息（空字串代表沒有送出表單，不用顯示任何通知）。
 */
function twshop_points_handle_manual_adjust( $user_id ) {
    if ( ! isset( $_POST['twshop_points_manual_adjust'] ) ) return '';
    check_admin_referer( 'twshop_points_manual_adjust' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) return '';

    $amount = isset( $_POST['twshop_manual_points'] ) ? (int) $_POST['twshop_manual_points'] : 0;
    if ( 0 === $amount ) return '請輸入非 0 的點數增減值。';

    $reason       = sanitize_text_field( wp_unslash( $_POST['twshop_points_reason'] ?? '' ) );
    $expire_days  = absint( $_POST['twshop_manual_points_expire_days'] ?? 0 );
    twshop_apply_manual_points_adjustment( $user_id, $amount, $reason, $expire_days );

    return 'saved';
}

/**
 * 頁籤：會員餘額（v25.8.65 新增，比照儲值金「會員餘額」頁籤的既有版面；v25.8.66 起
 * 手動調整點數的表單從使用者個人資料頁搬到這裡，見 `twshop_points_handle_manual_
 * adjust()` 與 `twshop_apply_manual_points_adjustment()`，`points-engine.php`）。
 * 搜尋欄位重用 `twshop_render_customer_search_field()`（`ui-components.php`，原本是
 * 儲值金頁專用，這次抽成共用 helper）。
 */
function twshop_points_balances_tab() {
    $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
    $term    = twshop_points_term();
    $notice  = $user_id ? twshop_points_handle_manual_adjust( $user_id ) : '';
    ?>
    <div class="twshop-panel">
        <?php twshop_panel_head( 'search', '搜尋會員' ); ?>
        <div class="twshop-panel-body">
            <form method="get">
                <input type="hidden" name="page" value="wc-general-settings">
                <input type="hidden" name="section" value="points">
                <input type="hidden" name="tab" value="balances">
                <?php twshop_render_customer_search_field( 'user_id', $user_id ); ?>
                <button type="submit" class="button">查看</button>
            </form>
        </div>
    </div>

    <?php
    if ( $user_id ) {
        $user = get_userdata( $user_id );
        if ( $user ) {
            if ( 'saved' === $notice ) {
                echo '<div class="notice notice-success is-dismissible"><p>點數已調整。</p></div>';
            } elseif ( $notice ) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
            }

            $points  = (int) get_user_meta( $user_id, 'twshop_reward_points', true );
            $history = get_user_meta( $user_id, 'twshop_points_history', true );
            if ( ! is_array( $history ) ) $history = array();
            $expiry_days = (int) get_option( 'wc_points_expiry_days', 0 );
            ?>
            <div class="twshop-panel">
                <?php twshop_panel_head(
                    'coins',
                    esc_html( $user->display_name ) . '（' . esc_html( $user->user_email ) . '）的' . esc_html( $term )
                ); ?>
                <div class="twshop-panel-body">
                    <p style="font-size:22px; font-weight:bold; margin-bottom:4px;"><?php echo esc_html( number_format( $points ) ); ?> <?php echo esc_html( $term ); ?></p>
                    <?php $nearest_expiring = twshop_get_nearest_expiring_batch( $user_id ); ?>
                    <?php if ( $nearest_expiring ) : ?>
                        <p class="twshop-text-danger" style="margin-top:0;">有 <?php echo esc_html( $nearest_expiring['amount'] ); ?> 點將於 <?php echo esc_html( $nearest_expiring['expire'] ); ?> 到期</p>
                    <?php endif; ?>

                    <h4>手動增減<?php echo esc_html( $term ); ?></h4>
                    <form method="post">
                        <?php wp_nonce_field( 'twshop_points_manual_adjust' ); ?>
                        <div style="display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end; margin-bottom:10px;">
                            <div>
                                <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php echo esc_html( $term ); ?>增減</label>
                                <input type="number" name="twshop_manual_points" value="" class="regular-text" placeholder="例如: 10 或 -5" style="width:200px;">
                            </div>
                            <?php if ( $expiry_days > 0 ) : ?>
                                <div>
                                    <label style="display:block; font-weight:bold; margin-bottom:5px;">有效天數</label>
                                    <input type="number" name="twshop_manual_points_expire_days" min="1" class="small-text" placeholder="<?php echo esc_attr( $expiry_days ); ?>"> 天
                                </div>
                            <?php endif; ?>
                            <div style="flex:1; min-width:220px;">
                                <label style="display:block; font-weight:bold; margin-bottom:5px;">備註原因</label>
                                <input type="text" name="twshop_points_reason" value="" class="regular-text" placeholder="手動調整" style="width:100%;">
                            </div>
                        </div>
                        <?php if ( $expiry_days > 0 ) : ?>
                            <p class="description" style="margin-top:-6px;">有效天數僅適用於本次輸入正數（增加）的點數，自入帳日起算；留空則依系統預設（<?php echo esc_html( $expiry_days ); ?> 天）</p>
                        <?php endif; ?>
                        <button type="submit" name="twshop_points_manual_adjust" value="1" class="button button-primary">儲存<?php echo esc_html( $term ); ?></button>
                        <p class="description">輸入正數為增加，輸入負數為扣除。</p>
                    </form>

                    <h4>最近異動（最新 <?php echo count( $history ); ?> 筆，只保留最新 100 筆）</h4>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr><th>時間</th><th>異動</th><th>原因</th><th>異動後餘額</th><th>到期日</th></tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $history ) ) : ?>
                                <tr><td colspan="5" class="twshop-text-muted" style="text-align:center;">目前尚無紀錄</td></tr>
                            <?php else : foreach ( $history as $row ) :
                                $amount = (int) ( $row['amount'] ?? 0 );
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $row['time'] ?? '' ); ?></td>
                                    <td><?php echo esc_html( ( $amount > 0 ? '+' : '' ) . $amount ); ?></td>
                                    <td><?php echo esc_html( $row['reason'] ?? '' ); ?></td>
                                    <td><?php echo esc_html( number_format( (int) ( $row['balance'] ?? 0 ) ) ); ?></td>
                                    <td><?php echo esc_html( $row['expire'] ?? '' ); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php
        } else {
            echo '<div class="notice notice-error"><p>找不到這位會員。</p></div>';
        }
    }
    ?>

    <div class="twshop-panel">
        <?php twshop_panel_head( 'list', esc_html( $term ) . '總覽（依餘額排序，前 50 名）' ); ?>
        <div class="twshop-panel-body">
            <?php $overview = twshop_points_get_balances_overview( 50 ); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr><th>會員</th><th><?php echo esc_html( $term ); ?></th><th></th></tr>
                </thead>
                <tbody>
                    <?php if ( empty( $overview ) ) : ?>
                        <tr><td colspan="3" class="twshop-text-muted" style="text-align:center;">目前沒有任何會員持有<?php echo esc_html( $term ); ?></td></tr>
                    <?php else : foreach ( $overview as $u ) :
                        $bal = (int) get_user_meta( $u->ID, 'twshop_reward_points', true );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $u->display_name . '（' . $u->user_email . '）' ); ?></td>
                            <td><?php echo esc_html( number_format( $bal ) ); ?></td>
                            <td><a href="<?php echo esc_url( twshop_admin_url( 'points', array( 'tab' => 'balances', 'user_id' => $u->ID ) ) ); ?>">查看</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

/**
 * 頁籤：點數規則設定。
 */
function twshop_points_rules_tab() {
    $p_term = get_option( 'wc_points_term_name', '點數' );
    $p_base = get_option( 'wc_points_base_rate', 100 );
    $p_rate = get_option( 'wc_points_redemption_rate', 1 );
    $p_max  = get_option( 'wc_points_max_percent', 30 );
    $p_min_amount = get_option( 'wc_points_min_cart_amount', 0 );
    list( $earn_restrict_type, $earn_restrict_values ) = twshop_get_typed_restriction(
        'wc_points_earn_restrict_type', 'wc_points_earn_restrict_values',
        array( 'category' => 'wc_points_earn_restricted_category', 'tag' => 'wc_points_earn_restricted_tag' )
    );
    list( $redeem_restrict_type, $redeem_restrict_values ) = twshop_get_typed_restriction(
        'wc_points_redeem_restrict_type', 'wc_points_redeem_restrict_values',
        array( 'category' => 'wc_points_restricted_categories' )
    );
    $p_expiry_days   = (int) get_option( 'wc_points_expiry_days', 0 );
    $p_notify_days   = get_option( 'wc_points_expiry_notify_days', 7 );
    $p_notify_subj   = get_option( 'wc_points_expiry_notify_subject', '您的' . twshop_points_term() . '即將到期' );
    $p_notify_body   = get_option( 'wc_points_expiry_notify_body', "親愛的 {name}：\n\n您有 {amount} {term}將於 {date} 到期，請把握時間使用！" );

    $product_cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
    if ( is_wp_error( $product_cats ) ) $product_cats = array();
    $product_tags = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
    if ( is_wp_error( $product_tags ) ) $product_tags = array();

    $cat_options = array();
    foreach ( $product_cats as $term ) { $cat_options[ $term->term_id ] = $term->name; }
    $tag_options = array();
    foreach ( $product_tags as $term ) { $tag_options[ $term->term_id ] = $term->name; }
    ?>
        <form action="options.php" method="post">
            <?php settings_fields( 'wc_points_rules_group' ); ?>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'coins', '點數規則設定' ); ?>
                <div class="twshop-panel-body">
                <table class="form-table">
                    <tr>
                        <th scope="row">點數名稱</th>
                        <td>
                            <input type="text" name="wc_points_term_name" value="<?php echo esc_attr( $p_term ); ?>" class="regular-text" placeholder="點數" />
                            <p class="description">設定前台顯示的點數名稱；留空則使用「點數」。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">點數獲取比例</th>
                        <td>消費滿 <input type="number" name="wc_points_base_rate" value="<?php echo esc_attr($p_base); ?>" class="small-text" /> 元，獲得 1 點</td>
                    </tr>
                    <tr>
                        <th scope="row">限制獲得點數的商品</th>
                        <td>
                            <?php
                            echo twshop_render_typed_condition_field(
                                'wc_points_earn_restrict_type', $earn_restrict_type,
                                array( 'category' => '商品分類', 'tag' => '商品標籤' ),
                                array(
                                    'category' => array( 'name' => 'wc_points_earn_restrict_values', 'options' => $cat_options, 'selected' => $earn_restrict_type === 'category' ? $earn_restrict_values : array() ),
                                    'tag'      => array( 'name' => 'wc_points_earn_restrict_values', 'options' => $tag_options, 'selected' => $earn_restrict_type === 'tag' ? $earn_restrict_values : array() ),
                                )
                            );
                            ?>
                            <p class="description">先選擇要限制的類型（商品分類或商品標籤），再從清單中複選項目。設定後，訂單中只有屬於所選項目的商品金額，才會列入點數計算基準；其餘商品消費不會產生點數。選擇「無限制」則依訂單總額計算點數（維持原有行為）。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">點數折抵匯率</th>
                        <td><input type="number" name="wc_points_redemption_rate" value="<?php echo esc_attr($p_rate); ?>" class="small-text" min="1" /> 點折抵 1 元</td>
                    </tr>
                    <tr>
                        <th scope="row">單筆最高折抵上限</th>
                        <td>單筆最多折抵總額 <input type="number" name="wc_points_max_percent" value="<?php echo esc_attr($p_max); ?>" class="small-text" /> %</td>
                    </tr>
                    <tr>
                        <th scope="row">最低消費折抵門檻</th>
                        <td>購物車總金額需達 <input type="number" step="0.01" name="wc_points_min_cart_amount" value="<?php echo esc_attr($p_min_amount); ?>" class="small-text" /> 元，才可使用點數折抵 (0 為無限制)</td>
                    </tr>
                    <tr>
                        <th scope="row">限制兌換商品</th>
                        <td>
                            <?php
                            echo twshop_render_typed_condition_field(
                                'wc_points_redeem_restrict_type', $redeem_restrict_type,
                                array( 'category' => '商品分類', 'tag' => '商品標籤' ),
                                array(
                                    'category' => array( 'name' => 'wc_points_redeem_restrict_values', 'options' => $cat_options, 'selected' => $redeem_restrict_type === 'category' ? $redeem_restrict_values : array() ),
                                    'tag'      => array( 'name' => 'wc_points_redeem_restrict_values', 'options' => $tag_options, 'selected' => $redeem_restrict_type === 'tag' ? $redeem_restrict_values : array() ),
                                )
                            );
                            ?>
                            <p class="description">先選擇要限制的類型（商品分類或商品標籤），再從清單中複選項目。設定後，購物車內必須包含其中任一所選項目的商品，才能在結帳時看到點數折抵區塊。選擇「無限制」則全館皆可使用。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">點數有效期限</th>
                        <td>
                            每筆點數自入帳日起算 <input type="number" name="wc_points_expiry_days" value="<?php echo esc_attr( $p_expiry_days ); ?>" class="small-text" min="0" /> 天後到期
                            <p class="description">設為 0 代表點數永久有效（不到期）。使用點數折抵時，會優先扣除最早到期的點數。<strong>此設定啟用前已入帳的點數不受影響，永遠不會到期。</strong></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">到期前提醒</th>
                        <td>
                            點數到期前 <input type="number" name="wc_points_expiry_notify_days" value="<?php echo esc_attr( $p_notify_days ); ?>" class="small-text" min="0" /> 天，寄送 Email 提醒會員
                            <p class="description">設為 0 則不寄送提醒信。僅在上方「點數有效期限」大於 0 時生效。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">到期提醒信件主旨</th>
                        <td><input type="text" name="wc_points_expiry_notify_subject" value="<?php echo esc_attr( $p_notify_subj ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">到期提醒信件內容</th>
                        <td>
                            <textarea name="wc_points_expiry_notify_body" rows="4" class="regular-text"><?php echo esc_html( $p_notify_body ); ?></textarea>
                            <p class="description">可用 <code>{name}</code>／<code>{amount}</code>／<code>{term}</code>／<code>{date}</code> 代表會員姓名/到期點數/點數名稱/到期日。</p>
                        </td>
                    </tr>
                </table>
                </div>
            </div>

            <?php submit_button( '儲存點數規則設定' ); ?>
        </form>
    <?php twshop_render_chip_field_assets(); ?>
    <?php
}

/**
 * 頁籤：點數提示文字。
 */
function twshop_points_texts_tab() {
    $p_ui_heading        = twshop_option( 'wc_points_ui_heading' );
    $p_balance_text      = twshop_option( 'wc_points_balance_text' );
    $p_expiry_soon_text  = twshop_option( 'wc_points_expiry_soon_text' );
    $p_input_placeholder = twshop_option( 'wc_points_input_placeholder' );
    $p_btn_apply_text    = twshop_option( 'wc_points_btn_apply_text' );
    $p_btn_update_text   = twshop_option( 'wc_points_btn_update_text' );
    $p_applied_text      = twshop_option( 'wc_points_applied_text' );
    $p_no_balance_text   = twshop_option( 'wc_points_no_balance_text' );
    $p_min_cart_text     = twshop_option( 'wc_points_min_cart_text' );
    $p_restricted_text   = twshop_option( 'wc_points_restricted_text' );
    ?>
        <form action="options.php" method="post">
            <?php settings_fields( 'wc_points_texts_group' ); ?>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'pencil', '點數提示文字', '設定購物車與結帳頁的點數文案；可用變數請參照各欄位說明。' ); ?>
                <div class="twshop-panel-body">
                    <table class="form-table">
                        <tr><th scope="row">區塊標題</th><td><input type="text" name="wc_points_ui_heading" value="<?php echo esc_attr( $p_ui_heading ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">目前餘額文字</th><td><input type="text" name="wc_points_balance_text" value="<?php echo esc_attr( $p_balance_text ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">即將到期提醒</th><td><input type="text" name="wc_points_expiry_soon_text" value="<?php echo esc_attr( $p_expiry_soon_text ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">輸入框提示文字</th><td><input type="text" name="wc_points_input_placeholder" value="<?php echo esc_attr( $p_input_placeholder ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">套用按鈕（尚未套用）</th><td><input type="text" name="wc_points_btn_apply_text" value="<?php echo esc_attr( $p_btn_apply_text ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">套用按鈕（已套用）</th><td><input type="text" name="wc_points_btn_update_text" value="<?php echo esc_attr( $p_btn_update_text ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">已套用折抵確認文字</th><td><input type="text" name="wc_points_applied_text" value="<?php echo esc_attr( $p_applied_text ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">餘額不足提示</th><td><input type="text" name="wc_points_no_balance_text" value="<?php echo esc_attr( $p_no_balance_text ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">未達最低消費門檻提示</th><td><input type="text" name="wc_points_min_cart_text" value="<?php echo esc_attr( $p_min_cart_text ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">限定商品未達成提示</th><td><input type="text" name="wc_points_restricted_text" value="<?php echo esc_attr( $p_restricted_text ); ?>" class="regular-text" /></td></tr>
                    </table>
                </div>
            </div>

            <?php submit_button( '儲存點數提示文字' ); ?>
        </form>
    <?php
}

/**
 * 頁籤：點數發放與退還時機。
 */
function twshop_points_award_tab() {
    $award_statuses  = twshop_get_points_award_statuses();
    $revoke_statuses = twshop_get_points_revoke_statuses();
    $order_statuses  = wc_get_order_statuses();
    ?>
        <form action="options.php" method="post">
            <?php settings_fields( 'wc_points_award_group' ); ?>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'clock', '點數發放與退還時機' ); ?>
                <div class="twshop-panel-body">
                <table class="form-table">
                    <tr>
                        <th scope="row">發放消費回饋點數的訂單狀態</th>
                        <td>
                            <input type="hidden" name="wc_points_award_statuses[]" value="" />
                            <?php foreach ( $order_statuses as $status_key => $status_label ) : $slug = str_replace( 'wc-', '', $status_key ); ?>
                                <label style="display:inline-block; margin:0 16px 6px 0;">
                                    <input type="checkbox" name="wc_points_award_statuses[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $award_statuses, true ) ); ?> />
                                    <?php echo esc_html( $status_label ); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">訂單進入以上任一勾選狀態時，發放該筆訂單的消費回饋點數（預設僅「已完成」）。同一張訂單只會發放一次，即使之後在多個勾選狀態間轉換也不會重複發放。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">退還/追回點數的訂單狀態</th>
                        <td>
                            <input type="hidden" name="wc_points_revoke_statuses[]" value="" />
                            <?php foreach ( $order_statuses as $status_key => $status_label ) : $slug = str_replace( 'wc-', '', $status_key ); ?>
                                <label style="display:inline-block; margin:0 16px 6px 0;">
                                    <input type="checkbox" name="wc_points_revoke_statuses[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $revoke_statuses, true ) ); ?> />
                                    <?php echo esc_html( $status_label ); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">訂單進入以上任一勾選狀態時，退還該訂單當初折抵扣除的點數，並追回已發放的消費回饋點數（預設「已取消」「已退款」「付款失敗」）。同一張訂單只會各自退還/追回一次。</p>
                        </td>
                    </tr>
                </table>
                </div>
            </div>

            <?php submit_button( '儲存發放與退還設定' ); ?>
        </form>
    <?php
}

/**
 * 頁籤：點數兌換商品。
 */
function twshop_points_redeem_tab() {
    $p_term = twshop_points_term();
    $redeemable_products = get_option( 'wc_points_redeemable_products', array() );
    if ( ! is_array( $redeemable_products ) ) $redeemable_products = array();

    $product_cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
    if ( is_wp_error( $product_cats ) ) $product_cats = array();
    $product_tags = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
    if ( is_wp_error( $product_tags ) ) $product_tags = array();

    $cat_options = array();
    foreach ( $product_cats as $term ) { $cat_options[ $term->term_id ] = $term->name; }
    $tag_options = array();
    foreach ( $product_tags as $term ) { $tag_options[ $term->term_id ] = $term->name; }
    ?>
        <form action="options.php" method="post">
            <?php settings_fields( 'wc_points_redeem_group' ); ?>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'gift', '點數兌換商品', '選擇可用' . esc_html( $p_term ) . '直接兌換的商品、分類或標籤；此功能與現金折抵分開計算。' ); ?>
                <div class="twshop-panel-body">
                    <?php echo twshop_render_redeemable_products_field( $redeemable_products, $cat_options, $tag_options ); ?>
                </div>
            </div>

            <?php submit_button( '儲存點數兌換商品設定' ); ?>
        </form>
    <?php
}

/**
 * 頁籤：匯入點數資料。純 AJAX 工具，不經 register_setting()/options.php，
 * 搬移前就已經是獨立在 <form> 之外，這裡原封不動延續同一個做法。
 */
function twshop_points_import_tab() {
    $p_term = twshop_points_term();
    ?>
        <div class="twshop-panel">
            <?php twshop_panel_head( 'upload', '匯入點數資料', '上傳 CSV，欄位依序為 <code>email,points,備註</code>（備註選填）；只增加' . esc_html( $p_term ) . '，不覆蓋原有餘額。', array(
                    'url'   => wp_nonce_url( admin_url( 'admin-post.php?action=twshop_download_points_import_template' ), 'twshop_download_points_import_template' ),
                    'label' => '下載範例 CSV',
                    'class' => 'button',
                ) ); ?>
            <div class="twshop-panel-body">
                <p>
                    <input type="file" id="twshop-points-import-file" accept=".csv,text/csv" />
                    <button type="button" class="button button-primary" id="twshop-points-import-btn">開始匯入</button>
                </p>
                <div id="twshop-points-import-result"></div>
            </div>
        </div>
        <?php twshop_enqueue_asset_script( 'admin/points-import', array(
            'twshopPointsImport' => array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'twshop_admin_action' ),
            ),
        ) ); ?>
    <?php
}

/**
 * 「點數兌換商品」清單的後台編輯 UI：單一隱藏欄位存 JSON（比照會員等級「生日禮/升等禮」的
 * gifts-json repeater 慣例），JS 端維護新增/移除、下方即時渲染已選清單。
 *
 * v25.8.15 起每筆設定可以是單一商品，也可以是整個商品分類/標籤（展開邏輯見
 * twshop_resolve_redeemable_products()，includes/modules/points-engine.php）。
 * 三個下拉選單（商品／分類／標籤）都先渲染在畫面上、用 JS 依「類型」下拉切換顯示/隱藏
 * 對應的那一個，而不是用 AJAX 動態換選項——分類/標籤清單通常很小，沒必要為了
 * 換一顆下拉選單多打一次 AJAX。
 *
 * v25.8.17 起選「分類」/「標籤」時「所需點數」欄位改隱藏——同分類底下商品售價通常不同，
 * 硬性統一成同一個點數等於讓貴的商品被賤賣，這兩種類型改成讀取端依各商品當下售價
 * 自動換算（twshop_calc_redeem_cost_from_price()），管理員不需要也不能為分類/標籤
 * 手動填點數，欄位切換邏輯見 assets/js/admin/redeemable-products.js。
 *
 * v25.8.27 起「單一商品」改用 twshop_render_product_search_field()（AJAX 搜尋，見
 * includes/admin/ui-components.php）取代原本一次性撈最多 200 筆商品塞進 <select> 的
 * 陽春下拉——商品多的店找不到、超過 200 筆的商品選不到。已加入清單的每筆項目改用
 * twshop_get_redeemable_entry_display_name() 在伺服器端把名稱解析好、直接寫進隱藏欄位
 * 的 JSON 裡（多一個 name 鍵，只給這裡渲染清單用，twshop_sanitize_points_redeemable_products()
 * 存檔時只白名單挑 type/id/points_cost/max_qty，name 會被自然忽略，不影響 option 本身的儲存格式）
 * ——AJAX 搜尋模式下 <select> 不會預先塞滿選項，JS 端沒有 DOM 可以查商品名稱，必須由
 * PHP 端先解析好。
 *
 * v25.8.32 起每筆多一個 max_qty（單次兌換上限數量，三種 type 都適用），跟 points_cost
 * 一樣走「就地點擊編輯」的 chip UI，見 assets/js/admin/redeemable-products.js。
 */
function twshop_render_redeemable_products_field( $redeemable_products, $cat_options, $tag_options ) {
    // 正規化成 {type, id, points_cost, max_qty}：舊資料（升級前存的 {product_id, points_cost}，
    // 管理員還沒重新儲存過這一頁）也要能正常顯示，不能直接把 $redeemable_products
    // 原封不動印進隱藏欄位，否則 JS 端會讀不到 id 而整批消失。
    $normalized = array();
    foreach ( $redeemable_products as $row ) {
        $entry = twshop_normalize_redeemable_entry( $row );
        $entry['name'] = twshop_get_redeemable_entry_display_name( $entry );
        $normalized[] = $entry;
    }

    ob_start();
    ?>
    <div class="twshop-redeem-products-section">
        <input type="hidden" name="wc_points_redeemable_products" value="<?php echo esc_attr( wp_json_encode( $normalized ) ); ?>" class="redeem-products-json">
        <div class="redeem-products-list twshop-chip-box"></div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:10px;">
            <select class="redeem-item-type-select">
                <option value="product">單一商品</option>
                <option value="category">商品分類（整批加入）</option>
                <option value="tag">商品標籤（整批加入）</option>
            </select>
            <span class="redeem-product-add-select-wrap" style="flex:1; min-width:220px;">
                <?php // name 純粹是這支共用元件的必要參數，這顆 <select> 本身不是表單欄位
                // （值只給 JS 讀取後推進 wc_points_redeemable_products 的 JSON，不直接送出），
                // 沒有註冊對應的 option，即使被送出也會被 options.php 忽略，無副作用。
                // 排除可變商品：twshop_ajax_redeem_points_product() 是直接
                // WC()->cart->add_to_cart( $id, 1, 0, ... )（不含 variation_id），選到可變商品
                // 的父商品會讓顧客兌換時必定失敗（WooCommerce 核心要求可變商品一定要指定規格）。
                echo twshop_render_product_search_field( 'redeem_product_add_picker', array(), false, '搜尋商品名稱或商品編號…', array( 'variable', 'wallet_credit' ) ); ?>
            </span>
            <select class="redeem-category-add-select" style="display:none;">
                <option value="">選擇商品分類</option>
                <?php foreach ( $cat_options as $term_id => $name ) : ?>
                    <option value="<?php echo esc_attr( $term_id ); ?>"><?php echo esc_html( $name ); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="redeem-tag-add-select" style="display:none;">
                <option value="">選擇商品標籤</option>
                <?php foreach ( $tag_options as $term_id => $name ) : ?>
                    <option value="<?php echo esc_attr( $term_id ); ?>"><?php echo esc_html( $name ); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" class="redeem-product-add-points" min="1" placeholder="所需點數" />
            <span class="redeem-category-cost-note twshop-text-muted" style="display:none; font-size:12px;">依商品售價自動換算，不需填點數</span>
            <input type="number" class="redeem-product-add-maxqty" min="1" placeholder="單次兌換上限" value="1" style="width:110px;" title="顧客單次最多可兌換幾個（預設 1）" />
            <button type="button" class="button add-redeem-product-btn">加入</button>
        </div>
        <p class="description">分類/標籤是動態展開：加入後，日後新上架進該分類/標籤的商品會自動一併開放兌換，不需要回來這裡重新設定；兌換點數也不是統一值，而是依各商品目前售價換算（換算匯率沿用上方「點數折抵匯率」設定），避免同分類裡貴的商品被低點數賤賣。「單次兌換上限」是顧客一次點擊「立即兌換」最多能選幾個，預設 1（跟改版前行為相同）。</p>
    </div>
    <?php twshop_enqueue_asset_script( 'admin/redeemable-products' ); ?>
    <?php
    return ob_get_clean();
}
