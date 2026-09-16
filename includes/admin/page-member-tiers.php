<?php
/**
 * 介面 1：會員與自動贈禮設定
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// -------------------------------------------------------------------------
// 介面 1：會員與自動贈禮設定
// -------------------------------------------------------------------------
function twshop_member_tiers_tab() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足。' );
    $b_days = get_option( 'wc_birthday_validity_days', 30 );
    $b_subject = twshop_option( 'wc_birthday_email_subject' );
    $u_days = get_option( 'wc_upgrade_validity_days', 30 );
    $u_subject = twshop_option( 'wc_upgrade_email_subject' );

    $birthday_body          = get_option( 'wc_birthday_email_body', "親愛的 {name}：\n\n生日快樂！這是系統為您生成的生日禮包：\n{codes}\n\n有效期限為 {days} 天，請至網站查看！" );
    $upgrade_body_gift      = get_option( 'wc_upgrade_email_body_gift', "恭喜升級！這是您的專屬升級禮包：\n{codes}\n\n請至會員中心查看。" );
    $upgrade_subject_no_gift = twshop_option( 'wc_upgrade_email_subject_no_gift' );
    $upgrade_body_no_gift    = twshop_option( 'wc_upgrade_email_body_no_gift' );
    $tier_change_subject     = twshop_option( 'wc_tier_change_email_subject' );
    $tier_change_body        = twshop_option( 'wc_tier_change_email_body' );

    $tier_max_reached_text  = twshop_option( 'wc_tier_max_reached_text' );
    $tier_not_configured    = twshop_option( 'wc_tier_not_configured_text' );

    $tiers = get_option( 'wc_member_tiers_settings', array() );
    ?>
        <form action="options.php" method="post">
            <?php settings_fields( 'wc_member_tiers_group' ); ?>

            <h2 class="twshop-section-title">會員等級設定 <span class="twshop-hint">(提示：請利用卡片標題左側圖示拖曳排序，將最高等級放在最上方)</span></h2>
            <p class="description">優惠券頁面文字請至<a href="<?php echo esc_url( admin_url( 'admin.php?page=twshop-system&tab=coupons' ) ); ?>">「優惠卡券」</a>頁面編輯。</p>
            <div id="tier-repeater-container">
                <?php
                if ( ! empty( $tiers ) ) { foreach ( $tiers as $tier ) echo twshop_get_tier_row_html( $tier ); }
                else { echo twshop_get_tier_row_html( array() ); }
                ?>
            </div>

            <p><button type="button" class="button" id="add-tier-row">新增會員等級</button></p>

            <?php submit_button( '儲存設定', 'primary', 'submit-tiers' ); ?>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'gift', '生日禮與升級禮全域設定' ); ?>
                <div class="twshop-panel-body">
                    <div class="twshop-two-col">
                        <div>
                            <h3>生日禮</h3>
                            <label>有效天數：<input type="number" name="wc_birthday_validity_days" value="<?php echo esc_attr($b_days); ?>" class="small-text" /> 天</label>
                        </div>
                        <div>
                            <h3>升級禮</h3>
                            <label>有效天數：<input type="number" name="wc_upgrade_validity_days" value="<?php echo esc_attr($u_days); ?>" class="small-text" /> 天</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'mail', '會員通知信件內容', '四種會員等級相關通知信的主旨與正文，統一在此設定。' ); ?>
                <div class="twshop-panel-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">生日禮信件內容</th>
                            <td>
                                主旨：<input type="text" name="wc_birthday_email_subject" value="<?php echo esc_attr( $b_subject ); ?>" class="regular-text" /><br><br>
                                內容：<textarea name="wc_birthday_email_body" rows="4" class="regular-text"><?php echo esc_html( $birthday_body ); ?></textarea>
                                <p class="description">可用 <code>{name}</code>／<code>{codes}</code>／<code>{days}</code> 代表會員姓名/優惠券代碼清單/有效天數。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">升級禮信件內容</th>
                            <td>
                                主旨：<input type="text" name="wc_upgrade_email_subject" value="<?php echo esc_attr( $u_subject ); ?>" class="regular-text" /><br><br>
                                內容：<textarea name="wc_upgrade_email_body_gift" rows="4" class="regular-text"><?php echo esc_html( $upgrade_body_gift ); ?></textarea>
                                <p class="description">升級且該等級有設定升等禮包時寄送。可用 <code>{codes}</code> 代表優惠券代碼清單。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">升級通知（未設定贈禮）</th>
                            <td>
                                主旨：<input type="text" name="wc_upgrade_email_subject_no_gift" value="<?php echo esc_attr( $upgrade_subject_no_gift ); ?>" class="regular-text" /><br><br>
                                內容：<textarea name="wc_upgrade_email_body_no_gift" rows="3" class="regular-text"><?php echo esc_html( $upgrade_body_no_gift ); ?></textarea>
                                <p class="description">升級但該等級未啟用升等禮／未設定贈禮時寄送。可用 <code>{tier}</code> 代表新等級名稱。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">降級/等級調整通知</th>
                            <td>
                                主旨：<input type="text" name="wc_tier_change_email_subject" value="<?php echo esc_attr( $tier_change_subject ); ?>" class="regular-text" /><br><br>
                                內容：<textarea name="wc_tier_change_email_body" rows="3" class="regular-text"><?php echo esc_html( $tier_change_body ); ?></textarea>
                                <p class="description">週期到期後依累積消費降級或調整等級時寄送。可用 <code>{tier}</code> 代表調整後的等級名稱。</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'pencil', '會員進度頁文字' ); ?>
                <div class="twshop-panel-body">
                    <table class="form-table">
                        <tr><th scope="row">已達最高等級文字</th><td><input type="text" name="wc_tier_max_reached_text" value="<?php echo esc_attr( $tier_max_reached_text ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">尚未設定等級制度提示</th><td><input type="text" name="wc_tier_not_configured_text" value="<?php echo esc_attr( $tier_not_configured ); ?>" class="regular-text" /></td></tr>
                    </table>
                </div>
            </div>

            <?php submit_button( '儲存所有設定' ); ?>
        </form>

        <div id="tier-template" style="display:none;">
            <?php echo twshop_get_tier_row_html( array() ); ?>
        </div>

    <?php twshop_enqueue_asset_script( 'admin/member-tiers', array(
        'twshopMemberTiers' => array( 'pointsTerm' => twshop_points_term() ),
    ) ); ?>
    <?php
}

function twshop_get_account_tab_row_html( $slug, $label, $enabled, $icon = '' ) {
    $is_logout   = ( 'customer-logout' === $slug );
    $name_option = array( 'my-membership' => 'wc_membership_tab_name', 'my-coupons' => 'wc_general_tab_name' );
    ob_start();
    ?>
    <div class="twshop-tab-row" style="display:flex; align-items:center; gap:12px; background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:10px 14px; margin-bottom:8px;">
        <span class="drag-handle twshop-text-muted" style="cursor:move;"><?php echo twshop_get_account_tab_icon_svg( 'grip-vertical' ); ?></span>
        <input type="hidden" name="wc_account_tabs_settings[slug][]" value="<?php echo esc_attr( $slug ); ?>" />
        <input type="hidden" class="tab-enabled-input" name="wc_account_tabs_settings[enabled][]" value="<?php echo esc_attr( $is_logout ? 'yes' : $enabled ); ?>" />
        <?php echo twshop_render_account_tab_icon_picker( $slug, $icon ); ?>
        <span style="flex:1; display:flex; align-items:center; gap:8px;">
            <?php if ( isset( $name_option[ $slug ] ) ) : ?>
                <input type="text" name="<?php echo esc_attr( $name_option[ $slug ] ); ?>" value="<?php echo esc_attr( $label ); ?>" class="regular-text" style="max-width:220px;" />
            <?php else : ?>
                <input type="text" name="wc_account_tab_names[<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( $label ); ?>" class="regular-text" style="max-width:220px;" />
            <?php endif; ?>
            <code class="twshop-text-muted" style="font-weight:normal;">(<?php echo esc_html( $slug ); ?>)</code>
        </span>
        <label style="display:flex; align-items:center; gap:6px; white-space:nowrap;">
            <input type="checkbox" class="tab-enabled-checkbox" <?php checked( $is_logout || 'yes' === $enabled ); ?> <?php disabled( $is_logout ); ?> />
            啟用<?php if ( $is_logout ) echo '（登出無法關閉）'; ?>
        </label>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * 單一頁籤的圖示選擇器（按鈕預覽目前圖示，點擊展開 Lucide SVG 圖示網格選單）。
 * 實際送出的欄位是隱藏的 <input name="wc_account_tab_icons[{slug}]">，由 JS 點選面板選項時寫入。
 *
 * 圖示網格面板（50 個選項的完整 SVG 集合）不在這裡輸出——改成整頁只渲染一份共用面板
 * （見 twshop_render_account_tab_icon_picker_assets()），開啟時由 JS 把同一個面板節點搬進
 * 當下這顆按鈕所屬的 .twshop-icon-picker。頁籤數多的設定頁若每列各自重複輸出一次完整 50 個
 * icon 的 SVG markup，會讓單一頁面 HTML 膨脹到頁籤數 × 圖示數的量級，共用一份可避免這個問題。
 */
