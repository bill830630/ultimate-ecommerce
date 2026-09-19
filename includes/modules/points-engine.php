<?php
/**
 * 5. 點數核心引擎與管理員後台管理
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 5. 點數核心引擎與管理員後台管理
// =========================================================================

// 共用的點數寫入與記錄函式
function twshop_add_points_log( $user_id, $amount, $reason, $custom_expire = null ) {
    if ( $amount == 0 ) return;
    $current_points = (int) get_user_meta( $user_id, 'twshop_reward_points', true );
    $new_points = max( 0, $current_points + $amount );
    update_user_meta( $user_id, 'twshop_reward_points', $new_points );

    $batch_expire = twshop_points_batches_apply_delta( $user_id, $amount, $custom_expire );

    $history = get_user_meta( $user_id, 'twshop_points_history', true );
    if ( ! is_array( $history ) ) $history = array();

    array_unshift( $history, array(
        'time'    => current_time('mysql'),
        'amount'  => $amount,
        'reason'  => $reason,
        'balance' => $new_points,
        // 入帳當下批次的到期日（由 twshop_points_batches_apply_delta 算出，含 custom_expire）；未啟用到期規則或扣除類紀錄一律為空字串
        'expire'  => ( $amount > 0 && $batch_expire ) ? $batch_expire : '',
    ));

    $history = array_slice( $history, 0, 100 ); // 保留最新 100 筆紀錄
    update_user_meta( $user_id, 'twshop_points_history', $history );
}

/**
 * 紅利點數頁「匯入點數資料」的 AJAX handler：CSV 逐行比對 Email 找出現有會員，
 * 把點數欄位疊加到該會員目前餘額上（走 twshop_add_points_log()，跟手動加點/消費回饋
 * 走同一套邏輯，同步寫入異動紀錄與到期批次）。找不到會員/格式錯誤的行略過並回傳清單，
 * 不會讓整批匯入因單一行有問題而中斷。
 *
 * 單次上限 5000 行，是避免單一請求跑太久超過 PHP max_execution_time 的保守防線，
 * 而非資料筆數本身有什麼特殊意義——超過上限時停在該行，已處理的行不會回滾，
 * 回傳 truncated 旗標讓前端提示使用者把剩餘資料另存新檔再次匯入。
 */
function twshop_ajax_import_points_csv() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'msg' => '權限不足。' ) );
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );

    if ( empty( $_FILES['csv_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv_file']['tmp_name'] ) ) {
        wp_send_json_error( array( 'msg' => '請選擇要匯入的 CSV 檔案。' ) );
    }

    $handle = fopen( $_FILES['csv_file']['tmp_name'], 'r' );
    if ( ! $handle ) {
        wp_send_json_error( array( 'msg' => '無法讀取上傳的檔案。' ) );
    }

    $line_cap  = 5000;
    $imported  = 0;
    $skipped   = array();
    $line_no   = 0;
    $truncated = false;

    while ( ( $row = fgetcsv( $handle ) ) !== false ) {
        if ( 1 === count( $row ) && '' === trim( (string) $row[0] ) ) continue; // 空行

        $line_no++;
        if ( $line_no > $line_cap ) { $truncated = true; break; }

        $email  = trim( (string) ( $row[0] ?? '' ) );
        $points = trim( (string) ( $row[1] ?? '' ) );
        $reason = trim( (string) ( $row[2] ?? '' ) );

        // 略過標題列：第一行且第一欄不含 @，視為 "email,points,備註" 這種欄位說明列。
        if ( 1 === $line_no && false === strpos( $email, '@' ) ) continue;

        if ( '' === $email || ! is_email( $email ) ) {
            $skipped[] = array( 'line' => $line_no, 'email' => $email, 'reason' => 'Email 格式錯誤' );
            continue;
        }

        $points = (int) $points;
        if ( $points <= 0 ) {
            $skipped[] = array( 'line' => $line_no, 'email' => $email, 'reason' => '點數必須為正整數' );
            continue;
        }

        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            $skipped[] = array( 'line' => $line_no, 'email' => $email, 'reason' => '找不到對應會員' );
            continue;
        }

        twshop_add_points_log( $user->ID, $points, '' !== $reason ? $reason : '資料匯入' );
        $imported++;
    }

    fclose( $handle );

    wp_send_json_success( array(
        'imported'  => $imported,
        'skipped'   => $skipped,
        'truncated' => $truncated,
    ) );
}

/**
 * 「匯入點數資料」面板的「下載範例 CSV」連結 handler（admin-post.php，非 AJAX——
 * 純粹輸出檔案下載，不是本外掛其他後台操作慣用的 wp_ajax_* JSON 回應）。
 * 內容只有標題列 ＋ 一行範例資料，讓管理員照著格式填自己的資料，
 * 而不是先看一大段文字說明再自己猜欄位順序。
 *
 * UTF-8 BOM 是刻意加的：純 UTF-8 CSV 在 Excel（Windows 版）直接開啟時，中文欄位
 * （備註）會被誤判成 Big5 而變亂碼，加 BOM 能讓 Excel 正確辨識編碼；文字編輯器/
 * 其他試算表軟體開啟不受 BOM 影響。
 */
function twshop_download_points_import_template() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足。' );
    check_admin_referer( 'twshop_download_points_import_template' );

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="twshop-points-import-template.csv"' );

    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    echo "email,points,備註\r\n";
    echo "member@example.com,100,資料轉入\r\n";
    exit;
}

function twshop_get_points_batches( $user_id ) {
    $batches = get_user_meta( $user_id, 'twshop_points_batches', true );
    return is_array( $batches ) ? $batches : array();
}

/**
 * 依「點數有效期限」設定維護每位會員的點數批次（每筆入帳各自的到期日）。
 * 入帳（$amount > 0）建立新批次；扣除（$amount < 0）依到期日由舊到新（FIFO）扣抵，
 * 沒有到期日（例如啟用期限前已入帳）的批次一律最後才扣。
 * 未設定有效期限（wc_points_expiry_days = 0）時不建立/維護批次，避免無謂的 user meta 讀寫。
 */
/**
 * @return string|null 新入帳批次的到期日（$amount > 0 時），其餘情況（扣除、或未啟用到期規則）回傳 null
 */
function twshop_points_batches_apply_delta( $user_id, $amount, $custom_expire = null ) {
    $expiry_days = (int) get_option( 'wc_points_expiry_days', 0 );
    if ( $expiry_days <= 0 ) return null;

    $batches = twshop_get_points_batches( $user_id );
    $expire  = null;

    if ( $amount > 0 ) {
        $today  = wp_date( 'Y-m-d' );
        $expire = $custom_expire ? $custom_expire : date( 'Y-m-d', strtotime( $today . " +{$expiry_days} days" ) );
        $batches[] = array(
            'amount'   => $amount,
            'earned'   => $today,
            'expire'   => $expire,
            'notified' => false,
        );
    } else {
        $remaining = abs( $amount );
        usort( $batches, function( $a, $b ) {
            if ( $a['expire'] === '' ) return 1;
            if ( $b['expire'] === '' ) return -1;
            return strcmp( $a['expire'], $b['expire'] );
        } );
        foreach ( $batches as &$batch ) {
            if ( $remaining <= 0 ) break;
            $take = min( $batch['amount'], $remaining );
            $batch['amount'] -= $take;
            $remaining -= $take;
        }
        unset( $batch );
        $batches = array_values( array_filter( $batches, function( $b ) { return $b['amount'] > 0; } ) );
    }

    update_user_meta( $user_id, 'twshop_points_batches', $batches );
    return $expire;
}

/**
 * 回傳該會員最快到期的一筆點數批次資訊，供前台/後台顯示提醒使用。
 * @return array|null [ 'amount' => int, 'expire' => 'Y-m-d' ] 或 null（無到期設定/無批次）
 */
function twshop_get_nearest_expiring_batch( $user_id ) {
    if ( (int) get_option( 'wc_points_expiry_days', 0 ) <= 0 ) return null;

    $batches = twshop_get_points_batches( $user_id );
    $nearest = null;
    foreach ( $batches as $batch ) {
        if ( $batch['amount'] <= 0 || $batch['expire'] === '' ) continue;
        if ( $nearest === null || $batch['expire'] < $nearest['expire'] ) {
            $nearest = array( 'amount' => $batch['amount'], 'expire' => $batch['expire'] );
        }
    }
    return $nearest;
}

/**
 * 每日排程：到期批次直接扣除點數（透過 twshop_add_points_log 統一寫入餘額與歷程紀錄），
 * 即將到期（到期前 N 天，wc_points_expiry_notify_days）且尚未通知過的批次寄送 Email 提醒。
 */
