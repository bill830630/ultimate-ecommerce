<?php
/**
 * 後台選單註冊與「模組設定」頁籤
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 模組設定
// =========================================================================

// 僅限 Administrator（manage_options）操作，跟外掛其餘頁面/AJAX 一律用 manage_woocommerce
// 的慣例不同——這是刻意的例外：模組開關會整批啟用/停用電商功能，影響範圍比其他設定頁大，
// 不該讓 Shop Manager 這類只有 manage_woocommerce 的角色碰。這一行同時擋畫面顯示與下方
// $_POST 手動存檔（此頁籤不走 options.php，沒有 WordPress 核心那層 manage_options 保護）。
function twshop_system_modules_tab() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足。' );

    $modules = twshop_get_module_definitions();

    $mod_settings = get_option( 'twshop_module_settings', array() );

    // 這個頁籤用自己的 <form>＋$_POST 手動處理，不像其他頁籤透過 register_setting()/options.php。
    if ( isset( $_POST['twshop_save_modules'] ) ) {
        check_admin_referer( 'twshop_module_settings' );
        $new = array();
        foreach ( $modules as $mod_key => $info ) {
            $new[ $mod_key ] = isset( $_POST['modules'][ $mod_key ] ) ? '1' : '0';
        }
        update_option( 'twshop_module_settings', $new );
        $mod_settings = $new;
        echo '<div class="notice notice-success is-dismissible"><p>模組設定已儲存。</p></div>';
    }
    ?>
    <form method="post">
        <?php wp_nonce_field( 'twshop_module_settings' ); ?>
        <div class="twshop-panel" style="max-width:700px;">
            <?php twshop_panel_head( 'settings', '功能模組' ); ?>
            <div class="twshop-panel-body" style="padding:4px 24px;">
                <?php foreach ( $modules as $mod_key => $info ) :
                    $enabled = ( $mod_settings[ $mod_key ] ?? '0' ) === '1'; // 預設值同步 twshop_module_enabled()（helpers.php）
                ?>
                <div class="twshop-module-row">
                    <label class="twshop-toggle">
                        <input type="checkbox"
                               name="modules[<?php echo esc_attr( $mod_key ); ?>]"
                               value="1"
                               <?php checked( $enabled ); ?>>
                        <span class="twshop-slider"></span>
                    </label>
                    <span class="twshop-module-label"><?php echo esc_html( $info['label'] ); ?></span>
                    <span class="twshop-module-desc"><?php echo esc_html( $info['desc'] ); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <input type="hidden" name="twshop_save_modules" value="1">
        <?php submit_button( '儲存模組設定', 'primary', 'submit', true ); ?>
    </form>
    <?php
}

function twshop_register_menus() {
    add_menu_page( '終極電商', '終極電商', 'manage_woocommerce', 'wc-general-settings', 'twshop_dashboard_render_page', 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyBmaWxsPSJ3aGl0ZSIgaWQ9Il/lnJblsaRfMiIgZGF0YS1uYW1lPSLlnJblsaQgMiIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB2aWV3Qm94PSIwIDAgNDEzLjExIDQxMy4xMSI+CiAgPGcgaWQ9Il/lnJblsaRfMS0yIiBkYXRhLW5hbWU9IuWcluWxpCAxIj4KICAgIDxnPgogICAgICA8cGF0aCBkPSJNMjA2LjU1LDBDMTM5LjI1LDAsNzkuNDcsMzIuMiw0MS43Niw4Mi4wMmw4MC40Niw4MC40Niw0NC4wNy00NC4wN2MxMy42NC0xMy42NCwyOS42NS0yMy40NCw0Ni43LTI5LjQ0LDEwLjIzLTMuNTksMjAuODMtNS44MiwzMS41NC02LjY3LDM1LjExLTIuNzksNzEuMTksOS4yNSw5OC4wNCwzNi4xMSw0OC42OSw0OC42OCw0OC42OCwxMjcuNjIsMCwxNzYuMy0yNi44NSwyNi44Ny02Mi45NCwzOC45MS05OC4wNSwzNi4xMS0xMC42Mi0uODQtMjEuMTYtMy4wMy0zMS4zMy02LjU4LTE3LjE0LTUuOTktMzMuMjItMTUuODQtNDYuOTEtMjkuNTMtLjA0LS4wMy0uMDYtLjA3LS4xLS4xMmwtNDMuOTYtNDMuOTYtODAuNDYsODAuNDZjMzcuNzEsNDkuODIsOTcuNDksODIuMDIsMTY0Ljc5LDgyLjAyLDExNC4wOCwwLDIwNi41NS05Mi40OCwyMDYuNTUtMjA2LjU1UzMyMC42MywwLDIwNi41NSwwWiIvPgogICAgICA8cGF0aCBkPSJNMTEuMTMsMTM5LjUzQzMuOTIsMTYwLjU1LDAsMTgzLjA5LDAsMjA2LjU1czMuOTIsNDYuMDEsMTEuMTMsNjcuMDJsNjcuMDItNjcuMDJMMTEuMTMsMTM5LjUzWiIvPgogICAgICA8cGF0aCBkPSJNMjQ0LjUzLDI2OC4wOGMxOS4wNywzLjA3LDM5LjI5LTIuNzUsNTMuOTktMTcuNDYsMjQuMzMtMjQuMzMsMjQuMzItNjMuOC0uMDEtODguMTQtMTQuNy0xNC43LTM0LjkxLTIwLjUxLTUzLjk3LTE3LjQ1LTExLjM3LDEuODEtMjIuMzMsNi43Ny0zMS40NiwxNC45Mi0uOTMuODEtMS44MywxLjY2LTIuNzEsMi41NGwtNDQuMDYsNDQuMDcsNDQuMDcsNDQuMDdjLjkuOSwxLjgzLDEuNzcsMi43NywyLjYxLDkuMTQsOC4wOCwyMC4wNiwxMy4wNCwzMS4zOSwxNC44NFoiLz4KICAgIDwvZz4KICA8L2c+Cjwvc3ZnPg==', 56 );
    remove_submenu_page( 'wc-general-settings', 'wc-general-settings' );
    add_submenu_page( 'wc-general-settings', '儀表板', '儀表板', 'manage_woocommerce', 'wc-general-settings', 'twshop_dashboard_render_page' );
    // v25.5.84：後台選單改成跟「系統設定 ▸ 模組開關」的模組清單對齊，原本「行銷／會員」兩個大分類、
    // 底下再切頁籤的結構拆開，每個有獨立設定內容的模組各自變成一個頂層選單項目，模組停用時
    // 該選單項目直接不註冊（沿用改版前「模組停用時對應頁籤從導覽列消失」的既有精神，只是
    // 現在消失的單位是整個選單項目而非頁籤）。`order_checkout_enhancements` 模組本身沒有任何
    // 可調整設定（純粹是 hook 開關），故不建立對應選單項目，開關維持只在「系統設定 ▸ 模組開關」操作。
    if ( twshop_module_enabled( 'member_tiers' ) ) {
        add_submenu_page( 'wc-general-settings', '會員分級', '會員分級', 'manage_woocommerce', 'twshop-member-tiers', 'twshop_member_tiers_render_page' );
    }
    if ( twshop_module_enabled( 'discount_rules' ) ) {
        add_submenu_page( 'wc-general-settings', '折扣規則', '折扣規則', 'manage_woocommerce', 'twshop-discount-rules', 'twshop_discount_rules_render_page' );
    }
    // 優惠卡券（v25.8.71 起不再是獨立頂層選單）移到「系統設定 ▸ 優惠卡券」頁籤，
    // 比照蝦皮串接搬遷的既有先例，見 twshop_system_render_page()（pages.php）。
    if ( twshop_module_enabled( 'points' ) ) {
        add_submenu_page( 'wc-general-settings', '紅利點數', '紅利點數', 'manage_woocommerce', 'twshop-points', 'twshop_points_render_page' );
    }
    // 蝦皮串接（v25.8.65 起不再是獨立頂層選單／不再受模組開關影響）移到「系統設定 ▸
    // 蝦皮串接」頁籤，見 twshop_system_render_page()（pages.php）與 CLAUDE.md「蝦皮串接
    // 模組」一節。
    if ( twshop_module_enabled( 'wallet' ) ) {
        // 選單顯示名稱 v25.8.71 改為「儲值中心」（湊足四字，跟其餘頂層選單一致）；
        // slug／函式名／option key／功能本身的既有用詞「儲值金」不變，只換外殼。
        add_submenu_page( 'wc-general-settings', '儲值中心', '儲值中心', 'manage_woocommerce', 'twshop-wallet', 'twshop_wallet_render_page' );
    }
    add_submenu_page( 'wc-general-settings', '系統設定', '系統設定', 'manage_woocommerce', 'twshop-system', 'twshop_system_render_page' );
}

