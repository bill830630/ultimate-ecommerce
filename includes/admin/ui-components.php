<?php
/**
 * 2. 後台設定選單與註冊：共用 UI 元件與 sanitize helper
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 2. 後台設定選單與註冊
// =========================================================================

/**
 * v25.5.84：後台選單改成每個模組一個頂層選單項目後，這裡改用明確的 slug 清單逐一比對，
 * 不再依賴「twshop-member」剛好是「twshop-member-tiers」子字串這種巧合式的 str_contains 命中。
 * v25.5.86：短代碼說明頁（原本是唯一不需要 sortable/flatpickr 的頁面，CSS/腳本清單因此得分兩份）
 * 移除後，兩份清單完全相同，合併成一份。
 * v25.8.81：後台選單收攏成單一入口（見 twshop_admin_render_page()），6 個 slug 收成 1 個，
 * 改成跟 twshop_admin_page_hook() 記錄的唯一 hook suffix 精準比對，不用清單/str_contains 了
 * ——這個 hook 值本身是動態的（「快捷鍵」父選單 owner/attach 兩種情境下 hook suffix 格式不同，
 * 見 twshop_register_menus()），寫死字串會在其中一種情境下靜默失效，比照 ultimate-login 的
 * WCLON_Settings::$page_hook 既有模式，一律讀 add_submenu_page() 的實際回傳值。
 */
function twshop_admin_external_scripts($hook) {
    $on_page = ( '' !== twshop_admin_page_hook() && $hook === twshop_admin_page_hook() );

    // WordPress 原生控制台（wp-admin/index.php）不是本外掛的頁面，但控制台 widget
    // （twshop_render_dashboard_widget()）在那裡渲染，.twshop-widget__* 的樣式原本是
    // 跟著 widget 內容一起內嵌輸出的。樣式改成集中在 twshop-admin.css 之後，這裡要
    // 一併載入，否則 widget 會變成沒有樣式的裸 HTML——不會有任何錯誤訊息。
    // 條件跟 widget 本身的註冊條件一致（見 twshop_register_dashboard_widget()）：
    // 沒有 manage_woocommerce 權限的使用者根本看不到 widget，不需要載入這支 CSS。
    $on_wp_dashboard = ( 'index.php' === $hook && current_user_can( 'manage_woocommerce' ) );

    if ( $on_page || $on_wp_dashboard ) {
        // selectWoo 的外觀樣式編譯在 WooCommerce admin.css；本頁不在 WC 預設的
        // screen 清單中，需主動載入。控制台 widget 則不需要整份 WC 樣式。
        if ( $on_page ) {
            wp_enqueue_style( 'woocommerce_admin_styles' );
        }
        wp_enqueue_style( 'twshop-admin', TWSHOP_PLUGIN_URL . 'assets/css/twshop-admin.css', $on_page ? array( 'woocommerce_admin_styles' ) : array(), filemtime( TWSHOP_PLUGIN_DIR . 'assets/css/twshop-admin.css' ) );
    }

    if ( $on_page ) {
        wp_enqueue_script( 'jquery-ui-sortable' );
        // AJAX 商品搜尋（wc-product-search class + selectWoo）：WooCommerce 核心已經在
        // admin_init 註冊/localize 過這支腳本（含 ajax_url、search-products nonce），這裡
        // 只需要 enqueue，不用自己重新註冊，見 twshop_render_product_search_field()。
        wp_enqueue_script( 'wc-enhanced-select' );
        // flatpickr 改從外掛自帶的 assets/vendor/flatpickr/ 本地載入（版本鎖 4.6.13），
        // 不再依賴 cdn.jsdelivr.net——商業外掛不應帶第三方 CDN 相依，且原本 CSS 的 CDN URL
        // 還沒鎖版本（JS 已鎖 4.6.13），改本地後 CSS/JS 版本一併固定。
        wp_enqueue_style( 'flatpickr-style', TWSHOP_PLUGIN_URL . 'assets/vendor/flatpickr/flatpickr.min.css', array(), '4.6.13' );
        wp_enqueue_script( 'flatpickr-script', TWSHOP_PLUGIN_URL . 'assets/vendor/flatpickr/flatpickr.min.js', array('jquery'), '4.6.13', true );
    }
}

