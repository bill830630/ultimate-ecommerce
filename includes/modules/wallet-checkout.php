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
 * twshop_wallet_block_reason() 只是把同一組判斷條件轉成給顧客看的文字，兩者判斷邏輯
 * 必須一致，改其中一個要記得同步改另一個。
 */
function twshop_can_use_wallet() {
    if ( ! WC()->cart || WC()->cart->is_empty() ) return false;

    // 儲值金不能拿來購買儲值金商品，見 twshop_cart_has_wallet_credit_product() 的說明。
    if ( twshop_cart_has_wallet_credit_product() ) return false;

    $min_amount = (float) get_option( 'wc_wallet_min_cart_amount', 0 );
    if ( $min_amount > 0 && ( WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax() ) < $min_amount ) {
        return false;
    }

    list( $restrict_type, $restrict_values ) = twshop_get_typed_restriction(
        'wc_wallet_restrict_type', 'wc_wallet_restrict_values'
    );
    if ( empty( $restrict_type ) || empty( $restrict_values ) ) return true;
    $taxonomy = $restrict_type === 'tag' ? 'product_tag' : 'product_cat';

    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( has_term( $restrict_values, $taxonomy, $cart_item['product_id'] ) ) return true;
    }
    return false;
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
        return twshop_option( 'wc_wallet_topup_restricted_text' );
    }

    $user_id = get_current_user_id();
    $applied = WC()->session ? (float) WC()->session->get( 'twshop_wallet_applied', 0 ) : 0;
    $balance = $user_id ? twshop_wallet_get_balance( $user_id ) : 0.0;

    if ( $balance <= 0 && $applied <= 0 ) {
        return twshop_option( 'wc_wallet_no_balance_text' );
    }

    $min_amount = (float) get_option( 'wc_wallet_min_cart_amount', 0 );
    if ( $min_amount > 0 && ( WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax() ) < $min_amount ) {
        return str_replace(
            '{amount}',
            strip_tags( wc_price( $min_amount ) ),
            twshop_option( 'wc_wallet_min_cart_text' )
        );
    }

    list( $restrict_type, $restrict_values ) = twshop_get_typed_restriction(
        'wc_wallet_restrict_type', 'wc_wallet_restrict_values'
    );
    if ( ! empty( $restrict_type ) && ! empty( $restrict_values ) ) {
        $taxonomy = $restrict_type === 'tag' ? 'product_tag' : 'product_cat';
        $can_use = false;
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( has_term( $restrict_values, $taxonomy, $cart_item['product_id'] ) ) {
                $can_use = true;
                break;
            }
        }
        if ( ! $can_use ) {
            $term_names = array();
            foreach ( $restrict_values as $term_id ) {
                $term = get_term( $term_id, $taxonomy );
                if ( $term && ! is_wp_error( $term ) ) $term_names[] = $term->name;
            }
            return str_replace(
                '{names}',
                esc_html( implode( '、', $term_names ) ),
                twshop_option( 'wc_wallet_restricted_text' )
            );
        }
    }

    return null;
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

    $cart->add_fee( '儲值金折抵', -$amount, false );
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
        $errors->add( 'validation', '儲值金餘額不足以完成本次折抵，請重新確認購物車。' );
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

    $errors->add( 'validation', '購買儲值金商品需要先登入會員，請登入後再結帳。' );
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
 *
 * **必須檢查 twshop_wallet_apply() 的回傳值**（v25.8.79 修正）：這支函式是唯一真正
 * 執行扣款的地方，`ref` 的鎖只能擋住「同一張訂單重複扣兩次」，擋不住「同一位會員
 * 的兩張不同訂單幾乎同時各自結帳」——例如顧客開兩個分頁，餘額 $300，兩邊都套用
 * $300 折抵後幾乎同時送出：第一筆成功扣款、餘額歸零；第二筆這裡重算出的目標差額
 * 會讓 twshop_wallet_apply() 因為透支而回傳 WP_Error。改版前這裡完全沒檢查回傳值，
 * 第二張訂單仍會正常建立、帶著已經算好的折扣走完結帳，商店白白虧掉這筆折扣、帳本
 * 卻只有一筆扣款紀錄。這個 hook（woocommerce_checkout_order_processed）是包在
 * WC_Checkout::process_checkout() 的 try{}catch(Exception $e) 裡的，從這裡
 * throw new Exception() 會被核心接住、顯示錯誤通知給顧客、不會繼續走到付款流程，
 * 訂單停在未付款狀態，是唯一能在扣款失敗當下真正擋住結帳的位置。
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
        $result = twshop_wallet_apply( $user_id, -$delta, 'spend', $ref, array(
            'order_id' => $order_id,
            'note'     => '訂單 #' . $order_id . ' 儲值金折抵',
        ) );
        if ( is_wp_error( $result ) ) {
            throw new Exception( '儲值金扣款失敗：' . $result->get_error_message() );
        }
    } else {
        // 重新結帳時折抵金額比之前少（例如餘額被另一個分頁花掉、套用金額被自動夾小），
        // 退回差額。
        $result = twshop_wallet_apply( $user_id, -$delta, 'spend_return', $ref, array(
            'order_id' => $order_id,
            'note'     => '訂單 #' . $order_id . ' 重新結帳，儲值金差額退還',
        ) );
        if ( is_wp_error( $result ) ) {
            throw new Exception( '儲值金差額退還失敗：' . $result->get_error_message() );
        }
    }
}

