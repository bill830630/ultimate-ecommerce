<?php
/**
 * 介面：儲值金（會員餘額查詢、交易紀錄、設定，三個頁籤）。
 *
 * v25.8.67 起「儲值方案」頁籤整個移除——線上儲值改用「儲值金商品」（在 WooCommerce
 * 商品編輯頁設定，見 wallet-topup.php 的 twshop_add_wallet_product_fields()），不再有
 * 後台這裡另外維護的方案清單。詳見 CLAUDE.md「儲值金模組」一節的階段說明與各頁籤細節。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function twshop_wallet_render_page() {
    // 頁面 H1 標題 v25.8.71 改為「儲值中心」，跟側邊選單新名稱一致；三個頁籤名稱不變。
    twshop_render_admin_page( '儲值中心', function () {
        $tabs = array(
            'balances' => '會員餘額',
            'ledger'   => '交易紀錄',
            'settings' => '設定',
        );
        $current = twshop_get_current_admin_tab( $tabs );
        twshop_render_admin_tabs( $tabs, $current, 'twshop-wallet' );
        if ( 'balances' === $current ) twshop_wallet_balances_tab();
        elseif ( 'ledger' === $current ) twshop_wallet_ledger_tab();
        elseif ( 'settings' === $current ) twshop_wallet_settings_tab();
    } );
}

function twshop_wallet_render_ledger_table_rows( $rows, $show_user_column = false ) {
    if ( empty( $rows ) ) {
        $colspan = $show_user_column ? 6 : 5;
        echo '<tr><td colspan="' . esc_attr( $colspan ) . '" class="twshop-text-muted" style="text-align:center;">沒有符合條件的紀錄</td></tr>';
        return;
    }
    foreach ( $rows as $row ) {
        $order_id = (int) $row['order_id'];
        ?>
        <tr>
            <td><?php echo esc_html( $row['created_at'] ); ?></td>
            <?php if ( $show_user_column ) :
                $u = get_userdata( (int) $row['user_id'] ); ?>
                <td><?php echo $u ? esc_html( $u->display_name . '（' . $u->user_email . '）') : '#' . (int) $row['user_id']; ?></td>
            <?php endif; ?>
            <td><?php echo esc_html( twshop_wallet_type_label( $row['type'] ) ); ?></td>
            <td><?php echo esc_html( twshop_wallet_signed_amount( $row['amount_paid'] ) ); ?></td>
            <td><?php echo esc_html( number_format( (float) $row['balance_paid_after'], 2 ) ); ?></td>
            <td>
                <?php echo esc_html( $row['note'] ); ?>
                <?php if ( $order_id ) : ?>
                    <br><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ); ?>">訂單 #<?php echo esc_html( $order_id ); ?></a>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

/**
 * 手動調整儲值金表單的 $_POST 處理，在 `twshop_wallet_balances_tab()` 輸出任何內容之前
 * 呼叫，跟 `twshop_points_handle_manual_adjust()`（`page-points.php`）同一套慣例。
 *
 * @return string 'saved' 表示成功；非空字串代表要顯示的錯誤訊息；空字串代表沒有送出表單。
 */
function twshop_wallet_handle_manual_adjust( $user_id ) {
    if ( ! isset( $_POST['twshop_wallet_manual_adjust'] ) ) return '';
    check_admin_referer( 'twshop_wallet_manual_adjust' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) return '';

    $amount = isset( $_POST['twshop_wallet_manual_amount'] ) ? (float) wp_unslash( $_POST['twshop_wallet_manual_amount'] ) : 0.0;
    if ( 0.0 === round( $amount, 2 ) ) return '請輸入一個非 0 的數字。';

    $reason = sanitize_text_field( wp_unslash( $_POST['twshop_wallet_reason'] ?? '' ) );
    if ( '' === $reason ) $reason = '管理員手動調整';

    $result = twshop_wallet_apply( $user_id, $amount, 'adjust', 'adjust:' . wp_generate_uuid4(), array(
        'note'       => $reason,
        'created_by' => get_current_user_id(),
    ) );

    return is_wp_error( $result ) ? $result->get_error_message() : 'saved';
}