function twshop_points_daily_expiry_check() {
    $expiry_days = (int) get_option( 'wc_points_expiry_days', 0 );
    if ( $expiry_days <= 0 ) return;

    $notify_days  = (int) get_option( 'wc_points_expiry_notify_days', 7 );
    $notify_subj  = get_option( 'wc_points_expiry_notify_subject', '您的' . twshop_points_term() . '即將到期' );
    // 到期提醒信件模板是全站設定值，跟迴圈內的個別會員/批次無關，讀一次即可，
    // 移出巢狀迴圈避免每個符合通知條件的批次都重複呼叫一次 get_option()。
    $notify_body_tpl = get_option( 'wc_points_expiry_notify_body', "親愛的 {name}：\n\n您有 {amount} {term}將於 {date} 到期，請把握時間使用！" );
    $today        = wp_date( 'Y-m-d' );
    $notify_until = date( 'Y-m-d', strtotime( $today . " +{$notify_days} days" ) );
    $pt           = twshop_points_term();

    // 分批處理＋只取 ID，比照 twshop_run_daily_check() 既有的分批模式，
    // 避免點數到期會員數大時單次把所有使用者 ID 一次查完（雖然只取 ID 記憶體佔用有限，
    // 仍統一比照批次上限，避免單次查詢筆數無上限）。
    $batch_size = 200;
    $paged = 1;
    do {
        $user_ids = get_users( array(
            'meta_key' => 'twshop_points_batches',
            'fields'   => 'ID',
            'number'   => $batch_size,
            'paged'    => $paged,
        ) );

        foreach ( $user_ids as $user_id ) {
            $batches = twshop_get_points_batches( $user_id );
            if ( empty( $batches ) ) continue;

            $expired_total = 0;
            // 同一位會員的 WP_User 物件在多筆批次都需要通知時只查一次即可（get_userdata()
            // 內部本身有 object cache，但仍是一次不必要的函式呼叫＋DB 往返成本，這裡改成
            // 移出巢狀迴圈、每位會員最多查一次，且只在真的需要寄信時才查，不需要通知的會員
            // 完全不會呼叫 get_userdata()）。
            $user = null;

            foreach ( $batches as &$batch ) {
                if ( $batch['amount'] <= 0 || $batch['expire'] === '' ) continue;

                if ( $batch['expire'] <= $today ) {
                    $expired_total += $batch['amount'];
                } elseif ( $notify_days > 0 && empty( $batch['notified'] ) && $batch['expire'] <= $notify_until ) {
                    if ( null === $user ) {
                        $user = get_userdata( $user_id );
                    }
                    if ( $user && $user->user_email ) {
                        $message = str_replace(
                            array( '{name}', '{amount}', '{term}', '{date}' ),
                            array( $user->display_name, $batch['amount'], $pt, $batch['expire'] ),
                            $notify_body_tpl
                        );
                        // 排入佇列而非在這個迴圈裡同步呼叫 wp_mail()（阻塞性網路呼叫），
                        // 避免會員/到期批次多時拖慢這支每日 cron 的總執行時間。
                        wp_schedule_single_event( time(), 'twshop_send_points_expiry_notice', array( $user->user_email, $notify_subj, $message ) );
                    }
                    $batch['notified'] = true;
                }
            }
            unset( $batch );

            update_user_meta( $user_id, 'twshop_points_batches', $batches );

            if ( $expired_total > 0 ) {
                twshop_add_points_log( $user_id, -$expired_total, '點數到期' );
            }
        }
        $paged++;
    } while ( count( $user_ids ) === $batch_size );
}

/**
 * twshop_points_daily_expiry_check() 排入佇列（wp_schedule_single_event）的到期提醒信
 * 實際寄送 callback，讓每日 cron 主迴圈不會被 wp_mail() 的阻塞式網路呼叫逐一拖慢。
 */
function twshop_send_points_expiry_notice_email( $to, $subject, $message ) {
    wp_mail( $to, $subject, $message );
}

/**
 * 生日只保留「月/日」，不需要年份（僅用於每年比對是否為生日當天，年份無意義）。
 * 儲存格式為 'MM-DD'；同時相容舊資料的 'YYYY-MM-DD' 格式（取字串結尾的 MM-DD）。
 */
function twshop_parse_birthday_month_day( $value ) {
    if ( preg_match( '/(\d{2})-(\d{2})$/', (string) $value, $m ) ) {
        return array( (int) $m[1], (int) $m[2] );
    }
    return array( 0, 0 );
}

function twshop_render_birthday_select_fields( $month, $day, $disabled = false ) {
    $attr = $disabled ? ' disabled style="background:#f5f5f5; cursor:not-allowed;"' : '';
    ?>
    <span class="twshop-birthday-select-row" style="display:flex; gap:8px;">
        <select name="twshop_birthday_month" id="twshop_birthday_month" class="woocommerce-Input woocommerce-Input-text"<?php echo $attr; ?>>
            <option value="">月</option>
            <?php for ( $i = 1; $i <= 12; $i++ ) : ?>
                <option value="<?php echo esc_attr( $i ); ?>" <?php selected( $month, $i ); ?>><?php echo esc_html( $i ); ?> 月</option>
            <?php endfor; ?>
        </select>
        <select name="twshop_birthday_day" id="twshop_birthday_day" class="woocommerce-Input woocommerce-Input-text"<?php echo $attr; ?>>
            <option value="">日</option>
            <?php for ( $i = 1; $i <= 31; $i++ ) : ?>
                <option value="<?php echo esc_attr( $i ); ?>" <?php selected( $day, $i ); ?>><?php echo esc_html( $i ); ?> 日</option>
            <?php endfor; ?>
        </select>
    </span>
    <?php twshop_enqueue_asset_script( 'frontend/birthday-select', array(), array() ); ?>
    <?php
}

/**
 * 註冊頁面新增生日欄位
 */
function twshop_add_birthday_field_registration() {
    $month = isset( $_POST['twshop_birthday_month'] ) ? (int) $_POST['twshop_birthday_month'] : 0;
    $day   = isset( $_POST['twshop_birthday_day'] ) ? (int) $_POST['twshop_birthday_day'] : 0;
    ?>
    <p class="form-row form-row-wide">
        <label for="twshop_birthday_month"><?php esc_html_e( '生日（月/日）', 'ultimate-ecommerce' ); ?></label>
        <?php twshop_render_birthday_select_fields( $month, $day ); ?>
    </p>
    <?php
}

/**
 * 儲存註冊頁面填寫的生日 (新會員建立時)
 */
function twshop_save_birthday_field_registration( $customer_id ) {
    $month = isset( $_POST['twshop_birthday_month'] ) ? (int) $_POST['twshop_birthday_month'] : 0;
    $day   = isset( $_POST['twshop_birthday_day'] ) ? (int) $_POST['twshop_birthday_day'] : 0;
    if ( $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 && checkdate( $month, $day, 2000 ) ) {
        update_user_meta( $customer_id, 'twshop_birthday', sprintf( '%02d-%02d', $month, $day ) );
    }
}

/**
 * 「我的帳戶 > 編輯帳戶資料」頁面顯示生日欄位 (帶入已儲存的值)
 */
function twshop_add_birthday_field_frontend() {
    $user_id  = get_current_user_id();
    $birthday = get_user_meta( $user_id, 'twshop_birthday', true );
    $locked   = ! empty( $birthday );
    list( $month, $day ) = twshop_parse_birthday_month_day( $birthday );
    ?>
    <p class="form-row form-row-wide">
        <label for="twshop_birthday_month"><?php esc_html_e( '生日（月/日）', 'ultimate-ecommerce' ); ?></label>
        <?php twshop_render_birthday_select_fields( $month, $day, $locked ); ?>
        <?php if ( $locked ) : ?>
            <span style="font-size:12px; color:#888; display:block; margin-top:4px;">生日設定後不可更改，如需更正請聯繫客服。</span>
        <?php else : ?>
            <span style="font-size:12px; color:#888; display:block; margin-top:4px;">生日設定後將無法自行更改。</span>
        <?php endif; ?>
    </p>
    <?php
}

function twshop_save_birthday_field_frontend( $user_id ) {
    if ( get_user_meta( $user_id, 'twshop_birthday', true ) ) {
        return;
    }
    $month = isset( $_POST['twshop_birthday_month'] ) ? (int) $_POST['twshop_birthday_month'] : 0;
    $day   = isset( $_POST['twshop_birthday_day'] ) ? (int) $_POST['twshop_birthday_day'] : 0;
    if ( $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 && checkdate( $month, $day, 2000 ) ) {
        update_user_meta( $user_id, 'twshop_birthday', sprintf( '%02d-%02d', $month, $day ) );
    }
}

/**
 * 使用者編輯頁的會員生日管理。v25.8.66 前這裡跟「手動增減點數」共用同一個區塊
 * （函式當時叫 `twshop_user_profile_management_ui()`），手動調整點數已搬到後台
 * 「紅利點數 ▸ 會員餘額」頁籤（`twshop_points_balances_tab()`，`page-points.php`），
 * 管理員找會員點數餘額跟調整點數現在是同一個地方，這裡只剩生日——生日是身分資料、
 * 不是點數本身，維持在使用者編輯頁比較合理，沒有跟著搬。
 */
function twshop_birthday_management_ui( $user ) {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    ?>
    <div id="twshop-birthday-management">
    <h3>會員生日管理</h3>
    <table class="form-table">
        <tr>
            <th><label>會員生日（月/日）</label></th>
            <td>
                <?php
                $birthday = get_user_meta( $user->ID, 'twshop_birthday', true );
                list( $b_month, $b_day ) = twshop_parse_birthday_month_day( $birthday );
                twshop_render_birthday_select_fields( $b_month, $b_day );
                ?>
                <p class="description">會員前台一旦設定生日即無法自行修改；管理員可在此直接修改或補登，「月」「日」皆留空白並儲存即可清空生日（清空後會員可重新自行填寫）。</p>
            </td>
        </tr>
    </table>
    </div>
    <?php
}

/**
 * 使用者編輯頁（profile.php / user-edit.php）：把生日管理區塊搬到個人資料表單最上方。
 *
 * 原本掛在 `admin_footer-user-edit.php` / `admin_footer-profile.php` 直接印出 <script>。
 * 改成載入獨立檔案之後**不能再掛那兩個 hook**——wp-admin/admin-footer.php 的順序是
 * `admin_print_footer_scripts` 先跑、`admin_footer-{hook}` 後跑，在後者裡 enqueue 的
 * 腳本永遠不會被印出來，而且不會有任何錯誤訊息，只是區塊不再被搬到最上面。
 * 因此改掛 `admin_enqueue_scripts`（頁面開始輸出之前就跑完）。
 */
function twshop_enqueue_birthday_to_top_script( $hook ) {
    if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) return;
    twshop_enqueue_asset_script( 'admin/birthday-to-top', array(), array() );
}

