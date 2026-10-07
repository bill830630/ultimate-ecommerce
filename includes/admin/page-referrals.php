<?php
/** 推薦碼設定；推薦關係只在新會員註冊時建立。 */
if ( ! defined( 'ABSPATH' ) ) exit;

function twshop_referrals_settings_tab() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足。' );
    if ( ! twshop_module_enabled( 'points' ) ) wp_die( '請先啟用紅利點數模組。' );
    if ( isset( $_POST['twshop_referrals_save'] ) ) {
        check_admin_referer( 'twshop_referrals_settings' );
        update_option( 'twshop_referrals_enabled', isset( $_POST['twshop_referrals_enabled'] ) ? 'yes' : 'no', false );
        $raw = wp_unslash( $_POST['twshop_referrals_reward_points'] ?? '0' );
        $points = is_scalar( $raw ) ? min( 1000000, max( 0, (int) $raw ) ) : 0;
        update_option( 'twshop_referrals_reward_points', $points, false );
        echo '<div class="notice notice-success inline"><p>推薦碼設定已儲存。</p></div>';
    }
    echo '<div class="twshop-panel">';
    twshop_panel_head( 'users', '推薦碼設定' );
    echo '<div class="twshop-panel-body">';
    echo '<form method="post">';
    wp_nonce_field( 'twshop_referrals_settings' );
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="twshop-referrals-enabled">推薦碼功能</label></th><td>';
    echo '<label><input id="twshop-referrals-enabled" type="checkbox" name="twshop_referrals_enabled" value="yes" ' . checked( twshop_referrals_enabled(), true, false ) . '> 啟用推薦碼</label>';
    echo '<p class="twshop-hint">會員中心提供專屬推薦連結；朋友點擊後建立新帳號，系統自動綁定，不需手動輸入。</p>';
    echo '<p class="twshop-hint">停用後保留既有代碼與推薦關係。</p></td></tr>';
    echo '<tr><th scope="row"><label for="twshop-referral-points">推薦人獎勵點數</label></th><td>';
    echo '<input id="twshop-referral-points" class="small-text" name="twshop_referrals_reward_points" type="number" min="0" max="1000000" step="1" aria-describedby="twshop-referral-points-description" value="' . (int) twshop_referrals_reward_points() . '"> 點';
    echo '<p id="twshop-referral-points-description" class="twshop-hint">新會員首筆訂單完成後，只給推薦人紅利點數。設為 0 點只記錄推薦關係，不發放獎勵。</p></td></tr>';
    echo '<tr><th scope="row">獎勵規則</th><td>';
    echo '<p class="twshop-hint">首筆訂單須有實付金額且不能只有儲值金商品。取消或全額退款會追回獎勵，部分退款保留獎勵。</p>';
    echo '<p class="twshop-hint">獎勵只發一次；之後調整點數不會補發過去已完成的訂單，新會員不獲得獎勵。</p></td></tr>';
    echo '<tr><th scope="row">推薦碼使用說明</th><td>';
    echo '<p class="twshop-hint">來源保留 30 天，以首次有效推薦連結為準。推薦關係註冊後固定，不能自行推薦或更換推薦人。</p>';
    echo '<details class="twshop-help"><summary>詳細說明：來源保存與註冊方式</summary><p>以同一瀏覽器的 Cookie 保存來源，清除 Cookie、跨裝置或超過 30 天無法自動綁定。支援 WooCommerce／Blocksy 註冊與傳統結帳；其他註冊方式須在同一瀏覽器請求觸發 WordPress 新會員事件，社群登入與區塊結帳仍需依使用的外掛實測。頁面快取與 CDN 請排除含 ref 參數的連結，確保來源可保存。</p></details></td></tr>';
    echo '</tbody></table>';
    submit_button( '儲存設定', 'primary', 'twshop_referrals_save' );
    echo '</form></div></div>';
    twshop_referrals_admin_recent();
}

function twshop_referrals_admin_recent() {
    if ( ! current_user_can( 'manage_woocommerce' ) || ! twshop_module_enabled( 'points' ) ) return;
    global $wpdb;
    $table = twshop_referrals_table();
    $rows = $wpdb->get_results( "SELECT r.*, u.display_name AS member_name, f.display_name AS referrer_name
        FROM {$table} r LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
        LEFT JOIN {$wpdb->users} f ON f.ID = r.referrer_id
        WHERE r.referrer_id > 0 ORDER BY r.referred_at DESC, r.user_id DESC LIMIT 20", ARRAY_A );
    echo '<div class="twshop-panel">';
    twshop_panel_head( 'users', '最近 20 筆推薦註冊' );
    echo '<div class="twshop-panel-body">';
    if ( ! $rows ) { echo '<p class="twshop-hint">尚無推薦紀錄。</p></div></div>'; return; }
    $labels = array( '' => '尚未發獎', 'awarded' => '已發放', 'revoked' => '已追回', 'unfunded' => '未發放（點數為 0 或點數模組停用）' );
    echo '<table class="widefat striped"><thead><tr><th>新會員</th><th>推薦人</th><th>註冊日期</th><th>首筆訂單</th><th>獎勵點數</th><th>狀態</th></tr></thead><tbody>';
    foreach ( $rows as $row ) {
        echo '<tr><td>' . esc_html( ( $row['member_name'] ?: '已刪除會員' ) . ' (#' . $row['user_id'] . ')' ) . '</td>';
        echo '<td>' . esc_html( ( $row['referrer_name'] ?: '已刪除會員' ) . ' (#' . $row['referrer_id'] . ')' ) . '</td>';
        echo '<td>' . esc_html( $row['referred_at'] ) . '</td><td>' . ( $row['reward_order_id'] ? '#' . (int) $row['reward_order_id'] : '—' ) . '</td>';
        echo '<td>' . (int) $row['reward_points'] . '</td><td>' . esc_html( $labels[ $row['reward_status'] ] ?? '—' ) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}
