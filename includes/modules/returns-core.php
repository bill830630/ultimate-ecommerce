<?php
/**
 * 退換貨模組（returns，v25.8.152 新增）：資料表、狀態機、資格判斷、退款金額計算、照片儲存。
 *
 * 顧客端（會員中心）在 returns-account.php、通知信在 returns-mail.php、後台在 admin/page-returns.php。
 * 第一期範圍：申請／審核／手動寄回單號／WooCommerce 退款（金流端由管理員手動退款）。
 * 點數、儲值金折抵、儲值金商品的退款連動不在這裡寫——建立 WooCommerce 退款時
 * `woocommerce_order_refunded` 上既有的 callback 會自動處理（見 CLAUDE.md「退換貨模組」）。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TWSHOP_RETURNS_DB_VERSION', '1.0.0' );

// =========================================================================
// 資料表（比照 wallet-core.php：activation hook 涵蓋全新安裝，admin_init 版本比對涵蓋外掛更新，
// 兩者都不受模組開關限制）
// =========================================================================

function twshop_returns_table() {
    global $wpdb;
    return $wpdb->prefix . 'twshop_returns';
}

function twshop_returns_install_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table           = twshop_returns_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        type VARCHAR(10) NOT NULL DEFAULT 'return',
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        reason VARCHAR(191) NOT NULL DEFAULT '',
        reason_note TEXT NULL,
        items LONGTEXT NULL,
        photos LONGTEXT NULL,
        exchange_note TEXT NULL,
        return_method VARCHAR(20) NOT NULL DEFAULT '',
        carrier VARCHAR(100) NOT NULL DEFAULT '',
        tracking_no VARCHAR(100) NOT NULL DEFAULT '',
        admin_note TEXT NULL,
        reject_reason TEXT NULL,
        refund_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        refund_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY order_id (order_id),
        KEY user_id (user_id),
        KEY status (status)
    ) {$charset_collate};";
    dbDelta( $sql );

    update_option( 'twshop_returns_db_version', TWSHOP_RETURNS_DB_VERSION );
}
register_activation_hook( TWSHOP_PLUGIN_FILE, 'twshop_returns_install_tables' );

function twshop_returns_maybe_upgrade_db() {
    if ( get_option( 'twshop_returns_db_version' ) !== TWSHOP_RETURNS_DB_VERSION ) {
        twshop_returns_install_tables();
    }
}
add_action( 'admin_init', 'twshop_returns_maybe_upgrade_db' );

// =========================================================================
// 狀態與標籤
// =========================================================================

function twshop_returns_status_labels() {
    return array(
        'pending'   => '待審核',
        'approved'  => '已核准，待寄回',
        'shipped'   => '顧客已寄回',
        'received'  => '已收到退貨',
        'refunded'  => '已退款',
        'exchanged' => '換貨完成',
        'rejected'  => '已拒絕',
        'cancelled' => '顧客已取消',
    );
}

function twshop_returns_status_label( $status, $type = '' ) {
    // 取消訂單申請核准後（取消＋退款）沒有「退貨」的過程，終點文字另外顯示
    if ( 'cancel' === $type && 'refunded' === $status ) return '已取消並退款';
    $labels = twshop_returns_status_labels();
    return $labels[ $status ] ?? $status;
}

function twshop_returns_type_label( $type ) {
    if ( 'cancel' === $type ) return '取消訂單';
    return 'exchange' === $type ? '換貨' : '退貨';
}

/** 佔用「可退數量」的進行中狀態：已拒絕、已取消不佔用；已退款的數量由 WooCommerce 退款紀錄負責。 */
function twshop_returns_active_statuses() {
    return array( 'pending', 'approved', 'shipped', 'received' );
}

/** 狀態轉換表：from => 允許的 to。終點（refunded／exchanged／rejected／cancelled）沒有後續。 */
function twshop_returns_transitions( $type = '' ) {
    // 取消訂單申請沒有寄回流程：待審核 → 核准即取消並退款（refunded）／拒絕／顧客撤回
    if ( 'cancel' === $type ) return array( 'pending' => array( 'refunded', 'rejected', 'cancelled' ) );
    return array(
        'pending'  => array( 'approved', 'rejected', 'cancelled' ),
        'approved' => array( 'shipped', 'received', 'cancelled' ),
        'shipped'  => array( 'received' ),
        'received' => array( 'refunded', 'exchanged' ),
    );
}

// =========================================================================
// 設定讀取
// =========================================================================

function twshop_returns_allowed_order_statuses() {
    $statuses = get_option( 'wc_returns_allowed_statuses', array( 'completed' ) );
    return is_array( $statuses ) && $statuses ? array_values( $statuses ) : array( 'completed' );
}

function twshop_returns_window_days() {
    return max( 0, (int) twshop_option( 'wc_returns_window_days' ) );
}

