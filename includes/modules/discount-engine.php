<?php
/**
 * 6. 折扣規則引擎 (AND/OR、滿額贈與加購品防呆)
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 6. 折扣規則引擎 (AND/OR、滿額贈與加購品防呆)
// =========================================================================

/**
 * 全外掛呼叫頻率最高的函式（price / is_on_sale / fees / shipping / progress 全部經過）。
 * 加 per-request static cache 包一層外殼，實際判斷邏輯在 twshop_is_discount_rule_valid_compute()。
 *
 * 快取 key：product_id/user_id/user_roles/cart_total 皆直接影響回傳值，一併納入。
 */
function twshop_is_discount_rule_valid( $rule, $user_roles, $cart_total = 0, $product_id = 0 ) {
    static $cache = array();
    // (array) 轉型：改版前 $user_roles 只在規則有 role 限制時才會被讀取，傳入非陣列時多半靜默無事；
    // 現在快取 key 無條件 implode() 它，非陣列會直接 TypeError。這支函式掛在 woocommerce_product_get_price
    // 等價格 filter 上，一次 fatal 就是整個商店頁白畫面，代價遠高於一次轉型，故保留這道防呆。
    // （目前 16 個呼叫端傳的都是 WP_User::$roles 或 array('customer')，必為陣列；這純粹是保險。）
    $user_roles = (array) $user_roles;
    $cache_key = ( $rule['rule_id'] ?? '' ) . '|' . $product_id . '|' . get_current_user_id() . '|' . implode( ',', $user_roles ) . '|' . $cart_total;
    // 購物車層判斷（$product_id = 0）且規則有條件時，結果取決於購物車內容，同一請求內內容可能變動。
    if ( 0 === (int) $product_id ) {
        list( $cond_type, $cond_values ) = twshop_get_rule_condition( $rule );
        if ( ! empty( $cond_type ) && ! empty( $cond_values ) ) {
            $cache_key .= '|' . twshop_cart_condition_fingerprint();
        }
    }
    if ( array_key_exists( $cache_key, $cache ) ) return $cache[ $cache_key ];

    $result = twshop_is_discount_rule_valid_compute( $rule, $user_roles, $cart_total, $product_id );
    $cache[ $cache_key ] = $result;
    return $result;
}

/**
 * 商品在指定分類法下的 term 清單，per-request static cache（key: product_id|taxonomy）。
 * 內部作法與 WordPress has_term()/is_object_in_term() 讀取 term 清單的路徑完全一致
 * （先查 get_object_term_cache()，沒有才 wp_get_object_terms()），只是額外用自己的 static
 * 陣列記住結果，讓「同一商品 × 多條規則」不用每條規則都重新查一次。
 */
function twshop_get_product_terms_cached( $product_id, $taxonomy ) {
    static $cache = array();
    $key = $product_id . '|' . $taxonomy;
    if ( array_key_exists( $key, $cache ) ) return $cache[ $key ];

    $object_terms = get_object_term_cache( $product_id, $taxonomy );
    if ( false === $object_terms ) {
        $object_terms = wp_get_object_terms( $product_id, $taxonomy, array( 'update_term_meta_cache' => false ) );
        if ( is_wp_error( $object_terms ) ) $object_terms = array();
    }

    $cache[ $key ] = $object_terms;
    return $object_terms;
}

/**
 * has_term() 的等效替代，比對邏輯逐行照抄 WordPress is_object_in_term() 在取得 term 清單之後的
 * 比對演算法（term_id 整數比對／數字字串比對 term_id／字串比對 slug 或 name），確保輸出與原生
 * has_term() 完全一致，差別只在 term 清單改用上面的 twshop_get_product_terms_cached() 取得。
 */
function twshop_has_term_cached( $terms, $taxonomy, $product_id ) {
    $object_terms = twshop_get_product_terms_cached( $product_id, $taxonomy );
    if ( empty( $object_terms ) || is_wp_error( $object_terms ) ) return false;

    $terms = (array) $terms;
    if ( empty( $terms ) ) return true;

    $ints = array_filter( $terms, 'is_int' );
    $strs = $ints ? array_diff( $terms, $ints ) : $terms;

    foreach ( $object_terms as $object_term ) {
        if ( $ints && in_array( $object_term->term_id, $ints, true ) ) return true;
        if ( $strs ) {
            $numeric_strs = array_map( 'intval', array_filter( $strs, 'is_numeric' ) );
            if ( in_array( $object_term->term_id, $numeric_strs, true ) ) return true;
            if ( in_array( $object_term->name, $strs, true ) ) return true;
            if ( in_array( $object_term->slug, $strs, true ) ) return true;
        }
    }
    return false;
}

function twshop_rule_condition_matches_product( $cond_type, $cond_values, $product_id ) {
    if ( $cond_type === 'product' ) {
        return in_array( (int) $product_id, array_map( 'intval', (array) $cond_values ), true );
    } elseif ( $cond_type === 'category' ) {
        return twshop_has_term_cached( $cond_values, 'product_cat', $product_id );
    } elseif ( $cond_type === 'tag' ) {
        return twshop_has_term_cached( $cond_values, 'product_tag', $product_id );
    }
    return false;
}

/**
 * twshop 自己加進購物車的特殊項目（贈品、買N送N 免費項目、點數兌換商品、加購項目）。
 * 這些項目不算「顧客購買的商品」，不能拿來滿足規則條件（否則贈品可以自己撐住自己的條件）。
 */
function twshop_is_twshop_special_cart_item( $cart_item ) {
    return isset( $cart_item['twshop_gift_rule_id'] )
        || isset( $cart_item['twshop_bxgy_rule_id'] )
        || isset( $cart_item['twshop_points_redeem_product_id'] )
        || isset( $cart_item['twshop_addon_rule_id'] );
}

function twshop_cart_condition_fingerprint() {
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) return 'nocart';
    $ids = array();
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( twshop_is_twshop_special_cart_item( $cart_item ) ) continue;
        $ids[] = (int) $cart_item['product_id'];
    }
    sort( $ids );
    return md5( implode( ',', array_unique( $ids ) ) );
}

