<?php
/**
 * 核心 helper：規則快取、規則使用次數、贈品優惠券、模組開關與定義
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 靜態快取規則列表：同一次請求內多次讀取只查一次資料庫。
 * 傳入 true 可強制重新讀取（規則儲存/刪除/排序後呼叫）。
 */
function twshop_get_rules( $force_refresh = false ) {
    static $rules_cache = null;
    if ( $force_refresh ) {
        $rules_cache = null;
    }
    if ( $rules_cache === null ) {
        $rules_cache = get_option( 'wc_discount_rules_settings', array() );
        $rules_cache = twshop_backfill_missing_rule_ids( $rules_cache );
    }
    return $rules_cache;
}

/**
 * 規則使用次數（全站累計）的 static cache 讀寫入口，供下方三個 twshop_*_rule_usage_total()
 * 共用同一份記憶體狀態，確保同一次請求內「遞增後立即再讀」也能拿到最新值。
 */
function twshop_rule_usage_totals_cache( $new_value = null ) {
    static $totals = null;
    if ( null !== $new_value ) {
        $totals = $new_value;
    }
    if ( null === $totals ) {
        $totals = get_option( 'wc_discount_rules_usage_totals', array() );
        if ( ! is_array( $totals ) ) $totals = array();
    }
    return $totals;
}

/**
 * 規則使用次數合併成單一陣列型 option（wc_discount_rules_usage_totals），取代改版前
 * 逐規則各自一個 twshop_rule_usage_total_{rule_id} option 的做法——規則數量多的站台會累積
 * 大量零散的動態 key option，這些通常會被 autoload 拉進每一次頁面載入的 alloptions
 * （不只影響折扣相關頁面），合併成一個 option 一次讀取即可涵蓋全部規則。
 * 相容舊資料：新格式尚無此規則紀錄時，回退讀取舊的動態 key option（不主動遷移舊 option，
 * 避免多執行緒/多請求同時寫入造成競態；只在下面「遞增」時才順手把該筆遷移過去並清掉舊值）。
 */
function twshop_get_rule_usage_total( $rule_id ) {
    $totals = twshop_rule_usage_totals_cache();
    if ( isset( $totals[ $rule_id ] ) ) return intval( $totals[ $rule_id ] );
    return intval( get_option( 'twshop_rule_usage_total_' . $rule_id, 0 ) );
}

function twshop_increment_rule_usage_total( $rule_id ) {
    $totals = twshop_rule_usage_totals_cache();
    $current = isset( $totals[ $rule_id ] ) ? intval( $totals[ $rule_id ] ) : intval( get_option( 'twshop_rule_usage_total_' . $rule_id, 0 ) );
    $totals[ $rule_id ] = $current + 1;
    update_option( 'wc_discount_rules_usage_totals', $totals, false );
    delete_option( 'twshop_rule_usage_total_' . $rule_id ); // 完成遷移，避免新舊兩份資料以後對不上
    twshop_rule_usage_totals_cache( $totals );
}

function twshop_delete_rule_usage_total( $rule_id ) {
    $totals = twshop_rule_usage_totals_cache();
    if ( isset( $totals[ $rule_id ] ) ) {
        unset( $totals[ $rule_id ] );
        update_option( 'wc_discount_rules_usage_totals', $totals, false );
        twshop_rule_usage_totals_cache( $totals );
    }
    delete_option( 'twshop_rule_usage_total_' . $rule_id ); // 相容舊資料殘留
}

/**
 * 讀出折扣規則的限制條件（type ＋ values），並相容舊資料。
 *
 * 舊版規則只有單一的 `category` / `tag` 欄位，尚未重新儲存過的規則沒有
 * `condition_type`/`condition_values`，必須從舊欄位回退讀取。這段回退原本在三個地方
 * 各抄了一份，三份逐字相同；漏改一份的後果是
 * 「舊規則在某一條路徑上限制條件突然消失」——規則會變成無條件適用，不會有任何錯誤訊息。
 *
 * 回傳 array( $type, $values )，供 list() 解構。
 */
