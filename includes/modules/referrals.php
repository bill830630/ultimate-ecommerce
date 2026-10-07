<?php
/** 推薦碼：唯一代碼與不可覆寫的註冊推薦關係。 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TWSHOP_REFERRALS_DB_VERSION', '1.0.0' );

function twshop_referrals_table() {
    global $wpdb;
    return $wpdb->prefix . 'twshop_referrals';
}

function twshop_referrals_install_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = twshop_referrals_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$table} (
        user_id BIGINT UNSIGNED NOT NULL,
        code VARCHAR(12) NOT NULL,
        referrer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        referred_at DATETIME NULL,
        reward_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        reward_points BIGINT UNSIGNED NOT NULL DEFAULT 0,
        reward_status VARCHAR(20) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (user_id),
        UNIQUE KEY code (code),
        KEY referrer_id (referrer_id),
        KEY referred_at (referred_at),
        KEY reward_order_id (reward_order_id)
    ) ENGINE=InnoDB {$charset};" );
    // 建表失敗時保留重試機會。
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
        update_option( 'twshop_referrals_db_version', TWSHOP_REFERRALS_DB_VERSION, false );
    }
}

function twshop_referrals_maybe_upgrade_db() {
    if ( get_option( 'twshop_referrals_db_version' ) !== TWSHOP_REFERRALS_DB_VERSION ) twshop_referrals_install_tables();
}

function twshop_referrals_normalize_code( $value ) {
    if ( ! is_string( $value ) ) return '';
    $code = strtoupper( trim( $value ) );
    return preg_match( '/^[A-Z0-9]{12}$/D', $code ) ? $code : '';
}

/** 每位會員一個代碼；唯一索引防止碰撞與同時請求建立重複資料。 */
function twshop_referrals_identity( $user_id, $create = false ) {
    global $wpdb;
    $user_id = absint( $user_id );
    if ( ! $user_id || ! get_userdata( $user_id ) ) return null;
    $table = twshop_referrals_table();
    $read = function () use ( $wpdb, $table, $user_id ) {
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A );
    };
    $row = $read();
    if ( $row || ! $create ) return $row;
    for ( $attempt = 0; $attempt < 5; $attempt++ ) {
        $code = strtoupper( wp_generate_password( 12, false, false ) );
        $ok = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (user_id, code, created_at) VALUES (%d, %s, %s)",
            $user_id, $code, current_time( 'mysql' )
        ) );
        if ( false === $ok ) return null;
        $row = $read();
        if ( $row ) return $row;
    }
    return null;
}

function twshop_referrals_find_referrer( $code ) {
    global $wpdb;
    $code = twshop_referrals_normalize_code( $code );
    if ( ! $code ) return 0;
    $id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . twshop_referrals_table() . ' WHERE code = %s', $code ) );
    return $id && get_userdata( $id ) ? $id : 0;
}

/** 僅供建立新會員的 callback 呼叫；既有推薦人不能被另一個請求覆寫。 */
function twshop_referrals_bind( $user_id, $code ) {
    global $wpdb;
    $user_id = absint( $user_id );
    $referrer = twshop_referrals_find_referrer( $code );
    if ( ! $referrer || $referrer === $user_id || ! twshop_referrals_identity( $user_id, true ) ) return false;
    return 1 === $wpdb->query( $wpdb->prepare(
        'UPDATE ' . twshop_referrals_table() . ' SET referrer_id = %d, referred_at = %s WHERE user_id = %d AND referrer_id = 0',
        $referrer, current_time( 'mysql' ), $user_id
    ) );
}

function twshop_referrals_enabled() {
    return twshop_module_enabled( 'points' ) && 'yes' === get_option( 'twshop_referrals_enabled', 'no' );
}

function twshop_referrals_reward_points() {
    return min( 1000000, max( 0, (int) get_option( 'twshop_referrals_reward_points', 0 ) ) );
}