function twshop_returns_allowed_types() {
    $types = array();
    if ( 'yes' === twshop_option( 'wc_returns_allow_return' ) ) $types[] = 'return';
    if ( 'yes' === twshop_option( 'wc_returns_allow_exchange' ) ) $types[] = 'exchange';
    return $types;
}

/** 原因清單：後台設定一行一個；沒設定時用預設清單。 */
function twshop_returns_reasons() {
    $lines = preg_split( '/\r\n|\r|\n/', (string) twshop_option( 'wc_returns_reasons' ) );
    $lines = array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
    return $lines ?: array( '其他' );
}

function twshop_returns_max_photos() {
    return max( 0, min( 10, (int) twshop_option( 'wc_returns_max_photos' ) ) );
}

// =========================================================================
// 資料存取
// =========================================================================

function twshop_returns_decode_row( $row ) {
    if ( ! $row ) return null;
    $row['id']            = (int) $row['id'];
    $row['order_id']      = (int) $row['order_id'];
    $row['user_id']       = (int) $row['user_id'];
    $row['refund_id']     = (int) $row['refund_id'];
    $row['refund_amount'] = (float) $row['refund_amount'];
    $items  = json_decode( (string) $row['items'], true );
    $photos = json_decode( (string) $row['photos'], true );
    $row['items']  = is_array( $items ) ? $items : array();
    $row['photos'] = is_array( $photos ) ? $photos : array();
    return $row;
}

function twshop_returns_get( $id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . twshop_returns_table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
    return twshop_returns_decode_row( $row );
}

function twshop_returns_for_order( $order_id ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . twshop_returns_table() . ' WHERE order_id = %d ORDER BY id DESC', (int) $order_id ), ARRAY_A );
    return array_map( 'twshop_returns_decode_row', (array) $rows );
}

/**
 * 查詢列表。$args：user_id、order_id、status、type、limit、offset、count_total。
 * count_total 預設 true；不需分頁總數時傳 false，省略 COUNT 查詢，total 回傳 null。
 */
function twshop_returns_query( $args = array() ) {
    global $wpdb;
    $table = twshop_returns_table();
    $where = array( '1=1' );
    $vals  = array();
    foreach ( array( 'user_id' => '%d', 'order_id' => '%d' ) as $col => $fmt ) {
        if ( ! empty( $args[ $col ] ) ) { $where[] = "{$col} = {$fmt}"; $vals[] = (int) $args[ $col ]; }
    }
    foreach ( array( 'status', 'type' ) as $col ) {
        if ( ! empty( $args[ $col ] ) ) { $where[] = "{$col} = %s"; $vals[] = (string) $args[ $col ]; }
    }
    $where_sql = implode( ' AND ', $where );
    $limit     = max( 1, (int) ( $args['limit'] ?? 50 ) );
    $offset    = max( 0, (int) ( $args['offset'] ?? 0 ) );

    $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
    $list_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
    $total = null;
    if ( $args['count_total'] ?? true ) {
        $total = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) );
    }
    $rows  = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $vals, array( $limit, $offset ) ) ), ARRAY_A );
    return array( 'rows' => array_map( 'twshop_returns_decode_row', (array) $rows ), 'total' => $total );
}

function twshop_returns_insert( array $data ) {
    global $wpdb;
    $now = current_time( 'mysql' );
    $ok  = $wpdb->insert( twshop_returns_table(), array(
        'order_id'      => (int) $data['order_id'],
        'user_id'       => (int) $data['user_id'],
        'type'          => $data['type'],
        'status'        => 'pending',
        'reason'        => $data['reason'],
        'reason_note'   => $data['reason_note'] ?? '',
        'items'         => wp_json_encode( $data['items'] ?? array() ),
        'photos'        => wp_json_encode( $data['photos'] ?? array() ),
        'exchange_note' => $data['exchange_note'] ?? '',
        'created_at'    => $now,
        'updated_at'    => $now,
    ) );
    return $ok ? (int) $wpdb->insert_id : 0;
}

/** 更新欄位；items／photos 傳陣列會自動轉 JSON。 */
function twshop_returns_update( $id, array $fields ) {
    global $wpdb;
    foreach ( array( 'items', 'photos' ) as $json_col ) {
        if ( isset( $fields[ $json_col ] ) && is_array( $fields[ $json_col ] ) ) $fields[ $json_col ] = wp_json_encode( $fields[ $json_col ] );
    }
    $fields['updated_at'] = current_time( 'mysql' );
    return false !== $wpdb->update( twshop_returns_table(), $fields, array( 'id' => (int) $id ) );
}

// =========================================================================
// 資格判斷
// =========================================================================