function twshop_is_discount_rule_valid_compute( $rule, $user_roles, $cart_total = 0, $product_id = 0 ) {
    // 唯一判斷入口：所有型別的計算函式都經過這支函式判斷有效性，缺欄位（舊規則）一律視為啟用。
    if ( ( $rule['enabled'] ?? 'yes' ) === 'no' ) return false;

    $now = current_time('timestamp');

    if ( !empty($rule['start_time']) && $now < strtotime($rule['start_time']) ) return false;
    if ( !empty($rule['end_time']) && $now > strtotime($rule['end_time']) ) return false;
    if ( $rule['role'] !== 'all' && ! in_array( $rule['role'], $user_roles ) ) return false;
    
    // 已移除的「優惠卡券」規則一律不生效（twshop_migrate_removed_rule_coupons() 會把它們停用；這裡是保險）
    if ( 'yes' === ( $rule['is_coupon'] ?? 'no' ) ) return false;

    $t_limit = intval($rule['usage_limit'] ?? 0);
    $u_limit = intval($rule['user_limit'] ?? 0);
    if ($t_limit > 0 && twshop_get_rule_usage_total( $rule['rule_id'] ) >= $t_limit) return false;
    if ($u_limit > 0 && is_user_logged_in() && intval(get_user_meta(get_current_user_id(), 'twshop_rule_usage_' . $rule['rule_id'], true)) >= $u_limit) return false;

    $logic = $rule['logic'] ?? 'and';
    $has_min = !empty($rule['min_amount']) && $rule['min_amount'] > 0;

    // 限制條件：condition_type (product/category/tag) + condition_values (可複選)。
    // 商品層（$product_id > 0）比對該商品；購物車層（$product_id = 0：免運/贈品/加購/整單折扣）
    // 改為「購物車內任一件一般商品符合即成立」——修正前購物車層一律略過條件，等於全站適用（v25.8.34）。
    list( $cond_type, $cond_values ) = twshop_get_rule_condition( $rule );
    $has_cond = ! empty( $cond_type ) && ! empty( $cond_values );

    if ( !$has_min && !$has_cond ) return true;

    $p_min = $has_min ? ($cart_total >= floatval($rule['min_amount'])) : false;
    $p_cond = false;
    if ( $has_cond ) {
        if ( $product_id > 0 ) {
            $p_cond = twshop_rule_condition_matches_product( $cond_type, $cond_values, $product_id );
        } elseif ( function_exists( 'WC' ) && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $cart_item ) {
                if ( twshop_is_twshop_special_cart_item( $cart_item ) ) continue;
                if ( twshop_rule_condition_matches_product( $cond_type, $cond_values, (int) $cart_item['product_id'] ) ) {
                    $p_cond = true;
                    break;
                }
            }
        }
    }

    if ( $logic === 'and' ) {
        if ( $has_min && !$p_min ) return false;
        if ( $has_cond && !$p_cond ) return false;
        return true;
    } else {
        if ( $p_min || $p_cond ) return true;
        return false;
    }
}

/**
 * 買N送N（buy_x_get_y）：判斷購物車項目是否落在規則的限制條件範圍內（商品/分類/標籤，此型別必填）。
 * 排除任何已被其他機制標記為 $0 的項目（贈品/本規則自己上一輪拆出的免費項目），避免自我循環計數。
 */
function twshop_bxgy_item_matches_rule( $cart_item, $rule ) {
    if ( isset( $cart_item['twshop_gift_rule_id'] ) || isset( $cart_item['twshop_bxgy_rule_id'] ) ) return false;
    $product_id = $cart_item['product_id'];
    $cond_type = $rule['condition_type'] ?? '';
    $cond_values = (array) ( $rule['condition_values'] ?? array() );
    if ( empty( $cond_type ) || empty( $cond_values ) ) return false;
    if ( $cond_type === 'product' ) {
        return in_array( $product_id, array_map( 'intval', $cond_values ), true );
    } elseif ( $cond_type === 'category' ) {
        return (bool) has_term( $cond_values, 'product_cat', $product_id );
    } elseif ( $cond_type === 'tag' ) {
        return (bool) has_term( $cond_values, 'product_tag', $product_id );
    }
    return false;
}

/**
 * 贈品/加購門檻用的商品小計（不含贈品、不含稅）。直接用商品目前售價計算，不讀 line_subtotal：
 * woocommerce_before_calculate_totals 觸發時 line_subtotal 還是上一輪的值，新加入的商品是 0，
 * 贈品增減會晚一次計算才反映（v25.8.36 修正）。
 */
function twshop_get_cart_threshold_total( $cart_obj ) {
    $total = 0;
    foreach ( $cart_obj->get_cart() as $cart_item ) {
        if ( isset( $cart_item['twshop_gift_rule_id'] ) || empty( $cart_item['data'] ) ) continue;
        $total += (float) wc_get_price_excluding_tax( $cart_item['data'], array( 'qty' => (int) $cart_item['quantity'] ) );
    }
    return $total;
}

/**
 * 從購物車「加購」區塊加入時（按鈕帶 twshop_addon=<rule_id>），把加購規則 ID 寫進購物車項目，
 * 之後只有這一行會套用加購價並鎖定 1 件（v25.8.36）。規則不存在/不是加購型別/商品對不上/目前不符資格時不標記，
 * 視為一般正價購買。
 */
function twshop_mark_addon_cart_item( $cart_item_data, $product_id, $variation_id = 0 ) {
    if ( empty( $_REQUEST['twshop_addon'] ) ) return $cart_item_data;
    $rule_id = sanitize_text_field( wp_unslash( $_REQUEST['twshop_addon'] ) );
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array( 'customer' );
    foreach ( twshop_get_rules() as $rule ) {
        if ( $rule['rule_id'] !== $rule_id ) continue;
        if ( $rule['type'] === 'addon_product' && (int) $rule['gift_product_id'] === (int) $product_id
            && WC()->cart && twshop_is_discount_rule_valid( $rule, $user_roles, twshop_get_cart_threshold_total( WC()->cart ), 0 ) ) {
            $cart_item_data['twshop_addon_rule_id'] = $rule_id;
        }
        break;
    }
    return $cart_item_data;
}

function twshop_lock_addon_item_quantity( $product_quantity, $cart_item_key, $cart_item ) {
    if ( isset( $cart_item['twshop_addon_rule_id'] ) ) {
        return '<span class="twshop-addon-qty">' . esc_html( $cart_item['quantity'] ) . '</span>';
    }
    return $product_quantity;
}

function twshop_bxgy_index_key( $cart_item ) {
    $variation = (array) ( $cart_item['variation'] ?? array() );
    ksort( $variation );
    return $cart_item['product_id'] . '|' . $cart_item['variation_id'] . '|' . md5( wp_json_encode( $variation ) );
}