/**
 * `.twshop-panel` 的標題列：`<h2>` ＋（可選）動作按鈕與說明文字。
 * $icon 保留於函式簽名以相容既有呼叫，但設定頁不再顯示裝飾性圖示。
 * 十九個面板原本各自把這段 markup 抄一遍，順序（圖示→標題→按鈕→說明）也全靠人記。
 *
 * $hint 是**已經組好的 HTML**，不會再被跳脫——現有的說明文字裡本來就有 `<code>` 標籤，
 * 整段 esc_html() 會把標籤印成字面文字。呼叫端全是寫死的開發者字串；要帶入變數
 * （例如點數名稱）請在呼叫端自己 esc_html() 之後再串進去，不要把未跳脫的變數丟進來。
 *
 * $button 則刻意收成結構化陣列而非 HTML 字串（url/label/class/confirm），
 * 由這裡負責 esc_url()/esc_html()/esc_attr()，呼叫端沒有寫錯跳脫的機會。
 */
function twshop_panel_head( $icon, $title, $hint = '', array $button = array() ) {
    echo '<div class="twshop-panel-head">';
    echo '<h2>' . esc_html( $title ) . '</h2>';

    if ( ! empty( $button['url'] ) && ! empty( $button['label'] ) ) {
        // esc_js() 而非 esc_attr( wp_json_encode() )：後者會把 JS 字串的引號變成 &quot;，
        // 雖然瀏覽器照樣跑得動，但輸出位元組跟原本不同，會在渲染比對時多出一筆假差異。
        $onclick = empty( $button['confirm'] )
            ? ''
            : ' onclick="return confirm(\'' . esc_js( $button['confirm'] ) . '\');"';
        printf(
            '<a href="%s" class="%s"%s>%s</a>',
            esc_url( $button['url'] ),
            esc_attr( $button['class'] ?? 'button' ),
            $onclick,
            esc_html( $button['label'] )
        );
    }

    if ( '' !== $hint ) {
        echo '<p class="twshop-panel-hint">' . $hint . '</p>';
    }

    echo '</div>';
}

/**
 * 後台頁面的共用外框：權限檢查、`.wrap` 容器、標題列，內容交給 $content 輸出。
 *
 * v25.8.79 前，六個後台頁面（儀表板、會員分級、折扣規則、優惠卡券、紅利點數、系統設定）
 * 各自呼叫一次這支函式；v25.8.81 起後台選單收攏成單一入口，全站只剩
 * twshop_admin_render_page()（pages.php）這**唯一一處**呼叫它，六個功能收成最外層的
 * section 頁籤，共用同一個 `<h1>終極電商</h1>`，不再各自有自己的標題。
 *
 * $intro 只有儀表板用得到：有簡介文字時標題與簡介要包在同一個 <div> 內，
 * 才不會被 .twshop-dash-header 的 space-between 拆到左右兩端。
 *
 * 分支用的 if/else/endif 刻意頂到第 0 欄：`?>` 只吃掉緊接的換行，標籤前的縮排空白
 * 會照樣印進 HTML，寫在第 0 欄輸出才會跟拆共用函式之前逐位元相同。
 *
 * 注意：權限檢查必須留在這裡的最前面，呼叫端不要在算自己的資料時搶先跑——
 * 原本六頁都是「先擋權限，才做事」，把準備工作寫在 twshop_render_admin_page() 之外
 * 會讓無權限者也跑到那段程式碼。內容有前置計算的頁面請寫在 $content 裡面。
 */
