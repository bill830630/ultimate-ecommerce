<?php
/**
 * 7. 智慧會員中心與排程發放
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 7. 智慧會員中心與排程發放
// =========================================================================

function twshop_run_daily_check() {
    // 分批處理＋只取 ID（不載入完整 WP_User 物件），避免會員數大時單次把所有使用者塞進記憶體、
    // 一次性跑完全部無上限查詢。role__not_in 條件不受等級重算影響（只排除 administrator/editor，
    // 不受任何自訂等級 role 變動影響），分頁過程中比對集合不會位移。
    $batch_size = 200;
    $paged = 1;
    do {
        $user_ids = get_users( array(
            'role__not_in' => array( 'administrator', 'editor' ),
            'fields'       => 'ID',
            'number'       => $batch_size,
            'paged'        => $paged,
        ) );
        foreach ( $user_ids as $user_id ) {
            twshop_recalculate_user_tier( $user_id );
        }
        $paged++;
        // twshop_get_user_spent_since() 內的 per-request static cache 沒有上限，
        // 每批處理完就清空一次，避免會員數大時記憶體隨批次數線性成長（見該函式與
        // twshop_flush_user_spent_cache() 的說明；跟 twshop_clear_user_spent_cache()
        // 清「單一會員」跨請求 transient 的用途不同，這裡清的是整個 static array）。
        twshop_flush_user_spent_cache();
    } while ( count( $user_ids ) === $batch_size );

    $today_md     = wp_date( 'm-d' );
    $current_year = wp_date( 'Y' );
    $b_days       = get_option( 'wc_birthday_validity_days', 30 );
    $b_subject    = twshop_option( 'wc_birthday_email_subject' );
    $tiers        = get_option( 'wc_member_tiers_settings', array() );

    // 生日欄位 twshop_birthday 現行格式為 'MM-DD'（無年份），舊資料可能是 'YYYY-MM-DD'
    // （見 twshop_parse_birthday_month_day() 的格式假設）。用兩次可走索引的查詢取代 REGEXP
    // （REGEXP 無法走索引，等同全 usermeta 表掃描）：
    //   1. 新格式：meta_value 精確等於今天的 'MM-DD'（可走索引）
    //   2. 舊格式：meta_value LIKE '%-MM-DD'（結尾比對，雖仍非最佳但遠優於 REGEXP 的全表掃描）
    // 兩者都比照第一段分批模式（200 筆一批、fields => 'ID'、paged 迴圈），結果合併去重。
    $birthday_user_ids = array();

    $paged = 1;
    do {
        $batch = get_users( array(
            'role__not_in' => array( 'administrator', 'editor' ),
            'fields'       => 'ID',
            'number'       => $batch_size,
            'paged'        => $paged,
            'meta_key'     => 'twshop_birthday',
            'meta_value'   => $today_md,
            'meta_compare' => '=',
        ) );
        $birthday_user_ids = array_merge( $birthday_user_ids, $batch );
        $paged++;
    } while ( count( $batch ) === $batch_size );

    // 舊格式 'YYYY-MM-DD' 結尾比對：WP_User_Query 的 meta_query 'LIKE' compare 一律把值
    // 包在 %...% 兩端（無法只做結尾比對），改用 $wpdb 直接下「結尾比對」的 LIKE 查出候選 ID，
    // 再透過 get_users( 'include' => ... ) 套用 role__not_in／分批，行為與上面新格式查詢一致。
    global $wpdb;
    $legacy_candidate_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'twshop_birthday' AND meta_value LIKE %s",
        '%-' . $wpdb->esc_like( $today_md )
    ) );
    if ( ! empty( $legacy_candidate_ids ) ) {
        $paged = 1;
        do {
            $batch = get_users( array(
                'role__not_in' => array( 'administrator', 'editor' ),
                'fields'       => 'ID',
                'number'       => $batch_size,
                'paged'        => $paged,
                'include'      => $legacy_candidate_ids,
            ) );
            $birthday_user_ids = array_merge( $birthday_user_ids, $batch );
            $paged++;
        } while ( count( $batch ) === $batch_size );
    }

    $birthday_user_ids = array_unique( array_map( 'intval', $birthday_user_ids ) );

    foreach ( array_chunk( $birthday_user_ids, $batch_size ) as $id_chunk ) {
        foreach ( $id_chunk as $user_id ) {
            $last_sent = get_user_meta( $user_id, 'twshop_birthday_sent_year', true );
            if ( $last_sent === $current_year ) continue;

            $user = get_userdata( $user_id );
            if ( ! $user ) continue;

            $user_role = ! empty( $user->roles ) ? $user->roles[0] : 'customer';
            $b_enable = 'no'; $gifts = array(); $role_name = '一般顧客';
            foreach ( $tiers as $t ) {
                if ( $t['slug'] === $user_role ) {
                    $b_enable  = $t['b_enable'] ?? 'no';
                    $gifts     = json_decode( stripslashes( $t['b_gifts'] ?? '[]' ), true );
                    $role_name = $t['name'];
                    break;
                }
            }

            if ( $b_enable !== 'yes' || ! is_array( $gifts ) || empty( $gifts ) ) continue;

            $msg_lines = twshop_issue_tier_gifts( $user, $gifts, 'BDAY-', '-' . $current_year, $b_days, $role_name . ' 生日禮', '專屬優惠結帳即可折抵' );
            $message = str_replace(
                array( '{name}', '{codes}', '{days}' ),
                array( $user->display_name, implode( "\n", $msg_lines ), $b_days ),
                get_option( 'wc_birthday_email_body', "親愛的 {name}：\n\n生日快樂！這是系統為您生成的生日禮包：\n{codes}\n\n有效期限為 {days} 天，請至網站查看！" )
            );
            // 排入佇列而非在這個迴圈裡同步呼叫 wp_mail()（阻塞性網路呼叫），比照
            // twshop_points_daily_expiry_check() 既有的做法，避免生日會員多時拖慢 cron 總執行時間。
            wp_schedule_single_event( time(), 'twshop_send_birthday_gift_notice', array( $user->user_email, $b_subject, $message ) );
            update_user_meta( $user->ID, 'twshop_birthday_sent_year', $current_year );
        }
        twshop_flush_user_spent_cache();
    }
}

/**
 * 發放一組等級禮（生日禮/升等禮共用）：點數直接入帳，其餘建立限本人使用的優惠券並記進
 * _twshop_claimed_coupons。回傳通知信用的每行說明文字。
 */
function twshop_issue_tier_gifts( WP_User $user, array $gifts, $code_prefix, $code_suffix, $days, $title, $desc ) {
    $claimed   = get_user_meta( $user->ID, '_twshop_claimed_coupons', true ) ?: array();
    $msg_lines = array();
    foreach ( $gifts as $gift ) {
        if ( ( $gift['type'] ?? '' ) === 'points' ) {
            $amount = (int) $gift['amount'];
            twshop_add_points_log( $user->ID, $amount, $title );
            $msg_lines[] = "+{$amount} " . twshop_points_term();
            continue;
        }
        $code = $code_prefix . strtoupper( wp_generate_password( 6, false ) ) . $code_suffix;
        twshop_create_gift_coupon( $code, $gift, $user->user_email, $days, $title, $desc );
        $claimed[]   = $code;
        $t_txt       = ( $gift['type'] === 'percent' ) ? '打折(%)' : '折抵($)';
        $msg_lines[] = "【{$code}】 ({$t_txt}: {$gift['amount']})";
    }
    update_user_meta( $user->ID, '_twshop_claimed_coupons', $claimed );
    return $msg_lines;
}

/**
 * twshop_run_daily_check() 排入佇列（wp_schedule_single_event）的生日禮通知信
 * 實際寄送 callback，讓每日 cron 主迴圈不會被 wp_mail() 的阻塞式網路呼叫逐一拖慢。
 */
function twshop_send_birthday_gift_notice_email( $to, $subject, $message ) {
    wp_mail( $to, $subject, $message );
}

/**
 * 計算某會員自 $since_date（Y-m-d，null 代表不限，全時間累計）以來的已完成訂單消費總額。
 * 是等級週期制的核心讀數：搭配 twshop_tier_anchor_date（起算日）使用。
 */
/**
 * 這張訂單計入等級消費額的金額。依 wc_wallet_tier_spend_full_amount 設定（預設 yes）
 * 決定儲值金折抵掉的部分算不算：yes 時把 `_twshop_wallet_applied` 加回 get_total()
 * （視同以全額現金購買——理由是儲值金本身是顧客先前已經付過的真錢，不是店家額外
 * 讓利的折扣，跟優惠券/點數折抵是店家實質讓利不同）；no 時只算實際透過其他金流付款
 * 的部分（沿用 get_total() 已扣除儲值金折抵後的原值，跟優惠券/點數折抵的既有計算
 * 方式一致）。
 *
 * 另外扣掉這張訂單裡儲值金商品項目的金額（v25.8.67 起）——買儲值金本身不是消費，
 * 理由同 `twshop_award_points_on_order_complete()` 的排除註解：儲值當下不算，真正
 * 花掉這筆儲值金買東西時該筆消費訂單自然會計入，不能兩邊都算。訂單可能同時有儲值金
 * 商品與一般商品，只扣儲值金商品那部分，一般商品的消費額仍正常計入。
 *
 * twshop_get_user_spent_since() 與 twshop_find_qualifying_order_date() 必須共用同一套
 * 邏輯，不能各自公式——否則會出現「前者算出的累積總額已達到升級門檻，後者卻因為公式
 * 不同、永遠找不到達標的那個日期」這種自相矛盾的結果。
 */
