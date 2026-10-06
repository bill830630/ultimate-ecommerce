<?php
/**
 * 退換貨：後台（v25.8.152）。依使用者要求直接併進 WooCommerce 原本的訂單管理，不另立選單：
 *   - 訂單編輯頁：「退換貨申請」metabox，完整處理（核准／拒絕／標記收到／建立退款／換貨完成／備註）
 *   - 訂單列表：「退換貨」欄位（狀態標籤）＋「退換貨」篩選（有申請／處理中／待審核）
 *   - WooCommerce → 設定 → 「退換貨」頁籤：申請期限、原因、寄回地址、通知信…
 * 審核操作走 AJAX（nonce twshop_admin_action，權限 manage_woocommerce）：assets/js/admin/returns.js。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function twshop_returns_admin_status_badge( $status, $type = '' ) {
    $class = in_array( $status, array( 'refunded', 'exchanged' ), true ) ? 'twshop-badge--ok' : ( in_array( $status, array( 'pending', 'rejected' ), true ) ? 'twshop-badge--warn' : '' );
    return '<span class="twshop-badge ' . esc_attr( $class ) . '">' . esc_html( twshop_returns_status_label( $status, $type ) ) . '</span>';
}

// =========================================================================
// 訂單編輯頁 metabox：這張訂單的退換貨申請與處理（HPOS／傳統兩種畫面共用）
// =========================================================================

function twshop_returns_register_order_metabox( $post_or_order ) {
    $order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
    if ( ! $order instanceof WC_Order || ! twshop_returns_for_order( $order->get_id() ) ) return;
    $screen = get_current_screen();
    if ( ! $screen ) return;
    // 放主欄位（normal／high）：裡面有退款表單與多個操作，側邊欄太窄
    add_meta_box( 'twshop-order-returns', '退換貨申請', 'twshop_returns_render_order_metabox', $screen->id, 'normal', 'high', array( 'order' => $order ) );
}

function twshop_returns_render_order_metabox( $post_or_order, $box ) {
    $order = $box['args']['order'] ?? null;
    if ( ! $order instanceof WC_Order ) { echo '<p>找不到這張訂單。</p>'; return; }
    twshop_enqueue_asset_script( 'admin/returns', array(
        'twshopReturnsAdmin' => array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'twshop_admin_action' ) ),
    ) );
    foreach ( twshop_returns_for_order( $order->get_id() ) as $row ) twshop_returns_render_request_panel( $row, $order );
}

/** 單筆申請：資料表＋處理操作。同一張訂單可以有多筆申請，各自一個 .twshop-returns-actions 面板。 */
function twshop_returns_render_request_panel( array $row, $order ) {
    $id   = (int) $row['id'];
    $user = $row['user_id'] ? get_userdata( $row['user_id'] ) : null;
    ?>
    <div class="twshop-returns-request" style="margin-bottom:24px; padding-bottom:12px; border-bottom:1px solid #dcdcde;">
        <h3 style="margin-top:0;">申請 #<?php echo $id; ?>　<?php echo esc_html( twshop_returns_type_label( $row['type'] ) ); ?>　<?php echo wp_kses_post( twshop_returns_admin_status_badge( $row['status'], $row['type'] ) ); ?></h3>
        <table class="form-table" style="margin-top:0;">
            <tr><th>申請時間</th><td><?php echo esc_html( $row['created_at'] ); ?><?php if ( $user ) echo '　' . esc_html( $user->display_name ); ?></td></tr>
            <tr><th>商品</th><td><?php foreach ( $row['items'] as $it ) echo esc_html( sprintf( '%s × %d', $it['name'] ?? '', (int) ( $it['qty'] ?? 0 ) ) ) . '<br>'; ?></td></tr>
            <tr><th>原因</th><td><?php echo esc_html( $row['reason'] ); ?><?php if ( '' !== trim( (string) $row['reason_note'] ) ) echo '<br>' . nl2br( esc_html( $row['reason_note'] ) ); ?></td></tr>
            <?php if ( 'exchange' === $row['type'] ) : ?><tr><th>想換成</th><td><?php echo nl2br( esc_html( $row['exchange_note'] ) ); ?></td></tr><?php endif; ?>
            <?php if ( $row['photos'] ) : ?><tr><th>照片</th><td><?php foreach ( $row['photos'] as $i => $f ) : ?>
                <a href="<?php echo esc_url( twshop_returns_photo_url( $id, $f ) ); ?>" target="_blank" rel="noopener">照片 <?php echo (int) $i + 1; ?></a>&nbsp;
            <?php endforeach; ?></td></tr><?php endif; ?>
            <?php if ( $row['tracking_no'] ) : ?><tr><th>寄回物流</th><td><?php echo esc_html( trim( $row['carrier'] . ' ' . $row['tracking_no'] ) ); ?></td></tr><?php endif; ?>
            <?php if ( '' !== trim( (string) $row['admin_note'] ) ) : ?><tr><th>給顧客的回覆</th><td><?php echo nl2br( esc_html( $row['admin_note'] ) ); ?></td></tr><?php endif; ?>
            <?php if ( '' !== trim( (string) $row['reject_reason'] ) ) : ?><tr><th>拒絕原因</th><td><?php echo nl2br( esc_html( $row['reject_reason'] ) ); ?></td></tr><?php endif; ?>
            <?php if ( $row['refund_id'] ) : ?><tr><th>退款</th><td><?php echo wp_kses_post( wc_price( $row['refund_amount'] ) ); ?>（退款單 #<?php echo (int) $row['refund_id']; ?>）</td></tr><?php endif; ?>
        </table>
        <div class="twshop-returns-actions" data-return-id="<?php echo $id; ?>">
            <?php twshop_returns_render_actions( $row, $order ); ?>
            <p class="twshop-returns-admin-msg" aria-live="polite"></p>
        </div>
    </div>
    <?php
}