function twshop_render_admin_page( $title, callable $content, $intro = '' ) {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足。' );
    ?>
    <div class="wrap twshop-admin-wrap">
        <div class="twshop-dash-header">
<?php if ( '' !== $intro ) : ?>
            <div>
                <h1><?php echo esc_html( $title ); ?></h1>
                <p class="twshop-admin-intro"><?php echo esc_html( $intro ); ?></p>
            </div>
<?php else : ?>
            <h1><?php echo esc_html( $title ); ?></h1>
<?php endif; ?>
        </div>
        <?php $content(); ?>
    </div>
    <?php
}

/**
 * 各功能區塊「內部」的子頁籤導覽（例如紅利點數的 6 個頁籤），沿用 WordPress 核心
 * `<ul class="subsubsub">` 樣式——WooCommerce 自己的設定頁對這一層（例如「運送方式」
 * 頁籤內的「運送區域｜送貨設定｜類別」）用的就是這個元件，不是 nav-tab-wrapper。
 * 純文字＋豎線分隔，跟最外層「功能」導覽（`twshop_render_admin_section_tabs()`，
 * nav-tab-wrapper）在視覺上刻意區分層級；豎線由共用樣式產生，方便窄螢幕隱藏。
 * （v25.8.88 改版，取代 v25.8.81~87 這層也用 nav-tab-wrapper、跟最外層形狀相同而分不清
 * 層級的舊做法，詳見 CLAUDE.md）。
 * $tabs 格式 slug => 標籤；只有 1 個頁籤時不輸出導覽列（沒有切換的必要）。
 *
 * $extra_args（v25.8.81 新增，預設空陣列，向後相容）：後台選單收攏成單一入口後，紅利點數／
 * 儲值中心／系統設定三個功能區塊都掛在同一個 $page_slug（wc-general-settings）底下，光靠
 * page+tab 兩個參數組出的網址會弄丟「目前在哪個功能區塊（section）」這件事，點頁籤連結會
 * 跳回預設的儀表板。呼叫端各自補 array('section'=>'points') 之類的參數即可正確保留。
 */
function twshop_render_admin_tabs( array $tabs, $current, $page_slug, array $extra_args = array() ) {
    if ( count( $tabs ) < 2 ) return;
    echo '<nav class="twshop-subtabs" aria-label="設定分類"><ul class="subsubsub">';
    foreach ( $tabs as $slug => $label ) {
        $url = admin_url( 'admin.php?page=' . $page_slug . '&tab=' . $slug );
        if ( $extra_args ) {
            $url = add_query_arg( $extra_args, $url );
        }
        $class = ( $slug === $current ) ? ' class="current" aria-current="page"' : '';
        echo '<li><a href="' . esc_url( $url ) . '"' . $class . '>' . esc_html( $label ) . '</a></li>';
    }
    echo '</ul></nav>';
}

/**
 * 依 $_GET['tab'] 決定目前頁籤，未指定或指定到不存在/已停用模組對應的頁籤時，
 * 安全回退到 $tabs 的第一個項目，不會顯示空白頁或報錯。呼叫端須確保 $tabs 不為空陣列。
 */
function twshop_get_current_admin_tab( array $tabs ) {
    $requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
    if ( $requested && isset( $tabs[ $requested ] ) ) return $requested;
    $keys = array_keys( $tabs );
    return $keys[0];
}

/**
 * 終極電商唯一後台入口頁面（v25.8.81 選單收攏新增）的頂層「功能」清單，取代原本 6 個獨立
 * 子選單頁面。'dashboard' 必須是第一個 key——twshop_get_current_admin_section() 在請求的
 * section 不存在（含對應模組已停用）時會退回 array_keys()[0]，這是「模組關閉時內容不會被
 * dispatch 到」這條防線的基礎，順序不能亂動。'render' 直接指向各功能既有的內容函式。
 */
