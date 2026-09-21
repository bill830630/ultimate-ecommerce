<?php
/**
 * 台灣地址欄位客製化 + 超商取貨免填地址
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 台灣地址欄位客製化 (縣市下拉選單、欄位順序與標籤)
// =========================================================================

/**
 * 補上台灣 22 個縣市，讓「縣/市」欄位在結帳/帳戶頁顯示為下拉選單。WooCommerce 核心的
 * 欄位型態判斷（`WC_Countries::get_address_fields()` 的 `state` 分支）是「該國有定義
 * `states` 就是選單，沒有就是文字輸入」，所以「要不要顯示選單」完全由這裡回不回傳
 * `$states['TW']` 決定。
 *
 * **跟「鄉鎮市區」下拉選單同一個開關（2026-09 補上）**：只在目前已選的運送方式勾了
 * 「台灣地址下拉選單連動」時才回傳台灣縣市清單，否則原樣回傳（`$states['TW']` 未設定，
 * `state` 欄位退回原生文字輸入）——道理跟 `twshop_taiwan_city_field_as_select()` 完全一樣，
 * 「縣市、鄉鎮市區、郵遞區號」是同一組連動功能，不該縣市自己不受開關管。
 *
 * **`is_admin()` 是刻意加的例外，不能拿掉**：`woocommerce_states` 這個 filter 不是只有
 * 結帳頁在用——WooCommerce ▸ 設定 ▸ 一般的「商店地址」（`single_select_country` 欄位型態，
 * `WC()->countries->country_dropdown_options()`）、運送區域設定的地區選擇器、稅率設定的
 * 地區選擇器都會觸發同一個 filter。這些後台畫面完全沒有「運送方式」的概念，拿結帳 session
 * 裡的已選運送方式去判斷會誤鎖管理員自己的設定介面（沒有最近結帳過連動方式，商店地址就
 * 選不到任何台灣縣市）。`is_admin()` 在這裡是安全的判斷依據：WooCommerce 結帳頁的
 * `update_order_review` 等 AJAX 走的是 `wc-ajax=xxx`（首頁網址帶查詢字串），不是
 * `/wp-admin/admin-ajax.php`，不會讓 `is_admin()` 誤判成 true。
 */
function twshop_add_taiwan_states( $states ) {
    if ( ! is_admin() && ! twshop_is_address_linkage_shipping_chosen() ) return $states;

    $states['TW'] = twshop_get_taiwan_state_names();
    return $states;
}

/**
 * 台灣 22 縣市代碼 → 中文名稱，是這份對照表的唯一來源。
 *
 * 兩個用途刻意共用同一份：`twshop_add_taiwan_states()` 拿它當結帳頁「縣/市」下拉選單的選項，
 * `twshop_taiwan_state_name()` 拿它把**已經存進訂單的代碼**還原成中文（見該函式）。
 * 分成兩份維護的話，新增/更名一個縣市時只改到其中一邊，另一邊會靜默失效——選單裡選得到、
 * 訂單上卻印代碼，或反過來。
 */
function twshop_get_taiwan_state_names() {
    return array(
        'TPE' => '臺北市', 'NTP' => '新北市', 'TYC' => '桃園市', 'TXG' => '臺中市',
        'TNN' => '臺南市', 'KHH' => '高雄市', 'KEE' => '基隆市', 'HSZ' => '新竹市',
        'HSQ' => '新竹縣', 'MIA' => '苗栗縣', 'CHA' => '彰化縣', 'NAN' => '南投縣',
        'YUN' => '雲林縣', 'CYI' => '嘉義市', 'CYQ' => '嘉義縣', 'PIF' => '屏東縣',
        'YIL' => '宜蘭縣', 'HUA' => '花蓮縣', 'TTT' => '臺東縣', 'PEN' => '澎湖縣',
        'KIN' => '金門縣', 'LIE' => '連江縣',
    );
}

/**
 * 縣市代碼 → 中文名稱；**不是代碼就回傳空字串**（呼叫端據此決定「不要動這個值」）。
 *
 * 資料庫裡的 `state` 有兩種形態並存，而且沒有欄位分辨得出來：連動模式下結帳的訂單存的是
 * WooCommerce 的州別代碼（`TYC`），非連動模式下顧客自由輸入的是中文（`桃園市`）。所以判斷
 * 一律用「查得到 key 才換」，查不到就原樣放行——中文值查不到 key，不會被誤動。
 */
function twshop_taiwan_state_name( $state, $country ) {
    if ( 'TW' !== $country || ! is_string( $state ) || '' === $state ) return '';
    $names = twshop_get_taiwan_state_names();
    return isset( $names[ $state ] ) ? $names[ $state ] : '';
}

/**
 * 台灣地址的顯示格式，覆寫 WooCommerce 核心的 `TW` 樣板。
 *
 * 核心原本是 `{address_1}\n{address_2}\n{state}, {city} {postcode}`（西式，由小到大、逗號分隔），
 * 印出來長這樣：`東興路二段58號7F-5` ／ `桃園市, 平鎮區 324`。台灣宅配是由大到小、不加分隔，
 * 郵遞區號在最前面，所以改成 `{postcode} {state}{city}{address_1} {address_2}`
 * → `324 桃園市平鎮區東興路二段58號7F-5`，整行可以直接抄進託運單。
 *
 * `{name}` 的姓名連寫規則由 `twshop_taiwan_formatted_address_replacements()` 覆寫，不在這裡用
 * `{last_name}{first_name}` 寫死——理由見該函式。
 *
 * `{address_1} {address_2}` 之間留一個空白是刻意的：兩者連寫會黏成
 * `…58號7F-5B室`。address_2 為空時，核心的 `trim_formatted_address_line()` 會把行尾空白清掉。
 */
function twshop_taiwan_address_format( $formats ) {
    $formats['TW'] = "{name}\n{company}\n{postcode} {state}{city}{address_1} {address_2}\n{country}";
    return $formats;
}

/**
 * 台灣地址顯示時的兩項替換：姓名連寫規則，以及 `{state}` 由縣市代碼還原成中文名稱。
 *
 * ── 姓名 ──
 * 核心的 `{name}` 是用 `_x( '%1$s %2$s', 'full name' )` 組的「名 空格 姓」，套在中文姓名上會變成
 * 「小明 王」。但也不能在樣板裡直接寫死 `{last_name}{first_name}`：那對中文是對的
 * （`王小明`），對英文姓名卻會黏成 `NiBill`——本站確實有英文姓名的顧客資料，而且黏起來之後
 * 印在託運單上不會有任何警訊。所以改成**看姓名裡有沒有漢字**決定連寫或空格分隔。
 * Han 涵蓋中文與日文漢字；純拉丁字母的姓名走空格那一支。
 *
 * ── 縣市 ──
 *
 * **為什麼核心自己換不過來**：`WC_Countries::get_formatted_address()` 是用
 * `$this->states[ $country ][ $state ]` 查中文名，而 `$this->states` 走 `__get()` → `get_states()`
 * → `woocommerce_states` filter，也就是 `twshop_add_taiwan_states()`。那支**刻意**只在後台或
 * 「目前已選的運送方式勾了連動」時才回傳 `$states['TW']`（見該函式，那是「縣/市要不要變下拉選單」
 * 的開關）。但訂單詳情頁／通知信／帳號地址頁全都是結帳結束之後才渲染，session 裡早就沒有運送方式，
 * 查表整個落空，`$full_state` 直接 fallback 成資料庫裡的原值——畫面上就印出 `TYC`、`TPE`。
 * **不會有任何錯誤訊息**，只有顧客看到一組英文代碼。
 *
 * 修法刻意不是「讓 `twshop_add_taiwan_states()` 一律回傳清單」：那等於把「縣/市 永遠是下拉選單」
 * 改回去，2026-09 才特意讓它跟著連動開關走。顯示與欄位型態是兩件事，這裡只補顯示。
 *
 * `{state_upper}` 一併換掉是為了跟 `{state}` 一致（`wc_strtoupper()` 對中文是 no-op）；
 * `{state_code}` **維持代碼不動**，那個 token 的語意本來就是代碼。
 * 回傳值不必自己跳脫——核心在 `apply_filters()` 之後才整批 `array_map( 'esc_html', ... )`。
 */
