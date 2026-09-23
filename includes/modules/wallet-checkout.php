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
 * 購物車「儲值金折抵」費用名稱。儲值金跟點數一樣視為付款方式而不是商品折扣，
 * 免運門檻等判斷要靠這個名稱把它排除（見 twshop_get_active_free_shipping_rule()）。
 */
function twshop_wallet_fee_name() {
    return twshop_wallet_term() . '折抵';
}

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
        if ( $fee->amount < 0 && $fee->name !== twshop_wallet_fee_name() ) $payable += (float) $fee->amount;
    }
    $amount = max( 0, min( $amount, round( $payable, 2 ) ) );

    return round( $amount, 2 );
}

/**
 * 購物車內是否有任一項目是儲值金商品（product type = wallet_credit）。
 * twshop_can_use_wallet()／twshop_wallet_block_reason() 共用，避免各寫一份迴圈。
 *
 * 儲值金不能拿來購買儲值金商品：用既有餘額折抵儲值金商品等於「用儲值金換更多儲值金」，
 * 面額與售價不一致時（見「儲值金模組 ▸ 線上儲值改用儲值金商品」一節的促銷用途）會讓
 * 顧客不花一毛真錢就無中生有出額外餘額，是必須擋死的業務規則，不是單純的使用體驗選項。
 */
function twshop_cart_has_wallet_credit_product() {
    if ( ! WC()->cart ) return false;
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( ! empty( $cart_item['data'] ) && twshop_is_wallet_credit_product( $cart_item['data'] ) ) {
            return true;
        }
    }
    return false;
}

/**
 * 儲值金折抵可否使用，比照 twshop_can_redeem_points()（points-engine.php）的既有慣例：
 * twshop_apply_wallet_discount_fee() 加費用前一定要先過這關，是唯一真正的伺服器端守門；
 * twshop_wallet_block_reason() 把同一組條件轉成給顧客看的文字，兩者共用
 * twshop_wallet_cart_restriction()，條件只有一份。
 */
function twshop_can_use_wallet() {
    if ( ! WC()->cart || WC()->cart->is_empty() ) return false;

    // 儲值金不能拿來購買儲值金商品，見 twshop_cart_has_wallet_credit_product() 的說明。
    if ( twshop_cart_has_wallet_credit_product() ) return false;

    return null === twshop_wallet_cart_restriction();
}

function twshop_wallet_cart_restriction() {
    return twshop_cart_usage_restriction( 'wc_wallet_min_cart_amount', 'wc_wallet_restrict_type', 'wc_wallet_restrict_values' );
}

/**
 * 回傳儲值金折抵區塊的顯示狀態，比照 twshop_points_block_reason()（points-engine.php）
 * 的既有慣例：
 *   false  → 不顯示區塊（購物車為空）
 *   null   → 可正常使用（顯示輸入欄）
 *   string → 不可使用的原因說明（顯示提示文字）
 *
 * v25.8.76 起新增：原本餘額不足時整個隱藏區塊（不留任何說明），改成跟點數一樣顯示
 * 原因文字；同時比照點數新增最低消費門檻／分類/標籤限制兩項設定。
 * v25.8.78 起新增：購物車內含儲值金商品時禁止使用儲值金折抵，見
 * twshop_cart_has_wallet_credit_product() 的說明。
 */
function twshop_wallet_block_reason() {
    if ( ! WC()->cart || WC()->cart->is_empty() ) return false;

    if ( twshop_cart_has_wallet_credit_product() ) {
        return twshop_wallet_text( 'topup_restricted_text' );
    }

    $user_id = get_current_user_id();
    $applied = WC()->session ? (float) WC()->session->get( 'twshop_wallet_applied', 0 ) : 0;
    $balance = $user_id ? twshop_wallet_get_balance( $user_id ) : 0.0;

    if ( $balance <= 0 && $applied <= 0 ) {
        return twshop_wallet_text( 'no_balance_text' );
    }

    $block = twshop_wallet_cart_restriction();
    if ( null === $block ) return null;
    if ( 'min' === $block[0] ) {
        return str_replace( '{amount}', twshop_plain_price( $block[1] ), twshop_wallet_text( 'min_cart_text' ) );
    }
    return str_replace( '{names}', esc_html( $block[1] ), twshop_wallet_text( 'restricted_text' ) );
}