/**
 * 把這張訂單的儲值金退款「settle 到目標金額」，是 twshop_refund_wallet_on_order_cancel()
 * （整單）與 twshop_handle_order_refund_wallet()（部分退款）共用的唯一寫入路徑
 * （v25.8.79 新增，取代原本兩支函式各自算差額、各用不同 ref 命名空間的設計）。
 *
 * **改版前的問題**：兩支函式分別用 `wallet_cancel_return:{order_id}` 與
 * `wallet_partial_return:{refund_id}` 兩種不同的 ref，且都是先用未加鎖的 SUM() 查詢
 * 算出「應退多少」才把固定差額丟進 twshop_wallet_apply()。wallet_apply() 的鎖只保證
 * 「同一個 ref 不會被重複套用」，兩個不同 ref 之間完全無法互相 dedupe——如果訂單狀態
 * 轉換與退款事件幾乎同時觸發（例如後台按一次「全額退款」，WooCommerce 在同一次請求
 * 裡先後觸發 woocommerce_order_refunded 與訂單狀態轉 refunded 兩個 hook），兩條路徑
 * 可能各自讀到「已退＝0」的舊狀態、各退一次，疊加超額退款。
 *
 * **修法**：兩支呼叫端改成都算出同一種「目標應退總額」（整單退款＝已扣總額；部分
 * 退款＝已扣總額 × 累計退款比例），統一用 `wallet_return:{order_id}:{target}` 這個
 * 格式的 ref。訂單最終變成 100% 退款時，兩邊各自算出的目標值會收斂成同一個數字
 * （$spent），產生完全相同的 ref，twshop_wallet_apply() 的列鎖 + ref 唯一性就能正確
 * 擋住重複退款——跟 twshop_deduct_wallet_on_checkout()「目標值 vs 已處理，ref 內含
 * 目標金額」的既有模式是同一套邏輯。
 */
function twshop_wallet_settle_order_return( $order, $target_returned, $note ) {
    $order_id = $order->get_id();
    $user_id  = $order->get_customer_id();
    if ( ! $user_id ) return;

    $spent   = twshop_wallet_get_order_spent( $order_id );
    // 不會退超過當初扣掉的金額（$target_returned 理論上不該超過 $spent，這裡再夾一次保險）。
    $target  = min( round( $target_returned, 2 ), $spent );
    if ( $target <= 0 ) return;
    $already = twshop_wallet_get_order_returned( $order_id );

    $delta = round( $target - $already, 2 );
    if ( $delta <= 0 ) return;

    $ref = 'wallet_return:' . $order_id . ':' . number_format( $target, 2, '.', '' );
    twshop_wallet_apply( $user_id, $delta, 'spend_return', $ref, array(
        'order_id' => $order_id,
        'note'     => $note,
    ) );
}

