<?php
/**
 * 團購主分潤：管理員指定團購主，團員經專屬頁或 ?gb= 連結取得指定商品團購價，
 * 訂單完成後依實付金額比例記錄現金佣金，管理員線下匯款後標記結算。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TWSHOP_GROUP_BUY_DB_VERSION', '1.5.0' );

function twshop_group_buy_tables() {
    global $wpdb;
    return array(
        'leaders'     => $wpdb->prefix . 'twshop_group_leaders',
        'products'    => $wpdb->prefix . 'twshop_group_leader_products',
        'commissions' => $wpdb->prefix . 'twshop_group_commissions',
        'settlements' => $wpdb->prefix . 'twshop_group_settlements',
        'prices'      => $wpdb->prefix . 'twshop_group_leader_prices',
    );
}

function twshop_group_buy_install_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = twshop_group_buy_tables();
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$t['leaders']} (
        user_id BIGINT UNSIGNED NOT NULL,
        code VARCHAR(12) NOT NULL,
        slug VARCHAR(40) NULL,
        title VARCHAR(191) NOT NULL DEFAULT '',
        intro TEXT NULL,
        rate DECIMAL(5,2) NULL,
        tiers TEXT NULL,
        template_id BIGINT NULL,
        discount_type VARCHAR(10) NULL,
        discount_value DECIMAL(12,2) NULL,
        starts_at DATETIME NULL,
        ends_at DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (user_id),
        UNIQUE KEY code (code),
        UNIQUE KEY slug (slug)
    ) ENGINE=InnoDB {$charset};" );
    dbDelta( "CREATE TABLE {$t['products']} (
        leader_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY  (leader_id,product_id),
        KEY product_id (product_id)
    ) ENGINE=InnoDB {$charset};" );
    dbDelta( "CREATE TABLE {$t['prices']} (
        leader_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        price DECIMAL(12,2) NOT NULL,
        PRIMARY KEY  (leader_id,product_id)
    ) ENGINE=InnoDB {$charset};" );
    dbDelta( "CREATE TABLE {$t['commissions']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        leader_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NOT NULL,
        entry_key VARCHAR(64) NOT NULL,
        base_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        rate DECIMAL(5,2) NOT NULL DEFAULT 0,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        note VARCHAR(191) NOT NULL DEFAULT '',
        settlement_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY entry_key (entry_key),
        KEY leader_settlement (leader_id,settlement_id),
        KEY order_id (order_id)
    ) ENGINE=InnoDB {$charset};" );
    dbDelta( "CREATE TABLE {$t['settlements']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        leader_id BIGINT UNSIGNED NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        note VARCHAR(191) NOT NULL DEFAULT '',
        created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY leader_id (leader_id)
    ) ENGINE=InnoDB {$charset};" );
    foreach ( $t as $table ) {
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) return;
    }
    update_option( 'twshop_group_buy_db_version', TWSHOP_GROUP_BUY_DB_VERSION, false );
}

function twshop_group_buy_maybe_upgrade_db() {
    if ( get_option( 'twshop_group_buy_db_version' ) !== TWSHOP_GROUP_BUY_DB_VERSION ) twshop_group_buy_install_tables();
}

function twshop_group_buy_enabled() {
    return twshop_module_enabled( 'group_buy' ) && twshop_license_is_active();
}

/** 專屬頁網址前綴（全站統一，預設 group-buy）。 */
function twshop_group_buy_base() {
    $base = get_option( 'twshop_group_buy_base', 'group-buy' );
    return is_string( $base ) && preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $base ) ? $base : 'group-buy';
}

function twshop_group_buy_rewrite_regex( $base = null ) {
    return '^' . preg_quote( $base ?? twshop_group_buy_base(), '#' ) . '/([A-Za-z0-9-]{3,40})/?$';
}

/** 專屬頁網址與會員中心頁籤：與 my-wallet 相同，無條件註冊，內容輸出才看模組開關。 */
function twshop_group_buy_register_rewrites() {
    add_rewrite_rule( twshop_group_buy_rewrite_regex(), 'index.php?twshop_gb=$matches[1]', 'top' );
    add_rewrite_endpoint( 'my-group-buy', EP_ROOT | EP_PAGES );
}
add_action( 'init', 'twshop_group_buy_register_rewrites' );
add_filter( 'query_vars', function ( $vars ) { $vars[] = 'twshop_gb'; return $vars; } );
add_filter( 'woocommerce_get_query_vars', function ( $vars ) { $vars['my-group-buy'] = 'my-group-buy'; return $vars; }, 0 );

/** 規則版本含前綴，前綴改變時下一次後台請求自動 flush。 */
function twshop_group_buy_maybe_flush_rewrites() {
    $version = '1.1.0|' . twshop_group_buy_base();
    if ( $version !== get_option( 'twshop_group_buy_rewrite_version' ) ) {
        twshop_group_buy_register_rewrites();
        flush_rewrite_rules();
        update_option( 'twshop_group_buy_rewrite_version', $version, false );
    }
}

/** 儲存新前綴後立即換掉本次請求已註冊的舊規則並 flush，不必等下一次請求。 */
function twshop_group_buy_apply_base( $old_base ) {
    global $wp_rewrite;
    unset( $wp_rewrite->extra_rules_top[ twshop_group_buy_rewrite_regex( $old_base ) ] );
    add_rewrite_rule( twshop_group_buy_rewrite_regex(), 'index.php?twshop_gb=$matches[1]', 'top' );
    flush_rewrite_rules();
    update_option( 'twshop_group_buy_rewrite_version', '1.1.0|' . twshop_group_buy_base(), false );
}

// 專屬頁網址常駐解析：模組停用或代碼無效時回 404，不落到首頁。
add_action( 'template_redirect', 'twshop_group_buy_page_redirect', 1 );

register_activation_hook( TWSHOP_PLUGIN_FILE, 'twshop_group_buy_install_tables' );
add_action( 'admin_init', 'twshop_group_buy_maybe_upgrade_db' );
add_action( 'admin_init', 'twshop_group_buy_maybe_flush_rewrites' );

// ── 設定與團購主資料 ─────────────────────────────────────────────────────

function twshop_group_buy_default_rate() {
    return twshop_group_buy_clamp_rate( get_option( 'twshop_group_buy_default_rate', 0 ) );
}

function twshop_group_buy_clamp_rate( $value ) {
    return is_scalar( $value ) && is_numeric( $value ) ? round( min( 100, max( 0, (float) $value ) ), 2 ) : 0.0;
}

function twshop_group_buy_normalize_code( $value ) {
    if ( ! is_string( $value ) ) return '';
    $code = strtoupper( trim( $value ) );
    return preg_match( '/^[A-Z0-9]{12}$/D', $code ) ? $code : '';
}

function twshop_group_buy_get_leader( $user_id ) {
    global $wpdb;
    $user_id = absint( $user_id );
    if ( ! $user_id ) return null;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . twshop_group_buy_tables()['leaders'] . ' WHERE user_id = %d', $user_id ), ARRAY_A ) ?: null;
}

/** 同一請求的團購主查詢快取；後台修改團購主資料後呼叫 twshop_group_buy_reset_cache()。 */
function &twshop_group_buy_cache( $bucket ) {
    static $store = array();
    if ( ! isset( $store[ $bucket ] ) ) $store[ $bucket ] = array();
    return $store[ $bucket ];
}

function twshop_group_buy_reset_cache() {
    foreach ( array( 'code', 'page', 'products', 'prices', 'expired_items' ) as $bucket ) {
        $cache = &twshop_group_buy_cache( $bucket );
        $cache = array();
    }
}

/** 依代碼取得「仍啟用且會員存在」的團購主；同一請求快取。 */
function twshop_group_buy_find_active_leader( $code ) {
    $cache = &twshop_group_buy_cache( 'code' );
    $code = twshop_group_buy_normalize_code( $code );
    if ( ! $code ) return null;
    if ( array_key_exists( $code, $cache ) ) return $cache[ $code ];
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . twshop_group_buy_tables()['leaders'] . " WHERE code = %s AND status = 'active'", $code ), ARRAY_A );
    $cache[ $code ] = ( $row && get_userdata( (int) $row['user_id'] ) ) ? $row : null;
    return $cache[ $code ];
}

// ── 佣金級距（依累計業績超額累進）────────────────────────────────────────
//
// 級距格式：[ [ 'from' => 累計業績門檻, 'rate' => 比例% ], ... ]，依 from 遞增，第一級 from 必為 0。
// 每張訂單依它在團購主累計業績上的位置分段計算，例如累計從 25,000 跨到 35,000、門檻 30,000 時，
// 前 5,000 用第一級、後 5,000 用第二級。固定比例就是只有一級的級距。

/** 正規化級距；格式不符回傳 null。 */
function twshop_group_buy_normalize_tiers( $tiers ) {
    if ( is_string( $tiers ) ) $tiers = json_decode( $tiers, true );
    if ( ! is_array( $tiers ) || ! $tiers ) return null;
    $out = array();
    foreach ( $tiers as $tier ) {
        if ( ! is_array( $tier ) || ! isset( $tier['from'], $tier['rate'] ) || ! is_numeric( $tier['from'] ) || ! is_numeric( $tier['rate'] ) ) return null;
        $from = round( (float) $tier['from'], 2 );
        if ( $from < 0 || (float) $tier['rate'] < 0 || (float) $tier['rate'] > 100 ) return null;
        $out[ (string) $from ] = array( 'from' => $from, 'rate' => twshop_group_buy_clamp_rate( $tier['rate'] ) );
    }
    if ( count( $out ) !== count( $tiers ) ) return null; // 門檻重複
    usort( $out, fn( $a, $b ) => $a['from'] <=> $b['from'] );
    return 0.0 === $out[0]['from'] ? array_values( $out ) : null;
}

/** 全站級距：第一級為預設佣金比例，加上設定頁的其他級距。 */
function twshop_group_buy_global_tiers() {
    $extra = get_option( 'twshop_group_buy_tiers', array() );
    $tiers = array( array( 'from' => 0, 'rate' => twshop_group_buy_default_rate() ) );
    if ( is_array( $extra ) ) foreach ( $extra as $tier ) if ( is_array( $tier ) && ( $tier['from'] ?? 0 ) > 0 ) $tiers[] = $tier;
    return twshop_group_buy_normalize_tiers( $tiers ) ?? array( array( 'from' => 0.0, 'rate' => twshop_group_buy_default_rate() ) );
}

/** 團購主適用的級距：自訂級距 → 固定比例 → 全站級距。 */
function twshop_group_buy_leader_tiers( array $leader ) {
    $own = twshop_group_buy_normalize_tiers( $leader['tiers'] ?? null );
    if ( $own ) return $own;
    if ( isset( $leader['rate'] ) && null !== $leader['rate'] && '' !== $leader['rate'] ) return array( array( 'from' => 0.0, 'rate' => twshop_group_buy_clamp_rate( $leader['rate'] ) ) );
    return twshop_group_buy_global_tiers();
}

/** 規則來源：tiers／rate／global。 */
function twshop_group_buy_leader_rule_mode( array $leader ) {
    if ( twshop_group_buy_normalize_tiers( $leader['tiers'] ?? null ) ) return 'tiers';
    return isset( $leader['rate'] ) && null !== $leader['rate'] && '' !== $leader['rate'] ? 'rate' : 'global';
}

/** 累計業績從 $start 增加 $base 時的超額累進佣金。 */
function twshop_group_buy_tier_commission( $start, $base, array $tiers ) {
    $start = max( 0, (float) $start );
    $end = $start + max( 0, (float) $base );
    $total = 0.0;
    foreach ( $tiers as $i => $tier ) {
        $lo = (float) $tier['from'];
        $hi = isset( $tiers[ $i + 1 ] ) ? (float) $tiers[ $i + 1 ]['from'] : INF;
        $overlap = min( $end, $hi ) - max( $start, $lo );
        if ( $overlap > 0 ) $total += $overlap * (float) $tier['rate'] / 100;
    }
    return round( $total, 2 );
}

/** 某累計業績所在級距的索引。 */
function twshop_group_buy_tier_index_at( $amount, array $tiers ) {
    $index = 0;
    foreach ( $tiers as $i => $tier ) if ( (float) $amount >= (float) $tier['from'] ) $index = $i;
    return $index;
}

function twshop_group_buy_describe_tiers( array $tiers ) {
    if ( 1 === count( $tiers ) ) return wc_format_localized_decimal( $tiers[0]['rate'] ) . '%';
    $parts = array();
    foreach ( $tiers as $tier ) {
        $parts[] = ( $tier['from'] > 0 ? '累計 ' . wp_strip_all_tags( wc_price( $tier['from'] ) ) . ' 起 ' : '' ) . wc_format_localized_decimal( $tier['rate'] ) . '%';
    }
    return implode( '；', $parts );
}