/** 使用簽章 Cookie 保存首次有效來源；到期時間亦納入簽章，不能自行延長。 */
function twshop_referrals_cookie_value( $code, $expires ) {
    $payload = $code . '|' . (int) $expires;
    return $payload . '|' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
}

function twshop_referrals_pending_code() {
    $raw = wp_unslash( $_COOKIE['twshop_referral_source'] ?? '' );
    if ( ! is_string( $raw ) ) return '';
    $parts = explode( '|', $raw );
    if ( count( $parts ) !== 3 || ! ctype_digit( $parts[1] ) ) return '';
    list( $code, $expires, $signature ) = $parts;
    if ( $expires <= time() || $expires > time() + 30 * DAY_IN_SECONDS ) return '';
    if ( ! hash_equals( twshop_referrals_cookie_value( $code, $expires ), $raw ) ) return '';
    return twshop_referrals_find_referrer( $code ) ? twshop_referrals_normalize_code( $code ) : '';
}

function twshop_referrals_set_source_cookie( $value, $expires ) {
    if ( headers_sent() ) return false;
    $ok = setcookie( 'twshop_referral_source', $value, array(
        'expires' => $expires, 'path' => '/', 'domain' => COOKIE_DOMAIN ?: '',
        'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax',
    ) );
    if ( $ok ) {
        if ( '' === $value ) unset( $_COOKIE['twshop_referral_source'] );
        else $_COOKIE['twshop_referral_source'] = $value;
    }
    return $ok;
}

/** 前台連結入口在輸出前執行；後續連結不覆蓋來源，也不延長原本的 30 天。 */
function twshop_referrals_capture_link() {
    if ( ! twshop_referrals_enabled() || is_user_logged_in() || is_admin() || wp_doing_ajax() ) return;
    if ( ! isset( $_GET['ref'] ) || twshop_referrals_pending_code() ) return;
    $code = twshop_referrals_normalize_code( wp_unslash( $_GET['ref'] ) );
    if ( ! $code || ! twshop_referrals_find_referrer( $code ) ) return;
    // 推薦來源須由 PHP 個別處理；快取外掛也應排除含 ref 參數的入口。
    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    nocache_headers();
    $expires = time() + 30 * DAY_IN_SECONDS;
    twshop_referrals_set_source_cookie( twshop_referrals_cookie_value( $code, $expires ), $expires );
}

/** 僅新帳號建立事件使用來源，既有會員登入不會補綁。無效來源不阻擋註冊。 */
function twshop_referrals_on_customer_created( $user_id ) {
    if ( ! twshop_referrals_enabled() || is_user_logged_in() || ( is_admin() && ! wp_doing_ajax() ) ) return;
    if ( defined( 'WP_CLI' ) && WP_CLI ) return;
    $code = twshop_referrals_pending_code();
    if ( ! $code ) return;
    if ( twshop_referrals_find_referrer( $code ) === (int) $user_id || twshop_referrals_bind( $user_id, $code ) ) {
        twshop_referrals_set_source_cookie( '', time() - DAY_IN_SECONDS );
    }
}

function twshop_referrals_enqueue_account_script() {
    if ( twshop_referrals_enabled() && is_account_page() && is_wc_endpoint_url( 'my-membership' ) ) {
        wp_enqueue_style( 'twshop-referrals', plugins_url( 'assets/css/referrals.css', TWSHOP_PLUGIN_FILE ), array(), filemtime( TWSHOP_PLUGIN_DIR . 'assets/css/referrals.css' ) );
        wp_enqueue_script( 'twshop-referrals', plugins_url( 'assets/js/referrals.js', TWSHOP_PLUGIN_FILE ), array(), filemtime( TWSHOP_PLUGIN_DIR . 'assets/js/referrals.js' ), true );
    }
}

