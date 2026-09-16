<?php
/**
 * 儲值金：購物車折抵、結帳扣款、取消/退款自動退回、訂單編輯頁 metabox。
 *
 * 跟紅利點數的結帳折抵流程（points-engine.php）走同一套骨架（AJAX 套用 → 購物車費用
 * filter → 訂單建立時記下套用金額 → 結帳完成時冪等扣款 → 訂單狀態變化時退回），但**不用
 * postmeta 進度旗標**（點數用 `_twshop_points_redeemed_refunded`/`_amount` 這組 meta
 * 追蹤「已經退還/追回多少」，儲值金直接對帳本 `SUM()` 這張表本身就是唯一真實來源，
 * 少一層旗標同步的風險，見下方 twshop_wallet_get_order_spent()/twshop_wallet_get_order_returned()）。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 依「可用餘額」與「扣掉優惠券/點數與其他負費用後實際還要付的金額」兩個上限，
 * 換算實際可套用的折抵金額。AJAX 套用、購物車費用計算兩處都靠這支，確保上限判斷
 * 只有一套邏輯（比照點數 twshop_get_points_discount_amount() 的既有做法）。
 *
 * 跟點數不同，儲值金是 1:1 現金，沒有「兌換比率」要換算，回傳值就是折抵金額本身。
 */
function twshop_get_wallet_discount_amount( $requested_amount ) {
    if ( ! WC()->cart || ! is_user_logged_in() ) return 0.0;

    $amount = round( max( 0, (float) $requested_amount ), 2 );
    if ( $amount <= 0 ) return 0.0;

    $balance = twshop_wallet_get_balance( get_current_user_id() );
    $amount  = min( $amount, $balance );

    // 上限另外不能超過「扣掉優惠券與其他折扣後實際還要付的金額」：WooCommerce 會把負費用
    // 夾到總額不低於 0，超出的部分沒有真的折到錢，儲值金卻照扣（比照點數的既有踩坑修正）。
    // 折抵費用掛 priority 30（見 init.php），此時優惠券、twshop 購物車層折扣（20）、點數
    // 折抵（25）都已經算好，用同一輪 foreach 排除掉自己這筆費用（重算時會重複加總）。
    $cart_subtotal = WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax();
    $payable = $cart_subtotal - WC()->cart->get_discount_total() - WC()->cart->get_discount_tax();
    foreach ( WC()->cart->get_fees() as $fee ) {
        if ( $fee->amount < 0 && $fee->name !== '儲值金折抵' ) $payable += (float) $fee->amount;
    }
    $amount = max( 0, min( $amount, round( $payable, 2 ) ) );

    return round( $amount, 2 );
}

function twshop_ajax_apply_wallet() {
    check_ajax_referer( 'twshop_frontend_action', 'twshop_nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => '請先登入才能使用儲值金折抵。' ) );
    }

    $requested = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0.0;
    $amount    = twshop_get_wallet_discount_amount( $requested );

    if ( $amount > 0 ) {
        WC()->session->set( 'twshop_wallet_applied', $amount );
    } else {
        WC()->session->__unset( 'twshop_wallet_applied' );
    }

    // WC 在下次頁面載入時，若購物車內容沒變，只會從 session 的 cart_totals 快照還原總計，
    // 不會重新觸發 woocommerce_cart_calculate_fees；這裡強制重算一次，讓快照立刻反映
    // 最新的折抵費用（比照點數 twshop_ajax_apply_points() 的既有做法）。
    WC()->cart->calculate_totals();

    wp_send_json_success( array( 'actual_amount' => $amount ) );
}

/**
 * 這是「折抵金額超過餘額」的最後一道、也是唯一真正堵住漏洞的防線：WooCommerce 核心在
 * add_to_cart/cart_item_removed/applied_coupon 等動作都會自動重跑 calculate_totals()，
 * 所以只要購物車內容或折抵有任何變動，這裡都會重新核對一次，不只是 AJAX 當下算過就沒事。
 */