function twshop_get_admin_sections() {
    $sections = array(
        'dashboard' => array( 'label' => '儀表板', 'render' => 'twshop_dashboard_section' ),
    );
    if ( twshop_module_enabled( 'member_tiers' ) ) {
        $sections['member-tiers'] = array( 'label' => '會員分級', 'render' => 'twshop_member_tiers_tab' );
    }
    if ( twshop_module_enabled( 'discount_rules' ) ) {
        $sections['discount-rules'] = array( 'label' => '折扣規則', 'render' => 'twshop_marketing_rules_tab' );
    }
    if ( twshop_module_enabled( 'points' ) ) {
        $sections['points'] = array( 'label' => '紅利點數', 'render' => 'twshop_points_section' );
    }
    if ( twshop_module_enabled( 'wallet' ) ) {
        $sections['wallet'] = array( 'label' => '儲值中心', 'render' => 'twshop_wallet_section' );
    }
    $sections['system'] = array( 'label' => '系統設定', 'render' => 'twshop_system_section' );
    return $sections;
}

/**
 * 依 $_GET['section'] 決定目前顯示哪個功能區塊，找不到或對應的模組已停用（因此根本
 * 沒出現在 $sections 裡）時安全退回第一個項目（恆為 'dashboard'，見上方函式的順序說明）。
 * 跟 twshop_get_current_admin_tab() 是同一套退回慣例，刻意獨立成一支函式而非幫舊函式加
 * 參數——這層讀寫的是獨立的 section 參數，跟各功能區塊自己的 tab（甚至蝦皮串接自己的
 * subtab）語意上是不同層級，分開有明確的函式名比較不會混淆。
 */
function twshop_get_current_admin_section( array $sections ) {
    $requested = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
    if ( $requested && isset( $sections[ $requested ] ) ) return $requested;
    $keys = array_keys( $sections );
    return $keys[0];
}

/**
 * 最外層「功能」導覽（v25.8.88 改版：改回 nav-tab-wrapper，取代 v25.8.84~86 那套左側
 * 垂直選單＋手機版下拉選單的自訂元件）。
 *
 * 改版理由：使用者拿 WooCommerce 自己的設定頁（設定 ▸ 運送方式）當參考，指出 WooCommerce
 * 對這種「大分類 + 分類內子項目」的兩層導覽，兩層各用一種不同的 wp-admin 原生元件——
 * 最外層（一般／商品／運送方式…）是 nav-tab-wrapper，分類內子項目（運送區域｜送貨設定｜
 * 類別）是 subsubsub——而不是把其中一層改造成自訂元件去跟另一層拉開視覺差異。
 * v25.8.81 剛把選單收攏成單一入口時，兩層都用 nav-tab-wrapper，確實會讓人分不清層級
 * （這是真的問題），但 v25.8.83～86 一路把最外層換成分段藥丸、再換成左側垂直選單，
 * 解法的方向是錯的：不是「這層要長得多獨特」，而是「這兩層本來就該對應到 wp-admin 既有
 * 的兩種不同元件」。現在的正確分工：這一層維持 nav-tab-wrapper，各功能自己的子頁籤
 * （`twshop_render_admin_tabs()`）改成 subsubsub，兩者形狀天生不同，也天生跟其他所有
 * wp-admin 頁面一致——不需要為了「不要疊在一起」而發明新元件。
 *
 * 跟 `twshop_render_admin_tabs()` 的差異：固定用 section 參數（避免跟內層 tab/subtab
 * 撞名），頁面本身只有一個 slug，不需要 $page_slug 參數，改呼叫 twshop_admin_url() 組
 * 網址。只有 1 個功能時不輸出導覽（沿用既有慣例防呆，實務上不會發生）。
 */
function twshop_render_admin_section_tabs( array $sections, $current ) {
    if ( count( $sections ) < 2 ) return;
    echo '<nav class="nav-tab-wrapper" aria-label="終極電商功能">';
    foreach ( $sections as $slug => $info ) {
        $url   = twshop_admin_url( $slug );
        $class = 'nav-tab' . ( $slug === $current ? ' nav-tab-active' : '' );
        $aria_current = ( $slug === $current ) ? ' aria-current="page"' : '';
        echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '"' . $aria_current . '>' . esc_html( $info['label'] ) . '</a>';
    }
    echo '</nav>';
}