function twshop_referrals_account_content() {
    if ( ! twshop_referrals_enabled() || ! is_user_logged_in() ) return;
    global $wpdb;
    $row = twshop_referrals_identity( get_current_user_id(), true );
    if ( ! $row ) { echo '<p>暫時無法取得推薦碼，請稍後再試。</p>'; return; }
    $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . twshop_referrals_table() . ' WHERE referrer_id = %d', get_current_user_id() ) );
    $url = add_query_arg( 'ref', $row['code'], wc_get_page_permalink( 'myaccount' ) );
    echo '<div class="twshop-account-section twshop-referral-section">';
    echo '<h2>我的推薦連結</h2>';
    echo '<p>分享專屬連結給朋友。朋友點擊後，30 天內註冊或在結帳建立帳號，即自動綁定推薦人，不需輸入推薦碼。</p>';
    echo '<p><label for="twshop-referral-url">分享連結</label><input id="twshop-referral-url" class="input-text" type="text" readonly value="' . esc_attr( $url ) . '"></p>';
    echo '<p class="twshop-referral-actions"><button type="button" class="button" id="twshop-copy-referral-url">複製推薦連結</button> <span id="twshop-referral-copy-status" role="status" aria-live="polite"></span></p>';
    echo '<p>以首次有效推薦連結為準，綁定後不能更換推薦人。</p>';
    echo '<p>已推薦註冊：<strong>' . (int) $count . '</strong> 位會員。</p>';
    $points = twshop_referrals_reward_points();
    if ( $points && twshop_module_enabled( 'points' ) ) {
        echo '<p>朋友的首筆訂單完成後，推薦人可獲得 <strong>' . (int) $points . '</strong> 點紅利。訂單需有實付金額且不能只有儲值金商品；全額退款或取消會追回獎勵。</p>';
    } else {
        echo '<p>目前僅記錄推薦關係，不發放推薦獎勵。</p>';
    }
    echo '</div>';
}

/** 舊推薦頁書籤保留相容，導向合併後的會員權益。 */
function twshop_referrals_redirect_legacy_endpoint() {
    if ( ! is_account_page() || ! is_wc_endpoint_url( 'my-referrals' ) ) return;
    $url = wc_get_page_permalink( 'myaccount' );
    if ( twshop_module_enabled( 'member_tiers' ) || twshop_referrals_enabled() ) {
        $url = wc_get_endpoint_url( 'my-membership', '', $url );
    }
    wp_safe_redirect( $url );
    exit;
}
add_action( 'template_redirect', 'twshop_referrals_redirect_legacy_endpoint', 2 );

function twshop_referrals_maybe_flush_endpoint() {
    if ( 'yes' !== get_option( 'twshop_referrals_endpoint_flushed' ) ) {
        flush_rewrite_rules();
        update_option( 'twshop_referrals_endpoint_flushed', 'yes', false );
    }
}

function twshop_referrals_init() {
    add_action( 'template_redirect', 'twshop_referrals_capture_link', 1 );
    add_action( 'user_register', 'twshop_referrals_on_customer_created', 20 );
    add_action( 'wp_enqueue_scripts', 'twshop_referrals_enqueue_account_script' );
    add_action( 'woocommerce_created_customer', 'twshop_referrals_on_customer_created', 20 );
    add_action( 'woocommerce_account_my-membership_endpoint', 'twshop_referrals_account_content', 20 );
    add_action( 'woocommerce_order_status_completed', 'twshop_referrals_award_on_order_completed', 30 );
}

/** 停用點數模組會停止推薦入口與新獎勵，但不免除既有獎勵的退款追回。 */
function twshop_referrals_init_reversals() {
    foreach ( array( 'cancelled', 'failed', 'refunded' ) as $status ) {
        add_action( 'woocommerce_order_status_' . $status, 'twshop_referrals_revoke_for_order', 30 );
    }
    add_action( 'woocommerce_order_refunded', 'twshop_referrals_revoke_on_full_refund', 30 );
}

register_activation_hook( TWSHOP_PLUGIN_FILE, 'twshop_referrals_install_tables' );
add_action( 'admin_init', 'twshop_referrals_maybe_upgrade_db' );
add_action( 'admin_init', 'twshop_referrals_maybe_flush_endpoint' );