function twshop_apply_wallet_discount_fee( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    if ( ! is_user_logged_in() ) return;

    $applied = (float) WC()->session->get( 'twshop_wallet_applied', 0 );
    if ( $applied <= 0 ) return;

    $amount = twshop_get_wallet_discount_amount( $applied );

    if ( abs( $amount - $applied ) > 0.001 ) {
        WC()->session->set( 'twshop_wallet_applied', $amount );
    }

    if ( $amount <= 0 ) {
        WC()->session->__unset( 'twshop_wallet_applied' );
        return;
    }

    $cart->add_fee( '儲值金折抵', -$amount, false );
}

/**
 * 建立訂單當下記下這張訂單實際套用的折抵金額（此時 fee 已依餘額/上限算完，session 值即實際值）。
 * 付款失敗後重新送出結帳時 WooCommerce 會重用同一張訂單，這個 hook 每次都會重新寫入。
 */
function twshop_store_wallet_applied_on_order( $order ) {
    $amount = WC()->session ? (float) WC()->session->get( 'twshop_wallet_applied', 0 ) : 0;
    $order->update_meta_data( '_twshop_wallet_applied', round( max( 0, $amount ), 2 ) );
}

function twshop_clear_applied_wallet_on_cart_emptied() {
    if ( WC()->session ) WC()->session->__unset( 'twshop_wallet_applied' );
}

/**
 * 結帳送出前的最後防線：購物車內容加入後到送出結帳這段時間，儲值金餘額可能因為
 * 另一個分頁/裝置先花掉而減少，這裡重新核對套用金額是否仍在餘額之內。
 */
function twshop_validate_wallet_balance( $data, $errors ) {
    if ( ! is_user_logged_in() ) return;
    $applied = (float) ( WC()->session ? WC()->session->get( 'twshop_wallet_applied', 0 ) : 0 );
    if ( $applied <= 0 ) return;

    $balance = twshop_wallet_get_balance( get_current_user_id() );
    if ( $applied > $balance + 0.001 ) {
        $errors->add( 'validation', '儲值金餘額不足以完成本次折抵，請重新確認購物車。' );
    }
}

/**
 * 這張訂單目前帳本裡「淨扣掉多少」（spend 型紀錄的加總，取正號）。跟點數模組用
 * postmeta 進度旗標（`_twshop_points_redeemed_refunded_amount` 等）追蹤不同，
 * 這裡直接對帳本 SUM()，帳本本身就是唯一真實來源，不用另外同步一份進度狀態。
 */
function twshop_wallet_get_order_spent( $order_id ) {
    global $wpdb;
    // spend 紀錄的 amount 皆為負數，取絕對值還原成正數的「扣了多少」
    return abs( (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(amount_paid),0) FROM " . twshop_wallet_ledger_table() . " WHERE order_id = %d AND type = 'spend'",
        (int) $order_id
    ) ) );
}

/**
 * 這張訂單目前帳本裡「已經退回多少」（spend_return 型紀錄的加總）。
 */
function twshop_wallet_get_order_returned( $order_id ) {
    global $wpdb;
    return (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(amount_paid),0) FROM " . twshop_wallet_ledger_table() . " WHERE order_id = %d AND type = 'spend_return'",
        (int) $order_id
    ) );
}

/**
 * 結帳扣款，冪等：以「這張訂單目前應扣金額」（_twshop_wallet_applied）對照「帳本裡這張
 * 訂單目前淨扣掉的金額」（已扣 − 已退），只處理差額。ref 用 order_id + 目標金額組成，
 * **刻意不用隨機值**——兩個並發請求（例如兩個分頁同時完成同一張訂單的結帳）若讀到
 * 相同的「已扣」狀態、算出相同的目標差額，會產生完全相同的 ref，
 * twshop_wallet_apply() 的冪等機制才擋得住，避免重複扣款。
 */
