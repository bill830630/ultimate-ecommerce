<?php
/**
 * 退換貨：會員中心（v25.8.152）。
 *   /my-account/returns/           申請列表
 *   /my-account/returns/{訂單ID}/  申請表單
 *   /my-account/returns/?view=ID   申請詳情（寄回物流單號、取消）
 * 入口：訂單列表的「申請退換貨」按鈕、訂單詳情頁底下的申請狀態區塊。
 * 送出、取消、填寄回單號都走 AJAX（nonce twshop_frontend_action）；前台 JS 在 assets/js/frontend/returns.js。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function twshop_returns_account_url( $order_id = 0 ) {
    return wc_get_endpoint_url( 'returns', $order_id ? (string) (int) $order_id : '', wc_get_page_permalink( 'myaccount' ) );
}

function twshop_returns_status_badge( $status, $type = '' ) {
    return '<span class="twshop-returns-status twshop-returns-status--' . esc_attr( $status ) . '">' . esc_html( twshop_returns_status_label( $status, $type ) ) . '</span>';
}

// =========================================================================
// 頁籤內容
// =========================================================================

function twshop_returns_endpoint_content( $value = '' ) {
    if ( ! is_user_logged_in() ) return;
    $user_id = get_current_user_id();
    echo '<div class="twshop-returns-account">';

    $view = absint( $_GET['view'] ?? 0 );
    if ( $view ) {
        $row = twshop_returns_get( $view );
        if ( $row && (int) $row['user_id'] === $user_id ) twshop_returns_render_detail( $row );
        else echo '<p>找不到這筆申請。</p>';
    } elseif ( $value && ctype_digit( (string) $value ) ) {
        $order = wc_get_order( (int) $value );
        if ( 'cancel' === sanitize_key( $_GET['type'] ?? '' ) ) {
            $elig = twshop_returns_cancel_eligibility( $order, $user_id );
            if ( $elig['ok'] ) { twshop_returns_render_cancel_form( $order ); echo '</div>'; return; }
            echo '<p>' . esc_html( $elig['message'] ) . '</p><p><a class="button" href="' . esc_url( wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) ) ) . '">返回我的訂單</a></p></div>';
            return;
        }
        $elig  = twshop_returns_order_eligibility( $order, $user_id );
        if ( $elig['ok'] ) twshop_returns_render_form( $order, $elig );
        else echo '<p>' . esc_html( $elig['message'] ) . '</p><p><a class="button" href="' . esc_url( twshop_returns_account_url() ) . '">返回申請列表</a></p>';
    } else {
        twshop_returns_render_list( $user_id );
    }
    echo '</div>';
}

function twshop_returns_render_list( $user_id ) {
    $result = twshop_returns_query( array( 'user_id' => $user_id, 'limit' => 50, 'count_total' => false ) );
    if ( ! $result['rows'] ) {
        echo '<p>目前沒有退換貨申請。需要退換貨時，請到「訂單」找到要申請的訂單，點選「申請退換貨」。</p>';
        echo '<p><a class="button" href="' . esc_url( wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) ) ) . '">前往我的訂單</a></p>';
        return;
    }
    ?>
    <table class="woocommerce-table shop_table twshop-returns-table">
        <thead><tr><th>申請編號</th><th>訂單</th><th>類型</th><th>狀態</th><th>申請日期</th><th></th></tr></thead>
        <tbody>
        <?php foreach ( $result['rows'] as $row ) : ?>
            <tr>
                <td>#<?php echo (int) $row['id']; ?></td>
                <td>#<?php echo esc_html( ( $o = wc_get_order( $row['order_id'] ) ) ? $o->get_order_number() : $row['order_id'] ); ?></td>
                <td><?php echo esc_html( twshop_returns_type_label( $row['type'] ) ); ?></td>
                <td><?php echo wp_kses_post( twshop_returns_status_badge( $row['status'], $row['type'] ) ); ?></td>
                <td><?php echo esc_html( mysql2date( 'Y-m-d', $row['created_at'] ) ); ?></td>
                <td><a class="button" href="<?php echo esc_url( twshop_returns_customer_view_url( $row['id'] ) ); ?>">查看</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/**
 * 原生訂單列表多一個「退換貨」欄：這張訂單的申請狀態（連到詳情）。
 * 這位會員一筆申請都沒有時不加欄位，訂單列表維持原樣。每個請求只查一次（依使用者）。
 */