function twshop_get_rule_condition( $rule ) {
    $type   = $rule['condition_type'] ?? '';
    $values = $rule['condition_values'] ?? array();
    if ( empty( $type ) && ! empty( $rule['category'] ) ) return array( 'category', array( $rule['category'] ) );
    if ( empty( $type ) && ! empty( $rule['tag'] ) )      return array( 'tag', array( $rule['tag'] ) );
    return array( $type, $values );
}

/**
 * 「規則作為優惠卡券供會員點擊套用」功能已於 v25.8.50 移除。舊資料一次性遷移：原本設成卡券的規則
 * 改為停用（否則會突然對所有符合條件的顧客自動套用），並清掉所有規則上的卡券欄位。管理員若要沿用
 * 這些規則，自行在後台啟用即可（變成自動套用）。
 *
 * 掛在 admin_init、以 twshop_rule_coupons_migrated 旗標只跑一次，**不要放進 twshop_get_rules()**：
 * 讀取時順手寫回，會把測試用 pre_option_wc_discount_rules_settings 注入的假規則寫進正式資料庫
 * （開發時實際發生過，蓋掉了站台的真實規則）。遷移前規則引擎本身就會忽略 is_coupon=yes 的規則
 * （twshop_is_discount_rule_valid_compute()），前台不會誤套用。
 */
function twshop_maybe_migrate_removed_rule_coupons() {
    if ( 'yes' === get_option( 'twshop_rule_coupons_migrated' ) ) return;
    $rules = twshop_migrate_removed_rule_coupons( get_option( 'wc_discount_rules_settings', array() ) );
    if ( null !== $rules ) {
        update_option( 'wc_discount_rules_settings', $rules );
        twshop_get_rules( true );
    }
    update_option( 'twshop_rule_coupons_migrated', 'yes' );
}

/**
 * @return array|null 有需要寫回時回傳遷移後的規則陣列，不需要變更時回傳 null
 */
function twshop_migrate_removed_rule_coupons( $rules ) {
    if ( ! is_array( $rules ) || empty( $rules ) ) return null;
    $coupon_keys = array( 'is_coupon', 'c_code', 'c_title', 'c_desc', 'c_exclusive' );
    $changed = false;
    foreach ( $rules as $k => $r ) {
        if ( ! is_array( $r ) ) continue;
        if ( 'yes' === ( $r['is_coupon'] ?? 'no' ) ) {
            $rules[ $k ]['enabled'] = 'no';
        }
        foreach ( $coupon_keys as $key ) {
            if ( array_key_exists( $key, $r ) ) {
                unset( $rules[ $k ][ $key ] );
                $changed = true;
            }
        }
    }
    return $changed ? $rules : null;
}

/**
 * 修補沒有 rule_id（或值為空）的舊規則資料。
 *
 * 早期版本的規則陣列沒有 rule_id 這個欄位，這類規則在後台編輯表單裡的隱藏欄位會被
 * 渲染成 value=""；儲存時 twshop_ajax_save_rule() 一看到空字串就會用 uniqid('rule_')
 * 生一個全新 ID，導致比對永遠對不到原本那筆（原本那筆也還是沒有 rule_id），於是「更新」
 * 變成在陣列尾端多插入一筆新資料，畫面上原本那張卡片看起來就像「存了也沒用」；
 * 刪除同一筆舊規則時，前端 JS 甚至因為 rule_id 是空字串直接 `if(!rule_id){ $form.remove(); return; }`
 * 短路掉，根本沒送出刪除的 AJAX 請求，重新整理後又會原封不動地跑回來。
 *
 * 這裡在每次讀取規則列表時檢查一次，把缺 rule_id 的項目補上真正的唯一值並立即寫回，
 * 補過一次之後全部規則都有正常 rule_id，就不會再觸發寫入。
 */