function twshop_get_order_total_for_tier_spend( $order ) {
    $total = (float) $order->get_total();
    if ( 'yes' === twshop_option( 'wc_wallet_tier_spend_full_amount' ) ) {
        $total += (float) $order->get_meta( '_twshop_wallet_applied' );
    }
    $total -= twshop_get_order_wallet_product_total( $order );
    return $total;
}

function twshop_get_user_spent_since( $user_id, $since_date = null, $flush_cache = false ) {
    static $cache = array();
    // $flush_cache 僅供 twshop_flush_user_spent_cache() 內部呼叫使用，清空整個 static array
    // 後直接返回，不執行下方查詢邏輯。
    if ( $flush_cache ) {
        $cache = array();
        return null;
    }
    $cache_key = $user_id . '_' . ( $since_date ?: 'all' );
    if ( isset( $cache[ $cache_key ] ) ) return $cache[ $cache_key ];

    // 跨請求短期快取（1 小時）：同一位會員短時間內重複查看「我的會員權益」頁會重複觸發
    // 這支查詢，1 小時的 TTL 足以消掉同一次瀏覽 session 內的重複查詢，同時短到不會實質
    // 影響每日排程（twshop_run_daily_check）的判斷新鮮度——退款/取消訂單目前沒有
    // 任何 hook 會主動清這個快取，這個 TTL 讓排程每天執行時幾乎必定重新查詢，維持原本
    // 「每天都看得到最新退款狀態」的行為，不會因為快取而延遲太久。訂單完成時
    // （twshop_trigger_on_order()）會主動清快取，確保升級判斷永遠拿到當下最新的消費總額。
    $transient_key = 'twshop_spent_' . $cache_key;
    $cached = get_transient( $transient_key );
    if ( false !== $cached ) {
        $cache[ $cache_key ] = (float) $cached;
        return $cache[ $cache_key ];
    }

    // v25.8.67 起不再用 meta_query 排除「整張訂單」——訂單可能同時有儲值金商品與一般
    // 商品，排除要靠 twshop_get_order_total_for_tier_spend() 逐項扣除，不能在查詢層級
    // 整張跳過（否則混合訂單裡一般商品的消費額也會被誤排除）。
    $args = array(
        'customer_id' => $user_id,
        'status'      => 'completed',
        'limit'       => -1,
    );
    if ( $since_date ) $args['date_created'] = '>=' . $since_date;
    $orders = wc_get_orders( $args );

    $total_spent = 0;
    foreach ( $orders as $order ) $total_spent += twshop_get_order_total_for_tier_spend( $order );
    $cache[ $cache_key ] = $total_spent;
    set_transient( $transient_key, $total_spent, HOUR_IN_SECONDS );
    return $total_spent;
}

/**
 * 清除 twshop_get_user_spent_since() 對某會員的快取（all-time 與目前起算日兩種 key）。
 * 在會員的消費總額實際發生變化的時間點（目前只有訂單完成一處）呼叫，確保升級判斷
 * 永遠拿到當下最新資料，不受上面 1 小時 TTL 快取影響。
 */
function twshop_clear_user_spent_cache( $user_id ) {
    delete_transient( 'twshop_spent_' . $user_id . '_all' );
    $anchor_date = get_user_meta( $user_id, 'twshop_tier_anchor_date', true );
    if ( $anchor_date ) {
        delete_transient( 'twshop_spent_' . $user_id . '_' . $anchor_date );
    }
}

/**
 * 清空 twshop_get_user_spent_since() 的 per-request static cache（整個請求內、所有會員）。
 * 跟上面的 twshop_clear_user_spent_cache( $user_id ) 用途不同、不要混淆：
 * 那支清的是「某一位會員」的跨請求 transient 快取（訂單完成時呼叫，確保升級判斷拿到最新資料）；
 * 這支清的是「目前這次請求內、目前已經算過的所有會員」的記憶體 static array，
 * 沒有上限、正常請求不會有問題，但 twshop_run_daily_check() 這種單次請求內要
 * 遍歷大量會員的 cron 場景，static cache 會隨會員數線性成長佔用記憶體，因此該函式在
 * 每批（batch）處理完之後呼叫一次即可，兩者互不影響、互不取代。
 */
function twshop_flush_user_spent_cache() {
    twshop_get_user_spent_since( 0, null, true );
}

/**
 * 依訂單時間由舊到新累加消費金額，回傳第一筆讓累積金額達到 $threshold 的訂單日期（Y-m-d）。
 * 用來決定「造成升級/首次達標的那筆消費」，作為新一輪等級週期的起算日。
 */
function twshop_find_qualifying_order_date( $user_id, $since_date, $threshold ) {
    // v25.8.67 起不再用 meta_query 排除整張訂單，理由同 twshop_get_user_spent_since()。
    $args = array(
        'customer_id' => $user_id,
        'status'      => 'completed',
        'limit'       => -1,
        'orderby'     => 'date',
        'order'       => 'ASC',
    );
    if ( $since_date ) $args['date_created'] = '>=' . $since_date;
    $orders = wc_get_orders( $args );

    $running = 0;
    foreach ( $orders as $order ) {
        $running += twshop_get_order_total_for_tier_spend( $order );
        if ( $running >= $threshold ) {
            $date = $order->get_date_created();
            return $date ? $date->date( 'Y-m-d' ) : wp_date( 'Y-m-d' );
        }
    }
    return wp_date( 'Y-m-d' ); // 安全防線：理論上呼叫端已確認門檻有達到，不應該執行到這裡
}

/**
 * 實際切換會員角色，並依情境（升級 / 降級或調整）寄送對應通知信。
 * $new_tier 傳 null 代表降回一般顧客（customer）。
 */
function twshop_apply_tier_change( WP_User $user, $new_tier, $is_upgrade ) {
    $new_role      = $new_tier ? $new_tier['slug'] : 'customer';
    $new_role_name = $new_tier ? $new_tier['name'] : '一般顧客';

    if ( in_array( $new_role, $user->roles, true ) ) return;

    // 只替換「會員等級角色＋customer」，其他角色（其他外掛的角色、員工角色）一律保留（v25.8.34 修正：
    // 原本移除全部角色，員工帳號消費達標會失去後台權限）。
    $tier_roles = wp_list_pluck( (array) get_option( 'wc_member_tiers_settings', array() ), 'slug' );
    $tier_roles[] = 'customer';
    foreach ( $user->roles as $role ) {
        if ( in_array( $role, $tier_roles, true ) ) $user->remove_role( $role );
    }
    $user->add_role( $new_role );

    if ( $is_upgrade && $new_tier ) {
        $u_enable = $new_tier['u_enable'] ?? 'no';
        if ( $u_enable === 'yes' ) {
            $gifts = json_decode( stripslashes( $new_tier['u_gifts'] ?? '[]' ), true );
            if ( is_array( $gifts ) && ! empty( $gifts ) ) {
                $u_subject = twshop_option( 'wc_upgrade_email_subject' );
                $msg_lines = twshop_issue_tier_gifts(
                    $user, $gifts, 'UPG-', '', get_option( 'wc_upgrade_validity_days', 30 ),
                    $new_role_name . ' 升級禮', '慶祝達成新等級結帳即可折抵'
                );
                $gift_body = str_replace(
                    '{codes}', implode( "\n", $msg_lines ),
                    get_option( 'wc_upgrade_email_body_gift', "恭喜升級！這是您的專屬升級禮包：\n{codes}\n\n請至會員中心查看。" )
                );
                wp_mail( $user->user_email, $u_subject, $gift_body );
            } else {
                wp_mail(
                    $user->user_email,
                    twshop_option( 'wc_upgrade_email_subject_no_gift' ),
                    str_replace( '{tier}', $new_role_name, twshop_option( 'wc_upgrade_email_body_no_gift' ) )
                );
            }
        } else {
            wp_mail(
                $user->user_email,
                twshop_option( 'wc_upgrade_email_subject_no_gift' ),
                str_replace( '{tier}', $new_role_name, twshop_option( 'wc_upgrade_email_body_no_gift' ) )
            );
        }
    } else {
        wp_mail(
            $user->user_email,
            twshop_option( 'wc_tier_change_email_subject' ),
            str_replace( '{tier}', $new_role_name, twshop_option( 'wc_tier_change_email_body' ) )
        );
    }
}

/**
 * 會員等級判定：以「造成升級的那筆訂單日期」為起算日（twshop_tier_anchor_date）的週期制。
 * - 起算日以來累積消費只要達到更高等級門檻，隨時升級；起算日重設為造成升級的那筆訂單日期
 * - 未達更高等級時，僅在「起算日 + 目前等級 period 天」到期當下才檢查：
 *     達標 → 續等，起算日重設為今天；未達標 → 依累積消費降到符合門檻的最高等級，起算日重設為今天
 * - period = 0 代表永久累計，達成後不再檢查降級
 * - 尚未有起算日（新會員第一次達標、或既有會員第一次套用本邏輯）視為「至今全部消費」
 */