/** 進行中的申請已佔用的數量：[ order_item_id => qty ]。 */
function twshop_returns_occupied_qty( $order_id, $exclude_return_id = 0 ) {
    $occupied = array();
    foreach ( twshop_returns_for_order( $order_id ) as $row ) {
        if ( (int) $row['id'] === (int) $exclude_return_id ) continue;
        if ( ! in_array( $row['status'], twshop_returns_active_statuses(), true ) ) continue;
        foreach ( $row['items'] as $it ) {
            $iid = (int) ( $it['item_id'] ?? 0 );
            $occupied[ $iid ] = ( $occupied[ $iid ] ?? 0 ) + (int) ( $it['qty'] ?? 0 );
        }
    }
    return $occupied;
}

/** 這個商品（含規格的父商品）是否被標示為不可退換（商品本身勾選，或屬於設定的不可退換分類）。 */
function twshop_returns_product_excluded( $product_id ) {
    $product_id = (int) $product_id;
    $product    = wc_get_product( $product_id );
    if ( $product && 'yes' === $product->get_meta( '_twshop_no_return' ) ) return true;
    $slugs = get_option( 'wc_returns_excluded_cats', array() );
    if ( is_array( $slugs ) && $slugs && function_exists( 'twshop_has_term_cached' ) && twshop_has_term_cached( $slugs, 'product_cat', $product_id ) ) return true;
    return false;
}

/**
 * 訂單的申請資格。回傳 [ 'ok' => bool, 'message' => string, 'items' => [ order_item_id => [ 'name', 'available', 'item' ] ] ]。
 * $user_id > 0 時同時驗證訂單屬於該會員。
 */
function twshop_returns_order_eligibility( $order, $user_id = 0, $exclude_return_id = 0 ) {
    $fail = function ( $msg ) { return array( 'ok' => false, 'message' => $msg, 'items' => array() ); };

    if ( ! $order instanceof WC_Order ) return $fail( '找不到這張訂單。' );
    if ( ! twshop_order_feature_enabled( 'returns' ) ) return $fail( '目前未開放新的申請，既有申請仍可查看與處理。' );
    if ( $user_id && (int) $order->get_customer_id() !== (int) $user_id ) return $fail( '找不到這張訂單。' );
    if ( ! twshop_returns_allowed_types() ) return $fail( '目前未開放退換貨申請。' );
    if ( ! in_array( $order->get_status(), twshop_returns_allowed_order_statuses(), true ) ) return $fail( '這張訂單目前的狀態無法申請退換貨。' );

    $completed = $order->get_date_completed();
    if ( ! $completed ) return $fail( '這張訂單尚未完成，無法申請退換貨。' );
    $days = twshop_returns_window_days();
    if ( $days > 0 && ( time() - $completed->getTimestamp() ) > $days * DAY_IN_SECONDS ) {
        return $fail( sprintf( '已超過申請期限（收貨後 %d 天內）。', $days ) );
    }

    $occupied = twshop_returns_occupied_qty( $order->get_id(), $exclude_return_id );
    $items    = array();
    foreach ( $order->get_items() as $item_id => $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) continue;
        $product = $item->get_product();
        if ( $product && function_exists( 'twshop_is_wallet_credit_product' ) && twshop_is_wallet_credit_product( $product ) ) continue; // 儲值金商品不可退
        if ( (float) $item->get_total() <= 0 ) continue; // 贈品／兌換品（實付 $0）沒有可退的金額
        if ( twshop_returns_product_excluded( $item->get_product_id() ) ) continue;

        $refunded  = abs( (int) $order->get_qty_refunded_for_item( $item_id ) );
        $available = (int) $item->get_quantity() - $refunded - (int) ( $occupied[ $item_id ] ?? 0 );
        if ( $available <= 0 ) continue;
        $items[ $item_id ] = array( 'name' => $item->get_name(), 'available' => $available, 'item' => $item );
    }
    if ( ! $items ) return $fail( '這張訂單沒有可以申請退換貨的商品。' );

    return array( 'ok' => true, 'message' => '', 'items' => $items );
}

// =========================================================================
// 取消訂單申請（v25.8.153）：還沒出貨（付款後、處理中）的訂單，顧客申請取消，店家審核後取消並退款
// =========================================================================

function twshop_returns_cancel_enabled() {
    return 'yes' === twshop_option( 'wc_returns_allow_cancel' );
}

/**
 * 取消訂單資格：開關開啟、訂單本人、狀態是「處理中」（已付款、未出貨；已出貨／配送中／已完成都不行，
 * 要收到貨才能走退換貨）、沒有進行中的申請、不含儲值金商品。整張訂單取消，不選品項。
 * 回傳 array( ok, message, items )，items 是全部商品（寫進申請供審核面板顯示）。
 */