function twshop_returns_orders_rows_by_order() {
    static $maps = array();
    $user_id = get_current_user_id();
    if ( ! $user_id ) return array();
    // 同一請求若切換會員身分，不可沿用上一位會員的申請資料。
    if ( ! isset( $maps[ $user_id ] ) ) {
        $maps[ $user_id ] = array();
        $result = twshop_returns_query( array( 'user_id' => $user_id, 'limit' => 200, 'count_total' => false ) );
        foreach ( $result['rows'] as $row ) $maps[ $user_id ][ (int) $row['order_id'] ][] = $row;
    }
    return $maps[ $user_id ];
}

function twshop_returns_orders_column( $columns ) {
    if ( ! twshop_returns_orders_rows_by_order() ) return $columns;
    $out = array();
    foreach ( $columns as $key => $label ) {
        $out[ $key ] = $label;
        if ( 'order-status' === $key ) $out['twshop-returns'] = '退換貨';
    }
    if ( ! isset( $out['twshop-returns'] ) ) $out['twshop-returns'] = '退換貨';
    return $out;
}

function twshop_returns_orders_column_content( $order ) {
    $rows = twshop_returns_orders_rows_by_order()[ $order->get_id() ] ?? array();
    if ( ! $rows ) { echo '—'; return; }
    foreach ( $rows as $row ) {
        printf(
            '<a href="%s" class="twshop-returns-order-link">%s %s</a><br>',
            esc_url( twshop_returns_customer_view_url( $row['id'] ) ),
            esc_html( twshop_returns_type_label( $row['type'] ) ),
            wp_kses_post( twshop_returns_status_badge( $row['status'], $row['type'] ) )
        );
    }
}

/** 申請表單／詳情頁沒有自己的選單項目，選單反白「訂單」。 */
function twshop_returns_menu_highlight_orders( $classes, $endpoint ) {
    if ( 'orders' === $endpoint && function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'returns' ) ) $classes[] = 'is-active';
    return $classes;
}