function twshop_deduct_wallet_on_checkout( $order_id, $posted_data, $order ) {
    $user_id = $order->get_customer_id();
    if ( ! $user_id ) return;

    $target = round( (float) $order->get_meta( '_twshop_wallet_applied' ), 2 );

    $spent    = twshop_wallet_get_order_spent( $order_id );
    $returned = twshop_wallet_get_order_returned( $order_id );
    $already  = round( $spent - $returned, 2 );

    if ( $target <= 0 && $already <= 0 ) return;

    $delta = round( $target - $already, 2 );
    if ( 0.0 === $delta ) return;

    $ref = 'wallet_settle:' . $order_id . ':' . number_format( $target, 2, '.', '' );

    if ( $delta > 0 ) {
        twshop_wallet_apply( $user_id, -$delta, 'spend', $ref, array(
            'order_id' => $order_id,
            'note'     => '訂單 #' . $order_id . ' 儲值金折抵',
        ) );
    } else {
        // 重新結帳時折抵金額比之前少（例如餘額被另一個分頁花掉、套用金額被自動夾小），
        // 退回差額。
        twshop_wallet_apply( $user_id, -$delta, 'spend_return', $ref, array(
            'order_id' => $order_id,
            'note'     => '訂單 #' . $order_id . ' 重新結帳，儲值金差額退還',
        ) );
    }
}

/**
 * 訂單取消/已退款/付款失敗：把這張訂單目前「還沒退回」的餘額全部退回。跟點數模組
 * 用 `_done` 旗標避免重複全額退還不同，這裡直接算「當初扣了多少 − 已經退了多少」，
 * 天然就是差額——先前若已經透過 twshop_handle_order_refund_wallet()（部分退款）
 * 退回一部分，這裡只會退剩下的部分，不會重複。
 */
function twshop_refund_wallet_on_order_cancel( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    $user_id = $order->get_customer_id();
    if ( ! $user_id ) return;

    $spent    = twshop_wallet_get_order_spent( $order_id );
    $returned = twshop_wallet_get_order_returned( $order_id );
    $remaining = round( $spent - $returned, 2 );
    if ( $remaining <= 0 ) return;

    // ref 只跟 order_id 綁定（沒有金額變數）：這支的語意是「全額退回剩餘部分」，
    // 同一張訂單只應該被這條路徑完整處理一次；就算因為狀態變化被觸發多次
    // （例如同時符合多個退回狀態的轉換），第二次進來時 $remaining 已經是 0，
    // 函式在上面就直接 return 了，不會走到這裡產生第二個 ref。
    twshop_wallet_apply( $user_id, $remaining, 'spend_return', 'wallet_cancel_return:' . $order_id, array(
        'order_id' => $order_id,
        'note'     => '訂單 #' . $order_id . ' 取消/退款，儲值金折抵退還',
    ) );
}

/**
 * WooCommerce 部分退款（後台訂單頁按「退款」但不一定改變訂單狀態）時，依「本次退款金額 /
 * 訂單原始總額」的比例退回儲值金。用 refund_id（每筆退款各自獨立、不會重複觸發）當 ref，
 * 天然冪等，不需要像點數模組那樣額外維護「累計已處理」的 postmeta。
 */
function twshop_handle_order_refund_wallet( $order_id, $refund_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    $user_id = $order->get_customer_id();
    if ( ! $user_id ) return;

    $refund = wc_get_order( $refund_id );
    if ( ! $refund ) return;

    // WC_Order_Refund::get_total() 存的是負數（代表退款金額），取絕對值還原成正數
    $refunded_amount = abs( (float) $refund->get_total() );
    if ( $refunded_amount <= 0 ) return;

    $order_total = (float) $order->get_total();
    // 全額用儲值金付款、訂單金額 0 的單，$order_total 是 0，比例算不出來——這種單交給
    // 訂單編輯頁的「手動退回儲值金」按鈕處理（見下方 metabox），這裡直接不處理避免除以 0。
    if ( $order_total <= 0 ) return;

    $spent_total = twshop_wallet_get_order_spent( $order_id );
    if ( $spent_total <= 0 ) return;

    $proportion    = min( 1, $refunded_amount / $order_total );
    $target_return = round( $spent_total * $proportion, 2 );

    $already_returned = twshop_wallet_get_order_returned( $order_id );

    $delta = round( $target_return - $already_returned, 2 );
    if ( $delta <= 0 ) return;

    twshop_wallet_apply( $user_id, $delta, 'spend_return', 'wallet_partial_return:' . $refund_id, array(
        'order_id' => $order_id,
        'note'     => '訂單 #' . $order_id . ' 部分退款（' . round( $proportion * 100 ) . '%），儲值金折抵退還',
    ) );
}