function twshop_returns_render_actions( array $row, $order ) {
    $status = $row['status'];
    if ( 'pending' === $status && 'cancel' === $row['type'] ) :
        $shipment = function_exists( 'twshop_returns_ecpay_shipment_info' ) ? twshop_returns_ecpay_shipment_info( $order ) : array( 'state' => 'none' ); ?>
        <p><strong>顧客申請取消整張訂單（尚未出貨）。</strong>核准後會自動取消訂單並全額退款，綠界信用卡訂單會同時向綠界退刷。</p>
        <?php if ( 'supported' === $shipment['state'] ) : ?>
            <p><strong>這張訂單已建立綠界 7-ELEVEN 物流單</strong>（<?php echo esc_html( $shipment['logistics_id'] ); ?>），核准時會先自動取消物流單；取消失敗就不會核准。</p>
            <p><label><input type="checkbox" class="twshop-sw" data-field="skip_shipment" value="1"> 我已在綠界後台處理物流單（略過自動取消）</label></p>
        <?php elseif ( 'unsupported' === $shipment['state'] ) : ?>
            <p class="twshop-text-danger"><strong>注意：</strong>這張訂單已建立綠界物流單（<?php echo esc_html( $shipment['reason'] ); ?>，無法自動取消）。核准取消後，請到綠界後台取消物流單。</p>
        <?php endif; ?>
        <p class="twshop-text-muted">核准時會重新確認訂單仍是「處理中」；若已經出貨，請改為拒絕。</p>
        <p><label><input type="checkbox" class="twshop-sw" data-field="restock" value="1" checked> 退回庫存</label></p>
        <p><button type="button" class="button button-primary twshop-returns-op" data-op="cancel_approve">核准取消並退款</button></p>
        <hr>
        <p><label><strong>拒絕原因（必填，會寄給顧客）</strong><br><textarea data-field="reject_reason" rows="3" class="large-text"></textarea></label></p>
        <p><button type="button" class="button twshop-button-danger twshop-returns-op" data-op="reject">拒絕</button></p>
    <?php elseif ( 'pending' === $status ) : ?>
        <p><label><strong>給顧客的回覆（選填，核准信會帶）</strong><br><textarea data-field="admin_note" rows="3" class="large-text"></textarea></label></p>
        <p><button type="button" class="button button-primary twshop-returns-op" data-op="approve">核准</button></p>
        <hr>
        <p><label><strong>拒絕原因（必填，會寄給顧客）</strong><br><textarea data-field="reject_reason" rows="3" class="large-text"></textarea></label></p>
        <p><button type="button" class="button twshop-button-danger twshop-returns-op" data-op="reject">拒絕</button></p>
    <?php elseif ( in_array( $status, array( 'approved', 'shipped' ), true ) ) : ?>
        <p class="twshop-text-muted"><?php echo 'approved' === $status ? '已核准，等顧客寄回並填寫物流單號。若已收到商品，可直接標記。' : '顧客已寄回，確認收到商品後標記。'; ?></p>
        <p><button type="button" class="button button-primary twshop-returns-op" data-op="receive">標記為已收到退貨</button></p>
    <?php elseif ( 'received' === $status && 'return' === $row['type'] && $order instanceof WC_Order ) :
        $calc_no   = twshop_returns_calc_refund( $order, $row, false );
        $calc_ship = twshop_returns_calc_refund( $order, $row, true ); ?>
        <p><strong>退款試算</strong>：品項實付 <?php echo wp_kses_post( wc_price( $calc_no['items_amount'] ) ); ?>，訂單剩餘可退 <?php echo wp_kses_post( wc_price( $calc_no['remaining'] ) ); ?>。</p>
        <p class="twshop-text-muted">用點數或儲值金折抵的訂單，可退現金會比品項加總少；折抵的部分由系統依比例自動退回點數／儲值金。</p>
        <p><label>退款金額 <input type="number" step="0.01" min="0" data-field="amount" value="<?php echo esc_attr( $calc_no['amount'] ); ?>" data-amount-no-ship="<?php echo esc_attr( $calc_no['amount'] ); ?>" data-amount-ship="<?php echo esc_attr( $calc_ship['amount'] ); ?>" style="width:140px;"></label></p>
        <p><label><input type="checkbox" class="twshop-sw" data-field="include_shipping" value="1"> 一併退運費</label></p>
        <p><label><input type="checkbox" class="twshop-sw" data-field="restock" value="1" checked> 退回庫存</label></p>
        <p class="twshop-text-danger"><strong>注意：</strong>這裡只建立 WooCommerce 退款紀錄（並連動點數、儲值金、庫存、發票作廢），<strong>金流端的退款請到綠界後台手動處理</strong>。</p>
        <p><button type="button" class="button button-primary twshop-returns-op" data-op="refund">建立退款</button></p>
    <?php elseif ( 'received' === $status && 'exchange' === $row['type'] ) : ?>
        <p class="twshop-text-muted">換貨請由客服人工出貨，出貨後標記完成。</p>
        <p><label><strong>給顧客的回覆（選填，如換貨出貨資訊）</strong><br><textarea data-field="admin_note" rows="3" class="large-text"></textarea></label></p>
        <p><button type="button" class="button button-primary twshop-returns-op" data-op="exchanged">標記為換貨完成</button></p>
    <?php else : ?>
        <p class="twshop-text-muted">這筆申請已結案（<?php echo esc_html( twshop_returns_status_label( $status, $row['type'] ) ); ?>）。</p>
    <?php endif; ?>
    <hr>
    <p><label><strong>內部備註（只寫進訂單備註，顧客看不到）</strong><br><textarea data-field="note" rows="2" class="large-text"></textarea></label></p>
    <p><button type="button" class="button twshop-returns-op" data-op="note">新增備註</button></p>
    <?php
}