function twshop_taiwan_formatted_address_replacements( $replacements, $args ) {
    $country = isset( $args['country'] ) ? $args['country'] : '';
    if ( 'TW' !== $country ) return $replacements;

    $last  = isset( $args['last_name'] ) ? $args['last_name'] : '';
    $first = isset( $args['first_name'] ) ? $args['first_name'] : '';
    if ( '' !== $last && '' !== $first ) {
        $glue = preg_match( '/\p{Han}/u', $last . $first ) ? '' : ' ';
        $replacements['{name}']       = $last . $glue . $first;
        $replacements['{name_upper}'] = wc_strtoupper( $last . $glue . $first );
    }

    $state_name = twshop_taiwan_state_name( isset( $args['state'] ) ? $args['state'] : '', $country );
    if ( '' !== $state_name ) {
        $replacements['{state}']       = $state_name;
        $replacements['{state_upper}'] = $state_name;
    }

    return $replacements;
}

/**
 * 訂單的 `get_billing_state()`／`get_shipping_state()` 在 **view context** 下回傳中文縣市名。
 *
 * 上面那支只修「經過 `get_formatted_address()` 的顯示」，第三方外掛直接讀 props 的路徑蓋不到，
 * 而那條路徑才是真的會出事的地方——綠界物流（`ecpay-ecommerce-for-woocommerce`）的宅配收件地址是
 * `$order->get_shipping_state() . get_shipping_city() . get_shipping_address_1() . get_shipping_address_2()`
 * 直接串起來的（`includes/services/helpers/logistic/ecpay-logistic-helper.php`，`ReceiverAddress`），
 * 沒有任何 filter 可以攔那份 payload。不補這一道，**送去綠界、印在託運單上的地址就是
 * `TYC平鎮區東興路二段58號7F-5`**，貨會送不到，而且站台這端完全看不出異常。
 *
 * **只動 view context，不動 edit context**，所以下列全部不受影響：後台訂單編輯頁的地址欄位
 * （`WC_Meta_Box_Order_Data` 從頭到尾都用 `'edit'` 取值，已核對）、`$order->save()`、
 * `WC_Data::get_data()`（REST API 走這支，拿到的還是代碼）。也就是說**資料庫存的值完全沒被改寫**，
 * 這只是一層讀取時的翻譯。
 *
 * 值本來就是中文（非連動模式自由輸入）時 `twshop_taiwan_state_name()` 回空字串、原樣放行，
 * 所以這支對兩種資料形態都安全，重複套用也不會出錯。
 */
function twshop_localize_order_billing_state( $state, $order ) {
    if ( ! $order instanceof WC_Order ) return $state;
    $name = twshop_taiwan_state_name( $state, $order->get_billing_country( 'edit' ) );
    return '' === $name ? $state : $name;
}

/**
 * 同上，運送地址版。分成兩支是因為要各自比對「同一組地址的國別」——billing 是 TW、shipping 是
 * 其他國家（或反過來）的訂單確實存在，共用一支就得猜要拿哪個國別，會在跨國訂單上換錯。
 */
function twshop_localize_order_shipping_state( $state, $order ) {
    if ( ! $order instanceof WC_Order ) return $state;
    $name = twshop_taiwan_state_name( $state, $order->get_shipping_country( 'edit' ) );
    return '' === $name ? $state : $name;
}

/**
 * 調整台灣地址欄位的標籤、提示文字與排列順序：
 * 郵遞區號 → 縣/市 → 鄉鎮市區 → 詳細地址，符合台灣慣用填寫習慣
 * (billing 與 shipping 欄位皆會套用，僅影響國別為 TW 的情況)
 */
function twshop_taiwan_address_locale( $locale ) {
    $locale['TW'] = array(
        'postcode'  => array(
            'label'    => '郵遞區號',
            'priority' => 45,
        ),
        'state'     => array(
            'label'    => '縣/市',
            'priority' => 55,
        ),
        'city'      => array(
            'label'    => '鄉鎮市區',
            'priority' => 65,
        ),
        'address_1' => array(
            'label'       => '詳細地址',
            'placeholder' => '路名、巷弄、號數、樓層',
            'priority'    => 75,
        ),
        'address_2' => array(
            'priority' => 85,
        ),
    );
    return $locale;
}

/**
 * 台灣 22 縣市 → 鄉鎮市區 → 3 碼郵遞區號對照表（共 368 個鄉鎮市區），對應 2010 年縣市合併後的
 * 現行行政區劃。這份表同時是兩件事的唯一資料來源：
 *
 * 1. `twshop_get_taiwan_districts()`（下方）取 `array_keys()` 得到「鄉鎮市區」下拉選單的選項；
 * 2. `twshop_lookup_taiwan_postcode()` 依「縣市＋鄉鎮市區」反查郵遞區號，供結帳/編輯地址在
 *    郵遞區號欄位隱藏的情況下，於伺服器端把值補回去（見該函式與
 *    `twshop_taiwan_hide_postcode_field()`）。
 *
 * 資料整理自 assets/js/twshop-tw-postcode.js 的 TWSHOP_POSTCODE_UNIQUE（363 筆唯一對應）
 * 加上 HSZ（新竹市）／CYI（嘉義市）——這兩市底下多個行政區共用同一碼（300／600），
 * 「郵遞區號 → 鄉鎮市區」方向判斷不出是哪一區（見該檔 TWSHOP_POSTCODE_AMBIGUOUS 的說明），
 * 但**本表要的是反過來的方向**：不管選哪一區，郵遞區號都是同一個，沒有歧義。
 *
 * **這份資料跟前端 JS（twshop-tw-postcode.js）各自獨立維護**：這裡供伺服器端渲染下拉選單、
 * 以及送出表單時補郵遞區號用，JS 那份供使用者選縣市/鄉鎮市區時即時換選項、即時帶郵遞區號用，
 * 執行環境不同（PHP 渲染一次 vs. 瀏覽器端互動）沒有共用一份資料的簡單做法。台灣行政區劃
 * 極少變動（上次是 2010 年五都合併），異動機率低，但若真的異動，兩邊都要一併更新。
 */