function twshop_returns_render_form( $order, array $elig ) {
    $types   = twshop_returns_allowed_types();
    $reasons = twshop_returns_reasons();
    $max     = twshop_returns_max_photos();
    $days    = twshop_returns_window_days();
    ?>
    <h3>申請退換貨：訂單 #<?php echo esc_html( $order->get_order_number() ); ?></h3>
    <?php if ( $days > 0 ) : ?><p class="twshop-returns-hint">收貨後 <?php echo (int) $days; ?> 天內可以申請；商品需保持全新、包裝完整。</p><?php endif; ?>

    <form class="twshop-returns-form" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="order_id" value="<?php echo (int) $order->get_id(); ?>" />

        <?php if ( count( $types ) > 1 ) : ?>
        <p class="twshop-returns-field">
            <span class="twshop-returns-label">申請類型</span>
            <?php foreach ( $types as $i => $type ) : ?>
                <label><input type="radio" name="type" value="<?php echo esc_attr( $type ); ?>" <?php checked( 0 === $i ); ?> /> <?php echo esc_html( twshop_returns_type_label( $type ) ); ?></label>
            <?php endforeach; ?>
        </p>
        <?php else : ?>
            <input type="hidden" name="type" value="<?php echo esc_attr( $types[0] ); ?>" />
        <?php endif; ?>

        <div class="twshop-returns-field">
            <span class="twshop-returns-label">要申請的商品與數量</span>
            <table class="woocommerce-table shop_table twshop-returns-items">
                <thead><tr><th>商品</th><th>可申請</th><th>數量</th></tr></thead>
                <tbody>
                <?php foreach ( $elig['items'] as $item_id => $info ) : ?>
                    <tr>
                        <td><?php echo esc_html( $info['name'] ); ?></td>
                        <td><?php echo (int) $info['available']; ?></td>
                        <td><input type="number" class="twshop-returns-qty" name="qty[<?php echo (int) $item_id; ?>]" min="0" max="<?php echo (int) $info['available']; ?>" value="0" /></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="twshop-returns-field">
            <label class="twshop-returns-label" for="twshop-returns-reason">申請原因</label>
            <select id="twshop-returns-reason" name="reason">
                <option value="">請選擇</option>
                <?php foreach ( $reasons as $reason ) : ?><option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option><?php endforeach; ?>
            </select>
        </p>

        <p class="twshop-returns-field twshop-returns-exchange-field" style="<?php echo 'exchange' === $types[0] ? '' : 'display:none;'; ?>">
            <label class="twshop-returns-label" for="twshop-returns-exchange">想換成的規格或商品</label>
            <textarea id="twshop-returns-exchange" name="exchange_note" rows="2"></textarea>
        </p>

        <p class="twshop-returns-field">
            <label class="twshop-returns-label" for="twshop-returns-note">補充說明</label>
            <textarea id="twshop-returns-note" name="reason_note" rows="3"></textarea>
        </p>

        <?php if ( $max > 0 ) : ?>
        <p class="twshop-returns-field">
            <label class="twshop-returns-label" for="twshop-returns-photos">商品照片<?php echo 'yes' === twshop_option( 'wc_returns_photos_required' ) ? '（必填）' : '（選填）'; ?></label>
            <input type="file" id="twshop-returns-photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple />
            <span class="twshop-returns-hint">最多 <?php echo (int) $max; ?> 張，JPG／PNG／WebP，每張不超過 5MB。</span>
        </p>
        <?php endif; ?>

        <p>
            <button type="submit" class="button alt twshop-returns-submit">送出申請</button>
            <a class="button" href="<?php echo esc_url( twshop_returns_account_url() ); ?>">取消</a>
            <span class="twshop-returns-message" aria-live="polite"></span>
        </p>
    </form>
    <?php
}

/** 取消訂單申請表單：整張訂單、不選商品、不用照片，只要原因。 */
function twshop_returns_render_cancel_form( $order ) {
    $reasons = twshop_returns_reasons();
    ?>
    <h3>申請取消訂單：訂單 #<?php echo esc_html( $order->get_order_number() ); ?></h3>
    <p class="twshop-returns-hint">這張訂單還沒出貨，店家核准後會取消整張訂單並全額退款（原付款方式）。已出貨的訂單無法取消，請在收到商品後申請退換貨。</p>

    <form class="twshop-returns-form" novalidate>
        <input type="hidden" name="order_id" value="<?php echo (int) $order->get_id(); ?>" />
        <input type="hidden" name="type" value="cancel" />

        <p class="twshop-returns-field">
            <label class="twshop-returns-label" for="twshop-returns-reason">取消原因</label>
            <select id="twshop-returns-reason" name="reason">
                <option value="">請選擇</option>
                <?php foreach ( $reasons as $reason ) : ?><option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option><?php endforeach; ?>
            </select>
        </p>

        <p class="twshop-returns-field">
            <label class="twshop-returns-label" for="twshop-returns-note">補充說明</label>
            <textarea id="twshop-returns-note" name="reason_note" rows="3"></textarea>
        </p>

        <p>
            <button type="submit" class="button alt twshop-returns-submit">送出申請</button>
            <a class="button" href="<?php echo esc_url( wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) ) ); ?>">返回</a>
            <span class="twshop-returns-message" aria-live="polite"></span>
        </p>
    </form>
    <?php
}