// 智能自動贈品與加購處理核心
function twshop_auto_manage_gifts_and_addons( $cart_obj ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    
    // 防呆鎖定，避免重複執行導致無限迴圈
    static $is_processing = false;
    if ( $is_processing ) return;
    $is_processing = true;

    $rules = twshop_get_rules();
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array('customer');

    $cart_total = twshop_get_cart_threshold_total( $cart_obj );

    $gifts_to_add = [];
    $gifts_to_remove = [];

    // 第零階段：收回孤兒贈品——規則已被刪除（不在目前 $rules 內），但購物車裡仍帶著
    // 該規則自動加入的 $0 贈品，foreach($rules) 找不到規則就永遠不會被下面的邏輯迭代到，
    // 贈品會卡在購物車直到顧客手動移除或購物車過期。這裡直接反查一次，跟規則是否還存在無關。
    $valid_gift_rule_ids = wp_list_pluck( $rules, 'rule_id' );
    foreach ( $cart_obj->get_cart() as $cart_item_key => $cart_item ) {
        if ( isset( $cart_item['twshop_gift_rule_id'] ) && ! in_array( $cart_item['twshop_gift_rule_id'], $valid_gift_rule_ids, true ) ) {
            $gifts_to_remove[] = $cart_item_key;
        }
    }

    // 第一階段：判斷哪些贈品該送、哪些該收回
    foreach($rules as $rule) {
        if ( $rule['type'] === 'free_gift' && !empty($rule['gift_product_id']) ) {
            $gift_id = (int)$rule['gift_product_id'];
            $is_valid = twshop_is_discount_rule_valid($rule, $user_roles, $cart_total, 0);

            $found_in_cart = false;
            $cart_item_key_to_remove = '';
            foreach ( $cart_obj->get_cart() as $cart_item_key => $cart_item ) {
                if ( isset($cart_item['twshop_gift_rule_id']) && $cart_item['twshop_gift_rule_id'] === $rule['rule_id'] ) {
                    $found_in_cart = true;
                    $cart_item_key_to_remove = $cart_item_key;
                    break;
                }
            }

            if ($is_valid && !$found_in_cart) {
                $gifts_to_add[] = ['id' => $gift_id, 'rule_id' => $rule['rule_id']];
            } elseif (!$is_valid && $found_in_cart) {
                $gifts_to_remove[] = $cart_item_key_to_remove;
            }
        }
    }

    // 執行新增與移除 (此動作可能會再次觸發 calculate_totals，因為被我們鎖住了所以安全)
    if ( !empty($gifts_to_add) || !empty($gifts_to_remove) ) {
        foreach($gifts_to_remove as $key) { WC()->cart->remove_cart_item($key); }
        // 贈品本身被 twshop_restrict_purchase_for_redeem_and_gift_products()
        // （includes/helpers.php）設成不可直接購買，這裡是唯一允許自動把它加入購物車的
        // 合法管道，用 bypass 旗標跳過那道限制，否則 add_to_cart() 會自己擋自己。
        foreach($gifts_to_add as $gift) {
            twshop_bypass_purchase_restriction( true );
            try {
                WC()->cart->add_to_cart($gift['id'], 1, 0, array(), array('twshop_gift_rule_id' => $gift['rule_id']));
            } finally {
                twshop_bypass_purchase_restriction( false );
            }
        }
    }

    // 第二階段：買N送N（buy_x_get_y）——重用同一套「add_to_cart + cart_item_data 標記 + set_price(0)」
    // 機制，差別是免費的是顧客自己已經在買的商品（挑最便宜的 free_qty 個單位拆成獨立一行），
    // 不是額外指定商品。門檻不可重複觸發：一律先還原上一輪的拆分，再依當下數量重新判斷一次。
    foreach ( $rules as $rule ) {
        if ( $rule['type'] !== 'buy_x_get_y' ) continue;

        // 2a：先把上一輪這條規則拆出的免費項目還原——併回同商品/同規格的一般價格項目，
        // 找不到就以原價重新加回購物車，確保接下來的數量計算是從「未拆分」的乾淨基準開始。
        //
        // 合併對象改用索引查表（O(1)）而非每還原一筆就重新掃描整個購物車（原本是
        // O(splits × cart_size)，購物車項目多、同一規則拆出的免費項目也多時會放大）。
        // 索引在還原迴圈開始前建一次、之後隨還原動作同步更新，不重建：兩個分割項目
        // 若剛好合併回同一個商品，第二個要接到第一個剛建立/更新的項目上，用同一份
        // 索引才能保證這一點。
        $item_index = array();
        foreach ( $cart_obj->get_cart() as $idx_key => $idx_item ) {
            if ( twshop_is_twshop_special_cart_item( $idx_item ) ) continue;
            $item_index[ twshop_bxgy_index_key( $idx_item ) ] = $idx_key;
        }

        foreach ( $cart_obj->get_cart() as $split_key => $split_item ) {
            if ( ! isset( $split_item['twshop_bxgy_rule_id'] ) || $split_item['twshop_bxgy_rule_id'] !== $rule['rule_id'] ) continue;
            $restore_qty          = $split_item['quantity'];
            $restore_product_id   = $split_item['product_id'];
            $restore_variation_id = $split_item['variation_id'];
            // 「任意」屬性的規格必須帶回顧客當初選的屬性，否則 add_to_cart() 會失敗或遺失屬性（v25.8.36 修正）。
            $restore_variation    = (array) ( $split_item['variation'] ?? array() );
            $index_key = twshop_bxgy_index_key( $split_item );

            $cart_obj->remove_cart_item( $split_key );

            $sibling_key  = $item_index[ $index_key ] ?? null;
            $sibling_item = $sibling_key ? $cart_obj->get_cart_item( $sibling_key ) : null;
            if ( $sibling_item ) {
                $cart_obj->set_quantity( $sibling_key, $sibling_item['quantity'] + $restore_qty, false );
            } else {
                $new_key = $cart_obj->add_to_cart( $restore_product_id, $restore_qty, $restore_variation_id, $restore_variation );
                if ( $new_key ) $item_index[ $index_key ] = $new_key;
            }
        }

        if ( ! twshop_is_discount_rule_valid( $rule, $user_roles, $cart_total, 0 ) ) continue;

        $buy_qty  = max( 1, (int) ( $rule['buy_qty'] ?? 0 ) );
        $free_qty = max( 1, (int) ( $rule['free_qty'] ?? 0 ) );

        // 2b：加總符合限制條件範圍內的購買數量（已排除贈品/本規則免費分割項目）
        $matching_units = array(); // 每個購買單位一筆：['key' => cart_item_key, 'price' => 目前單價]
        foreach ( $cart_obj->get_cart() as $m_key => $m_item ) {
            if ( ! twshop_bxgy_item_matches_rule( $m_item, $rule ) ) continue;
            // 儲值金商品不能被「買N送N」選中當免費單位（v25.8.79 新增）：規則條件範圍是
            // 用分類/標籤動態決定，沒有固定的目標商品可以在存檔時擋，只能在這裡跑到
            // 才擋——免費單位一樣會被歸零售價，但入帳金額不受影響，等於顧客不花錢就
            // 拿到真錢。
            if ( ! empty( $m_item['data'] ) && twshop_is_wallet_credit_product( $m_item['data'] ) ) continue;
            $unit_price = (float) $m_item['data']->get_price();
            for ( $i = 0; $i < (int) $m_item['quantity']; $i++ ) {
                $matching_units[] = array( 'key' => $m_key, 'price' => $unit_price );
            }
        }

        if ( count( $matching_units ) < $buy_qty ) continue; // 未達標，維持還原後的狀態，不重複觸發

        // 2c：取單價最低的 free_qty 個單位，依所屬購物車項目分組，各自扣除數量並拆出一筆 $0 項目
        usort( $matching_units, function( $a, $b ) { return $a['price'] <=> $b['price']; } );
        $free_qty_by_key = array();
        foreach ( array_slice( $matching_units, 0, $free_qty ) as $unit ) {
            $free_qty_by_key[ $unit['key'] ] = ( $free_qty_by_key[ $unit['key'] ] ?? 0 ) + 1;
        }

        foreach ( $free_qty_by_key as $src_key => $qty_to_split ) {
            $src_item = $cart_obj->get_cart_item( $src_key );
            if ( ! $src_item ) continue;
            $remaining_qty = $src_item['quantity'] - $qty_to_split;
            $product_id    = $src_item['product_id'];
            $variation_id  = $src_item['variation_id'];
            $variation     = $src_item['variation'];
            if ( $remaining_qty > 0 ) {
                $cart_obj->set_quantity( $src_key, $remaining_qty, false );
            } else {
                $cart_obj->remove_cart_item( $src_key );
            }
            $cart_obj->add_to_cart( $product_id, $qty_to_split, $variation_id, $variation, array( 'twshop_bxgy_rule_id' => $rule['rule_id'] ) );
        }
    }

    // 第三階段：將自動帶入的贈品／買N送N 免費項目強制售價改為 $0，並處理加購商品的售價
    foreach ( $cart_obj->get_cart() as $cart_item_key => $cart_item ) {
        if ( isset($cart_item['twshop_gift_rule_id']) || isset($cart_item['twshop_bxgy_rule_id']) ) {
            $cart_item['data']->set_price(0);
            continue;
        }

        // 加購價只給「從加購區塊加入」的那一行（twshop_addon_rule_id），且只限 1 件；
        // 顧客自己用正價買的同一商品不受影響（v25.8.36 修正：原本整個商品所有數量都變加購價）。
        if ( isset( $cart_item['twshop_addon_rule_id'] ) ) {
            $addon_rule = null;
            foreach ( $rules as $rule ) {
                if ( $rule['rule_id'] === $cart_item['twshop_addon_rule_id'] && $rule['type'] === 'addon_product' ) { $addon_rule = $rule; break; }
            }
            if ( $addon_rule && (int) $addon_rule['gift_product_id'] === (int) $cart_item['product_id']
                && twshop_is_discount_rule_valid( $addon_rule, $user_roles, $cart_total, 0 ) ) {
                if ( (int) $cart_item['quantity'] > 1 ) {
                    $cart_obj->set_quantity( $cart_item_key, 1, false );
                }
                $cart_item['data']->set_price( floatval( $addon_rule['value'] ) );
                twshop_fixed_price_products()[ $cart_item['data'] ] = true;
            }
            // 規則失效（刪除、停用、不再達標）時不改價，維持改版前行為：以正價留在購物車。
        }
    }

    $is_processing = false;
}

