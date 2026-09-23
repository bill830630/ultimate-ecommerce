<?php
/**
 * 儲值金核心：資料表建立與升級、帳本（ledger）唯一寫入入口。
 *
 * 儲值金是真錢，不能像 twshop_add_points_log()（points-engine.php，read-then-write、
 * 完全無鎖、用 max(0,…) 靜默吃掉透支）那樣寫，見 CLAUDE.md「儲值金模組」一節。所有餘額
 * 異動一律經過 twshop_wallet_apply()，用資料庫交易＋SELECT...FOR UPDATE 鎖住該會員的
 * 餘額列，同一會員的並發異動會排隊依序執行、不會互相覆蓋；ref 欄位是冪等鍵（例如
 * `topup:{order_id}`），同一個 ref 重複呼叫只會真正執行一次，之後直接回傳第一次的結果——
 * 用於「付款完成的 webhook 因網路重試被觸發兩次」這類情境，避免重複入帳。
 *
 * v25.8.75 起移除「加贈金」這個獨立追蹤的概念——不再有本金／加贈金之分，只有單一餘額。
 * 資料表欄位刻意保留原本的 `balance_paid`／`amount_paid`／`balance_paid_after` 命名
 * （沒有改名成 `balance`／`amount`），只砍掉 `balance_bonus`／`amount_bonus`／
 * `balance_bonus_after`／`bonus_expire_at` 這幾個欄位——改名要用 `ALTER TABLE ... CHANGE
 * COLUMN`，風險與複雜度都比「刪除不用的欄位」高，且欄位名稱只有這個檔案內部看得到，
 * 不影響任何對外介面，不值得為了命名美觀多冒一次遷移風險。既有站台的既有加贈金餘額在
 * 升級時會自動併入本金欄位，見 `twshop_wallet_migrate_remove_bonus_columns()`。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TWSHOP_WALLET_DB_VERSION', '2.0.0' );

/**
 * 前台顯示的儲值金名稱（「儲值中心 ▸ 設定」可自訂，留空＝「儲值金」），比照 twshop_points_term()。
 */
function twshop_wallet_term() {
    $term = get_option( 'wc_wallet_term_name', '' );
    return '' !== $term ? $term : '儲值金';
}

/**
 * 購物車儲值金折抵區塊的固定文案，{term} 代換成 twshop_wallet_term()。v25.8.109 起不再開放
 * 逐句自訂（原「儲值中心 ▸ 設定 ▸ 儲值金提示文字」面板已移除），資料庫裡舊的 wc_wallet_*_text
 * option 不再讀取。
 */
function twshop_wallet_text( $key ) {
    static $texts = array(
        'ui_heading'            => '使用{term}折抵',
        'balance_text'          => '目前{term}餘額：{amount}',
        'input_placeholder'     => '輸入要折抵的金額',
        'btn_apply_text'        => '套用折抵',
        'btn_update_text'       => '更新折抵',
        'applied_text'          => '本次訂單將折抵 {amount}',
        'no_balance_text'       => '您目前沒有可用的{term}',
        'min_cart_text'         => '購物車需滿 {amount} 才可使用{term}折抵',
        'restricted_text'       => '購物車需包含「{names}」分類/標籤商品才可使用{term}折抵',
        'topup_restricted_text' => '購物車內含{term}商品時，無法使用{term}折抵',
    );
    return str_replace( '{term}', twshop_wallet_term(), $texts[ $key ] ?? '' );
}

function twshop_wallet_balances_table() {
    global $wpdb;
    return $wpdb->prefix . 'twshop_wallet_balances';
}

function twshop_wallet_ledger_table() {
    global $wpdb;
    return $wpdb->prefix . 'twshop_wallet_ledger';
}