function twshop_wallet_balances_tab() {
    $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
    $notice  = $user_id ? twshop_wallet_handle_manual_adjust( $user_id ) : '';
    ?>
    <div class="twshop-panel">
        <?php twshop_panel_head( 'search', '搜尋會員' ); ?>
        <div class="twshop-panel-body">
            <form method="get">
                <input type="hidden" name="page" value="twshop-wallet">
                <input type="hidden" name="tab" value="balances">
                <?php twshop_render_customer_search_field( 'user_id', $user_id ); ?>
                <button type="submit" class="button">查看</button>
            </form>
        </div>
    </div>

    <?php
    if ( $user_id ) {
        $user = get_userdata( $user_id );
        if ( $user ) {
            if ( 'saved' === $notice ) {
                echo '<div class="notice notice-success is-dismissible"><p>儲值金已調整。</p></div>';
            } elseif ( $notice ) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
            }

            $balance = twshop_wallet_get_balance( $user_id );
            $history = twshop_wallet_get_ledger( $user_id, 20 );
            ?>
            <div class="twshop-panel">
                <?php twshop_panel_head(
                    'wallet',
                    esc_html( $user->display_name ) . '（' . esc_html( $user->user_email ) . '）的儲值金'
                ); ?>
                <div class="twshop-panel-body">
                    <p style="font-size:22px; font-weight:bold; margin-bottom:4px;">NT$<?php echo esc_html( number_format( $balance, 2 ) ); ?></p>

                    <h4>手動增減儲值金</h4>
                    <form method="post">
                        <?php wp_nonce_field( 'twshop_wallet_manual_adjust' ); ?>
                        <div style="display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end; margin-bottom:10px;">
                            <div>
                                <label style="display:block; font-weight:bold; margin-bottom:5px;">金額增減</label>
                                <input type="number" step="0.01" name="twshop_wallet_manual_amount" value="" class="regular-text" placeholder="例如 500 或 -100" style="width:200px;">
                            </div>
                            <div style="flex:1; min-width:220px;">
                                <label style="display:block; font-weight:bold; margin-bottom:5px;">備註原因</label>
                                <input type="text" name="twshop_wallet_reason" value="" class="regular-text" placeholder="手動調整" style="width:100%;">
                            </div>
                        </div>
                        <button type="submit" name="twshop_wallet_manual_adjust" value="1" class="button button-primary">儲存儲值金</button>
                        <p class="description">輸入正數為增加，輸入負數為扣除。</p>
                    </form>

                    <h4>最近異動（最新 20 筆）</h4>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr><th>時間</th><th>類型</th><th>金額異動</th><th>餘額</th><th>備註</th></tr>
                        </thead>
                        <tbody>
                            <?php twshop_wallet_render_ledger_table_rows( $history, false ); ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php
        } else {
            echo '<div class="notice notice-error"><p>找不到這位會員。</p></div>';
        }
    }
    ?>

    <div class="twshop-panel">
        <?php twshop_panel_head( 'list', '餘額總覽（依餘額排序，前 50 名）' ); ?>
        <div class="twshop-panel-body">
            <?php $overview = twshop_wallet_get_balances_overview( 50 ); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr><th>會員</th><th>餘額</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if ( empty( $overview ) ) : ?>
                        <tr><td colspan="3" class="twshop-text-muted" style="text-align:center;">目前沒有任何會員持有儲值金</td></tr>
                    <?php else : foreach ( $overview as $row ) :
                        $u = get_userdata( (int) $row['user_id'] );
                        if ( ! $u ) continue;
                        ?>
                        <tr>
                            <td><?php echo esc_html( $u->display_name . '（' . $u->user_email . '）' ); ?></td>
                            <td><?php echo esc_html( number_format( (float) $row['balance_paid'], 2 ) ); ?></td>
                            <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=twshop-wallet&tab=balances&user_id=' . $row['user_id'] ) ); ?>">查看</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