function twshop_returns_render_detail( array $row ) {
    $order = wc_get_order( $row['order_id'] );
    ?>
    <p><a href="<?php echo esc_url( twshop_returns_account_url() ); ?>">&larr; 返回申請列表</a></p>
    <h3>申請 #<?php echo (int) $row['id']; ?>　<?php echo wp_kses_post( twshop_returns_status_badge( $row['status'], $row['type'] ) ); ?></h3>
    <table class="woocommerce-table shop_table twshop-returns-detail">
        <tr><th>類型</th><td><?php echo esc_html( twshop_returns_type_label( $row['type'] ) ); ?></td></tr>
        <tr><th>訂單</th><td>#<?php echo esc_html( $order ? $order->get_order_number() : $row['order_id'] ); ?></td></tr>
        <tr><th>商品</th><td><?php foreach ( $row['items'] as $it ) echo esc_html( sprintf( '%s × %d', $it['name'] ?? '', (int) ( $it['qty'] ?? 0 ) ) ) . '<br>'; ?></td></tr>
        <tr><th>原因</th><td><?php echo esc_html( $row['reason'] ); ?><?php if ( '' !== trim( (string) $row['reason_note'] ) ) echo '<br>' . nl2br( esc_html( $row['reason_note'] ) ); ?></td></tr>
        <?php if ( 'exchange' === $row['type'] && '' !== trim( (string) $row['exchange_note'] ) ) : ?>
            <tr><th>想換成</th><td><?php echo nl2br( esc_html( $row['exchange_note'] ) ); ?></td></tr>
        <?php endif; ?>
        <?php if ( $row['photos'] ) : ?>
            <tr><th>照片</th><td class="twshop-returns-photos"><?php foreach ( $row['photos'] as $i => $f ) : ?>
                <a href="<?php echo esc_url( twshop_returns_photo_url( $row['id'], $f ) ); ?>" target="_blank" rel="noopener">照片 <?php echo (int) $i + 1; ?></a>
            <?php endforeach; ?></td></tr>
        <?php endif; ?>
        <?php if ( '' !== trim( (string) $row['admin_note'] ) ) : ?><tr><th>店家回覆</th><td><?php echo nl2br( esc_html( $row['admin_note'] ) ); ?></td></tr><?php endif; ?>
        <?php if ( 'rejected' === $row['status'] && '' !== trim( (string) $row['reject_reason'] ) ) : ?><tr><th>未核准原因</th><td><?php echo nl2br( esc_html( $row['reject_reason'] ) ); ?></td></tr><?php endif; ?>
        <?php if ( 'refunded' === $row['status'] ) : ?><tr><th>退款金額</th><td><?php echo wp_kses_post( wc_price( $row['refund_amount'] ) ); ?></td></tr><?php endif; ?>
        <?php if ( $row['tracking_no'] ) : ?><tr><th>寄回物流</th><td><?php echo esc_html( trim( $row['carrier'] . ' ' . $row['tracking_no'] ) ); ?></td></tr><?php endif; ?>
    </table>

    <?php if ( 'approved' === $row['status'] ) :
        $instructions = trim( (string) twshop_option( 'wc_returns_instructions' ) ); ?>
        <?php if ( '' !== $instructions ) : ?>
            <div class="twshop-returns-instructions"><strong>寄回地址與說明</strong><br><?php echo nl2br( esc_html( $instructions ) ); ?></div>
        <?php endif; ?>
        <form class="twshop-returns-ship-form" data-return-id="<?php echo (int) $row['id']; ?>">
            <h4>寄出商品後，請填寫物流資訊</h4>
            <p class="twshop-returns-field"><label class="twshop-returns-label">物流公司</label><input type="text" name="carrier" maxlength="100" placeholder="例如：7-11、全家、黑貓" /></p>
            <p class="twshop-returns-field"><label class="twshop-returns-label">物流單號</label><input type="text" name="tracking_no" maxlength="100" /></p>
            <p><button type="submit" class="button alt">送出寄回資訊</button> <span class="twshop-returns-message" aria-live="polite"></span></p>
        </form>
    <?php endif; ?>

    <?php if ( in_array( $row['status'], array( 'pending', 'approved' ), true ) ) : ?>
        <p><button type="button" class="button twshop-returns-cancel" data-return-id="<?php echo (int) $row['id']; ?>"><?php echo 'cancel' === $row['type'] ? '撤回申請' : '取消這筆申請'; ?></button> <span class="twshop-returns-message" aria-live="polite"></span></p>
    <?php endif;
}