/**
 * 幫 WC_Product_Variable 的規格價格 transient 快取（wc_var_prices_{id}，預設快取 30 天）加上會
 * 影響 twshop 折扣規則計算結果的因子，避免不同角色的使用者共用到同一份快取、看到不該看到的折扣價格。
 *
 * 刻意不納入購物車小計（$cart_total，會影響 min_amount 門檻類規則）：小計是連續數值，幾乎每個訪客
 * 當下的購物車金額都不一樣，若也納入快取 key，會讓同一個商品在 30 天快取視窗內產生近乎無限多組
 * hash、每一組都各自佔用 wc_var_prices_{id} 這顆 transient 裡的一筆資料，永遠不會自然清掉，有讓單一
 * transient 越長越大的風險。取捨後只涵蓋角色限定的規則（最常見的會員分級折扣），min_amount 門檻類規則
 * 不會反映在「選規格前」的價格區間摘要，但購物車/結帳頁與選定規格後的價格不受影響，仍即時正確計算。
 *
 * 也加了以小時為顆粒度的時間區段，讓有排程起訖時間的規則至少在一小時內會反映到快取（此快取沒有其他
 * 會隨時間自動失效的機制，只靠 WC 商品版本號變動或滿 30 天才會重算，不加時間因子的話，排程規則的
 * 起訖時刻可能要等到有其他商品被儲存、間接刷新版本號才會生效，等待時間不可預期）。
 *
 * v25.8.33 加入規則內容的雜湊值：新增/修改/刪除任何一筆折扣規則都會讓這裡的雜湊值改變，使快取
 * 立即失效，不用再等到整點。跟 $cart_total 那種連續值不同，規則整體內容是離散、低頻異動（管理員
 * 手動操作才會變），不會有 transient 內部資料量無限增長的風險，所以可以直接整包納入 key。
 */
function twshop_add_discount_context_to_variation_price_hash( $price_hash ) {
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array( 'customer' );
    sort( $user_roles );
    $price_hash['twshop_roles'] = $user_roles;
    $price_hash['twshop_hour']  = current_time( 'Y-m-d H' );
    $price_hash['twshop_rules'] = md5( wp_json_encode( twshop_get_rules() ) );
    return $price_hash;
}

function twshop_get_calculated_discount_price( $price, $product, $user_roles ) {
    return twshop_calculate_product_discount( $price, $product, $user_roles )['price'];
}