/**
 * 建表／升級表結構。比照蝦皮模組 twshop_shopee_install_tables() 的既有慣例：
 * register_activation_hook 涵蓋全新安裝，admin_init 的版本比對涵蓋既有站台的外掛更新
 * （更新外掛不會重新觸發 activation hook，光靠它表結構升級不會套用，且不會有任何錯誤訊息）。
 * dbDelta() 本身是冪等的，重複執行安全。
 *
 * 這裡不受 wallet 模組開關限制——模組關閉只是不掛載功能 hook，建表與否無關，
 * 跟蝦皮模組的既有慣例一致。
 */
function twshop_wallet_install_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $balances_table  = twshop_wallet_balances_table();
    $ledger_table    = twshop_wallet_ledger_table();

    $sql_balances = "CREATE TABLE {$balances_table} (
        user_id BIGINT UNSIGNED NOT NULL,
        balance_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (user_id)
    ) {$charset_collate};";
    dbDelta( $sql_balances );

    $sql_ledger = "CREATE TABLE {$ledger_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        type VARCHAR(20) NOT NULL,
        amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
        balance_paid_after DECIMAL(15,2) NOT NULL DEFAULT 0,
        order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        ref VARCHAR(191) NOT NULL,
        note TEXT NULL,
        created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY ref (ref),
        KEY user_id (user_id),
        KEY order_id (order_id)
    ) {$charset_collate};";
    dbDelta( $sql_ledger );

    twshop_wallet_migrate_remove_bonus_columns();

    update_option( 'twshop_wallet_db_version', TWSHOP_WALLET_DB_VERSION );
}
register_activation_hook( TWSHOP_PLUGIN_FILE, 'twshop_wallet_install_tables' );

/**
 * v25.8.75 一次性遷移：既有站台可能已經有 `balance_bonus`／`amount_bonus`／
 * `balance_bonus_after`／`bonus_expire_at` 這幾個欄位（1.0.0 版本的表結構），且可能已經
 * 累積了真的加贈金餘額。`dbDelta()` 只會「新增缺少的欄位」，不會刪除/改名既有欄位，
 * 所以這幾個欄位不會因為上面 `dbDelta()` 跑完就自動消失，要在這裡手動處理：
 *
 * 1. 把既有 `balance_bonus`／`amount_bonus`／`balance_bonus_after` 的值併入對應的
 *    `_paid` 欄位（沒有任何金額憑空消失，只是不再分開記錄是本金還是加贈金）。
 * 2. 確認欄位真的存在才動作（`information_schema` 查詢），確保這支函式在全新安裝
 *    （一開始就不會建出這些欄位）與已經跑過一次遷移的站台上重複呼叫都安全、無副作用。
 *
 * 用 `SHOW COLUMNS`（而非 `information_schema.COLUMNS` 那種需要額外資料庫權限的查法）
 * 判斷欄位是否存在，跟 WordPress 核心 `dbDelta()` 本身判斷欄位的方式一致。
 */
function twshop_wallet_migrate_remove_bonus_columns() {
    global $wpdb;
    $balances_table = twshop_wallet_balances_table();
    $ledger_table   = twshop_wallet_ledger_table();

    $balances_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$balances_table}" );
    if ( in_array( 'balance_bonus', $balances_columns, true ) ) {
        $wpdb->query( "UPDATE {$balances_table} SET balance_paid = balance_paid + balance_bonus" );
        $wpdb->query( "ALTER TABLE {$balances_table} DROP COLUMN balance_bonus" );
    }

    $ledger_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$ledger_table}" );
    if ( in_array( 'amount_bonus', $ledger_columns, true ) ) {
        $wpdb->query( "UPDATE {$ledger_table} SET amount_paid = amount_paid + amount_bonus" );
        $wpdb->query( "ALTER TABLE {$ledger_table} DROP COLUMN amount_bonus" );
    }
    if ( in_array( 'balance_bonus_after', $ledger_columns, true ) ) {
        $wpdb->query( "UPDATE {$ledger_table} SET balance_paid_after = balance_paid_after + balance_bonus_after" );
        $wpdb->query( "ALTER TABLE {$ledger_table} DROP COLUMN balance_bonus_after" );
    }
    if ( in_array( 'bonus_expire_at', $ledger_columns, true ) ) {
        // 從未有任何程式碼讀寫過這個欄位（見 v25.8.61 加入時的既有註解），直接砍掉、
        // 不需要遷移任何資料。
        $wpdb->query( "ALTER TABLE {$ledger_table} DROP COLUMN bonus_expire_at" );
    }
}

