<?php
/**
 * 後台儀表板頁面與控制台 widget
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 儀表板統計資料的共用計算入口，供後台「儀表板」頁面（`twshop_dashboard_render_page()`）與
 * WordPress 原生控制台儀表板 widget（`twshop_render_dashboard_widget()`）共用，避免兩處
 * 分別查詢同一批資料、日後改一邊忘了改另一邊。含 per-request static cache（同一次請求內
 * 兩處都會呼叫時，例如理論上不會同時發生但仍保守處理，只查一次）。
 */
function twshop_get_dashboard_stats() {
    static $stats = null;
    if ( null !== $stats ) return $stats;

    $modules = twshop_get_module_definitions();
    $module_icons = array(
        'member_tiers'    => 'crown',
        'discount_rules'  => 'percent',
        'visual_coupons'  => 'ticket',
        'points'          => 'coins',
        'order_checkout_enhancements' => 'clipboard-list',
    );
    // order_checkout_enhancements 沒有對應選單頁（純 hook 開關，無可調整設定，見
    // twshop_register_menus() 的說明），卡片維持唯讀、不可點擊。
    $module_urls = array(
        'member_tiers'    => admin_url( 'admin.php?page=twshop-member-tiers' ),
        'discount_rules'  => admin_url( 'admin.php?page=twshop-discount-rules' ),
        'visual_coupons'  => admin_url( 'admin.php?page=twshop-system&tab=coupons' ),
        'points'          => admin_url( 'admin.php?page=twshop-points' ),
    );
    $enabled_count = 0;
    foreach ( $modules as $mod_key => $info ) {
        if ( twshop_module_enabled( $mod_key ) ) $enabled_count++;
    }

    $tiers       = get_option( 'wc_member_tiers_settings', array() );
    $tier_count  = count( $tiers );
    $rule_count  = count( twshop_get_rules() );
    $points_rate = get_option( 'wc_points_redemption_rate', 1 );

    // 優惠卡券數量：跟 twshop_auto_display_coupons() 用同一套 meta_query（_visual_coupon_title
    // 存在即視為視覺化優惠券），只取 ID 計數，不逐筆組 HTML，成本很低。
    $coupon_count = count( get_posts( array(
        'posts_per_page' => -1,
        'post_type'      => 'shop_coupon',
        'post_status'    => 'publish',
        'no_found_rows'  => true,
        'fields'         => 'ids',
        'meta_query'     => array(
            array( 'key' => '_visual_coupon_title', 'compare' => 'EXISTS' ),
        ),
    ) ) );

    // 加購品數量：折扣規則裡 type === 'addon_product' 且未停用的筆數。
    $addon_count = 0;
    foreach ( twshop_get_rules() as $r ) {
        if ( 'addon_product' === ( $r['type'] ?? '' ) && 'no' !== ( $r['enabled'] ?? 'yes' ) ) $addon_count++;
    }

    // 會員等級分佈：count_users() 是 WP 核心函式，一次查詢回傳各角色人數，比逐一等級各自
    // WP_User_Query 便宜；等級 slug 本來就等於 WP role（見「會員等級判定邏輯」）。
    $tier_user_counts = count_users();
    $tier_max_count = 0;
    foreach ( $tiers as $t ) {
        $tier_max_count = max( $tier_max_count, $tier_user_counts['avail_roles'][ $t['slug'] ] ?? 0 );
    }

    $stats = compact(
        'modules', 'module_icons', 'module_urls', 'enabled_count',
        'tiers', 'tier_count', 'rule_count', 'points_rate',
        'coupon_count', 'addon_count', 'tier_user_counts', 'tier_max_count'
    );
    return $stats;
}

/**
 * 儀表板：功能總覽（v25.5.86 起「常用連結」與「模組狀態」合併成一個區塊——每張模組卡片
 * 本身就是連到該模組設定頁的連結，不再需要另外一組獨立的連結清單重複列出同樣的 5 個項目）。
 * 模組的啟用/停用狀態唯讀顯示於此，實際開關在「系統設定 ▸ 模組開關」。
 */