/**
 * 商品層折扣計算本體：回傳 [ 'price' => 折扣後價格或 false（無規則生效）, 'rule_ids' => 實際生效的規則 ID ]。
 * rule_ids 供結帳時記錄規則使用次數（twshop_collect_applied_rule_ids()），只算真正套用到的規則，
 * 被 stack_exclusive 擋掉的不算。
 */
function twshop_calculate_product_discount( $price, $product, $user_roles ) {
    // 儲值金商品不受一般折扣規則影響（v25.8.79 新增）：入帳金額是商品自己的
    // _twshop_wallet_credit_amount 面額 meta，跟這裡算出來的售價完全無關——放任這支
    // 函式打折會讓顧客用低於面額的真錢買到儲值金（面額不變，只是售價被打折），這是
    // woocommerce_product_get_price／_variation_get_price 與 twshop_product_is_on_sale()
    // 的共同入口，這裡擋掉同時也讓折扣角標/促銷標記不會誤標儲值金商品。
    if ( twshop_is_wallet_credit_product( $product ) ) return array( 'price' => false, 'rule_ids' => array() );

    $rules = twshop_get_rules();
    if ( empty($rules) ) return array( 'price' => false, 'rule_ids' => array() );
    $product_id = $product->get_id();
    $cart_total = WC()->cart ? WC()->cart->get_subtotal() : 0;

    // 限制條件（商品/分類/標籤）一律比對「父商品」ID：規格（variation）本身沒有自己的分類/標籤，
    // 後台選規則限制條件時選的也是父商品，用規格自己的 ID（$product_id）去比對永遠對不上。
    // 折扣計算與快取仍用規格自己的 ID/價格——同一個父商品底下不同規格售價可能不同。
    $condition_product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product_id;

    // 價格 filter（woocommerce_product_get_price）與促銷角標 filter（woocommerce_product_is_on_sale）
    // 常常在同一次請求內各自對同一商品呼叫本函式（商店頁一次商品渲染就可能觸發好幾次：價格、
    // price_html、促銷角標等），兩者傳入的 $price 可能不同（is_on_sale 特意傳「未打折的原價」），
    // 快取 key 納入 $price 才不會把不同輸入誤判成同一結果；購物車小計與角色在同一次請求內視為不變。
    static $cache = array();
    $cache_key = $product_id . '|' . $price . '|' . $cart_total . '|' . implode( ',', $user_roles );
    if ( array_key_exists( $cache_key, $cache ) ) return $cache[ $cache_key ];

    $final_price = floatval($price);
    $applied_ids = array();

    // 疊加群組 A（product 層）：percent + fixed_product 依卡片排序（優先權）逐一套用；
    // 遇到第一筆 stack_exclusive='yes' 的有效規則就只套用它、不再套用同群組其餘規則。
    foreach ( $rules as $rule ) {
        if ( $rule['type'] === 'percent' || $rule['type'] === 'fixed_product' ) {
            if ( twshop_is_discount_rule_valid( $rule, $user_roles, $cart_total, $condition_product_id ) ) {
                $applied_ids[] = $rule['rule_id'];
                if ( $rule['type'] === 'percent' ) $final_price = $final_price * ( floatval($rule['value']) / 100 );
                elseif ( $rule['type'] === 'fixed_product' ) $final_price = $final_price - floatval($rule['value']);
                if ( ( $rule['stack_exclusive'] ?? 'no' ) === 'yes' ) break;
            }
        }
    }

    $result = array(
        'price'    => $applied_ids ? max( 0, $final_price ) : false,
        'rule_ids' => $applied_ids,
    );
    $cache[ $cache_key ] = $result;
    return $result;
}

/**
 * 商品折扣規則該不該套用在目前這個請求。後台頁面不套（商品編輯頁要看到原價），但本外掛自己的前台
 * AJAX（走 admin-ajax.php，is_admin() 為 true）必須套用，否則購物車刷新/套用優惠券時用的是未折扣
 * 小計（v25.8.36 修正）。後台訂單編輯等 WooCommerce 自己的 AJAX 仍不套用。
 */
/**
 * 購物車裡被本外掛直接指定售價（加購價）的商品物件。用 WeakMap 只在記憶體標記，
 * 不寫商品 meta——萬一其他程式對購物車商品物件呼叫 save()，旗標也不會被存進資料庫。
 */
function twshop_fixed_price_products() {
    static $map = null;
    if ( null === $map ) $map = new WeakMap();
    return $map;
}

function twshop_is_frontend_price_context() {
    if ( ! is_admin() ) return true;
    if ( ! wp_doing_ajax() ) return false;
    $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
    return in_array( $action, array(
        'twshop_refresh_components', 'twshop_apply_points', 'twshop_redeem_points_product',
        'twshop_remove_addon', 'apply_visual_coupon', 'remove_visual_coupon',
    ), true );
}

function twshop_apply_product_discount_rules( $price, $product ) {
    if ( $price === '' || ! twshop_is_frontend_price_context() ) return $price;
    // 加購價等由 twshop_auto_manage_gifts_and_addons() 直接指定的價格不再疊加商品層折扣。
    if ( twshop_fixed_price_products()->offsetExists( $product ) ) return $price;
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array('customer');
    $discounted = twshop_get_calculated_discount_price( $price, $product, $user_roles );
    return ($discounted !== false) ? $discounted : $price;
}

function twshop_product_is_on_sale( $is_on_sale, $product ) {
    // 可變商品父層沒有自己的單一原價（get_regular_price() 回傳空字串），下面的算法直接 bail out，
    // 完全不會檢查 twshop 折扣規則；改成跟徽章計算共用同一支「取所有規格中折扣幅度最大者」的函式，
    // 只要有任一規格被規則打折就視為特價中（跟 twshop_get_variable_product_max_discount_percent()
    // 本身依賴的 $variation->get_price() 一樣，都需要 woocommerce_product_variation_get_price 這個
    // 規格專用 filter 有掛上 twshop_apply_product_discount_rules 才會生效）。
    if ( $product->is_type( 'variable' ) ) {
        return twshop_get_variable_product_max_discount_percent( $product ) > 0 ? true : $is_on_sale;
    }

    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array('customer');
    $regular_price = $product->get_regular_price();
    if ( ! $regular_price ) return $is_on_sale;

    $discounted = twshop_get_calculated_discount_price( $regular_price, $product, $user_roles );
    if ( $discounted !== false && $discounted < $regular_price ) return true;
    return $is_on_sale;
}