function twshop_backfill_missing_rule_ids( $rules ) {
    if ( ! is_array( $rules ) || empty( $rules ) ) return $rules;
    $changed = false;
    foreach ( $rules as $k => $r ) {
        if ( empty( $r['rule_id'] ) ) {
            $rules[ $k ]['rule_id'] = uniqid( 'rule_' );
            $changed = true;
        }
    }
    if ( $changed ) {
        update_option( 'wc_discount_rules_settings', $rules );
    }
    return $rules;
}

function twshop_get_earn_base_amount( $items_data, $unrestricted_total ) {
    list( $restrict_type, $restrict_values ) = twshop_get_typed_restriction(
        'wc_points_earn_restrict_type', 'wc_points_earn_restrict_values',
        array( 'category' => 'wc_points_earn_restricted_category', 'tag' => 'wc_points_earn_restricted_tag' )
    );

    if ( empty( $restrict_type ) || empty( $restrict_values ) ) {
        return $unrestricted_total;
    }
    $taxonomy = $restrict_type === 'tag' ? 'product_tag' : 'product_cat';

    $total = 0;
    foreach ( $items_data as $item ) {
        if ( has_term( $restrict_values, $taxonomy, $item['product_id'] ) ) $total += $item['total'];
    }
    return $total;
}

function twshop_get_user_point_multiplier( $user ) {
    // member_tiers 模組停用時，等級加倍效果也應一併停止——這裡是唯一判斷入口，
    // 呼叫端（實際發點/購物車預估）都不需要各自重複檢查模組狀態（v25.5.82 修正）。
    if ( ! twshop_module_enabled( 'member_tiers' ) ) return 1;
    $settings = get_option( 'wc_member_tiers_settings', array() );
    if ( empty( $settings ) || ! is_array( $settings ) ) return 1;
    foreach ( $settings as $tier ) {
        if ( in_array( $tier['slug'], (array) $user->roles, true ) && ! empty( $tier['point_multiplier'] ) ) {
            return floatval( $tier['point_multiplier'] );
        }
    }
    return 1;
}

function twshop_create_gift_coupon( $code, $gift, $email, $validity_days, $title, $desc ) {
    $coupon = new WC_Coupon();
    $coupon->set_code( $code );
    $coupon->set_discount_type( $gift['type'] );
    $coupon->set_amount( $gift['amount'] );
    $coupon->set_date_expires( strtotime( '+' . $validity_days . ' days' ) );
    $coupon->set_usage_limit( 1 );
    $coupon->set_usage_limit_per_user( 1 );
    $coupon->set_email_restrictions( array( $email ) );
    $coupon->set_individual_use( true );
    $coupon->update_meta_data( '_visual_coupon_title', $title );
    $coupon->update_meta_data( '_visual_coupon_desc', $desc );
    $coupon->save();
}

/**
 * 前台文字與開關類 option 的預設值對照表。
 *
 * 這 43 個 option 的預設值原本在後台設定頁與前台渲染處各寫一次(部分寫了三到五次),
 * 兩邊逐字相同全靠人維護。改了前台忘了改後台的後果特別隱晦:後台輸入框顯示的是
 * 舊預設字串,使用者以為那就是目前生效的文字,實際上前台跑的是新的——兩邊都不會報錯,
 * 而且只有在「使用者從沒儲存過這個欄位」時才看得出來。
 *
 * 只收「有多處指定預設值」的 option。單一處使用的預設值留在原地,搬進來只是把
 * 上下文推遠,沒有一致性可言。
 */