function twshop_save_birthday_management( $user_id ) {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return false;

    if ( isset( $_POST['twshop_birthday_month'], $_POST['twshop_birthday_day'] ) ) {
        $month = (int) $_POST['twshop_birthday_month'];
        $day   = (int) $_POST['twshop_birthday_day'];
        if ( $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 && checkdate( $month, $day, 2000 ) ) {
            update_user_meta( $user_id, 'twshop_birthday', sprintf( '%02d-%02d', $month, $day ) );
        } elseif ( $month === 0 && $day === 0 ) {
            delete_user_meta( $user_id, 'twshop_birthday' );
        }
    }
}

/**
 * 手動增減點數，唯一寫入入口。原本是使用者編輯頁表單的一部分
 * （`twshop_save_user_profile_management()` 的一段），v25.8.66 搬到後台「紅利點數 ▸
 * 會員餘額」頁籤自己的 `<form>`（`twshop_points_balances_tab()`，`page-points.php`），
 * 這支保留在 points-engine.php 純粹是因為 `twshop_add_points_log()`／點數到期批次計算
 * 邏輯本來就在這個檔案，UI 呼叫端搬去哪裡不影響這支函式的位置。
 *
 * @return true 有實際套用一筆異動；false 金額為 0、沒有動作。
 */
function twshop_apply_manual_points_adjustment( $user_id, $amount, $reason = '', $custom_expire_days = 0 ) {
    $amount = (int) $amount;
    if ( 0 === $amount ) return false;

    $reason = sanitize_text_field( $reason );
    if ( '' === $reason ) $reason = '管理員手動調整';

    $custom_expire = null;
    if ( $amount > 0 && $custom_expire_days > 0 ) {
        $custom_expire = date( 'Y-m-d', strtotime( wp_date( 'Y-m-d' ) . " +{$custom_expire_days} days" ) );
    }

    twshop_add_points_log( $user_id, $amount, $reason, $custom_expire );
    return true;
}

/**
 * 訂單完成時，依最新累計消費重新計算會員等級 (升級/降級)
 */
function twshop_trigger_on_order( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    $user_id = $order->get_customer_id();
    if ( $user_id > 0 ) {
        twshop_clear_user_spent_cache( $user_id );
        twshop_recalculate_user_tier( $user_id );
    }
}

function twshop_award_points_on_order_complete( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    $user_id = $order->get_customer_id();
    if ( ! $user_id ) return;

    if ( $order->get_meta( '_twshop_points_awarded' ) ) return;
    $order->update_meta_data( '_twshop_points_awarded', 'yes' );
    $order->save();

    $base_earn_rate = (int) get_option( 'wc_points_base_rate', 100 );
    if ( $base_earn_rate <= 0 ) $base_earn_rate = 100;

    // 儲值金商品項目（顧客購買儲值金本身）不算消費回饋點數——買了 1000 元儲值金不該被
    // 當成「消費 1000」發點數，之後真正花掉這筆儲值金買東西時，該筆消費訂單自己會再
    // 正常算一次點數，不然同一筆錢等於被算了兩次。見 CLAUDE.md「儲值金模組」一節。
    // 逐項跳過（而非整張訂單排除，v25.8.67 起）：訂單可能同時有儲值金商品與一般商品，
    // 一般商品的消費額仍要正常發點數。
    $items_data = array();
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( ! $product ) continue;
        if ( twshop_is_wallet_credit_product( $product ) ) continue;
        $items_data[] = array( 'product_id' => $product->get_id(), 'total' => $item->get_total() + $item->get_total_tax() );
    }
    // $total_excl_shipping 是「沒有設定限制獲得點數商品」時 twshop_get_earn_base_amount()
    // 直接使用的基準值，不是從 $items_data 算出來的——只跳過陣列元素不會讓它跟著減少，
    // 必須額外扣掉儲值金商品項目的金額，否則沒設限制條件的站台仍會把儲值金商品算進點數。
    $total_excl_shipping = $order->get_total() - $order->get_shipping_total() - $order->get_shipping_tax()
        - twshop_get_order_wallet_product_total( $order );
    $earn_base_amount    = twshop_get_earn_base_amount( $items_data, $total_excl_shipping );

    $base_points = floor( $earn_base_amount / $base_earn_rate );
    if ( $base_points <= 0 ) return;

    $user             = get_userdata( $user_id );
    $point_multiplier = twshop_get_user_point_multiplier( $user );
    $final_points     = floor( $base_points * $point_multiplier );
    $order->update_meta_data( '_twshop_points_awarded_amount', $final_points );
    $order->save();
    twshop_add_points_log( $user_id, $final_points, '訂單 #' . $order_id . ' 消費回饋' );
}

function twshop_can_redeem_points() {
    if ( ! WC()->cart || WC()->cart->is_empty() ) return false;

    $min_amount = (float) get_option( 'wc_points_min_cart_amount', 0 );
    if ( $min_amount > 0 && ( WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax() ) < $min_amount ) {
        return false;
    }

    list( $restrict_type, $restrict_values ) = twshop_get_typed_restriction(
        'wc_points_redeem_restrict_type', 'wc_points_redeem_restrict_values',
        array( 'category' => 'wc_points_restricted_categories' )
    );
    if ( empty( $restrict_type ) || empty( $restrict_values ) ) return true;
    $taxonomy = $restrict_type === 'tag' ? 'product_tag' : 'product_cat';

    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( has_term( $restrict_values, $taxonomy, $cart_item['product_id'] ) ) return true;
    }
    return false;
}

/**
 * 回傳點數折抵區塊的顯示狀態：
 *   false  → 不顯示區塊（購物車為空）
 *   null   → 可正常使用（顯示輸入欄）
 *   string → 不可使用的原因說明（顯示提示文字）
 */
function twshop_points_block_reason() {
    if ( ! WC()->cart || WC()->cart->is_empty() ) return false;

    $pt             = twshop_points_term();
    $user_id        = get_current_user_id();
    $applied_points = WC()->session ? (int) WC()->session->get( 'twshop_applied_points', 0 ) : 0;
    $points         = $user_id ? (int) get_user_meta( $user_id, 'twshop_reward_points', true ) : 0;

    if ( $points <= 0 && $applied_points <= 0 ) {
        return str_replace( '{term}', $pt, twshop_option( 'wc_points_no_balance_text' ) );
    }

    $min_amount = (float) get_option( 'wc_points_min_cart_amount', 0 );
    if ( $min_amount > 0 && ( WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax() ) < $min_amount ) {
        return str_replace(
            array( '{amount}', '{term}' ),
            array( strip_tags( wc_price( $min_amount ) ), $pt ),
            twshop_option( 'wc_points_min_cart_text' )
        );
    }

    list( $restrict_type, $restrict_values ) = twshop_get_typed_restriction(
        'wc_points_redeem_restrict_type', 'wc_points_redeem_restrict_values',
        array( 'category' => 'wc_points_restricted_categories' )
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
                array( '{names}', '{term}' ),
                array( esc_html( implode( '、', $term_names ) ), $pt ),
                twshop_option( 'wc_points_restricted_text' )
            );
        }
    }

    return null;
}

/**
 * 目前已經「佔用」的點數總量：現金折抵（session 的 twshop_applied_points）＋購物車內所有
 * 「用點數兌換商品」項目各自的兌換點數（cart_item_data 的 twshop_points_redeem_cost）加總。
 * 兩者都是「還沒結帳、但已經打算用掉」的點數，加總後才是目前真正可再運用的點數餘額判斷基準。
 *
 * $include_cash_discount 傳 false 時只算「兌換商品」佔用量、不含現金折抵：
 * twshop_ajax_apply_points()／twshop_apply_points_discount_fee() 在計算「現金折抵還能用多少
 * 點數」時，若把 session 裡現有的（即將被這次請求覆蓋/重算的）現金折抵值也算進佔用量，
 * 會變成自己卡住自己，永遠調不高折抵點數。
 */
function twshop_get_committed_redeem_points( $include_cash_discount = true ) {
    $total = ( $include_cash_discount && WC()->session ) ? (int) WC()->session->get( 'twshop_applied_points', 0 ) : 0;
    if ( WC()->cart ) {
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['twshop_points_redeem_cost'] ) ) $total += (int) $cart_item['twshop_points_redeem_cost'];
        }
    }
    return $total;
}

function twshop_cart_has_redeem_product( $product_id ) {
    if ( ! WC()->cart ) return false;
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( isset( $cart_item['twshop_points_redeem_product_id'] ) && (int) $cart_item['twshop_points_redeem_product_id'] === (int) $product_id ) return true;
    }
    return false;
}

/**
 * 「點數兌換商品」清單單筆設定值正規化：{type, id, points_cost}。
 *
 * v25.8.15 前只支援單一商品，儲存格式是 {product_id, points_cost}（沒有 type）。
 * 這支函式是**唯一**認得兩種格式的地方——舊資料在管理員下次於「紅利點數」頁按下
 * 儲存之前會一直是舊格式（sanitize callback 只在儲存當下跑），所有讀取路徑
 * （前台清單渲染、AJAX 兌換時查點數）都必須經過這裡，不能直接讀 $row['product_id']，
 * 否則舊資料在升級後、管理員重新儲存前會被誤判成缺 id 而整批從清單消失。
 *
 * type=category/tag 的 points_cost 不再是管理員手動填的固定值（v25.8.17 起）——同一分類
 * 底下商品價格通常不一致，全部套同一個點數兌換等同把高單價商品用低點數賤賣，見下方
 * twshop_calc_redeem_cost_from_price()。這裡的 points_cost 只對 type=product 有意義，
 * 分類/標籤的舊資料若還留著 points_cost 也直接忽略，讀取端一律重新換算。
 */