function twshop_returns_ajax_admin() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'msg' => '無權限。' ) );
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );

    $id  = absint( $_POST['return_id'] ?? 0 );
    $row = twshop_returns_get( $id );
    if ( ! $row ) wp_send_json_error( array( 'msg' => '找不到這筆申請。' ) );
    $op  = sanitize_key( wp_unslash( $_POST['op'] ?? '' ) );
    $get = function ( $k ) { return sanitize_textarea_field( wp_unslash( $_POST[ $k ] ?? '' ) ); };

    switch ( $op ) {
        case 'approve':
            $res = twshop_returns_transition( $id, 'approved', array( 'admin_note' => $get( 'admin_note' ) ), '已核准。' );
            break;
        case 'reject':
            $reason = $get( 'reject_reason' );
            if ( '' === trim( $reason ) ) wp_send_json_error( array( 'msg' => '請填寫拒絕原因。' ) );
            $res = twshop_returns_transition( $id, 'rejected', array( 'reject_reason' => $reason ), '已拒絕。' );
            break;
        case 'cancel_approve':
            $res = twshop_returns_do_cancel( $id, array(
                'restock'       => ! empty( $_POST['restock'] ) && '0' !== $_POST['restock'],
                'skip_shipment' => ! empty( $_POST['skip_shipment'] ) && '0' !== $_POST['skip_shipment'],
            ) );
            break;
        case 'receive':
            $res = twshop_returns_transition( $id, 'received', array(), '已收到退貨。' );
            break;
        case 'exchanged':
            $res = twshop_returns_transition( $id, 'exchanged', array( 'admin_note' => $get( 'admin_note' ) ?: $row['admin_note'] ), '換貨完成。' );
            break;
        case 'refund':
            $res = twshop_returns_do_refund( $id, array(
                'amount'           => isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '',
                'include_shipping' => ! empty( $_POST['include_shipping'] ) && '0' !== $_POST['include_shipping'],
                'restock'          => ! empty( $_POST['restock'] ) && '0' !== $_POST['restock'],
            ) );
            break;
        case 'note':
            $text = $get( 'note' );
            if ( '' === trim( $text ) ) wp_send_json_error( array( 'msg' => '請輸入備註內容。' ) );
            twshop_returns_log( wc_get_order( $row['order_id'] ), $id, '備註：' . $text );
            $res = true;
            break;
        default:
            wp_send_json_error( array( 'msg' => '未知的操作。' ) );
    }
    if ( is_wp_error( $res ) ) wp_send_json_error( array( 'msg' => $res->get_error_message() ) );
    wp_send_json_success( array( 'msg' => '已完成。' ) );
}