function twshop_wallet_maybe_upgrade_db() {
    if ( get_option( 'twshop_wallet_db_version' ) !== TWSHOP_WALLET_DB_VERSION ) {
        twshop_wallet_install_tables();
    }
}
add_action( 'admin_init', 'twshop_wallet_maybe_upgrade_db' );

/**
 * 讀取某會員目前餘額，回傳單一浮點數。查不到資料列（從未有過任何異動）視為 0，
 * 不主動建立資料列——建立資料列的時機交給 twshop_wallet_apply() 在第一次真正異動時
 * 處理，維持「這支只讀、不寫」的單純語意。
 */
function twshop_wallet_get_balance( $user_id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT balance_paid FROM " . twshop_wallet_balances_table() . " WHERE user_id = %d",
        (int) $user_id
    ), ARRAY_A );

    return $row ? (float) $row['balance_paid'] : 0.0;
}

/**
 * 儲值金餘額異動唯一入口。
 *
 * @param int    $user_id
 * @param float  $delta  異動金額（正數增加／負數扣除）
 * @param string $type   topup／spend／spend_return／topup_revoke／adjust
 * @param string $ref    冪等鍵，同一個 ref 只會真正執行一次（例如 topup:123、spend:456、adjust:<uuid>）
 * @param array  $args   order_id／note／created_by（皆選填）；settle_target／settle_no_charge
 *                       見 twshop_wallet_settle_order()，此時 $delta 與 $type 由鎖內重新計算
 * @return array|WP_Error 成功回傳這筆帳本紀錄（含 balance_paid_after 與 id）；
 *                        餘額不足回傳 WP_Error。
 */