/**
 * 購物車含儲值金商品時，把可用付款方式限縮成「儲值中心 ▸ 設定」允許清單裡的金流
 * （v25.8.79 新增）。清單留空＝不限制，維持既有站台升級後的行為不變。
 *
 * 這是「業務層面的購買限制」，不是商品類型註冊本身（見 init.php 商品類型三支
 * filter 旁邊「不綁模組開關」的既有說明）——掛在 wallet 模組開關下，跟同一個
 * if 區塊裡「加入購物車」按鈕的既有掛法一致。
 */
function twshop_restrict_wallet_credit_payment_gateways( $available_gateways ) {
    if ( ! twshop_cart_has_wallet_credit_product() ) return $available_gateways;
    $allowed = get_option( 'wc_wallet_topup_allowed_gateways', array() );
    if ( empty( $allowed ) ) return $available_gateways;
    return array_intersect_key( $available_gateways, array_flip( $allowed ) );
}

function twshop_ajax_apply_wallet() {
    check_ajax_referer( 'twshop_frontend_action', 'twshop_nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => '請先登入才能使用' . twshop_wallet_term() . '折抵。' ) );
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
    if ( ! twshop_can_use_wallet() ) {
        // 使用條件（最低消費/分類限制/購物車含儲值金商品等）在這次計算時剛好不成立——
        // 必須連 session 一起清掉，否則畫面上這筆折抵「消失」了，但
        // twshop_store_wallet_applied_on_order() 若沒有同步這道判斷，還是會讀到這個
        // 殘留值寫進訂單、之後被 twshop_deduct_wallet_on_checkout() 照樣扣款（v25.8.79
        // 修正：實測發現訂單總額沒有折抵、餘額卻被扣的落差，見 CLAUDE.md）。
        if ( WC()->session ) WC()->session->__unset( 'twshop_wallet_applied' );
        return;
    }

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

    $cart->add_fee( twshop_wallet_fee_name(), -$amount, false );
}

/**
 * 建立訂單當下記下這張訂單實際套用的折抵金額（此時 fee 已依餘額/上限算完，session 值即實際值）。
 * 付款失敗後重新送出結帳時 WooCommerce 會重用同一張訂單，這個 hook 每次都會重新寫入。
 *
 * **不能只信任 session**（v25.8.79 修正）：twshop_apply_wallet_discount_fee() 雖然
 * 已經在 twshop_can_use_wallet() 不通過時清掉 session，但這兩個 hook 是不同時機各自
 * 觸發——理論上仍可能出現「fee 沒加、session 卻還沒被清到」的極短暫窗口（例如這個
 * hook 在同一次結帳流程裡搶先於某次 calculate_totals() 之前跑）。這裡直接再檢查一次
 * twshop_can_use_wallet()，不通過就強制寫入 0，是寫進真實會扣款依據前的最後一道防線，
 * 跟上面 fee filter 那道防線各自獨立、互不取代。
 */
function twshop_store_wallet_applied_on_order( $order ) {
    $amount = WC()->session ? (float) WC()->session->get( 'twshop_wallet_applied', 0 ) : 0;
    if ( ! twshop_can_use_wallet() ) $amount = 0;
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
        $errors->add( 'validation', twshop_wallet_term() . '餘額不足以完成本次折抵，請重新確認購物車。' );
    }
}

/**
 * 結帳送出時的權威防線：購物車含儲值金商品、且顧客未登入時擋下結帳（v25.8.79 新增，
 * 配合 twshop_restrict_wallet_credit_purchase_for_guest()（wallet-topup.php，
 * woocommerce_is_purchasable 這一層 UX 防線）——那道防線擋不住「商品已經在購物車裡
 * 才登出／從未登入就直接送出結帳」這種 WC_Cart::check_cart_item_validity() 不會
 * 重新檢查 is_purchasable() 的路徑，這裡才是真正能阻止訂單建立的地方。
 *
 * **必須排除「這次結帳順便建立帳號」的情況**：已讀 WooCommerce 核心
 * WC_Checkout::process_checkout() 確認執行順序是 validate_checkout()（觸發這個
 * hook）→ process_customer()（真正建立訪客帳號的地方）→ create_order()——這個 hook
 * 觸發當下，即使顧客這次結帳有勾選「建立帳號」或站台本身強制要求註冊，
 * is_user_logged_in() 依然回傳 false（帳號要到下一步才會建立），不排除這兩種情況
 * 會把「正在註冊帳號順便買儲值金商品」這個正常流程誤判成訪客擋下來。
 */