function twshop_render_account_tab_icon_picker( $slug, $current_icon ) {
    $choices      = twshop_get_account_tab_icon_choices();
    $current_icon = isset( $choices[ $current_icon ] ) ? $current_icon : '';
    ob_start();
    ?>
    <div class="twshop-icon-picker">
        <button type="button" class="button twshop-icon-picker-toggle<?php echo $current_icon ? '' : ' is-empty'; ?>" title="選擇圖示" aria-label="選擇圖示">
            <span class="twshop-icon-picker-preview"><?php echo $current_icon ? twshop_get_account_tab_icon_svg( $current_icon ) : twshop_get_account_tab_icon_svg( 'ban' ); ?></span>
        </button>
        <input type="hidden" class="twshop-icon-picker-input" name="wc_account_tab_icons[<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( $current_icon ); ?>" />
    </div>
    <?php
    return ob_get_clean();
}

/**
 * 圖示選擇器共用資源（CSS + JS + 唯一一份圖示網格面板），同一頁只輸出一次（static 旗標防重複），
 * 沿用 twshop_render_chip_field_assets() 的模式。面板節點由 JS 在開啟時搬移到對應的 .twshop-icon-picker
 * 底下（見下方 script），關閉後留在原地，不需要每次都搬回來。
 */
function twshop_render_account_tab_icon_picker_assets() {
    static $printed = false;
    if ( $printed ) return;
    $printed = true;
    ?>
    <div id="twshop-icon-picker-shared-panel" class="twshop-icon-picker-panel">
        <button type="button" class="twshop-icon-picker-option twshop-icon-picker-option--none" data-icon="" title="不顯示圖示">
            <?php echo twshop_get_account_tab_icon_svg( 'ban' ); ?><span>不顯示圖示</span>
        </button>
        <?php foreach ( twshop_get_account_tab_icon_choices() as $icon_slug => $icon_label ) : ?>
            <button type="button" class="twshop-icon-picker-option" data-icon="<?php echo esc_attr( $icon_slug ); ?>" title="<?php echo esc_attr( $icon_label ); ?>">
                <?php echo twshop_get_account_tab_icon_svg( $icon_slug ); ?>
            </button>
        <?php endforeach; ?>
    </div>
    <?php twshop_enqueue_asset_script( 'admin/icon-picker' ); ?>
    <?php
}