function twshop_get_taiwan_district_postcodes() {
    return array(
        'TPE' => array( '中正區' => '100', '大同區' => '103', '中山區' => '104', '松山區' => '105', '大安區' => '106', '萬華區' => '108', '信義區' => '110', '士林區' => '111', '北投區' => '112', '內湖區' => '114', '南港區' => '115', '文山區' => '116' ),
        'NTP' => array( '萬里區' => '207', '金山區' => '208', '板橋區' => '220', '汐止區' => '221', '深坑區' => '222', '石碇區' => '223', '瑞芳區' => '224', '平溪區' => '226', '雙溪區' => '227', '貢寮區' => '228', '新店區' => '231', '坪林區' => '232', '烏來區' => '233', '永和區' => '234', '中和區' => '235', '土城區' => '236', '三峽區' => '237', '樹林區' => '238', '鶯歌區' => '239', '三重區' => '241', '新莊區' => '242', '泰山區' => '243', '林口區' => '244', '蘆洲區' => '247', '五股區' => '248', '八里區' => '249', '淡水區' => '251', '三芝區' => '252', '石門區' => '253' ),
        'TYC' => array( '中壢區' => '320', '平鎮區' => '324', '龍潭區' => '325', '楊梅區' => '326', '新屋區' => '327', '觀音區' => '328', '桃園區' => '330', '龜山區' => '333', '八德區' => '334', '大溪區' => '335', '復興區' => '336', '大園區' => '337', '蘆竹區' => '338' ),
        'TXG' => array( '中區' => '400', '東區' => '401', '南區' => '402', '西區' => '403', '北區' => '404', '北屯區' => '406', '西屯區' => '407', '南屯區' => '408', '太平區' => '411', '大里區' => '412', '霧峰區' => '413', '烏日區' => '414', '豐原區' => '420', '后里區' => '421', '石岡區' => '422', '東勢區' => '423', '和平區' => '424', '新社區' => '426', '潭子區' => '427', '大雅區' => '428', '神岡區' => '429', '大肚區' => '432', '沙鹿區' => '433', '龍井區' => '434', '梧棲區' => '435', '清水區' => '436', '大甲區' => '437', '外埔區' => '438', '大安區' => '439' ),
        'TNN' => array( '中西區' => '700', '東區' => '701', '南區' => '702', '北區' => '704', '安平區' => '708', '安南區' => '709', '永康區' => '710', '歸仁區' => '711', '新化區' => '712', '左鎮區' => '713', '玉井區' => '714', '楠西區' => '715', '南化區' => '716', '仁德區' => '717', '關廟區' => '718', '龍崎區' => '719', '官田區' => '720', '麻豆區' => '721', '佳里區' => '722', '西港區' => '723', '七股區' => '724', '將軍區' => '725', '學甲區' => '726', '北門區' => '727', '新營區' => '730', '後壁區' => '731', '白河區' => '732', '東山區' => '733', '六甲區' => '734', '下營區' => '735', '柳營區' => '736', '鹽水區' => '737', '善化區' => '741', '大內區' => '742', '山上區' => '743', '新市區' => '744', '安定區' => '745' ),
        'KHH' => array( '新興區' => '800', '前金區' => '801', '苓雅區' => '802', '鹽埕區' => '803', '鼓山區' => '804', '旗津區' => '805', '前鎮區' => '806', '三民區' => '807', '楠梓區' => '811', '小港區' => '812', '左營區' => '813', '仁武區' => '814', '大社區' => '815', '岡山區' => '820', '路竹區' => '821', '阿蓮區' => '822', '田寮區' => '823', '燕巢區' => '824', '橋頭區' => '825', '梓官區' => '826', '彌陀區' => '827', '永安區' => '828', '湖內區' => '829', '鳳山區' => '830', '大寮區' => '831', '林園區' => '832', '鳥松區' => '833', '大樹區' => '840', '旗山區' => '842', '美濃區' => '843', '六龜區' => '844', '內門區' => '845', '杉林區' => '846', '甲仙區' => '847', '桃源區' => '848', '那瑪夏區' => '849', '茂林區' => '851', '茄萣區' => '852' ),
        'KEE' => array( '仁愛區' => '200', '信義區' => '201', '中正區' => '202', '中山區' => '203', '安樂區' => '204', '暖暖區' => '205', '七堵區' => '206' ),
        'HSZ' => array( '東區' => '300', '北區' => '300', '香山區' => '300' ),
        'HSQ' => array( '竹北市' => '302', '湖口鄉' => '303', '新豐鄉' => '304', '新埔鎮' => '305', '關西鎮' => '306', '芎林鄉' => '307', '寶山鄉' => '308', '竹東鎮' => '310', '五峰鄉' => '311', '橫山鄉' => '312', '尖石鄉' => '313', '北埔鄉' => '314', '峨嵋鄉' => '315' ),
        'MIA' => array( '竹南鎮' => '350', '頭份市' => '351', '三灣鄉' => '352', '南庄鄉' => '353', '獅潭鄉' => '354', '後龍鎮' => '356', '通霄鎮' => '357', '苑裡鎮' => '358', '苗栗市' => '360', '造橋鄉' => '361', '頭屋鄉' => '362', '公館鄉' => '363', '大湖鄉' => '364', '泰安鄉' => '365', '銅鑼鄉' => '366', '三義鄉' => '367', '西湖鄉' => '368', '卓蘭鎮' => '369' ),
        'CHA' => array( '彰化市' => '500', '芬園鄉' => '502', '花壇鄉' => '503', '秀水鄉' => '504', '鹿港鎮' => '505', '福興鄉' => '506', '線西鄉' => '507', '和美鎮' => '508', '伸港鄉' => '509', '員林市' => '510', '社頭鄉' => '511', '永靖鄉' => '512', '埔心鄉' => '513', '溪湖鎮' => '514', '大村鄉' => '515', '埔鹽鄉' => '516', '田中鎮' => '520', '北斗鎮' => '521', '田尾鄉' => '522', '埤頭鄉' => '523', '溪州鄉' => '524', '竹塘鄉' => '525', '二林鎮' => '526', '大城鄉' => '527', '芳苑鄉' => '528', '二水鄉' => '530' ),
        'NAN' => array( '南投市' => '540', '中寮鄉' => '541', '草屯鎮' => '542', '國姓鄉' => '544', '埔里鎮' => '545', '仁愛鄉' => '546', '名間鄉' => '551', '集集鎮' => '552', '水里鄉' => '553', '魚池鄉' => '555', '信義鄉' => '556', '竹山鎮' => '557', '鹿谷鄉' => '558' ),
        'YUN' => array( '斗南鎮' => '630', '大埤鄉' => '631', '虎尾鎮' => '632', '土庫鎮' => '633', '褒忠鄉' => '634', '東勢鄉' => '635', '臺西鄉' => '636', '崙背鄉' => '637', '麥寮鄉' => '638', '斗六市' => '640', '林內鄉' => '643', '古坑鄉' => '646', '莿桐鄉' => '647', '西螺鎮' => '648', '二崙鄉' => '649', '北港鎮' => '651', '水林鄉' => '652', '口湖鄉' => '653', '四湖鄉' => '654', '元長鄉' => '655' ),
        'CYI' => array( '東區' => '600', '西區' => '600' ),
        'CYQ' => array( '番路鄉' => '602', '梅山鄉' => '603', '竹崎鄉' => '604', '阿里山鄉' => '605', '中埔鄉' => '606', '大埔鄉' => '607', '水上鄉' => '608', '鹿草鄉' => '611', '太保市' => '612', '朴子市' => '613', '東石鄉' => '614', '六腳鄉' => '615', '新港鄉' => '616', '民雄鄉' => '621', '大林鎮' => '622', '溪口鄉' => '623', '義竹鄉' => '624', '布袋鎮' => '625' ),
        'PIF' => array( '屏東市' => '900', '三地門鄉' => '901', '霧臺鄉' => '902', '瑪家鄉' => '903', '九如鄉' => '904', '里港鄉' => '905', '高樹鄉' => '906', '鹽埔鄉' => '907', '長治鄉' => '908', '麟洛鄉' => '909', '竹田鄉' => '911', '內埔鄉' => '912', '萬丹鄉' => '913', '潮州鎮' => '920', '泰武鄉' => '921', '來義鄉' => '922', '萬巒鄉' => '923', '崁頂鄉' => '924', '新埤鄉' => '925', '南州鄉' => '926', '林邊鄉' => '927', '東港鎮' => '928', '琉球鄉' => '929', '佳冬鄉' => '931', '新園鄉' => '932', '枋寮鄉' => '940', '枋山鄉' => '941', '春日鄉' => '942', '獅子鄉' => '943', '車城鄉' => '944', '牡丹鄉' => '945', '恆春鎮' => '946', '滿州鄉' => '947' ),
        'YIL' => array( '宜蘭市' => '260', '頭城鎮' => '261', '礁溪鄉' => '262', '壯圍鄉' => '263', '員山鄉' => '264', '羅東鎮' => '265', '三星鄉' => '266', '大同鄉' => '267', '五結鄉' => '268', '冬山鄉' => '269', '蘇澳鎮' => '270', '南澳鄉' => '272' ),
        'HUA' => array( '花蓮市' => '970', '新城鄉' => '971', '秀林鄉' => '972', '吉安鄉' => '973', '壽豐鄉' => '974', '鳳林鎮' => '975', '光復鄉' => '976', '豐濱鄉' => '977', '瑞穗鄉' => '978', '萬榮鄉' => '979', '玉里鎮' => '981', '卓溪鄉' => '982', '富里鄉' => '983' ),
        'TTT' => array( '臺東市' => '950', '綠島鄉' => '951', '蘭嶼鄉' => '952', '延平鄉' => '953', '卑南鄉' => '954', '鹿野鄉' => '955', '關山鎮' => '956', '海端鄉' => '957', '池上鄉' => '958', '東河鄉' => '959', '成功鎮' => '961', '長濱鄉' => '962', '太麻里鄉' => '963', '金峰鄉' => '964', '大武鄉' => '965', '達仁鄉' => '966' ),
        'PEN' => array( '馬公市' => '880', '西嶼鄉' => '881', '望安鄉' => '882', '七美鄉' => '883', '白沙鄉' => '884', '湖西鄉' => '885' ),
        'KIN' => array( '金沙鎮' => '890', '金湖鎮' => '891', '金寧鄉' => '892', '金城鎮' => '893', '烈嶼鄉' => '894', '烏坵鄉' => '896' ),
        'LIE' => array( '南竿鄉' => '209', '北竿鄉' => '210', '莒光鄉' => '211', '東引鄉' => '212' ),
    );
}