function twshop_get_option_defaults() {
    static $defaults = null;
    if ( null !== $defaults ) return $defaults;

    $defaults = array(
        // 優惠券前台文字
        'wc_coupon_btn_apply_text'              => '點擊套用',
        'wc_coupon_btn_remove_text'             => '取消套用',
        'wc_coupon_btn_shop_text'               => '去購物',
        'wc_coupon_btn_unavailable_text'        => '暫不可用',
        'wc_coupon_btn_used_text'               => '已使用',
        'wc_coupon_dialog_heading'              => '可用優惠券',
        'wc_coupon_dialog_trigger_applied_text' => '已套用優惠券・點此查看或更換',
        'wc_coupon_dialog_trigger_none_text'    => '查看可用優惠券（{count}）',
        'wc_general_coupon_noun'                => '優惠券',
        'wc_general_coupon_page_desc'           => '這裡展示您擁有的所有優惠，點擊按鈕即可前往購物選購',
        'wc_general_coupon_page_title'          => '專屬優惠券',
        'wc_general_no_coupon_msg'              => '目前沒有可用的專屬優惠券喔',

        // 加購區塊文字
        'wc_addon_btn_add_text'    => '加入加購',
        'wc_addon_btn_incart_text' => '已在購物車',
        'wc_addon_section_title'   => '🎉 專屬加購優惠',

        // 點數前台文字
        'wc_points_applied_text'      => '已套用 {amount} {term}，折抵 {discount} 元',
        'wc_points_balance_text'      => '您目前擁有 {amount} {term}可用',
        'wc_points_btn_apply_text'    => '套用折抵',
        'wc_points_btn_update_text'   => '更新或取消{term}',
        'wc_points_expiry_soon_text'  => '有 {amount} {term}將於 {date} 到期',
        'wc_points_input_placeholder' => '輸入欲使用{term}（{rate} 的倍數）',
        'wc_points_min_cart_text'     => '購物車需滿 {amount} 才可使用{term}折抵',
        'wc_points_no_balance_text'   => '您目前沒有可用的{term}',
        'wc_points_restricted_text'   => '購物車需包含「{names}」分類/標籤商品才可使用{term}',
        'wc_points_ui_heading'        => '使用{term}折抵',

        // 會員通知信與等級文字
        'wc_birthday_email_subject'        => '祝您生日快樂！專屬生日禮金',
        'wc_tier_change_email_body'        => '您好，您的會員等級已調整為：{tier}。',
        'wc_tier_change_email_subject'     => '【會員通知】等級調整',
        'wc_tier_max_reached_text'         => '您已達到最高會員等級 🎉',
        'wc_tier_not_configured_text'      => '目前尚未設定會員等級制度。',
        'wc_upgrade_email_body_no_gift'    => '您的會員等級已升級為：{tier}。',
        'wc_upgrade_email_subject'         => '恭喜升級！專屬升級回饋禮',
        'wc_upgrade_email_subject_no_gift' => '【會員通知】恭喜升級',

        // 會員中心頁籤名稱
        'wc_general_tab_name'    => '優惠券',
        'wc_membership_tab_name' => '會員權益',

        // 開關類（yes/no）
        'wc_account_tab_mobile_scroll'  => 'yes',
        'wc_badge_enabled'              => 'yes',
        'wc_classic_cart_show_addons'   => 'yes',
        'wc_classic_cart_show_coupons'  => 'yes',
        'wc_classic_cart_show_points'   => 'yes',
        'wc_classic_cart_show_progress' => 'yes',
        'wc_classic_cart_show_wallet'   => 'yes',
        'wc_wallet_tier_spend_full_amount' => 'yes',
        'wc_wallet_topup_email_enabled' => 'yes',
        'wc_wallet_topup_email_subject' => '儲值成功通知',
        'wc_shopee_sync_enabled'        => 'no',
    );
    return $defaults;
}

/**
 * 讀取上表涵蓋的 option,預設值統一由 twshop_get_option_defaults() 供應。
 *
 * 沿用 get_option() 的既有語意:只有 option 不存在時才回退預設值——已存在但為空字串
 * 的欄位仍然回傳空字串(使用者刻意清空該欄位的意思),跟改動前的行為完全一致。
 *
 * 對照表沒有的 key 會回退成空字串。這種打錯字的情況不會噴錯,但會讓對應的前台文字
 * 整段消失,渲染快照(.dev-tools/admin-render.php)與效能腳本的輸出雜湊都會抓到。
 */
function twshop_option( $option ) {
    $defaults = twshop_get_option_defaults();
    return get_option( $option, $defaults[ $option ] ?? '' );
}