function twshop_get_tier_row_html( $t ) {
    $slug = $t['slug'] ?? ''; $name = $t['name'] ?? ''; $threshold = $t['threshold'] ?? ''; $period = $t['period'] ?? '365';
    $b_enable = $t['b_enable'] ?? 'no'; $b_gifts = $t['b_gifts'] ?? '[]';
    $u_enable = $t['u_enable'] ?? 'no'; $u_gifts = $t['u_gifts'] ?? '[]';
    $point_multiplier = $t['point_multiplier'] ?? '1';
    ob_start();
    ?>
    <div class="twshop-tier-card" style="background:#fff; border:1px solid #ccd0d4; margin-bottom:15px; border-radius:5px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div class="twshop-card-header" style="padding:15px; background:#f7f7f7; cursor:pointer; display:flex; justify-content:space-between; align-items:center; font-weight:bold; border-bottom:1px solid #eee;">
            <span>
                <span class="drag-handle twshop-text-muted" style="cursor:move; margin-right:10px;" title="拖曳排序"><?php echo twshop_get_account_tab_icon_svg( 'grip-vertical' ); ?></span>
                <?php echo $name ? esc_html($name) : '新等級'; ?>
            </span>
            <span class="twshop-card-toggle-icon <?php echo $name ? '' : 'is-open'; ?>" title="點擊收合或展開"><?php echo twshop_get_account_tab_icon_svg( 'chevron-down' ); ?></span>
        </div>
        <div class="twshop-card-body" style="padding:20px; <?php echo $name ? 'display:none;' : ''; ?>">
            <div style="display:flex; flex-wrap:wrap; gap:15px; margin-bottom:20px;">
                <div style="flex:1; min-width:150px;"><label style="font-weight:bold; display:block; margin-bottom:5px;">等級識別碼</label><input type="text" name="wc_member_tiers_settings[slug][]" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" style="width:100%;" required /></div>
                <div style="flex:1; min-width:150px;"><label style="font-weight:bold; display:block; margin-bottom:5px;">顯示名稱</label><input type="text" name="wc_member_tiers_settings[name][]" value="<?php echo esc_attr( $name ); ?>" class="regular-text" style="width:100%;" required /></div>
                <div style="flex:1; min-width:150px;"><label style="font-weight:bold; display:block; margin-bottom:5px;">升級門檻 ($)</label><input type="number" name="wc_member_tiers_settings[threshold][]" value="<?php echo esc_attr( $threshold ); ?>" min="0" class="regular-text" style="width:100%;" required /></div>
                <div style="flex:1; min-width:150px;">
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">維持效期 (天)</label>
                    <input type="number" name="wc_member_tiers_settings[period][]" value="<?php echo esc_attr( $period ); ?>" min="0" class="regular-text" style="width:100%;" placeholder="0為永久" required />
                    <p class="description" style="margin:4px 0 0;">會員達成本等級後，需在這段天數內維持門檻消費才能續等，否則到期時將依累積消費調整等級（可能降級或跳過中間等級變回一般顧客）；0 = 永久，達成後不再檢查降級。</p>
                </div>
                <div style="flex:1; min-width:150px;"><label style="font-weight:bold; display:block; margin-bottom:5px;">點數加倍倍率</label><input type="number" step="0.1" name="wc_member_tiers_settings[point_multiplier][]" value="<?php echo esc_attr( $point_multiplier ); ?>" min="1" class="regular-text" style="width:100%;" required /></div>
            </div>
            
            <div class="gifts-section" style="background:#f9f9f9; padding:15px; border-radius:4px; margin-bottom:15px; border: 1px solid #eee;">
                <label style="font-weight:bold;"><input type="checkbox" name="wc_member_tiers_settings[b_enable][]" value="yes" <?php checked($b_enable, 'yes'); ?>> 啟用專屬生日禮包</label>
                <input type="hidden" name="wc_member_tiers_settings[b_gifts][]" value="<?php echo esc_attr($b_gifts); ?>" class="gifts-json">
                <div class="gifts-list" style="margin:10px 0;"></div>
                <div style="display:flex; gap:10px; align-items:center;">
                    <select class="gift-add-type"><option value="percent">百分比折扣(%)</option><option value="fixed_cart">固定金額折抵($)</option><option value="points"><?php echo esc_html( twshop_points_term() ); ?></option></select>
                    <input type="number" class="gift-add-val small-text" placeholder="額度" />
                    <button type="button" class="button add-gift-btn">加入禮包</button>
                </div>
            </div>

            <div class="gifts-section" style="background:#fffcf5; padding:15px; border-radius:4px; margin-bottom:15px; border: 1px solid #fae8c3;">
                <label style="font-weight:bold;"><input type="checkbox" name="wc_member_tiers_settings[u_enable][]" value="yes" <?php checked($u_enable, 'yes'); ?>> 啟用達成升級禮包</label>
                <input type="hidden" name="wc_member_tiers_settings[u_gifts][]" value="<?php echo esc_attr($u_gifts); ?>" class="gifts-json">
                <div class="gifts-list" style="margin:10px 0;"></div>
                <div style="display:flex; gap:10px; align-items:center;">
                    <select class="gift-add-type"><option value="percent">百分比折扣(%)</option><option value="fixed_cart">固定金額折抵($)</option><option value="points"><?php echo esc_html( twshop_points_term() ); ?></option></select>
                    <input type="number" class="gift-add-val small-text" placeholder="額度" />
                    <button type="button" class="button add-gift-btn">加入禮包</button>
                </div>
            </div>
            <div style="text-align:right;"><button type="button" class="button remove-tier-row twshop-button-danger">刪除此等級</button></div>
        </div>
    </div>
    <?php return ob_get_clean();
}