function twshop_returns_cancel_eligibility( $order, $user_id = 0 ) {
    $fail = function ( $msg ) { return array( 'ok' => false, 'message' => $msg, 'items' => array() ); };

    if ( ! $order instanceof WC_Order ) return $fail( '找不到這張訂單。' );
    if ( ! twshop_order_feature_enabled( 'returns' ) ) return $fail( '目前未開放新的申請，既有申請仍可查看與處理。' );
    if ( $user_id && (int) $order->get_customer_id() !== (int) $user_id ) return $fail( '找不到這張訂單。' );
    if ( ! twshop_returns_cancel_enabled() ) return $fail( '目前未開放申請取消訂單。' );
    if ( 'processing' !== $order->get_status() ) return $fail( '這張訂單目前的狀態無法申請取消（已出貨的訂單請在收到商品後申請退換貨）。' );

    foreach ( twshop_returns_for_order( $order->get_id() ) as $existing ) {
        if ( in_array( $existing['status'], twshop_returns_active_statuses(), true ) ) return $fail( '這張訂單已經有進行中的申請。' );
    }

    $items = array();
    foreach ( $order->get_items() as $item_id => $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) continue;
        $product = $item->get_product();
        if ( $product && function_exists( 'twshop_is_wallet_credit_product' ) && twshop_is_wallet_credit_product( $product ) ) {
            return $fail( '含儲值金商品的訂單無法線上申請取消，請聯絡店家。' );
        }
        $items[] = array(
            'item_id'      => (int) $item_id,
            'product_id'   => (int) $item->get_product_id(),
            'variation_id' => (int) $item->get_variation_id(),
            'name'         => $item->get_name(),
            'qty'          => (int) $item->get_quantity(),
        );
    }
    if ( ! $items ) return $fail( '這張訂單沒有商品，無法申請取消。' );

    return array( 'ok' => true, 'message' => '', 'items' => $items );
}

/** 建立取消訂單申請。$input：reason、reason_note。成功回傳申請 ID，失敗回傳 WP_Error。 */
function twshop_returns_create_cancel( $order, $user_id, array $input ) {
    $elig = twshop_returns_cancel_eligibility( $order, $user_id );
    if ( ! $elig['ok'] ) return new WP_Error( 'ineligible', $elig['message'] );

    $reason = trim( (string) ( $input['reason'] ?? '' ) );
    if ( ! in_array( $reason, twshop_returns_reasons(), true ) ) return new WP_Error( 'reason', '請選擇申請原因。' );

    $id = twshop_returns_insert( array(
        'order_id'    => $order->get_id(),
        'user_id'     => (int) $user_id,
        'type'        => 'cancel',
        'reason'      => $reason,
        'reason_note' => sanitize_textarea_field( (string) ( $input['reason_note'] ?? '' ) ),
        'items'       => $elig['items'],
    ) );
    if ( ! $id ) return new WP_Error( 'db', '申請送出失敗，請稍後再試。' );

    twshop_returns_log( $order, $id, sprintf( '顧客送出取消訂單申請（原因：%s）。', $reason ) );
    do_action( 'twshop_returns_created', $id );
    return $id;
}

// =========================================================================
// 建立申請
// =========================================================================

/**
 * 建立申請。$input：type、reason、reason_note、exchange_note、qty[order_item_id]。
 * $photos：已通過驗證並存好的檔名陣列。成功回傳申請 ID，失敗回傳 WP_Error。
 */
function twshop_returns_create( $order, $user_id, array $input, array $photos = array() ) {
    if ( 'cancel' === ( $input['type'] ?? '' ) ) return twshop_returns_create_cancel( $order, $user_id, $input );

    $elig = twshop_returns_order_eligibility( $order, $user_id );
    if ( ! $elig['ok'] ) return new WP_Error( 'ineligible', $elig['message'] );

    $type = 'exchange' === ( $input['type'] ?? '' ) ? 'exchange' : 'return';
    if ( ! in_array( $type, twshop_returns_allowed_types(), true ) ) return new WP_Error( 'type', '目前未開放這種申請類型。' );

    $reason = trim( (string) ( $input['reason'] ?? '' ) );
    if ( ! in_array( $reason, twshop_returns_reasons(), true ) ) return new WP_Error( 'reason', '請選擇申請原因。' );

    $qty_input = (array) ( $input['qty'] ?? array() );
    $items     = array();
    foreach ( $elig['items'] as $item_id => $info ) {
        $q = absint( $qty_input[ $item_id ] ?? 0 );
        if ( $q <= 0 ) continue;
        if ( $q > $info['available'] ) return new WP_Error( 'qty', sprintf( '「%s」最多只能申請 %d 件。', $info['name'], $info['available'] ) );
        $item = $info['item'];
        $items[] = array(
            'item_id'      => (int) $item_id,
            'product_id'   => (int) $item->get_product_id(),
            'variation_id' => (int) $item->get_variation_id(),
            'name'         => $info['name'],
            'qty'          => $q,
        );
    }
    if ( ! $items ) return new WP_Error( 'items', '請選擇要申請的商品與數量。' );

    $exchange_note = trim( (string) ( $input['exchange_note'] ?? '' ) );
    if ( 'exchange' === $type && '' === $exchange_note ) return new WP_Error( 'exchange_note', '請填寫想換成的規格或商品。' );

    if ( 'yes' === twshop_option( 'wc_returns_photos_required' ) && ! $photos ) return new WP_Error( 'photos', '請上傳商品照片。' );

    $id = twshop_returns_insert( array(
        'order_id'      => $order->get_id(),
        'user_id'       => (int) $user_id,
        'type'          => $type,
        'reason'        => $reason,
        'reason_note'   => sanitize_textarea_field( (string) ( $input['reason_note'] ?? '' ) ),
        'items'         => $items,
        'photos'        => $photos,
        'exchange_note' => sanitize_textarea_field( $exchange_note ),
    ) );
    if ( ! $id ) return new WP_Error( 'db', '申請送出失敗，請稍後再試。' );

    twshop_returns_log( $order, $id, sprintf( '顧客送出%s申請（原因：%s）。', twshop_returns_type_label( $type ), $reason ) );
    do_action( 'twshop_returns_created', $id );
    return $id;
}