/**
 * 台灣 22 縣市 → 鄉鎮市區清單，供 twshop_taiwan_city_field_as_select() 建立「鄉鎮市區」
 * 下拉選單的 options。直接從上方對照表取 key，陣列順序即選單顯示順序，
 * 不另外維護第二份名稱清單（原本這裡是獨立的一份陣列，2026-09 併進對照表）。
 */
function twshop_get_taiwan_districts() {
    static $cache = null;
    if ( null !== $cache ) return $cache;

    $cache = array_map( 'array_keys', twshop_get_taiwan_district_postcodes() );
    return $cache;
}

/**
 * 「縣市代碼＋鄉鎮市區名稱」→ 3 碼郵遞區號。查不到回傳空字串（呼叫端一律以「查不到就不要動
 * 原本的值」處理，不要塞入猜測值）。
 */
function twshop_lookup_taiwan_postcode( $state, $district ) {
    if ( '' === $state || '' === $district ) return '';

    $map = twshop_get_taiwan_district_postcodes();
    return $map[ $state ][ $district ] ?? '';
}

/**
 * 縣市（state）已經是 WooCommerce 原生下拉選單（見 twshop_add_taiwan_states()），且不受
 * 這裡的開關影響、永遠是選單。這支函式讓「鄉鎮市區」（city）也變成下拉選單而非自由輸入
 * 文字，避免打錯字、跟縣市對不起來的髒地址資料，符合台灣慣用地址填寫方式。
 *
 * **只在目前已選的運送方式勾了「台灣地址下拉選單連動」時才啟用**（見
 * twshop_is_address_linkage_shipping_chosen()，管理員在每個運送方式 instance 的設定頁
 * 個別勾選，跟「超商取貨免填地址」的 twshop_is_cvs 是同一套機制）。沒有勾、或還沒選
 * 運送方式時，city 維持原生自由輸入文字，行為跟這個功能誕生之前完全一樣。
 *
 * options 只放「目前這個欄位所屬縣市」的鄉鎮市區清單：縣市改變時前端 JS
 * （twshop-tw-postcode.js）會即時重新灌一次選項，這裡負責的是「頁面第一次載入當下」該顯示
 * 哪個縣市的清單——用 WC()->customer 目前記錄的縣市（登入會員存過地址、或本次瀏覽已選過
 * 運送地址時就有值），沒有值就只顯示「請先選擇縣市」佔位選項，交由使用者選定縣市後前端 JS
 * 才會灌入清單。
 *
 * 若客戶既有資料（帳號已存的舊地址）裡的鄉鎮市區剛好不在目前縣市的清單裡（例如資料本來就
 * 打錯、或行政區劃調整前的舊資料），額外把這個既有值也塞進 options，確保原生 <select>
 * 渲染時不會因為「目前值不在清單裡」而悄悄變成沒有任何選項被選中，使用者沒留意就直接送出
 * 表單，把舊地址無聲蓋成空字串。
 *
 * 掛在 billing/shipping 兩個 field filter 而非 woocommerce_checkout_fields：
 * get_address_fields()（WC_Countries）是結帳頁與「我的帳號 ▸ 編輯地址」頁共用的同一支函式，
 * 掛這兩個 filter 才能讓兩個頁面都變成選單，不會編輯地址頁還留著自由輸入文字的落差。
 * **「我的帳號 ▸ 編輯地址」頁沒有運送方式可選**，`twshop_is_address_linkage_shipping_chosen()`
 * 讀的是 session 裡上次結帳時記得的已選運送方式，跨頁沿用同一個判斷結果。
 *
 * **本站「銷售地區」實際上不只台灣**（`woocommerce_specific_allowed_countries` 目前是
 * CN/TW/HK），結帳頁國別選單確實可以切換。客戶把國別從 TW 切到其他國家後，`city` 欄位
 * 不會自動切回文字輸入（WooCommerce 原生的 country→state 切換邏輯只處理 state，不知道
 * city 也被本外掛動過），畫面上會留著一個顯示台灣鄉鎮市區選項、卻對應著其他國家地址的
 * `<select>`。目前刻意沒處理這個組合情境（運送方式勾了連動＋國別又切離開台灣）。
 */