// =========================================================================
// 訂單列表：「退換貨」欄位與篩選
// =========================================================================

function twshop_returns_order_list_columns( $columns ) {
    $result = array();
    foreach ( $columns as $key => $label ) {
        $result[ $key ] = $label;
        if ( 'order_status' === $key ) $result['twshop_returns'] = '退換貨';
    }
    if ( ! isset( $result['twshop_returns'] ) ) $result['twshop_returns'] = '退換貨';
    return $result;
}

/** 欄位內容：每筆申請一個狀態標籤，點了進訂單編輯頁處理。HPOS 傳入 WC_Order，傳統畫面傳入文章 ID。 */
function twshop_returns_order_list_column_content( $column, $order_or_id ) {
    if ( 'twshop_returns' !== $column ) return;
    $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
    if ( ! $order instanceof WC_Order ) return;
    $rows = twshop_returns_for_order( $order->get_id() );
    if ( ! $rows ) { echo '<span class="na">–</span>'; return; }
    foreach ( $rows as $row ) {
        printf(
            '<a href="%s" style="text-decoration:none; display:block; margin-bottom:3px;">%s %s</a>',
            esc_url( $order->get_edit_order_url() ),
            esc_html( twshop_returns_type_label( $row['type'] ) ),
            wp_kses_post( twshop_returns_admin_status_badge( $row['status'], $row['type'] ) )
        );
    }
}

function twshop_returns_filter_options() {
    return array(
        ''        => '退換貨：全部',
        'any'     => '有退換貨申請',
        'pending' => '待審核',
        'active'  => '處理中（待審核／待寄回／已寄回／已收到）',
    );
}

function twshop_returns_render_orders_filter() {
    $current = sanitize_key( wp_unslash( $_GET['twshop_returns_filter'] ?? '' ) );
    echo '<select name="twshop_returns_filter">';
    foreach ( twshop_returns_filter_options() as $value => $label ) {
        printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
    }
    echo '</select>';
}