function twshop_recalculate_user_tier( $user_id ) {
    static $is_calculating = false;
    if ( $is_calculating ) return;
    $is_calculating = true;

    $user = new WP_User( $user_id );
    if ( ! $user->exists() || user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'edit_posts' ) ) {
        $is_calculating = false;
        return;
    }

    $tiers = get_option( 'wc_member_tiers_settings', array() );
    if( empty($tiers) || ! is_array( $tiers ) ) { $is_calculating = false; return; }
    // 判定依門檻由高到低，不依賴後台卡片排列順序（C16）。
    usort( $tiers, function( $a, $b ) { return (int) ( $b['threshold'] ?? 0 ) <=> (int) ( $a['threshold'] ?? 0 ); } );

    $current_tier = null;
    foreach ( $tiers as $tier ) {
        if ( in_array( $tier['slug'], (array) $user->roles, true ) ) { $current_tier = $tier; break; }
    }
    $current_threshold = $current_tier ? (int) $current_tier['threshold'] : 0;

    $anchor_date = get_user_meta( $user_id, 'twshop_tier_anchor_date', true );
    $spend       = twshop_get_user_spent_since( $user_id, $anchor_date ?: null );

    $qualifying_tier = null;
    foreach ( $tiers as $tier ) {
        if ( $spend >= $tier['threshold'] ) { $qualifying_tier = $tier; break; }
    }
    $qualifying_threshold = $qualifying_tier ? (int) $qualifying_tier['threshold'] : 0;

    // 升級：只要達到更高門檻，隨時生效，起算日重設為造成升級的那筆訂單日期
    if ( $qualifying_threshold > $current_threshold ) {
        $new_anchor = twshop_find_qualifying_order_date( $user_id, $anchor_date ?: null, $qualifying_tier['threshold'] );
        update_user_meta( $user_id, 'twshop_tier_anchor_date', $new_anchor );
        twshop_apply_tier_change( $user, $qualifying_tier, true );
        $is_calculating = false;
        return;
    }

    // 尚未達成任何等級，維持一般顧客，不需動作
    if ( ! $current_tier ) { $is_calculating = false; return; }

    // 既有會員第一次套用本邏輯、尚無起算日：以今天為起算日開始第一輪週期
    if ( ! $anchor_date ) {
        update_user_meta( $user_id, 'twshop_tier_anchor_date', wp_date( 'Y-m-d' ) );
        $is_calculating = false;
        return;
    }

    $period = isset( $current_tier['period'] ) ? intval( $current_tier['period'] ) : 365;
    if ( $period <= 0 ) { $is_calculating = false; return; } // 永久累計，不檢查降級

    $period_end = date( 'Y-m-d', strtotime( $anchor_date . " +{$period} days" ) );
    if ( wp_date( 'Y-m-d' ) < $period_end ) { $is_calculating = false; return; } // 週期尚未到期，繼續等待

    // 週期到期：檢查是否維持門檻
    if ( $qualifying_threshold === $current_threshold ) {
        update_user_meta( $user_id, 'twshop_tier_anchor_date', wp_date( 'Y-m-d' ) ); // 續等成功，起算日重設為今天
    } else {
        update_user_meta( $user_id, 'twshop_tier_anchor_date', wp_date( 'Y-m-d' ) );
        twshop_apply_tier_change( $user, $qualifying_tier, false ); // 未達標，依累積消費降級
    }

    $is_calculating = false;
}

/**
 * 會員中心頁籤預設順序（未曾在後台儲存過排序設定時使用，與升級前的固定順序一致）
 */
function twshop_get_default_account_tab_order() {
    return array( 'my-membership', 'my-coupons', 'my-wallet', 'orders', 'edit-account', 'edit-address', 'dashboard', 'downloads' );
}

/**
 * 取得目前所有已註冊的會員中心頁籤（slug => 標籤），涵蓋 WC 核心與其他外掛（如 wc-line-order-notify）
 * 註冊的項目。暫時移除本外掛自己的排序/開關 filter，避免抓到「已經被篩選過」的結果。
 */
function twshop_get_all_registered_account_tabs() {
    remove_filter( 'woocommerce_account_menu_items', 'twshop_modify_account_menu_items', 20 );
    $items = function_exists( 'wc_get_account_menu_items' ) ? wc_get_account_menu_items() : array();
    add_filter( 'woocommerce_account_menu_items', 'twshop_modify_account_menu_items', 20 );

    return twshop_add_own_account_tabs( $items );
}

/**
 * 加入本外掛自己的會員中心頁籤（依模組開關）並套用後台自訂名稱。
 */
function twshop_add_own_account_tabs( $items ) {
    if ( twshop_module_enabled( 'member_tiers' ) ) {
        $items['my-membership'] = twshop_option( 'wc_membership_tab_name' );
    }
    if ( twshop_module_enabled( 'visual_coupons' ) ) {
        $items['my-coupons'] = twshop_option( 'wc_general_tab_name' );
    }
    if ( twshop_module_enabled( 'wallet' ) ) {
        $items['my-wallet'] = twshop_wallet_term();
    }
    return twshop_apply_account_tab_name_overrides( $items );
}

/**
 * 套用後台自訂的頁籤名稱（僅適用於「會員等級&積分」「折價券」以外的頁籤，
 * 這兩個已有各自專屬的設定欄位 wc_membership_tab_name / wc_general_tab_name）。
 * 留空即代表沿用 WC 核心或其他外掛提供的預設名稱。
 */
function twshop_apply_account_tab_name_overrides( $items ) {
    $custom_names = get_option( 'wc_account_tab_names', array() );
    foreach ( $custom_names as $slug => $name ) {
        if ( '' !== $name && isset( $items[ $slug ] ) ) {
            $items[ $slug ] = $name;
        }
    }
    return $items;
}

/**
 * 取得後台儲存的頁籤排序/開關設定；尚未儲存過時，依預設順序產生（控制台、下載預設關閉，其餘皆開啟）
 */
function twshop_get_account_tabs_settings( $registered_slugs = null ) {
    if ( null === $registered_slugs ) {
        $registered_slugs = array_keys( twshop_get_all_registered_account_tabs() );
    }

    $saved = get_option( 'wc_account_tabs_settings', array() );
    if ( ! empty( $saved ) ) {
        $known_slugs = wp_list_pluck( $saved, 'slug' );
        foreach ( $registered_slugs as $slug ) {
            if ( 'customer-logout' === $slug ) continue;
            if ( in_array( $slug, $known_slugs, true ) ) continue;
            $saved[] = array( 'slug' => $slug, 'enabled' => 'yes' );
        }
        return $saved;
    }

    $default_order = twshop_get_default_account_tab_order();
    $rows          = array();

    foreach ( $default_order as $slug ) {
        if ( ! in_array( $slug, $registered_slugs, true ) ) continue;
        $rows[] = array(
            'slug'    => $slug,
            'enabled' => in_array( $slug, array( 'dashboard', 'downloads' ), true ) ? 'no' : 'yes',
        );
    }
    foreach ( $registered_slugs as $slug ) {
        if ( 'customer-logout' === $slug ) continue;
        if ( in_array( $slug, $default_order, true ) ) continue;
        $rows[] = array( 'slug' => $slug, 'enabled' => 'yes' );
    }

    return $rows;
}