function twshop_taiwan_city_field_as_select( $fields, $country ) {
    if ( 'TW' !== $country ) return $fields;
    if ( ! twshop_is_address_linkage_shipping_chosen() ) return $fields;

    $districts = twshop_get_taiwan_districts();
    $map       = array(
        'billing_city'  => 'billing',
        'shipping_city' => 'shipping',
    );

    foreach ( $map as $key => $prefix ) {
        if ( ! isset( $fields[ $key ] ) ) continue;

        $state   = ( 'billing' === $prefix ) ? WC()->customer->get_billing_state() : WC()->customer->get_shipping_state();
        $current = ( 'billing' === $prefix ) ? WC()->customer->get_billing_city() : WC()->customer->get_shipping_city();

        $options = array( '' => '請先選擇縣市' );
        foreach ( $districts[ $state ] ?? array() as $district ) {
            $options[ $district ] = $district;
        }
        if ( '' !== $current && ! isset( $options[ $current ] ) ) {
            $options[ $current ] = $current;
        }

        $fields[ $key ]['type']    = 'select';
        $fields[ $key ]['options'] = $options;
        unset( $fields[ $key ]['placeholder'] ); // select 沒有 placeholder 屬性可用，避免殘留無用值
    }

    return $fields;
}

/**
 * 隱藏「郵遞區號」欄位，改由「縣/市 ＋ 鄉鎮市區」自動決定。
 *
 * **不是移除欄位，是讓它不顯示**：`<input>` 仍留在表單裡照常送出，值一樣會寫進訂單、
 * 出現在訂單詳情/通知信/物流單上，只是顧客不用自己填——縣市與鄉鎮市區都已經是下拉選單、
 * 選完就唯一決定了郵遞區號（見 twshop_get_taiwan_district_postcodes()），再要顧客抄一次
 * 只是多一個填錯的機會。實際填值有三道：
 *
 * 1. 前端 twshop-tw-postcode.js 的 twshopSyncPostcodeFromAddress()：選完縣市/鄉鎮市區當下
 *    立刻寫進隱藏欄位，結帳頁的運費/稅率試算才拿得到正確郵遞區號；
 * 2. 送出結帳時 twshop_fill_taiwan_postcode_posted_data()（`woocommerce_checkout_posted_data`）；
 * 3. 儲存「我的帳號 ▸ 編輯地址」時 twshop_fill_taiwan_postcode_on_save_address()。
 *
 * 第 2、3 道是伺服器端的最後防線，確保 JS 沒跑（被擋掉、報錯）時訂單上的郵遞區號仍然正確，
 * 不會靜默留下空白。
 *
 * **一定要同時 `required = false`**：欄位被 CSS 藏起來（`.twshop-postcode-auto`）之後，
 * 若還留著 HTML5 `required` 屬性，瀏覽器原生驗證會在送出時擋下表單、又因為欄位不可見而
 * 無法把焦點移過去，Chrome 只會在 console 丟一句 "An invalid form control ... is not
 * focusable"，畫面上完全沒有任何提示，顧客會覺得「按了送出沒反應」。少填的情況由「鄉鎮市區」
 * 本身的必填擋下就夠了（沒選鄉鎮市區才會查不到郵遞區號），不需要再擋一次。
 *
 * 掛在 billing/shipping 兩個 field filter（跟 twshop_taiwan_city_field_as_select() 同一組），
 * 結帳頁與「我的帳號 ▸ 編輯地址」頁一併涵蓋。
 */
function twshop_taiwan_hide_postcode_field( $fields, $country ) {
    if ( 'TW' !== $country ) return $fields;
    if ( ! twshop_is_address_linkage_shipping_chosen() ) return $fields;

    foreach ( array( 'billing_postcode', 'shipping_postcode' ) as $key ) {
        if ( ! isset( $fields[ $key ] ) ) continue;

        $fields[ $key ]['required'] = false;

        $classes = isset( $fields[ $key ]['class'] ) ? (array) $fields[ $key ]['class'] : array();
        if ( ! in_array( 'twshop-postcode-auto', $classes, true ) ) {
            $classes[] = 'twshop-postcode-auto';
        }
        $fields[ $key ]['class'] = $classes;
    }

    return $fields;
}

/**
 * 只有一個允許銷售/運送的國家時，隱藏「國家/地區」欄位——只有一個選項的下拉選單對顧客
 * 沒有意義，只是結帳表單多一列可以誤觸的東西。
 *
 * **不需要另外處理欄位的值**：WooCommerce 核心的 `woocommerce_form_field()` 對
 * `type === 'country'` 欄位本來就有內建的單一國家處理（`wc-template-functions.php`
 * 的 `case 'country':`）——`count($countries) === 1` 時直接輸出「只有一個 `<option selected>`
 * 的 `<select>`」，值是**寫死的那個唯一允許國別代碼**，完全不依賴顧客既有的
 * `get_billing_country()`/`get_shipping_country()` 儲存值。也就是說欄位本來就已經
 * 「鎖定在正確的唯一值」，只是視覺上還是顯示一個只能選一項的下拉選單；這裡要做的**只有**
 * 把外層 `<p class="form-row">` 藏起來，不需要也不應該動 `type`/`default`/`options`，
 * 動了反而可能踩掉核心這段已經處理好的邏輯。
 *
 * billing／shipping 各自獨立判斷（`get_allowed_countries()`／`get_shipping_countries()`，
 * 對應 WooCommerce ▸ 設定 ▸ 一般的「銷售地區」／「運送地區」），因為允許下單的國家跟
 * 允許出貨的國家理論上可以不同，不能假設兩者一致。
 *
 * 隱藏用 CSS class（`.twshop-country-auto`），不是直接從 `$fields` 陣列刪掉這個 key——
 * 欄位本身仍要正常存在並送出，只是不顯示，跟 twshop_taiwan_hide_postcode_field() 的
 * 既有做法一致。
 */
function twshop_hide_single_country_field( $fields ) {
    $map = array(
        'billing_country'  => 'get_allowed_countries',
        'shipping_country' => 'get_shipping_countries',
    );

    foreach ( $map as $key => $countries_method ) {
        if ( ! isset( $fields[ $key ] ) ) continue;

        $countries = WC()->countries->{$countries_method}();
        if ( 1 !== count( $countries ) ) continue;

        $classes = isset( $fields[ $key ]['class'] ) ? (array) $fields[ $key ]['class'] : array();
        if ( ! in_array( 'twshop-country-auto', $classes, true ) ) {
            $classes[] = 'twshop-country-auto';
        }
        $fields[ $key ]['class'] = $classes;
    }

    return $fields;
}

/**
 * 送出的表單裡，被選中的運送方式是否勾了「台灣地址下拉選單連動」。
 *
 * 跟 twshop_is_address_linkage_shipping_chosen()（讀 session）刻意分成兩支：送出結帳的當下，
 * session 裡的 chosen_shipping_methods 還是「上一次結帳頁 AJAX 更新完」記下的值，顧客在送出
 * 前那一刻改選運送方式時兩者會不一致，這裡以實際送出的 `$_POST['shipping_method']` 為準
 * （寫法比照 twshop_cvs_remove_address_errors()）。沒有這個 POST 欄位的情境
 * （「我的帳號 ▸ 編輯地址」頁、全虛擬商品的訂單）退回讀 session，跟欄位當初渲染時的判斷一致。
 */
function twshop_is_address_linkage_posted() {
    $linked = twshop_get_address_linkage_method_strings();
    if ( empty( $linked ) ) return false;

    // phpcs:ignore WordPress.Security.NonceVerification
    if ( ! isset( $_POST['shipping_method'] ) ) return twshop_is_address_linkage_shipping_chosen();

    foreach ( (array) wp_unslash( $_POST['shipping_method'] ) as $method ) { // phpcs:ignore WordPress.Security.NonceVerification
        if ( in_array( sanitize_text_field( $method ), $linked, true ) ) return true;
    }
    return false;
}