/**
 * 傳統購物車頁「價格」欄（`templates/cart/cart.php`）的「價格」欄。
 *
 * WooCommerce 核心這一欄一律只印一個數字（`WC_Cart::get_product_price()` 直接
 * `wc_price( wc_get_price_to_display( $product ) )`），**不會**像商品頁 `get_price_html()`
 * 那樣在特價時顯示「原價劃掉＋特價」——這是核心一路以來的既有行為，不是本外掛造成的，
 * 連原生 WooCommerce 特價（`_sale_price`）在傳統購物車頁一樣只看得到單一價格。顧客在購物車頁
 * 完全看不出這件商品其實有打折，只會覺得小計數字對不上商品頁看到的價格，可能誤以為算錯。
 *
 * 只在商品「目前是特價中」（`is_on_sale()`，`twshop_product_is_on_sale()` 已把本外掛折扣規則
 * 與原生特價一併納入判斷，非本外掛造成的特價一樣受惠）才改成「原價劃掉＋特價」格式，套用
 * WooCommerce 商品頁同一套 `wc_format_sale_price()` 組字串；不是特價的商品維持原生單一價格
 * 顯示，不影響任何既有版面。
 */
function twshop_cart_item_price_with_strike( $price_html, $cart_item, $cart_item_key ) {
    $product = $cart_item['data'] ?? null;
    if ( ! $product instanceof WC_Product || ! $product->is_on_sale() ) return $price_html;

    return wc_format_sale_price(
        wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ),
        wc_get_price_to_display( $product )
    ) . $product->get_price_suffix();
}

/**
 * 可變商品的折扣徽章百分比：逐一讀取「可見」規格（get_visible_children，跟 WC 內建
 * get_variation_prices() 篩選範圍一致，排除下架/未發布/庫存狀態不允許購買的規格——
 * 顧客本來就買不到的規格沒必要拿來算折扣，也可能造成庫存售完但仍在商店頁閃現折扣角標的怪異情況）
 * 的實際售價（$variation->get_price()，context 'view'，會套用 woocommerce_product_get_price
 * filter 鏈，因此**含 twshop 折扣規則模組**的折扣，跟簡單商品的計算基準一致），取其中折扣
 * 幅度最大的一個當作徽章要顯示的百分比。
 *
 * 刻意不用 WC_Product_Variable::get_variation_prices()：該函式內部讀規格價格時明確傳入
 * context 'edit'（繞過 woocommerce_product_get_price 顯示 filter，只給原始 meta 值），
 * 只反映 WooCommerce 原生特價（sale_price），讀不到 twshop 折扣規則模組造成的降價。
 *
 * 效能：不像 get_variation_prices() 有 WC 自己的 transient 快取，這裡逐一讀取規格會直接
 * 觸發 get_price() 的完整 filter 鏈。用 static cache（key 為商品 ID）確保同一次請求內
 * （商品彙整頁一次商品渲染常會對同一商品呼叫好幾次 woocommerce_sale_flash / is_on_sale
 * 等 filter）只實際計算一次；成本只發生在「有折扣且已被 WC 判定為特價中」的可變商品身上
 * （不在特價中的可變商品，WooCommerce 連 woocommerce_sale_flash 都不會觸發），且僅限單頁
 * 實際渲染出來的商品數量，不是全站每次請求都掃描。
 */
function twshop_get_variable_product_max_discount_percent( $product ) {
    static $cache = array();
    $product_id = $product->get_id();
    if ( array_key_exists( $product_id, $cache ) ) return $cache[ $product_id ];

    $max_percent = 0;
    foreach ( $product->get_visible_children() as $variation_id ) {
        $variation = wc_get_product( $variation_id );
        if ( ! $variation instanceof WC_Product ) continue;

        $regular_price = $variation->get_regular_price();
        if ( $regular_price === '' || ! is_numeric( $regular_price ) || (float) $regular_price <= 0 ) continue;

        $active_price = (float) $variation->get_price();
        $regular_price = (float) $regular_price;
        if ( $active_price >= $regular_price ) continue;

        $percent = (int) round( ( $regular_price - $active_price ) / $regular_price * 100 );
        if ( $percent > $max_percent ) $max_percent = $percent;
    }

    $cache[ $product_id ] = $max_percent;
    return $max_percent;
}

/**
 * 商品折扣徽章：覆寫 woocommerce_sale_flash 輸出的文字，預設顯示折扣百分比。
 * 可變商品取所有可見規格中折扣幅度最大者（twshop_get_variable_product_max_discount_percent()）。
 *
 * 掛在很晚的優先權（999），$html 收到的已經是主題處理過的最終版本（例如 Blocksy 會組出
 * `<span class="onsale" data-shape="...">`），這裡只用 regex 換掉外層標籤中間的文字，
 * 保留主題自己加的屬性/class 不動，讓徽章外觀完全交給主題既有 CSS 決定。
 */
function twshop_render_discount_badge( $html, $post, $product ) {
    if ( $html === '' || ! $product instanceof WC_Product ) return $html;

    $product_id = $product->get_id();

    // 兩個 get_post_meta() 合併為單次無 key 呼叫（取全部 meta），避免每個商品各查兩次。
    $all_meta   = get_post_meta( $product_id );
    $badge_hide = isset( $all_meta['_twshop_badge_hide'][0] ) ? $all_meta['_twshop_badge_hide'][0] : '';
    $badge_text = isset( $all_meta['_twshop_badge_text'][0] ) ? $all_meta['_twshop_badge_text'][0] : '';

    // 個別商品「隱藏徽章」是最強的覆寫，即使全站徽章開關是開的也優先套用，
    // 直接回傳空字串（連主題原生的角標一併移除），不是回傳 $html（那樣只是不客製文字，主題預設角標仍會顯示）。
    if ( $badge_hide === 'yes' ) return '';

    // 兩個 get_option() 是全站設定值，同一次請求內不會變動，static cache 只讀一次。
    static $site_options = null;
    if ( null === $site_options ) {
        $site_options = array(
            'wc_badge_enabled'      => twshop_option( 'wc_badge_enabled' ),
            'wc_badge_text_template' => get_option( 'wc_badge_text_template', '' ),
        );
    }

    if ( $site_options['wc_badge_enabled'] !== 'yes' ) return $html;

    if ( $product->is_type( 'variable' ) ) {
        $percent = twshop_get_variable_product_max_discount_percent( $product );
    } else {
        $regular_price = $product->get_regular_price();
        if ( $regular_price === '' || ! is_numeric( $regular_price ) || (float) $regular_price <= 0 ) return $html;

        $active_price = (float) $product->get_price();
        $regular_price = (float) $regular_price;
        if ( $active_price >= $regular_price ) return $html;

        $percent = (int) round( ( $regular_price - $active_price ) / $regular_price * 100 );
    }

    if ( $percent <= 0 ) return $html;

    // 文字樣板優先順序：個別商品自訂 > 全站樣板 > 寫死的預設值
    $template = trim( (string) $badge_text );
    if ( $template === '' ) $template = trim( (string) $site_options['wc_badge_text_template'] );
    if ( $template === '' ) $template = '-{percent}%';
    $text = str_replace( '{percent}', $percent, $template );

    if ( preg_match( '/^(<[^>]+>)(.*)(<\/[a-zA-Z0-9]+>)$/s', $html, $m ) ) {
        return $m[1] . esc_html( $text ) . $m[3];
    }

    return '<span class="onsale">' . esc_html( $text ) . '</span>';
}

