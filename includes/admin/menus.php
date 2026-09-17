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

// 終極電商與終極登入（ultimate-login）v25.8.80 起共用一個「快捷鍵」頂層選單。
// 兩邊互相 fallback：誰的 admin_menu 先跑、誰的既有 slug 就當父選單，另一邊偵測到
// 父選單已存在就把自己的頁面掛成子選單。不寫死載入順序，任一外掛單獨啟用時「快捷鍵」
// 父選單依然正常顯示，見 CLAUDE.md「快捷鍵父選單合併」一節。
function twshop_shortcut_parent_slug() {
    global $admin_page_hooks;
    if ( isset( $admin_page_hooks['wclon-settings'] ) ) {
        return 'wclon-settings';
    }
    return 'wc-general-settings';
}

/**
 * 終極電商唯一後台頁面（wc-general-settings）的 hook suffix（v25.8.81 選單收攏新增），
 * procedural 版本的 ultimate-login WCLON_Settings::$page_hook——供
 * twshop_admin_external_scripts()（ui-components.php）精準比對用，取代 v25.5.84～v25.8.80
 * 那套「明確 slug 清單逐一 str_contains 比對」寫法。這個值本身是動態的（「快捷鍵」父選單
 * owner/attach 兩種情境下 hook suffix 格式不同，見 twshop_shortcut_parent_slug()），一律
 * 從 add_submenu_page() 的實際回傳值取得，不寫死字串。$hook 傳 null（預設）當 getter，
 * 傳實際值當 setter，只在 twshop_register_menus() 內呼叫一次；admin_menu 一定早於
 * admin_enqueue_scripts 執行，讀取時保證已經 set 過。
 */
function twshop_admin_page_hook( $hook = null ) {
    static $stored = '';
    if ( null !== $hook ) {
        $stored = $hook;
    }
    return $stored;
}

function twshop_register_menus() {
    $parent_slug = twshop_shortcut_parent_slug();
    $is_owner    = ( 'wc-general-settings' === $parent_slug );

    if ( $is_owner ) {
        add_menu_page( '快捷鍵', '快捷鍵', 'manage_woocommerce', $parent_slug, 'twshop_admin_render_page', 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyBmaWxsPSJ3aGl0ZSIgaWQ9Il/lnJblsaRfMiIgZGF0YS1uYW1lPSLlnJblsaQgMiIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB2aWV3Qm94PSIwIDAgNDEzLjExIDQxMy4xMSI+CiAgPGcgaWQ9Il/lnJblsaRfMS0yIiBkYXRhLW5hbWU9IuWcluWxpCAxIj4KICAgIDxnPgogICAgICA8cGF0aCBkPSJNMjA2LjU1LDBDMTM5LjI1LDAsNzkuNDcsMzIuMiw0MS43Niw4Mi4wMmw4MC40Niw4MC40Niw0NC4wNy00NC4wN2MxMy42NC0xMy42NCwyOS42NS0yMy40NCw0Ni43LTI5LjQ0LDEwLjIzLTMuNTksMjAuODMtNS44MiwzMS41NC02LjY3LDM1LjExLTIuNzksNzEuMTksOS4yNSw5OC4wNCwzNi4xMSw0OC42OSw0OC42OCw0OC42OCwxMjcuNjIsMCwxNzYuMy0yNi44NSwyNi44Ny02Mi45NCwzOC45MS05OC4wNSwzNi4xMS0xMC42Mi0uODQtMjEuMTYtMy4wMy0zMS4zMy02LjU4LTE3LjE0LTUuOTktMzMuMjItMTUuODQtNDYuOTEtMjkuNTMtLjA0LS4wMy0uMDYtLjA3LS4xLS4xMmwtNDMuOTYtNDMuOTYtODAuNDYsODAuNDZjMzcuNzEsNDkuODIsOTcuNDksODIuMDIsMTY0Ljc5LDgyLjAyLDExNC4wOCwwLDIwNi41NS05Mi40OCwyMDYuNTUtMjA2LjU1UzMyMC42MywwLDIwNi41NSwwWiIvPgogICAgICA8cGF0aCBkPSJNMTEuMTMsMTM5LjUzQzMuOTIsMTYwLjU1LDAsMTgzLjA5LDAsMjA2LjU1czMuOTIsNDYuMDEsMTEuMTMsNjcuMDJsNjcuMDItNjcuMDJMMTEuMTMsMTM5LjUzWiIvPgogICAgICA8cGF0aCBkPSJNMjQ0LjUzLDI2OC4wOGMxOS4wNywzLjA3LDM5LjI5LTIuNzUsNTMuOTktMTcuNDYsMjQuMzMtMjQuMzMsMjQuMzItNjMuOC0uMDEtODguMTQtMTQuNy0xNC43LTM0LjkxLTIwLjUxLTUzLjk3LTE3LjQ1LTExLjM3LDEuODEtMjIuMzMsNi43Ny0zMS40NiwxNC45Mi0uOTMuODEtMS44MywxLjY2LTIuNzEsMi41NGwtNDQuMDYsNDQuMDcsNDQuMDcsNDQuMDdjLjkuOSwxLjgzLDEuNzcsMi43NywyLjYxLDkuMTQsOC4wOCwyMC4wNiwxMy4wNCwzMS4zOSwxNC44NFoiLz4KICAgIDwvZz4KICA8L2c+Cjwvc3ZnPg==', 56 );
        remove_submenu_page( $parent_slug, $parent_slug );
    }

    // 6 個獨立子選單（v25.8.80 前）收成 1 個（v25.8.81 起）：slug 仍是 wc-general-settings
    // （owner 情境下第一筆 slug 需等於 parent slug 的既有規則不變，只是現在也是唯一一筆）；
    // 標題「儀表板」改「終極電商」，跟同一父選單下的「終極登入」命名對稱。6 個功能收成頁面
    // 內的 section 頁籤，見 twshop_admin_render_page()（pages.php）與 CLAUDE.md
    // 「後台選單收攏成單一入口」一節，模組開關的判斷也搬到那裡（twshop_get_admin_sections()），
    // 不再是這裡的 add_submenu_page() 條件式註冊。
    $hook = add_submenu_page( $parent_slug, '終極電商', '終極電商', 'manage_woocommerce', 'wc-general-settings', 'twshop_admin_render_page' );
    twshop_admin_page_hook( $hook );
}