function twshop_validate_wallet_credit_guest_checkout( $data, $errors ) {
    if ( is_user_logged_in() ) return;
    if ( ! empty( $data['createaccount'] ) || WC()->checkout()->is_registration_required() ) return;
    if ( ! twshop_cart_has_wallet_credit_product() ) return;

    $errors->add( 'validation', '購買' . twshop_wallet_term() . '商品需要先登入會員，請登入後再結帳。' );
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
 * 把這張訂單「目前實際扣掉的儲值金」結算到 $target（結帳扣款、取消、部分退款共用的唯一寫入路徑）。
 *
 * 差額在 twshop_wallet_apply() 拿到會員餘額列鎖之後才計算（settle_target），並發的結帳／退款
 * 請求會依序看到彼此的結果，不會各自用舊狀態算出重疊的差額。ref 帶「這張訂單目前的帳本筆數」：
 * 同一狀態下重複觸發（付款重試、兩個分頁同時送出）產生相同 ref、只執行一次；狀態一改變 ref 就不同。
 * v25.8.105 前 ref 只帶目標金額，重新結帳時折抵 300 → 100 → 300，第三次會撞到第一次的 ref 被當成
 * 「已扣過」而跳過，訂單折抵 300 實際只扣 100。
 *
 * @param bool $allow_charge false 時只會退回、不會向會員扣款（退款情境用）
 * @return array|WP_Error|null
 */
function twshop_wallet_settle_order( $order, $target, $note, $allow_charge = true ) {
    global $wpdb;
    $user_id = $order->get_customer_id();
    if ( ! $user_id ) return null;

    $order_id = $order->get_id();
    $target   = max( 0, round( (float) $target, 2 ) );
    $rows     = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM " . twshop_wallet_ledger_table() . " WHERE order_id = %d AND type IN ('spend','spend_return')",
        $order_id
    ) );
    if ( 0 === $rows && $target <= 0 ) return null;

    $ref = 'wallet_settle:' . $order_id . ':' . $rows . ':' . number_format( $target, 2, '.', '' );
    return twshop_wallet_apply( $user_id, 0, 'spend', $ref, array(
        'order_id'         => $order_id,
        'note'             => $note,
        'settle_target'    => $target,
        'settle_no_charge' => ! $allow_charge,
    ) );
}

/**
 * 結帳扣款（woocommerce_checkout_order_processed，付款失敗重送會再觸發）：結算到訂單目前的
 * 套用金額。扣款失敗（餘額被另一張訂單先花掉）時丟出例外，WooCommerce 的 process_checkout()
 * 會接住並擋下結帳，不會讓訂單帶著沒扣到的折抵走完流程。
 */
function twshop_deduct_wallet_on_checkout( $order_id, $posted_data, $order ) {
    $result = twshop_wallet_settle_order(
        $order,
        (float) $order->get_meta( '_twshop_wallet_applied' ),
        '訂單 #' . $order_id . ' 儲值金折抵'
    );
    if ( is_wp_error( $result ) ) {
        throw new Exception( twshop_wallet_term() . '扣款失敗：' . $result->get_error_message() );
    }
}

/**
 * 訂單取消/已退款/付款失敗，以及訂單頁「手動退回儲值金」：全部退回。
 */
function twshop_refund_wallet_on_order_cancel( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    twshop_wallet_settle_order( $order, 0, '訂單 #' . $order_id . ' 取消/退款，儲值金折抵退還', false );
}