/**
 * 依已選的「縣/市 ＋ 鄉鎮市區」把郵遞區號補回送出的資料裡。
 *
 * 掛 `woocommerce_checkout_posted_data`（`WC_Checkout::get_posted_data()` 的最後一行），
 * **早於 `validate_checkout()`**，所以補完的值會一起通過驗證、寫進訂單。
 *
 * 只在兩種情況覆寫：欄位被隱藏（連動模式，值本來就不該由顧客決定），或顧客根本沒填。
 * 非連動模式下顧客自己填的郵遞區號一律尊重，不因為「跟我們的對照表算出來的不一樣」就蓋掉
 * ——對照表是 3 碼，顧客可能填了 5 碼，也可能是對照表沒收錄的新行政區。
 */
function twshop_fill_taiwan_postcode_posted_data( $data ) {
    $is_linked = twshop_is_address_linkage_posted();

    foreach ( array( 'billing', 'shipping' ) as $prefix ) {
        if ( 'TW' !== ( $data[ $prefix . '_country' ] ?? '' ) ) continue;
        if ( ! $is_linked && '' !== trim( (string) ( $data[ $prefix . '_postcode' ] ?? '' ) ) ) continue;

        $postcode = twshop_lookup_taiwan_postcode(
            (string) ( $data[ $prefix . '_state' ] ?? '' ),
            (string) ( $data[ $prefix . '_city' ] ?? '' )
        );
        if ( '' === $postcode ) continue;

        $data[ $prefix . '_postcode' ] = $postcode;
    }

    return $data;
}


/**
 * 「鄉鎮市區」跟「縣/市」對不起來時擋下結帳。
 *
 * **為什麼需要這道**：WooCommerce 對地址欄位只有「必填/格式」層級的驗證，沒有任何跨欄位檢查，
 * 「高雄市 ＋ 大安區」這種不存在的組合會照樣成立訂單。前端 twshop-tw-postcode.js 已經在顧客
 * 換縣市時把不屬於新縣市的鄉鎮市區清掉，但那只在 JS 有跑的前提下成立；JS 被擋掉、或顧客帳號
 * 裡本來就存著對不起來的舊地址時，送出的仍是矛盾的一組值。郵遞區號欄位在連動模式下是隱藏的，
 * 顧客連「這個地址怪怪的」都看不出來，所以這裡寧可擋下來要求重選，也不要靜默收下錯地址。
 *
 * 錯誤 code 刻意用 `billing_city`／`shipping_city`（而不是自訂 code）：`twshop_cvs_remove_address_errors()`
 * 在超商取貨時會 `$errors->remove( 'billing_city' )`，用同一個 code 就自動跟著被清掉——
 * 超商取貨本來就免填地址，不該因為留在欄位裡的舊值擋住結帳。
 *
 * 只在「縣市有值且在對照表裡」＋「鄉鎮市區有值」＋「兩者對不起來」時才報錯：縣市空白由原生
 * 必填驗證處理，不在對照表裡的縣市（理論上不會發生）則不多管，避免把判斷不了的情況擋成錯誤。
 */
function twshop_validate_taiwan_district_match( $data, $errors ) {
    if ( ! twshop_is_address_linkage_posted() ) return;

    $map = twshop_get_taiwan_district_postcodes();

    foreach ( array( 'billing', 'shipping' ) as $prefix ) {
        if ( 'TW' !== ( $data[ $prefix . '_country' ] ?? '' ) ) continue;

        $state    = (string) ( $data[ $prefix . '_state' ] ?? '' );
        $district = (string) ( $data[ $prefix . '_city' ] ?? '' );
        if ( '' === $district || ! isset( $map[ $state ] ) ) continue;
        if ( isset( $map[ $state ][ $district ] ) ) continue;

        $errors->add(
            $prefix . '_city',
            sprintf(
                '「%s」不屬於所選的縣/市，請重新選擇「鄉鎮市區」。',
                esc_html( $district )
            )
        );
    }
}

/**
 * 「我的帳號 ▸ 編輯地址」儲存時的同一件事。這一頁跟結帳頁共用同一組地址欄位
 * （`woocommerce_billing_fields`/`woocommerce_shipping_fields`），郵遞區號一樣會被藏起來，
 * 少了這道就會把顧客帳號裡原本存好的郵遞區號無聲蓋成空字串。
 *
 * 掛 `woocommerce_after_save_address_validation`：這個 action 在 `$customer->save()` **之前**
 * 觸發，直接改 `$customer` 上的值就會跟著存下去，不需要另外再存一次。
 */
function twshop_fill_taiwan_postcode_on_save_address( $user_id, $address_type, $address, $customer ) {
    if ( ! in_array( $address_type, array( 'billing', 'shipping' ), true ) ) return;
    if ( 'TW' !== $customer->{"get_{$address_type}_country"}() ) return;
    if ( ! twshop_is_address_linkage_posted() && '' !== trim( (string) $customer->{"get_{$address_type}_postcode"}() ) ) return;

    $postcode = twshop_lookup_taiwan_postcode(
        (string) $customer->{"get_{$address_type}_state"}(),
        (string) $customer->{"get_{$address_type}_city"}()
    );
    if ( '' === $postcode ) return;

    $customer->{"set_{$address_type}_postcode"}( $postcode );
}

// =========================================================================
// 超商取貨免填地址
// =========================================================================

// 在每個 WooCommerce 運送方式的設定頁加入超商勾選框
function twshop_register_cvs_field_for_shipping() {
    if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) return;
    foreach ( WC()->shipping()->get_shipping_methods() as $method ) {
        add_filter( "woocommerce_shipping_instance_form_fields_{$method->id}", 'twshop_add_cvs_instance_field' );
    }
}

/**
 * 函式名稱沿用「cvs instance field」，但現在同時掛了兩個跟運送方式 instance 綁定的
 * checkbox（超商取貨、台灣地址下拉選單連動），未跟著改名——沿用本外掛既有慣例
 * （見 CLAUDE.md「命名前綴」段落附近的說明），只掛在同一個
 * woocommerce_shipping_instance_form_fields_{$method->id} filter 上，不需要另外
 * 註冊 hook。
 */
function twshop_add_cvs_instance_field( $fields ) {
    $fields['twshop_is_cvs'] = array(
        'title'   => '超商取貨（免填地址）',
        'type'    => 'checkbox',
        'label'   => '勾選後，選擇此運送方式時結帳頁將自動隱藏地址必填欄位',
        'default' => 'no',
    );
    $fields['twshop_is_address_linkage'] = array(
        'title'   => '台灣地址下拉選單連動',
        'type'    => 'checkbox',
        'label'   => '勾選後，選擇此運送方式時「鄉鎮市區」會變成下拉選單、隨「縣/市」即時連動選項，郵遞區號則自動依所選縣市/鄉鎮市區帶入並隱藏欄位（值照常隨訂單送出）',
        'default' => 'no',
    );
    return $fields;
}

/**
 * 掃描所有運送區域＋各自的運送方式 instance，攤平回傳（含所屬區域名稱）。
 * twshop_get_cvs_method_strings() 與 twshop_get_shipping_method_options() 共用這份掃描結果與快取，
 * 避免兩者各自獨立呼叫 WC_Shipping_Zones::get_zones() 重複掃描同一組區域/方式。
 */