/** 每次狀態變化／重要動作寫一筆訂單備註（稽核軌跡）。 */
function twshop_returns_log( $order, $return_id, $text ) {
    if ( $order instanceof WC_Order ) $order->add_order_note( sprintf( '退換貨申請 #%d：%s', (int) $return_id, $text ) );
}

// =========================================================================
// 狀態機
// =========================================================================

/**
 * 轉換狀態。$fields 是同時要更新的欄位（admin_note、reject_reason、carrier、tracking_no…）。
 * 成功回傳 true，失敗回傳 WP_Error。「refunded」只能由 twshop_returns_do_refund() 呼叫。
 */
function twshop_returns_transition( $id, $to, array $fields = array(), $note = '' ) {
    $row = twshop_returns_get( $id );
    if ( ! $row ) return new WP_Error( 'missing', '找不到這筆申請。' );

    $allowed = twshop_returns_transitions( $row['type'] )[ $row['status'] ] ?? array();
    if ( ! in_array( $to, $allowed, true ) ) {
        return new WP_Error( 'transition', sprintf( '「%s」無法直接變更為「%s」。', twshop_returns_status_label( $row['status'], $row['type'] ), twshop_returns_status_label( $to, $row['type'] ) ) );
    }
    if ( 'refunded' === $to && ! in_array( $row['type'], array( 'return', 'cancel' ), true ) ) return new WP_Error( 'transition', '只有退貨或取消訂單申請可以標記為已退款。' );
    if ( 'exchanged' === $to && 'exchange' !== $row['type'] ) return new WP_Error( 'transition', '只有換貨申請可以標記為換貨完成。' );

    $fields['status'] = $to;
    if ( ! twshop_returns_update( $id, $fields ) ) return new WP_Error( 'db', '更新失敗，請稍後再試。' );

    $order = wc_get_order( $row['order_id'] );
    twshop_returns_log( $order, $id, $note ?: sprintf( '狀態變更為「%s」。', twshop_returns_status_label( $to ) ) );
    do_action( 'twshop_returns_status_changed', $id, $to, $row['status'] );
    return true;
}

// =========================================================================
// 退款金額計算與執行
// =========================================================================

/**
 * 依申請的品項算出退款內容。回傳：
 *  line_items   wc_create_refund() 的 line_items（已依上限等比例縮小）
 *  amount       最終退款金額（含稅，已套上限）
 *  raw_amount   未套上限的金額（品項實付＋選擇的運費）
 *  remaining    訂單剩餘可退金額（get_remaining_refund_amount）
 *  items_amount / shipping_amount 兩部分各自的金額（未套上限）
 * 金額邏輯：每個品項取「實付金額（含稅）× 申請數量 ÷ 原購買數量」；上限是訂單剩餘可退金額——
 * 用點數／儲值金折抵的訂單，get_total() 已經扣掉折抵（折抵是負的費用），可退的現金比品項加總少，
 * 超出的部分由既有的點數／儲值金退款 callback 依比例退回。
 */