/**
 * 部分退款：依「累計退款金額 / 訂單總額」比例，把實際扣款結算到「套用金額 ×（1 − 比例）」。
 * 只會退、不會扣（例如已整單退回後又有一筆部分退款事件）。訂單總額 0（全額儲值金付款）時比例
 * 算不出來，交給訂單頁的「手動退回儲值金」按鈕處理。
 */
function twshop_handle_order_refund_wallet( $order_id, $refund_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $order_total = (float) $order->get_total();
    $applied     = (float) $order->get_meta( '_twshop_wallet_applied' );
    if ( $order_total <= 0 || $applied <= 0 ) return;

    $proportion = min( 1, abs( (float) $order->get_total_refunded() ) / $order_total );
    twshop_wallet_settle_order(
        $order, $applied * ( 1 - $proportion ),
        '訂單 #' . $order_id . ' 部分退款（累計 ' . round( $proportion * 100 ) . '%），儲值金折抵退還',
        false
    );
}

/**
 * 購物車頁「儲值金折抵」區塊。效仿點數的 twshop_render_points_redemption_ui()（v25.8.76）：
 * 外層 wrapper 固定輸出（即使未登入/沒有折抵也一樣），AJAX 局部刷新需要固定錨點可以
 * replaceWith()，見 twshop_ajax_refresh_components() 與 twshop-frontend.js。餘額不足/
 * 未達最低消費/分類限制時不再整個隱藏區塊，改顯示 twshop_wallet_block_reason() 給的
 * 原因文字；所有文字改走 twshop_option() 讀取，可在「儲值中心 ▸ 設定」自訂。
 */
function twshop_render_wallet_redemption_ui() {
    echo '<div class="twshop-wallet-redemption-wrapper">';

    if ( ! is_user_logged_in() ) {
        echo '</div>';
        return;
    }

    $reason = twshop_wallet_block_reason();

    if ( $reason === false ) {
        echo '</div>';
        return;
    }

    $user_id = get_current_user_id();
    $balance = twshop_wallet_get_balance( $user_id );
    $applied = WC()->session ? (float) WC()->session->get( 'twshop_wallet_applied', 0 ) : 0;
    ?>
    <div class="twshop-wallet-redemption">
        <h4><?php echo esc_html( twshop_wallet_text( 'ui_heading' ) ); ?></h4>
        <?php if ( $balance > 0 ) : ?>
            <p><?php echo esc_html( str_replace( '{amount}', twshop_plain_price( $balance ), twshop_wallet_text( 'balance_text' ) ) ); ?></p>
        <?php endif; ?>
        <?php if ( $reason !== null ) : ?>
            <p class="twshop-wallet-notice"><?php echo esc_html( $reason ); ?></p>
        <?php else : ?>
            <div class="twshop-wallet-input-row">
                <input type="number" inputmode="decimal" id="twshop_wallet_input" min="0" step="1"
                       max="<?php echo esc_attr( $balance ); ?>"
                       placeholder="<?php echo esc_attr( twshop_wallet_text( 'input_placeholder' ) ); ?>"
                       value="<?php echo esc_attr( $applied ?: '' ); ?>">
                <button type="button" class="button" id="twshop_apply_wallet_btn"><?php echo $applied ? esc_html( twshop_wallet_text( 'btn_update_text' ) ) : esc_html( twshop_wallet_text( 'btn_apply_text' ) ); ?></button>
            </div>
            <?php if ( $applied > 0 ) :
                $actual_applied = twshop_get_wallet_discount_amount( $applied );
            ?>
                <p class="twshop-wallet-notice" style="color:#2271b1; margin-top:6px;"><?php echo esc_html( str_replace( '{amount}', twshop_plain_price( $actual_applied ), twshop_wallet_text( 'applied_text' ) ) ); ?></p>
            <?php endif; ?>
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
    <?php else : ?>
        <p style="color:#166534;">已全部退回。</p>
    <?php endif; ?>
    <?php
    // metabox 在頁面主體才渲染，腳本靠頁尾的 admin_print_footer_scripts 印出（同物流 metabox 的做法）。
    twshop_enqueue_asset_script( 'admin/order-wallet', array(
        'twshopOrderWallet' => array( 'nonce' => wp_create_nonce( 'twshop_admin_action' ) ),
    ) );
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
