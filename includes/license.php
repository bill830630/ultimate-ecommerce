<?php
/**
 * Cloudflare Workers 授權客戶端。
 *
 * 驗證成功快取 24 小時；授權伺服器暫時無法連線時，沿用最近一次成功結果 14 天。
 * 明確收到無效、停用或過期回應時不套用寬限期。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TWSHOP_LICENSE_API_URL', 'https://ctrla.bill830630.workers.dev' );
define( 'TWSHOP_LICENSE_PRODUCT_ID', 'ultimate-ecommerce' );
define( 'TWSHOP_LICENSE_OPTION', 'twshop_license' );
define( 'TWSHOP_LICENSE_CACHE_TTL', DAY_IN_SECONDS );
define( 'TWSHOP_LICENSE_GRACE_TTL', 14 * DAY_IN_SECONDS );

function twshop_license_get_data() {
    $data = get_option( TWSHOP_LICENSE_OPTION, array() );
    $data = is_array( $data ) ? $data : array();
    if ( empty( $data['instance_id'] ) ) {
        $data['instance_id'] = wp_generate_uuid4();
        update_option( TWSHOP_LICENSE_OPTION, $data, false );
    }
    return $data;
}

function twshop_license_update_data( array $changes ) {
    $data = array_merge( twshop_license_get_data(), $changes );
    update_option( TWSHOP_LICENSE_OPTION, $data, false );
    return $data;
}

function twshop_license_request( $endpoint, array $body ) {
    $response = wp_remote_post(
        TWSHOP_LICENSE_API_URL . $endpoint,
        array(
            'timeout' => 10,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $body ),
        )
    );
    if ( is_wp_error( $response ) ) return $response;

    $status  = wp_remote_retrieve_response_code( $response );
    $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( $status >= 500 ) {
        return new WP_Error( 'license_server_error', '授權伺服器暫時無法使用。' );
    }
    if ( ! is_array( $decoded ) ) {
        return new WP_Error( 'invalid_response', '授權伺服器回應格式不正確。' );
    }
    $decoded['_http_status'] = wp_remote_retrieve_response_code( $response );
    return $decoded;
}

function twshop_license_error_message( $code ) {
    $messages = array(
        'license_not_found'        => '找不到授權金鑰。',
        'activation_not_found'     => '這個網站尚未啟用授權。',
        'activation_limit_reached' => '授權可啟用的網站數量已達上限。',
        'license_expired'          => '授權已到期。',
        'license_inactive'         => '授權已停用。',
        'license_not_active'       => '授權已停用。',
        'missing_fields'           => '授權資料不完整。',
        'invalid_site_url'         => '網站網址格式不正確。',
    );
    return $messages[ $code ] ?? '授權驗證失敗，請確認金鑰後再試一次。';
}

function twshop_license_payload( array $data ) {
    return array(
        'license_key' => (string) ( $data['license_key'] ?? '' ),
        'product_id'  => TWSHOP_LICENSE_PRODUCT_ID,
        'instance_id' => (string) ( $data['instance_id'] ?? '' ),
        'site_url'    => home_url( '/' ),
    );
}

function twshop_license_store_response( array $response, $license_key = null ) {
    $now   = time();
    $valid = ! empty( $response['valid'] );
    $error = sanitize_key( $response['error'] ?? '' );
    $data  = array(
        'status'         => $valid ? 'active' : ( $error ?: sanitize_key( $response['status'] ?? 'invalid' ) ),
        'last_checked'   => $now,
        'expires_at'     => sanitize_text_field( $response['expires_at'] ?? '' ),
        'latest_version' => sanitize_text_field( $response['latest_version'] ?? '' ),
        'error'          => $valid ? '' : twshop_license_error_message( $error ),
    );
    if ( $valid ) {
        $activation              = is_array( $response['activation'] ?? null ) ? $response['activation'] : array();
        $data['last_success']    = $now;
        $data['product_name']    = sanitize_text_field( $response['product_name'] ?? '' );
        $data['max_activations'] = absint( $response['max_activations'] ?? 0 );
        $data['site_url']        = esc_url_raw( $activation['site_url'] ?? '' );
        $data['activated_at']    = sanitize_text_field( $activation['activated_at'] ?? '' );
        $data['last_seen_at']    = sanitize_text_field( $activation['last_seen_at'] ?? '' );
    }
    if ( null !== $license_key ) $data['license_key'] = sanitize_text_field( $license_key );
    return twshop_license_update_data( $data );
}

function twshop_license_is_active( $force = false ) {
    $data = twshop_license_get_data();
    if ( empty( $data['license_key'] ) ) return false;

    $now = time();
    if ( ! $force && ! empty( $data['last_checked'] ) && ( $now - (int) $data['last_checked'] ) < TWSHOP_LICENSE_CACHE_TTL ) {
        return in_array( $data['status'] ?? '', array( 'active', 'grace' ), true );
    }

    $within_grace = ! empty( $data['last_success'] ) && ( $now - (int) $data['last_success'] ) < TWSHOP_LICENSE_GRACE_TTL;

    // 前台訪客不等授權伺服器（最長 10 秒逾時）：快取過期時沿用上次結果，
    // 重新驗證交給後台頁面、WP-Cron 或 WP-CLI 請求（admin-ajax 也常是前台購物車呼叫，排除）（v25.8.107）。
    $can_fetch = ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
    if ( ! $force && ! $can_fetch ) {
        return in_array( $data['status'] ?? '', array( 'active', 'grace' ), true ) && $within_grace;
    }

    $lock_key = 'twshop_license_check_lock';
    if ( ! $force && get_transient( $lock_key ) ) {
        return $within_grace;
    }
    set_transient( $lock_key, '1', MINUTE_IN_SECONDS );
    $response = twshop_license_request( '/v1/licenses/validate', twshop_license_payload( $data ) );
    delete_transient( $lock_key );

    if ( is_wp_error( $response ) ) {
        twshop_license_update_data( array(
            'status'       => $within_grace ? 'grace' : 'unreachable',
            'last_checked' => $now,
            'error'        => $within_grace ? '授權伺服器暫時無法連線，目前使用離線寬限期。' : '無法連線授權伺服器。',
        ) );
        return $within_grace;
    }

    $data = twshop_license_store_response( $response );
    return 'active' === ( $data['status'] ?? '' );
}

function twshop_license_boot() {
    add_action( 'admin_post_twshop_license_activate', 'twshop_license_handle_activate' );
    add_action( 'admin_post_twshop_license_deactivate', 'twshop_license_handle_deactivate' );
    add_action( 'admin_notices', 'twshop_license_admin_notice' );
}

function twshop_license_redirect( $result ) {
    wp_safe_redirect( twshop_admin_url( 'system', array( 'tab' => 'license', 'license_result' => $result ) ) );
    exit;
}

function twshop_license_handle_activate() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足。' );
    check_admin_referer( 'twshop_license_activate' );
    $license_key = strtoupper( sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) ) );
    if ( '' === $license_key ) twshop_license_redirect( 'missing' );

    $data     = twshop_license_update_data( array( 'license_key' => $license_key ) );
    $response = twshop_license_request( '/v1/licenses/activate', twshop_license_payload( $data ) );
    if ( is_wp_error( $response ) ) {
        twshop_license_update_data( array( 'status' => 'unreachable', 'last_checked' => time(), 'error' => '無法連線授權伺服器。' ) );
        twshop_license_redirect( 'error' );
    }
    $stored = twshop_license_store_response( $response, $license_key );
    twshop_license_redirect( 'active' === ( $stored['status'] ?? '' ) ? 'activated' : 'invalid' );
}

function twshop_license_handle_deactivate() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足。' );
    check_admin_referer( 'twshop_license_deactivate' );
    $data = twshop_license_get_data();
    if ( ! empty( $data['license_key'] ) ) {
        $response = twshop_license_request( '/v1/licenses/deactivate', twshop_license_payload( $data ) );
        if ( is_wp_error( $response ) ) twshop_license_redirect( 'error' );
    }
    twshop_license_update_data( array( 'license_key' => '', 'status' => 'inactive', 'last_checked' => time(), 'last_success' => 0, 'error' => '' ) );
    twshop_license_redirect( 'deactivated' );
}

function twshop_license_admin_notice() {
    if ( ! current_user_can( 'manage_options' ) || twshop_license_is_active() ) return;
    $page = sanitize_key( $_GET['page'] ?? '' );
    if ( in_array( $page, array( 'wc-general-settings', 'wclon-settings' ), true ) ) return;
    $url = twshop_admin_url( 'system', array( 'tab' => 'license' ) );
    echo '<div class="notice notice-error"><p><strong>終極電商尚未啟用授權。</strong> 外掛功能目前停用，請前往 <a href="' . esc_url( $url ) . '">授權設定</a> 輸入有效金鑰。</p></div>';
}

function twshop_license_inline_notice() {
    if ( ! current_user_can( 'manage_options' ) || twshop_license_is_active() ) return;
    $section = sanitize_key( $_GET['section'] ?? '' );
    $tab     = sanitize_key( $_GET['tab'] ?? '' );
    if ( 'system' === $section && 'license' === $tab ) return;
    $url = twshop_admin_url( 'system', array( 'tab' => 'license' ) );
    echo '<div class="notice notice-error inline twshop-license-inline-notice"><p><strong>尚未啟用授權。</strong> 外掛功能目前停用，請前往 <a href="' . esc_url( $url ) . '">授權設定</a> 輸入有效金鑰。</p></div>';
}

function twshop_license_settings_tab() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $active = twshop_license_is_active(); // 可能順便重新驗證並更新資料，所以先呼叫再讀
    $data   = twshop_license_get_data();
    $status = $active ? ( 'grace' === ( $data['status'] ?? '' ) ? '離線寬限中' : '已啟用' ) : '未啟用';
    $result = sanitize_key( $_GET['license_result'] ?? '' );
    $messages = array(
        'activated'   => array( 'success', '授權已啟用。' ),
        'deactivated' => array( 'success', '授權已解除。' ),
        'missing'     => array( 'error', '請輸入授權金鑰。' ),
        'invalid'     => array( 'error', $data['error'] ?? '授權驗證失敗。' ),
        'error'       => array( 'error', '無法連線授權伺服器，請稍後再試。' ),
    );
    if ( isset( $messages[ $result ] ) ) {
        echo '<div class="notice notice-' . esc_attr( $messages[ $result ][0] ) . ' inline"><p>' . esc_html( $messages[ $result ][1] ) . '</p></div>';
    }
    echo '<div class="twshop-panel twshop-license-card">';
    $format_time = static function ( $value ) {
        if ( empty( $value ) ) return '—';
        $timestamp = is_numeric( $value ) ? (int) $value : strtotime( $value );
        return $timestamp ? wp_date( 'Y-m-d H:i', $timestamp ) : (string) $value;
    };
    $license_key = preg_replace( '/[^A-Z0-9-]/', '', strtoupper( (string) ( $data['license_key'] ?? '' ) ) );
    $masked_key  = $license_key ? ( 0 === strpos( $license_key, 'CTRLA-' ) ? 'CTRLA' : 'NIBILL' ) . '-••••-' . substr( $license_key, -4 ) : '—';
    $site_url    = $data['site_url'] ?? home_url();
    $status_class = $active ? ( 'grace' === ( $data['status'] ?? '' ) ? 'is-grace' : 'is-active' ) : 'is-inactive';
    $details = array(
        '授權序號' => '<code>' . esc_html( $masked_key ) . '</code>',
        '綁定網站' => esc_html( untrailingslashit( $site_url ) ),
        '啟用時間' => esc_html( $format_time( $data['activated_at'] ?? '' ) ),
        '最後驗證' => esc_html( $format_time( $data['last_seen_at'] ?? ( $data['last_checked'] ?? '' ) ) ),
        '授權期限' => esc_html( empty( $data['expires_at'] ) ? '永久' : $format_time( $data['expires_at'] ) ),
    );
    if ( ! empty( $data['max_activations'] ) ) $details['網站授權上限'] = esc_html( number_format_i18n( (int) $data['max_activations'] ) ) . ' 個網站';
    if ( 'grace' === ( $data['status'] ?? '' ) && ! empty( $data['last_success'] ) ) $details['離線寬限期限'] = esc_html( $format_time( (int) $data['last_success'] + TWSHOP_LICENSE_GRACE_TTL ) );
    echo '<div class="twshop-license-card__header"><h2>外掛授權</h2><span class="twshop-license-status ' . esc_attr( $status_class ) . '">' . esc_html( $status ) . '</span></div>';
    echo '<div class="twshop-panel-body"><dl class="twshop-license-details">';
    foreach ( $details as $label => $value ) echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd></div>';
    echo '</dl>';
    if ( ! empty( $data['error'] ) ) echo '<div class="twshop-license-message twshop-text-danger">' . esc_html( $data['error'] ) . '</div>';
    echo '<div class="twshop-license-actions">';
    if ( $active ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="twshop_license_deactivate">';
        wp_nonce_field( 'twshop_license_deactivate' );
        submit_button( '解除授權', 'secondary', 'submit', false );
        echo '</form>';
    } else {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="twshop_license_activate">';
        wp_nonce_field( 'twshop_license_activate' );
        echo '<label for="twshop_license_key" class="screen-reader-text">授權金鑰</label><input id="twshop_license_key" name="license_key" type="text" class="regular-text" autocomplete="off" placeholder="CTRLA-XXXXX-XXXXX-XXXXX-XXXXX" required>';
        submit_button( '啟用授權', 'primary', 'submit', false );
        echo '</form>';
    }
    echo '</div></div></div>';
}