/** 篩選對應的訂單 ID 清單；沒有符合時回傳 array( 0 )（讓列表為空，而不是忽略篩選）。 */
function twshop_returns_filtered_order_ids( $filter ) {
    global $wpdb;
    $table = twshop_returns_table();
    if ( 'pending' === $filter ) {
        $ids = $wpdb->get_col( "SELECT DISTINCT order_id FROM {$table} WHERE status = 'pending'" );
    } elseif ( 'active' === $filter ) {
        $ids = $wpdb->get_col( "SELECT DISTINCT order_id FROM {$table} WHERE status IN ('pending','approved','shipped','received')" );
    } elseif ( 'any' === $filter ) {
        $ids = $wpdb->get_col( "SELECT DISTINCT order_id FROM {$table}" );
    } else {
        return null;
    }
    $ids = array_map( 'intval', (array) $ids );
    return $ids ?: array( 0 );
}

/** HPOS 訂單列表：woocommerce_order_list_table_prepare_items_query_args */
function twshop_returns_filter_hpos_args( $args ) {
    $ids = twshop_returns_filtered_order_ids( sanitize_key( wp_unslash( $_GET['twshop_returns_filter'] ?? '' ) ) );
    if ( null !== $ids ) $args['post__in'] = $ids;
    return $args;
}

/** 傳統（文章）訂單列表 */
function twshop_returns_filter_legacy_query( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) return;
    $ids = twshop_returns_filtered_order_ids( sanitize_key( wp_unslash( $_GET['twshop_returns_filter'] ?? '' ) ) );
    if ( null !== $ids ) $query->set( 'post__in', $ids );
}

function twshop_returns_legacy_filter_dropdown( $post_type ) {
    if ( 'shop_order' === $post_type ) twshop_returns_render_orders_filter();
}

// =========================================================================
// WooCommerce → 設定 → 「退換貨」頁籤（選項 key 沿用 wc_returns_*，用 WooCommerce 原生設定 API 輸出與儲存）
// =========================================================================

function twshop_returns_wc_settings_tab( $tabs ) {
    $tabs['returns'] = '退換貨';
    return $tabs;
}