function twshop_returns_calc_refund( $order, array $return_row, $include_shipping = false ) {
    $dec        = wc_get_price_decimals();
    $line_items = array();
    $items_amt  = 0.0;

    foreach ( $return_row['items'] as $it ) {
        $item = $order->get_item( (int) $it['item_id'] );
        if ( ! $item instanceof WC_Order_Item_Product ) continue;
        $orig_qty = max( 1, (int) $item->get_quantity() );
        $ratio    = (int) $it['qty'] / $orig_qty;
        $total    = round( (float) $item->get_total() * $ratio, $dec );
        $taxes    = array();
        $tax_sum  = 0.0;
        foreach ( (array) ( $item->get_taxes()['total'] ?? array() ) as $rate_id => $tax ) {
            $t = round( (float) $tax * $ratio, $dec );
            $taxes[ $rate_id ] = $t;
            $tax_sum += $t;
        }
        $line_items[ $item->get_id() ] = array( 'qty' => (int) $it['qty'], 'refund_total' => $total, 'refund_tax' => $taxes );
        $items_amt += $total + $tax_sum;
    }

    $ship_amt = 0.0;
    if ( $include_shipping ) {
        foreach ( $order->get_items( 'shipping' ) as $ship_id => $ship ) {
            $already  = abs( (float) $order->get_total_refunded_for_item( $ship_id, 'shipping' ) );
            $total    = max( 0, round( (float) $ship->get_total() - $already, $dec ) );
            $taxes    = array();
            $tax_sum  = 0.0;
            foreach ( (array) ( $ship->get_taxes()['total'] ?? array() ) as $rate_id => $tax ) {
                $t = max( 0, round( (float) $tax - abs( (float) $order->get_tax_refunded_for_item( $ship_id, $rate_id, 'shipping' ) ), $dec ) );
                $taxes[ $rate_id ] = $t;
                $tax_sum += $t;
            }
            if ( $total <= 0 && ! $tax_sum ) continue;
            $line_items[ $ship_id ] = array( 'qty' => 0, 'refund_total' => $total, 'refund_tax' => $taxes );
            $ship_amt += $total + $tax_sum;
        }
    }

    $raw       = round( $items_amt + $ship_amt, $dec );
    $remaining = round( (float) $order->get_remaining_refund_amount(), $dec );
    $final     = min( $raw, $remaining );

    // 套上限時，line_items 的金額也等比例縮小，讓退款紀錄裡的品項金額加總跟退款金額一致
    if ( $raw > 0 && $final < $raw ) {
        $scale = $final / $raw;
        foreach ( $line_items as $iid => $li ) {
            $line_items[ $iid ]['refund_total'] = round( $li['refund_total'] * $scale, $dec );
            foreach ( $li['refund_tax'] as $rate_id => $t ) $line_items[ $iid ]['refund_tax'][ $rate_id ] = round( $t * $scale, $dec );
        }
    }

    return array(
        'line_items'      => $line_items,
        'amount'          => max( 0, $final ),
        'raw_amount'      => $raw,
        'remaining'       => $remaining,
        'items_amount'    => round( $items_amt, $dec ),
        'shipping_amount' => round( $ship_amt, $dec ),
    );
}

/**
 * 金流端退款：交給 filter 上的執行器（內建：綠界信用卡退刷，returns-ecpay-refund.php），回傳寫進訂單備註的說明。
 * 失敗不影響已建立的 WC 退款，只提示手動處理。
 */
function twshop_returns_gateway_refund_note( $order, $amount, $refund ) {
    $gateway = apply_filters( 'twshop_returns_refund_executor', null, $order, $amount, $refund );
    if ( ! is_array( $gateway ) || empty( $gateway['status'] ) ) $gateway = array( 'status' => 'skipped', 'message' => '' );
    if ( 'ok' === $gateway['status'] ) return $gateway['message'];
    if ( 'failed' === $gateway['status'] ) return '⚠️ ' . $gateway['message'] . '請到金流後台手動處理。';
    return '⚠️ 金流端的退款請到金流後台手動處理。';
}

/**
 * 建立 WooCommerce 退款並把申請標為「已退款」。$opts：amount（管理員覆寫金額，留空用計算值）、
 * include_shipping、restock。金流端的退款第一期由管理員手動處理（綠界外掛不支援自動退款），
 * 這裡 refund_payment 固定 false。點數／儲值金／儲值金商品由 woocommerce_order_refunded 上
 * 既有的 callback 自動連動。成功回傳退款 ID，失敗回傳 WP_Error。
 */