/**
 * 終極電商唯一後台頁面（wc-general-settings）網址組裝的唯一入口（v25.8.81 選單收攏新增），
 * 取代 13 處分散手寫的 admin_url('admin.php?page=twshop-xxx...') 字串。$section 對應
 * twshop_get_admin_sections() 的 key，留空只回到最外層網址（落在預設的儀表板 section）。
 * $args 可再帶 tab/subtab/user_id 等參數，直接透傳給 add_query_arg()。
 */
function twshop_admin_url( $section = '', array $args = array() ) {
    $query = $args;
    if ( '' !== $section ) {
        $query = array_merge( array( 'section' => $section ), $args );
    }
    $url = admin_url( 'admin.php?page=wc-general-settings' );
    return $query ? add_query_arg( $query, $url ) : $url;
}

// 「下拉選單挑選、已選項目以方塊呈現」欄位的共用資源（JS + CSS），供折扣系統／點數系統等後台頁面共用；
// 同一頁只需輸出一次，重複呼叫會自動略過。
function twshop_render_chip_field_assets() {
    static $printed = false;
    if ( $printed ) return;
    $printed = true;
    ?>
    <?php twshop_enqueue_asset_script( 'admin/chip-field' ); ?>
    <?php
}

/**
 * 商品分類／標籤複選欄位；$options 為 value => label 的清單（slug => 名稱），$name 為表單
 * 欄位名稱（實際 <select multiple name="{$name}[]">）。
 *
 * 跟「選擇商品」欄位（twshop_render_product_search_field()）用同一套 selectWoo 多選元件，
 * 操作方式與外觀一致；差別只在這裡不用 AJAX（wc-product-search 那套是因為商品可能上千筆，
 * 一次全撈不現實），分類/標籤數量有限，選項直接全部渲染成 <option>，selectWoo 對既有選項
 * 做本地過濾即可，不需要遠端搜尋。
 *
 * 舊版是「下拉挑選＋已選項目另外顯示成方塊」的兩截式陽春元件（.twshop-chip-picker／
 * .twshop-chip-box／.twshop-chip-source 三個元素），2026-09 改成這支之後已整個移除，
 * 對應的 chip-field.js 渲染邏輯也一併拿掉（見該檔）。「點數兌換商品」清單另外沿用
 * .twshop-chip／.twshop-chip-box 的純視覺樣式（不經過這支函式，見 redeemable-products.js），
 * 不受影響。
 */
function twshop_render_chip_field( $name, $selected_values, $options ) {
    $selected_values = array_map( 'strval', (array) $selected_values );
    ob_start();
    ?>
    <select
        name="<?php echo esc_attr( $name ); ?>[]"
        multiple="multiple"
        class="twshop-chip-field wc-enhanced-select"
        style="width:100%;"
        data-placeholder="+ 點選加入項目…"
    >
        <?php foreach ( $options as $value => $label ) : ?>
            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( in_array( (string) $value, $selected_values, true ) ); ?>><?php echo esc_html( $label ); ?></option>
        <?php endforeach; ?>
    </select>
    <?php
    return ob_get_clean();
}