/**
 * 商品編輯畫面「一般」頁籤新增此商品專屬的徽章文字/隱藏開關。
 * 簡單商品／外部商品／可變商品皆顯示（show_if_simple/show_if_external/show_if_variable，
 * 比照特價欄位在簡單/外部商品的顯示邏輯，額外加上可變商品——可變商品沒有單一售價，
 * 但仍套用同一組文字樣板/隱藏開關，百分比改由 twshop_get_variable_product_max_discount_percent()
 * 取所有規格中折扣幅度最大者）。分組商品（grouped）沒有自己的售價，不顯示。
 */
function twshop_add_badge_product_fields() {
    $default_template = trim( (string) get_option( 'wc_badge_text_template', '' ) );
    if ( $default_template === '' ) $default_template = '-{percent}%';

    echo '<div class="options_group show_if_simple show_if_external show_if_variable twshop-badge-product-fields">';
    woocommerce_wp_text_input( array(
        'id'          => '_twshop_badge_text',
        'label'       => '折扣徽章文字',
        'placeholder' => '留空則使用全站樣板：' . $default_template,
        'desc_tip'    => true,
        'description' => '此商品專屬的折扣徽章文字，可用 {percent} 代表折扣百分比數字，留空則沿用「一般設定」頁的全站樣板。可變商品會取所有規格中折扣幅度最大的百分比。',
    ) );
    woocommerce_wp_checkbox( array(
        'id'          => '_twshop_badge_hide',
        'label'       => '隱藏折扣徽章',
        'description' => '勾選後此商品即使有折扣，也完全不顯示折扣徽章（含主題原生角標）。',
    ) );
    echo '</div>';
}

function twshop_save_badge_product_fields( $post_id ) {
    $text = isset( $_POST['_twshop_badge_text'] ) ? sanitize_text_field( wp_unslash( $_POST['_twshop_badge_text'] ) ) : '';
    update_post_meta( $post_id, '_twshop_badge_text', $text );

    $hide = isset( $_POST['_twshop_badge_hide'] ) ? 'yes' : 'no';
    update_post_meta( $post_id, '_twshop_badge_hide', $hide );
}

/**
 * tiered_cart 規則：依 min_amount 由大到小找第一個購物車小計達標的門檻，回傳該階梯陣列；
 * 沒有任何門檻達標（或規則未設定任何 tiers）回傳 false。
 */
function twshop_get_matching_cart_tier( $rule, $cart_total ) {
    $tiers = is_array( $rule['tiers'] ?? null ) ? $rule['tiers'] : array();
    if ( empty( $tiers ) ) return false;
    usort( $tiers, function( $a, $b ) { return floatval( $b['min_amount'] ?? 0 ) <=> floatval( $a['min_amount'] ?? 0 ); } );
    foreach ( $tiers as $tier ) {
        if ( $cart_total >= floatval( $tier['min_amount'] ?? 0 ) ) return $tier;
    }
    return false;
}

function twshop_apply_cart_discount_rules( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    foreach ( twshop_get_cart_discount_fees( $cart ) as $fee ) {
        $cart->add_fee( $fee['label'], -1 * $fee['amount'], true );
    }
}

/**
 * 購物車層折扣（群組 B）實際會加上的費用清單，每筆 [ 'rule_id', 'label', 'amount'（正數） ]。
 * 加費用（twshop_apply_cart_discount_rules()）與結帳記錄規則使用次數（twshop_collect_applied_rule_ids()）
 * 共用同一份判斷，確保「計次」跟「真的有折」一致。
 */
function twshop_get_cart_discount_fees( $cart ) {
    $rules = twshop_get_rules();
    if ( empty($rules) ) return array();
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array('customer');
    $cart_total = $cart->get_subtotal();
    // 儲值金商品不計入購物車層折扣的小計基準（v25.8.79 新增）：這裡算出來的折扣是
    // 整單負費用，不是改單一商品價格，但結果一樣——含了儲值金商品的小計會讓顧客
    // 總支付金額被拉高、被打折的比例也隨之升高，入帳金額卻完全不受影響，等於一個
    // 完全不相干的全館促銷變相替儲值金商品打折；同一個 $cart_total 也餵進下面
    // min_amount 門檻判斷，順便擋掉「用儲值金商品的價格墊高小計去湊無關促銷門檻」。
    foreach ( $cart->get_cart() as $cart_item ) {
        if ( ! empty( $cart_item['data'] ) && twshop_is_wallet_credit_product( $cart_item['data'] ) ) {
            $cart_total -= (float) $cart_item['line_subtotal'];
        }
    }
    $fees = array();

    // 疊加群組 B（cart 層）：cart_percent + cart_discount + tiered_cart 依卡片排序（優先權）逐一套用；
    // 遇到第一筆 stack_exclusive='yes' 的有效規則就只套用它、不再套用同群組其餘規則（跟群組 A 同一套邏輯）。
    foreach ( $rules as $rule ) {
        if ( $rule['type'] === 'cart_discount' || $rule['type'] === 'cart_percent' ) {
            if ( twshop_is_discount_rule_valid( $rule, $user_roles, $cart_total, 0 ) ) {
                // 「打折 (%)」統一採「打N折＝付N%」慣例，跟商品層 percent 一致（value=90 代表打9折，
                // 折扣後應付原價 90%，即折抵掉 10%）；修法前這裡誤算成 value=90 折抵掉 90%（只收10%），
                // 跟商品層 percent 的算法方向剛好相反（v25.5.67 修正，見 CLAUDE.md）。
                $discount_amount = ($rule['type'] === 'cart_percent') ? ($cart_total * ( 1 - floatval($rule['value']) / 100 )) : abs(floatval($rule['value']));
                $fees[] = array( 'rule_id' => $rule['rule_id'], 'label' => esc_html( $rule['name'] ), 'amount' => $discount_amount );
                if ( ( $rule['stack_exclusive'] ?? 'no' ) === 'yes' ) break;
            }
        } elseif ( $rule['type'] === 'tiered_cart' ) {
            if ( twshop_is_discount_rule_valid( $rule, $user_roles, $cart_total, 0 ) ) {
                $tier = twshop_get_matching_cart_tier( $rule, $cart_total );
                if ( $tier !== false ) {
                    // 同上，'percent' 階梯一律採「打N折＝付N%」慣例。
                    $discount_amount = ( ( $tier['discount_type'] ?? 'fixed' ) === 'percent' )
                        ? ( $cart_total * ( 1 - floatval( $tier['value'] ?? 0 ) / 100 ) )
                        : abs( floatval( $tier['value'] ?? 0 ) );
                    $fee_label = esc_html( $rule['name'] ) . '（滿 ' . wp_strip_all_tags( wc_price( floatval( $tier['min_amount'] ?? 0 ) ) ) . '）';
                    $fees[] = array( 'rule_id' => $rule['rule_id'], 'label' => $fee_label, 'amount' => $discount_amount );
                    if ( ( $rule['stack_exclusive'] ?? 'no' ) === 'yes' ) break;
                }
            }
        }
    }
    return $fees;
}