function twshop_dashboard_render_page() {
    // 統計資料的計算刻意寫在 closure 內：twshop_render_admin_page() 的權限檢查要先跑，
    // 才輪得到 twshop_get_dashboard_stats() 去查一整批訂單/使用者/規則。
    twshop_render_admin_page( '終極電商', function () {
        $stats            = twshop_get_dashboard_stats();
        $modules          = $stats['modules'];
        $module_icons     = $stats['module_icons'];
        $module_urls      = $stats['module_urls'];
        $enabled_count    = $stats['enabled_count'];
        $tiers            = $stats['tiers'];
        $tier_count       = $stats['tier_count'];
        $rule_count       = $stats['rule_count'];
        $points_rate      = $stats['points_rate'];
        $coupon_count     = $stats['coupon_count'];
        $addon_count      = $stats['addon_count'];
        $tier_user_counts = $stats['tier_user_counts'];
        $tier_max_count   = $stats['tier_max_count'];

        ?>
        <div class="twshop-dash-stats">
            <div class="twshop-dash-stat">
                <span class="twshop-dash-stat__icon"><?php echo twshop_get_account_tab_icon_svg( 'layout-dashboard' ); ?></span>
                <span class="twshop-dash-stat__value"><?php echo esc_html( $enabled_count ); ?>/<?php echo esc_html( count( $modules ) ); ?></span>
                <span class="twshop-dash-stat__label">已啟用模組</span>
            </div>
            <div class="twshop-dash-stat">
                <span class="twshop-dash-stat__icon"><?php echo twshop_get_account_tab_icon_svg( 'crown' ); ?></span>
                <span class="twshop-dash-stat__value"><?php echo esc_html( $tier_count ); ?></span>
                <span class="twshop-dash-stat__label">會員等級</span>
            </div>
            <div class="twshop-dash-stat">
                <span class="twshop-dash-stat__icon"><?php echo twshop_get_account_tab_icon_svg( 'percent' ); ?></span>
                <span class="twshop-dash-stat__value"><?php echo esc_html( $rule_count ); ?></span>
                <span class="twshop-dash-stat__label">折扣規則</span>
            </div>
            <div class="twshop-dash-stat">
                <span class="twshop-dash-stat__icon"><?php echo twshop_get_account_tab_icon_svg( 'coins' ); ?></span>
                <span class="twshop-dash-stat__value"><?php echo esc_html( $points_rate ); ?> 點 = $1</span>
                <span class="twshop-dash-stat__label"><?php echo esc_html( twshop_points_term() ); ?>折抵匯率</span>
            </div>
            <div class="twshop-dash-stat">
                <span class="twshop-dash-stat__icon"><?php echo twshop_get_account_tab_icon_svg( 'ticket' ); ?></span>
                <span class="twshop-dash-stat__value"><?php echo esc_html( $coupon_count ); ?></span>
                <span class="twshop-dash-stat__label">優惠卡券</span>
            </div>
            <div class="twshop-dash-stat">
                <span class="twshop-dash-stat__icon"><?php echo twshop_get_account_tab_icon_svg( 'package' ); ?></span>
                <span class="twshop-dash-stat__value"><?php echo esc_html( $addon_count ); ?></span>
                <span class="twshop-dash-stat__label">加購品規則</span>
            </div>
        </div>

        <div class="twshop-panel">
            <?php twshop_panel_head( 'layout-dashboard', '功能總覽', '', array( 'url' => admin_url( 'admin.php?page=twshop-system&tab=modules' ), 'label' => '前往模組開關' ) ); ?>
            <div class="twshop-panel-body">
                <div class="twshop-dash-modules">
                    <?php foreach ( $modules as $mod_key => $info ) :
                        $enabled = twshop_module_enabled( $mod_key );
                        $url     = $module_urls[ $mod_key ] ?? null;
                        $tag     = $url ? 'a' : 'div';
                    ?>
                    <<?php echo $tag; ?> class="twshop-dash-module <?php echo $enabled ? 'is-enabled' : 'is-disabled'; ?>"<?php if ( $url ) : ?> href="<?php echo esc_url( $url ); ?>"<?php endif; ?>>
                        <div class="twshop-dash-module__head">
                            <span class="twshop-dash-module__icon"><?php echo twshop_get_account_tab_icon_svg( $module_icons[ $mod_key ] ?? 'package' ); ?></span>
                            <strong><?php echo esc_html( $info['label'] ); ?></strong>
                            <span class="twshop-badge <?php echo $enabled ? 'twshop-badge--ok' : 'twshop-badge--warn'; ?>"><?php echo $enabled ? '已啟用' : '已停用'; ?></span>
                        </div>
                        <p class="twshop-dash-module__desc"><?php echo esc_html( $info['desc'] ); ?></p>
                    </<?php echo $tag; ?>>
                    <?php endforeach; ?>
                    <a class="twshop-dash-module" href="<?php echo esc_url( admin_url( 'admin.php?page=twshop-system' ) ); ?>">
                        <div class="twshop-dash-module__head">
                            <span class="twshop-dash-module__icon"><?php echo twshop_get_account_tab_icon_svg( 'settings' ); ?></span>
                            <strong>系統設定</strong>
                        </div>
                        <p class="twshop-dash-module__desc">一般設定、模組開關、頁籤管理</p>
                    </a>
                </div>
            </div>
        </div>

        <div class="twshop-panel">
            <?php twshop_panel_head( 'crown', '會員等級分佈', '', array( 'url' => admin_url( 'admin.php?page=twshop-member-tiers' ), 'label' => '前往會員分級' ) ); ?>
            <div class="twshop-panel-body">
                <?php if ( empty( $tiers ) ) : ?>
                <p class="twshop-dash-tiers-empty">尚未設定任何會員等級。</p>
                <?php else : ?>
                <div class="twshop-dash-tiers">
                    <?php foreach ( $tiers as $t ) :
                        $t_count = $tier_user_counts['avail_roles'][ $t['slug'] ] ?? 0;
                        $t_pct   = $tier_max_count > 0 ? round( $t_count / $tier_max_count * 100 ) : 0;
                    ?>
                    <div class="twshop-dash-tier-row">
                        <span class="twshop-dash-tier-name"><?php echo esc_html( $t['name'] ?: $t['slug'] ); ?></span>
                        <div class="twshop-dash-tier-bar-track"><div class="twshop-dash-tier-bar-fill" style="width:<?php echo esc_attr( $t_pct ); ?>%"></div></div>
                        <span class="twshop-dash-tier-count"><?php echo esc_html( $t_count ); ?> 人</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
<?php
    }, '功能依模組各自獨立成一個選單頁面，不受模組開關影響的一般設定則統一收在「系統設定」。' );
    ?>
    <?php
}