/**
 * AJAX 商品搜尋欄位：重用 WooCommerce 核心的 wc-product-search（selectWoo +
 * woocommerce_json_search_products AJAX action），取代「一次性撈最多 200 筆商品塞進
 * <select>」的陽春下拉——商品多的店找不到、超過 200 筆的商品選不到，這裡搜尋不受限。
 * 只需要把「目前已選的商品」解析成 <option selected>，其餘商品由使用者輸入時即時搜尋，
 * 不需要預先把全站商品塞進頁面。核心端的 AJAX handler、nonce（action 固定是
 * `search-products`）、`wc_enhanced_select_params` 的 localize 都已經在 admin_init
 * 階段跑過，這裡不需要重新註冊或 wp_localize_script，只要頁面上有 enqueue
 * `wc-enhanced-select`（見 twshop_admin_external_scripts()）就能用。
 *
 * @param string $name           <select> 的 name 屬性（多選時自動補 []）
 * @param array  $selected_ids   目前已選的商品 ID
 * @param bool   $multiple       是否為多選
 * @param string $placeholder    搜尋框 placeholder
 * @param array  $exclude_types  要從搜尋結果排除的商品類型（例如 array('variable')）。
 *                                用途：呼叫端會直接把選到的商品 ID 拿去
 *                                WC()->cart->add_to_cart( $id, 1, 0, ... )（variation_id 固定
 *                                傳 0），可變商品（父商品）沒有指定規格時核心會丟例外、
 *                                add_to_cart() 回傳 false——排除可變商品讓管理員從源頭就選不到，
 *                                不要等前台顧客實際操作才發現失敗。只有「限制條件」這類純粹拿
 *                                商品 ID 做比對、不會直接加入購物車的欄位不需要排除，見呼叫端。
 */
function twshop_render_product_search_field( $name, $selected_ids, $multiple = false, $placeholder = '搜尋商品名稱或商品編號…', $exclude_types = array() ) {
    $selected_ids = array_filter( array_map( 'absint', (array) $selected_ids ) );
    $exclude_types = array_filter( array_map( 'sanitize_key', (array) $exclude_types ) );
    ob_start();
    ?>
    <select
        name="<?php echo esc_attr( $name . ( $multiple ? '[]' : '' ) ); ?>"
        class="wc-product-search"
        style="width:100%;"
        data-action="woocommerce_json_search_products"
        data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
        data-allow_clear="true"
        <?php if ( ! empty( $exclude_types ) ) : ?>
        data-exclude_type="<?php echo esc_attr( implode( ',', $exclude_types ) ); ?>"
        <?php endif; ?>
        <?php echo $multiple ? 'multiple="multiple"' : ''; ?>
    >
        <?php if ( ! $multiple ) : ?>
            <option value="">— 請選擇商品 —</option>
        <?php endif; ?>
        <?php foreach ( $selected_ids as $pid ) :
            $product = wc_get_product( $pid );
            if ( ! $product ) continue;
        ?>
            <option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( $product->get_name() ); ?></option>
        <?php endforeach; ?>
    </select>
    <?php
    return ob_get_clean();
}

/**
 * 會員搜尋欄位，重用 WooCommerce 核心已經註冊好的 `woocommerce_json_search_customers`
 * AJAX action（跟訂單編輯頁「客戶」欄位、訂單列表「依會員篩選」同一套元件），不用自己
 * 寫搜尋後端。已選會員的顯示格式（`姓名 (#ID – Email)`）比照 WooCommerce 核心
 * `class-wc-meta-box-order-data.php` 的既有慣例，跟其他頁面看到的呈現方式一致。
 *
 * 儲值金「會員餘額」頁籤（v25.8.64）與紅利點數「會員餘額」頁籤共用這支，原本各自
 * 內嵌一份幾乎相同的實作，抽成共用 helper 避免日後改一處漏改另一處。
 */
function twshop_render_customer_search_field( $name, $selected_user_id = 0 ) {
    $user_string = '';
    if ( $selected_user_id ) {
        $user = get_userdata( $selected_user_id );
        if ( $user ) {
            $customer    = new WC_Customer( $selected_user_id );
            $full_name   = trim( $customer->get_first_name() . ' ' . $customer->get_last_name() );
            $user_string = sprintf( '%s (#%d – %s)', $full_name ?: $user->display_name, $selected_user_id, $user->user_email );
        }
    }
    ?>
    <select class="wc-customer-search" name="<?php echo esc_attr( $name ); ?>" data-placeholder="搜尋會員姓名／Email" data-allow_clear="true" style="width:320px;">
        <?php if ( $selected_user_id && $user_string ) : ?>
            <option value="<?php echo esc_attr( $selected_user_id ); ?>" selected="selected"><?php echo esc_html( $user_string ); ?></option>
        <?php endif; ?>
    </select>
    <?php
}