// =========================================================================
// 訂單入口
// =========================================================================

function twshop_returns_cancel_url( $order_id ) {
    return add_query_arg( 'type', 'cancel', twshop_returns_account_url( $order_id ) );
}

/** 訂單列表：符合資格的訂單多一個「申請退換貨」（已完成）或「申請取消訂單」（未出貨）按鈕 */
function twshop_returns_my_orders_actions( $actions, $order ) {
    $elig = twshop_returns_order_eligibility( $order, get_current_user_id() );
    if ( $elig['ok'] ) $actions['twshop-return'] = array( 'url' => twshop_returns_account_url( $order->get_id() ), 'name' => '申請退換貨' );
    $cancel = twshop_returns_cancel_eligibility( $order, get_current_user_id() );
    if ( $cancel['ok'] ) $actions['twshop-cancel-order'] = array( 'url' => twshop_returns_cancel_url( $order->get_id() ), 'name' => '申請取消訂單' );
    return $actions;
}

/** 訂單詳情頁底下：這張訂單已有的申請狀態＋（符合資格時）申請按鈕。只在會員中心頁顯示。 */
function twshop_returns_order_details_block( $order ) {
    if ( ! is_account_page() || ! is_user_logged_in() || (int) $order->get_customer_id() !== get_current_user_id() ) return;
    $rows = twshop_returns_for_order( $order->get_id() );
    $elig   = twshop_returns_order_eligibility( $order, get_current_user_id() );
    $cancel = twshop_returns_cancel_eligibility( $order, get_current_user_id() );
    if ( ! $rows && ! $elig['ok'] && ! $cancel['ok'] ) return;
    echo '<section class="twshop-returns-order-block"><h2>退換貨</h2>';
    foreach ( $rows as $row ) {
        printf(
            '<p>#%d　%s　%s　<a href="%s">查看</a></p>',
            (int) $row['id'], esc_html( twshop_returns_type_label( $row['type'] ) ), wp_kses_post( twshop_returns_status_badge( $row['status'], $row['type'] ) ),
            esc_url( twshop_returns_customer_view_url( $row['id'] ) )
        );
    }
    if ( $elig['ok'] ) echo '<p><a class="button" href="' . esc_url( twshop_returns_account_url( $order->get_id() ) ) . '">申請退換貨</a></p>';
    if ( $cancel['ok'] ) echo '<p><a class="button" href="' . esc_url( twshop_returns_cancel_url( $order->get_id() ) ) . '">申請取消訂單</a></p>';
    echo '</section>';
}

// =========================================================================
// AJAX
// =========================================================================