/**
 * 訂單取消/已退款/付款失敗：把這張訂單目前「還沒退回」的餘額全部退回。目標值固定是
 * 「這張訂單目前帳本裡淨扣掉多少」，交給 twshop_wallet_settle_order_return() 統一
 * 處理冪等——先前若已經透過 twshop_handle_order_refund_wallet()（部分退款）退回
 * 一部分，這裡只會退剩下的部分，不會重複。
 */
function twshop_refund_wallet_on_order_cancel( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $spent = twshop_wallet_get_order_spent( $order_id );
    twshop_wallet_settle_order_return( $order, $spent, '訂單 #' . $order_id . ' 取消/退款，儲值金折抵退還' );
}

/**
 * WooCommerce 部分退款（後台訂單頁按「退款」但不一定改變訂單狀態）時，依「訂單累計已退款
 * 金額 / 訂單原始總額」的比例算出目標應退儲值金，交給 twshop_wallet_settle_order_return()
 * 統一處理。**改用 $order->get_total_refunded()（WooCommerce 對這張訂單累計所有退款事件
 * 的官方金額）取代原本只看單一 $refund->get_total()**——後者只反映「這一筆」退款，多筆
 * 部分退款疊加時無法正確算出「目前總共該退多少」，且跟 twshop_refund_wallet_on_order_cancel()
 * 各算各的、用不同 ref 的舊設計正是 v25.8.79 要修掉的競態來源（見上方函式說明）。
 */
function twshop_handle_order_refund_wallet( $order_id, $refund_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $order_total = (float) $order->get_total();
    // 全額用儲值金付款、訂單金額 0 的單，$order_total 是 0，比例算不出來——這種單交給
    // 訂單編輯頁的「手動退回儲值金」按鈕處理（見下方 metabox），這裡直接不處理避免除以 0。
    if ( $order_total <= 0 ) return;

    $spent_total = twshop_wallet_get_order_spent( $order_id );
    if ( $spent_total <= 0 ) return;

    $total_refunded = abs( (float) $order->get_total_refunded() );
    $proportion      = min( 1, $total_refunded / $order_total );
    $target_return   = round( $spent_total * $proportion, 2 );

    twshop_wallet_settle_order_return(
        $order, $target_return,
        '訂單 #' . $order_id . ' 部分退款（累計 ' . round( $proportion * 100 ) . '%），儲值金折抵退還'
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
        <h4><?php echo esc_html( twshop_option( 'wc_wallet_ui_heading' ) ); ?></h4>
        <?php if ( $balance > 0 ) : ?>
            <p><?php echo esc_html( str_replace( '{amount}', number_format( $balance, 2 ), twshop_option( 'wc_wallet_balance_text' ) ) ); ?></p>
        <?php endif; ?>
        <?php if ( $reason !== null ) : ?>
            <p class="twshop-wallet-notice"><?php echo esc_html( $reason ); ?></p>
        <?php else : ?>
            <div class="twshop-wallet-input-row">
                <input type="number" inputmode="decimal" id="twshop_wallet_input" min="0" step="1"
                       max="<?php echo esc_attr( $balance ); ?>"
                       placeholder="<?php echo esc_attr( twshop_option( 'wc_wallet_input_placeholder' ) ); ?>"
                       value="<?php echo esc_attr( $applied ?: '' ); ?>">
                <button type="button" class="button" id="twshop_apply_wallet_btn"><?php echo $applied ? esc_html( twshop_option( 'wc_wallet_btn_update_text' ) ) : esc_html( twshop_option( 'wc_wallet_btn_apply_text' ) ); ?></button>
            </div>
            <?php if ( $applied > 0 ) :
                $actual_applied = twshop_get_wallet_discount_amount( $applied );
            ?>
                <p class="twshop-wallet-notice" style="color:#2271b1; margin-top:6px;"><?php echo esc_html( str_replace( '{amount}', number_format( $actual_applied, 2 ), twshop_option( 'wc_wallet_applied_text' ) ) ); ?></p>
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