// 「先選限制類型、再依類型複選項目」的共用元件（單一設定欄位取代多個各自獨立的類型欄位）；
// $type_labels 為 type => 顯示文字（依序輸出，最前面固定加一個「無限制」）；
// $values_configs 為 type => array('name'=>欄位名稱, 'options'=>value=>label 清單, 'selected'=>目前已選的值陣列)
function twshop_render_typed_condition_field( $type_name, $current_type, $type_labels, $values_configs ) {
    ob_start();
    ?>
    <div class="twshop-condition-scope">
        <select name="<?php echo esc_attr( $type_name ); ?>" class="twshop-condition-type" style="width:100%; max-width:250px;">
            <option value="">無限制</option>
            <?php foreach ( $type_labels as $val => $label ) : ?>
                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_type, $val ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php foreach ( $values_configs as $val => $cfg ) : ?>
            <div class="condition-values-wrap condition-values-<?php echo esc_attr( $val ); ?>" style="display:none; margin-top:8px;">
                <?php echo twshop_render_chip_field( $cfg['name'], $cfg['selected'], $cfg['options'] ); ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

// term_id/product_id 陣列型 option 的通用讀取：相容改版前只存單一 int 的舊資料
function twshop_get_option_id_array( $option_name ) {
    $value = get_option( $option_name, array() );
    if ( empty( $value ) ) return array();
    if ( ! is_array( $value ) ) return array( (int) $value );
    return array_values( array_filter( array_map( 'intval', $value ) ) );
}

// 陣列型 option 的共用 sanitize callback（register_setting 用）：確保存入的是乾淨的正整數陣列
function twshop_sanitize_id_array( $value ) {
    return array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
}

/**
 * 折扣規則分類/標籤條件（存 slug）的 sanitize：只保留該分類法下真實存在的 slug。
 * 不能用 sanitize_text_field()——它會刪掉百分比編碼（%e6%9c%8d…），中文 slug 整個變空字串，
 * 條件被當成未設定，規則靜默變成全站適用（v25.8.34 修正）。
 */
function twshop_sanitize_term_slugs( $values, $taxonomy ) {
    $values = array_map( 'strval', (array) $values );
    if ( empty( $values ) ) return array();
    $existing = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'slugs' ) );
    if ( is_wp_error( $existing ) ) return array();
    return array_values( array_intersect( array_unique( $values ), $existing ) );
}

// 分類/標籤限制類型 option 的 sanitize callback（register_setting 用）：只允許 category/tag，其餘一律視為「無限制」
function twshop_sanitize_cat_tag_type( $value ) {
    $value = sanitize_text_field( $value );
    return in_array( $value, array( 'category', 'tag' ), true ) ? $value : '';
}

// 訂單狀態複選 option 的 sanitize callback（register_setting 用）：只保留 wc_get_order_statuses() 認得的合法狀態 slug（不含 'wc-' 前綴）
function twshop_sanitize_order_status_array( $value ) {
    $valid = array_map( function( $status_key ) { return str_replace( 'wc-', '', $status_key ); }, array_keys( wc_get_order_statuses() ) );
    return array_values( array_intersect( (array) $value, $valid ) );
}