function twshop_wallet_apply( $user_id, $delta, $type, $ref, $args = array() ) {
    global $wpdb;

    $user_id = (int) $user_id;
    $delta   = round( (float) $delta, 2 );
    $ref     = sanitize_text_field( $ref );

    if ( $user_id <= 0 || '' === $ref ) {
        return new WP_Error( 'twshop_wallet_invalid_args', '缺少會員 ID 或冪等鍵（ref）。' );
    }

    $ledger_table   = twshop_wallet_ledger_table();
    $balances_table = twshop_wallet_balances_table();

    // 交易外的快速路徑：多數呼叫本來就不是重複請求，先省一次交易的開銷。
    // 這裡查不到不代表真的可以放行——底下拿到列鎖之後還會再查一次才是真正權威的判斷，
    // 見下方註解。
    $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ledger_table} WHERE ref = %s", $ref ), ARRAY_A );
    if ( $existing ) return $existing;

    $wpdb->query( 'START TRANSACTION' );

    // FOR UPDATE 鎖住這位會員的餘額列：同一會員的並發異動會在這裡排隊，後面的請求
    // 必須等前一個交易 COMMIT/ROLLBACK 才能往下走。
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$balances_table} WHERE user_id = %d FOR UPDATE", $user_id ), ARRAY_A );
    if ( ! $row ) {
        // 這位會員第一次有異動：用 INSERT ... ON DUPLICATE KEY UPDATE（而不是先判斷
        // 「不存在」才 INSERT）避免兩個並發請求都判斷「不存在」而各自嘗試 INSERT
        // 造成主鍵衝突——ON DUPLICATE KEY UPDATE 讓兩者都能安全執行，其中一個是
        // 真正建立、另一個等同無害的自我更新。
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$balances_table} (user_id, balance_paid, updated_at) VALUES (%d, 0, %s)
             ON DUPLICATE KEY UPDATE user_id = user_id",
            $user_id, current_time( 'mysql' )
        ) );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$balances_table} WHERE user_id = %d FOR UPDATE", $user_id ), ARRAY_A );
    }

    // 權威的冪等檢查：必須排在拿到列鎖「之後」，不能只靠交易外那道快速路徑。
    // 理由：如果兩個帶著相同 ref 的並發請求都在交易外查到「不存在」而進了交易，
    // 靠列鎖序列化後，後面那個請求會在這裡重新查到 ref 已經被前一個交易寫入並
    // COMMIT，正確地在「還沒動到餘額」之前就短路回傳，而不是等最後 INSERT 撞到
    // UNIQUE 索引才發現——那樣會變成先錯誤地把餘額異動了一次，才靠 ROLLBACK 撤銷，
    // 邏輯上雖然結果正確但完全依賴交易復原、比較脆弱。這裡假設同一個 ref 永遠對應
    // 同一個 user_id（本模組所有呼叫端的 ref 命名規則皆是如此，例如 topup:{order_id}
    // 綁定單一訂單即單一會員），才能靠這把鎖天然序列化。
    $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ledger_table} WHERE ref = %s", $ref ), ARRAY_A );
    if ( $existing ) {
        $wpdb->query( 'COMMIT' ); // 沒有任何異動，COMMIT 純粹釋放鎖
        return $existing;
    }

    // 訂單結算模式（twshop_wallet_settle_order()）：差額必須在拿到列鎖「之後」才算，
    // 並發的結帳／退款請求才會依序看到彼此的結果，不會各自拿舊狀態算出重疊的差額。
    if ( isset( $args['settle_target'] ) ) {
        $charged = -(float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount_paid),0) FROM {$ledger_table} WHERE order_id = %d AND type IN ('spend','spend_return')",
            (int) ( $args['order_id'] ?? 0 )
        ) );
        $delta = round( $charged - (float) $args['settle_target'], 2 ); // 正數＝退回會員，負數＝向會員扣款
        if ( 0.0 === $delta || ( $delta < 0 && ! empty( $args['settle_no_charge'] ) ) ) {
            $wpdb->query( 'COMMIT' );
            return array( 'noop' => true );
        }
        $type = $delta > 0 ? 'spend_return' : 'spend';
    }

    $new_balance = round( (float) $row['balance_paid'] + $delta, 2 );

    if ( $new_balance < 0 ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'twshop_wallet_insufficient_balance', '儲值金餘額不足，無法完成這筆異動。' );
    }

    $wpdb->update(
        $balances_table,
        array( 'balance_paid' => $new_balance, 'updated_at' => current_time( 'mysql' ) ),
        array( 'user_id' => $user_id ),
        array( '%f', '%s' ),
        array( '%d' )
    );

    $insert = array(
        'user_id'            => $user_id,
        'type'               => sanitize_key( $type ),
        'amount_paid'        => $delta,
        'balance_paid_after' => $new_balance,
        'order_id'           => (int) ( $args['order_id'] ?? 0 ),
        'ref'                => $ref,
        'note'               => sanitize_text_field( $args['note'] ?? '' ),
        'created_by'         => (int) ( $args['created_by'] ?? get_current_user_id() ),
        'created_at'         => current_time( 'mysql' ),
    );
    $formats = array( '%d', '%s', '%f', '%f', '%d', '%s', '%s', '%d', '%s' );

    $inserted = $wpdb->insert( $ledger_table, $insert, $formats );
    if ( false === $inserted ) {
        // 理論上不會發生（上面的權威冪等檢查已經在同一把鎖底下排除了這個情況），
        // 保留這道防線只為了不要讓一次未預期的 DB 錯誤導致餘額被異動卻沒有留下帳本紀錄。
        $wpdb->query( 'ROLLBACK' );
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ledger_table} WHERE ref = %s", $ref ), ARRAY_A );
        return $existing ? $existing : new WP_Error( 'twshop_wallet_ledger_insert_failed', '寫入儲值金帳本失敗，請重新整理頁面後再試。' );
    }
    $insert['id'] = $wpdb->insert_id;

    $wpdb->query( 'COMMIT' );

    return $insert;
}