/**
 * 載入 assets/js/ 底下的一支腳本,取代原本直接印在頁面裡的 <script> 區塊。
 *
 * $name 是相對於 assets/js/ 的路徑(不含 .js),handle 一律是 'twshop-' ＋ 檔名。
 * 版本號用 filemtime():改完檔案不必手動改版本,也不會讓瀏覽器拿到舊快取。
 *
 * $localize 是 { JS 全域變數名 => 資料陣列 },給原本靠 PHP 內插塞進 JS 的動態值
 * (nonce、admin-ajax 網址、可翻譯的文案)。走 wp_localize_script() 而不是繼續內插,
 * 資料會經過 wp_json_encode(),不需要在 JS 字串裡自己處理跳脫。
 *
 * 一律載入到頁尾($in_footer = true)。原本這些 <script> 印在頁面中段,只看得到自己
 * 上方的 DOM;移到頁尾後看得到的 DOM 只多不少,不會有「找不到元素」的新問題。
 */
function twshop_enqueue_asset_script( $name, array $localize = array(), array $deps = array( 'jquery' ) ) {
    $handle = 'twshop-' . basename( $name );
    $rel    = 'assets/js/' . $name . '.js';

    wp_enqueue_script( $handle, TWSHOP_PLUGIN_URL . $rel, $deps, filemtime( TWSHOP_PLUGIN_DIR . $rel ), true );

    foreach ( $localize as $object_name => $data ) {
        wp_localize_script( $handle, $object_name, $data );
    }
}

/**
 * 未設定的模組 key **預設停用**（v25.8.12 起，原本預設啟用）。改成預設關閉是為了
 * 讓打包出去的安裝包對客戶站台而言是「全部功能關閉」的乾淨初始狀態，客戶自行到
 * 「系統設定 ▸ 模組開關」逐一啟用需要的功能，而不是裝上去就啟用一整套不確定要不要用的東西。
 * 「系統設定 ▸ 模組開關」頁籤本身的 checkbox 預設值（`twshop_system_modules_tab()`，
 * `includes/admin/menus.php`）是獨立的另一份 `?? '0'`，兩處要一起改，改一邊忘了改
 * 另一邊只會讓頁籤畫面顯示的開關狀態跟實際生效的狀態對不上，不會有任何錯誤訊息。
 */
function twshop_module_enabled( $module ) {
    static $settings = null;
    if ( $settings === null ) {
        $settings = get_option( 'twshop_module_settings', array() );
    }
    return ( $settings[ $module ] ?? '0' ) === '1';
}

/**
 * 內部旗標：twshop 自己呼叫 WC()->cart->add_to_cart() 把「兌換商品」／「贈品」加入購物車時，
 * 用來暫時跳過 twshop_restrict_purchase_for_redeem_and_gift_products()（掛在
 * woocommerce_is_purchasable）的自我阻擋——這兩者都是刻意把「原本不開放直接購買」的商品
 * 加進購物車，不是顧客自己的一般購買流程。呼叫端務必用 try/finally 包住，確保就算
 * add_to_cart() 拋例外也一定會重置，不會讓旗標卡在開啟狀態影響後續同一次請求裡的其他商品。
 */
function twshop_bypass_purchase_restriction( $active = null ) {
    static $bypass = false;
    if ( null !== $active ) {
        $bypass = (bool) $active;
    }
    return $bypass;
}

/**
 * 目前所有「不可直接購買」的商品 ID：已設定的點數兌換商品（含分類/標籤展開）＋所有
 * 啟用中 free_gift 規則指定的贈品商品。只在對應模組（points／discount_rules）啟用時
 * 收集，模組關閉時不擋任何商品。per-request static cache——twshop_resolve_redeemable_products()
 * 本身完全沒有快取，每次呼叫可能觸發 wc_get_products()，商城列表頁對每個商品都重新算
 * 一次會是嚴重的 N+1 查詢問題。
 *
 * 刻意只看「是否被設定」，不模擬購物車當下是否達標（不即時判斷 free_gift 規則的
 * min_amount/時間區間/會員角色）——避免同一商品「購物車還沒到門檻能買、湊到門檻後
 * 突然不能買」這種令人困惑的狀態，也避免在商城列表頁對每個商品都重新跑一次完整規則
 * 有效性判斷的效能成本。
 */