// 點數兌換商品清單 option 的 sanitize callback（register_setting 用）：後台送出的是單一隱藏欄位裡的
// JSON 字串（比照會員等級「生日禮/升等禮」的 gifts-json репeater 慣例），逐筆驗證 type/id/points_cost，
// 同一 (type, id) 組合重複出現只保留第一筆（v25.8.15 起 type 可為 product/category/tag，見
// twshop_normalize_redeemable_entry()）。id 是否真的存在（商品/分類/標籤）刻意不在這裡驗證——
// 前端下拉選單本身就只列得出存在的選項，這裡重複查一次 DB 換不到什麼額外保護，
// 之後商品/term 被刪掉留下孤兒 id 由讀取端（twshop_resolve_redeemable_products()／
// twshop_get_redeem_cost_for_product()）的 wc_get_product()/has_term() 自然跳過，不需要在存檔時擋。
//
// points_cost 只有 type=product 才要求必填正整數：type=category/tag 的兌換點數
// 改成讀取端依各商品售價即時換算（v25.8.17 起，見 twshop_calc_redeem_cost_from_price()），
// 這裡存的 points_cost 對分類/標籤沒有意義，不驗證也不需要——分類/標籤只要 id 合法就收。
//
// max_qty（v25.8.32 新增）：單次兌換最多可選的數量，三種 type 都適用（不像 points_cost
// 只對 type=product 有意義），沒填或填非正整數一律回退成 1，跟舊資料／前台既有行為一致。
function twshop_sanitize_points_redeemable_products( $value ) {
    $decoded = json_decode( is_string( $value ) ? stripslashes( $value ) : '[]', true );
    if ( ! is_array( $decoded ) ) return array();

    $result = array();
    $seen   = array();
    foreach ( $decoded as $row ) {
        $type = isset( $row['type'] ) && in_array( $row['type'], array( 'product', 'category', 'tag' ), true ) ? $row['type'] : 'product';
        $id   = isset( $row['id'] ) ? absint( $row['id'] ) : absint( $row['product_id'] ?? 0 );
        $points_cost = absint( $row['points_cost'] ?? 0 );
        $max_qty     = max( 1, absint( $row['max_qty'] ?? 1 ) );
        $dedup_key = $type . ':' . $id;
        if ( $id <= 0 || isset( $seen[ $dedup_key ] ) ) continue;
        if ( 'product' === $type && $points_cost <= 0 ) continue;
        // 儲值金商品不能被設成點數兌換商品（v25.8.79 新增）：$0 兌換卻仍會入帳完整面額，
        // 等於把點數免費換成真錢。type=category/tag 是動態展開，這裡驗證不到，改在
        // twshop_resolve_redeemable_products()（points-engine.php）展開時跳過。
        if ( 'product' === $type && twshop_is_wallet_credit_product( wc_get_product( $id ) ) ) continue;
        $seen[ $dedup_key ] = true;
        $result[] = array( 'type' => $type, 'id' => $id, 'points_cost' => $points_cost, 'max_qty' => $max_qty );
    }
    return $result;
}

// 發放消費回饋點數的訂單狀態（不含 'wc-' 前綴），預設僅「已完成」，維持改版前的原有行為
function twshop_get_points_award_statuses() {
    $statuses = get_option( 'wc_points_award_statuses', array( 'completed' ) );
    return ( is_array( $statuses ) && ! empty( $statuses ) ) ? $statuses : array( 'completed' );
}

// 退還折抵點數／追回已發放回饋點數的訂單狀態（不含 'wc-' 前綴），預設「已取消」「已退款」「付款失敗」
function twshop_get_points_revoke_statuses() {
    $statuses = get_option( 'wc_points_revoke_statuses', array( 'cancelled', 'refunded', 'failed' ) );
    return ( is_array( $statuses ) && ! empty( $statuses ) ) ? $statuses : array( 'cancelled', 'refunded', 'failed' );
}

// 讀取「限制類型 + 複選項目」設定，相容改版前分類/標籤各自獨立欄位的舊資料：
// 尚未透過新版介面儲存過（$type_option 選項不存在）時，才回退讀取 $legacy_map（type => 舊 option name），依序取第一個有值的
function twshop_get_typed_restriction( $type_option, $values_option, $legacy_map = array() ) {
    $type = get_option( $type_option, null );
    if ( $type !== null ) {
        return array( $type, twshop_get_option_id_array( $values_option ) );
    }
    foreach ( $legacy_map as $legacy_type => $legacy_option ) {
        $legacy_values = twshop_get_option_id_array( $legacy_option );
        if ( ! empty( $legacy_values ) ) return array( $legacy_type, $legacy_values );
    }
    return array( '', array() );
}