function twshop_returns_do_refund( $id, array $opts = array() ) {
    $row = twshop_returns_get( $id );
    if ( ! $row ) return new WP_Error( 'missing', '找不到這筆申請。' );
    if ( 'received' !== $row['status'] || 'return' !== $row['type'] ) return new WP_Error( 'status', '只有「已收到退貨」的退貨申請可以建立退款。' );

    $order = wc_get_order( $row['order_id'] );
    if ( ! $order instanceof WC_Order ) return new WP_Error( 'order', '找不到這張訂單。' );

    $calc = twshop_returns_calc_refund( $order, $row, ! empty( $opts['include_shipping'] ) );
    if ( $calc['remaining'] <= 0 ) {
        return new WP_Error( 'zero', '這張訂單已經沒有可退的現金（可能全額以點數或儲值金支付，或已全數退款）。請改到訂單編輯頁手動處理退款與點數／儲值金。' );
    }

    $amount = isset( $opts['amount'] ) && '' !== $opts['amount'] ? round( (float) $opts['amount'], wc_get_price_decimals() ) : $calc['amount'];
    if ( $amount <= 0 ) return new WP_Error( 'amount', '退款金額必須大於 0。' );
    if ( $amount > $calc['remaining'] ) return new WP_Error( 'amount', sprintf( '退款金額不能超過訂單剩餘可退金額（%s）。', twshop_plain_price( $calc['remaining'] ) ) );

    $refund = wc_create_refund( array(
        'amount'         => $amount,
        'reason'         => sprintf( '退換貨申請 #%d', $id ),
        'order_id'       => $order->get_id(),
        'line_items'     => $calc['line_items'],
        'refund_payment' => false,
        'restock_items'  => ! empty( $opts['restock'] ),
    ) );
    if ( is_wp_error( $refund ) ) return $refund;

    $gateway_note = twshop_returns_gateway_refund_note( $order, $amount, $refund );

    $result = twshop_returns_transition(
        $id,
        'refunded',
        array( 'refund_id' => $refund->get_id(), 'refund_amount' => $amount ),
        sprintf( '已建立退款 %s（退款單 #%d）。%s', twshop_plain_price( $amount ), $refund->get_id(), $gateway_note )
    );
    return is_wp_error( $result ) ? $result : $refund->get_id();
}

/**
 * 核准取消訂單申請：把整張訂單全額退款（綠界信用卡自動退刷）並取消。$opts：restock（預設 true）。
 * 訂單申請後可能已出貨，執行前重新確認狀態仍是「處理中」。沒有現金可退（全額點數／儲值金付款）時不建退款，
 * 直接取消訂單，由既有的取消 hook 退回點數／儲值金。成功回傳退款 ID（沒有退款時回傳 0），失敗回傳 WP_Error。
 */
function twshop_returns_do_cancel( $id, array $opts = array() ) {
    $row = twshop_returns_get( $id );
    if ( ! $row ) return new WP_Error( 'missing', '找不到這筆申請。' );
    if ( 'cancel' !== $row['type'] || 'pending' !== $row['status'] ) return new WP_Error( 'status', '只有「待審核」的取消訂單申請可以核准。' );

    $order = wc_get_order( $row['order_id'] );
    if ( ! $order instanceof WC_Order ) return new WP_Error( 'order', '找不到這張訂單。' );
    if ( 'processing' !== $order->get_status() ) {
        return new WP_Error( 'order_status', '這張訂單已經不是「處理中」（可能已出貨或已處理），無法取消。請拒絕這筆申請，並請顧客在收到商品後改申請退換貨。' );
    }

    // 綠界物流單一律由管理員到綠界後台取消（各家超商、宅配的取消方式不同，不做自動取消，也不讓不同物流行為不一致）
    $ship_note = '' !== (string) $order->get_meta( '_wooecpay_logistic_AllPayLogisticsID' )
        ? '⚠️ 這張訂單已建立綠界物流單，請到綠界後台取消物流單。'
        : '';

    $dec       = wc_get_price_decimals();
    $remaining = round( (float) $order->get_remaining_refund_amount(), $dec );
    $restock   = ! array_key_exists( 'restock', $opts ) || ! empty( $opts['restock'] );
    $refund_id = 0;
    $amount    = 0.0;
    $note      = '已核准取消訂單。';

    if ( $remaining > 0 ) {
        $calc   = twshop_returns_calc_refund( $order, $row, true );
        $amount = $remaining;
        $refund = wc_create_refund( array(
            'amount'         => $amount,
            'reason'         => sprintf( '取消訂單申請 #%d', $id ),
            'order_id'       => $order->get_id(),
            'line_items'     => $calc['line_items'],
            'refund_payment' => false,
            'restock_items'  => $restock,
        ) );
        if ( is_wp_error( $refund ) ) return $refund;
        $refund_id = $refund->get_id();
        $note      = sprintf( '已核准取消訂單並建立退款 %s（退款單 #%d）。%s', twshop_plain_price( $amount ), $refund_id, twshop_returns_gateway_refund_note( $order, $amount, $refund ) );
        $note     .= $ship_note ? ' ' . $ship_note : '';
        $order     = wc_get_order( $order->get_id() ); // 全額退款時 WooCommerce 會把狀態轉成「已退款」
    } else {
        $note = '已核准取消訂單（訂單沒有需要退回的現金，點數／儲值金折抵由系統退回）。' . ( $ship_note ? ' ' . $ship_note : '' );
    }

    if ( $order instanceof WC_Order && ! in_array( $order->get_status(), array( 'refunded', 'cancelled' ), true ) ) {
        $order->update_status( 'cancelled', sprintf( '取消訂單申請 #%d 已核准。', $id ) );
    }

    $result = twshop_returns_transition( $id, 'refunded', array( 'refund_id' => $refund_id, 'refund_amount' => $amount ), $note );
    return is_wp_error( $result ) ? $result : $refund_id;
}