function twshop_wallet_ledger_has_ref( $ref ) {
    global $wpdb;
    return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . twshop_wallet_ledger_table() . ' WHERE ref = %s', $ref ) );
}

/**
 * 讀取某會員的異動紀錄（新到舊），供會員中心「我的儲值金」與後台個人資料頁共用。
 */
function twshop_wallet_get_ledger( $user_id, $limit = 50, $offset = 0 ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM " . twshop_wallet_ledger_table() . " WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
        (int) $user_id, (int) $limit, (int) $offset
    ), ARRAY_A );
}

function twshop_wallet_get_type_labels() {
    return array(
        'topup'        => '線上儲值',
        'spend'        => '購物折抵',
        'spend_return' => '訂單退回',
        'topup_revoke' => '儲值訂單退款扣回',
        'adjust'       => '後台手動調整',
    );
}

function twshop_wallet_type_label( $type ) {
    $labels = twshop_wallet_get_type_labels();
    return $labels[ $type ] ?? $type;
}

function twshop_wallet_signed_amount( $amount ) {
    $amount = (float) $amount;
    if ( 0.0 === $amount ) return '—';
    return ( $amount > 0 ? '+' : '' ) . number_format( $amount, 2 );
}

/**
 * 後台「儲值金 ▸ 會員餘額」頁的總覽清單：目前有餘額（> 0）的會員，依餘額由高到低排序。
 * 純讀取、不分頁（`$limit` 已經足夠涵蓋一般站台的「有餘額會員」規模，真的需要逐頁瀏覽
 * 全部會員時應該用下方 twshop_wallet_query_ledger() 依會員篩選交易紀錄，而不是在這裡
 * 加分頁）。
 */
function twshop_wallet_get_balances_overview( $limit = 50 ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM " . twshop_wallet_balances_table() . "
         WHERE balance_paid > 0
         ORDER BY balance_paid DESC
         LIMIT %d",
        (int) $limit
    ), ARRAY_A );
}

/**
 * 後台「儲值金 ▸ 交易紀錄」頁用：跨會員、可依會員/類型/日期區間篩選的分頁查詢。
 * 跟 twshop_wallet_get_ledger()（單一會員、無篩選，會員中心與個人資料頁用）是兩支
 * 不同用途的函式，不合併——那支的呼叫端不需要篩選條件，多這些參數反而增加誤用風險。
 *
 * @return array{rows: array, total: int}
 */
function twshop_wallet_query_ledger( $args = array() ) {
    global $wpdb;
    $args = wp_parse_args( $args, array(
        'user_id'   => 0,
        'type'      => '',
        'date_from' => '',
        'date_to'   => '',
        'limit'     => 50,
        'offset'    => 0,
    ) );

    $where        = array( '1=1' );
    $where_params = array();
    if ( $args['user_id'] ) {
        $where[]        = 'user_id = %d';
        $where_params[] = (int) $args['user_id'];
    }
    if ( $args['type'] ) {
        $where[]        = 'type = %s';
        $where_params[] = $args['type'];
    }
    if ( $args['date_from'] ) {
        $where[]        = 'created_at >= %s';
        $where_params[] = $args['date_from'] . ' 00:00:00';
    }
    if ( $args['date_to'] ) {
        $where[]        = 'created_at <= %s';
        $where_params[] = $args['date_to'] . ' 23:59:59';
    }
    $where_sql = implode( ' AND ', $where );
    $table     = twshop_wallet_ledger_table();

    $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
    $total     = (int) ( $where_params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $where_params ) ) : $wpdb->get_var( $count_sql ) );

    $list_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
    $list_params = array_merge( $where_params, array( (int) $args['limit'], (int) $args['offset'] ) );
    $rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

    return array( 'rows' => $rows, 'total' => $total );
}
