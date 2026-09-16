<?php
/**
 * 各後台選單頁的 render callback（頁面容器）
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 行銷：折扣規則／點數／優惠券文案。折扣規則、點數兩個頁籤依對應模組開關決定是否顯示，
 * 優惠券文案頁籤不受模組開關影響（措辭設定不僅供視覺化優惠券使用，見 CLAUDE.md）。
 */
/**
 * 會員分級：單一頁面，對應 member_tiers 模組（模組停用時整個選單項目不註冊，見
 * twshop_register_menus()，故這裡不需要再判斷模組開關）。內容函式
 * twshop_member_tiers_tab() 沿用改版前的既有名稱，未跟著搬遷改名。
 */
function twshop_member_tiers_render_page() {
    twshop_render_single_tab_page( '會員分級', 'twshop_member_tiers_tab' );
}

/**
 * 折扣規則：單一頁面，對應 discount_rules 模組（頁面顯示名稱 v25.8.14 由「折扣贈品」
 * 改名，模組 slug／內容函式沿用原名未變）。內容函式 twshop_marketing_rules_tab()
 * 沿用改版前的既有名稱，未跟著搬遷改名。
 */
function twshop_discount_rules_render_page() {
    twshop_render_single_tab_page( '折扣規則', 'twshop_marketing_rules_tab' );
}

/**
 * 紅利點數：對應 points 模組。v25.8.25 起改成 5 個真正的頁籤（比照下方
 * twshop_system_render_page() 的既有模式），不再是單一頁面/單一 <form>——
 * 內容函式拆分與 settings group 拆分見 includes/admin/page-points.php／settings.php。
 */
function twshop_points_render_page() {
    twshop_render_admin_page( '紅利點數', function () {
        $tabs = array(
            'balances' => '會員餘額',
            'rules'    => '點數規則設定',
            'texts'    => '點數提示文字',
            'award'    => '發放與退還時機',
            'redeem'   => '點數兌換商品',
            'import'   => '匯入點數資料',
        );
        $current = twshop_get_current_admin_tab( $tabs );
        twshop_render_admin_tabs( $tabs, $current, 'twshop-points' );
        if ( 'balances' === $current ) twshop_points_balances_tab();
        elseif ( 'rules' === $current ) twshop_points_rules_tab();
        elseif ( 'texts' === $current ) twshop_points_texts_tab();
        elseif ( 'award' === $current ) twshop_points_award_tab();
        elseif ( 'redeem' === $current ) twshop_points_redeem_tab();
        elseif ( 'import' === $current ) twshop_points_import_tab();
    } );
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
 */
function twshop_system_render_page() {
    // $tabs/$current 的計算刻意寫在 closure 內：twshop_render_admin_page() 的權限檢查
    // 要先跑，才輪得到讀 $_GET 決定頁籤。
    twshop_render_admin_page( '系統設定', function () {
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
        twshop_render_admin_tabs( $tabs, $current, 'twshop-system' );
        if ( 'general' === $current ) twshop_system_general_tab();
        elseif ( 'modules' === $current ) twshop_system_modules_tab();
        elseif ( 'tabs' === $current ) twshop_member_tabs_tab();
        elseif ( 'coupons' === $current ) twshop_marketing_coupons_tab();
        elseif ( 'shopee' === $current ) twshop_shopee_settings_tab();
    } );
}