/** 團購主目前累計業績（已完成團購訂單的計佣金額，扣除取消與退款）。 */
function twshop_group_buy_leader_performance( $leader_id ) {
    global $wpdb;
    return round( (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(base_amount),0) FROM ' . twshop_group_buy_tables()['commissions'] . ' WHERE leader_id = %d', absint( $leader_id ) ) ), 2 );
}

/**
 * 後台輸入的級距列（門檻、比例兩個陣列；第一列門檻固定 0）轉成級距；
 * 兩欄都空白的列略過；回傳級距或 WP_Error。
 */
function twshop_group_buy_parse_tier_rows( $froms, $rates ) {
    $froms = is_array( $froms ) ? array_values( $froms ) : array();
    $rates = is_array( $rates ) ? array_values( $rates ) : array();
    $tiers = array();
    foreach ( $rates as $i => $rate ) {
        $from = 0 === $i ? '0' : trim( (string) ( $froms[ $i ] ?? '' ) );
        $rate = trim( (string) $rate );
        if ( 0 !== $i && '' === $from && '' === $rate ) continue;
        if ( '' === $rate || ! is_numeric( $rate ) || (float) $rate < 0 || (float) $rate > 100 ) return new WP_Error( 'group_buy_tiers', '每一級的比例須為 0–100。' );
        if ( '' === $from || ! is_numeric( $from ) || ( 0 !== $i && (float) $from <= 0 ) ) return new WP_Error( 'group_buy_tiers', '第二級起的累計業績門檻須大於 0。' );
        $tiers[] = array( 'from' => (float) $from, 'rate' => (float) $rate );
    }
    $normalized = twshop_group_buy_normalize_tiers( $tiers );
    return $normalized ?: new WP_Error( 'group_buy_tiers', '累計業績門檻不可重複。' );
}

/** 目前級距的比例（顯示用）。 */
function twshop_group_buy_leader_rate( array $leader ) {
    $tiers = twshop_group_buy_leader_tiers( $leader );
    $performance = isset( $leader['user_id'] ) ? twshop_group_buy_leader_performance( $leader['user_id'] ) : 0;
    return (float) $tiers[ twshop_group_buy_tier_index_at( $performance, $tiers ) ]['rate'];
}

function twshop_group_buy_leader_title( array $leader ) {
    if ( '' !== trim( (string) $leader['title'] ) ) return $leader['title'];
    $user = get_userdata( (int) $leader['user_id'] );
    return $user ? $user->display_name : '團購';
}

function twshop_group_buy_normalize_slug( $value ) {
    if ( ! is_string( $value ) ) return '';
    $slug = strtolower( trim( $value ) );
    return strlen( $slug ) >= 3 && strlen( $slug ) <= 40 && preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug ) ? $slug : '';
}

/** 專屬頁網址：有自訂網址用自訂網址，否則用代碼。傳團購主資料列或代碼字串。 */
function twshop_group_buy_page_url( $leader ) {
    $part = is_array( $leader )
        ? ( twshop_group_buy_normalize_slug( (string) ( $leader['slug'] ?? '' ) ) ?: twshop_group_buy_normalize_code( $leader['code'] ?? '' ) )
        : twshop_group_buy_normalize_code( $leader );
    if ( ! $part ) return '';
    return get_option( 'permalink_structure' )
        ? home_url( user_trailingslashit( twshop_group_buy_base() . '/' . $part ) )
        : add_query_arg( 'twshop_gb', $part, home_url( '/' ) );
}

/** 專屬頁網址的識別段：先比對自訂網址，再比對代碼。 */
function twshop_group_buy_find_page_leader( $ident ) {
    $cache = &twshop_group_buy_cache( 'page' );
    if ( ! is_string( $ident ) || '' === $ident ) return null;
    if ( array_key_exists( $ident, $cache ) ) return $cache[ $ident ];
    $leader = null;
    $slug = twshop_group_buy_normalize_slug( $ident );
    if ( $slug ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . twshop_group_buy_tables()['leaders'] . " WHERE slug = %s AND status = 'active'", $slug ), ARRAY_A );
        if ( $row && get_userdata( (int) $row['user_id'] ) ) $leader = $row;
    }
    if ( ! $leader ) $leader = twshop_group_buy_find_active_leader( $ident );
    $cache[ $ident ] = $leader;
    return $leader;
}

/**
 * 驗證自訂網址：格式、不得與其他團購主的自訂網址或代碼相同。回傳正規化結果或 WP_Error；空字串表示清除。
 */
function twshop_group_buy_validate_slug( $raw, $leader_id ) {
    $raw = is_string( $raw ) ? trim( $raw ) : '';
    if ( '' === $raw ) return '';
    $slug = twshop_group_buy_normalize_slug( $raw );
    if ( ! $slug ) return new WP_Error( 'group_buy_slug', '自訂網址只能用英文小寫、數字與連字號（不可開頭結尾或連續），長度 3–40 字。' );
    global $wpdb;
    $taken = $wpdb->get_var( $wpdb->prepare(
        'SELECT user_id FROM ' . twshop_group_buy_tables()['leaders'] . ' WHERE ( slug = %s OR code = %s ) AND user_id <> %d LIMIT 1',
        $slug, strtoupper( $slug ), absint( $leader_id )
    ) );
    if ( $taken ) return new WP_Error( 'group_buy_slug', '這個自訂網址已被其他團購主使用。' );
    return $slug;
}

/**
 * 驗證網址前綴：格式、不得與保留字或既有頁面、文章類型、分類網址衝突。回傳正規化結果或 WP_Error。
 */
function twshop_group_buy_validate_base( $raw ) {
    $base = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
    if ( strlen( $base ) < 2 || strlen( $base ) > 30 || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $base ) ) {
        return new WP_Error( 'group_buy_base', '網址前綴只能用英文小寫、數字與連字號（不可開頭結尾或連續），長度 2–30 字。' );
    }
    $reserved = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'page', 'comments', 'search', 'author', 'category', 'tag', 'product', 'product-category', 'product-tag', 'shop', 'cart', 'checkout', 'my-account' );
    foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
        if ( ! empty( $type->rewrite['slug'] ) ) $reserved[] = $type->rewrite['slug'];
    }
    foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
        if ( ! empty( $tax->rewrite['slug'] ) ) $reserved[] = $tax->rewrite['slug'];
    }
    if ( in_array( $base, $reserved, true ) || get_page_by_path( $base ) ) {
        return new WP_Error( 'group_buy_base', '這個前綴與網站既有的頁面或網址衝突，請換一個。' );
    }
    return $base;
}

/** 新增團購主；代碼以唯一索引防碰撞。已存在者回傳既有資料（重新啟用由呼叫端決定）。 */
function twshop_group_buy_create_leader( $user_id ) {
    global $wpdb;
    $user_id = absint( $user_id );
    if ( ! $user_id || ! get_userdata( $user_id ) ) return null;
    $table = twshop_group_buy_tables()['leaders'];
    $row = twshop_group_buy_get_leader( $user_id );
    if ( $row ) return $row;
    for ( $attempt = 0; $attempt < 5; $attempt++ ) {
        $code = strtoupper( wp_generate_password( 12, false, false ) );
        $ok = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (user_id, code, status, created_at) VALUES (%d, %s, 'active', %s)",
            $user_id, $code, current_time( 'mysql' )
        ) );
        if ( false === $ok ) return null;
        $row = twshop_group_buy_get_leader( $user_id );
        if ( $row ) return $row;
    }
    return null;
}

/** 團購主被指定的父商品 ID（依後台排序）。 */
function twshop_group_buy_leader_product_ids( $leader_id ) {
    $cache = &twshop_group_buy_cache( 'products' );
    $leader_id = absint( $leader_id );
    if ( isset( $cache[ $leader_id ] ) ) return $cache[ $leader_id ];
    global $wpdb;
    $ids = $wpdb->get_col( $wpdb->prepare(
        'SELECT product_id FROM ' . twshop_group_buy_tables()['products'] . ' WHERE leader_id = %d ORDER BY sort_order ASC, product_id ASC',
        $leader_id
    ) );
    $cache[ $leader_id ] = array_map( 'intval', $ids );
    return $cache[ $leader_id ];
}

function twshop_group_buy_set_leader_products( $leader_id, array $product_ids ) {
    global $wpdb;
    $table = twshop_group_buy_tables()['products'];
    $leader_id = absint( $leader_id );
    $product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
    $wpdb->query( 'START TRANSACTION' );
    $ok = false !== $wpdb->delete( $table, array( 'leader_id' => $leader_id ), array( '%d' ) );
    foreach ( $product_ids as $i => $pid ) {
        if ( ! $ok ) break;
        $ok = false !== $wpdb->insert( $table, array( 'leader_id' => $leader_id, 'product_id' => $pid, 'sort_order' => $i ), array( '%d', '%d', '%d' ) );
    }
    // 移出清單的商品（含其規格）不再保留此團購主的個別價格。
    if ( $ok ) {
        $keep = array();
        foreach ( $product_ids as $pid ) $keep = array_merge( $keep, array( $pid ), array_map( 'intval', wc_get_products( array( 'parent' => $pid, 'type' => 'variation', 'limit' => -1, 'return' => 'ids', 'status' => array( 'publish', 'private' ) ) ) ) );
        $prices = twshop_group_buy_tables()['prices'];
        $ok = false !== ( $keep
            ? $wpdb->query( $wpdb->prepare( "DELETE FROM {$prices} WHERE leader_id = %d AND product_id NOT IN (" . implode( ',', array_fill( 0, count( $keep ), '%d' ) ) . ')', array_merge( array( $leader_id ), $keep ) ) )
            : $wpdb->delete( $prices, array( 'leader_id' => $leader_id ), array( '%d' ) ) );
    }
    $wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' );
    return $ok;
}

/** 團購主的個別商品價格（商品或規格 ID => 價格）；同一請求快取。 */
function twshop_group_buy_leader_prices( $leader_id ) {
    $cache = &twshop_group_buy_cache( 'prices' );
    $leader_id = absint( $leader_id );
    if ( isset( $cache[ $leader_id ] ) ) return $cache[ $leader_id ];
    global $wpdb;
    $out = array();
    foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT product_id, price FROM ' . twshop_group_buy_tables()['prices'] . ' WHERE leader_id = %d', $leader_id ), ARRAY_A ) as $row ) {
        if ( (float) $row['price'] > 0 ) $out[ (int) $row['product_id'] ] = (float) $row['price'];
    }
    $cache[ $leader_id ] = $out;
    return $out;
}

/** 以「商品或規格 ID => 價格」整批取代團購主的個別價格；空白或非正數的項目視為不設定。 */
function twshop_group_buy_set_leader_prices( $leader_id, array $prices ) {
    global $wpdb;
    $table = twshop_group_buy_tables()['prices'];
    $leader_id = absint( $leader_id );
    $wpdb->query( 'START TRANSACTION' );
    $ok = false !== $wpdb->delete( $table, array( 'leader_id' => $leader_id ), array( '%d' ) );
    foreach ( $prices as $pid => $price ) {
        if ( ! $ok ) break;
        $ok = false !== $wpdb->insert( $table, array( 'leader_id' => $leader_id, 'product_id' => absint( $pid ), 'price' => (float) $price ), array( '%d', '%d', '%f' ) );
    }
    $wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' );
    return $ok;
}

// ── 團購期間（選填；資料庫存 UTC，後台以網站時區輸入與顯示）────────────────

/** 目前時間戳；測試可用 twshop_group_buy_now 濾鏡注入。 */
function twshop_group_buy_now() {
    return (int) apply_filters( 'twshop_group_buy_now', time() );
}

/** 期間邊界時間戳：array( 'start' => int|0, 'end' => int|0 )，0 代表未設定。 */
function twshop_group_buy_period( array $leader ) {
    $out = array( 'start' => 0, 'end' => 0 );
    foreach ( array( 'start' => 'starts_at', 'end' => 'ends_at' ) as $key => $col ) {
        $raw = (string) ( $leader[ $col ] ?? '' );
        if ( '' !== $raw && '0000-00-00 00:00:00' !== $raw ) $out[ $key ] = (int) strtotime( $raw . ' UTC' );
    }
    return $out;
}

/** upcoming＝尚未開始、open＝進行中（含未設定期間）、ended＝已結束。 */
function twshop_group_buy_period_state( array $leader, $now = null ) {
    $now = null === $now ? twshop_group_buy_now() : (int) $now;
    $period = twshop_group_buy_period( $leader );
    if ( $period['start'] && $now < $period['start'] ) return 'upcoming';
    if ( $period['end'] && $now >= $period['end'] ) return 'ended';
    return 'open';
}

function twshop_group_buy_is_open( array $leader ) {
    return 'open' === twshop_group_buy_period_state( $leader );
}

/** 以網站時區格式化期間時間戳。 */
function twshop_group_buy_format_time( $timestamp ) {
    return wp_date( 'Y/m/d H:i', (int) $timestamp );
}

/** 後台 datetime-local（網站時區，Y-m-d\TH:i）轉 UTC Y-m-d H:i:s；空字串回 ''，格式錯誤回 false。 */
function twshop_group_buy_parse_local_datetime( $raw ) {
    $raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
    if ( '' === $raw ) return '';
    $date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $raw, wp_timezone() );
    if ( ! $date || $date->format( 'Y-m-d\TH:i' ) !== $raw ) return false;
    return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
}

