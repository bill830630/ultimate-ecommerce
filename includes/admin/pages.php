<?php
/**
 * 各後台選單頁的 render callback（頁面容器）
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 終極電商唯一的後台選單入口（v25.8.81 選單收攏新增，取代原本 6 個獨立子選單頁面）。
 * 依 twshop_get_admin_sections()／twshop_get_current_admin_section() 決定目前顯示哪個
 * 功能區塊，最外層只包一次 twshop_render_admin_page()（權限檢查、`.wrap`、`<h1>終極電商`
 * 都只在這裡發生一次），區塊切換用最外層的 section 導覽列，各功能內部原本就有的 tab／
 * 蝦皮 subtab 機制完全不受影響。
 *
 * 模組關閉時的存取控制：twshop_get_admin_sections() 依模組開關條件式組出 $sections，
 * 停用的模組根本不會出現在陣列 key 裡；twshop_get_current_admin_section() 找不到對應
 * key 時一律退回第一個 key（恆為 'dashboard'）。全站只有這裡一處用 $current 去
 * call_user_func()，沒有第二條路徑能繞過這個查找，等同於「模組關閉的功能永遠不會被
 * dispatch 到」，取代舊版「該 slug 從未 add_submenu_page()，WordPress 核心直接擋下」
 * 的副作用式防線。
 *
 * v25.8.84 起版面改成左側垂直選單（twshop_render_admin_sidebar_nav()）＋右側內容的
 * 兩欄版面（`.twshop-admin-layout`／`.twshop-admin-content`，assets/css/twshop-admin.css），
 * 取代前兩版疊在一起的雙層頁籤／分段藥丸做法，見該函式與 CLAUDE.md 對應章節。
 *
 * v25.8.85 起內容區最上方多印一個 `<h2>` 顯示目前功能名稱（例如「紅利點數」）——改版前
 * 不管切到哪個功能，畫面上唯一的標題永遠是最外層固定的「終極電商」，使用者切換側邊選單
 * 後不容易確認自己現在在哪一頁；直接用 $sections[$current]['label']，跟側邊選單顯示的
 * 名稱保證一致，不需要另外維護一份標題文字。
 */
function twshop_admin_render_page() {
    $sections = twshop_get_admin_sections();
    $current  = twshop_get_current_admin_section( $sections );

    twshop_render_admin_page( '終極電商', function () use ( $sections, $current ) {
        echo '<div class="twshop-admin-layout">';
        twshop_render_admin_sidebar_nav( $sections, $current );
        echo '<div class="twshop-admin-content">';
        echo '<h2 class="twshop-admin-section-title">' . esc_html( $sections[ $current ]['label'] ) . '</h2>';
        call_user_func( $sections[ $current ]['render'] );
        echo '</div>';
        echo '</div>';
    } );
}

/**
 * 紅利點數：對應 points 模組。v25.8.25 起改成 5 個真正的頁籤（比照下方
 * twshop_system_section() 的既有模式），不再是單一頁面/單一 <form>——
 * 內容函式拆分與 settings group 拆分見 includes/admin/page-points.php／settings.php。
 * v25.8.81 起改名為 twshop_points_section()：不再自己呼叫 twshop_render_admin_page()
 * 包外框（外框只在最外層的 twshop_admin_render_page() 呼叫一次），組內部頁籤網址時
 * 額外帶 section=points，避免點頁籤連結時弄丟「目前在紅利點數這個功能區塊」的資訊。
 */
function twshop_points_section() {
    $tabs = array(
        'balances' => '會員餘額',
        'rules'    => '點數規則設定',
        'texts'    => '點數提示文字',
        'award'    => '發放與退還時機',
        'redeem'   => '點數兌換商品',
        'import'   => '匯入點數資料',
    );
    $current = twshop_get_current_admin_tab( $tabs );
    twshop_render_admin_tabs( $tabs, $current, 'wc-general-settings', array( 'section' => 'points' ) );
    if ( 'balances' === $current ) twshop_points_balances_tab();
    elseif ( 'rules' === $current ) twshop_points_rules_tab();
    elseif ( 'texts' === $current ) twshop_points_texts_tab();
    elseif ( 'award' === $current ) twshop_points_award_tab();
    elseif ( 'redeem' === $current ) twshop_points_redeem_tab();
    elseif ( 'import' === $current ) twshop_points_import_tab();
}