function twshop_get_purchase_restricted_product_ids( $force_refresh = false ) {
    static $ids_cache = null;
    if ( $force_refresh ) $ids_cache = null;
    if ( null === $ids_cache ) {
        $ids = array();

        if ( twshop_module_enabled( 'points' ) ) {
            $list = get_option( 'wc_points_redeemable_products', array() );
            if ( ! empty( $list ) && is_array( $list ) ) {
                foreach ( twshop_resolve_redeemable_products( $list ) as $entry ) {
                    $ids[] = $entry['product']->get_id();
                }
            }
        }

        if ( twshop_module_enabled( 'discount_rules' ) ) {
            foreach ( twshop_get_rules() as $rule ) {
                // 「缺 enabled 這個 key 視為啟用」的預設值方向比照
                // twshop_is_discount_rule_valid_compute()（discount-engine.php）既有慣例，
                // 給升級前沒有這個欄位的舊規則資料相容。
                if ( 'free_gift' === ( $rule['type'] ?? '' )
                    && ( $rule['enabled'] ?? 'yes' ) !== 'no'
                    && ! empty( $rule['gift_product_id'] )
                ) {
                    $ids[] = (int) $rule['gift_product_id'];
                }
            }
        }

        $ids_cache = array_values( array_unique( array_map( 'intval', $ids ) ) );
    }
    return $ids_cache;
}

/**
 * 商品被設定為「點數兌換商品」或啟用中的「贈品」規則指定商品時，擋掉顧客一般管道的直接
 * 購買——商城列表頁的「加入購物車」會自動退化成「查看更多」連結（WooCommerce 核心
 * WC_Product_Simple::add_to_cart_url()/add_to_cart_text() 既有邏輯）、商品詳情頁完全不
 * 輸出加入購物車表單（single-product/add-to-cart/simple.php 開頭的
 * `if ( ! $product->is_purchasable() ) return;`）。twshop 自己內部把這些商品加入購物車
 * （兌換／自動贈品）都透過 twshop_bypass_purchase_restriction() 暫時跳過這裡，不受影響。
 * 「加購品」（addon_product 型別）刻意不在此限——那本來就設計成可以單獨用正常價格購買，
 * 只是滿足條件時能特價加購，跟兌換商品/贈品的語意不同。
 */
function twshop_restrict_purchase_for_redeem_and_gift_products( $purchasable, $product ) {
    if ( ! $purchasable ) return $purchasable;
    if ( twshop_bypass_purchase_restriction() ) return $purchasable;
    if ( in_array( $product->get_id(), twshop_get_purchase_restricted_product_ids(), true ) ) return false;
    return $purchasable;
}

/**
 * 掛 woocommerce_cart_item_is_purchasable（注意跟上面的 woocommerce_is_purchasable 是
 * 兩支不同的 filter）：WC_Cart_Session::get_cart_from_session() 在「每一次」頁面載入、
 * 從 session 還原購物車內容時，都會對購物車裡已存在的每個項目重新檢查一次 is_purchasable()，
 * 不通過就直接把該項目從購物車移除，並顯示「已從您的購物車移除」的提示。
 *
 * twshop_bypass_purchase_restriction() 這個旗標只在 add_to_cart() 呼叫的當下短暫生效，
 * 涵蓋不到「之後的頁面載入」這個時間點，所以兌換商品/贈品成功加入購物車後，下一次
 * 頁面載入就會被這支 session 還原邏輯擋下來、悄悄消失（v25.8.30 上線後才發現的迴歸）。
 *
 * 修法不是延長旗標時效，而是直接看這個 filter 唯一能拿到的、購物車項目自己的 meta
 * （$values，也就是 add_to_cart() 當初傳入的 $cart_item_data，已知一定包含
 * twshop_points_redeem_product_id 或 twshop_gift_rule_id 其中之一）：只要這兩個 key
 * 有一個存在，就代表這是 twshop 自己合法加進去的項目，直接放行，不受限制清單影響——
 * 不需要旗標，因為每次頁面載入這個 filter 都會拿到當下持久化的真實項目資料，判斷永遠準確。
 */