/** UTC 欄位值轉後台 datetime-local 輸入值（網站時區）。 */
function twshop_group_buy_local_input_value( $utc ) {
    $utc = (string) $utc;
    if ( '' === $utc || '0000-00-00 00:00:00' === $utc ) return '';
    return wp_date( 'Y-m-d\TH:i', (int) strtotime( $utc . ' UTC' ) );
}

/** 期間狀態文字，例如「進行中，至 2026/11/01 23:59 結束」。 */
function twshop_group_buy_describe_period( array $leader ) {
    $period = twshop_group_buy_period( $leader );
    if ( ! $period['start'] && ! $period['end'] ) return '';
    $state = twshop_group_buy_period_state( $leader );
    if ( 'upcoming' === $state ) return '未開始，' . twshop_group_buy_format_time( $period['start'] ) . ' 開始';
    if ( 'ended' === $state ) return '已於 ' . twshop_group_buy_format_time( $period['end'] ) . ' 結束';
    return $period['end'] ? '進行中，至 ' . twshop_group_buy_format_time( $period['end'] ) . ' 結束' : '進行中（' . twshop_group_buy_format_time( $period['start'] ) . ' 起）';
}

/** 團購主整體折扣：array( 'type' => percent|fixed, 'value' => 數字 ) 或 null（未設定）。 */
function twshop_group_buy_leader_discount( array $leader ) {
    $type = $leader['discount_type'] ?? '';
    $value = isset( $leader['discount_value'] ) && is_numeric( $leader['discount_value'] ) ? (float) $leader['discount_value'] : 0.0;
    if ( 'percent' === $type && $value > 0 && $value < 100 ) return array( 'type' => 'percent', 'value' => $value );
    if ( 'fixed' === $type && $value > 0 ) return array( 'type' => 'fixed', 'value' => $value );
    return null;
}

/** 整體折扣的文字說明，例如「9 折（減 10%）」「每件減 NT$50」。 */
function twshop_group_buy_describe_discount( $discount ) {
    if ( ! $discount ) return '';
    if ( 'percent' === $discount['type'] ) {
        $zhe = rtrim( rtrim( number_format( ( 100 - $discount['value'] ) / 10, 2, '.', '' ), '0' ), '.' );
        return $zhe . ' 折（減 ' . wc_format_localized_decimal( $discount['value'] ) . '%）';
    }
    return '每件減 ' . wp_strip_all_tags( wc_price( $discount['value'] ) );
}

// ── 團購價（只在專屬頁顯示，或在從專屬頁加入的購物車項目上生效）──────────────
//
// 不使用 Cookie：專屬頁每位團購主一個網址，內容對所有訪客相同，可被 CDN／伺服器快取；
// 一般商店頁與商品頁不顯示團購價。團購身分在加入購物車（POST／AJAX，不會被快取）時
// 寫進購物車項目，之後的價格、歸屬與佣金都以購物車項目為準。

function twshop_group_buy_raw_price( $product ) {
    if ( ! $product instanceof WC_Product ) return null;
    $value = $product->get_meta( '_twshop_group_buy_price', true, 'edit' );
    return is_numeric( $value ) && (float) $value > 0 ? (float) $value : null;
}

/** 某團購主對某商品的團購價：父商品須被指定給該團購主，且團購價低於目前存檔售價。 */
function twshop_group_buy_price_for_leader( $leader, $product ) {
    if ( ! is_array( $leader ) || ! $product instanceof WC_Product || twshop_is_wallet_credit_product( $product ) ) return null;
    $parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
    if ( ! in_array( (int) $parent_id, twshop_group_buy_leader_product_ids( $leader['user_id'] ), true ) ) return null;
    $stored = $product->get_price( 'edit' );
    if ( '' === $stored || ! is_numeric( $stored ) ) return null;
    $gb = twshop_group_buy_leader_base_price( $leader, $product, (float) $stored );
    if ( null === $gb || $gb <= 0 || $gb >= (float) $stored ) return null;
    return $gb;
}

/**
 * 團購價來源優先順序：團購主的個別商品價 > 團購主整體折扣（以目前售價計算）> 商品上的團購價。
 * 只回傳候選價格，是否低於售價由呼叫端判斷。
 */
function twshop_group_buy_leader_base_price( array $leader, WC_Product $product, $stored ) {
    $own = twshop_group_buy_leader_prices( $leader['user_id'] );
    if ( isset( $own[ $product->get_id() ] ) ) return $own[ $product->get_id() ];
    $discount = twshop_group_buy_leader_discount( $leader );
    if ( $discount ) {
        $price = 'percent' === $discount['type'] ? $stored * ( 100 - $discount['value'] ) / 100 : $stored - $discount['value'];
        return round( $price, wc_get_price_decimals() );
    }
    return twshop_group_buy_raw_price( $product );
}

/** 購物車裡的商品物件（全部）與其中的團購項目（物件 => 團購資訊）；只在記憶體標記。 */
function twshop_group_buy_cart_products() {
    static $map = null;
    if ( null === $map ) $map = new WeakMap();
    return $map;
}

function twshop_group_buy_cart_items() {
    static $map = null;
    if ( null === $map ) $map = new WeakMap();
    return $map;
}

/** 目前是不是團購專屬頁（前台）。版面、標題、資產等「整頁」行為只看這個。 */
function twshop_group_buy_is_group_page() {
    return ! is_admin() && null !== twshop_group_buy_page_leader();
}

/**
 * 顯示團購價與團購表單的團購主：團購專屬頁的團購主；或在 Elementor 編輯團購頁範本時的示範團購主。
 * 其他頁面一律 null。
 */
function twshop_group_buy_context_leader() {
    if ( ! is_admin() ) {
        $leader = twshop_group_buy_page_leader();
        if ( $leader ) return $leader;
    }
    return twshop_group_buy_elementor_preview_leader();
}

/** 專屬頁上的顯示價格；購物車商品物件不受影響（迷你購物車照購物車實際價格顯示）。 */
function twshop_group_buy_page_price_for( $product ) {
    if ( ! $product instanceof WC_Product ) return null;
    $leader = twshop_group_buy_context_leader();
    if ( ! $leader ) return null;
    if ( twshop_group_buy_cart_products()->offsetExists( $product ) || twshop_fixed_price_products()->offsetExists( $product ) ) return null;
    if ( array_key_exists( 'price', $product->get_changes() ) ) return null;
    return twshop_group_buy_price_for_leader( $leader, $product );
}

/** 此商品物件目前是否以團購價計價（供折扣規則判斷不疊加）。 */
function twshop_group_buy_applies( $product ) {
    if ( ! $product instanceof WC_Product ) return false;
    if ( twshop_group_buy_cart_items()->offsetExists( $product ) ) return true;
    return null !== twshop_group_buy_page_price_for( $product );
}

function twshop_group_buy_filter_price( $price, $product ) {
    if ( '' === $price ) return $price;
    $gb = twshop_group_buy_page_price_for( $product );
    return null === $gb ? $price : wc_format_decimal( $gb );
}

function twshop_group_buy_filter_sale_price( $sale_price, $product ) {
    $gb = twshop_group_buy_page_price_for( $product );
    return null === $gb ? $sale_price : wc_format_decimal( $gb );
}

function twshop_group_buy_filter_is_on_sale( $on_sale, $product ) {
    if ( $on_sale || ! $product instanceof WC_Product || ! twshop_group_buy_context_leader() ) return $on_sale;
    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_visible_children() as $child_id ) {
            if ( null !== twshop_group_buy_page_price_for( wc_get_product( $child_id ) ) ) return true;
        }
        return $on_sale;
    }
    return null !== twshop_group_buy_page_price_for( $product ) ? true : $on_sale;
}

/** 規格價格快取：專屬頁與一般頁、不同團購主各自一份。 */
function twshop_group_buy_variation_price_hash( $hash ) {
    $leader = twshop_group_buy_context_leader();
    $hash['twshop_gb'] = $leader ? $leader['code'] . ':' . md5( wp_json_encode( array(
        twshop_group_buy_leader_product_ids( $leader['user_id'] ),
        twshop_group_buy_leader_discount( $leader ),
        twshop_group_buy_leader_prices( $leader['user_id'] ),
    ) ) ) : '';
    return $hash;
}

function twshop_group_buy_price_html_label( $html, $product ) {
    if ( '' === $html || ! twshop_group_buy_context_leader() ) return $html;
    $applies = $product->is_type( 'variable' )
        ? twshop_group_buy_filter_is_on_sale( false, $product )
        : null !== twshop_group_buy_page_price_for( $product );
    return $applies ? '<span class="twshop-group-buy-label">團購價</span> ' . $html : $html;
}

// ── 從專屬頁加入購物車 ───────────────────────────────────────────────────

/** 專屬頁的加入購物車請求帶 twshop_group_buy=代碼；驗證通過才記到購物車項目。 */
function twshop_group_buy_add_cart_item_data( $cart_item_data, $product_id, $variation_id = 0 ) {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- 與 WooCommerce 原生加入購物車相同，不需 nonce；只影響本次加入的價格資格。
    $raw = $_REQUEST['twshop_group_buy'] ?? '';
    // 只標記顧客按下的那件商品；同一請求內由其他程式連帶加入的加購品、贈品不吃團購資格。
    $requested = absint( $_REQUEST['add-to-cart'] ?? ( $_REQUEST['product_id'] ?? 0 ) );
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    if ( ! is_string( $raw ) || '' === $raw || ! twshop_group_buy_enabled() ) return $cart_item_data;
    if ( $requested !== (int) $product_id && $requested !== (int) $variation_id ) return $cart_item_data;
    if ( twshop_is_twshop_special_cart_item( $cart_item_data ) ) return $cart_item_data;
    $leader = twshop_group_buy_find_active_leader( wp_unslash( $raw ) );
    $product = wc_get_product( $variation_id ?: $product_id );
    if ( $leader && twshop_group_buy_is_open( $leader ) && null !== twshop_group_buy_price_for_leader( $leader, $product ) ) {
        $cart_item_data['twshop_gb'] = array( 'leader' => (int) $leader['user_id'], 'code' => $leader['code'] );
    }
    return $cart_item_data;
}