function twshop_normalize_redeemable_entry( $row ) {
    $type = isset( $row['type'] ) && in_array( $row['type'], array( 'product', 'category', 'tag' ), true ) ? $row['type'] : 'product';
    $id   = isset( $row['id'] ) ? absint( $row['id'] ) : absint( $row['product_id'] ?? 0 );
    return array(
        'type'        => $type,
        'id'          => $id,
        'points_cost' => absint( $row['points_cost'] ?? 0 ),
        // 單次兌換（單一購物車項目）最多可選的數量，v25.8.32 新增。分類/標籤展開出來的
        // 每個商品共用同一筆設定的這個上限值——這兩種類型本來就沒有「逐商品」的編輯介面，
        // 跟 points_cost 對分類/標籤沒意義、改用售價換算是同一種取捨。
        'max_qty'     => max( 1, absint( $row['max_qty'] ?? 1 ) ),
    );
}

/**
 * 兌換清單單筆項目的顯示名稱（後台編輯 UI 用，見 twshop_render_redeemable_products_field()）。
 * 商品/分類/標籤已刪除時回傳明確提示文字，不讓該筆從清單裡靜默消失——管理員才知道
 * 有一筆設定失效需要處理，而不是以為清單本來就只有這麼多筆。
 */
function twshop_get_redeemable_entry_display_name( $entry ) {
    if ( 'product' === $entry['type'] ) {
        $product = wc_get_product( $entry['id'] );
        return $product ? $product->get_name() : ( '#' . $entry['id'] . '（商品已不存在）' );
    }
    $taxonomy = 'category' === $entry['type'] ? 'product_cat' : 'product_tag';
    $term = get_term( $entry['id'], $taxonomy );
    return ( $term && ! is_wp_error( $term ) ) ? $term->name : ( '#' . $entry['id'] . '（項目已不存在）' );
}

/**
 * 依商品目前售價換算兌換所需點數，套用跟現金折抵同一個「點數折抵匯率」
 * （wc_points_redemption_rate，幾點折抵 1 元）——分類/標籤展開出來的商品各自價格不同，
 * 沒有理由全部收同一個點數，用這個站台既有的點數/金額換算比例，讓兌換成本跟著商品
 * 實際售價走，而不是管理員得手動幫分類裡每一件商品各自估一個點數。
 *
 * 無條件進位（不是四捨五入）：跟現金折抵方向相反但道理一致——折抵時
 * floor(點數/匯率) 是為了不讓店家吃虧，這裡反過來，換算成「至少要付多少點數」時
 * 無條件進位，同樣是不讓店家吃虧的方向，避免小數點後被無條件捨去變相少收點數。
 *
 * @return int 0 代表無法換算（售價為空，例如未設定售價的可變商品）
 */
function twshop_calc_redeem_cost_from_price( $price ) {
    if ( '' === $price || ! is_numeric( $price ) || (float) $price <= 0 ) return 0;
    $rate = max( 1, (float) get_option( 'wc_points_redemption_rate', 1 ) );
    return (int) ceil( (float) $price * $rate );
}

/**
 * 把「點數兌換商品」清單展開成實際商品清單：
 * - type=product：直接對應單一商品，成本用該筆設定手動填的 points_cost（管理員刻意
 *   對單一商品設定的固定兌換點數，例如促銷贈品，本來就不需要跟著售價走）。
 * - type=category/tag：展開成該分類/標籤底下所有已上架商品，成本改用
 *   twshop_calc_redeem_cost_from_price() 依each商品目前售價各自換算（v25.8.17 起，
 *   修正「整個分類套同一個點數，貴的商品被低價賤賣」的問題），售價換算不出來
 *   （例如未設定售價區間的可變商品）的商品直接跳過、不出現在清單中。
 *
 * 同一商品若被清單中多筆設定命中（例如同時在兩個分類、或分類與單一商品各設一筆），
 * 依清單順序取**第一筆命中**、後面重複的略過——管理員要讓某個商品的兌換點數跟它所屬
 * 分類的自動換算值不同，把該商品的單一商品設定排在分類設定前面即可（目前清單沒有
 * 拖曳排序，只能靠新增順序調整）。
 *
 * @return array 每筆 [ 'product' => WC_Product, 'points_cost' => int, 'max_qty' => int ]
 */
function twshop_resolve_redeemable_products( $list ) {
    $resolved = array();
    $seen     = array();

    foreach ( (array) $list as $row ) {
        $entry = twshop_normalize_redeemable_entry( $row );
        if ( $entry['id'] <= 0 ) continue;
        if ( 'product' === $entry['type'] && $entry['points_cost'] <= 0 ) continue;

        if ( 'product' === $entry['type'] ) {
            $product_ids = array( $entry['id'] );
        } else {
            // 分類/標籤底下的商品數量不設限地全撈可能拖垮頁面（客戶把整個大分類整批設成
            // 兌換品），跟商品選單下拉的既有上限（wc_get_products( ... 200 ... )，見
            // twshop_render_redeemable_products_field()）比照上限。
            $product_ids = wc_get_products( array(
                'status'    => 'publish',
                'limit'     => 200,
                'return'    => 'ids',
                'tax_query' => array( array(
                    'taxonomy' => 'category' === $entry['type'] ? 'product_cat' : 'product_tag',
                    'field'    => 'term_id',
                    'terms'    => $entry['id'],
                ) ),
            ) );
        }

        foreach ( $product_ids as $product_id ) {
            $product_id = (int) $product_id;
            if ( isset( $seen[ $product_id ] ) ) continue;
            $product = wc_get_product( $product_id );
            if ( ! $product ) continue;
            // 儲值金商品不能被設成點數兌換商品（v25.8.79 新增）：type=product 已在
            // twshop_sanitize_points_redeemable_products() 存檔時擋掉，這裡涵蓋的是
            // type=category/tag 動態展開、存檔當下驗證不到的路徑——分類/標籤底下若
            // 剛好含儲值金商品，展開時直接跳過，不列入兌換清單。
            if ( twshop_is_wallet_credit_product( $product ) ) continue;

            if ( 'product' === $entry['type'] ) {
                $cost = $entry['points_cost'];
            } else {
                $cost = twshop_calc_redeem_cost_from_price( $product->get_price() );
                if ( $cost <= 0 ) continue; // 換算不出售價（例如可變商品未設價格區間），不列入清單
            }

            $seen[ $product_id ] = true;
            $resolved[] = array( 'product' => $product, 'points_cost' => $cost, 'max_qty' => $entry['max_qty'] );
        }
    }

    return $resolved;
}

/**
 * 查詢單一商品目前的兌換點數成本＋單次可兌換數量上限（AJAX 兌換時驗證用）。跟
 * twshop_resolve_redeemable_products() 用同一套「清單順序、第一筆命中為準」邏輯與
 * 同一套成本計算規則（type=product 用手動設定值、type=category/tag 用
 * twshop_calc_redeem_cost_from_price() 依售價換算），但不需要展開整份清單、
 * 逐分類查商品——命中 type=product 直接比對 id，命中 type=category/tag 用 has_term()
 * 查單一商品是否屬於該分類/標籤即可，沒必要為了驗證一個商品而把整個分類的商品清單
 * 都撈出來。
 *
 * v25.8.32 起改回傳 cost/max_qty 兩個值（原本只回傳 cost 的
 * twshop_get_redeem_cost_for_product()，此函式當時全站唯一呼叫端就是這裡改名後的
 * twshop_ajax_redeem_points_product()，改名/改簽名沒有其他呼叫端需要同步更新）——
 * 兩者本來就是同一次查找算出來的，合併成一次回傳比另外再寫一支重複的迴圈查 max_qty
 * 更不容易兩邊查找邏輯之後改到不同步。
 *
 * @return array [ 'cost' => int, 'max_qty' => int ]，cost 為 0 代表不開放兌換
 */
function twshop_get_redeem_info_for_product( $product_id ) {
    $list = get_option( 'wc_points_redeemable_products', array() );
    foreach ( (array) $list as $row ) {
        $entry = twshop_normalize_redeemable_entry( $row );
        if ( $entry['id'] <= 0 ) continue;

        if ( 'product' === $entry['type'] ) {
            if ( $entry['points_cost'] <= 0 ) continue;
            if ( $entry['id'] === (int) $product_id ) {
                return array( 'cost' => $entry['points_cost'], 'max_qty' => $entry['max_qty'] );
            }
        } else {
            $tax = 'category' === $entry['type'] ? 'product_cat' : 'product_tag';
            if ( ! has_term( $entry['id'], $tax, $product_id ) ) continue;
            $product = wc_get_product( $product_id );
            if ( ! $product ) continue;
            $cost = twshop_calc_redeem_cost_from_price( $product->get_price() );
            if ( $cost > 0 ) return array( 'cost' => $cost, 'max_qty' => $entry['max_qty'] );
        }
    }
    return array( 'cost' => 0, 'max_qty' => 0 );
}

/**
 * 「用點數兌換商品」區塊：列出後台設定的兌換清單（分類/標籤已展開成個別商品），
 * 依目前點數餘額（扣除已佔用的部分）顯示「立即兌換」／「已兌換，取消」／
 * 「點數不足」三種狀態的按鈕。
 *
 * v25.8.18 起是購物車頁獨立掛載的區塊（掛 woocommerce_after_cart_table，緊接在加購商品
 * 後面，見 twshop_classic_cart_redeem_products()，includes/modules/cart-injection.php），
 * 不再嵌在「點數折抵」區塊（twshop_render_points_redemption_ui()）裡面——語意上這是
 * 「再選一項商品加入購物車」，放在商品列表下方比放進訂單金額摘要區塊更直覺。
 *
 * **外層固定輸出 wrapper**，即使未登入／沒有可兌換商品也一樣（比照
 * twshop_render_cart_addons() 的既有作法）：AJAX 局部刷新
 * （twshop_ajax_refresh_components()）靠這個 class 當錨點整段 replaceWith()，
 * 如果空清單時完全不輸出任何東西，前端會找不到元素可以替換，購物車數量變動後
 * 這個區塊就會卡住不再更新（不會有任何錯誤訊息，只是內容不會變）。
 *
 * v25.8.20 起版面比照「加購商品」（twshop_render_cart_addons()，
 * includes/modules/cart-injection.php）——原本是純文字清單（商品名稱＋文字按鈕），
 * 沒有商品圖片，跟加購商品區塊（真正的 WooCommerce 商品迴圈，含圖片/主題卡片樣式）
 * 視覺上不一致。改成同一套做法：用 WP_Query 建立真正的 product loop，讓主題所有
 * hooks（Blocksy ct-media-container 等）正確觸發，price html／加入購物車按鈕用
 * filter 覆蓋成點數兌換版本（顯示所需點數、按鈕觸發 twshop_redeem_points_product／
 * twshop_remove_addon 這兩支既有 AJAX handler，不是走 WooCommerce 原生的 ajax_add_to_cart，
 * 所以不需要像加購商品那樣額外 enqueue wc-add-to-cart 腳本）。
 */