/**
 * 購物車頁「儲值金折抵」區塊。跟點數的 twshop_render_points_redemption_ui() 同一套慣例：
 * 外層 wrapper 固定輸出（即使未登入/沒有折抵也一樣），AJAX 局部刷新需要固定錨點可以
 * replaceWith()，見 twshop_ajax_refresh_components() 與 twshop-frontend.js。
 */
function twshop_render_wallet_redemption_ui() {
    echo '<div class="twshop-wallet-redemption-wrapper">';

    if ( ! is_user_logged_in() ) {
        echo '</div>';
        return;
    }

    $user_id = get_current_user_id();
    $balance = twshop_wallet_get_balance( $user_id );
    $applied = WC()->session ? (float) WC()->session->get( 'twshop_wallet_applied', 0 ) : 0;

    if ( $balance <= 0 && $applied <= 0 ) {
        echo '</div>';
        return;
    }
    ?>
    <div class="twshop-wallet-redemption">
        <h4>使用儲值金折抵</h4>
        <p>目前餘額：NT$<?php echo esc_html( number_format( $balance, 2 ) ); ?></p>
        <div class="twshop-wallet-input-row">
            <input type="number" inputmode="decimal" id="twshop_wallet_input" min="0" step="1"
                   max="<?php echo esc_attr( $balance ); ?>"
                   placeholder="輸入要折抵的金額"
                   value="<?php echo esc_attr( $applied ?: '' ); ?>">
            <button type="button" class="button" id="twshop_apply_wallet_btn"><?php echo $applied ? '更新折抵' : '套用折抵'; ?></button>
        </div>
        <?php if ( $applied > 0 ) : ?>
            <p class="twshop-wallet-notice" style="color:#2271b1; margin-top:6px;">本次訂單將折抵 NT$<?php echo esc_html( number_format( $applied, 2 ) ); ?>。</p>
        <?php endif; ?>
    </div>
    <?php
    echo '</div>';
}

function twshop_classic_cart_wallet() {
    if ( ! twshop_module_enabled( 'wallet' ) ) return;
    if ( 'yes' !== twshop_option( 'wc_classic_cart_show_wallet' ) ) return;
    twshop_render_wallet_redemption_ui();
}

/**
 * 訂單編輯頁「儲值金」metabox：顯示這張訂單套用/已扣/已退回的金額，並提供「手動退回」按鈕
 * ——全額用儲值金付款、訂單金額 0 的單，WooCommerce 原生退款介面無法輸入金額（0 元訂單
 * 沒有能退的「訂單金額」），只能靠這裡手動觸發跟 twshop_refund_wallet_on_order_cancel()
 * 同一套「退回剩餘部分」邏輯。
 *
 * 【HPOS 注意】比照 twshop_register_order_logistics_metabox()（order-logistics.php）
 * 的既有寫法：legacy 畫面收到 WP_Post，HPOS 畫面收到 WC_Order 物件，兩種畫面各自掛一個
 * add_meta_boxes_{screen} hook（見 init.php）。
 */