function twshop_redirect_account_dashboard() {
    if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_user_logged_in() ) return;

    // WooCommerce 11.0 新增的「電子信箱確認」（Automattic\WooCommerce\Internal\CustomerEmailVerification\
    // VerificationController::maybe_process_request()，同樣掛 template_redirect，但比本函式晚註冊）用的
    // 確認連結／重寄連結是純 $_GET 參數（wc_verify_email_key+wc_verify_email_user／wc_send_verification）
    // 疊在會員中心根網址（wc_get_page_permalink('myaccount')）上，不是 add_rewrite_endpoint() 註冊的
    // endpoint，下面用 $wp_rewrite->endpoints 判斷「是否為純 dashboard」的通用邏輯偵測不到這組參數。
    // 若不在此提前排除，本函式會把這個連結誤判成純 dashboard，直接跳轉到預設頁籤並 exit，導致
    // WooCommerce 自己的確認 handler 永遠不會執行，客戶點了確認信也不會真的確認成功。
    if ( isset( $_GET['wc_verify_email_key'] ) || isset( $_GET['wc_send_verification'] ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    foreach ( wc_get_account_menu_items() as $endpoint => $label ) {
        if ( 'dashboard' === $endpoint || 'customer-logout' === $endpoint ) continue;
        break;
    }
    if ( empty( $endpoint ) || 'dashboard' === $endpoint ) return;

    // 判斷「目前是否為純 dashboard」不能只看 wc_get_account_menu_items()（只有導覽選單項目，不含
    // view-order 這類選單項目底下的子頁面，v25.5.40 修的第一版問題），也不能只看 is_wc_endpoint_url()／
    // WC()->query->get_query_vars()（只涵蓋透過 woocommerce_get_query_vars filter 額外註冊過的
    // endpoint——很多第三方外掛的會員中心頁籤只呼叫 add_rewrite_endpoint() + woocommerce_account_menu_items
    // filter，沒有再去 hook woocommerce_get_query_vars，實測 wc-line-order-notify 的「帳號綁定」
    // (social-login) 與 wc-auction-bidding 的「我的競標」(my-auctions) 都是這種寫法；v25.5.40 改用
    // is_wc_endpoint_url() 修 view-order 時，反而把這兩個第三方頁籤也誤判成「純 dashboard」造成同樣的
    // 跳轉問題，是這次的迴歸——這個判斷不能依賴「第三方外掛有沒有額外通知 WooCommerce」。
    // 改成直接查 WordPress 自己的 rewrite endpoint 註冊表（$wp_rewrite->endpoints）：任何外掛只要呼叫
    // add_rewrite_endpoint()（沒有這行，URL 根本不會被解析出對應的 query var，頁籤點了也不會動）就一定
    // 會登記在這裡，不受該外掛有沒有額外去對接 WooCommerce 內部登記表影響，才能同時涵蓋 WC 核心、twshop
    // 自己、以及所有第三方外掛（現在的與未來新增的）註冊的 endpoint（v25.5.41 修正）。
    global $wp, $wp_rewrite;
    $registered_endpoint_vars = array();
    if ( ! empty( $wp_rewrite->endpoints ) ) {
        foreach ( $wp_rewrite->endpoints as $endpoint_def ) {
            // add_endpoint() 存入格式為 [$places, $name, $query_var]，第 3 個元素才是真正寫進
            // $wp->query_vars 的 key（多數呼叫端 $name === $query_var，但用第 3 個元素才保證正確）。
            if ( isset( $endpoint_def[2] ) ) $registered_endpoint_vars[ $endpoint_def[2] ] = true;
        }
    }
    if ( ! empty( array_intersect_key( $wp->query_vars, $registered_endpoint_vars ) ) ) return;

    wp_safe_redirect( wc_get_account_endpoint_url( $endpoint ) );
    exit;
}

/**
 * 依後台儲存的排序/開關設定重組會員中心導覽項目。
 * 登出固定排在最後且永遠顯示，不受開關設定影響（避免會員被鎖在無法登出的狀態）。
 */
function twshop_modify_account_menu_items( $items ) {
    $items = twshop_add_own_account_tabs( $items );

    $tab_settings = twshop_get_account_tabs_settings( array_keys( $items ) );
    $known_slugs  = wp_list_pluck( $tab_settings, 'slug' );

    $ordered = array();
    foreach ( $tab_settings as $row ) {
        $slug = $row['slug'] ?? '';
        if ( '' === $slug || 'customer-logout' === $slug ) continue;
        if ( 'no' === ( $row['enabled'] ?? 'yes' ) ) continue;
        if ( isset( $items[ $slug ] ) ) $ordered[ $slug ] = $items[ $slug ];
    }
    // 涵蓋設定儲存後才新出現的頁籤（例如新安裝的外掛），預設開啟並排在登出之前；
    // 已存在設定中但被關閉的頁籤（$known_slugs 內）不會在這裡被重新加回
    foreach ( $items as $slug => $label ) {
        if ( 'customer-logout' === $slug ) continue;
        if ( in_array( $slug, $known_slugs, true ) ) continue;
        $ordered[ $slug ] = $label;
    }

    if ( isset( $items['customer-logout'] ) ) {
        $ordered['customer-logout'] = $items['customer-logout'];
    }

    return $ordered;
}

/**
 * 輸出會員中心頁籤的外觀樣式（形狀＋配色），套用同一組 Blocksy 主題既有的 CSS 變數，
 * 因此桌機側邊清單與手機橫向頁籤列（見 twshop-frontend.css）會同時套用，不需分別處理。
 * 配色留空的欄位不輸出對應變數，沿用主題原本的顏色。
 */
function twshop_my_coupons_endpoint_content() {
    $page_title = twshop_option( 'wc_general_coupon_page_title' );
    $page_desc  = twshop_option( 'wc_general_coupon_page_desc' );
    echo '<h2>' . esc_html($page_title) . '</h2>';
    echo '<p style="margin-bottom:20px;">' . esc_html($page_desc) . '</p>';
    twshop_auto_display_coupons('account');
}

function twshop_my_membership_endpoint_content() {
    $user_id = get_current_user_id();
    if ( ! $user_id ) return;

    $tiers = get_option( 'wc_member_tiers_settings', array() );

    // ── 預先計算：等級名稱（供下方「等級與消費進度」區塊使用）──────────
    $user              = wp_get_current_user();
    $current_tier_name = '一般顧客';
    foreach ( $tiers as $tier ) {
        if ( in_array( $tier['slug'], (array) $user->roles, true ) ) {
            $current_tier_name = $tier['name'];
            break;
        }
    }

    // ── 區塊一：會員等級與消費進度 ─────────────────────────────────────

    echo '<div class="twshop-account-section">';
    echo '<h2>會員等級</h2>';

    if ( ! empty( $tiers ) ) {
        $tiers_asc = $tiers; // 不依賴後台卡片排列順序，跟 twshop_recalculate_user_tier() 一致
        usort( $tiers_asc, function( $a, $b ) { return (int) ( $a['threshold'] ?? 0 ) <=> (int) ( $b['threshold'] ?? 0 ); } );
        $next_tier  = null;

        $current_tier_cfg = null;
        foreach ( $tiers as $tier ) {
            if ( in_array( $tier['slug'], (array) $user->roles, true ) ) { $current_tier_cfg = $tier; break; }
        }

        $anchor_date   = get_user_meta( $user_id, 'twshop_tier_anchor_date', true );
        $current_spent = twshop_get_user_spent_since( $user_id, $anchor_date ?: null );

        foreach ( $tiers_asc as $tier ) {
            if ( $tier['threshold'] > $current_spent ) { $next_tier = $tier; break; }
        }

        echo '<div class="woocommerce-Message woocommerce-message woocommerce-info twshop-tier-info" style="margin-bottom:10px;">';
        echo '<span>目前等級：<strong>' . esc_html( $current_tier_name ) . '</strong></span>';
        echo '<span>累計消費：<strong>' . wc_price( $current_spent ) . '</strong></span>';
        echo '</div>';

        if ( $next_tier ) {
            $percentage = min( 100, ( $current_spent / $next_tier['threshold'] ) * 100 );
            $diff       = $next_tier['threshold'] - $current_spent;

            if ( $current_tier_cfg && $anchor_date ) {
                $period = isset( $current_tier_cfg['period'] ) ? intval( $current_tier_cfg['period'] ) : 365;
                $time_req_text = ( $period <= 0 )
                    ? '永久累計，無時間限制'
                    : '請於 ' . date( 'Y-m-d', strtotime( $anchor_date . " +{$period} days" ) ) . ' 前達成，否則將依累積消費調整等級';
            } else {
                $time_req_text = '達成即可升級';
            }

            echo '<p style="margin:6px 0 4px;font-size:13px;opacity:.75;">';
            echo esc_html( $time_req_text ) . '&ensp;/&ensp;下一階：<strong>' . esc_html( $next_tier['name'] ) . '</strong>（' . wc_price( $next_tier['threshold'] ) . '）';
            echo '</p>';
            echo '<div style="background:rgba(0,0,0,0.08);border-radius:99px;height:10px;width:100%;overflow:hidden;margin:6px 0 8px;">';
            echo '<div style="background:var(--theme-palette-color-1,currentColor);height:100%;width:' . esc_attr( $percentage ) . '%;transition:width .8s ease;"></div>';
            echo '</div>';
            echo '<p style="margin:0;"><strong>距離升級還差 <mark>' . wc_price( $diff ) . '</mark></strong></p>';
        } else {
            echo '<p><mark>' . esc_html( twshop_option( 'wc_tier_max_reached_text' ) ) . '</mark></p>';
        }
    } else {
        echo '<p><em>' . esc_html( twshop_option( 'wc_tier_not_configured_text' ) ) . '</em></p>';
    }
    echo '</div>';

    // ── 分隔線 ──────────────────────────────────────────────────────────
    echo '<hr class="twshop-section-divider" />';

    // ── 區塊一‧五：會員等級權益總覽表 ───────────────────────────────────
    if ( ! empty( $tiers ) ) {
        $pt      = twshop_points_term();
        $rules   = get_option( 'wc_discount_rules_settings', array() );
        $user    = wp_get_current_user();

        echo '<div class="twshop-account-section">';
        echo '<h2>會員等級權益總覽</h2>';
        echo '<div class="twshop-tier-table-wrap">';
        echo '<table class="shop_table shop_table_responsive twshop-tier-table" style="width:100%;">';
        echo '<thead><tr>';
        echo '<th>等級</th>';
        echo '<th>升級門檻</th>';
        echo '<th>維持效期</th>';
        echo '<th>' . esc_html( $pt ) . '倍率</th>';
        echo '<th>折扣</th>';
        echo '<th>生日禮</th>';
        echo '<th>升等禮</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ( $tiers as $tier ) {
            $is_current = in_array( $tier['slug'], (array) $user->roles, true );
            $threshold  = intval( $tier['threshold'] );
            $period     = intval( $tier['period'] );
            $multiplier = floatval( $tier['point_multiplier'] ?? 1 );

            // 門檻文字（僅金額）
            $threshold_text = wc_price( $threshold );

            // 維持效期：達成後這段天數內需維持門檻消費才能續等，0 = 永久（達成後不再檢查降級）
            $period_text = ( $period > 0 ) ? $period . ' 天' : '永久有效';

            // 折扣：收集此等級的 percent / cart_percent 規則
            $discount_parts = array();
            foreach ( $rules as $rule ) {
                if ( ( $rule['role'] ?? 'all' ) !== $tier['slug'] ) continue;
                if ( $rule['type'] === 'percent' ) {
                    $discount_parts[] = '商品 ' . $rule['value'] . '% 折';
                } elseif ( $rule['type'] === 'cart_percent' ) {
                    $discount_parts[] = '全單 ' . $rule['value'] . '% 折';
                }
            }
            $discount_text = empty( $discount_parts ) ? '—' : implode( '<br>', $discount_parts );

            // 生日禮／升等禮輸出跳脫（S8 修補之二，輸出那半）：twshop_sanitize_gifts_json()
            // 只清洗「之後才存的」資料，這個 option 在修補之前就可能已經存在未經清洗的
            // 惡意/畸形 amount（例如管理員曾經存過 <script> 字串）——既有資料只能靠這裡
            // 的 esc_html() 擋下，兩半缺一不可。wc_price() 回傳的是 HTML（含
            // <span class="woocommerce-Price-amount">…</span>），不可整段 esc_html()，
            // 只需確保傳入的是數值（floatval）即可安全交給 wc_price() 組 HTML。$pt（點數
            // 名稱，來自 option）在下面組字串時也一併用 esc_html() 包住。

            // 生日禮
            $b_text = '—';
            if ( ( $tier['b_enable'] ?? 'no' ) === 'yes' ) {
                $b_gifts = json_decode( $tier['b_gifts'] ?? '[]', true );
                if ( ! empty( $b_gifts ) ) {
                    $b_parts = array();
                    foreach ( $b_gifts as $g ) {
                        $amount = floatval( $g['amount'] ?? 0 );
                        $b_parts[] = $g['type'] === 'percent'
                            ? esc_html( $amount . '% 折扣' )
                            : ( $g['type'] === 'points' ? esc_html( $amount . ' ' . $pt ) : wc_price( $amount ) . ' 折抵' );
                    }
                    $b_text = implode( '<br>', $b_parts );
                }
            }

            // 升等禮
            $u_text = '—';
            if ( ( $tier['u_enable'] ?? 'no' ) === 'yes' ) {
                $u_gifts = json_decode( $tier['u_gifts'] ?? '[]', true );
                if ( ! empty( $u_gifts ) ) {
                    $u_parts = array();
                    foreach ( $u_gifts as $g ) {
                        $amount = floatval( $g['amount'] ?? 0 );
                        $u_parts[] = $g['type'] === 'percent'
                            ? esc_html( $amount . '% 折扣' )
                            : ( $g['type'] === 'points' ? esc_html( $amount . ' ' . $pt ) : wc_price( $amount ) . ' 折抵' );
                    }
                    $u_text = implode( '<br>', $u_parts );
                }
            }

            echo '<tr' . ( $is_current ? ' class="twshop-tier-current"' : '' ) . '>';
            echo '<td data-title="等級">' . esc_html( $tier['name'] ) . ( $is_current ? ' ✦' : '' ) . '</td>';
            echo '<td data-title="升級門檻">' . $threshold_text . '</td>';
            echo '<td data-title="維持效期">' . esc_html( $period_text ) . '</td>';
            echo '<td data-title="' . esc_attr( $pt . '倍率' ) . '">' . esc_html( $multiplier ) . 'x</td>';
            echo '<td data-title="折扣">' . $discount_text . '</td>';
            echo '<td data-title="生日禮">' . $b_text . '</td>';
            echo '<td data-title="升等禮">' . $u_text . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
        echo '<hr class="twshop-section-divider" />';
    }

    // ── 區塊二：紅利點數（points 模組關閉時整段不顯示）───────────────────
    if ( ! twshop_module_enabled( 'points' ) ) return;

    $points  = (int) get_user_meta( $user_id, 'twshop_reward_points', true );
    $history = get_user_meta( $user_id, 'twshop_points_history', true );
    if ( ! is_array( $history ) ) $history = array();

    $pt = twshop_points_term();
    echo '<div class="twshop-account-section">';
    echo '<h2>我的' . esc_html( $pt ) . '</h2>';

    // ── 點數說明：餘額、兌換比例、目前總價值、到期規則、使用限制（合併於單一提示框） ──
    $redemption_rate = (float) get_option( 'wc_points_redemption_rate', 1 );
    if ( $redemption_rate <= 0 ) $redemption_rate = 1;
    $max_percent      = (float) get_option( 'wc_points_max_percent', 30 );
    $min_cart         = (float) get_option( 'wc_points_min_cart_amount', 0 );
    list( $redeem_restrict_type, $redeem_restrict_values ) = twshop_get_typed_restriction(
        'wc_points_redeem_restrict_type', 'wc_points_redeem_restrict_values',
        array( 'category' => 'wc_points_restricted_categories' )
    );
    $expiry_days      = (int) get_option( 'wc_points_expiry_days', 0 );
    $point_value      = floor( $points / $redemption_rate );
    $nearest_expiring = twshop_get_nearest_expiring_batch( $user_id );

    // 單一卡片：點數餘額最醒目置頂，其餘規則以易讀的列表形式呈現（獨立版面，不套用主題的 woocommerce-message 樣式）
    echo '<div class="twshop-points-card">';

    echo '<div class="twshop-points-card__balance">';
    echo '<div class="twshop-points-card__balance-label">目前可用' . esc_html( $pt ) . '</div>';
    echo '<div class="twshop-points-card__balance-value">' . esc_html( $points ) . ' <span class="twshop-points-card__balance-unit">' . esc_html( $pt ) . '</span></div>';
    echo '<div class="twshop-points-card__balance-worth">約可折抵 ' . wc_price( $point_value ) . '</div>';
    echo '</div>';

    echo '<div class="twshop-points-card__details">';

    echo '<div class="twshop-points-card__row">';
    echo '<span class="twshop-points-card__row-label">兌換比例</span>';
    echo '<span class="twshop-points-card__row-value">' . esc_html( $redemption_rate ) . ' ' . esc_html( $pt ) . ' = NT$1</span>';
    echo '</div>';

    echo '<div class="twshop-points-card__row">';
    echo '<span class="twshop-points-card__row-label">有效期限</span>';
    if ( $expiry_days > 0 ) {
        echo '<span class="twshop-points-card__row-value">' . esc_html( $expiry_days ) . ' 天（自入帳日起算）</span>';
    } else {
        echo '<span class="twshop-points-card__row-value">永久有效</span>';
    }
    echo '</div>';

    if ( $expiry_days > 0 && $nearest_expiring ) {
        echo '<div class="twshop-points-card__row twshop-points-card__row--highlight">';
        echo '<span class="twshop-points-card__row-label">即將到期</span>';
        echo '<span class="twshop-points-card__row-value">' . esc_html( $nearest_expiring['amount'] ) . ' ' . esc_html( $pt ) . '，將於 ' . esc_html( $nearest_expiring['expire'] ) . ' 到期</span>';
        echo '</div>';
    }

    // 使用限制：單筆最高折抵% + 其餘門檻條件合併為一行敘述，各條只保留關鍵字避免重複贅字
    $limit_parts = array();
    if ( $max_percent > 0 ) $limit_parts[] = '最高折抵 ' . esc_html( $max_percent ) . '%';
    if ( $min_cart > 0 ) $limit_parts[] = '滿 ' . esc_html( twshop_plain_price( $min_cart ) ) . ' 可用';
    if ( ! empty( $redeem_restrict_type ) && ! empty( $redeem_restrict_values ) ) {
        $redeem_taxonomy = $redeem_restrict_type === 'tag' ? 'product_tag' : 'product_cat';
        $term_names = array();
        foreach ( $redeem_restrict_values as $term_id ) {
            $term = get_term( $term_id, $redeem_taxonomy );
            if ( $term && ! is_wp_error( $term ) ) $term_names[] = $term->name;
        }
        $redeem_label = $redeem_restrict_type === 'tag' ? '標籤' : '分類';
        if ( $term_names ) $limit_parts[] = '限「' . esc_html( implode( '、', $term_names ) ) . '」' . $redeem_label;
    }
    echo '<div class="twshop-points-card__row">';
    echo '<span class="twshop-points-card__row-label">使用限制</span>';
    echo '<span class="twshop-points-card__row-value">' . ( empty( $limit_parts ) ? '無特殊限制' : implode( '、', $limit_parts ) ) . '</span>';
    echo '</div>';

    echo '</div>'; // .twshop-points-card__details
    echo '</div>'; // .twshop-points-card

    if ( ! empty( $history ) ) {
        echo '<div class="twshop-points-history-wrap">';
        echo '<table class="shop_table shop_table_responsive">';
        echo '<thead><tr>';
        echo '<th>時間</th><th>異動' . esc_html( $pt ) . '</th><th>說明</th><th>餘額</th><th>使用期限</th>';
        echo '</tr></thead><tbody>';
        foreach ( $history as $log ) {
            $is_earn = intval( $log['amount'] ) > 0;
            $cls     = $is_earn ? 'twshop-points-earn' : 'twshop-points-use';
            $amt     = ( $is_earn ? '+' : '' ) . esc_html( $log['amount'] ) . ' ' . esc_html( $pt );

            // 使用期限：僅入帳（+）紀錄適用單一到期日；扣除類紀錄是從多筆批次 FIFO 扣抵，沒有單一到期日可對應
            if ( $is_earn ) {
                if ( ! empty( $log['expire'] ) ) {
                    $expire_text = esc_html( $log['expire'] );
                } elseif ( array_key_exists( 'expire', $log ) ) {
                    $expire_text = '永久有效'; // 該筆入帳當下未啟用到期規則
                } else {
                    $expire_text = '—'; // 舊資料尚無 expire 欄位，無法回溯得知
                }
            } else {
                $expire_text = '—';
            }

            echo '<tr>';
            echo '<td data-title="時間">'     . esc_html( $log['time'] )    . '</td>';
            echo '<td data-title="' . esc_attr( '異動' . $pt ) . '" class="' . esc_attr( $cls ) . '">' . $amt . '</td>';
            echo '<td data-title="說明">'     . esc_html( $log['reason'] )  . '</td>';
            echo '<td data-title="餘額">'     . esc_html( $log['balance'] ) . '</td>';
            echo '<td data-title="使用期限">' . $expire_text . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    } else {
        echo '<p><em>目前尚無' . esc_html( $pt ) . '異動紀錄。</em></p>';
    }
    echo '</div>';
}

function twshop_add_visual_coupon_options( $coupon_get_id, $coupon ) {
    $auto_title = wp_strip_all_tags( twshop_format_wc_coupon_discount( $coupon->get_discount_type(), $coupon->get_amount() ) );
    $auto_desc  = twshop_build_wc_coupon_restrictions( $coupon );
    echo '<div class="options_group"><p class="form-field"><strong>優惠卡券設定 (終極電商)</strong></p>';
    woocommerce_wp_checkbox( array(
        'id'          => '_visual_coupon_manual_only',
        'label'       => '僅供手動輸入',
        'description' => '勾選後，此優惠券不會自動顯示在「我的優惠券」列表或購物車/結帳頁的優惠券卡片區塊，回到 WooCommerce 原生行為，僅能由顧客自行輸入代碼套用（例如私下發送、活動限定的優惠碼）。下方標題/說明欄位仍會用於顧客手動套用後、購物車小計「已套用優惠券」列的顯示文字。',
        'desc_tip'    => true,
    ) );
    woocommerce_wp_text_input( array(
        'id'          => '_visual_coupon_title',
        'label'       => '卡片標題',
        'description' => '儲存優惠券後即在前台顯示卡片；留空時自動以折扣文字顯示',
        'desc_tip'    => true,
        'placeholder' => $auto_title ?: '依折扣自動產生',
    ) );
    woocommerce_wp_text_input( array(
        'id'          => '_visual_coupon_desc',
        'label'       => '卡片說明敘述',
        'description' => '留空時自動以限制條件填入',
        'desc_tip'    => true,
        'placeholder' => $auto_desc ?: '（無限制條件）',
    ) );
    echo '</div>';
}
function twshop_save_visual_coupon_options( $post_id, $coupon ) {
    $coupon->update_meta_data( '_visual_coupon_title', isset( $_POST['_visual_coupon_title'] ) ? sanitize_text_field( wp_unslash( $_POST['_visual_coupon_title'] ) ) : '' );
    $coupon->update_meta_data( '_visual_coupon_desc', isset( $_POST['_visual_coupon_desc'] ) ? sanitize_text_field( wp_unslash( $_POST['_visual_coupon_desc'] ) ) : '' );
    $coupon->update_meta_data( '_visual_coupon_manual_only', isset( $_POST['_visual_coupon_manual_only'] ) ? 'yes' : 'no' );
    $coupon->save();
}

function twshop_build_wc_coupon_restrictions( WC_Coupon $coupon ) {
    $parts = array();
    $min = floatval( $coupon->get_minimum_amount() );
    if ( $min > 0 ) $parts[] = '消費滿 NT$' . number_format( $min, 0 );
    $max = floatval( $coupon->get_maximum_amount() );
    if ( $max > 0 ) $parts[] = '消費上限 NT$' . number_format( $max, 0 );
    $cat_ids = $coupon->get_product_categories();
    if ( ! empty( $cat_ids ) ) {
        $names = array();
        foreach ( $cat_ids as $id ) { $t = get_term( $id, 'product_cat' ); if ( $t && ! is_wp_error( $t ) ) $names[] = $t->name; }
        if ( $names ) $parts[] = '適用分類：' . implode( '、', $names );
    }
    $ex_cats = $coupon->get_excluded_product_categories();
    if ( ! empty( $ex_cats ) ) {
        $names = array();
        foreach ( $ex_cats as $id ) { $t = get_term( $id, 'product_cat' ); if ( $t && ! is_wp_error( $t ) ) $names[] = $t->name; }
        if ( $names ) $parts[] = '排除分類：' . implode( '、', $names );
    }
    if ( $coupon->get_individual_use() ) $parts[] = '不可與其他優惠同用';
    $per_user = intval( $coupon->get_usage_limit_per_user() );
    if ( $per_user > 0 ) $parts[] = '每人限用 ' . $per_user . ' 次';
    return implode( '｜', $parts );
}


function twshop_format_wc_coupon_discount( $discount_type, $amount ) {
    $amount = floatval( $amount );
    return match ( $discount_type ) {
        'percent'                 => sprintf( '折扣 %s%%', rtrim( rtrim( number_format( $amount, 2 ), '0' ), '.' ) ),
        'fixed_cart', 'fixed_product' => sprintf( '折抵 %s', wc_price( $amount ) ),
        default                   => '',
    };
}

function twshop_format_rule_discount( $type, $value, $rule = null ) {
    $value = floatval( $value );
    return match ( $type ) {
        'percent', 'cart_percent'     => sprintf( '折扣 %s%%', rtrim( rtrim( number_format( $value, 2 ), '0' ), '.' ) ),
        'fixed_product', 'cart_discount' => sprintf( '折抵 %s', wc_price( abs( $value ) ) ),
        'free_shipping'               => '免運費',
        'free_gift'                   => '贈品',
        'addon_product'               => '加購優惠',
        'buy_x_get_y'                 => $rule
            ? sprintf( '買%d送%d', max( 1, (int) ( $rule['buy_qty'] ?? 0 ) ), max( 1, (int) ( $rule['free_qty'] ?? 0 ) ) )
            : '買N送N優惠',
        'tiered_cart'                 => '階梯式訂單折扣',
        default                       => '',
    };
}

/**
 * 優惠券因未達最低消費而「暫不可用」時，在卡片底下提示還差多少錢（v25.5.92 起）。
 *
 * **v25.8.8 大幅簡化**：原本除了這行文字，還有一條進度條與「推薦加購湊滿額」商品清單
 * （每張未達標卡片各推薦最多 3 件、各帶一顆「加入」按鈕）。帳戶頁「我的優惠券」有幾張
 * 未達標的券就重複幾次，整頁被這些區塊塞滿、視覺上蓋過優惠券本身。推薦清單背後的
 * twshop_get_coupon_gap_products() 又得 wc_get_products( limit => -1 ) 掃全站商品、逐一
 * wc_get_price_to_display()，而且快取 key 含門檻金額，**不同門檻的優惠券各掃一次全站**。
 * 現在只留一行文字，該支查詢與它專用的 twshop_get_product_cached() 包裝一併移除。
 * 這是刻意的取捨，不要「順手」把進度條或推薦商品加回來。
 */
function twshop_render_coupon_gap_info( $gap_amount ) {
    return '<div class="twshop-coupon-gap">還差 <strong>' . wc_price( $gap_amount ) . '</strong> 即可使用</div>';
}

/**
 * 把單筆優惠券候選（WC 原生優惠券或折扣規則優惠券皆可）組成卡片 HTML，依 $is_used 分類
 * 寫入 available/unavailable 緩衝區，並同步更新計數器。兩種來源判斷 is_used／過期／使用上限
 * 的邏輯完全不同（分屬 WC_Coupon 物件 API 與規則陣列兩種資料結構，故意不強行合併），
 * 但算出結果後「組卡片＋依可用性分類＋計數」這段完全一樣，抽成共用函式，避免未來調整
 * 卡片排序/分類邏輯時（過去 v25.5.33/34/36/37 皆屬此類）漏改其中一個迴圈。
 */
function twshop_finalize_coupon_card(
    $code, $display_title, $display_desc, $expiry_date, $is_used, $is_applied, $location, $shop_url,
    &$output_cards_available, &$output_cards_unavailable, &$found_any, &$card_count, &$any_applied,
    $gap_html = ''
) {
    $found_any = true;
    $card_count++;
    if ( $is_applied ) $any_applied = true;
    $card_html = twshop_generate_coupon_html( $code, $display_title, $display_desc, $expiry_date, $is_used, $is_applied, $location, '', $shop_url, $gap_html );
    if ( $is_used ) { $output_cards_unavailable .= $card_html; } else { $output_cards_available .= $card_html; }
}

/**
 * 優惠券「還差 $X 即可使用」提示（v25.5.92）。WC 優惠券與規則優惠券兩個迴圈原本各寫一份，
 * 除了門檻來源不同（get_minimum_amount() vs $rule['min_amount']）逐字相同。
 *
 * 直接拿門檻跟購物車小計比較，不去猜 is_valid() 失敗的確切原因——WC_Coupon 沒有結構化的
 * 失敗原因可查，只有錯誤訊息字串，逐字比對不可靠；若金額其實已達標，差額會是 0 而不顯示。
 *
 * **必須額外排除 $already_used_up**（v25.5.93 修正，重大 bug）：個人使用上限已達成跟未達
 * 最低消費是兩個各自獨立的條件，可能同時成立。原本只看「金額是否達標」誤以為兩者互斥，
 * 導致已經用過的優惠券也顯示「還差 $X 即可使用」，顧客真的湊到滿額後套用仍然失敗
 * （卡住的其實是使用次數，不是金額），這個提示等於是空頭支票。
 *
 * v25.5.94：不再限定 cart/checkout、也不再依附 $is_used——帳戶頁（我的優惠券）瀏覽優惠券
 * 清單時不受目前購物車套用狀態影響（$is_used 在帳戶頁情境下只代表「已使用完」，不代表
 * 「這次購物車還沒達標」），純粹依「金額是否達標」這個獨立事實決定要不要顯示，三種頁面
 * 共用同一套判斷。
 *
 * v25.8.8：原本還收 $condition_type/$condition_values 兩個參數，用來把推薦加購商品限制在
 * 優惠券的限定範圍內。推薦清單移除後這兩個參數沒有任何用途，一併從簽名拿掉（見
 * twshop_render_coupon_gap_info() 的說明）。
 */
function twshop_build_coupon_gap_html( $min_amount, $already_used_up, $location ) {
    if ( $already_used_up || ! in_array( $location, array( 'cart', 'checkout', 'account' ), true ) ) return '';

    $min_amount = floatval( $min_amount );
    if ( $min_amount <= 0 ) return '';

    $cart_subtotal = WC()->cart ? WC()->cart->get_subtotal() : 0;
    if ( $cart_subtotal >= $min_amount ) return '';

    return twshop_render_coupon_gap_info( $min_amount - $cart_subtotal );
}

/**
 * 目前使用者看得到的視覺化優惠券（有 _visual_coupon_title meta 的 shop_coupon），已排除永久不可用的：
 * 僅供手動輸入、已過期、全站次數用完、限定其他 Email。每筆
 * [ 'post' => WP_Post, 'coupon' => WC_Coupon, 'used_up' => 本人使用次數已達上限 ]。
 * 我的優惠券/購物車卡片（twshop_auto_display_coupons()）與滿額進度（twshop_get_coupon_progress_items()）
 * 共用，原本兩邊各寫一份。
 *
 * 用 meta_query 在 DB 層過濾（_visual_coupon_title 是否存在的判斷方式被 wc-line-order-notify 依賴，見 CLAUDE.md）。
 */
function twshop_get_customer_visual_coupons() {
    static $cache = array();
    $user_id = get_current_user_id();
    if ( isset( $cache[ $user_id ] ) ) return $cache[ $user_id ];

    $email = is_user_logged_in() ? wp_get_current_user()->user_email : '';
    $list  = array();
    $posts = get_posts( array(
        'posts_per_page' => -1,
        'post_type'      => 'shop_coupon',
        'post_status'    => 'publish',
        'no_found_rows'  => true,
        'meta_query'     => array( array( 'key' => '_visual_coupon_title', 'compare' => 'EXISTS' ) ),
    ) );
    foreach ( $posts as $c ) {
        if ( 'yes' === get_post_meta( $c->ID, '_visual_coupon_manual_only', true ) ) continue;
        $coupon = new WC_Coupon( $c->ID );

        $expires = $coupon->get_date_expires();
        if ( $expires && $expires < current_datetime() ) continue;

        $u_limit = $coupon->get_usage_limit();
        if ( $u_limit > 0 && $coupon->get_usage_count() >= $u_limit ) continue;

        $restrictions = $coupon->get_email_restrictions();
        if ( ! empty( $restrictions ) && ( ! is_user_logged_in() || ! in_array( $email, $restrictions ) ) ) continue;

        $used_up = false;
        $p_limit = $coupon->get_usage_limit_per_user();
        if ( $p_limit > 0 && is_user_logged_in() ) {
            $u_count = 0;
            foreach ( (array) $coupon->get_used_by() as $used ) {
                if ( strtolower( $used ) === strtolower( $email ) || (string) $used === (string) $user_id ) $u_count++;
            }
            $used_up = $u_count >= $p_limit;
        }

        $list[] = array( 'post' => $c, 'coupon' => $coupon, 'used_up' => $used_up );
    }
    return $cache[ $user_id ] = $list;
}

function twshop_auto_display_coupons($location = 'account') {
    // 同一次請求內（例如傳統購物車自動注入與 AJAX 刷新前後銜接）可能被呼叫多次，
    // 輸出結果只取決於當下使用者／購物車／session，同一 request 內不會改變，故用 static cache
    // 依 $location 快取整段渲染好的 HTML，避免重複掃描與驗證優惠券。
    static $cache = array();
    if ( isset( $cache[ $location ] ) ) {
        echo $cache[ $location ];
        return;
    }
    ob_start();

    $found_any = false;
    $card_count = 0;
    $any_applied = false;
    // 可套用的排前面、不可套用（已使用／目前不符資格）的排後面，最後再合併成一份輸出，
    // 避免兩種狀態的卡片交錯排列，顧客一眼先看到真正能用的優惠券。
    $output_cards_available = '';
    $output_cards_unavailable = '';

    foreach ( twshop_get_customer_visual_coupons() as $entry ) {
        $c               = $entry['post'];
        $coupon_obj      = $entry['coupon'];
        $code            = $c->post_title;
        $visual_title    = get_post_meta( $c->ID, '_visual_coupon_title', true );
        // 個人使用上限已達成是永久狀態，跟下面 is_valid() 的暫時性狀態（未達最低消費等）分開記，
        // 讓「還差 $X」只在真的是金額問題時顯示（v25.5.93 修正）。
        $already_used_up = $entry['used_up'];
        $is_used         = $already_used_up;

        // 帳戶頁（我的優惠券）：已使用的優惠券不需要顯示，直接跳過；
        // 購物車/結帳頁維持顯示「暫不可用」（見下方），兩種情境的需求不同。
        if ( 'account' === $location && $is_used ) continue;

        // 購物車/結帳頁：目前不符合套用資格（未達最低消費、商品不符限制等）的優惠券不再直接隱藏，
        // 改標記 $is_used 沿用「灰階＋停用」外觀顯示出來，讓顧客知道有這張券、以及大概需要什麼條件
        // （描述文字裡本來就有寫門檻），而不是完全看不到。個人使用上限已達成的（上面已判斷）也是同一套處理。
        if ( ( $location === 'cart' || $location === 'checkout' ) && ! $is_used ) {
            $is_valid = true;
            try { if ( ! $coupon_obj->is_valid() ) $is_valid = false; } catch ( Exception $e ) { $is_valid = false; }
            if ( ! $is_valid ) $is_used = true;
        }

        $is_applied = is_object( WC()->cart ) && WC()->cart->has_discount( $code );
        $expiry_date = $coupon_obj->get_date_expires() ? $coupon_obj->get_date_expires()->date('Y-m-d') : '無期限';
        $discount_text = twshop_format_wc_coupon_discount($coupon_obj->get_discount_type(), $coupon_obj->get_amount());

        $auto_title    = wp_strip_all_tags( $discount_text );
        $auto_desc     = twshop_build_wc_coupon_restrictions( $coupon_obj );
        $display_title = $visual_title ?: $auto_title;
        $display_desc  = get_post_meta( $c->ID, '_visual_coupon_desc', true ) ?: $auto_desc;

        // WC_Coupon 原生只有「限定商品」「限定分類」兩種限制條件（沒有標籤），
        // 有商品限制優先於分類限制（商品是更精確的目標）。
        $coupon_product_ids = $coupon_obj->get_product_ids();
        $coupon_cat_ids     = $coupon_obj->get_product_categories();
        if ( ! empty( $coupon_product_ids ) ) {
            $shop_url = twshop_get_coupon_shop_url( 'product', $coupon_product_ids );
        } elseif ( ! empty( $coupon_cat_ids ) ) {
            $shop_url = twshop_get_coupon_shop_url( 'category', $coupon_cat_ids );
        } else {
            $shop_url = wc_get_page_permalink( 'shop' );
        }

        // 「還差 $X 即可使用」提示，判斷全在 twshop_build_coupon_gap_html() 內（含各版本修正的來由）。
        $gap_html = twshop_build_coupon_gap_html(
            $coupon_obj->get_minimum_amount(), $already_used_up, $location
        );

        twshop_finalize_coupon_card(
            $code, $display_title, $display_desc, $expiry_date, $is_used, $is_applied, $location, $shop_url,
            $output_cards_available, $output_cards_unavailable, $found_any, $card_count, $any_applied,
            $gap_html
        );
    }

    // 可套用的排前面、不可套用的排後面（見上方 $output_cards_available/$output_cards_unavailable 的說明）
    $output_cards = $output_cards_available . $output_cards_unavailable;

    // 帳戶頁（我的優惠券）維持原本一律展開的純網格顯示，那本身就是專門瀏覽優惠券的頁面；
    // 購物車/結帳頁改用「按鈕＋彈出視窗」，避免優惠券清單把商品列表往下擠。
    // 用原生 <dialog> 元素（showModal()）：內建遮罩、鍵盤 ESC 關閉、焦點管理，不需要額外的手風琴／彈窗套件。
    // 已套用優惠券時按鈕文字改為提示「已套用」，但不會自動彈出視窗——頁面載入就跳出彈窗是常見的體驗地雷，
    // 顧客仍可隨時點按鈕查看或更換。
    if ( 'account' !== $location ) {
        echo '<div class="twshop-visual-coupons-wrapper">';
        if ( $found_any ) {
            $dialog_id = wp_unique_id( 'twshop-coupons-dialog-' );
            echo '<button type="button" class="button twshop-coupons-open-btn" data-dialog="#' . esc_attr( $dialog_id ) . '">';
            echo esc_html( $any_applied
                ? twshop_option( 'wc_coupon_dialog_trigger_applied_text' )
                : str_replace( '{count}', intval( $card_count ), twshop_option( 'wc_coupon_dialog_trigger_none_text' ) ) );
            echo '</button>';
            echo '<dialog id="' . esc_attr( $dialog_id ) . '" class="twshop-coupons-dialog">';
            echo '<div class="twshop-coupons-dialog-head"><h3>' . esc_html( twshop_option( 'wc_coupon_dialog_heading' ) ) . '</h3><button type="button" class="twshop-coupons-dialog-close" aria-label="關閉">&times;</button></div>';
            echo '<div class="twshop-coupons-grid">' . $output_cards . '</div>';
            echo '</dialog>';
        }
        echo '</div>';
        $cache[ $location ] = ob_get_clean();
        echo $cache[ $location ];
        return;
    }

    echo '<div class="twshop-visual-coupons-wrapper">';
    if ( $found_any ) {
        echo $output_cards;
    } else {
        $no_msg = twshop_option( 'wc_general_no_coupon_msg' );
        echo '<p class="twshop-no-coupon-msg">' . esc_html($no_msg) . '</p>';
    }
    echo '</div>';
    $cache[ $location ] = ob_get_clean();
    echo $cache[ $location ];
}

/**
 * 依優惠券/規則的限制條件，算出帳戶頁「去購物」按鈕該連去哪裡：
 * 有限定商品 → 連到該商品頁；有限定分類 → 連到該分類頁；有限定標籤 → 連到該標籤頁；
 * 無限制、或條件值解析不到對應商品/分類/標籤 → 退回商店首頁。
 * 多筆限制值時一律取第一筆（多數優惠券只會限定一種分類/商品，沒有「同時連去好幾個頁面」的需求）。
 * $condition_values 可以是商品 ID（int）、或分類/標籤的 term slug（string）或 term ID（int，
 * 相容 WC_Coupon::get_product_categories() 回傳的是 term ID 而非 slug）。
 */
function twshop_get_coupon_shop_url( $condition_type, $condition_values ) {
    $condition_values = array_values( (array) $condition_values );
    $first = reset( $condition_values );

    if ( $first ) {
        if ( 'product' === $condition_type ) {
            $product_url = get_permalink( (int) $first );
            if ( $product_url ) return $product_url;
        } elseif ( 'category' === $condition_type || 'tag' === $condition_type ) {
            $taxonomy = 'tag' === $condition_type ? 'product_tag' : 'product_cat';
            $term = is_numeric( $first ) ? get_term( (int) $first, $taxonomy ) : get_term_by( 'slug', $first, $taxonomy );
            if ( $term && ! is_wp_error( $term ) ) {
                $term_url = get_term_link( $term );
                if ( ! is_wp_error( $term_url ) ) return $term_url;
            }
        }
    }

    return wc_get_page_permalink( 'shop' );
}

function twshop_generate_coupon_html( $code, $title, $desc, $expiry_date, $is_used, $is_applied, $location, $discount_text = '', $shop_url = null, $gap_html = '' ) {
    $btn_action = ''; $btn_class = ''; $btn_text = ''; $card_class = 'visual-coupon-card';

    if ( $location === 'account' ) {
        if ( $is_used ) {
            $btn_text = twshop_option( 'wc_coupon_btn_used_text' ); $btn_class = 'button'; $card_class .= ' is-used'; $btn_action = 'disabled';
        } else {
            $btn_text = twshop_option( 'wc_coupon_btn_shop_text' ); $btn_class = 'button'; $btn_action = 'link';
        }
    } else {
        // 購物車/結帳頁：$is_used 在這裡代表「目前不符合套用資格」（例如未達最低消費、
        // 已達個人使用上限等），沿用帳戶頁同一套「灰階＋按鈕停用」外觀，但文字改用
        // 「暫不可用」而非「已使用」，避免誤導成優惠券已經被用掉。
        if ( $is_used ) {
            $btn_text = twshop_option( 'wc_coupon_btn_unavailable_text' ); $btn_class = 'button'; $card_class .= ' is-used'; $btn_action = 'disabled';
        } elseif ( $is_applied ) {
            $btn_text = twshop_option( 'wc_coupon_btn_remove_text' ); $btn_action = 'remove'; $btn_class = 'button twshop-coupon-btn-remove';
        } else {
            $btn_text = twshop_option( 'wc_coupon_btn_apply_text' ); $btn_action = 'apply'; $btn_class = 'button';
        }
    }

    ob_start();
    ?>
    <div class="<?php echo esc_attr( $card_class ); ?>">
        <div class="twshop-coupon-card-main">
            <div class="twshop-coupon-card-body">
                <?php if($discount_text): ?>
                    <div class="twshop-coupon-price price"><?php echo $discount_text; ?></div>
                <?php endif; ?>
                <h4 class="twshop-coupon-title"><?php echo esc_html( $title ); ?></h4>
                <p class="twshop-coupon-desc"><?php echo esc_html( $desc ); ?></p>
                <div class="twshop-coupon-expiry">期限: <?php echo esc_html($expiry_date); ?></div>
                <span class="v-coupon-message"></span>
            </div>

            <?php if ($btn_action === 'link'): ?>
                <a href="<?php echo esc_url( $shop_url ?: wc_get_page_permalink('shop') ); ?>" class="<?php echo esc_attr($btn_class); ?> twshop-coupon-btn"><?php echo esc_html($btn_text); ?></a>
            <?php elseif ($btn_action === 'disabled'): ?>
                <button type="button" class="<?php echo esc_attr($btn_class); ?> twshop-coupon-btn" disabled><?php echo esc_html($btn_text); ?></button>
            <?php else: ?>
                <button type="button" class="<?php echo esc_attr($btn_class); ?> twshop-coupon-btn apply-v-coupon-btn" data-code="<?php echo esc_attr($code); ?>" data-action="<?php echo esc_attr($btn_action); ?>"><?php echo esc_html($btn_text); ?></button>
            <?php endif; ?>
        </div>
        <?php if ( $gap_html ) echo $gap_html; ?>
    </div>
    <?php return ob_get_clean();
}

function twshop_apply_visual_coupon() {
    check_ajax_referer( 'twshop_frontend_action', 'twshop_nonce' );
    if ( ! isset( WC()->cart ) || empty( $_POST['coupon_code'] ) ) wp_send_json_error( array( 'message' => '發生錯誤' ) );
    $code = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) );
    if ( WC()->cart->add_discount( $code ) ) {
        if ( function_exists('wc_clear_notices') && isset( WC()->session ) ) wc_clear_notices();
        wp_send_json_success( array( 'message' => '套用成功' ) );
    } else {
        // add_discount() 失敗時，WooCommerce 自己已經透過 wc_add_notice() 產生了具體原因
        // （已過期、未達最低消費、代碼不存在等）；在 wc_clear_notices() 清掉之前先擷取，
        // 避免固定用一句話蓋掉真正原因。
        $specific_message = '';
        if ( function_exists( 'wc_get_notices' ) ) {
            $error_notices = wc_get_notices( 'error' );
            if ( ! empty( $error_notices ) ) {
                $first = reset( $error_notices );
                $specific_message = is_array( $first ) ? ( $first['notice'] ?? '' ) : (string) $first;
            }
        }
        if ( function_exists('wc_clear_notices') && isset( WC()->session ) ) wc_clear_notices();
        wp_send_json_error( array( 'message' => $specific_message ?: '套用失敗，請確認優惠券代碼是否正確或是否符合使用條件。' ) );
    }
}
function twshop_remove_visual_coupon() {
    check_ajax_referer( 'twshop_frontend_action', 'twshop_nonce' );
    if ( ! isset( WC()->cart ) || empty( $_POST['coupon_code'] ) ) wp_send_json_error( array( 'message' => '發生錯誤' ) );
    $code = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) );
    WC()->cart->remove_coupon( $code );
    if ( function_exists('wc_clear_notices') && isset( WC()->session ) ) wc_clear_notices();
    wp_send_json_success( array( 'message' => '已取消套用' ) );
}