function twshop_render_points_redeemable_products_section() {
    echo '<div class="twshop-points-redeem-products-wrapper">';

    if ( ! is_user_logged_in() ) {
        echo '</div>';
        return;
    }

    $list = get_option( 'wc_points_redeemable_products', array() );
    $resolved = ( empty( $list ) || ! is_array( $list ) ) ? array() : twshop_resolve_redeemable_products( $list );

    if ( empty( $resolved ) ) {
        echo '</div>';
        return;
    }

    $user_id   = get_current_user_id();
    $balance   = (int) get_user_meta( $user_id, 'twshop_reward_points', true );
    $committed = twshop_get_committed_redeem_points();
    $pt        = twshop_points_term();

    // 以 product_id 為 key 組一份查詢 map，供迴圈內取用對應的兌換點數／單次可兌換數量上限
    // （resolved 已經是依清單順序、去重後的結果，見 twshop_resolve_redeemable_products()）。
    $cost_map    = array();
    $max_qty_map = array();
    foreach ( $resolved as $row ) {
        $pid_key = $row['product']->get_id();
        $cost_map[ $pid_key ]    = (int) $row['points_cost'];
        $max_qty_map[ $pid_key ] = (int) $row['max_qty'];
    }

    // 跟 twshop_render_cart_addons() 一樣用 WP_Query 建立真正的 loop，確保主題所有
    // hooks（Blocksy ct-media-container 等）正確觸發，而不是自己拼一段陽春的清單 HTML。
    $query = new WP_Query( array(
        'post_type'              => 'product',
        'post__in'               => array_keys( $cost_map ),
        'orderby'                => 'post__in',
        'posts_per_page'         => count( $cost_map ),
        'post_status'            => 'publish',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ) );

    if ( ! $query->have_posts() ) {
        echo '</div>';
        return;
    }

    echo '<div class="twshop-points-redeem-products woocommerce">';
    echo '<h3 class="twshop-points-redeem-products-title" style="font-size:var(--wp--preset--font-size--small,14px);">' . esc_html( '用' . $pt . '兌換商品' ) . '</h3>';

    woocommerce_product_loop_start();

    while ( $query->have_posts() ) {
        $query->the_post();
        $pid         = get_the_ID();
        $product_obj = wc_get_product( $pid );
        if ( ! $product_obj ) continue;

        // 設定 global $product，讓 WooCommerce template 函式取得正確商品
        $GLOBALS['product'] = $product_obj;

        $cost      = $cost_map[ $pid ];
        $max_qty   = max( 1, $max_qty_map[ $pid ] );
        $in_cart   = twshop_cart_has_redeem_product( $pid );
        // 已在購物車中的這筆本身也算在 $committed 裡，判斷「還能不能兌換其他的」時要先加回來，
        // 否則自己會把自己判定成「不足」。這裡只檢查「至少負擔得起 1 個」，選了較大數量卻點數
        // 不夠的情況留給 twshop_ajax_redeem_points_product() 送出時再擋，不在這裡為每個可能的
        // 數量都重算一次可負擔上限（多一層複雜度，換來的只是選單少幾個選項的次要體驗差異）。
        $available = $in_cart || ( ( $balance - $committed ) >= $cost );

        // 覆蓋價格顯示：用所需點數取代原本的售價（跟加購商品覆蓋成特價劃線同一個 filter，
        // 這裡不是價格比較，直接整段換成點數文字）。
        $price_filter = function( $price_html, $prod ) use ( $pid, $cost, $pt ) {
            if ( (int) $prod->get_id() !== $pid ) return $price_html;
            return '<span class="twshop-points-redeem-cost">' . esc_html( $cost . ' ' . $pt ) . '</span>';
        };

        // 覆蓋加入購物車按鈕：換成點數兌換專屬按鈕，走 twshop_redeem_points_product／
        // twshop_remove_addon 這兩支既有 AJAX handler（見 twshop-frontend.js），
        // 不是 WooCommerce 原生的 ajax_add_to_cart（那樣會用商品原價把商品加進購物車）。
        // max_qty > 1 時，「立即兌換」按鈕前面多插入一顆數量下拉選單（1~max_qty），
        // JS 端讀取這顆下拉的值當作兌換數量一併送出（見 twshop-frontend.js）；
        // max_qty === 1（預設值，多數安裝不會去改這個新欄位）維持原本純按鈕、無下拉選單的畫面。
        $button_filter = function( $html, $prod, $args ) use ( $pid, $in_cart, $available, $max_qty, $pt ) {
            if ( (int) $prod->get_id() !== $pid ) return $html;
            if ( $in_cart ) {
                return sprintf(
                    '<button type="button" data-product_id="%d" class="button twshop-remove-addon-btn" style="background-color:#dc3232!important;color:#fff!important;border-color:#dc3232!important;">取消兌換</button>',
                    esc_attr( $pid )
                );
            }
            if ( ! $available ) {
                return sprintf( '<button type="button" class="button" disabled>%s</button>', esc_html( $pt . '不足' ) );
            }
            $qty_select = '';
            if ( $max_qty > 1 ) {
                $options = '';
                for ( $n = 1; $n <= $max_qty; $n++ ) {
                    $options .= sprintf( '<option value="%1$d">%1$d</option>', $n );
                }
                $qty_select = sprintf(
                    '<select class="twshop-redeem-qty-select" data-product_id="%d">%s</select>',
                    esc_attr( $pid ),
                    $options
                );
            }
            return $qty_select . sprintf(
                '<button type="button" data-product_id="%d" class="button twshop-redeem-product-btn">立即兌換</button>',
                esc_attr( $pid )
            );
        };

        // 強制讓目錄可見性為「隱藏」的兌換商品通過 content-product.php 的 is_visible() 檢查
        // （管理員常把純粹用來兌換的商品設成目錄隱藏，不想讓它出現在一般商店頁面）。
        $visibility_filter = function( $visible, $product_id ) use ( $pid ) {
            return ( (int) $product_id === $pid ) ? true : $visible;
        };

        add_filter( 'woocommerce_product_is_visible',    $visibility_filter, 999, 2 );
        add_filter( 'woocommerce_get_price_html',        $price_filter,      999, 2 );
        // WooCommerce 9.2+ 使用 woocommerce_loop_add_to_cart_link；舊版用 woocommerce_loop_add_to_cart_html
        add_filter( 'woocommerce_loop_add_to_cart_link', $button_filter,     999, 3 );
        add_filter( 'woocommerce_loop_add_to_cart_html', $button_filter,     999, 3 );

        // 使用 WooCommerce 標準商品模板，主題樣式（Blocksy ct-media-container 等）自動套用
        wc_get_template_part( 'content', 'product' );

        remove_filter( 'woocommerce_product_is_visible',    $visibility_filter, 999 );
        remove_filter( 'woocommerce_get_price_html',        $price_filter,      999 );
        remove_filter( 'woocommerce_loop_add_to_cart_link', $button_filter,     999 );
        remove_filter( 'woocommerce_loop_add_to_cart_html', $button_filter,     999 );
    }

    wp_reset_postdata();
    woocommerce_product_loop_end();
    echo '</div>';

    echo '</div>';
}

function twshop_render_points_redemption_ui() {
    echo '<div class="twshop-points-redemption-wrapper">';

    if ( ! is_user_logged_in() ) {
        echo '</div>';
        return;
    }

    // 「用點數兌換商品」v25.8.18 起是獨立掛載的區塊（見 twshop_render_points_redeemable_products_section()），
    // 不再嵌在這裡——這支函式現在只負責「現金折抵」。
    $reason = twshop_points_block_reason();

    if ( $reason === false ) {
        echo '</div>';
        return;
    }

    $user_id         = get_current_user_id();
    $points          = (int) get_user_meta( $user_id, 'twshop_reward_points', true );
    $applied_points  = WC()->session ? (int) WC()->session->get( 'twshop_applied_points', 0 ) : 0;
    $pt              = twshop_points_term();
    $redemption_rate = max( 1, (int) get_option( 'wc_points_redemption_rate', 1 ) );
    ?>
    <div class="twshop-points-redemption">
        <h4><?php echo esc_html( str_replace( '{term}', $pt, twshop_option( 'wc_points_ui_heading' ) ) ); ?></h4>
        <?php if ( $points > 0 ) : ?>
            <p><?php echo esc_html( str_replace( array( '{amount}', '{term}' ), array( $points, $pt ), twshop_option( 'wc_points_balance_text' ) ) ); ?></p>
        <?php endif; ?>
        <?php $nearest_expiring = twshop_get_nearest_expiring_batch( $user_id ); ?>
        <?php if ( $nearest_expiring ) : ?>
            <p class="twshop-points-notice" style="color:#b32d2e;"><?php echo esc_html( str_replace(
                array( '{amount}', '{term}', '{date}' ),
                array( $nearest_expiring['amount'], $pt, $nearest_expiring['expire'] ),
                twshop_option( 'wc_points_expiry_soon_text' )
            ) ); ?></p>
        <?php endif; ?>
        <?php if ( $reason !== null ) : ?>
            <p class="twshop-points-notice"><?php echo esc_html( $reason ); ?></p>
        <?php else : ?>
            <div class="twshop-points-input-row">
                <input type="number" inputmode="numeric" id="twshop_points_input" min="<?php echo esc_attr( $redemption_rate ); ?>" step="<?php echo esc_attr( $redemption_rate ); ?>" placeholder="<?php echo esc_attr( str_replace( array( '{term}', '{rate}' ), array( $pt, $redemption_rate ), twshop_option( 'wc_points_input_placeholder' ) ) ); ?>" max="<?php echo esc_attr( $points ); ?>" value="<?php echo esc_attr( $applied_points ?: '' ); ?>">
                <button type="button" class="button" id="twshop_apply_points_btn"><?php echo $applied_points ? esc_html( str_replace( '{term}', $pt, twshop_option( 'wc_points_btn_update_text' ) ) ) : esc_html( twshop_option( 'wc_points_btn_apply_text' ) ); ?></button>
            </div>
            <?php if ( $applied_points > 0 ) :
                list( $discount, $applied_points ) = twshop_get_points_discount_amount( $applied_points );
            ?>
                <p class="twshop-points-notice" style="color:#2271b1; margin-top:6px;"><?php echo esc_html( str_replace(
                    array( '{amount}', '{term}', '{discount}' ),
                    array( $applied_points, $pt, $discount ),
                    twshop_option( 'wc_points_applied_text' )
                ) ); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    echo '</div>';
}