/** 新加入與從 session 還原的購物車項目都重新驗證；團購主停用或商品不再指定時恢復原價並移除歸屬。 */
function twshop_group_buy_prepare_cart_item( $cart_item ) {
    if ( ! isset( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) return $cart_item;
    $product = $cart_item['data'];
    twshop_group_buy_cart_products()[ $product ] = true;
    if ( empty( $cart_item['twshop_gb'] ) ) return $cart_item;
    $tag = $cart_item['twshop_gb'];
    $leader = twshop_group_buy_enabled() && is_array( $tag ) ? twshop_group_buy_find_active_leader( $tag['code'] ?? '' ) : null;
    $price = $leader && (int) $leader['user_id'] === (int) ( $tag['leader'] ?? 0 ) ? twshop_group_buy_price_for_leader( $leader, $product ) : null;
    // 團購期間結束後（或尚未開始）購物車裡的團購商品恢復原價，於購物車／結帳頁提示一次。
    if ( null !== $price && ! twshop_group_buy_is_open( $leader ) ) {
        $expired = &twshop_group_buy_cache( 'expired_items' );
        $expired[ $product->get_name() ] = twshop_group_buy_leader_title( $leader );
        $price = null;
    }
    if ( null === $price ) {
        unset( $cart_item['twshop_gb'] );
        return $cart_item;
    }
    $regular = $product->get_price( 'edit' );
    $product->set_price( $price );
    twshop_fixed_price_products()[ $product ] = true;
    twshop_group_buy_cart_items()[ $product ] = array( 'leader' => $leader, 'price' => $price, 'regular' => (float) $regular );
    return $cart_item;
}

/** 從團購頁加入但團購不在期間內：擋下並提示（快取的舊頁面仍可能顯示購買按鈕）。 */
function twshop_group_buy_add_to_cart_validation( $passed, $product_id = 0, $quantity = 0, $variation_id = 0 ) {
    if ( ! $passed || ! twshop_group_buy_is_page_request() || ! twshop_group_buy_enabled() ) return $passed;
    $leader = twshop_group_buy_find_active_leader( wp_unslash( $_REQUEST['twshop_group_buy'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 代碼經 normalize 驗證。
    if ( ! $leader ) return $passed;
    $state = twshop_group_buy_period_state( $leader );
    if ( 'open' === $state ) return $passed;
    $period = twshop_group_buy_period( $leader );
    wc_add_notice( 'upcoming' === $state
        ? '團購尚未開始，將於 ' . twshop_group_buy_format_time( $period['start'] ) . ' 開放購買。'
        : '團購已於 ' . twshop_group_buy_format_time( $period['end'] ) . ' 結束，無法再以團購價購買。', 'error' );
    return false;
}

/** 購物車／結帳頁：提示因團購結束而恢復原價的商品。 */
function twshop_group_buy_check_cart_items() {
    $expired = &twshop_group_buy_cache( 'expired_items' );
    if ( ! $expired ) return;
    foreach ( array_unique( array_values( $expired ) ) as $title ) {
        $names = array_keys( array_filter( $expired, fn( $t ) => $t === $title ) );
        wc_add_notice( sprintf( '「%s」團購已結束，%s 已恢復原價。', $title, implode( '、', $names ) ), 'notice' );
    }
    $expired = array();
}

function twshop_group_buy_add_cart_item( $cart_item, $cart_item_key = '' ) {
    return twshop_group_buy_prepare_cart_item( $cart_item );
}

function twshop_group_buy_cart_item_from_session( $cart_item, $values = array(), $key = '' ) {
    return twshop_group_buy_prepare_cart_item( $cart_item );
}

function twshop_group_buy_cart_item_data_display( $item_data, $cart_item ) {
    $info = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product && twshop_group_buy_cart_items()->offsetExists( $cart_item['data'] )
        ? twshop_group_buy_cart_items()[ $cart_item['data'] ] : null;
    if ( $info ) $item_data[] = array( 'key' => '團購', 'value' => twshop_group_buy_leader_title( $info['leader'] ) );
    return $item_data;
}

function twshop_group_buy_cart_item_price( $price_html, $cart_item, $cart_item_key ) {
    $product = $cart_item['data'] ?? null;
    if ( ! $product instanceof WC_Product || ! twshop_group_buy_cart_items()->offsetExists( $product ) ) return $price_html;
    $info = twshop_group_buy_cart_items()[ $product ];
    return wc_format_sale_price(
        wc_get_price_to_display( $product, array( 'price' => $info['regular'] ) ),
        wc_get_price_to_display( $product )
    ) . $product->get_price_suffix();
}

/** 訂單項目保存團購主與下單當下比例；團購主本人下單仍享團購價但不計佣。 */
function twshop_group_buy_order_line_item( $item, $cart_item_key, $values, $order ) {
    $product = $values['data'] ?? null;
    if ( ! $product instanceof WC_Product || ! twshop_group_buy_cart_items()->offsetExists( $product ) ) return;
    $leader = twshop_group_buy_cart_items()[ $product ]['leader'];
    $item->add_meta_data( '團購', twshop_group_buy_leader_title( $leader ), true );
    if ( $order instanceof WC_Order && (int) $order->get_customer_id() && (int) $order->get_customer_id() === (int) $leader['user_id'] ) return;
    $item->add_meta_data( '_twshop_gb_leader_id', (int) $leader['user_id'], true );
    // 下單當下的級距快照：之後修改規則不影響這張訂單。_twshop_gb_rate 為第一級比例，供舊資料與顯示相容。
    $tiers = twshop_group_buy_leader_tiers( $leader );
    $item->add_meta_data( '_twshop_gb_tiers', wp_json_encode( $tiers ), true );
    $item->add_meta_data( '_twshop_gb_rate', wc_format_decimal( $tiers[0]['rate'], 2 ), true );
}

/** 訂單層記錄涉及的團購主，供對帳與後台顯示。 */
function twshop_group_buy_attach_order( $order ) {
    if ( ! $order instanceof WC_Order ) return;
    $ids = array();
    foreach ( $order->get_items() as $item ) {
        $id = (int) $item->get_meta( '_twshop_gb_leader_id' );
        if ( $id ) $ids[ $id ] = $id;
    }
    if ( $ids ) $order->update_meta_data( '_twshop_gb_leader_ids', array_values( $ids ) );
}

// ── 商品編輯頁欄位 ───────────────────────────────────────────────────────

function twshop_group_buy_product_field() {
    global $product_object;
    if ( $product_object instanceof WC_Product && twshop_is_wallet_credit_product( $product_object ) ) return;
    woocommerce_wp_text_input( array(
        'id'          => '_twshop_group_buy_price',
        'value'       => $product_object instanceof WC_Product ? $product_object->get_meta( '_twshop_group_buy_price', true, 'edit' ) : '',
        'label'       => '團購價 (' . get_woocommerce_currency_symbol() . ')',
        'data_type'   => 'price',
        'desc_tip'    => true,
        'description' => '此商品被指定給團購主時，團員從該團購主專屬頁加入購物車即套用；須低於目前售價。',
    ) );
}

function twshop_group_buy_variation_field( $loop, $variation_data, $variation ) {
    $value = get_post_meta( $variation->ID, '_twshop_group_buy_price', true );
    woocommerce_wp_text_input( array(
        'id'            => "twshop_group_buy_price_{$loop}",
        'name'          => "twshop_group_buy_price[{$loop}]",
        'value'         => wc_format_localized_price( $value ),
        'label'         => '團購價 (' . get_woocommerce_currency_symbol() . ')',
        'data_type'     => 'price',
        'wrapper_class' => 'form-row form-row-first',
        'desc_tip'      => true,
        'description'   => '父商品被指定給團購主時，從專屬頁加入購物車的此規格套用團購價；須低於售價。',
    ) );
}

/** 只保留大於 0 且低於目前售價的團購價，其他情況清空並提示。 */
function twshop_group_buy_store_price( $product, $raw ) {
    $raw = is_scalar( $raw ) ? wc_format_decimal( wp_unslash( $raw ) ) : '';
    if ( '' === $raw ) { $product->delete_meta_data( '_twshop_group_buy_price' ); return; }
    $active = $product->get_sale_price( 'edit' );
    if ( '' === $active ) $active = $product->get_regular_price( 'edit' );
    if ( (float) $raw <= 0 || ( '' !== $active && (float) $raw >= (float) $active ) ) {
        $product->delete_meta_data( '_twshop_group_buy_price' );
        if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) WC_Admin_Meta_Boxes::add_error( sprintf( '「%s」的團購價須大於 0 且低於售價，已清除。', $product->get_name() ) );
        return;
    }
    $product->update_meta_data( '_twshop_group_buy_price', $raw );
}

function twshop_group_buy_save_product( $product ) {
    if ( ! isset( $_POST['_twshop_group_buy_price'] ) || twshop_is_wallet_credit_product( $product ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce 已驗證商品表單 nonce。
    twshop_group_buy_store_price( $product, $_POST['_twshop_group_buy_price'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
}

function twshop_group_buy_save_variation( $variation, $i ) {
    if ( ! isset( $_POST['twshop_group_buy_price'][ $i ] ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce 已驗證規格儲存 nonce。
    twshop_group_buy_store_price( $variation, $_POST['twshop_group_buy_price'][ $i ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
}

// ── 專屬頁面 ─────────────────────────────────────────────────────────────

function twshop_group_buy_page_leader() {
    $ident = get_query_var( 'twshop_gb' );
    return $ident && twshop_group_buy_enabled() ? twshop_group_buy_find_page_leader( $ident ) : null;
}

/** 只處理 404；有效頁面不設 no-cache，內容對所有訪客相同，可交給 CDN 快取。 */
function twshop_group_buy_page_redirect() {
    $ident = get_query_var( 'twshop_gb' );
    if ( ! $ident ) return;
    $leader = twshop_group_buy_page_leader();
    if ( $leader ) {
        // 設了自訂網址後，舊的代碼網址永久轉到自訂網址（已分享出去的連結不失效）。
        if ( '' !== (string) ( $leader['slug'] ?? '' ) && strtolower( $ident ) !== $leader['slug'] && 'GET' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
            wp_safe_redirect( twshop_group_buy_page_url( $leader ), 301 );
            exit;
        }
        return;
    }
    global $wp_query;
    $wp_query->set_404();
    status_header( 404 );
    nocache_headers();
}

function twshop_group_buy_page_template( $template ) {
    return twshop_group_buy_is_group_page() ? TWSHOP_PLUGIN_DIR . 'templates/group-buy-page.php' : $template;
}

function twshop_group_buy_page_title( $title ) {
    if ( ! twshop_group_buy_is_group_page() ) return $title;
    // 自訂路由在 WordPress 眼中是首頁，預設只有「網站名稱｜標語」；改成「團購名稱 - 網站名稱」
    return array( 'title' => twshop_group_buy_leader_title( twshop_group_buy_page_leader() ), 'site' => get_bloginfo( 'name', 'display' ) );
}

/** 團購頁完整標題「團購名稱 - 網站名稱」，給 SEO 外掛的標題濾鏡用。 */
function twshop_group_buy_page_full_title() {
    $sep = apply_filters( 'document_title_separator', '-' );
    return twshop_group_buy_leader_title( twshop_group_buy_page_leader() ) . ' ' . $sep . ' ' . get_bloginfo( 'name', 'display' );
}

function twshop_group_buy_page_description() {
    $leader = twshop_group_buy_page_leader();
    return $leader ? wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) ( $leader['intro'] ?? '' ) ) ), 80, '…' ) : '';
}

/**
 * SEO 外掛（Rank Math、Yoast）不認得團購頁這條自訂路由，主查詢落在首頁條件上，
 * 會把標題、canonical、og:url 換成部落格頁（文章頁面）的值、robots 改回 index。
 * 只在團購頁時覆寫這些輸出；沒有裝 SEO 外掛時 pre_get_document_title 回空字串，交回 document_title_parts。
 */
function twshop_group_buy_seo_title( $title ) {
    return twshop_group_buy_is_group_page() ? twshop_group_buy_page_full_title() : $title;
}

function twshop_group_buy_seo_document_title( $title ) {
    // 沒有其他外掛先接手時保持空字串，讓 WordPress 走 document_title_parts（twshop_group_buy_page_title）
    return twshop_group_buy_is_group_page() && '' !== (string) $title ? twshop_group_buy_page_full_title() : $title;
}

function twshop_group_buy_seo_description( $description ) {
    if ( ! twshop_group_buy_is_group_page() ) return $description;
    return twshop_group_buy_page_description();
}

function twshop_group_buy_seo_url( $url ) {
    if ( ! twshop_group_buy_is_group_page() ) return $url;
    return twshop_group_buy_page_url( twshop_group_buy_page_leader() );
}

function twshop_group_buy_seo_robots_array( $robots ) {
    if ( ! twshop_group_buy_is_group_page() ) return $robots;
    $robots = is_array( $robots ) ? $robots : array();
    // Rank Math 用 index/follow 鍵存值；Yoast 的 wpseo_robots_array 也是同樣的鍵
    $robots['index'] = 'noindex';
    $robots['follow'] = 'nofollow';
    return $robots;
}

function twshop_group_buy_seo_robots_string( $robots ) {
    return twshop_group_buy_is_group_page() ? 'noindex, nofollow' : $robots;
}

function twshop_group_buy_register_seo_filters() {
    add_filter( 'pre_get_document_title', 'twshop_group_buy_seo_document_title', 9999 );
    // Rank Math
    add_filter( 'rank_math/frontend/title', 'twshop_group_buy_seo_title', 9999 );
    add_filter( 'rank_math/frontend/description', 'twshop_group_buy_seo_description', 9999 );
    add_filter( 'rank_math/frontend/canonical', 'twshop_group_buy_seo_url', 9999 );
    add_filter( 'rank_math/frontend/robots', 'twshop_group_buy_seo_robots_array', 9999 );
    add_filter( 'rank_math/opengraph/url', 'twshop_group_buy_seo_url', 9999 );
    add_filter( 'rank_math/opengraph/facebook/og_title', 'twshop_group_buy_seo_title', 9999 );
    add_filter( 'rank_math/opengraph/facebook/og_description', 'twshop_group_buy_seo_description', 9999 );
    add_filter( 'rank_math/opengraph/twitter/twitter_title', 'twshop_group_buy_seo_title', 9999 );
    add_filter( 'rank_math/opengraph/twitter/twitter_description', 'twshop_group_buy_seo_description', 9999 );
    // Yoast SEO
    add_filter( 'wpseo_title', 'twshop_group_buy_seo_title', 9999 );
    add_filter( 'wpseo_opengraph_title', 'twshop_group_buy_seo_title', 9999 );
    add_filter( 'wpseo_twitter_title', 'twshop_group_buy_seo_title', 9999 );
    add_filter( 'wpseo_metadesc', 'twshop_group_buy_seo_description', 9999 );
    add_filter( 'wpseo_opengraph_desc', 'twshop_group_buy_seo_description', 9999 );
    add_filter( 'wpseo_twitter_description', 'twshop_group_buy_seo_description', 9999 );
    add_filter( 'wpseo_canonical', 'twshop_group_buy_seo_url', 9999 );
    add_filter( 'wpseo_opengraph_url', 'twshop_group_buy_seo_url', 9999 );
    add_filter( 'wpseo_robots_array', 'twshop_group_buy_seo_robots_array', 9999 );
    add_filter( 'wpseo_robots', 'twshop_group_buy_seo_robots_string', 9999 );
}

function twshop_group_buy_page_robots( $robots ) {
    if ( get_query_var( 'twshop_gb' ) ) { $robots['noindex'] = true; $robots['nofollow'] = true; }
    return $robots;
}

function twshop_group_buy_body_class( $classes ) {
    if ( twshop_group_buy_is_group_page() ) {
        $classes[] = 'woocommerce';
        $classes[] = 'woocommerce-page';
        $classes[] = 'twshop-group-buy-page';
    } elseif ( twshop_group_buy_checkout_leader() ) {
        $classes[] = 'twshop-group-buy-page';
        $classes[] = 'twshop-group-buy-checkout';
    }
    return $classes;
}

function twshop_group_buy_page_assets() {
    if ( twshop_group_buy_checkout_leader() ) {
        wp_enqueue_style( 'twshop-group-buy', TWSHOP_PLUGIN_URL . 'assets/css/group-buy.css', array(), filemtime( TWSHOP_PLUGIN_DIR . 'assets/css/group-buy.css' ) );
        return;
    }
    if ( ! twshop_group_buy_is_group_page() ) return;
    // 描述收合一律需要；倒數段落在沒有期間橫幅時自行略過
    wp_enqueue_script( 'twshop-group-buy-page', TWSHOP_PLUGIN_URL . 'assets/js/group-buy-page.js', array(), filemtime( TWSHOP_PLUGIN_DIR . 'assets/js/group-buy-page.js' ), true );
    wp_enqueue_script( 'wc-add-to-cart-variation' );
    wp_enqueue_script( 'wc-cart-fragments' );
    // 端點網址對所有訪客相同，可以放在可快取的頁面上；nonce 由購物車片段帶入。
    wp_enqueue_script( 'twshop-group-buy-cart', TWSHOP_PLUGIN_URL . 'assets/js/group-buy-cart.js', array( 'jquery', 'wc-cart-fragments' ), filemtime( TWSHOP_PLUGIN_DIR . 'assets/js/group-buy-cart.js' ), true );
    wp_localize_script( 'twshop-group-buy-cart', 'twshopGroupBuyCart', array( 'endpoint' => WC_AJAX::get_endpoint( 'twshop_gb_cart_update' ) ) );
    wp_enqueue_style( 'twshop-group-buy', TWSHOP_PLUGIN_URL . 'assets/css/group-buy.css', array(), filemtime( TWSHOP_PLUGIN_DIR . 'assets/css/group-buy.css' ) );
}

// ── 團購模式結帳頁 ───────────────────────────────────────────────────────
//
// 只有從團購頁「前往結帳」（網址帶 twshop_gb_checkout=代碼）進入時套用：結帳頁仍是 WooCommerce
// 原生結帳（不快取，金流、超商選店照常），只把外框換成與團購頁相同的獨立版型。代碼存在 WC session，
// 讓付款完成頁、付款失敗重付頁維持同一版型；從其他地方直接進結帳頁（不帶參數）則恢復一般版型。

function twshop_group_buy_checkout_url( $code ) {
    return add_query_arg( 'twshop_gb_checkout', $code, wc_get_checkout_url() );
}

function twshop_group_buy_checkout_detect() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! WC()->session ) return;
    if ( is_wc_endpoint_url() ) return; // 付款完成／重新付款頁沿用 session 狀態
    $raw = $_GET['twshop_gb_checkout'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只決定版面，不改資料。
    $leader = is_string( $raw ) && '' !== $raw ? twshop_group_buy_find_active_leader( wp_unslash( $raw ) ) : null;
    WC()->session->set( 'twshop_gb_checkout', $leader ? $leader['code'] : null );
}

/** 目前是否為團購模式的結帳相關頁面，是則回傳團購主。 */
function twshop_group_buy_checkout_leader() {
    if ( is_admin() || ! twshop_group_buy_enabled() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) return null;
    if ( ! function_exists( 'WC' ) || ! WC()->session ) return null;
    $code = WC()->session->get( 'twshop_gb_checkout' );
    return is_string( $code ) && '' !== $code ? twshop_group_buy_find_active_leader( $code ) : null;
}

function twshop_group_buy_checkout_template( $template ) {
    return twshop_group_buy_checkout_leader() ? TWSHOP_PLUGIN_DIR . 'templates/group-buy-checkout.php' : $template;
}

/** 專屬頁不放會帶離本頁的元件：終極詢價的詢價清單浮動鈕（連到詢價清單頁）。LINE 客服鈕保留。 */
function twshop_group_buy_strip_page_distractions() {
    if ( ( ! twshop_group_buy_is_group_page() && ! twshop_group_buy_checkout_leader() ) || ! class_exists( 'ULTQ_Frontend' ) ) return;
    remove_action( 'wp_footer', array( 'ULTQ_Frontend', 'take_over_line_float' ), 1 );
    remove_action( 'wp_footer', array( 'ULTQ_Frontend', 'render_float_button' ) );
}

/**
 * 專屬頁商品：指定、已發布的父商品，保留後台排序；目錄隱藏商品仍可在此頁販售。
 * 外部商品與組合商品（群組）的購買按鈕會連到其他頁面，不放上團購頁。
 */
function twshop_group_buy_page_products( array $leader ) {
    $ids = twshop_group_buy_leader_product_ids( $leader['user_id'] );
    $products = array();
    foreach ( $ids as $id ) {
        $product = wc_get_product( $id );
        if ( ! $product || 'publish' !== $product->get_status() || $product->is_type( array( 'variation', 'external', 'grouped' ) ) ) continue;
        if ( twshop_is_wallet_credit_product( $product ) ) continue;
        $products[] = $product;
    }
    return $products;
}

/** 商品卡的購買區：WooCommerce 原生表單（數量、規格），送回團購頁；不顯示商品頁加購區塊。 */
function twshop_group_buy_render_add_to_cart( WC_Product $product ) {
    $previous = $GLOBALS['product'] ?? null;
    $GLOBALS['product'] = $product;
    $addons = has_action( 'woocommerce_before_add_to_cart_button', 'twshop_render_pdp_addons' );
    if ( false !== $addons ) remove_action( 'woocommerce_before_add_to_cart_button', 'twshop_render_pdp_addons', $addons );
    ob_start();
    woocommerce_template_single_add_to_cart();
    $form = ob_get_clean();
    if ( false !== $addons ) add_action( 'woocommerce_before_add_to_cart_button', 'twshop_render_pdp_addons', $addons );
    $GLOBALS['product'] = $previous;
    return $form;
}

/** 簡短描述照原樣輸出，但移除連結，避免顧客從說明文字跳到其他頁面。 */
function twshop_group_buy_short_description( WC_Product $product ) {
    $text = trim( (string) $product->get_short_description() );
    if ( '' === $text ) return '';
    $allowed = wp_kses_allowed_html( 'post' );
    unset( $allowed['a'] );
    return wp_kses( wpautop( $text ), $allowed );
}

/** 加入購物車結果訊息：只在 POST 回應輸出，快取的 GET 頁不帶個人訊息。 */
function twshop_group_buy_render_notices( $is_post ) {
    if ( ! $is_post || ! function_exists( 'woocommerce_output_all_notices' ) ) return '';
    ob_start();
    woocommerce_output_all_notices();
    $html = trim( ob_get_clean() );
    return '' === $html ? '' : '<div class="twshop-gb__notices woocommerce">' . $html . '</div>';
}

/** 團購商品格線（內建版型與 Elementor「團購商品」小工具共用）。 */
function twshop_group_buy_render_products( $leader, $empty_text = '目前沒有團購商品。' ) {
    $state = is_array( $leader ) ? twshop_group_buy_period_state( $leader ) : 'open';
    if ( 'ended' === $state ) return '<p class="twshop-gb__empty">本團購已結束，感謝支持。</p>';
    $products = is_array( $leader ) ? twshop_group_buy_page_products( $leader ) : array();
    if ( ! $products ) return '<p class="twshop-gb__empty">' . esc_html( $empty_text ) . '</p>';
    $previous_post = $GLOBALS['post'] ?? null;
    ob_start();
    echo '<div class="twshop-gb__grid woocommerce">';
    foreach ( $products as $product ) {
        $GLOBALS['post'] = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        setup_postdata( $GLOBALS['post'] );
        $desc = twshop_group_buy_short_description( $product );
        echo '<article class="twshop-gb__card product type-product ' . esc_attr( 'product-type-' . $product->get_type() ) . '">';
        echo '<div class="twshop-gb__image images">' . wp_kses_post( $product->get_image( 'woocommerce_single' ) ) . '</div>';
        echo '<div class="twshop-gb__body summary">';
        echo '<h2 class="twshop-gb__name">' . esc_html( $product->get_name() ) . '</h2>';
        echo '<p class="price">' . wp_kses_post( $product->get_price_html() ) . '</p>';
        if ( $desc ) echo '<div class="twshop-gb__desc">' . $desc . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已 wp_kses。
        // 尚未開始只預告商品與團購價，不輸出購買表單。
        if ( 'upcoming' === $state ) echo '<div class="twshop-gb__buy twshop-gb__buy--soon"><p>團購開始後即可購買</p></div>';
        else echo '<div class="twshop-gb__buy">' . twshop_group_buy_render_add_to_cart( $product ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce 原生樣板。
        echo '</div></article>';
    }
    echo '</div>';
    $html = ob_get_clean();
    $GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
    if ( $previous_post instanceof WP_Post ) setup_postdata( $previous_post );
    return $html;
}

/**
 * 團購期間橫幅：倒數由 assets/js/group-buy-page.js 在瀏覽器依當下時間更新；
 * 頁面可能被 CDN 快取，跨過開始／結束時間時腳本會重新載入一次取得新狀態（伺服器端加入購物車與結帳也會再驗證）。
 */
function twshop_group_buy_render_period_notice( array $leader ) {
    $period = twshop_group_buy_period( $leader );
    if ( ! $period['start'] && ! $period['end'] ) return '';
    $state = twshop_group_buy_period_state( $leader );
    if ( 'upcoming' === $state ) {
        $text = '團購將於 ' . twshop_group_buy_format_time( $period['start'] ) . ' 開始';
        $target = $period['start'];
    } elseif ( 'ended' === $state ) {
        $text = '團購已於 ' . twshop_group_buy_format_time( $period['end'] ) . ' 結束';
        $target = 0;
    } else {
        $text = $period['end'] ? '團購將於 ' . twshop_group_buy_format_time( $period['end'] ) . ' 結束' : '';
        $target = $period['end'];
    }
    if ( '' === $text ) return '';
    return sprintf(
        '<div class="twshop-gb-period twshop-gb-period--%1$s" data-state="%1$s" data-start="%2$d" data-end="%3$d"><span class="twshop-gb-period__text">%4$s</span>%5$s</div>',
        esc_attr( $state ),
        $period['start'],
        $period['end'],
        esc_html( $text ),
        $target ? '<span class="twshop-gb-period__countdown" data-target="' . (int) $target . '" aria-live="off"></span>' : ''
    );
}

function twshop_group_buy_is_page_request() {
    return isset( $_REQUEST['twshop_group_buy'] ) && is_string( $_REQUEST['twshop_group_buy'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/** 從團購頁加入購物車後留在原頁（即使商店設定「加入後前往購物車」）。 */
function twshop_group_buy_add_to_cart_redirect( $url, $product = null ) {
    return twshop_group_buy_is_page_request() ? false : $url;
}

/** 加入成功訊息原本帶「查看購物車」連結，團購頁上改成純文字。 */
function twshop_group_buy_add_to_cart_message( $message, $products = array(), $show_qty = false ) {
    return twshop_group_buy_is_page_request() ? trim( preg_replace( '#<a\b[^>]*>.*?</a>#is', '', $message ) ) : $message;
}

/**
 * 底部購物車列內容。快取的 GET 頁面一律輸出空白佔位，由 WooCommerce 購物車片段（AJAX，不快取）
 * 換成該顧客的實際件數與小計；POST（剛加入購物車）的回應不會被快取，直接輸出實際內容。
 */
function twshop_group_buy_cartbar_summary( $live = false ) {
    $count = 0;
    $total = '';
    if ( $live && function_exists( 'WC' ) && WC()->cart ) {
        $count = (int) WC()->cart->get_cart_contents_count();
        $total = WC()->cart->get_cart_subtotal();
    }
    $text = $count ? sprintf( '購物車 %d 件　小計 %s', $count, $total ) : '購物車是空的';
    return '<div class="twshop-gb-cartbar__summary" data-count="' . (int) $count . '">' . wp_kses_post( $text ) . '</div>';
}

function twshop_group_buy_cart_fragments( $fragments ) {
    $fragments['div.twshop-gb-cartbar__summary'] = twshop_group_buy_cartbar_summary( true );
    $fragments['div.twshop-gb-cartpanel__items'] = twshop_group_buy_cart_panel_items( true );
    return $fragments;
}

/**
 * 團購頁購物車面板內容。GET 頁面輸出空白佔位（頁面可被 CDN 快取），由購物車片段在瀏覽器端補上；
 * nonce 放在片段裡（每位顧客各自的 session），不放在可快取的頁面上。
 * 加購品、贈品等外掛特殊項目只顯示、不能在這裡改數量或刪除，避免繞過規則。
 */
function twshop_group_buy_cart_panel_items( $live = false ) {
    if ( ! $live || ! function_exists( 'WC' ) || ! WC()->cart ) return '<div class="twshop-gb-cartpanel__items" data-loading="1"></div>';
    $cart = WC()->cart;
    $html = '<div class="twshop-gb-cartpanel__items" data-nonce="' . esc_attr( wp_create_nonce( 'twshop_gb_cart' ) ) . '">';
    if ( $cart->is_empty() ) return $html . '<p class="twshop-gb-cartpanel__empty">購物車是空的。</p></div>';
    $html .= '<ul class="twshop-gb-cartpanel__list">';
    foreach ( $cart->get_cart() as $key => $item ) {
        $product = $item['data'] ?? null;
        if ( ! $product instanceof WC_Product || ! apply_filters( 'woocommerce_widget_cart_item_visible', true, $item, $key ) ) continue;
        $qty = (int) $item['quantity'];
        $special = twshop_is_twshop_special_cart_item( $item );
        $max = $product->is_sold_individually() ? 1 : (int) $product->get_max_purchase_quantity();
        $html .= '<li class="twshop-gb-cartpanel__item" data-key="' . esc_attr( $key ) . '">';
        $html .= '<div class="twshop-gb-cartpanel__thumb">' . wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ) . '</div>';
        $html .= '<div class="twshop-gb-cartpanel__info"><div class="twshop-gb-cartpanel__name">' . esc_html( $product->get_name() ) . '</div>';
        $meta = wc_get_formatted_cart_item_data( $item, true );
        if ( '' !== trim( $meta ) ) $html .= '<div class="twshop-gb-cartpanel__meta">' . nl2br( esc_html( trim( $meta ) ) ) . '</div>';
        $html .= '<div class="twshop-gb-cartpanel__price">' . wp_kses_post( apply_filters( 'woocommerce_cart_item_price', $cart->get_product_price( $product ), $item, $key ) ) . '</div></div>';
        $html .= '<div class="twshop-gb-cartpanel__qty">';
        if ( $special || 1 === $max ) {
            $html .= '<span class="twshop-gb-cartpanel__qty-fixed">× ' . $qty . '</span>';
        } else {
            $html .= '<button type="button" class="twshop-gb-qty-btn" data-step="-1" aria-label="減少數量">−</button>';
            $html .= '<input type="number" class="twshop-gb-qty" min="0"' . ( $max > 0 ? ' max="' . $max . '"' : '' ) . ' step="1" value="' . $qty . '" aria-label="' . esc_attr( $product->get_name() . ' 數量' ) . '">';
            $html .= '<button type="button" class="twshop-gb-qty-btn" data-step="1" aria-label="增加數量">+</button>';
        }
        $html .= '</div><div class="twshop-gb-cartpanel__subtotal">' . wp_kses_post( apply_filters( 'woocommerce_cart_item_subtotal', $cart->get_product_subtotal( $product, $qty ), $item, $key ) ) . '</div>';
        $html .= $special ? '<span class="twshop-gb-cartpanel__remove-placeholder"></span>' : '<button type="button" class="twshop-gb-cartpanel__remove" aria-label="' . esc_attr( '移除 ' . $product->get_name() ) . '">×</button>';
        $html .= '</li>';
    }
    $html .= '</ul><div class="twshop-gb-cartpanel__total"><span>小計</span><strong>' . wp_kses_post( $cart->get_cart_subtotal() ) . '</strong></div></div>';
    return $html;
}

/**
 * 團購頁購物車面板：修改數量（0＝移除）。走 WooCommerce 的 wc-ajax 端點，回傳更新後的購物車片段。
 * 只能改一般商品；加購品、贈品等特殊項目回傳錯誤。
 */
function twshop_group_buy_ajax_cart_update() {
    if ( ! check_ajax_referer( 'twshop_gb_cart', 'nonce', false ) ) wp_send_json_error( array( 'message' => '頁面已過期，請重新整理後再試。' ), 403 );
    $cart = WC()->cart;
    $key = isset( $_POST['cart_item_key'] ) ? wc_clean( wp_unslash( $_POST['cart_item_key'] ) ) : '';
    $item = $cart && is_string( $key ) ? $cart->get_cart_item( $key ) : array();
    if ( ! $item ) wp_send_json_error( array( 'message' => '找不到這個購物車項目，請重新整理頁面。' ) );
    if ( twshop_is_twshop_special_cart_item( $item ) ) wp_send_json_error( array( 'message' => '這個項目不能在這裡修改。' ) );
    $raw_qty = isset( $_POST['quantity'] ) && is_scalar( $_POST['quantity'] ) ? trim( (string) wp_unslash( $_POST['quantity'] ) ) : '';
    $qty = ctype_digit( $raw_qty ) ? (int) $raw_qty : -1;
    if ( $qty < 0 ) wp_send_json_error( array( 'message' => '數量不正確。' ) );
    $product = $item['data'];
    if ( 0 === $qty ) {
        $cart->remove_cart_item( $key );
    } else {
        $max = $product->is_sold_individually() ? 1 : (int) $product->get_max_purchase_quantity();
        if ( $max > 0 && $qty > $max ) {
            wp_send_json_error( array( 'message' => sprintf( '「%s」最多只能購買 %d 件。', $product->get_name(), $max ) ) );
        }
        if ( ! apply_filters( 'woocommerce_update_cart_validation', true, $key, $item, $qty ) ) {
            $notices = wc_get_notices( 'error' );
            wc_clear_notices();
            wp_send_json_error( array( 'message' => $notices ? wp_strip_all_tags( $notices[0]['notice'] ) : '無法更新數量。' ) );
        }
        $cart->set_quantity( $key, $qty, true );
    }
    $cart->calculate_totals();
    wc_clear_notices(); // 移除商品的「已移除，復原？」通知不會在團購頁顯示，避免留到下一頁
    WC_AJAX::get_refreshed_fragments();
}

function twshop_group_buy_form_hidden_field() {
    $leader = twshop_group_buy_context_leader();
    if ( $leader ) echo '<input type="hidden" name="twshop_group_buy" value="' . esc_attr( $leader['code'] ) . '">';
}

function twshop_group_buy_form_action( $action ) {
    $leader = twshop_group_buy_context_leader();
    return $leader ? twshop_group_buy_page_url( $leader ) : $action;
}

/** 規格資料全部內嵌在頁面裡，避免 AJAX 取規格時失去團購頁情境。 */
function twshop_group_buy_variation_threshold( $threshold, $product = null ) {
    return twshop_group_buy_context_leader() ? max( (int) $threshold, 1000 ) : $threshold;
}

// ── 佣金帳本 ─────────────────────────────────────────────────────────────

function twshop_group_buy_transactions_supported() {
    global $wpdb;
    static $supported = null;
    if ( null === $supported ) {
        $tables = array_values( twshop_group_buy_tables() );
        $engines = $wpdb->get_col( $wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode( ', ', array_fill( 0, count( $tables ), '%s' ) ) . ')',
            $tables
        ) );
        $supported = count( $engines ) === count( $tables ) && count( array_filter( $engines, fn( $e ) => 'INNODB' === strtoupper( $e ) ) ) === count( $tables );
    }
    return $supported;
}

/**
 * 鎖住團購主資料列後執行 $callback；任何例外都回滾。不可巢狀在點數交易內，
 * 避免另開交易把外層尚未提交的點數寫入一起提交。
 */
function twshop_group_buy_transaction( $leader_id, callable $callback ) {
    global $wpdb;
    static $active = false;
    if ( $active || ! empty( $GLOBALS['twshop_points_transaction_context'] ) ) throw new LogicException( '團購佣金交易不可巢狀。' );
    if ( ! twshop_group_buy_transactions_supported() ) throw new RuntimeException( '團購佣金需要 InnoDB 資料表。' );
    $active = true;
    $wpdb->query( 'START TRANSACTION' );
    try {
        $locked = $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . twshop_group_buy_tables()['leaders'] . ' WHERE user_id = %d FOR UPDATE', $leader_id ) );
        if ( (int) $locked !== (int) $leader_id || $wpdb->last_error ) throw new RuntimeException( '無法鎖定團購主資料。' );
        $result = $callback();
        if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( '團購佣金提交失敗。' );
        $active = false;
        return $result;
    } catch ( Throwable $e ) {
        $wpdb->query( 'ROLLBACK' );
        $active = false;
        throw $e;
    }
}

/** 整張訂單的實付基準：含稅、儲值金算實付，扣除運費與儲值金商品。 */
function twshop_group_buy_order_base( WC_Order $order ) {
    $base = (float) $order->get_total() - (float) $order->get_shipping_total() - (float) $order->get_shipping_tax()
        + (float) $order->get_meta( '_twshop_wallet_applied' );
    foreach ( $order->get_items() as $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) continue;
        $product = $item->get_product();
        if ( $product && twshop_is_wallet_credit_product( $product ) ) $base -= (float) $item->get_total() + (float) $item->get_total_tax();
    }
    return round( max( 0, $base ), 2 );
}

/**
 * 某團購主在這張訂單的計佣資料：只算他的團購商品。全單層級的點數折抵、滿額折扣等依商品金額比例分攤，
 * 退款依「指定給他的商品的逐項退款＋未指定商品的金額退款按比例」計算。
 */
function twshop_group_buy_order_leader_share( WC_Order $order, $leader_id ) {
    $all = $mine = 0.0;
    $item_ids = array();
    $tiers = null;
    foreach ( $order->get_items() as $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) continue;
        $product = $item->get_product();
        if ( $product && twshop_is_wallet_credit_product( $product ) ) continue;
        $gross = (float) $item->get_total() + (float) $item->get_total_tax();
        $all += $gross;
        if ( (int) $item->get_meta( '_twshop_gb_leader_id' ) === (int) $leader_id ) {
            $mine += $gross;
            $item_ids[] = $item->get_id();
            $tiers = $tiers ?? twshop_group_buy_normalize_tiers( $item->get_meta( '_twshop_gb_tiers' ) )
                ?? array( array( 'from' => 0.0, 'rate' => twshop_group_buy_clamp_rate( $item->get_meta( '_twshop_gb_rate' ) ) ) );
        }
    }
    $base = $all > 0 ? round( twshop_group_buy_order_base( $order ) * $mine / $all, 2 ) : 0.0;

    // 刪除退款時 WooCommerce 只清 refund_ids 快取，get_total_refunded() 可能是舊值；改由退款清單逐筆計算。
    $refunded = 0.0;
    $order_total = (float) $order->get_total();
    foreach ( $order->get_refunds() as $refund ) {
        $itemized = 0.0;
        foreach ( $refund->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $r_item ) {
            $amount = abs( (float) $r_item->get_total() + (float) $r_item->get_total_tax() );
            $itemized += $amount;
            if ( $r_item instanceof WC_Order_Item_Product && in_array( (int) $r_item->get_meta( '_refunded_item_id' ), $item_ids, true ) ) $refunded += $amount;
        }
        $unallocated = max( 0, abs( (float) $refund->get_amount() ) - $itemized );
        if ( $unallocated > 0 && $order_total > 0 ) $refunded += $unallocated * $mine / $order_total;
    }
    $ratio = $mine > 0 ? min( 1, $refunded / $mine ) : 1;
    $tiers = $tiers ?? array( array( 'from' => 0.0, 'rate' => 0.0 ) );
    return array( 'base' => $base, 'tiers' => $tiers, 'rate' => (float) $tiers[0]['rate'], 'refund_ratio' => $ratio, 'has_items' => $mine > 0 );
}

function twshop_group_buy_order_leader_ids( WC_Order $order ) {
    $ids = $order->get_meta( '_twshop_gb_leader_ids' );
    return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
}

/**
 * 把每位團購主在這張訂單的帳本調整到應有淨額。帳本只新增不修改，重複呼叫冪等：
 * 首次完成建立 earn；取消／失敗／全退歸零；部分退款依比例扣回；已結算紀錄不動，差額另記一筆。
 */
function twshop_group_buy_reconcile_order( $order ) {
    if ( ! $order instanceof WC_Order ) $order = wc_get_order( $order );
    if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) return 'skipped';
    $leader_ids = twshop_group_buy_order_leader_ids( $order );
    if ( ! $leader_ids ) return 'skipped';
    $results = array();
    foreach ( $leader_ids as $leader_id ) $results[] = twshop_group_buy_reconcile_leader( $order, $leader_id );
    foreach ( $results as $r ) if ( is_wp_error( $r ) ) return $r;
    return in_array( 'updated', $results, true ) ? 'updated' : ( in_array( 'unchanged', $results, true ) ? 'unchanged' : 'skipped' );
}

function twshop_group_buy_reconcile_leader( WC_Order $order, $leader_id ) {
    global $wpdb;
    $t = twshop_group_buy_tables();
    $order_id = $order->get_id();
    $earn_key = 'earn:' . $order_id . ':' . $leader_id;
    try {
        $outcome = twshop_group_buy_transaction( $leader_id, function () use ( $wpdb, $t, $order, $order_id, $leader_id, $earn_key ) {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT entry_key, amount, base_amount FROM {$t['commissions']} WHERE order_id = %d AND leader_id = %d", $order_id, $leader_id ), ARRAY_A );
            if ( $wpdb->last_error ) throw new RuntimeException( '無法讀取佣金帳本。' );
            $earn = null;
            $earn_base = 0.0;
            $current = $current_base = 0.0;
            foreach ( $rows as $row ) {
                $current += (float) $row['amount'];
                $current_base += (float) $row['base_amount'];
                if ( $earn_key === $row['entry_key'] ) { $earn = (float) $row['amount']; $earn_base = (float) $row['base_amount']; }
            }
            $count = count( $rows );
            $status = $order->get_status();
            $share = twshop_group_buy_order_leader_share( $order, $leader_id );
            $inserted = array();
            if ( null === $earn ) {
                if ( 'completed' !== $status || ! $share['has_items'] ) return array( 'result' => 'skipped' );
                // 累計業績位置：鎖住團購主列後讀取其他訂單的淨業績，同一團購主的入帳依序進行。
                $position = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(base_amount),0) FROM {$t['commissions']} WHERE leader_id = %d AND order_id <> %d", $leader_id, $order_id ) );
                if ( $wpdb->last_error ) throw new RuntimeException( '無法讀取累計業績。' );
                $earn = twshop_group_buy_tier_commission( $position, $share['base'], $share['tiers'] );
                $effective = $share['base'] > 0 ? min( 100, round( $earn / $share['base'] * 100, 2 ) ) : 0;
                $earn_base = $share['base'];
                $ok = $wpdb->insert( $t['commissions'], array(
                    'leader_id' => $leader_id, 'order_id' => $order_id, 'entry_key' => $earn_key,
                    'base_amount' => $share['base'], 'rate' => $effective, 'amount' => $earn, 'note' => 1 === count( $share['tiers'] ) ? '訂單完成' : '訂單完成（累進，累計業績 ' . wp_strip_all_tags( wc_price( $position ) ) . ' 起算）',
                    'created_at' => current_time( 'mysql' ),
                ), array( '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s' ) );
                if ( 1 !== $ok ) throw new RuntimeException( '無法寫入團購佣金。' );
                $current += $earn;
                $current_base += $earn_base;
                $count++;
                $inserted[] = array( '佣金入帳', $earn );
            }
            // 佣金與累計業績一起調整：取消／全退歸零，部分退款依比例；已入帳的累進位置不回溯重算。
            $voided = in_array( $status, array( 'cancelled', 'failed', 'refunded' ), true );
            $target = $voided ? 0.0 : round( $earn * ( 1 - $share['refund_ratio'] ), 2 );
            $target_base = $voided ? 0.0 : round( $earn_base * ( 1 - $share['refund_ratio'] ), 2 );
            $delta = round( $target - $current, 2 );
            $base_delta = round( $target_base - $current_base, 2 );
            if ( abs( $delta ) >= 0.01 || abs( $base_delta ) >= 0.01 ) {
                $note = $delta < 0 || ( 0.0 === $delta && $base_delta < 0 ) ? ( $target_base <= 0 ? '取消或全額退款扣回' : '部分退款依比例扣回' ) : '訂單恢復，補回佣金';
                $ok = $wpdb->insert( $t['commissions'], array(
                    'leader_id' => $leader_id, 'order_id' => $order_id, 'entry_key' => 'adj:' . $order_id . ':' . $leader_id . ':' . $count,
                    'base_amount' => $base_delta, 'rate' => 0, 'amount' => $delta, 'note' => $note,
                    'created_at' => current_time( 'mysql' ),
                ), array( '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s' ) );
                if ( 1 !== $ok ) throw new RuntimeException( '無法寫入團購佣金調整。' );
                $inserted[] = array( $note, $delta );
            }
            return array( 'result' => $inserted ? 'updated' : 'unchanged', 'inserted' => $inserted );
        } );
    } catch ( Throwable $e ) {
        wc_get_logger()->error( $e->getMessage(), array( 'source' => 'twshop-group-buy', 'order_id' => $order_id, 'leader_id' => $leader_id ) );
        $order->add_order_note( '⚠️ 團購佣金處理失敗，請查看 WooCommerce 記錄（twshop-group-buy）。' );
        return new WP_Error( 'group_buy_commission', $e->getMessage() );
    }
    foreach ( $outcome['inserted'] ?? array() as $entry ) {
        if ( abs( (float) $entry[1] ) < 0.01 ) continue; // 只調整累計業績、金額不變時不留備註
        $order->add_order_note( sprintf( '團購佣金%s：%s（團購主 #%d）', $entry[0], wp_strip_all_tags( wc_price( $entry[1] ) ), $leader_id ) );
    }
    return $outcome['result'];
}

function twshop_group_buy_on_status_changed( $order_id ) {
    twshop_group_buy_reconcile_order( wc_get_order( $order_id ) );
}

function twshop_group_buy_on_refund_deleted( $refund_id, $order_id ) {
    twshop_group_buy_reconcile_order( wc_get_order( $order_id ) );
}

/** 模組停用時既有佣金仍隨退款／取消扣回，帳本保持一致。 */
function twshop_group_buy_init_reconcile() {
    add_action( 'woocommerce_order_status_changed', 'twshop_group_buy_on_status_changed', 30, 1 );
    add_action( 'woocommerce_order_refunded', 'twshop_group_buy_on_status_changed', 30, 1 );
    add_action( 'woocommerce_refund_deleted', 'twshop_group_buy_on_refund_deleted', 30, 2 );
}

/** 未結算紀錄整批結算；合計須大於 0。只結算鎖內撈到的紀錄，之後新增的留給下次。 */
function twshop_group_buy_settle( $leader_id, $note = '' ) {
    global $wpdb;
    $leader_id = absint( $leader_id );
    if ( ! twshop_group_buy_get_leader( $leader_id ) ) return new WP_Error( 'group_buy_settle', '找不到團購主。' );
    $t = twshop_group_buy_tables();
    try {
        return twshop_group_buy_transaction( $leader_id, function () use ( $wpdb, $t, $leader_id, $note ) {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, amount FROM {$t['commissions']} WHERE leader_id = %d AND settlement_id IS NULL", $leader_id ), ARRAY_A );
            if ( $wpdb->last_error ) throw new RuntimeException( '無法讀取未結算佣金。' );
            $sum = round( array_sum( array_map( 'floatval', array_column( $rows, 'amount' ) ) ), 2 );
            if ( $sum <= 0 ) return new WP_Error( 'group_buy_settle', '未結算金額須大於 0 才能結算。' );
            $ok = $wpdb->insert( $t['settlements'], array(
                'leader_id' => $leader_id, 'amount' => $sum, 'note' => mb_substr( sanitize_text_field( $note ), 0, 190 ),
                'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql' ),
            ), array( '%d', '%f', '%s', '%d', '%s' ) );
            if ( 1 !== $ok ) throw new RuntimeException( '無法建立結算紀錄。' );
            $settlement_id = (int) $wpdb->insert_id;
            $ids = array_map( 'intval', array_column( $rows, 'id' ) );
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE {$t['commissions']} SET settlement_id = %d WHERE settlement_id IS NULL AND id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
                array_merge( array( $settlement_id ), $ids )
            ) );
            if ( $updated !== count( $ids ) ) throw new RuntimeException( '結算紀錄數量不一致。' );
            return array( 'settlement_id' => $settlement_id, 'amount' => $sum, 'count' => count( $ids ) );
        } );
    } catch ( Throwable $e ) {
        wc_get_logger()->error( $e->getMessage(), array( 'source' => 'twshop-group-buy', 'leader_id' => $leader_id ) );
        return new WP_Error( 'group_buy_settle', '結算失敗，請查看 WooCommerce 記錄。' );
    }
}

