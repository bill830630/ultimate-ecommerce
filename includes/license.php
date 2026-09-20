<?php
/**
 * Cloudflare Workers 授權客戶端。
 *
 * 驗證成功快取 24 小時；授權伺服器暫時無法連線時，沿用最近一次成功結果 14 天。
 * 明確收到無效、停用或過期回應時不套用寬限期。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TWSHOP_LICENSE_API_URL', 'https://nibill-license-api.bill830630.workers.dev' );
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
    if ( $valid ) $data['last_success'] = $now;
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

    $lock_key = 'twshop_license_check_lock';
    if ( ! $force && get_transient( $lock_key ) ) {
        return ! empty( $data['last_success'] ) && ( $now - (int) $data['last_success'] ) < TWSHOP_LICENSE_GRACE_TTL;
    }
    set_transient( $lock_key, '1', MINUTE_IN_SECONDS );
    $response = twshop_license_request( '/v1/licenses/validate', twshop_license_payload( $data ) );
    delete_transient( $lock_key );

    if ( is_wp_error( $response ) ) {
        $within_grace = ! empty( $data['last_success'] ) && ( $now - (int) $data['last_success'] ) < TWSHOP_LICENSE_GRACE_TTL;
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
    $data   = twshop_license_get_data();
    $active = twshop_license_is_active();
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
    echo '<div class="twshop-panel twshop-panel--narrow">';
    twshop_panel_head( '', '外掛授權', '授權綁定目前網站；驗證成功後會快取 24 小時，服務暫時中斷時保留 14 天離線寬限。' );
    echo '<div class="twshop-panel-body"><table class="form-table"><tr><th scope="row">授權狀態</th><td><strong>' . esc_html( $status ) . '</strong>';
    if ( ! empty( $data['expires_at'] ) ) echo '<p class="description">到期時間：' . esc_html( $data['expires_at'] ) . '</p>';
    if ( ! empty( $data['error'] ) ) echo '<p class="description twshop-text-danger">' . esc_html( $data['error'] ) . '</p>';
    echo '</td></tr></table>';
    if ( $active ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="twshop_license_deactivate">';
        wp_nonce_field( 'twshop_license_deactivate' );
        submit_button( '解除授權', 'secondary', 'submit', false );
        echo '</form>';
    } else {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="twshop_license_activate">';
        wp_nonce_field( 'twshop_license_activate' );
        echo '<p><label for="twshop_license_key"><strong>授權金鑰</strong></label></p><input id="twshop_license_key" name="license_key" type="text" class="regular-text" autocomplete="off" placeholder="NIBILL-XXXXX-XXXXX-XXXXX-XXXXX" required>';
        submit_button( '啟用授權', 'primary', 'submit', false );
        echo '</form>';
    }
    echo '</div></div>';
}