/** 不獎勵訪客、零元訂單或純儲值訂單。 */
function twshop_referrals_order_eligible( $order ) {
    if ( ! $order instanceof WC_Order || ! $order->get_customer_id() || (float) $order->get_total() <= 0 ) return false;
    foreach ( $order->get_items() as $item ) {
        if ( ! $item instanceof WC_Order_Item_Product || (float) $item->get_total() <= 0 ) continue;
        $product = $item->get_product();
        if ( $product && ! twshop_is_wallet_credit_product( $product ) ) return true;
    }
    return false;
}

/** 財務寫入需要交易支援；不支援時保留未發獎狀態供管理員處理。 */
function twshop_referrals_transactions_supported() {
    global $wpdb;
    static $supported = null;
    if ( null === $supported ) {
        $engines = $wpdb->get_col( $wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)',
            twshop_referrals_table(), $wpdb->usermeta
        ) );
        $supported = count( $engines ) === 2 && count( array_filter( $engines, fn( $engine ) => 'INNODB' === strtoupper( $engine ) ) ) === 2;
    }
    return $supported;
}

/** 與既有點數帳本共用入帳／到期批次，並驗證持久化結果。需在交易與鎖內呼叫。 */
function twshop_referrals_change_points( $user_id, $delta, $reason ) {
    wp_cache_delete( $user_id, 'user_meta' );
    $before = (int) get_user_meta( $user_id, 'twshop_reward_points', true );
    $batches_before = array_sum( array_column( twshop_get_points_batches( $user_id ), 'amount' ) );
    twshop_add_points_log( $user_id, $delta, $reason );
    wp_cache_delete( $user_id, 'user_meta' );
    $history = get_user_meta( $user_id, 'twshop_points_history', true );
    $valid = (int) get_user_meta( $user_id, 'twshop_reward_points', true ) === max( 0, $before + $delta )
        && is_array( $history ) && ( $history[0]['reason'] ?? '' ) === $reason
        && (int) ( $history[0]['amount'] ?? 0 ) === $delta;
    if ( (int) get_option( 'wc_points_expiry_days', 0 ) > 0 ) {
        $valid = $valid && array_sum( array_column( twshop_get_points_batches( $user_id ), 'amount' ) ) === max( 0, $batches_before + $delta );
    }
    if ( ! $valid ) throw new RuntimeException( '推薦點數寫入失敗。' );
}