/**
 * 依「單筆最高折抵上限」（wc_points_max_percent，以含稅小計計算）換算實際可用點數與折抵金額。
 * AJAX 套用點數、購物車費用計算兩處都靠這個函式，確保上限判斷只有一套邏輯。
 *
 * @return array [ $discount_amount, $capped_points ]
 */
function twshop_get_points_discount_amount( $applied_points ) {
    $redemption_rate = (float) get_option( 'wc_points_redemption_rate', 1 );
    if ( $redemption_rate <= 0 || ! WC()->cart ) {
        return array( 0, 0 );
    }

    $discount_amount = floor( $applied_points / $redemption_rate );

    $max_percent   = min( 100, (float) get_option( 'wc_points_max_percent', 30 ) );
    $cart_subtotal = WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax();
    $max_discount  = $cart_subtotal * ( $max_percent / 100 );

    // 上限另外不能超過「扣掉優惠券與其他折扣後實際還要付的商品金額」：WooCommerce 會把負費用夾到
    // 總額不低於 0，超出的部分沒有真的折到錢，點數卻照扣（v25.8.36 修正）。點數費用在 priority 25，
    // 此時優惠券與 twshop 購物車層折扣（priority 20）都已經算好。
    $payable = $cart_subtotal - WC()->cart->get_discount_total() - WC()->cart->get_discount_tax();
    foreach ( WC()->cart->get_fees() as $fee ) {
        if ( $fee->amount < 0 && $fee->name !== twshop_points_term() . '折抵' ) $payable += (float) $fee->amount;
    }
    $max_discount = max( 0, min( $max_discount, floor( $payable ) ) );

    if ( $discount_amount > $max_discount ) {
        $discount_amount = $max_discount;
        $applied_points  = floor( $discount_amount * $redemption_rate );
    }

    return array( $discount_amount, $applied_points );
}

function twshop_ajax_apply_points() {
    check_ajax_referer( 'twshop_frontend_action', 'twshop_nonce' );
    $requested_points = isset( $_POST['points'] ) ? absint( $_POST['points'] ) : 0;
    $user_id = get_current_user_id();
    $user_points = (int) get_user_meta( $user_id, 'twshop_reward_points', true );

    // 扣掉購物車裡「用點數兌換商品」已經佔用的點數，避免現金折抵跟兌換商品
    // 各自獨立驗證、加總卻超過實際餘額（不含 session 現有的現金折抵本身，見
    // twshop_get_committed_redeem_points() 的參數說明）。
    $redeem_committed = twshop_get_committed_redeem_points( false );
    $available_points = max( 0, $user_points - $redeem_committed );

    $points = min( $requested_points, $available_points );

    $redemption_rate = (int) get_option( 'wc_points_redemption_rate', 1 );
    if ( $redemption_rate > 1 ) {
        $points = floor( $points / $redemption_rate ) * $redemption_rate;
    }

    // 輸入的點數不足一個折抵倍率，會被無聲無息地捨去成 0，需明確告知客戶原因，而不是靜默改回未套用狀態
    if ( $requested_points > 0 && 0 === $points ) {
        wp_send_json_error( array(
            'message' => sprintf( '%s折抵需為 %d 點的倍數，最少需要 %d 點才能折抵。', twshop_points_term(), $redemption_rate, $redemption_rate ),
        ) );
    }

    if ( $points > 0 ) {
        list( , $points ) = twshop_get_points_discount_amount( $points );
    }

    if ( $points > 0 ) {
        WC()->session->set( 'twshop_applied_points', $points );
    } else {
        WC()->session->__unset( 'twshop_applied_points' );
    }

    // WC 在下次頁面載入時，若購物車內容沒變，只會從 session 的 cart_totals 快照還原總計，
    // 不會重新觸發 woocommerce_cart_calculate_fees；這裡強制重算一次，
    // 讓快照立刻反映最新的點數折抵費用，避免小計下方金額與此處回傳的 actual_points 對不上。
    WC()->cart->calculate_totals();

    wp_send_json_success( array( 'actual_points' => $points ) );
}

/**
 * 「立即兌換」：把兌換清單裡的商品以 $0 加入購物車，標記 twshop_points_redeem_product_id/
 * twshop_points_redeem_cost 供 twshop_zero_redeemed_product_price() 歸零售價、
 * twshop_deduct_points_on_checkout() 結帳時扣點。點數本身在這裡不扣，比照現金折抵
 * （twshop_ajax_apply_points()）的既有做法，實際扣點延後到結帳完成，顧客改變主意
 * 移除購物車項目就不會真的損失點數。
 *
 * v25.8.32 起支援單次兌換數量（1~該筆設定的 max_qty，見 twshop_get_redeem_info_for_product()）：
 * qty 直接乘上單位點數存進 twshop_points_redeem_cost（這個 meta 存的是「整個購物車項目」的
 * 總點數，不是單價）——twshop_get_committed_redeem_points()／twshop_deduct_points_on_checkout()
 * 兩處既有邏輯本來就只是單純加總這個 meta、完全不看數量，存成總額而不是單價，這兩處
 * 下游都不需要跟著改。max_qty 本身也一併存進 cart item meta（twshop_points_redeem_max_qty），
 * 讓 twshop_zero_redeemed_product_price() 之後鎖定數量時不用再查一次 option。
 */
function twshop_ajax_redeem_points_product() {
    check_ajax_referer( 'twshop_frontend_action', 'twshop_nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => '請先登入' ) );
    }

    $product_id = absint( $_POST['product_id'] ?? 0 );
    $info       = twshop_get_redeem_info_for_product( $product_id );
    $unit_cost  = $info['cost'];
    $max_qty    = max( 1, $info['max_qty'] );
    if ( $unit_cost <= 0 ) {
        wp_send_json_error( array( 'message' => '此商品不開放' . twshop_points_term() . '兌換' ) );
    }

    // 數量來自前台下拉選單（1~max_qty，見 twshop_render_points_redeemable_products_section()），
    // 這裡是防止直接偽造 POST 送超過上限的最後防線，夾在合法範圍內，不特別回錯誤——
    // 正常操作路徑本來就選不出超過上限的值，沒必要為了這個異常路徑多寫一則錯誤訊息。
    $qty = absint( $_POST['qty'] ?? 1 );
    $qty = max( 1, min( $qty, $max_qty ) );

    if ( twshop_cart_has_redeem_product( $product_id ) ) {
        wp_send_json_error( array( 'message' => '此商品已在購物車中' ) );
    }

    $total_cost = $unit_cost * $qty;

    $balance   = (int) get_user_meta( get_current_user_id(), 'twshop_reward_points', true );
    $committed = twshop_get_committed_redeem_points();
    if ( ( $balance - $committed ) < $total_cost ) {
        wp_send_json_error( array( 'message' => twshop_points_term() . '不足，無法兌換' ) );
    }

    // 兌換商品本身被 twshop_restrict_purchase_for_redeem_and_gift_products()
    // （includes/helpers.php）設成不可直接購買，這裡是唯一允許把它加入購物車的合法管道，
    // 用 bypass 旗標跳過那道限制，否則 add_to_cart() 會自己擋自己。
    twshop_bypass_purchase_restriction( true );
    try {
        $added = WC()->cart->add_to_cart( $product_id, $qty, 0, array(), array(
            'twshop_points_redeem_product_id' => $product_id,
            'twshop_points_redeem_cost'       => $total_cost,
            'twshop_points_redeem_max_qty'    => $max_qty,
        ) );
    } finally {
        twshop_bypass_purchase_restriction( false );
    }
    if ( ! $added ) {
        // WC()->cart->add_to_cart() 失敗時（缺貨、可變商品沒給 variation_id 等）內部會用
        // wc_add_notice() 寫一則具體原因到 WC session 的通知佇列、自己只回傳 false，不會拋出
        // 例外讓這裡接到。直接讀那則通知取代寫死的「商品可能已下架或缺貨」，訊息才會對得上
        // 真正的原因（例如可變商品未選規格），而不是每次失敗都顯示同一句不一定正確的猜測。
        $notices = wc_get_notices( 'error' );
        $message = ! empty( $notices ) ? wp_strip_all_tags( end( $notices )['notice'] ) : ( '加入購物車失敗，商品可能已下架或缺貨' );
        wc_clear_notices(); // 這則通知是給這次 AJAX 回應用的，不清掉會在顧客下次刷新頁面時意外冒出來
        wp_send_json_error( array( 'message' => $message ) );
    }

    // 防呆：WC()->cart->add_to_cart() 的 $quantity 參數不保證一定照實加入——商品若被管理員
    // 另外勾選「售完限購一件」（sold_individually），WooCommerce 核心會直接無聲把數量壓成 1，
    // 完全不管這裡傳的 $qty 是多少。若不在這裡回頭核對，會出現「顧客選了 2 個、扣了 2 個的
    // 點數，購物車卻只真的加進 1 個」的落差——用實際加入的數量重新核算並覆寫
    // twshop_points_redeem_cost，讓扣點金額永遠對得上購物車裡真正拿到的數量。
    $actual_qty = isset( WC()->cart->cart_contents[ $added ]['quantity'] ) ? (int) WC()->cart->cart_contents[ $added ]['quantity'] : $qty;
    if ( $actual_qty !== $qty ) {
        WC()->cart->cart_contents[ $added ]['twshop_points_redeem_cost'] = $unit_cost * $actual_qty;
        WC()->cart->set_session();
    }

    wp_send_json_success( array( 'message' => '兌換成功' ) );
}