function twshop_returns_ajax_guard() {
    check_ajax_referer( 'twshop_frontend_action', 'twshop_nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( array( 'msg' => '請先登入。' ) );
}

function twshop_returns_ajax_submit() {
    twshop_returns_ajax_guard();
    $user_id = get_current_user_id();
    $order   = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
    $is_cancel = 'cancel' === sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
    $elig    = $is_cancel ? twshop_returns_cancel_eligibility( $order, $user_id ) : twshop_returns_order_eligibility( $order, $user_id );
    if ( ! $elig['ok'] ) wp_send_json_error( array( 'msg' => $elig['message'] ) );

    // 先把照片全部驗證並存好；任何一張失敗就清掉已存的，整筆不成立（取消訂單申請沒有照片）
    $stored = array();
    $files  = $is_cancel ? null : ( $_FILES['photos'] ?? null );
    if ( $files && is_array( $files['name'] ?? null ) ) {
        $count = 0;
        foreach ( $files['name'] as $i => $name ) {
            if ( '' === $name ) continue;
            if ( ++$count > twshop_returns_max_photos() ) { $err = new WP_Error( 'max', sprintf( '照片最多 %d 張。', twshop_returns_max_photos() ) ); break; }
            $res = twshop_returns_store_photo( $files['tmp_name'][ $i ], $name, $files['size'][ $i ], $files['error'][ $i ] );
            if ( is_wp_error( $res ) ) { $err = $res; break; }
            $stored[] = $res;
        }
        if ( isset( $err ) ) {
            foreach ( $stored as $f ) @unlink( twshop_returns_photo_dir() . $f );
            wp_send_json_error( array( 'msg' => $err->get_error_message() ) );
        }
    }

    $input = wp_unslash( $_POST );
    $id    = twshop_returns_create( $order, $user_id, $input, $stored );
    if ( is_wp_error( $id ) ) {
        foreach ( $stored as $f ) @unlink( twshop_returns_photo_dir() . $f );
        wp_send_json_error( array( 'msg' => $id->get_error_message() ) );
    }
    wp_send_json_success( array( 'msg' => '申請已送出。', 'redirect' => twshop_returns_customer_view_url( $id ) ) );
}

function twshop_returns_ajax_owner_row() {
    $row = twshop_returns_get( absint( $_POST['return_id'] ?? 0 ) );
    if ( ! $row || (int) $row['user_id'] !== get_current_user_id() ) wp_send_json_error( array( 'msg' => '找不到這筆申請。' ) );
    return $row;
}

function twshop_returns_ajax_cancel() {
    twshop_returns_ajax_guard();
    $row = twshop_returns_ajax_owner_row();
    $res = twshop_returns_transition( $row['id'], 'cancelled', array(), '顧客取消申請。' );
    if ( is_wp_error( $res ) ) wp_send_json_error( array( 'msg' => $res->get_error_message() ) );
    wp_send_json_success( array( 'msg' => '已取消。', 'redirect' => twshop_returns_customer_view_url( $row['id'] ) ) );
}

function twshop_returns_ajax_ship() {
    twshop_returns_ajax_guard();
    $row     = twshop_returns_ajax_owner_row();
    $carrier = sanitize_text_field( wp_unslash( $_POST['carrier'] ?? '' ) );
    $track   = sanitize_text_field( wp_unslash( $_POST['tracking_no'] ?? '' ) );
    if ( '' === $track ) wp_send_json_error( array( 'msg' => '請填寫物流單號。' ) );
    $res = twshop_returns_transition( $row['id'], 'shipped', array( 'return_method' => 'self_ship', 'carrier' => mb_substr( $carrier, 0, 100 ), 'tracking_no' => mb_substr( $track, 0, 100 ) ), sprintf( '顧客已寄回（%s %s）。', $carrier, $track ) );
    if ( is_wp_error( $res ) ) wp_send_json_error( array( 'msg' => $res->get_error_message() ) );
    wp_send_json_success( array( 'msg' => '已送出寄回資訊。', 'redirect' => twshop_returns_customer_view_url( $row['id'] ) ) );
}

// =========================================================================
// 前台資產（會員中心頁面）
// =========================================================================

function twshop_returns_enqueue_assets() {
    if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_user_logged_in() ) return;
    twshop_enqueue_asset_script( 'frontend/returns', array(
        'twshopReturns' => array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'twshop_frontend_action' ),
        ),
    ) );
}