/** 鎖住被推薦人與推薦人兩筆資料；狀態與點數在同一交易提交，重試不會重複入帳。 */
function twshop_referrals_settle_reward( $order, $revoke = false ) {
    global $wpdb;
    if ( ! $revoke && ! twshop_referrals_enabled() ) return 'skipped';
    if ( ! $order instanceof WC_Order ) return 'skipped';
    $table = twshop_referrals_table();
    $row = $revoke
        ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE reward_order_id = %d AND reward_status = 'awarded'", $order->get_id() ), ARRAY_A )
        : twshop_referrals_identity( $order->get_customer_id() );
    if ( ! $row || ! $row['referrer_id'] || ! get_userdata( $row['referrer_id'] ) ) return 'skipped';
    if ( ! $revoke && (int) $row['reward_order_id'] > 0 ) return 'skipped';
    if ( ! twshop_referrals_transactions_supported() ) return new WP_Error( 'referral_storage', '推薦獎勵需要 InnoDB 資料表，請聯絡管理員。' );

    $referrer = (int) $row['referrer_id'];
    try {
        $outcome = twshop_points_transaction( array( (int) $row['user_id'], $referrer ), function () use ( $wpdb, $table, $row, $referrer, $order, $revoke ) {
            // 固定順序取鎖，讓多位被推薦人同時完成訂單時，共用推薦人的點數寫入序列化。
            $locked = $wpdb->get_col( $wpdb->prepare(
                "SELECT user_id FROM {$table} WHERE user_id IN (%d, %d) ORDER BY user_id FOR UPDATE",
                (int) $row['user_id'], $referrer
            ) );
            if ( count( $locked ) !== 2 || $wpdb->last_error ) throw new RuntimeException( '無法鎖定推薦關係。' );
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", (int) $row['user_id'] ), ARRAY_A );
            if ( ! $row || (int) $row['referrer_id'] !== $referrer ) throw new RuntimeException( '推薦關係已變更。' );

            if ( $revoke ) {
                if ( 'awarded' !== $row['reward_status'] || (int) $row['reward_order_id'] !== $order->get_id() ) { return array( 'result' => 'skipped', 'points' => 0 ); }
                $points = (int) $row['reward_points'];
                twshop_referrals_change_points( $referrer, -$points, '推薦獎勵追回：訂單 #' . $order->get_id() );
                $fields = array( 'reward_status' => 'revoked' );
                $result = 'revoked';
            } else {
                if ( (int) $row['reward_order_id'] > 0 ) { return array( 'result' => 'skipped', 'points' => 0 ); }
                $first = wc_get_orders( array( 'customer_id' => (int) $row['user_id'], 'status' => array( 'completed', 'refunded' ), 'orderby' => 'ID', 'order' => 'ASC', 'limit' => 1, 'return' => 'ids' ) );
                if ( $wpdb->last_error ) throw new RuntimeException( '無法核對首筆訂單。' );
                if ( ! $first || (int) $first[0] !== $order->get_id() ) { return array( 'result' => 'skipped', 'points' => 0 ); }
                $points = twshop_module_enabled( 'points' ) ? twshop_referrals_reward_points() : 0;
                if ( $points > 0 ) twshop_referrals_change_points( $referrer, $points, '推薦獎勵：訂單 #' . $order->get_id() );
                $fields = array( 'reward_order_id' => $order->get_id(), 'reward_points' => $points, 'reward_status' => $points > 0 ? 'awarded' : 'unfunded' );
                $result = $fields['reward_status'];
            }
            if ( 1 !== $wpdb->update( $table, $fields, array( 'user_id' => (int) $row['user_id'] ) ) ) throw new RuntimeException( '無法儲存推薦獎勵狀態。' );
            return array( 'result' => $result, 'points' => $points );
        } );
        $result = $outcome['result'];
        $points = $outcome['points'];
        if ( 'skipped' === $result ) return $result;
    } catch ( Throwable $error ) {
        wp_cache_delete( $referrer, 'user_meta' );
        wc_get_logger()->error( $error->getMessage(), array( 'source' => 'twshop-referrals', 'order_id' => $order->get_id() ) );
        return new WP_Error( 'referral_reward', '推薦獎勵處理失敗，請查看 WooCommerce 記錄。' );
    }
    // 帳本已提交。通知備註獨立於交易，避免備註失敗被誤報為入帳失敗。
    if ( 'unfunded' !== $result ) $order->add_order_note( sprintf( '推薦獎勵%s：會員 #%d，%d 點。', $revoke ? '追回' : '發放', $referrer, $points ) );
    return $result;
}

function twshop_referrals_award_on_order_completed( $order_id ) {
    if ( ! twshop_referrals_enabled() ) return;
    $order = wc_get_order( $order_id );
    if ( ! $order || 'completed' !== $order->get_status() || ! twshop_referrals_order_eligible( $order ) ) return;
    $result = twshop_referrals_settle_reward( $order );
    if ( is_wp_error( $result ) ) $order->add_order_note( '⚠️ ' . $result->get_error_message() );
}

/** 即使推薦碼或紅利點數模組已停用，既有推薦獎勵仍需在退款時追回。 */
function twshop_referrals_revoke_for_order( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    if ( ! in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true )
        && (float) $order->get_total_refunded() < (float) $order->get_total() ) return;
    $result = twshop_referrals_settle_reward( $order, true );
    if ( is_wp_error( $result ) ) $order->add_order_note( '⚠️ ' . $result->get_error_message() );
}

function twshop_referrals_revoke_on_full_refund( $order_id ) {
    twshop_referrals_revoke_for_order( $order_id );
}