function twshop_returns_wc_settings_fields() {
    $statuses = array();
    foreach ( wc_get_order_statuses() as $key => $label ) $statuses[ str_replace( 'wc-', '', $key ) ] = $label;
    $cats  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
    $cat_opts = array();
    if ( ! is_wp_error( $cats ) ) foreach ( $cats as $t ) $cat_opts[ $t->slug ] = $t->name;

    return array(
        array( 'title' => '退換貨', 'type' => 'title', 'id' => 'twshop_returns_section', 'desc' => '顧客在「我的帳號」自助申請退貨或換貨，申請會出現在訂單列表的「退換貨」欄位，並在訂單編輯頁處理。' ),
        array( 'title' => '開放退貨', 'id' => 'wc_returns_allow_return', 'type' => 'checkbox', 'default' => 'yes', 'desc' => '允許顧客申請退貨' ),
        array( 'title' => '開放取消訂單', 'id' => 'wc_returns_allow_cancel', 'type' => 'checkbox', 'default' => 'yes', 'desc' => '允許顧客對「處理中」（已付款、還沒出貨）的訂單申請取消，經審核後自動取消並全額退款（綠界信用卡同時退刷）' ),
        array( 'title' => '開放換貨', 'id' => 'wc_returns_allow_exchange', 'type' => 'checkbox', 'default' => 'yes', 'desc' => '允許顧客申請換貨（只收申請與審核，出貨由客服人工處理）' ),
        array( 'title' => '可申請的訂單狀態', 'id' => 'wc_returns_allowed_statuses', 'type' => 'multiselect', 'class' => 'wc-enhanced-select', 'default' => array( 'completed' ), 'options' => $statuses, 'desc_tip' => '預設只有「已完成」。訂單必須有完成日期才能計算申請期限；開啟「訂單強化」模組時，綠界物流顯示取件完成會自動把訂單轉為已完成。' ),
        array( 'title' => '申請期限（天）', 'id' => 'wc_returns_window_days', 'type' => 'number', 'default' => '7', 'css' => 'width:90px;', 'custom_attributes' => array( 'min' => '0' ), 'desc' => '從訂單完成日起算，0 代表不限制。' ),
        array( 'title' => '不可退換的商品分類', 'id' => 'wc_returns_excluded_cats', 'type' => 'multiselect', 'class' => 'wc-enhanced-select', 'default' => array(), 'options' => $cat_opts, 'desc_tip' => '個別商品可在商品編輯頁勾選「不可退換貨」。儲值金商品與實付 $0 的贈品固定不可申請。' ),
        array( 'title' => '申請原因清單', 'id' => 'wc_returns_reasons', 'type' => 'textarea', 'default' => twshop_get_option_defaults()['wc_returns_reasons'], 'css' => 'width:400px; height:130px;', 'desc' => '一行一個，顧客申請時用下拉選單選擇。' ),
        array( 'title' => '商品照片', 'id' => 'wc_returns_photos_required', 'type' => 'checkbox', 'default' => 'no', 'desc' => '必須上傳照片' ),
        array( 'title' => '照片張數上限', 'id' => 'wc_returns_max_photos', 'type' => 'number', 'default' => '3', 'css' => 'width:90px;', 'custom_attributes' => array( 'min' => '0', 'max' => '10' ), 'desc' => '0＝不開放上傳。' ),
        array( 'title' => '寄回地址與說明', 'id' => 'wc_returns_instructions', 'type' => 'textarea', 'default' => '', 'css' => 'width:400px; height:110px;', 'desc' => '核准後會顯示在顧客的申請頁，並寫進核准通知信。' ),
        array( 'title' => '通知信（顧客）', 'id' => 'wc_returns_notify_customer', 'type' => 'checkbox', 'default' => 'yes', 'desc' => '申請收到、核准、拒絕、收到退貨、換貨完成時寄信給顧客（退款通知由 WooCommerce 原生的「已退款的訂單」信件負責，請在 WooCommerce → 設定 → 電子郵件確認它是開啟的）' ),
        array( 'title' => '通知信（管理員）', 'id' => 'wc_returns_notify_admin', 'type' => 'checkbox', 'default' => 'yes', 'desc' => '有新申請時寄信給管理員' ),
        array( 'title' => '管理員通知信箱', 'id' => 'wc_returns_admin_email', 'type' => 'email', 'default' => '', 'css' => 'width:300px;', 'placeholder' => get_option( 'admin_email' ), 'desc' => '留空使用網站管理員信箱。' ),
        array( 'type' => 'sectionend', 'id' => 'twshop_returns_section' ),
    );
}

function twshop_returns_wc_settings_output() {
    woocommerce_admin_fields( twshop_returns_wc_settings_fields() );
}

function twshop_returns_wc_settings_save() {
    woocommerce_update_options( twshop_returns_wc_settings_fields() );
    // WooCommerce 通用儲存只做 wc_clean；多選欄位另外依各自的白名單過濾
    update_option( 'wc_returns_allowed_statuses', twshop_sanitize_order_status_array( get_option( 'wc_returns_allowed_statuses', array() ) ) );
    update_option( 'wc_returns_excluded_cats', twshop_sanitize_term_slugs( (array) get_option( 'wc_returns_excluded_cats', array() ), 'product_cat' ) );
    update_option( 'wc_returns_window_days', absint( get_option( 'wc_returns_window_days', 7 ) ) );
    update_option( 'wc_returns_max_photos', min( 10, absint( get_option( 'wc_returns_max_photos', 3 ) ) ) );
}

// =========================================================================
// 商品編輯頁：不可退換貨
// =========================================================================

function twshop_returns_product_field() {
    woocommerce_wp_checkbox( array(
        'id'          => '_twshop_no_return',
        'label'       => '不可退換貨',
        'description' => '勾選後，這項商品在會員中心不能申請退換貨。',
    ) );
}

function twshop_returns_save_product_field( $post_id ) {
    $product = wc_get_product( $post_id );
    if ( ! $product ) return;
    $product->update_meta_data( '_twshop_no_return', isset( $_POST['_twshop_no_return'] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce 已在 woocommerce_process_product_meta 前驗證
    $product->save_meta_data();
}