function twshop_register_order_wallet_metabox( $post_or_order ) {
    $order = ( $post_or_order instanceof WC_Order )
        ? $post_or_order
        : wc_get_order( $post_or_order->ID ?? 0 );

    if ( ! $order instanceof WC_Order ) return;
    // 這張訂單完全沒有套用過儲值金（沒有 meta、帳本也查不到任何紀錄）就不註冊，
    // 避免每張訂單編輯頁都多一個空白區塊。
    $has_meta = '' !== $order->get_meta( '_twshop_wallet_applied' );
    $spent = twshop_wallet_get_order_spent( $order->get_id() );
    if ( ! $has_meta && $spent <= 0 ) return;

    $screen = get_current_screen();
    if ( ! $screen ) return;

    add_meta_box(
        'twshop-order-wallet-info',
        '儲值金',
        'twshop_render_order_wallet_metabox',
        $screen->id,
        'side',
        'default',
        array( 'order' => $order )
    );
}

function twshop_render_order_wallet_metabox( $post_or_order, $box ) {
    $order = $box['args']['order'] ?? ( ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 ) );
    if ( ! $order instanceof WC_Order ) { echo '<p>找不到這張訂單。</p>'; return; }

    $order_id = $order->get_id();
    $applied  = round( (float) $order->get_meta( '_twshop_wallet_applied' ), 2 );
    $spent_total    = twshop_wallet_get_order_spent( $order_id );
    $returned_total = twshop_wallet_get_order_returned( $order_id );
    $remaining      = round( $spent_total - $returned_total, 2 );
    ?>
    <p>套用折抵：NT$<?php echo esc_html( number_format( $applied, 2 ) ); ?></p>
    <p>已扣款：NT$<?php echo esc_html( number_format( $spent_total, 2 ) ); ?></p>
    <p>已退回：NT$<?php echo esc_html( number_format( $returned_total, 2 ) ); ?></p>
    <?php if ( $remaining > 0 ) : ?>
        <p style="color:#b32d2e;">尚未退回：NT$<?php echo esc_html( number_format( $remaining, 2 ) ); ?></p>
        <button type="button" class="button" id="twshop-wallet-manual-return" data-order-id="<?php echo esc_attr( $order_id ); ?>">手動退回儲值金</button>
        <p class="description">全額儲值金付款、訂單金額 0 的單，WooCommerce 原生退款介面無法輸入金額，用這個按鈕退回剩餘尚未退回的部分。</p>
        <script>
        jQuery(function($){
            $('#twshop-wallet-manual-return').on('click', function(){
                var $btn = $(this);
                if (!confirm('確定要把尚未退回的儲值金全部退回給這位會員嗎？')) return;
                $btn.prop('disabled', true).text('處理中…');
                $.post(ajaxurl, {
                    action: 'twshop_wallet_manual_return',
                    order_id: $btn.data('order-id'),
                    twshop_nonce: '<?php echo esc_js( wp_create_nonce( 'twshop_admin_action' ) ); ?>'
                }, function(res){
                    if (res && res.success) {
                        alert('已退回。');
                        location.reload();
                    } else {
                        alert((res && res.data && res.data.msg) || '退回失敗，請重新整理頁面後再試。');
                        $btn.prop('disabled', false).text('手動退回儲值金');
                    }
                }).fail(function(){
                    alert('退回失敗，請檢查網路連線後重試。');
                    $btn.prop('disabled', false).text('手動退回儲值金');
                });
            });
        });
        </script>
    <?php else : ?>
        <p style="color:#166534;">已全部退回。</p>
    <?php endif; ?>
    <?php
}

/**
 * 訂單編輯頁「手動退回儲值金」按鈕的 AJAX handler。跟 twshop_refund_wallet_on_order_cancel()
 * 共用同一套「退回這張訂單尚未退回的剩餘部分」邏輯——沒有另外重寫一份，避免兩處判斷
 * 「該退多少」的算法日後各自修改而漂移不同步。
 */
function twshop_ajax_wallet_manual_return() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );

    $order_id = absint( $_POST['order_id'] ?? 0 );
    if ( ! $order_id ) wp_send_json_error( array( 'msg' => '缺少訂單編號。' ) );

    twshop_refund_wallet_on_order_cancel( $order_id );
    wp_send_json_success();
}