// =========================================================================
// 照片（外掛原本沒有前台上傳，這裡從零設計）
//   - 只收 jpg／png／webp，單張 ≤ 5MB，張數上限由設定決定
//   - 存 uploads/twshop-returns/ 下的隨機檔名，並放 index.php 與 .htaccess 擋目錄瀏覽／直連
//   - 顧客與管理員只能透過 twshop_returns_serve_photo() 這支需驗證身分的 handler 看
// =========================================================================

function twshop_returns_photo_dir() {
    $upload = wp_upload_dir();
    return trailingslashit( $upload['basedir'] ) . 'twshop-returns/';
}

function twshop_returns_ensure_photo_dir() {
    $dir = twshop_returns_photo_dir();
    if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
    if ( is_dir( $dir ) ) {
        if ( ! file_exists( $dir . 'index.php' ) ) file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
        if ( ! file_exists( $dir . '.htaccess' ) ) file_put_contents( $dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
    }
    return $dir;
}

function twshop_returns_photo_mimes() {
    return array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
}

/**
 * 驗證並儲存單張照片。$mover 預設 move_uploaded_file（測試時傳 'copy' 之類的 callable）。
 * 成功回傳儲存後的檔名，失敗回傳 WP_Error。
 */
function twshop_returns_store_photo( $tmp_name, $orig_name, $size, $error = UPLOAD_ERR_OK, $mover = 'move_uploaded_file' ) {
    if ( UPLOAD_ERR_OK !== (int) $error ) return new WP_Error( 'upload', '照片上傳失敗，請再試一次。' );
    if ( (int) $size > 5 * MB_IN_BYTES ) return new WP_Error( 'size', '每張照片不能超過 5MB。' );

    $check = wp_check_filetype_and_ext( $tmp_name, $orig_name, twshop_returns_photo_mimes() );
    if ( empty( $check['ext'] ) || empty( $check['type'] ) ) return new WP_Error( 'type', '照片只接受 JPG、PNG、WebP 格式。' );
    if ( ! @getimagesize( $tmp_name ) ) return new WP_Error( 'type', '照片檔案無法辨識。' );

    $dir = twshop_returns_ensure_photo_dir();
    if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) return new WP_Error( 'dir', '照片儲存失敗（目錄無法寫入）。' );

    $filename = bin2hex( random_bytes( 16 ) ) . '.' . $check['ext'];
    if ( ! call_user_func( $mover, $tmp_name, $dir . $filename ) ) return new WP_Error( 'move', '照片儲存失敗。' );
    return $filename;
}

/** 照片的驗證連結（需登入；handler 再檢查是本人申請或管理員）。 */
function twshop_returns_photo_url( $return_id, $filename ) {
    return wp_nonce_url(
        add_query_arg( array( 'action' => 'twshop_return_photo', 'rid' => (int) $return_id, 'f' => $filename ), admin_url( 'admin-post.php' ) ),
        'twshop_return_photo_' . (int) $return_id . '_' . $filename
    );
}

function twshop_returns_serve_photo() {
    $rid      = absint( $_GET['rid'] ?? 0 );
    $filename = sanitize_file_name( wp_unslash( $_GET['f'] ?? '' ) );
    if ( ! $rid || '' === $filename || ! is_user_logged_in() ) wp_die( '無權限。', '', array( 'response' => 403 ) );
    check_admin_referer( 'twshop_return_photo_' . $rid . '_' . $filename );

    $row = twshop_returns_get( $rid );
    if ( ! $row || ! in_array( $filename, $row['photos'], true ) ) wp_die( '找不到照片。', '', array( 'response' => 404 ) );
    if ( ! current_user_can( 'manage_woocommerce' ) && (int) $row['user_id'] !== get_current_user_id() ) wp_die( '無權限。', '', array( 'response' => 403 ) );

    $path = twshop_returns_photo_dir() . $filename;
    if ( ! is_file( $path ) ) wp_die( '找不到照片。', '', array( 'response' => 404 ) );
    $type = wp_check_filetype( $filename, twshop_returns_photo_mimes() );
    nocache_headers();
    header( 'Content-Type: ' . ( $type['type'] ?: 'application/octet-stream' ) );
    header( 'Content-Length: ' . filesize( $path ) );
    header( 'X-Content-Type-Options: nosniff' );
    readfile( $path );
    exit;
}

// =========================================================================
// 會員等級消費額：退款後重算（所有走 WooCommerce 退款的訂單都適用，不只退換貨）
// =========================================================================

function twshop_refresh_tier_after_refund( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order instanceof WC_Order ) return;
    $user_id = (int) $order->get_customer_id();
    if ( ! $user_id ) return;
    twshop_clear_user_spent_cache( $user_id );
    twshop_recalculate_user_tier( $user_id );
}