/**
 * 運送方式／付款方式的自訂名稱對照表。
 *
 * **為什麼需要這個功能**：WooCommerce 核心的運送方式（flat_rate 等）與付款方式
 * （cod/bacs 等）都有 Title 設定欄位可以改，但第三方外掛不一定有——本站用的綠界
 * （ECPay）13 個付款方式與 2 個超商取貨運送方式**完全沒有開放 title 欄位**，
 * 前台結帳頁只能顯示「綠界信用卡」「綠界物流 超商取貨 7-ELEVEN」這種帶著金流商名稱的字串。
 *
 * 留空 = 沿用該方式自己的原生名稱，所以本功能不會跟原生設定打架：原生改得動的照樣去
 * 原生的地方改，這裡只是多一層覆寫。
 *
 * @param string $which 'shipping' 或 'payment'
 * @return array id => 自訂名稱（已過濾掉空字串）
 */
function twshop_get_method_title_overrides( $which ) {
    static $cache = array();
    if ( isset( $cache[ $which ] ) ) return $cache[ $which ];

    $option = ( 'shipping' === $which ) ? 'wc_shipping_method_titles' : 'wc_payment_method_titles';
    $raw    = get_option( $option, array() );

    $cache[ $which ] = is_array( $raw ) ? array_filter( array_map( 'strval', $raw ), 'strlen' ) : array();
    return $cache[ $which ];
}

/**
 * 覆寫結帳／購物車頁的運送方式名稱。
 *
 * **priority 20 是刻意的，必須早於 twshop_apply_free_shipping_rules() 的 100**：
 * 後者會把「(免運費)」之類的規則名稱接在 label 後面。若改名排在它之後，那段後綴會被
 * 整個蓋掉、免運提示消失；排在它之前，後綴才會正確地接在改名後的字串上。
 */
function twshop_rename_shipping_rates( $rates, $package ) {
    $titles = twshop_get_method_title_overrides( 'shipping' );
    if ( empty( $titles ) ) return $rates;

    foreach ( $rates as $rate_id => $rate ) {
        if ( isset( $titles[ $rate_id ] ) ) {
            $rates[ $rate_id ]->label = $titles[ $rate_id ];
        }
    }
    return $rates;
}

/**
 * 覆寫付款方式名稱。
 *
 * 這個過濾器只影響「當下呈現出來的名稱」。已成立的訂單不受影響——WooCommerce 在結帳當下
 * 就把當時的名稱寫進 `_payment_method_title` 存起來了，訂單詳情與通知信讀的是那份快照。
 * 這是刻意不去動的：改名不應該回頭改寫歷史訂單上顧客當初看到的字樣。
 */
function twshop_rename_gateway_title( $title, $gateway_id ) {
    $titles = twshop_get_method_title_overrides( 'payment' );
    return $titles[ $gateway_id ] ?? $title;
}

/**
 * **重入防呆（2026-09 加，實測踩過兩層）**：`WC_Shipping_Zone::get_formatted_location()`
 * 會呼叫不指定國別的 `WC()->countries->get_states()`（取全部國家的州省清單），這會觸發
 * `woocommerce_states` filter；`twshop_add_taiwan_states()` 現在依「運送方式是否勾選地址
 * 連動」決定要不要注入台灣縣市，而這個判斷會呼叫回這支函式掃描運送區域/方式——如果這支
 * 函式本身又是從 `WC_Shipping_Zones::get_zones()` 的呼叫鏈裡面被重新觸發（`get_zones()`
 * 內部組資料時可能觸發 `get_formatted_location()`），就會形成無窮遞迴，實測會把記憶體
 * 一路吃到好幾 GB 才 fatal，且完全不會有任何提示指向本外掛程式碼。
 *
 * **第一層**：用 `$computing` 旗標擋下這支函式自己的重入，重入時直接回傳 `null`
 * （**不是 `array()`**——刻意用 `null` 當「這次拿到的不是真正完整結果」的訊號，
 * 呼叫端要能分辨「重入時的空殼」跟「掃描完、真的沒有任何運送方式」這兩種不同情況）。
 *
 * **第二層（2026-09 補，實測踩過）**：`twshop_get_cvs_method_strings()`／
 * `twshop_get_address_linkage_method_strings()`／`twshop_get_shipping_method_options()`
 * 這幾支「疊在這支函式上面再做一層計算＋各自快取」的函式，如果**不特別處理 `null`**，
 * 而是直接把 `null` 傳給 `foreach` 或當成「掃描到 0 筆」快取起來，會把重入時的不完整結果
 * 誤存成永久快取——即使觸發重入的那個呼叫鏈跟這幾支函式本身完全無關（例如任何其他程式碼
 * 只是呼叫了這支函式一次，途中的 `get_zones()` 剛好又觸發了 `get_states()`），也會讓
 * `twshop_get_address_linkage_method_strings()` 之後整個請求都回傳空清單，**即使管理員
 * 已經在後台正確勾選了「台灣地址下拉選單連動」**，畫面上鄉鎮市區/縣市還是會停在自由輸入
 * 文字——這正是本外掛實際踩到的版本：問題不在勾選有沒有存到 DB，而在「快取被一次意外的
 * 重入污染，之後整個請求都讀到錯的答案」。三支衍生函式都必須檢查 `twshop_get_all_shipping_zone_methods()`
 * 是否回傳 `null`，是的話**直接回傳空結果、但不要設定自己的 `$cache`**，讓下一次（很可能
 * 就是緊接著的下一行程式碼）呼叫能在 `twshop_get_all_shipping_zone_methods()` 真正快取好
 * 之後重新算一次正確答案。
 */
function twshop_get_all_shipping_zone_methods() {
    static $cache = null;
    static $computing = false;
    if ( $cache !== null ) return $cache;
    if ( $computing ) return null;
    $computing = true;

    $flattened = array();
    if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
        $cache = $flattened;
        $computing = false;
        return $cache;
    }

    $zones = WC_Shipping_Zones::get_zones();
    $zones[] = array( 'zone_name' => '其他地區', 'shipping_methods' => WC_Shipping_Zones::get_zone( 0 )->get_shipping_methods() );

    foreach ( $zones as $zone ) {
        foreach ( (array) ( $zone['shipping_methods'] ?? array() ) as $method ) {
            $flattened[] = array(
                'zone_name' => $zone['zone_name'] ?? '',
                'method'    => $method,
            );
        }
    }
    $cache = $flattened;
    $computing = false;
    return $cache;
}

// 取得所有被標記為超商取貨的 method:instance 字串陣列
function twshop_get_cvs_method_strings() {
    static $cache = null;
    if ( $cache !== null ) return $cache;

    $all_methods = twshop_get_all_shipping_zone_methods();
    if ( null === $all_methods ) return array(); // 重入時的空殼，見 twshop_get_all_shipping_zone_methods() 說明；不快取

    $cvs = array();
    foreach ( $all_methods as $entry ) {
        $method = $entry['method'];
        $settings = get_option( 'woocommerce_' . $method->id . '_' . $method->instance_id . '_settings', array() );
        if ( ( $settings['twshop_is_cvs'] ?? 'no' ) === 'yes' ) {
            $cvs[] = $method->id . ':' . $method->instance_id;
        }
    }
    $cache = $cvs;
    return $cache;
}