/**
 * v25.5.88：WordPress 原生控制台（wp-admin/index.php，非本外掛自己的「儀表板」選單頁）新增一個
 * dashboard widget，呈現本外掛儀表板的部分資訊（模組啟用狀態＋幾個關鍵數字），讓管理員一登入
 * 後台首頁就能看到，不用特地點進「終極電商」選單。只對有 manage_woocommerce 權限的使用者註冊，
 * 跟本外掛後台選單本身的權限要求一致。掛載點在 twshop_membership_init()（跟 admin_menu 那行
 * 放一起），這裡只放函式定義本身。
 */
function twshop_register_dashboard_widget() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    wp_add_dashboard_widget( 'twshop_dashboard_widget', '終極電商', 'twshop_render_dashboard_widget' );
}

/**
 * dashboard widget 內容：資料來源跟「終極電商」選單裡的儀表板頁共用同一個
 * twshop_get_dashboard_stats()，只挑其中一部分呈現（5 個模組的啟用/停用狀態徽章＋4 個關鍵數字），
 * 不是把整頁儀表板塞進小小的 widget 裡；完整內容（含會員等級分佈）仍需點連結前往完整儀表板頁。
 */
function twshop_render_dashboard_widget() {
    $stats = twshop_get_dashboard_stats();
    $modules      = $stats['modules'];
    $module_icons = $stats['module_icons'];
    $module_urls  = $stats['module_urls'];
    ?>
    <div class="twshop-widget">
        <ul class="twshop-widget__modules">
            <?php foreach ( $modules as $mod_key => $info ) :
                $enabled = twshop_module_enabled( $mod_key );
                $url     = $module_urls[ $mod_key ] ?? null;
            ?>
            <li class="twshop-widget__module <?php echo $enabled ? 'is-enabled' : 'is-disabled'; ?>">
                <span class="twshop-widget__module-icon"><?php echo twshop_get_account_tab_icon_svg( $module_icons[ $mod_key ] ?? 'package' ); ?></span>
                <?php if ( $url ) : ?>
                <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $info['label'] ); ?></a>
                <?php else : ?>
                <span><?php echo esc_html( $info['label'] ); ?></span>
                <?php endif; ?>
                <span class="twshop-widget__module-dot" title="<?php echo $enabled ? '已啟用' : '已停用'; ?>"></span>
            </li>
            <?php endforeach; ?>
        </ul>

        <div class="twshop-widget__stats">
            <div><strong><?php echo esc_html( $stats['tier_count'] ); ?></strong><span>會員等級</span></div>
            <div><strong><?php echo esc_html( $stats['rule_count'] ); ?></strong><span>折扣規則</span></div>
            <div><strong><?php echo esc_html( $stats['coupon_count'] ); ?></strong><span>優惠卡券</span></div>
            <div><strong><?php echo esc_html( $stats['addon_count'] ); ?></strong><span>加購品規則</span></div>
        </div>

        <p class="twshop-widget__more"><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-general-settings' ) ); ?>">查看完整儀表板 &rarr;</a></p>
    </div>
    <?php
}