/**
 * 把「用點數兌換商品」的購物車項目售價強制歸零，並把數量鎖在加入購物車當下決定好的
 * 數量（不能再被改動——避免透過修改購物車數量無限取得免費商品）。獨立於 discount_rules
 * 模組的 twshop_auto_manage_gifts_and_addons() 之外——兌換商品是 points 模組自己的功能，
 * 不應該依賴 discount_rules 模組是否啟用。
 *
 * v25.8.32 起數量上限不再寫死 1，改夾在加入購物車當下存進 cart item meta 的
 * twshop_points_redeem_max_qty（見 twshop_ajax_redeem_points_product()）——這是
 * 唯一合法的加入管道，數量選擇只在那個時間點發生一次，購物車頁的數量欄位本身沒有
 * 輸入框可以再改（見下方 twshop_lock_redeemed_item_quantity()），這裡的 set_quantity()
 * 純粹是防線，擋掉透過購物車更新端點直接偽造請求的異常路徑。缺這個 meta 的舊購物車項目
 * （部署當下已經在顧客購物車 session 裡的舊資料）退回舊版行為鎖 1，避免誤判成無上限。
 */
function twshop_zero_redeemed_product_price( $cart_obj ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    foreach ( $cart_obj->get_cart() as $cart_item_key => $cart_item ) {
        if ( ! isset( $cart_item['twshop_points_redeem_product_id'] ) ) continue;
        $max_qty = isset( $cart_item['twshop_points_redeem_max_qty'] )
            ? max( 1, (int) $cart_item['twshop_points_redeem_max_qty'] )
            : 1;
        if ( (int) $cart_item['quantity'] > $max_qty ) {
            $cart_obj->set_quantity( $cart_item_key, $max_qty, false );
        }
        $cart_item['data']->set_price( 0 );
    }
}

// 購物車頁「用點數兌換商品」項目的數量欄位改成純文字顯示（顯示加入時決定好的實際數量，
// 不是永遠顯示 1），避免顧客透過原生數量輸入框把免費商品的數量調大；伺服器端
// twshop_zero_redeemed_product_price() 仍會強制夾住上限，這裡只是同步前端顯示，
// 避免出現「畫面上能改、但改了沒有用」的落差。
function twshop_lock_redeemed_item_quantity( $product_quantity, $cart_item_key, $cart_item ) {
    if ( isset( $cart_item['twshop_points_redeem_product_id'] ) ) {
        return '<span class="twshop-redeem-qty">' . esc_html( $cart_item['quantity'] ) . '</span>';
    }
    return $product_quantity;
}

// 結帳時把購物車項目的 twshop_points_redeem_cost 複製進對應訂單項目的 meta，
// twshop_deduct_points_on_checkout() 才能在訂單建立後讀到兌換成本、正確扣點。
function twshop_save_points_redeem_order_item_meta( $item, $cart_item_key, $values, $order ) {
    if ( isset( $values['twshop_points_redeem_cost'] ) ) {
        $item->add_meta_data( '_twshop_points_redeem_cost', (int) $values['twshop_points_redeem_cost'], true );
    }
}

// 結帳送出前的最後防線：購物車內容加入後到送出結帳這段時間，會員點數可能因到期/其他訂單
// 折抵而減少，這裡重新核對「現金折抵＋兌換商品」合計是否仍在餘額之內，避免結帳成功後
// twshop_deduct_points_on_checkout() 把點數扣成負值（雖然該函式本身也有下限 0 保護，
// 但那樣會讓顧客拿到不該拿到的兌換商品，屬於系統邊界應該擋下的情況，非事後補救即可）。
function twshop_validate_points_redeem_balance( $data, $errors ) {
    if ( ! is_user_logged_in() ) return;
    $committed = twshop_get_committed_redeem_points();
    if ( $committed <= 0 ) return;
    $balance = (int) get_user_meta( get_current_user_id(), 'twshop_reward_points', true );
    if ( $committed > $balance ) {
        $errors->add( 'validation', twshop_points_term() . '餘額不足以完成本次折抵/兌換，請重新確認購物車。' );
    }
}

/**
 * 這是「現金折抵點數＋兌換商品佔用點數」超過餘額的最後一道、也是唯一真正堵住漏洞的防線：
 * WooCommerce 核心在 add_to_cart/cart_item_removed/applied_coupon 等動作都會自動重跑
 * calculate_totals()，所以只要購物車內容或現金折抵有任何變動，這裡都會重新核對一次，
 * 而不只是 twshop_ajax_apply_points() 那次 AJAX 當下算過就沒事。
 */
function twshop_apply_points_discount_fee( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    if ( ! twshop_can_redeem_points() ) return;

    $applied_points = (int) WC()->session->get( 'twshop_applied_points', 0 );
    if ( $applied_points <= 0 ) return;

    $user_id          = get_current_user_id();
    $user_points      = $user_id ? (int) get_user_meta( $user_id, 'twshop_reward_points', true ) : 0;
    $redeem_committed = twshop_get_committed_redeem_points( false );
    $available        = max( 0, $user_points - $redeem_committed );

    if ( $applied_points > $available ) {
        $applied_points = $available;
    }

    if ( $applied_points <= 0 ) {
        WC()->session->__unset( 'twshop_applied_points' );
        return;
    }

    list( $discount_amount, $actual_applied ) = twshop_get_points_discount_amount( $applied_points );

    if ( $actual_applied !== (int) WC()->session->get( 'twshop_applied_points', 0 ) ) {
        WC()->session->set( 'twshop_applied_points', $actual_applied );
    }

    $cart->add_fee( twshop_points_term() . '折抵', -$discount_amount, false );
}

/**
 * 建立訂單當下記下這張訂單實際用掉的現金折抵點數（此時 fee 已依餘額/上限算完，session 值即實際值）。
 * 付款失敗後重新送出結帳時 WooCommerce 會重用同一張訂單，這個 hook 每次都會重新寫入。
 */
function twshop_store_points_cash_applied_on_order( $order ) {
    $points = WC()->session ? (int) WC()->session->get( 'twshop_applied_points', 0 ) : 0;
    $order->update_meta_data( '_twshop_points_cash_applied', max( 0, $points ) );
}

function twshop_clear_applied_points_on_cart_emptied() {
    if ( WC()->session ) WC()->session->__unset( 'twshop_applied_points' );
}

/**
 * 結帳扣點，**冪等**：付款失敗/從金流返回後重新送出，WooCommerce 會重用同一張 pending/failed 訂單並
 * 再次觸發這個 hook。這裡以「這張訂單目前應扣總點數」對照「已淨扣點數」（已扣 − 已退還），只記差額；
 * session 裡的現金折抵點數也不在這裡清掉（改在購物車清空時清），重送時折抵才不會消失（v25.8.35 修正：
 * 原本每次觸發都全額再扣一次，且第二次沒有折抵卻已扣點）。
 *
 * 「用點數兌換商品」的兌換成本合併進同一筆 _twshop_points_redeemed meta，退款/取消的退還邏輯
 * 讀同一個 meta，兩種來源一起被涵蓋。
 */
function twshop_deduct_points_on_checkout( $order_id, $posted_data, $order ) {
    $user_id = $order->get_customer_id();
    if ( ! $user_id ) return;

    $cash_points = (int) $order->get_meta( '_twshop_points_cash_applied' );
    $redeem_points_total = 0;
    foreach ( $order->get_items() as $item ) {
        $redeem_points_total += (int) $item->get_meta( '_twshop_points_redeem_cost' );
    }
    $target = $cash_points + $redeem_points_total;

    $recorded = (int) $order->get_meta( '_twshop_points_redeemed' );
    $refunded = $order->get_meta( '_twshop_points_redeemed_refunded' )
        ? $recorded
        : min( $recorded, (int) $order->get_meta( '_twshop_points_redeemed_refunded_amount' ) );
    $net_deducted = $recorded - $refunded;

    if ( $target <= 0 && $recorded <= 0 ) return;

    $delta = $target - $net_deducted;
    if ( 0 !== $delta ) {
        $label = $delta > 0
            ? ( '訂單 #' . $order_id . ' ' . twshop_points_term() . ( $redeem_points_total > 0 ? '折抵/兌換商品' : '折抵' ) )
            : ( '訂單 #' . $order_id . ' 重新結帳，' . twshop_points_term() . '差額退還' );
        twshop_add_points_log( $user_id, -$delta, $label );
    }

    $order->update_meta_data( '_twshop_points_redeemed', $target );
    $order->delete_meta_data( '_twshop_points_redeemed_refunded' );
    $order->delete_meta_data( '_twshop_points_redeemed_refunded_amount' );
    $order->save();
}