function twshop_allow_purchasable_for_tracked_cart_items( $purchasable, $key, $values ) {
    if ( $purchasable ) return $purchasable;
    if ( ! empty( $values['twshop_points_redeem_product_id'] ) || ! empty( $values['twshop_gift_rule_id'] ) ) {
        return true;
    }
    return $purchasable;
}

/**
 * 商品詳情頁的說明文字：is_purchasable() 為 false 時，simple.php 樣板完全不輸出加入購物車
 * 表單（連庫存資訊都不顯示），沒有這行說明的話頁面看起來會像空白/壞掉，顧客不知道為什麼
 * 不能買。掛在價格（priority 10）之後、加入購物車表單（priority 30）之前。兌換商品／贈品
 * 兩種情況統一用同一句話，不特別區分，避免多一次查詢判斷是哪一種。
 */
function twshop_render_purchase_restricted_notice() {
    global $product;
    if ( ! $product instanceof WC_Product ) return;
    if ( ! in_array( $product->get_id(), twshop_get_purchase_restricted_product_ids(), true ) ) return;
    echo '<p class="twshop-purchase-restricted-notice">' . esc_html__( '此商品目前無法直接購買。', 'ultimate-ecommerce' ) . '</p>';
}

/**
 * 商品網址（slug）是否改用商品編號。**單一讀取入口**，所以刻意不登記進
 * twshop_get_option_defaults()——那張表的用途是「同一個純量 option 在多處被讀取、
 * 各處各寫一次預設值導致漂移」，只有一個入口的 option 硬塞進去反而讓那張表的語意變模糊
 * （跟蝦皮那三個 option 同樣的判斷）。
 *
 * **預設 no**：客戶更新外掛不該被靜默改掉全站商品網址。實作見
 * includes/modules/product-slug.php。
 */
function twshop_product_slug_use_id_enabled() {
    return 'yes' === get_option( 'wc_product_slug_use_id', 'no' );
}

/**
 * 可開關模組的清單（label + 說明文字），供「系統設定 ▸ 模組開關」與「儀表板」共用，
 * 避免模組清單分散在兩處各自維護、改一邊忘了改另一邊。
 */
function twshop_get_module_definitions() {
    return array(
        'member_tiers'    => array( 'label' => '會員分級', 'desc' => '會員等級升降、生日禮、升等禮' ),
        'discount_rules'  => array( 'label' => '折扣規則', 'desc' => '動態折扣、免運、自動贈品、加購品' ),
        'visual_coupons'  => array( 'label' => '優惠卡券', 'desc' => '卡片式優惠券、會員優惠券頁面' ),
        'points'          => array( 'label' => '紅利點數', 'desc' => '消費累點、點數折抵、異動紀錄' ),
        'wallet'          => array( 'label' => '儲值中心', 'desc' => '線上自助儲值、購物折抵、會員中心餘額與交易紀錄' ),
        'order_checkout_enhancements' => array(
            'label' => '訂單強化',
            'desc'  => '台灣地址下拉選單、超商取貨免填地址、訂單物流資訊顯示與搜尋、自訂訂單狀態、批次操作、物流貨態自動完成訂單',
        ),
        // 蝦皮串接（原 shopee_sync 模組）v25.8.65 起移出模組開關系統，改成「系統設定 ▸
        // 蝦皮串接」頁籤裡的獨立開關 wc_shopee_sync_enabled，見 twshop_shopee_sync_enabled()
        // （shopee-api.php）與 CLAUDE.md「蝦皮串接模組」一節。
    );
}