// 取得所有勾選了「台灣地址下拉選單連動」的 method:instance 字串陣列，寫法跟
// twshop_get_cvs_method_strings() 完全對稱
function twshop_get_address_linkage_method_strings() {
    static $cache = null;
    if ( $cache !== null ) return $cache;

    $all_methods = twshop_get_all_shipping_zone_methods();
    if ( null === $all_methods ) return array(); // 重入時的空殼，見 twshop_get_all_shipping_zone_methods() 說明；不快取

    $linked = array();
    foreach ( $all_methods as $entry ) {
        $method = $entry['method'];
        $settings = get_option( 'woocommerce_' . $method->id . '_' . $method->instance_id . '_settings', array() );
        if ( ( $settings['twshop_is_address_linkage'] ?? 'no' ) === 'yes' ) {
            $linked[] = $method->id . ':' . $method->instance_id;
        }
    }
    $cache = $linked;
    return $cache;
}

// 取得所有已設定的運送方式選項，供「折扣規則」的免運費功能挑選適用對象
// key 為 method_id:instance_id（與 woocommerce_package_rates 的 $rates 陣列 key 格式一致）
function twshop_get_shipping_method_options() {
    static $cache = null;
    if ( $cache !== null ) return $cache;

    $all_methods = twshop_get_all_shipping_zone_methods();
    if ( null === $all_methods ) return array(); // 重入時的空殼，見 twshop_get_all_shipping_zone_methods() 說明；不快取

    $options = array();
    foreach ( $all_methods as $entry ) {
        $method = $entry['method'];
        $key = $method->id . ':' . $method->instance_id;
        $title = method_exists( $method, 'get_title' ) ? $method->get_title() : $method->get_method_title();
        $options[ $key ] = '[' . $entry['zone_name'] . '] ' . $title;
    }
    $cache = $options;
    return $cache;
}

function twshop_is_cvs_shipping_chosen() {
    if ( ! WC()->session ) return false;
    $cvs = twshop_get_cvs_method_strings();
    foreach ( (array) WC()->session->get( 'chosen_shipping_methods' ) as $method ) {
        if ( in_array( $method, $cvs, true ) ) return true;
    }
    return false;
}

/**
 * 目前 session 記得的已選運送方式裡，是否有任一個勾選了「台灣地址下拉選單連動」。
 * 寫法跟 twshop_is_cvs_shipping_chosen() 完全對稱，供 twshop_taiwan_city_field_as_select()
 * 判斷「鄉鎮市區」欄位要不要渲染成下拉選單。
 *
 * **只影響伺服器端首次渲染**：使用者在頁面上第一次選運送方式、或改選運送方式時，
 * session 的 chosen_shipping_methods 要等到 AJAX 更新完才會反映，這個時間點的欄位
 * 型態切換（select ↔ 文字輸入）由前端 twshop-tw-postcode.js 的
 * twshopToggleCityFieldType() 補上，兩邊要保持判斷邏輯一致。
 */
function twshop_is_address_linkage_shipping_chosen() {
    if ( ! WC()->session ) return false;
    $linked = twshop_get_address_linkage_method_strings();
    foreach ( (array) WC()->session->get( 'chosen_shipping_methods' ) as $method ) {
        if ( in_array( $method, $linked, true ) ) return true;
    }
    return false;
}

/**
 * 頁面載入時：若 session 已選超商，移除地址欄位的必填限制
 */
function twshop_cvs_address_optional( $fields ) {
    if ( ! twshop_is_cvs_shipping_chosen() ) return $fields;
    foreach ( array( 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode' ) as $key ) {
        if ( isset( $fields['billing'][ $key ] ) ) {
            $fields['billing'][ $key ]['required'] = false;
        }
    }
    return $fields;
}

/**
 * 送出結帳時：若選超商，移除地址欄位的必填驗證錯誤
 */
function twshop_cvs_remove_address_errors( $data, $errors ) {
    $cvs_methods = twshop_get_cvs_method_strings();
    $is_cvs      = false;
    foreach ( (array) ( $_POST['shipping_method'] ?? array() ) as $method ) { // phpcs:ignore WordPress.Security.NonceVerification
        if ( in_array( sanitize_text_field( $method ), $cvs_methods, true ) ) {
            $is_cvs = true;
            break;
        }
    }
    if ( ! $is_cvs ) return;
    foreach ( array( 'billing_address_1', 'billing_city', 'billing_state', 'billing_postcode' ) as $key ) {
        $errors->remove( $key );
    }
}



/** 單一商品頁三個內建頁籤的 slug（預設順序）。 */
function twshop_product_tab_slugs() {
    return array( 'description', 'additional_information', 'reviews' );
}

/**
 * 讀取商品頁頁籤的順序與啟用狀態，回傳 [ slug => 'yes'|'no' ]（陣列順序即顯示順序）。
 * 沒存過、或存的內容缺 slug 時，缺的補在最後並預設啟用。
 */
function twshop_get_product_tabs_settings() {
    $saved  = get_option( 'wc_product_tabs_settings', array() );
    $result = array();
    if ( is_array( $saved ) && ! empty( $saved['slug'] ) && is_array( $saved['slug'] ) ) {
        foreach ( $saved['slug'] as $i => $slug ) {
            if ( in_array( $slug, twshop_product_tab_slugs(), true ) && ! isset( $result[ $slug ] ) ) {
                $result[ $slug ] = ( ( $saved['enabled'][ $i ] ?? 'yes' ) === 'no' ) ? 'no' : 'yes';
            }
        }
    }
    foreach ( twshop_product_tab_slugs() as $slug ) {
        if ( ! isset( $result[ $slug ] ) ) $result[ $slug ] = 'yes';
    }
    return $result;
}

/**
 * 單一商品頁的頁籤自訂：名稱、是否顯示、順序（描述／額外資訊／評價）。
 * 名稱留空沿用 WooCommerce 原名稱；評價名稱可用 {count} 帶入評價數量。
 * 順序做法：把這三個頁籤原本佔用的 priority 依儲存的順序重新分配，其他外掛新增的頁籤
 * 維持原本的 priority，相對位置不受影響。不綁任何模組開關（純顯示偏好，比照運送／付款方式改名）。
 * priority 98：晚於 WooCommerce 預設頁籤（10）與多數外掛新增的頁籤。
 */
function twshop_customize_product_tab_titles( $tabs ) {
    $titles   = get_option( 'wc_product_tab_titles', array() );
    $settings = twshop_get_product_tabs_settings();

    if ( is_array( $titles ) ) {
        foreach ( twshop_product_tab_slugs() as $key ) {
            if ( empty( $titles[ $key ] ) || ! isset( $tabs[ $key ] ) ) continue;
            $title = (string) $titles[ $key ];
            if ( 'reviews' === $key ) {
                global $product;
                $count = ( $product instanceof WC_Product ) ? (int) $product->get_review_count() : 0;
                $title = str_replace( '{count}', (string) $count, $title );
            }
            $tabs[ $key ]['title'] = $title;
        }
    }

    // 順序：這幾個頁籤原本的 priority 由小到大排好，依儲存順序依序分配回去。
    $present = array_values( array_filter( array_keys( $settings ), function( $slug ) use ( $tabs ) { return isset( $tabs[ $slug ] ); } ) );
    $slots   = array();
    foreach ( $present as $slug ) $slots[] = (int) ( $tabs[ $slug ]['priority'] ?? 10 );
    sort( $slots );
    foreach ( $present as $i => $slug ) $tabs[ $slug ]['priority'] = $slots[ $i ];

    foreach ( $settings as $slug => $enabled ) {
        if ( 'no' === $enabled ) unset( $tabs[ $slug ] );
    }
    return $tabs;
}