register_activation_hook( TWSHOP_PLUGIN_FILE, 'twshop_activation_cron' );
function twshop_activation_cron() {
    if ( ! wp_next_scheduled( 'wc_membership_daily_downgrade_check' ) ) {
        wp_schedule_event( twshop_next_local_midnight(), 'daily', 'wc_membership_daily_downgrade_check' );
    }
}
register_deactivation_hook( TWSHOP_PLUGIN_FILE, 'twshop_deactivation_cron' );
function twshop_deactivation_cron() { wp_clear_scheduled_hook( 'wc_membership_daily_downgrade_check' ); }

function twshop_next_local_midnight() {
    return ( new DateTime( 'tomorrow', wp_timezone() ) )->getTimestamp();
}

/**
 * 既有站台在此版本之前是以「啟用外掛當下的時間」排程（`time()`），並非固定 00:00。
 * 這裡在既有排程時間不是本地 00:00 時重新排程，讓已啟用中的網站不必停用/啟用外掛就能套用新的固定時間。
 */
function twshop_maybe_realign_daily_cron_to_midnight() {
    $timestamp = wp_next_scheduled( 'wc_membership_daily_downgrade_check' );
    if ( ! $timestamp ) {
        // 排程只在啟用外掛時建立；資料夾改名或外掛被自動停用再啟用失敗等情況下會遺失，
        // 等級重算、生日禮、點數到期全部靜默停擺，這裡補建（v25.8.107）。
        twshop_activation_cron();
        return;
    }
    if ( '00:00' !== get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), 'H:i' ) ) {
        wp_clear_scheduled_hook( 'wc_membership_daily_downgrade_check' );
        wp_schedule_event( twshop_next_local_midnight(), 'daily', 'wc_membership_daily_downgrade_check' );
    }
}
add_action( 'admin_init', 'twshop_maybe_realign_daily_cron_to_midnight' );