/**
 * 目前購物車第一條生效的免運規則（沒有則 null）。門檻以折扣後金額判斷：
 * woocommerce_package_rates 觸發時，費用（含折扣負費用）已計算完畢，可直接讀取。
 */
function twshop_get_active_free_shipping_rule() {
    $rules = twshop_get_rules();
    if ( empty( $rules ) || ! WC()->cart ) return null;
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array('customer');

    $subtotal        = WC()->cart->get_subtotal();
    $coupon_discount = WC()->cart->get_discount_total(); // WooCommerce 優惠券折扣（正數）
    $fee_discount    = 0;
    foreach ( WC()->cart->get_fees() as $fee ) {
        // 點數折抵視為付款方式，不計入商品折扣（不影響免運門檻）
        if ( $fee->total < 0 && $fee->name !== twshop_points_term() . '折抵' ) {
            $fee_discount += abs( $fee->total );
        }
    }
    $cart_total = max( 0.0, $subtotal - $coupon_discount - $fee_discount );

    foreach ( $rules as $rule ) {
        if ( $rule['type'] === 'free_shipping' && twshop_is_discount_rule_valid( $rule, $user_roles, $cart_total, 0 ) ) {
            return $rule;
        }
    }
    return null;
}

/**
 * WooCommerce 會把運費結果依 package hash 快取在 session，hash 不含 twshop 規則，免運規則的
 * woocommerce_package_rates filter 只在快取失效時才跑。在 package 裡加上規則內容雜湊＋小時，
 * 後台改規則或排程起訖時間到了，既有購物車的運費才會重新計算（v25.8.36，思路同
 * twshop_add_discount_context_to_variation_price_hash()）。
 */
function twshop_add_rules_context_to_shipping_packages( $packages ) {
    $ctx = md5( wp_json_encode( twshop_get_rules() ) ) . '|' . current_time( 'Y-m-d H' );
    foreach ( $packages as $i => $package ) {
        $packages[ $i ]['twshop_rules_ctx'] = $ctx;
    }
    return $packages;
}

function twshop_free_shipping_rule_methods( $rule ) {
    return is_array( $rule['shipping_methods'] ?? null ) ? $rule['shipping_methods'] : array();
}

function twshop_apply_free_shipping_rules( $rates, $package ) {
    $rule = twshop_get_active_free_shipping_rule();
    if ( ! $rule ) return $rates;
    $free_rule_methods = twshop_free_shipping_rule_methods( $rule );
    foreach ( $rates as $rate_id => $rate ) {
        // 未指定適用運送方式（舊規則、或管理員刻意不勾選任何項目）時，視為全部運送方式皆免運，維持既有行為
        if ( ! empty( $free_rule_methods ) && ! in_array( $rate_id, $free_rule_methods, true ) ) continue;
        $rates[$rate_id]->cost = 0; $rates[$rate_id]->taxes = array();
        $rates[$rate_id]->label = $rate->label . ' (' . $rule['name'] . ')';
    }
    return $rates;
}

/**
 * 結帳建立訂單當下，這張購物車「實際套用到」的折扣規則 ID（供規則使用次數計算）：
 * 商品層折扣、購物車層折扣費用、生效且選用中的免運、購物車裡實際存在的贈品/買N送N/加購項目。
 * 修正前是訂單成立後重算「規則是否有效」，沒套用到的規則也會被計次（v25.8.35）。
 */
function twshop_collect_applied_rule_ids( $cart ) {
    $ids = array();
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array('customer');

    foreach ( $cart->get_cart() as $cart_item ) {
        if ( isset( $cart_item['twshop_gift_rule_id'] ) )  { $ids[] = $cart_item['twshop_gift_rule_id']; continue; }
        if ( isset( $cart_item['twshop_bxgy_rule_id'] ) )  { $ids[] = $cart_item['twshop_bxgy_rule_id']; continue; }
        if ( isset( $cart_item['twshop_addon_rule_id'] ) ) { $ids[] = $cart_item['twshop_addon_rule_id']; continue; }
        if ( isset( $cart_item['twshop_points_redeem_product_id'] ) ) continue;
        $product = $cart_item['data'];
        $base    = $product->get_price( 'edit' );
        if ( '' === $base ) continue;
        $ids = array_merge( $ids, twshop_calculate_product_discount( $base, $product, $user_roles )['rule_ids'] );
    }

    $ids = array_merge( $ids, wp_list_pluck( twshop_get_cart_discount_fees( $cart ), 'rule_id' ) );

    $shipping_rule = twshop_get_active_free_shipping_rule();
    if ( $shipping_rule ) {
        $methods = twshop_free_shipping_rule_methods( $shipping_rule );
        $chosen  = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
        if ( ! empty( $chosen ) && ( empty( $methods ) || array_intersect( $chosen, $methods ) ) ) {
            $ids[] = $shipping_rule['rule_id'];
        }
    }

    return array_values( array_unique( array_filter( $ids ) ) );
}

function twshop_store_applied_rule_ids_on_order( $order ) {
    if ( ! WC()->cart ) return;
    $order->update_meta_data( '_twshop_applied_rule_ids', twshop_collect_applied_rule_ids( WC()->cart ) );
}