/** 團購主未結算／累計佣金（可一次查多位）。 */
function twshop_group_buy_leader_totals( array $leader_ids = array() ) {
    global $wpdb;
    $t = twshop_group_buy_tables();
    $where = '';
    if ( $leader_ids ) {
        $leader_ids = array_map( 'absint', $leader_ids );
        $where = $wpdb->prepare( ' WHERE leader_id IN (' . implode( ',', array_fill( 0, count( $leader_ids ), '%d' ) ) . ')', $leader_ids );
    }
    $rows = $wpdb->get_results( "SELECT leader_id, SUM(amount) AS total, SUM(CASE WHEN settlement_id IS NULL THEN amount ELSE 0 END) AS unsettled
        FROM {$t['commissions']}{$where} GROUP BY leader_id", ARRAY_A );
    $out = array();
    foreach ( $rows as $row ) $out[ (int) $row['leader_id'] ] = array( 'total' => (float) $row['total'], 'unsettled' => (float) $row['unsettled'] );
    return $out;
}

// ── 會員中心 ─────────────────────────────────────────────────────────────

function twshop_group_buy_current_user_leader() {
    if ( ! is_user_logged_in() || ! twshop_group_buy_enabled() ) return null;
    $row = twshop_group_buy_get_leader( get_current_user_id() );
    return $row && 'active' === $row['status'] ? $row : null;
}

function twshop_group_buy_enqueue_account_script() {
    if ( ! is_account_page() || ! is_wc_endpoint_url( 'my-group-buy' ) || ! twshop_group_buy_current_user_leader() ) return;
    wp_enqueue_style( 'twshop-referrals', plugins_url( 'assets/css/referrals.css', TWSHOP_PLUGIN_FILE ), array(), filemtime( TWSHOP_PLUGIN_DIR . 'assets/css/referrals.css' ) );
    wp_enqueue_script( 'twshop-referrals', plugins_url( 'assets/js/referrals.js', TWSHOP_PLUGIN_FILE ), array(), filemtime( TWSHOP_PLUGIN_DIR . 'assets/js/referrals.js' ), true );
}

function twshop_group_buy_account_content() {
    $leader = twshop_group_buy_current_user_leader();
    if ( ! $leader ) { echo '<p>此頁僅供團購主使用。</p>'; return; }
    global $wpdb;
    $t = twshop_group_buy_tables();
    $totals = twshop_group_buy_leader_totals( array( (int) $leader['user_id'] ) )[ (int) $leader['user_id'] ] ?? array( 'total' => 0, 'unsettled' => 0 );
    $url = twshop_group_buy_page_url( $leader );
    echo '<div class="twshop-account-section twshop-referral-section twshop-group-buy-section">';
    echo '<h2>' . esc_html( twshop_group_buy_leader_title( $leader ) ) . '</h2>';
    $tiers = twshop_group_buy_leader_tiers( $leader );
    $performance = twshop_group_buy_leader_performance( $leader['user_id'] );
    $tier_index = twshop_group_buy_tier_index_at( $performance, $tiers );
    echo '<p>分享團購專屬頁給團員。團員在專屬頁把商品加入購物車並完成訂單後，依這些團購商品實付金額（不含運費）記錄佣金。</p>';
    if ( count( $tiers ) > 1 ) {
        echo '<p>佣金依累計業績分段累進：' . esc_html( twshop_group_buy_describe_tiers( $tiers ) ) . '。</p>';
        echo '<p>目前累計業績：<strong>' . wp_kses_post( wc_price( $performance ) ) . '</strong>，適用 <strong>' . esc_html( wc_format_localized_decimal( $tiers[ $tier_index ]['rate'] ) ) . '%</strong>';
        if ( isset( $tiers[ $tier_index + 1 ] ) ) {
            echo '；再 ' . wp_kses_post( wc_price( $tiers[ $tier_index + 1 ]['from'] - $performance ) ) . ' 達下一級 ' . esc_html( wc_format_localized_decimal( $tiers[ $tier_index + 1 ]['rate'] ) ) . '%';
        }
        echo '。</p>';
    } else {
        echo '<p>佣金比例：<strong>' . esc_html( wc_format_localized_decimal( $tiers[0]['rate'] ) ) . '%</strong>　累計業績：<strong>' . wp_kses_post( wc_price( $performance ) ) . '</strong></p>';
    }
    echo '<p><label for="twshop-referral-url">團購專屬頁連結</label><input id="twshop-referral-url" class="input-text" type="text" readonly value="' . esc_attr( $url ) . '"></p>';
    echo '<p class="twshop-referral-actions"><button type="button" class="button" id="twshop-copy-referral-url">複製團購連結</button> <span id="twshop-referral-copy-status" role="status" aria-live="polite"></span></p>';
    echo '<p>未結算佣金：<strong>' . wp_kses_post( wc_price( $totals['unsettled'] ) ) . '</strong>　累計佣金：<strong>' . wp_kses_post( wc_price( $totals['total'] ) ) . '</strong></p>';
    echo '<p>佣金由商店線下匯款結算；取消或退款會依比例扣回，已結算部分於下次結算扣除。</p>';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT order_id, SUM(amount) AS amount, MAX(base_amount) AS base_amount, MIN(created_at) AS created_at,
            SUM(CASE WHEN settlement_id IS NULL THEN 1 ELSE 0 END) AS open_entries
         FROM {$t['commissions']} WHERE leader_id = %d GROUP BY order_id ORDER BY order_id DESC LIMIT 50",
        (int) $leader['user_id']
    ), ARRAY_A );
    echo '<h3>最近 50 筆團購訂單</h3>';
    if ( ! $rows ) { echo '<p>尚無已完成的團購訂單。</p></div>'; return; }
    echo '<table class="woocommerce-table shop_table shop_table_responsive"><thead><tr><th>訂單</th><th>完成日期</th><th>計佣金額</th><th>佣金</th><th>狀態</th></tr></thead><tbody>';
    foreach ( $rows as $row ) {
        $order = wc_get_order( (int) $row['order_id'] );
        echo '<tr><td data-title="訂單">#' . esc_html( $order ? $order->get_order_number() : $row['order_id'] ) . '</td>';
        echo '<td data-title="完成日期">' . esc_html( mysql2date( get_option( 'date_format' ), $row['created_at'] ) ) . '</td>';
        echo '<td data-title="計佣金額">' . wp_kses_post( wc_price( $row['base_amount'] ) ) . '</td>';
        echo '<td data-title="佣金">' . wp_kses_post( wc_price( $row['amount'] ) ) . '</td>';
        echo '<td data-title="狀態">' . ( (int) $row['open_entries'] > 0 ? '未結算' : '已結算' ) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

// ── 訂單列表：「團購」欄位與依團購主篩選（HPOS 與傳統文章式訂單列表各一組）──────────

function twshop_group_buy_order_list_columns( $columns ) {
    $result = array();
    foreach ( $columns as $key => $label ) {
        $result[ $key ] = $label;
        if ( 'order_status' === $key ) $result['twshop_group_buy'] = '團購';
    }
    if ( ! isset( $result['twshop_group_buy'] ) ) $result['twshop_group_buy'] = '團購';
    return $result;
}

/** 每位團購主一行：團購名稱＋這張訂單的佣金狀態（未入帳／已入帳／已結算／已扣回）。 */
function twshop_group_buy_order_list_column_content( $column, $order_or_id ) {
    if ( 'twshop_group_buy' !== $column ) return;
    $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
    if ( ! $order instanceof WC_Order ) return;
    $leader_ids = twshop_group_buy_order_leader_ids( $order );
    if ( ! $leader_ids ) { echo '<span class="na">–</span>'; return; }
    global $wpdb;
    $entries = array();
    foreach ( $wpdb->get_results( $wpdb->prepare(
        'SELECT leader_id, SUM(amount) AS amount, COUNT(*) AS n, SUM(settlement_id IS NULL) AS open_n FROM ' . twshop_group_buy_tables()['commissions'] . ' WHERE order_id = %d GROUP BY leader_id',
        $order->get_id()
    ), ARRAY_A ) as $row ) {
        $entries[ (int) $row['leader_id'] ] = $row;
    }
    foreach ( $leader_ids as $leader_id ) {
        $leader = twshop_group_buy_get_leader( $leader_id );
        $name = $leader ? twshop_group_buy_leader_title( $leader ) : '已刪除團購主 #' . $leader_id;
        $row = $entries[ $leader_id ] ?? null;
        if ( ! $row ) {
            $status = '未入帳';
        } elseif ( abs( (float) $row['amount'] ) < 0.005 ) {
            $status = '已扣回';
        } else {
            $status = wp_strip_all_tags( wc_price( $row['amount'] ) ) . ( (int) $row['open_n'] > 0 ? ' 已入帳' : ' 已結算' );
        }
        printf(
            '<div class="twshop-gb-order-cell"><a href="%s">%s</a> <span class="twshop-gb-order-status">%s</span></div>',
            esc_url( add_query_arg( 'twshop_gb_leader', $leader_id ) ),
            esc_html( $name ),
            esc_html( $status )
        );
    }
}

function twshop_group_buy_render_orders_filter() {
    global $wpdb;
    $current = sanitize_key( wp_unslash( $_GET['twshop_gb_leader'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只篩選列表。
    $leaders = $wpdb->get_results( 'SELECT * FROM ' . twshop_group_buy_tables()['leaders'] . ' ORDER BY status ASC, created_at ASC', ARRAY_A );
    if ( ! $leaders ) return;
    echo '<select name="twshop_gb_leader"><option value="">團購：全部</option>';
    printf( '<option value="any"%s>所有團購訂單</option>', selected( $current, 'any', false ) );
    foreach ( $leaders as $leader ) {
        printf(
            '<option value="%d"%s>%s%s</option>',
            (int) $leader['user_id'],
            selected( $current, (string) (int) $leader['user_id'], false ),
            esc_html( twshop_group_buy_leader_title( $leader ) ),
            'active' === $leader['status'] ? '' : '（停用）'
        );
    }
    echo '</select>';
}

/**
 * 篩選值對應的訂單 ID：依訂單商品上的團購主歸屬（HPOS 與傳統訂單共用訂單商品資料表）。
 * 不是篩選值時回 null；沒有符合時回 array( 0 )，讓列表為空而不是忽略篩選。
 */
function twshop_group_buy_filtered_order_ids( $filter ) {
    global $wpdb;
    if ( 'any' === $filter ) {
        $ids = $wpdb->get_col( "SELECT DISTINCT oi.order_id FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = oi.order_item_id
            WHERE m.meta_key = '_twshop_gb_leader_id'" );
    } elseif ( '' !== $filter && ctype_digit( $filter ) ) {
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT oi.order_id FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = oi.order_item_id
            WHERE m.meta_key = '_twshop_gb_leader_id' AND m.meta_value = %s", (string) absint( $filter ) ) );
    } else {
        return null;
    }
    $ids = array_map( 'intval', (array) $ids );
    return $ids ? $ids : array( 0 );
}

function twshop_group_buy_filter_hpos_args( $args ) {
    $ids = twshop_group_buy_filtered_order_ids( sanitize_key( wp_unslash( $_GET['twshop_gb_leader'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( null !== $ids ) $args['post__in'] = isset( $args['post__in'] ) && $args['post__in'] ? ( array_values( array_intersect( (array) $args['post__in'], $ids ) ) ?: array( 0 ) ) : $ids;
    return $args;
}

function twshop_group_buy_filter_legacy_query( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) return;
    $ids = twshop_group_buy_filtered_order_ids( sanitize_key( wp_unslash( $_GET['twshop_gb_leader'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( null === $ids ) return;
    $existing = (array) $query->get( 'post__in' );
    $query->set( 'post__in', array_filter( $existing ) ? ( array_values( array_intersect( $existing, $ids ) ) ?: array( 0 ) ) : $ids );
}

function twshop_group_buy_legacy_filter_dropdown( $post_type ) {
    if ( 'shop_order' === $post_type ) twshop_group_buy_render_orders_filter();
}

// ── 訂單編輯頁 ───────────────────────────────────────────────────────────

function twshop_group_buy_register_order_metabox() {
    $screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
    add_meta_box( 'twshop-group-buy', '團購', 'twshop_group_buy_order_metabox', $screen, 'side', 'default' );
}

function twshop_group_buy_order_metabox( $post_or_order ) {
    $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
    if ( ! $order ) return;
    $leader_ids = twshop_group_buy_order_leader_ids( $order );
    if ( ! $leader_ids ) { echo '<p>非團購訂單。</p>'; return; }
    global $wpdb;
    foreach ( $leader_ids as $leader_id ) {
        $user = get_userdata( $leader_id );
        $share = twshop_group_buy_order_leader_share( $order, $leader_id );
        $net = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . twshop_group_buy_tables()['commissions'] . ' WHERE order_id = %d AND leader_id = %d', $order->get_id(), $leader_id ) );
        echo '<p><strong>' . esc_html( $user ? $user->display_name : '已刪除會員' ) . ' (#' . (int) $leader_id . ')</strong><br>';
        echo '計佣金額：' . wp_kses_post( wc_price( $share['base'] ) ) . '<br>佣金規則：' . esc_html( twshop_group_buy_describe_tiers( $share['tiers'] ) ) . '<br>';
        echo '目前佣金：' . wp_kses_post( wc_price( $net ) ) . '<br>';
        echo '<a href="' . esc_url( twshop_admin_url( 'group-buy', array( 'tab' => 'commissions', 'leader' => $leader_id ) ) ) . '">查看佣金紀錄</a></p>';
    }
}

// ── 掛鉤註冊 ─────────────────────────────────────────────────────────────

function twshop_group_buy_init() {
    twshop_group_buy_elementor_init();
    // 專屬頁（可被快取：不寫 Cookie、不設 no-cache）
    add_filter( 'template_include', 'twshop_group_buy_page_template', 20 );
    add_filter( 'document_title_parts', 'twshop_group_buy_page_title' );
    twshop_group_buy_register_seo_filters();
    add_filter( 'wp_robots', 'twshop_group_buy_page_robots' );
    add_filter( 'body_class', 'twshop_group_buy_body_class' );
    add_action( 'wp_enqueue_scripts', 'twshop_group_buy_page_assets' );
    add_action( 'template_redirect', 'twshop_group_buy_strip_page_distractions', 20 );
    add_action( 'template_redirect', 'twshop_group_buy_checkout_detect', 5 );
    add_filter( 'template_include', 'twshop_group_buy_checkout_template', 30 );
    add_filter( 'woocommerce_add_to_cart_redirect', 'twshop_group_buy_add_to_cart_redirect', 99, 2 );
    add_filter( 'woocommerce_add_to_cart_validation', 'twshop_group_buy_add_to_cart_validation', 20, 4 );
    add_action( 'woocommerce_check_cart_items', 'twshop_group_buy_check_cart_items' );
    add_filter( 'wc_add_to_cart_message_html', 'twshop_group_buy_add_to_cart_message', 20, 3 );
    add_filter( 'woocommerce_add_to_cart_fragments', 'twshop_group_buy_cart_fragments' );
    add_action( 'wc_ajax_twshop_gb_cart_update', 'twshop_group_buy_ajax_cart_update' );
    add_action( 'woocommerce_before_add_to_cart_button', 'twshop_group_buy_form_hidden_field' );
    add_filter( 'woocommerce_add_to_cart_form_action', 'twshop_group_buy_form_action', 20 );
    add_filter( 'woocommerce_ajax_variation_threshold', 'twshop_group_buy_variation_threshold', 20, 2 );

    // 專屬頁顯示價格。98：早於折扣規則（99）；團購價商品在 twshop_calculate_product_discount() 不再疊加商品折扣。
    add_filter( 'woocommerce_product_get_price', 'twshop_group_buy_filter_price', 98, 2 );
    add_filter( 'woocommerce_product_variation_get_price', 'twshop_group_buy_filter_price', 98, 2 );
    add_filter( 'woocommerce_variation_prices_price', 'twshop_group_buy_filter_price', 98, 2 );
    add_filter( 'woocommerce_product_get_sale_price', 'twshop_group_buy_filter_sale_price', 98, 2 );
    add_filter( 'woocommerce_product_variation_get_sale_price', 'twshop_group_buy_filter_sale_price', 98, 2 );
    add_filter( 'woocommerce_variation_prices_sale_price', 'twshop_group_buy_filter_sale_price', 98, 2 );
    add_filter( 'woocommerce_product_is_on_sale', 'twshop_group_buy_filter_is_on_sale', 98, 2 );
    add_filter( 'woocommerce_get_variation_prices_hash', 'twshop_group_buy_variation_price_hash', 10, 1 );
    add_filter( 'woocommerce_get_price_html', 'twshop_group_buy_price_html_label', 20, 2 );

    // 購物車與結帳：團購資格跟著購物車項目。20：晚於加購／贈品標記（10）。
    add_filter( 'woocommerce_add_cart_item_data', 'twshop_group_buy_add_cart_item_data', 20, 3 );
    add_filter( 'woocommerce_add_cart_item', 'twshop_group_buy_add_cart_item', 20, 2 );
    add_filter( 'woocommerce_get_cart_item_from_session', 'twshop_group_buy_cart_item_from_session', 20, 3 );
    add_filter( 'woocommerce_get_item_data', 'twshop_group_buy_cart_item_data_display', 20, 2 );
    add_filter( 'woocommerce_cart_item_price', 'twshop_group_buy_cart_item_price', 20, 3 );
    add_action( 'woocommerce_checkout_create_order_line_item', 'twshop_group_buy_order_line_item', 20, 4 );
    add_action( 'woocommerce_checkout_create_order', 'twshop_group_buy_attach_order', 20, 1 );

    add_action( 'woocommerce_product_options_pricing', 'twshop_group_buy_product_field' );
    add_action( 'woocommerce_admin_process_product_object', 'twshop_group_buy_save_product' );
    add_action( 'woocommerce_variation_options_pricing', 'twshop_group_buy_variation_field', 20, 3 );
    add_action( 'woocommerce_admin_process_variation_object', 'twshop_group_buy_save_variation', 10, 2 );

    add_action( 'wp_enqueue_scripts', 'twshop_group_buy_enqueue_account_script' );
    add_action( 'woocommerce_account_my-group-buy_endpoint', 'twshop_group_buy_account_content' );

    add_action( 'add_meta_boxes_shop_order', 'twshop_group_buy_register_order_metabox' );
    add_action( 'add_meta_boxes_woocommerce_page_wc-orders', 'twshop_group_buy_register_order_metabox' );

    // 訂單列表：「團購」欄位（排在退換貨 20 之後）與依團購主篩選
    add_filter( 'manage_shop_order_posts_columns', 'twshop_group_buy_order_list_columns', 25 );
    add_filter( 'manage_woocommerce_page_wc-orders_columns', 'twshop_group_buy_order_list_columns', 25 );
    add_action( 'manage_shop_order_posts_custom_column', 'twshop_group_buy_order_list_column_content', 25, 2 );
    add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'twshop_group_buy_order_list_column_content', 25, 2 );
    add_action( 'woocommerce_order_list_table_restrict_manage_orders', 'twshop_group_buy_render_orders_filter' );
    add_filter( 'woocommerce_order_list_table_prepare_items_query_args', 'twshop_group_buy_filter_hpos_args' );
    add_action( 'restrict_manage_posts', 'twshop_group_buy_legacy_filter_dropdown' );
    add_action( 'pre_get_posts', 'twshop_group_buy_filter_legacy_query' );
}