/**
 * 訂單取消／退款／付款失敗時：
 * 1. 把該筆訂單當初折抵扣除的點數退還給會員（`_twshop_points_redeemed_refunded` 標記避免重複退還）
 * 2. 若訂單先前已進入 completed 狀態並發放過消費回饋點數，追回該筆已發放的點數
 *    （`_twshop_points_awarded_revoked` 標記避免重複追回）——涵蓋「訂單完成後才發現有問題而退款」的情境
 * 兩者互相獨立，一筆訂單可能只符合其中一種、兩種都符合、或都不符合（例如根本沒用點數、也還沒進入 completed）。
 * 點數餘額本身在 `twshop_add_points_log()` 內部就有下限 0 的保護，若會員已把追回的點數花掉，餘額最多歸零、不會出現負值。
 */
function twshop_refund_points_on_order_cancel( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    $user_id = $order->get_customer_id();
    if ( ! $user_id ) return;

    // 只處理「尚未被部分退款處理過」的差額：先前 twshop_handle_order_refund_points() 已按比例
    // 退還/追回的部分記在 *_amount 進度 meta，這裡若直接用全額會重複退還（v25.8.34 修正）。
    twshop_complete_points_reversal(
        $order_id, $user_id,
        '_twshop_points_redeemed', '_twshop_points_redeemed_refunded_amount', '_twshop_points_redeemed_refunded',
        1, '訂單 #' . $order_id . ' 取消/退款，' . twshop_points_term() . '折抵退還'
    );
    twshop_complete_points_reversal(
        $order_id, $user_id,
        '_twshop_points_awarded_amount', '_twshop_points_awarded_revoked_amount', '_twshop_points_awarded_revoked',
        -1, '訂單 #' . $order_id . ' 取消/退款，追回消費回饋' . twshop_points_term()
    );
}

function twshop_complete_points_reversal( $order_id, $user_id, $base_meta, $progress_meta, $done_flag_meta, $sign, $reason ) {
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_meta( $done_flag_meta ) ) return;

    $base = (int) $order->get_meta( $base_meta );
    if ( $base <= 0 ) return;

    $already = (int) $order->get_meta( $progress_meta );
    $delta   = $base - $already;

    $order->update_meta_data( $done_flag_meta, 'yes' );
    $order->update_meta_data( $progress_meta, $base );
    $order->save();
    if ( $delta > 0 ) {
        twshop_add_points_log( $user_id, $sign * $delta, $reason );
    }
}

/**
 * WooCommerce 部分退款（後台訂單頁按「退款」但不一定改變訂單狀態）時，
 * 依「本次退款金額 / 訂單原始總額」的比例，按比例退還折抵點數／追回已發放回饋點數。
 * 掛在 `woocommerce_order_refunded`，每建立一筆退款（不論部分或全額）都會觸發一次；
 * 用 `_twshop_points_redeemed_refunded_amount`/`_twshop_points_awarded_revoked_amount`
 * 記錄「累計已處理」的點數，多次部分退款時只補上與上次相比新增的差額，不會重複退還/追回。
 * 若同一張訂單先前已透過 `twshop_refund_points_on_order_cancel()`（訂單狀態變化）全額處理過
 * （`_twshop_points_redeemed_refunded`/`_twshop_points_awarded_revoked` 旗標已是 `'yes'`），
 * 這裡會直接跳過對應那一半，避免兩套機制重複退還/追回同一筆點數。
 */
function twshop_handle_order_refund_points( $order_id, $refund_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    $user_id = $order->get_customer_id();
    if ( ! $user_id ) return;

    $refund = wc_get_order( $refund_id );
    if ( ! $refund ) return;

    // WC_Order_Refund::get_total() 存的是負數（代表退款金額），取絕對值還原成正數的退款金額
    $refunded_amount = abs( (float) $refund->get_total() );
    if ( $refunded_amount <= 0 ) return;

    $order_total = (float) $order->get_total();
    if ( $order_total <= 0 ) return;

    $proportion = min( 1, $refunded_amount / $order_total );

    twshop_apply_proportional_points_reversal(
        $order_id, $user_id, $proportion,
        '_twshop_points_redeemed', '_twshop_points_redeemed_refunded_amount', '_twshop_points_redeemed_refunded',
        1, twshop_points_term() . '折抵退還'
    );
    twshop_apply_proportional_points_reversal(
        $order_id, $user_id, $proportion,
        '_twshop_points_awarded_amount', '_twshop_points_awarded_revoked_amount', '_twshop_points_awarded_revoked',
        -1, '追回消費回饋' . twshop_points_term()
    );
}

/**
 * @param string $base_meta      原始點數數量的 meta key（`_twshop_points_redeemed` 或 `_twshop_points_awarded_amount`）
 * @param string $progress_meta  累計已處理數量的 meta key
 * @param string $done_flag_meta 是否已「全額」處理過的旗標 meta key（與 twshop_refund_points_on_order_cancel() 共用同一把旗標）
 * @param int    $sign           1 = 加回會員點數（折抵退還），-1 = 扣回會員點數（追回回饋）
 */
function twshop_apply_proportional_points_reversal( $order_id, $user_id, $proportion, $base_meta, $progress_meta, $done_flag_meta, $sign, $reason_label ) {
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_meta( $done_flag_meta ) ) return;

    $base = (int) $order->get_meta( $base_meta );
    if ( $base <= 0 ) return;

    $already = (int) $order->get_meta( $progress_meta );
    $target  = (int) floor( $base * $proportion );
    $delta   = $target - $already;
    if ( $delta <= 0 ) return;

    $order->update_meta_data( $progress_meta, $already + $delta );
    if ( $target >= $base ) {
        $order->update_meta_data( $done_flag_meta, 'yes' );
    }
    $order->save();

    twshop_add_points_log( $user_id, $sign * $delta, '訂單 #' . $order_id . ' 部分退款（' . round( $proportion * 100 ) . '%），' . $reason_label );
}

/**
 * 購物車/結帳頁總計表格下方顯示「本次訂單使用OO點數」，數字是現金折抵（session 的
 * twshop_applied_points）＋購物車內所有兌換商品成本的合計，直接沿用
 * twshop_get_committed_redeem_points()（已存在、原本是拿來判斷「還能不能再兌換」的
 * 上限基準，這裡只是換個地方顯示同一個數字）。
 *
 * 兩處都掛（v25.8.22 起，原本只掛結帳頁）：現金折抵在兩頁都已經有「積分折抵 -$Y」金額列
 * （twshop_apply_points_discount_fee()）可以看，這裡要補的是「到底扣了幾點」這個數字
 * 本身——尤其「用點數兌換商品」那部分完全沒有對應金額列（商品直接歸零售價），顧客在
 * 購物車階段就想知道這筆訂單會扣多少點，不用等到結帳頁才看到。
 *
 * priority 5（早於 twshop_display_estimated_points_earn() 的預設 priority 10，兩個掛載點都是）：
 * 「本次使用」（成本）排在「預估獲得」（回饋）前面，閱讀順序上先看花費、再看回饋。
 */
function twshop_display_points_used() {
    if ( ! is_user_logged_in() ) return;
    if ( ! WC()->cart || WC()->cart->is_empty() ) return;

    $used = twshop_get_committed_redeem_points();
    if ( $used <= 0 ) return;

    $pt = twshop_points_term();
    ?>
    <tr class="twshop-points-used">
        <th><?php echo esc_html( '本次訂單使用' . $pt ); ?></th>
        <td data-title="<?php echo esc_attr( '本次訂單使用' . $pt ); ?>"><strong style="color:#2271b1;">-<?php echo esc_html( $used ); ?> <?php echo esc_html( $pt ); ?></strong></td>
    </tr>
    <?php
}

/**
 * 在購物車/結帳頁的總計表格下方顯示此筆消費預估可獲得的點數
 * 計算邏輯與 twshop_award_points_on_order_complete() 完全一致
 */
function twshop_display_estimated_points_earn() {
    if ( ! is_user_logged_in() ) return;
    if ( ! WC()->cart || WC()->cart->is_empty() ) return;

    $base_earn_rate = (int) get_option( 'wc_points_base_rate', 100 );
    if ( $base_earn_rate <= 0 ) $base_earn_rate = 100;

    $items_data = array();
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        $total  = isset( $cart_item['line_total'] ) ? $cart_item['line_total'] : ( $cart_item['data']->get_price() * $cart_item['quantity'] );
        $total += isset( $cart_item['line_tax'] ) ? $cart_item['line_tax'] : 0;
        $items_data[] = array( 'product_id' => $cart_item['product_id'], 'total' => $total );
    }
    $cart_total_excl_shipping = WC()->cart->get_total( 'edit' ) - WC()->cart->get_shipping_total() - WC()->cart->get_shipping_tax();
    $earn_base_amount        = twshop_get_earn_base_amount( $items_data, $cart_total_excl_shipping );

    $base_points = floor( $earn_base_amount / $base_earn_rate );
    if ( $base_points <= 0 ) return;

    $user             = wp_get_current_user();
    $point_multiplier = twshop_get_user_point_multiplier( $user );
    $final_points     = floor( $base_points * $point_multiplier );
    if ( $final_points <= 0 ) return;

    $multiplier_note = ( $point_multiplier > 1 ) ? ' <small style="opacity:0.7;">(' . rtrim( rtrim( number_format( $point_multiplier, 1 ), '0' ), '.' ) . 'x 會員加倍)</small>' : '';
    ?>
    <tr class="twshop-estimated-points">
        <th>預估獲得<?php echo esc_html( twshop_points_term() ); ?></th>
        <td data-title="<?php echo esc_attr( '預估獲得' . twshop_points_term() ); ?>"><strong style="color:#d68a00;">+<?php echo esc_html( $final_points ); ?> <?php echo esc_html( twshop_points_term() ); ?></strong><?php echo $multiplier_note; ?></td>
    </tr>
    <?php
}