function twshop_wallet_ledger_tab() {
    $user_id   = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
    $type      = isset( $_GET['ledger_type'] ) ? sanitize_key( wp_unslash( $_GET['ledger_type'] ) ) : '';
    $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
    $date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
    $paged     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $per_page  = 50;

    $result = twshop_wallet_query_ledger( array(
        'user_id'   => $user_id,
        'type'      => $type,
        'date_from' => $date_from,
        'date_to'   => $date_to,
        'limit'     => $per_page,
        'offset'    => ( $paged - 1 ) * $per_page,
    ) );
    $total_pages = max( 1, (int) ceil( $result['total'] / $per_page ) );
    ?>
    <div class="twshop-panel">
        <?php twshop_panel_head( 'filter', '篩選' ); ?>
        <div class="twshop-panel-body">
            <form method="get">
                <input type="hidden" name="page" value="twshop-wallet">
                <input type="hidden" name="tab" value="ledger">
                <div style="display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end;">
                    <div>
                        <label style="display:block; font-weight:bold; margin-bottom:5px;">會員</label>
                        <?php twshop_render_customer_search_field( 'user_id', $user_id ); ?>
                    </div>
                    <div>
                        <label style="display:block; font-weight:bold; margin-bottom:5px;">類型</label>
                        <select name="ledger_type">
                            <option value="">全部</option>
                            <?php foreach ( twshop_wallet_get_type_labels() as $slug => $label ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $type, $slug ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-weight:bold; margin-bottom:5px;">起始日期</label>
                        <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
                    </div>
                    <div>
                        <label style="display:block; font-weight:bold; margin-bottom:5px;">結束日期</label>
                        <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
                    </div>
                    <div>
                        <button type="submit" class="button button-primary">篩選</button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=twshop-wallet&tab=ledger' ) ); ?>" class="button">清除</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="twshop-panel">
        <?php twshop_panel_head( 'list', '交易紀錄（共 ' . number_format( $result['total'] ) . ' 筆）' ); ?>
        <div class="twshop-panel-body">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr><th>時間</th><th>會員</th><th>類型</th><th>金額異動</th><th>餘額</th><th>備註</th></tr>
                </thead>
                <tbody>
                    <?php twshop_wallet_render_ledger_table_rows( $result['rows'], true ); ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) :
                $base_args = array_filter( array(
                    'page'        => 'twshop-wallet',
                    'tab'         => 'ledger',
                    'user_id'     => $user_id ?: null,
                    'ledger_type' => $type ?: null,
                    'date_from'   => $date_from ?: null,
                    'date_to'     => $date_to ?: null,
                ) );
                ?>
                <div style="margin-top:12px;">
                    <?php echo paginate_links( array(
                        'base'      => add_query_arg( array_merge( $base_args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'add_args'  => false,
                    ) ); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function twshop_wallet_settings_tab() {
    $full_amount   = twshop_option( 'wc_wallet_tier_spend_full_amount' );
    $email_enabled = twshop_option( 'wc_wallet_topup_email_enabled' );
    $email_subject = twshop_option( 'wc_wallet_topup_email_subject' );
    $email_body    = get_option( 'wc_wallet_topup_email_body', "親愛的 {name}：\n\n您的儲值已完成！\n\n本次儲值：NT{amount}\n目前餘額：NT{balance}\n\n感謝您的支持！" );
    ?>
    <form action="options.php" method="post">
        <?php settings_fields( 'wc_wallet_settings_group' ); ?>
        <div class="twshop-panel">
            <?php twshop_panel_head( 'settings', '儲值金與等級消費額' ); ?>
            <div class="twshop-panel-body">
                <table class="form-table">
                    <tr>
                        <th scope="row">用儲值金折抵時，等級消費額計算方式</th>
                        <td>
                            <label><input type="checkbox" name="wc_wallet_tier_spend_full_amount" value="yes" <?php checked( $full_amount, 'yes' ); ?>> 計入商品全額（折抵掉的部分仍算進等級消費額）</label>
                            <p class="description">預設勾選：儲值金是顧客先前已經付過的真錢，折抵消費時仍視同全額消費計算會員等級門檻。取消勾選則只計入實際透過其他金流付款的部分（跟點數折抵的既有計算方式一致）。線上儲值訂單本身（不論金額大小）一律不計入消費額與紅利點數，不受這個設定影響。</p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="twshop-panel">
            <?php twshop_panel_head( 'mail', '儲值成功通知信' ); ?>
            <div class="twshop-panel-body">
                <table class="form-table">
                    <tr>
                        <th scope="row">寄送通知信</th>
                        <td><label><input type="checkbox" name="wc_wallet_topup_email_enabled" value="yes" <?php checked( $email_enabled, 'yes' ); ?>> 儲值訂單付款完成、入帳成功後寄送通知信給會員</label></td>
                    </tr>
                    <tr>
                        <th scope="row">主旨</th>
                        <td><input type="text" name="wc_wallet_topup_email_subject" value="<?php echo esc_attr( $email_subject ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row">內容</th>
                        <td>
                            <textarea name="wc_wallet_topup_email_body" rows="6" class="regular-text" style="width:100%; max-width:500px;"><?php echo esc_textarea( $email_body ); ?></textarea>
                            <p class="description">可用 <code>{name}</code>／<code>{amount}</code>（本次儲值金額）／<code>{balance}</code>（目前總餘額）／<code>{order_id}</code>。</p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <?php submit_button( '儲存設定' ); ?>
    </form>
    <?php
}