/**
 * 系統：一般／模組開關／頁籤管理／優惠卡券／蝦皮串接，皆為不屬於任何單一功能模組的核心
 * 系統設定（優惠卡券頁籤例外，見下方說明），不受任何模組開關影響，永遠顯示。
 * v25.5.83：原本獨立的「物流」頁籤（物流貨態自動完成訂單／訂單物流資訊 metabox 開關）
 * 已併入「模組開關」頁籤的 order_checkout_enhancements 單一模組開關，頁籤整個移除，
 * 比照 v25.5.69 移除「通知」頁籤的既有先例。
 * v25.5.84：後台選單改成跟模組清單對齊（見 twshop_register_menus()），原本「會員」
 * 選單底下的「頁籤管理」（帳戶頁籤排序/開關/命名，不屬於任何模組，是核心會員中心功能）
 * 沒有自己的模組可以搬過去，改併入「系統」選單新增頁籤，跟「一般／模組開關」放在一起，
 * 內容函式 twshop_member_tabs_tab() 沿用改版前的既有名稱，未跟著搬遷改名。
 * v25.8.81 起改名為 twshop_system_section()：不再自己呼叫 twshop_render_admin_page()
 * 包外框，組內部頁籤網址時額外帶 section=system。
 */
function twshop_system_section() {
    // 「模組開關」僅限 Administrator（manage_options），非管理員的頁籤清單裡不放這個 key，
    // ?tab=modules 會被 twshop_get_current_admin_tab() 判定為不存在的頁籤、退回第一個
    // 分頁，不會執行到 twshop_system_modules_tab()（該函式自己也有一道 manage_options
    // 檢查，這裡是分頁層級的第二道防線）。
    $tabs = array( 'general' => '一般設定' );
    if ( current_user_can( 'manage_options' ) ) {
        $tabs['modules'] = '模組開關';
    }
    $tabs['tabs'] = '頁籤管理';
    // 優惠卡券（v25.8.71 起從獨立頂層選單搬進來，比照蝦皮串接搬遷的既有先例）：
    // 內容函式 twshop_marketing_coupons_tab() 本身刻意不受模組開關影響、永遠顯示
    // ——優惠券措辭設定不只給視覺化優惠券用，也用在折扣規則「排他性優惠券」的
    // 錯誤提示文字，即使 visual_coupons 模組關閉仍需要能編輯，這裡的頁籤顯示條件
    // 因此也不受模組開關限制。
    $tabs['coupons'] = '優惠卡券';
    // 蝦皮串接（v25.8.65 起從獨立頂層選單搬進來，見 page-shopee.php 檔頭說明）：
    // 跟「一般」「頁籤管理」一樣不受模組開關限制、manage_woocommerce 即可看到，
    // 只是內容本身多包一層自己的子頁籤（`subtab` 參數，見 twshop_shopee_settings_tab()）。
    $tabs['shopee'] = '蝦皮串接';
    $current = twshop_get_current_admin_tab( $tabs );
    twshop_render_admin_tabs( $tabs, $current, 'wc-general-settings', array( 'section' => 'system' ) );
    if ( 'general' === $current ) twshop_system_general_tab();
    elseif ( 'modules' === $current ) twshop_system_modules_tab();
    elseif ( 'tabs' === $current ) twshop_member_tabs_tab();
    elseif ( 'coupons' === $current ) twshop_marketing_coupons_tab();
    elseif ( 'shopee' === $current ) twshop_shopee_settings_tab();
}

