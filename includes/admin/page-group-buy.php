<?php
/** 團購後台：團購主、佣金紀錄與設定。 */
if ( ! defined( 'ABSPATH' ) ) exit;

function twshop_group_buy_section() {
    $tabs = array(
        'leaders'     => '團購主',
        'commissions' => '佣金紀錄',
        'settings'    => '設定',
    );
    $current = twshop_get_current_admin_tab( $tabs );
    twshop_render_admin_tabs( $tabs, $current, 'wc-general-settings', array( 'section' => 'group-buy' ) );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足。' );
    // 團購頁表格欄位多，外層放寬（.twshop-admin-wrap:has(.twshop-gb-section)）。
    echo '<div class="twshop-gb-section">';
    if ( 'leaders' === $current ) twshop_group_buy_leaders_tab();
    elseif ( 'commissions' === $current ) twshop_group_buy_commissions_tab();
    elseif ( 'settings' === $current ) twshop_group_buy_settings_tab();
    echo '</div>';
}

function twshop_group_buy_admin_notice( $message, $type = 'success' ) {
    echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( $message ) . '</p></div>';
}

function twshop_group_buy_member_label( $user_id ) {
    $user = get_userdata( $user_id );
    return $user ? sprintf( '%s (#%d – %s)', $user->display_name, $user_id, $user->user_email ) : '已刪除會員 (#' . (int) $user_id . ')';
}

// ── 團購主 ───────────────────────────────────────────────────────────────

function twshop_group_buy_handle_leader_post() {
    if ( empty( $_POST['twshop_group_buy_action'] ) ) return;
    check_admin_referer( 'twshop_group_buy_leaders' );
    global $wpdb;
    $table = twshop_group_buy_tables()['leaders'];
    $action = sanitize_key( wp_unslash( $_POST['twshop_group_buy_action'] ) );
    $user_id = absint( $_POST['leader_id'] ?? 0 );

    if ( 'add' === $action ) {
        if ( ! $user_id || ! get_userdata( $user_id ) ) { twshop_group_buy_admin_notice( '請選擇會員。', 'error' ); return; }
        $existing = twshop_group_buy_get_leader( $user_id );
        if ( $existing ) {
            if ( 'active' !== $existing['status'] ) $wpdb->update( $table, array( 'status' => 'active' ), array( 'user_id' => $user_id ) );
            twshop_group_buy_admin_notice( '此會員已是團購主，已確認為啟用狀態。' );
            return;
        }
        twshop_group_buy_create_leader( $user_id )
            ? twshop_group_buy_admin_notice( '已新增團購主，請點「編輯」指定團購商品。' )
            : twshop_group_buy_admin_notice( '新增團購主失敗，請稍後再試。', 'error' );
        return;
    }

    $leader = twshop_group_buy_get_leader( $user_id );
    if ( ! $leader ) { twshop_group_buy_admin_notice( '找不到團購主。', 'error' ); return; }

    if ( 'toggle' === $action ) {
        $status = 'active' === $leader['status'] ? 'disabled' : 'active';
        $wpdb->update( $table, array( 'status' => $status ), array( 'user_id' => $user_id ) );
        twshop_group_buy_admin_notice( 'active' === $status ? '已啟用團購主。' : '已停用團購主：連結與團購價立即失效，已下的訂單仍照原比例計佣。' );
    } elseif ( 'save' === $action ) {
        // 佣金規則：global＝全站級距、rate＝固定比例、tiers＝自訂級距。級距格式錯誤時保留原規則並提示。
        $mode = sanitize_key( wp_unslash( $_POST['commission_mode'] ?? 'global' ) );
        $rate = isset( $leader['rate'] ) && '' !== $leader['rate'] ? $leader['rate'] : null;
        $tiers_json = $leader['tiers'] ?? null;
        $rule_error = null;
        if ( 'rate' === $mode ) {
            $raw_rate = trim( (string) wp_unslash( $_POST['rate'] ?? '' ) );
            if ( '' === $raw_rate || ! is_numeric( $raw_rate ) ) $rule_error = '固定比例請填 0–100 的數字。';
            else { $rate = twshop_group_buy_clamp_rate( $raw_rate ); $tiers_json = null; }
        } elseif ( 'tiers' === $mode ) {
            $parsed = twshop_group_buy_parse_tier_rows( wp_unslash( $_POST['tier_from'] ?? array() ), wp_unslash( $_POST['tier_rate'] ?? array() ) );
            if ( is_wp_error( $parsed ) ) $rule_error = $parsed->get_error_message();
            else { $tiers_json = wp_json_encode( $parsed ); $rate = null; }
        } else {
            $rate = null;
            $tiers_json = null;
        }
        if ( $rule_error ) twshop_group_buy_admin_notice( $rule_error . '佣金規則未變更。', 'error' );
        $title = mb_substr( sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ), 0, 190 );
        $intro = sanitize_textarea_field( wp_unslash( $_POST['intro'] ?? '' ) );
        $raw_products = isset( $_POST['products'] ) && is_array( $_POST['products'] ) ? wp_unslash( $_POST['products'] ) : array();
        $products = array_filter( array_map( 'absint', $raw_products ), function ( $id ) {
            $p = wc_get_product( $id );
            return $p && ! $p->is_type( 'variation' ) && ! twshop_is_wallet_credit_product( $p );
        } );
        $ok = false !== $wpdb->update( $table, array( 'title' => $title, 'intro' => $intro ), array( 'user_id' => $user_id ) );
        $ok = $ok && false !== $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET rate = " . ( null === $rate ? 'NULL' : '%f' ) . ', tiers = ' . ( null === $tiers_json ? 'NULL' : '%s' ) . ' WHERE user_id = %d',
            array_merge( null === $rate ? array() : array( (float) $rate ), null === $tiers_json ? array() : array( $tiers_json ), array( $user_id ) )
        ) );
        $ok = $ok && twshop_group_buy_set_leader_products( $user_id, $products );
        $ok ? twshop_group_buy_admin_notice( '團購主設定已儲存。' ) : twshop_group_buy_admin_notice( '儲存失敗，請稍後再試。', 'error' );
        if ( $ok ) twshop_group_buy_save_leader_pricing( $user_id, $products );
        if ( $ok ) twshop_group_buy_save_leader_period( $user_id );
        // 自訂網址另外驗證：不合格時其他欄位照常儲存，只保留原本的網址並提示原因。
        $slug = twshop_group_buy_validate_slug( wp_unslash( $_POST['slug'] ?? '' ), $user_id );
        if ( is_wp_error( $slug ) ) {
            twshop_group_buy_admin_notice( $slug->get_error_message() . '自訂網址未變更。', 'error' );
        } elseif ( $slug !== (string) ( $leader['slug'] ?? '' ) ) {
            $saved = false !== $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET slug = " . ( '' === $slug ? 'NULL' : '%s' ) . ' WHERE user_id = %d',
                '' === $slug ? array( $user_id ) : array( $slug, $user_id )
            ) );
            if ( ! $saved ) twshop_group_buy_admin_notice( '自訂網址儲存失敗，可能已被其他團購主使用。', 'error' );
            elseif ( '' !== (string) ( $leader['slug'] ?? '' ) ) twshop_group_buy_admin_notice( '自訂網址已變更：舊的自訂網址會失效，原始代碼網址仍會轉到新網址。', 'warning' );
        }
        // 版型：欄位只在 Elementor 啟用時出現；沒送出就保留原設定。空字串＝使用預設，0＝內建版型。
        if ( isset( $_POST['template_id'] ) && is_scalar( $_POST['template_id'] ) ) {
            $raw_template = (string) wp_unslash( $_POST['template_id'] );
            $template = '' === $raw_template ? null : twshop_group_buy_valid_template( $raw_template );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET template_id = " . ( null === $template ? 'NULL' : '%d' ) . ' WHERE user_id = %d',
                null === $template ? array( $user_id ) : array( $template, $user_id )
            ) );
        }
    } elseif ( 'settle' === $action ) {
        $result = twshop_group_buy_settle( $user_id, wp_unslash( $_POST['note'] ?? '' ) );
        if ( is_wp_error( $result ) ) twshop_group_buy_admin_notice( $result->get_error_message(), 'error' );
        else twshop_group_buy_admin_notice( sprintf( '已結算 %d 筆，共 %s。', $result['count'], wp_strip_all_tags( wc_price( $result['amount'] ) ) ) );
    }
}

function twshop_group_buy_leaders_tab() {
    twshop_group_buy_handle_leader_post();
    twshop_group_buy_reset_cache(); // 本次請求剛儲存的網址、商品要立即反映在列表上
    $edit = absint( $_GET['edit'] ?? 0 );
    $settle = absint( $_GET['settle'] ?? 0 );
    if ( $edit && ( $leader = twshop_group_buy_get_leader( $edit ) ) && empty( $_POST['twshop_group_buy_action'] ) ) {
        twshop_group_buy_leader_edit_form( $leader );
        return;
    }
    if ( $settle && ( $leader = twshop_group_buy_get_leader( $settle ) ) && empty( $_POST['twshop_group_buy_action'] ) ) {
        twshop_group_buy_settle_form( $leader );
        return;
    }

    global $wpdb;
    $leaders = $wpdb->get_results( 'SELECT * FROM ' . twshop_group_buy_tables()['leaders'] . ' ORDER BY created_at DESC, user_id DESC', ARRAY_A );
    $totals = twshop_group_buy_leader_totals();
    $counts = array();
    foreach ( $wpdb->get_results( 'SELECT leader_id, COUNT(*) AS c FROM ' . twshop_group_buy_tables()['products'] . ' GROUP BY leader_id', ARRAY_A ) as $row ) {
        $counts[ (int) $row['leader_id'] ] = (int) $row['c'];
    }

    echo '<div class="twshop-panel">';
    twshop_panel_head( 'users', '新增團購主' );
    echo '<div class="twshop-panel-body"><form method="post">';
    wp_nonce_field( 'twshop_group_buy_leaders' );
    echo '<input type="hidden" name="twshop_group_buy_action" value="add">';
    echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">會員</th><td>';
    twshop_render_customer_search_field( 'leader_id' );
    echo '<p class="twshop-hint">新增後系統產生專屬頁連結；請再「編輯」指定團購商品與佣金比例。</p></td></tr></tbody></table>';
    submit_button( '新增團購主', 'primary', 'submit', false );
    echo '</form></div></div>';

    echo '<div class="twshop-panel">';
    twshop_panel_head( 'users', '團購主列表' );
    echo '<div class="twshop-panel-body">';
    if ( ! $leaders ) { echo '<p class="twshop-hint">尚未指定團購主。</p></div></div>'; return; }
    $own_counts = array();
    foreach ( $wpdb->get_results( 'SELECT leader_id, COUNT(*) AS c FROM ' . twshop_group_buy_tables()['prices'] . ' GROUP BY leader_id', ARRAY_A ) as $row ) {
        $own_counts[ (int) $row['leader_id'] ] = (int) $row['c'];
    }
    $performance = array();
    foreach ( $wpdb->get_results( 'SELECT leader_id, SUM(base_amount) AS p FROM ' . twshop_group_buy_tables()['commissions'] . ' GROUP BY leader_id', ARRAY_A ) as $row ) {
        $performance[ (int) $row['leader_id'] ] = (float) $row['p'];
    }
    echo '<table class="widefat striped twshop-gb-leaders"><thead><tr><th>狀態</th><th>團購主</th><th>專屬頁</th><th class="num">商品</th><th>團購折扣</th><th>佣金規則</th><th class="num">累計業績</th><th class="num">未結算</th><th class="num">累計佣金</th><th>操作</th></tr></thead><tbody>';
    foreach ( $leaders as $leader ) {
        $id = (int) $leader['user_id'];
        $active = 'active' === $leader['status'];
        $sum = $totals[ $id ] ?? array( 'total' => 0, 'unsettled' => 0 );
        $perf = $performance[ $id ] ?? 0;
        $url = twshop_group_buy_page_url( $leader );
        $user = get_userdata( $id );
        $tiers = twshop_group_buy_leader_tiers( $leader );
        $current_tier = twshop_group_buy_tier_index_at( $perf, $tiers );
        $period_state = twshop_group_buy_period_state( $leader );
        $period = twshop_group_buy_period( $leader );
        // 徽章已表明狀態，下方只列時間，避免「進行中」重複出現
        $period_text = 'upcoming' === $period_state ? twshop_group_buy_format_time( $period['start'] ) . ' 開始'
            : ( 'ended' === $period_state ? twshop_group_buy_format_time( $period['end'] ) . ' 結束'
            : ( $period['end'] ? '至 ' . twshop_group_buy_format_time( $period['end'] ) . ' 結束' : ( $period['start'] ? twshop_group_buy_format_time( $period['start'] ) . ' 起' : '' ) ) );
        $badge = ! $active ? array( 'twshop-badge--warn', '停用' ) : ( 'upcoming' === $period_state ? array( '', '未開始' ) : ( 'ended' === $period_state ? array( 'twshop-badge--warn', '已結束' ) : array( 'twshop-badge--ok', '進行中' ) ) );
        $status_html = '<td class="twshop-gb-status-cell"><div class="twshop-gb-status"><span class="twshop-badge ' . esc_attr( $badge[0] ) . '">' . esc_html( $badge[1] ) . '</span>';
        if ( $active && '' !== $period_text ) $status_html .= '<span class="twshop-hint twshop-gb-period-hint">' . esc_html( $period_text ) . '</span>';
        $status_html .= '</div></td>';
        echo '<tr' . ( $active ? '' : ' class="is-inactive"' ) . '>' . $status_html . '<td class="twshop-gb-leader-cell"><strong>' . esc_html( twshop_group_buy_leader_title( $leader ) ) . '</strong>';
        echo '<div class="twshop-hint">' . ( $user ? esc_html( $user->display_name . ' · ' . $user->user_email ) : '已刪除會員' ) . ' <span class="twshop-gb-id">#' . $id . '</span></div></td>';
        echo '<td class="twshop-gb-url-cell"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><code>' . esc_html( wp_parse_url( $url, PHP_URL_PATH ) ?: $leader['code'] ) . '</code><span class="dashicons dashicons-external" aria-hidden="true"></span></a></td>';
        echo '<td class="num">' . (int) ( $counts[ $id ] ?? 0 ) . '</td>';
        $discount_text = twshop_group_buy_describe_discount( twshop_group_buy_leader_discount( $leader ) );
        echo '<td class="twshop-gb-discount-cell">' . ( $discount_text ? esc_html( $discount_text ) : '<span class="twshop-hint">商品團購價</span>' );
        if ( ! empty( $own_counts[ $id ] ) ) echo '<div class="twshop-hint">另有 ' . (int) $own_counts[ $id ] . ' 項個別價格</div>';
        echo '</td><td>';
        if ( 'global' === twshop_group_buy_leader_rule_mode( $leader ) ) echo '<span class="twshop-badge">全站預設</span>';
        echo '<ul class="twshop-gb-tier-list">';
        foreach ( $tiers as $i => $tier ) {
            $label = ( $tier['from'] > 0 ? '累計 ' . wp_strip_all_tags( wc_price( $tier['from'] ) ) . ' 起' : '基本' ) . ' <b>' . esc_html( wc_format_localized_decimal( $tier['rate'] ) ) . '%</b>';
            echo '<li' . ( $i === $current_tier && count( $tiers ) > 1 ? ' class="is-current" title="目前級距"' : '' ) . '>' . $label . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 金額為純文字、比例已跳脫。
        }
        echo '</ul></td>';
        echo '<td class="num">' . wp_kses_post( wc_price( $perf ) ) . '</td>';
        echo '<td class="num">' . ( $sum['unsettled'] > 0 ? '<strong>' . wp_kses_post( wc_price( $sum['unsettled'] ) ) . '</strong>' : wp_kses_post( wc_price( 0 ) ) ) . '</td>';
        echo '<td class="num">' . wp_kses_post( wc_price( $sum['total'] ) ) . '</td>';
        echo '<td><div class="twshop-gb-actions">';
        echo '<a class="button button-small" href="' . esc_url( twshop_admin_url( 'group-buy', array( 'tab' => 'leaders', 'edit' => $id ) ) ) . '">編輯</a>';
        if ( $sum['unsettled'] > 0 ) echo '<a class="button button-small button-primary" href="' . esc_url( twshop_admin_url( 'group-buy', array( 'tab' => 'leaders', 'settle' => $id ) ) ) . '">結算</a>';
        echo '<form method="post">';
        wp_nonce_field( 'twshop_group_buy_leaders' );
        echo '<input type="hidden" name="twshop_group_buy_action" value="toggle"><input type="hidden" name="leader_id" value="' . $id . '">';
        echo '<button type="submit" class="button button-small">' . ( $active ? '停用' : '啟用' ) . '</button></form>';
        echo '</div></td></tr>';
    }
    echo '</tbody></table></div></div>';
}

function twshop_group_buy_leader_edit_form( array $leader ) {
    $id = (int) $leader['user_id'];
    $product_ids = twshop_group_buy_leader_product_ids( $id );
    echo '<p><a href="' . esc_url( twshop_admin_url( 'group-buy', array( 'tab' => 'leaders' ) ) ) . '">← 返回團購主列表</a></p>';
    echo '<div class="twshop-panel">';
    twshop_panel_head( 'users', '編輯團購主：' . twshop_group_buy_member_label( $id ) );
    echo '<div class="twshop-panel-body"><form method="post" action="' . esc_url( twshop_admin_url( 'group-buy', array( 'tab' => 'leaders' ) ) ) . '">';
    wp_nonce_field( 'twshop_group_buy_leaders' );
    echo '<input type="hidden" name="twshop_group_buy_action" value="save"><input type="hidden" name="leader_id" value="' . $id . '">';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">專屬頁連結</th><td><a href="' . esc_url( twshop_group_buy_page_url( $leader ) ) . '" target="_blank" rel="noopener">' . esc_html( twshop_group_buy_page_url( $leader ) ) . '</a></td></tr>';
    echo '<tr><th scope="row"><label for="twshop-gb-slug">自訂網址</label></th><td><code>' . esc_html( trailingslashit( home_url( twshop_group_buy_base() ) ) ) . '</code>';
    echo '<input id="twshop-gb-slug" class="regular-text" name="slug" type="text" maxlength="40" pattern="[A-Za-z0-9]+(-[A-Za-z0-9]+)*" value="' . esc_attr( (string) ( $leader['slug'] ?? '' ) ) . '" placeholder="' . esc_attr( strtolower( $leader['code'] ) ) . '">';
    echo '<p class="twshop-hint">英文字母、數字與連字號，可以全部都是英文，3–40 字，例如 amy、amyshop 或 amy-2026；大寫會自動轉成小寫。留空使用系統代碼。原始代碼網址（' . esc_html( twshop_group_buy_page_url( $leader['code'] ) ) . '）永遠有效，會自動轉到自訂網址。</p></td></tr>';
    echo '<tr><th scope="row"><label for="twshop-gb-title">團購名稱</label></th><td><input id="twshop-gb-title" class="regular-text" name="title" type="text" value="' . esc_attr( $leader['title'] ) . '">';
    echo '<p class="twshop-hint">顯示在專屬頁標題；留空使用會員顯示名稱。</p></td></tr>';
    echo '<tr><th scope="row"><label for="twshop-gb-intro">簡介</label></th><td><textarea id="twshop-gb-intro" class="large-text" rows="4" name="intro">' . esc_textarea( (string) $leader['intro'] ) . '</textarea></td></tr>';
    echo '<tr><th scope="row"><label for="twshop-gb-starts">團購期間</label></th><td><span class="twshop-gb-period-fields">';
    foreach ( array( 'starts_at' => array( 'twshop-gb-starts', '開始', '00:00' ), 'ends_at' => array( 'twshop-gb-ends', '結束', '23:59' ) ) as $field => $meta ) {
        $local = twshop_group_buy_local_input_value( $leader[ $field ] ?? '' );
        list( $date, $time ) = '' === $local ? array( '', '' ) : explode( 'T', $local );
        if ( 'ends_at' === $field ) echo '<span class="twshop-gb-period-sep">至</span>';
        echo '<input id="' . esc_attr( $meta[0] ) . '" name="' . esc_attr( $field ) . '[date]" type="date" value="' . esc_attr( $date ) . '" aria-label="' . esc_attr( $meta[1] ) . '日期">';
        echo '<input class="twshop-gb-time" name="' . esc_attr( $field ) . '[time]" type="text" inputmode="numeric" maxlength="5" pattern="([01]?[0-9]|2[0-3]):[0-5][0-9]" placeholder="' . esc_attr( $meta[2] ) . '" value="' . esc_attr( $time ) . '" aria-label="' . esc_attr( $meta[1] ) . '時間（24 小時制）">';
    }
    echo '</span>';
    $period_text = twshop_group_buy_describe_period( $leader );
    if ( '' !== $period_text ) echo ' <span class="twshop-badge' . ( 'open' === twshop_group_buy_period_state( $leader ) ? ' twshop-badge--ok' : '' ) . '">' . esc_html( $period_text ) . '</span>';
    echo '<p class="twshop-hint">網站時區（' . esc_html( wp_timezone_string() ) . '），時間用 24 小時制，例如 09:00、23:59；只填日期時開始預設 00:00、結束預設 23:59。都留空＝不限時間；只填開始或結束也可以。未開始時團購頁只預告商品與團購價、不能購買；結束後頁面顯示已結束，購物車裡尚未結帳的團購商品恢復原價。</p></td></tr>';
    echo '<tr><th scope="row"><label for="twshop-gb-template">頁面版型</label></th><td>';
    twshop_group_buy_template_field( 'template_id', $leader['template_id'] ?? null, true );
    echo '</td></tr>';
    $mode = twshop_group_buy_leader_rule_mode( $leader );
    $own_tiers = 'tiers' === $mode ? twshop_group_buy_leader_tiers( $leader ) : twshop_group_buy_global_tiers();
    echo '<tr><th scope="row">佣金規則</th><td><fieldset class="twshop-gb-rule">';
    echo '<p><label><input type="radio" name="commission_mode" value="global"' . checked( $mode, 'global', false ) . '> 使用全站規則</label>';
    echo ' <span class="twshop-hint">目前：' . esc_html( twshop_group_buy_describe_tiers( twshop_group_buy_global_tiers() ) ) . '　<a href="' . esc_url( twshop_admin_url( 'group-buy', array( 'tab' => 'settings' ) ) ) . '">調整全站規則</a></span></p>';
    echo '<p><label><input type="radio" name="commission_mode" value="rate"' . checked( $mode, 'rate', false ) . '> 固定比例</label>';
    echo '<span class="twshop-gb-rule-panel" data-mode="rate"> <input id="twshop-gb-rate" class="small-text" name="rate" type="number" min="0" max="100" step="0.01" value="' . esc_attr( 'rate' === $mode ? (float) $leader['rate'] : '' ) . '" aria-label="固定比例"> %</span></p>';
    echo '<p><label><input type="radio" name="commission_mode" value="tiers"' . checked( $mode, 'tiers', false ) . '> 自訂累進級距</label></p>';
    echo '<div class="twshop-gb-rule-panel" data-mode="tiers">';
    twshop_group_buy_tier_rows_field( $own_tiers, 'tier_rate', 'tier_from' );
    echo '</div>';
    echo '<p class="twshop-hint">目前累計業績：' . wp_kses_post( wc_price( twshop_group_buy_leader_performance( $id ) ) ) . '。採超額累進：累計業績超過門檻的部分才用較高比例。修改只影響之後的新訂單；下單當下的規則會記在訂單上。</p>';
    echo '</fieldset></td></tr>';
    echo '<tr><th scope="row">團購商品</th><td>';
    echo twshop_render_product_search_field( 'products', $product_ids, true, '搜尋要上架到此團購頁的商品…', array( 'variation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 元件內部已跳脫。
    echo '<p class="twshop-hint">團購價只對這裡指定的商品生效；可變商品指定父商品即可，團購價在各規格設定。目錄隱藏的商品仍會在此團購頁顯示；外部商品與群組商品的購買按鈕會連到其他頁面，不會出現在團購頁。</p>';
    echo '</td></tr>';
    twshop_enqueue_asset_script( 'admin/group-buy-admin' );
    $discount = twshop_group_buy_leader_discount( $leader );
    $dtype = $discount['type'] ?? '';
    echo '<tr><th scope="row"><label for="twshop-gb-discount-type">團購折扣</label></th><td><span class="twshop-gb-discount">';
    echo '<select id="twshop-gb-discount-type" name="discount_type">';
    foreach ( array( '' => '使用商品團購價', 'percent' => '售價打折', 'fixed' => '每件減價' ) as $value => $label ) {
        echo '<option value="' . esc_attr( $value ) . '"' . selected( $dtype, $value, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select> <span class="twshop-gb-discount-value"><span data-type="fixed">' . esc_html( html_entity_decode( get_woocommerce_currency_symbol() ) ) . '</span> ';
    echo '<input class="small-text" name="discount_value" type="number" min="0" step="0.01" value="' . esc_attr( $discount ? $discount['value'] : '' ) . '" aria-label="折扣數值"> <span data-type="percent">%</span></span>';
    echo ' <span class="twshop-gb-discount-preview twshop-hint"></span></span>';
    echo '<p class="twshop-hint">套用到此團購主所有團購商品與規格，以目前售價（特價中用特價）計算；下方個別商品價格優先。</p></td></tr>';
    echo '<tr><th scope="row">個別商品價格</th><td>';
    twshop_group_buy_leader_price_table( $leader, $product_ids );
    echo '</td></tr></tbody></table>';
    submit_button( '儲存團購主' );
    echo '</form></div></div>';
}

/**
 * 後台期間欄位（日期＋24 小時制時間）組成 Y-m-d\TH:i；時間留空用預設值、可省略小時前導 0。
 * 已是字串時原樣交給 twshop_group_buy_parse_local_datetime()；只有時間沒有日期或時間格式錯誤回傳 false。
 */
function twshop_group_buy_compose_period_input( $raw, $default_time ) {
    if ( ! is_array( $raw ) ) return is_scalar( $raw ) ? (string) $raw : false;
    $date = is_scalar( $raw['date'] ?? '' ) ? trim( (string) ( $raw['date'] ?? '' ) ) : '';
    $time = is_scalar( $raw['time'] ?? '' ) ? trim( (string) ( $raw['time'] ?? '' ) ) : '';
    if ( '' === $date ) return '' === $time ? '' : false;
    if ( '' === $time ) $time = $default_time;
    if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/D', $time, $m ) ) return false;
    return $date . 'T' . str_pad( $m[1], 2, '0', STR_PAD_LEFT ) . ':' . $m[2];
}

/** 儲存團購期間；欄位沒送出就保留，格式錯誤或結束不晚於開始時保留原設定並提示。 */
function twshop_group_buy_save_leader_period( $user_id ) {
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- 呼叫端已驗證 nonce。
    if ( ! isset( $_POST['starts_at'] ) && ! isset( $_POST['ends_at'] ) ) return;
    $start = twshop_group_buy_parse_local_datetime( twshop_group_buy_compose_period_input( wp_unslash( $_POST['starts_at'] ?? '' ), '00:00' ) );
    $end = twshop_group_buy_parse_local_datetime( twshop_group_buy_compose_period_input( wp_unslash( $_POST['ends_at'] ?? '' ), '23:59' ) );
    // phpcs:enable WordPress.Security.NonceVerification.Missing
    if ( false === $start || false === $end ) { twshop_group_buy_admin_notice( '團購期間的日期或時間格式不正確（時間請用 24 小時制，例如 09:00、23:59），團購期間未變更。', 'error' ); return; }
    if ( '' !== $start && '' !== $end && strtotime( $end . ' UTC' ) <= strtotime( $start . ' UTC' ) ) { twshop_group_buy_admin_notice( '結束時間必須晚於開始時間，團購期間未變更。', 'error' ); return; }
    global $wpdb;
    $sets = array();
    $args = array();
    foreach ( array( 'starts_at' => $start, 'ends_at' => $end ) as $col => $value ) {
        if ( '' === $value ) { $sets[] = "{$col} = NULL"; continue; }
        $sets[] = "{$col} = %s";
        $args[] = $value;
    }
    $args[] = absint( $user_id );
    $wpdb->query( $wpdb->prepare( 'UPDATE ' . twshop_group_buy_tables()['leaders'] . ' SET ' . implode( ', ', $sets ) . ' WHERE user_id = %d', $args ) );
    if ( '' !== $end && strtotime( $end . ' UTC' ) <= twshop_group_buy_now() ) twshop_group_buy_admin_notice( '結束時間已經過了，這個團購目前顯示為已結束。', 'warning' );
}

/**
 * 儲存團購主的整體折扣與個別商品價格。欄位沒送出就保留原設定；格式錯誤只略過該項並提示。
 * 個別價格只接受目前指定的商品（可變商品為其規格），清單外的 ID 一律忽略。
 */
function twshop_group_buy_save_leader_pricing( $user_id, array $product_ids ) {
    global $wpdb;
    if ( isset( $_POST['discount_type'] ) && is_scalar( $_POST['discount_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 呼叫端已驗證 nonce。
        $type = sanitize_key( wp_unslash( $_POST['discount_type'] ) );
        $raw = trim( (string) wp_unslash( $_POST['discount_value'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $error = null;
        if ( '' === $type ) {
            $value = null;
        } elseif ( 'percent' === $type ) {
            $value = is_numeric( $raw ) && (float) $raw > 0 && (float) $raw < 100 ? round( (float) $raw, 2 ) : null;
            if ( null === $value ) $error = '售價打折請填大於 0、小於 100 的百分比（例如 10 代表打 9 折）。';
        } elseif ( 'fixed' === $type ) {
            $value = is_numeric( $raw ) && (float) $raw > 0 ? round( (float) $raw, 2 ) : null;
            if ( null === $value ) $error = '每件減價請填大於 0 的金額。';
        } else {
            $error = '團購折扣類型不正確。';
        }
        if ( $error ) {
            twshop_group_buy_admin_notice( $error . '團購折扣未變更。', 'error' );
        } else {
            $wpdb->query( $wpdb->prepare(
                'UPDATE ' . twshop_group_buy_tables()['leaders'] . ' SET discount_type = ' . ( null === $value ? 'NULL' : '%s' ) . ', discount_value = ' . ( null === $value ? 'NULL' : '%f' ) . ' WHERE user_id = %d',
                null === $value ? array( $user_id ) : array( $type, $value, $user_id )
            ) );
        }
    }
    if ( ! isset( $_POST['leader_price'] ) || ! is_array( $_POST['leader_price'] ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $allowed = array();
    foreach ( $product_ids as $pid ) {
        $product = wc_get_product( $pid );
        if ( ! $product ) continue;
        if ( $product->is_type( 'variable' ) ) foreach ( $product->get_children() as $cid ) $allowed[ (int) $cid ] = true;
        else $allowed[ (int) $pid ] = true;
    }
    $prices = array();
    $invalid = array();
    $ineffective = array();
    foreach ( wp_unslash( $_POST['leader_price'] ) as $pid => $raw ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $pid = absint( $pid );
        $raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
        if ( ! isset( $allowed[ $pid ] ) || '' === $raw ) continue;
        $product = wc_get_product( $pid );
        if ( ! is_numeric( $raw ) || (float) $raw <= 0 ) { $invalid[] = $product ? $product->get_name() : '#' . $pid; continue; }
        $prices[ $pid ] = round( (float) $raw, wc_get_price_decimals() );
        $stored = $product ? $product->get_price( 'edit' ) : '';
        if ( is_numeric( $stored ) && $prices[ $pid ] >= (float) $stored ) $ineffective[] = $product->get_name();
    }
    if ( ! twshop_group_buy_set_leader_prices( $user_id, $prices ) ) {
        twshop_group_buy_admin_notice( '個別商品價格儲存失敗，請稍後再試。', 'error' );
        return;
    }
    if ( $invalid ) twshop_group_buy_admin_notice( '以下商品的個別價格不是大於 0 的數字，已略過：' . implode( '、', $invalid ), 'error' );
    if ( $ineffective ) twshop_group_buy_admin_notice( '以下商品的個別價格不低於目前售價，暫時不會生效：' . implode( '、', $ineffective ), 'warning' );
}

/**
 * 個別商品價格表：列出指定商品（可變商品逐規格）、目前售價、此團購主價格輸入（提示文字為商品團購價）、團購價與來源。
 * 團購價欄由 admin/group-buy-admin.js 依輸入與上方團購折扣即時重算；伺服器端輸出的是已儲存設定的結果。
 */
function twshop_group_buy_leader_price_table( array $leader, array $product_ids ) {
    if ( ! $product_ids ) { echo '<p class="twshop-hint">請先在上方指定團購商品並儲存。</p>'; return; }
    $own = twshop_group_buy_leader_prices( $leader['user_id'] );
    $rows = array();
    foreach ( $product_ids as $pid ) {
        $product = wc_get_product( $pid );
        if ( ! $product ) continue;
        if ( $product->is_type( 'variable' ) ) {
            $rows[] = array( 'heading' => $product->get_name() );
            foreach ( $product->get_children() as $cid ) {
                $variation = wc_get_product( $cid );
                if ( $variation ) $rows[] = array( 'product' => $variation, 'label' => wc_get_formatted_variation( $variation, true, false, false ) ?: '任一規格（#' . $cid . '）' );
            }
        } else {
            $rows[] = array( 'product' => $product, 'label' => $product->get_name() );
        }
    }
    $decimals = wc_get_price_decimals();
    printf(
        '<table class="widefat twshop-gb-price-table" data-symbol="%s" data-decimals="%d" data-thousand="%s" data-decimal="%s" data-format="%s">',
        esc_attr( html_entity_decode( get_woocommerce_currency_symbol() ) ),
        $decimals,
        esc_attr( wc_get_price_thousand_separator() ),
        esc_attr( wc_get_price_decimal_separator() ),
        esc_attr( get_woocommerce_price_format() )
    );
    echo '<thead><tr><th>商品</th><th class="num">目前售價</th><th>此團購主價格</th><th class="num">團購價</th></tr></thead><tbody>';
    foreach ( $rows as $row ) {
        if ( isset( $row['heading'] ) ) { echo '<tr class="twshop-gb-price-parent"><td colspan="4">' . esc_html( $row['heading'] ) . '</td></tr>'; continue; }
        $product = $row['product'];
        $id = $product->get_id();
        $stored = $product->get_price( 'edit' );
        $raw = twshop_group_buy_raw_price( $product );
        $effective = twshop_group_buy_price_for_leader( $leader, $product );
        printf(
            '<tr class="twshop-gb-price-row%s" data-price="%s" data-raw="%s">',
            $product->is_type( 'variation' ) ? ' twshop-gb-price-variation' : '',
            esc_attr( is_numeric( $stored ) ? (float) $stored : '' ),
            esc_attr( null === $raw ? '' : $raw )
        );
        echo '<td>' . esc_html( $row['label'] ) . '</td>';
        echo '<td class="num">' . ( is_numeric( $stored ) ? wp_kses_post( wc_price( $stored ) ) : '—' ) . '</td>';
        echo '<td><input type="number" min="0.01" step="any" name="leader_price[' . (int) $id . ']" value="' . esc_attr( isset( $own[ $id ] ) ? wc_format_decimal( $own[ $id ] ) : '' ) . '" placeholder="' . esc_attr( null === $raw ? '不設定' : '商品團購價 ' . wc_format_decimal( $raw, $decimals ) ) . '" aria-label="' . esc_attr( $row['label'] . ' 此團購主價格' ) . '"></td>';
        echo '<td class="num twshop-gb-price-result">' . ( null === $effective ? '<span class="twshop-hint">原價</span>' : '<strong>' . wp_kses_post( wc_price( $effective ) ) . '</strong>' ) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p class="twshop-hint">留空就依團購折扣或商品團購價（輸入框淡色提示）計算。團購價須低於目前售價才會生效，否則以原價販售。剛加入的商品儲存後才會出現在這裡。</p>';
}

function twshop_group_buy_settle_form( array $leader ) {
    global $wpdb;
    $id = (int) $leader['user_id'];
    $t = twshop_group_buy_tables();
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['commissions']} WHERE leader_id = %d AND settlement_id IS NULL ORDER BY id ASC", $id ), ARRAY_A );
    $sum = array_sum( array_map( 'floatval', array_column( $rows, 'amount' ) ) );
    echo '<p><a href="' . esc_url( twshop_admin_url( 'group-buy', array( 'tab' => 'leaders' ) ) ) . '">← 返回團購主列表</a></p>';
    echo '<div class="twshop-panel">';
    twshop_panel_head( 'users', '結算佣金：' . twshop_group_buy_member_label( $id ) );
    echo '<div class="twshop-panel-body">';
    twshop_group_buy_render_entries( $rows, false );
    echo '<p>未結算合計：<strong>' . wp_kses_post( wc_price( $sum ) ) . '</strong></p>';
    if ( $sum <= 0 ) { echo '<p class="twshop-hint">未結算金額須大於 0 才能結算。</p></div></div>'; return; }
    echo '<form method="post" action="' . esc_url( twshop_admin_url( 'group-buy', array( 'tab' => 'leaders' ) ) ) . '">';
    wp_nonce_field( 'twshop_group_buy_leaders' );
    echo '<input type="hidden" name="twshop_group_buy_action" value="settle"><input type="hidden" name="leader_id" value="' . $id . '">';
    echo '<p><label for="twshop-gb-note">備註（例如匯款日期、帳號末五碼）</label><br><input id="twshop-gb-note" class="regular-text" name="note" type="text" maxlength="190"></p>';
    echo '<p class="twshop-hint">確認已線下匯款後再按結算；結算後上列紀錄標為已結算，之後產生的扣回會留到下次結算。</p>';
    submit_button( '確認已匯款並結算', 'primary', 'submit', false );
    echo '</form></div></div>';
}

// ── 佣金紀錄 ─────────────────────────────────────────────────────────────

function twshop_group_buy_render_entries( array $rows, $show_leader = true ) {
    if ( ! $rows ) { echo '<p class="twshop-hint">沒有紀錄。</p>'; return; }
    echo '<table class="widefat striped"><thead><tr><th>日期</th>' . ( $show_leader ? '<th>團購主</th>' : '' ) . '<th>訂單</th><th>說明</th><th>計佣金額</th><th>比例</th><th>金額</th><th>結算</th></tr></thead><tbody>';
    foreach ( $rows as $row ) {
        $order = wc_get_order( (int) $row['order_id'] );
        $order_cell = $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '#' . (int) $row['order_id'];
        $is_earn = 0 === strpos( $row['entry_key'], 'earn:' );
        echo '<tr><td>' . esc_html( $row['created_at'] ) . '</td>';
        if ( $show_leader ) echo '<td>' . esc_html( twshop_group_buy_member_label( (int) $row['leader_id'] ) ) . '</td>';
        echo '<td>' . $order_cell . '</td><td>' . esc_html( $row['note'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已於上方跳脫。
        echo '<td>' . ( $is_earn ? wp_kses_post( wc_price( $row['base_amount'] ) ) : '—' ) . '</td>';
        echo '<td>' . ( $is_earn ? esc_html( wc_format_localized_decimal( (float) $row['rate'] ) ) . '%' : '—' ) . '</td>';
        echo '<td>' . wp_kses_post( wc_price( $row['amount'] ) ) . '</td>';
        echo '<td>' . ( $row['settlement_id'] ? '已結算 #' . (int) $row['settlement_id'] : '未結算' ) . '</td></tr>';
    }
    echo '</tbody></table>';
}

function twshop_group_buy_commissions_tab() {
    global $wpdb;
    $t = twshop_group_buy_tables();
    $leader = absint( $_GET['leader'] ?? 0 );
    $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
    $per_page = 50;
    $where = $leader ? $wpdb->prepare( ' WHERE leader_id = %d', $leader ) : '';
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['commissions']}{$where}" );
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['commissions']}{$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
    $leaders = $wpdb->get_col( "SELECT user_id FROM {$t['leaders']} ORDER BY user_id ASC" );

    echo '<div class="twshop-panel">';
    twshop_panel_head( 'users', '佣金紀錄' );
    echo '<div class="twshop-panel-body">';
    echo '<form method="get"><input type="hidden" name="page" value="wc-general-settings"><input type="hidden" name="section" value="group-buy"><input type="hidden" name="tab" value="commissions">';
    echo '<label for="twshop-gb-filter">團購主 </label><select id="twshop-gb-filter" name="leader"><option value="0">全部</option>';
    foreach ( $leaders as $id ) echo '<option value="' . (int) $id . '" ' . selected( $leader, (int) $id, false ) . '>' . esc_html( twshop_group_buy_member_label( (int) $id ) ) . '</option>';
    echo '</select> ';
    submit_button( '篩選', 'secondary', '', false );
    echo '</form><br>';
    twshop_group_buy_render_entries( $rows );
    $pages = (int) ceil( $total / $per_page );
    if ( $pages > 1 ) {
        echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages,
        ) ) . '</div></div>';
    }
    echo '</div></div>';

    $s_where = $leader ? $wpdb->prepare( ' WHERE leader_id = %d', $leader ) : '';
    $settlements = $wpdb->get_results( "SELECT * FROM {$t['settlements']}{$s_where} ORDER BY id DESC LIMIT 50", ARRAY_A );
    echo '<div class="twshop-panel">';
    twshop_panel_head( 'users', '最近 50 筆結算' );
    echo '<div class="twshop-panel-body">';
    if ( ! $settlements ) { echo '<p class="twshop-hint">尚無結算紀錄。</p></div></div>'; return; }
    echo '<table class="widefat striped"><thead><tr><th>編號</th><th>日期</th><th>團購主</th><th>金額</th><th>備註</th><th>操作人</th></tr></thead><tbody>';
    foreach ( $settlements as $s ) {
        $by = get_userdata( (int) $s['created_by'] );
        echo '<tr><td>#' . (int) $s['id'] . '</td><td>' . esc_html( $s['created_at'] ) . '</td><td>' . esc_html( twshop_group_buy_member_label( (int) $s['leader_id'] ) ) . '</td>';
        echo '<td>' . wp_kses_post( wc_price( $s['amount'] ) ) . '</td><td>' . esc_html( $s['note'] ) . '</td><td>' . esc_html( $by ? $by->display_name : '—' ) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}

// ── 設定 ─────────────────────────────────────────────────────────────────

function twshop_group_buy_settings_tab() {
    if ( isset( $_POST['twshop_group_buy_settings_save'] ) ) {
        check_admin_referer( 'twshop_group_buy_settings' );
        $global = twshop_group_buy_parse_tier_rows( wp_unslash( $_POST['tier_from'] ?? array() ), wp_unslash( $_POST['tier_rate'] ?? array( $_POST['twshop_group_buy_default_rate'] ?? 0 ) ) );
        if ( is_wp_error( $global ) ) {
            twshop_group_buy_admin_notice( $global->get_error_message() . '佣金級距未變更。', 'error' );
        } else {
            update_option( 'twshop_group_buy_default_rate', $global[0]['rate'], false );
            update_option( 'twshop_group_buy_tiers', array_values( array_slice( $global, 1 ) ), false );
        }
        if ( isset( $_POST['twshop_group_buy_template_id'] ) && is_scalar( $_POST['twshop_group_buy_template_id'] ) ) {
            update_option( 'twshop_group_buy_template_id', twshop_group_buy_valid_template( wp_unslash( $_POST['twshop_group_buy_template_id'] ) ), false );
        }
        twshop_group_buy_admin_notice( '團購設定已儲存。' );
        $old_base = twshop_group_buy_base();
        $base = twshop_group_buy_validate_base( wp_unslash( $_POST['twshop_group_buy_base'] ?? $old_base ) );
        if ( is_wp_error( $base ) ) {
            twshop_group_buy_admin_notice( $base->get_error_message() . '網址前綴未變更。', 'error' );
        } elseif ( $base !== $old_base ) {
            update_option( 'twshop_group_buy_base', $base, false );
            twshop_group_buy_apply_base( $old_base );
            twshop_group_buy_admin_notice( '網址前綴已改為 /' . $base . '/：所有團購頁網址跟著改變，舊前綴的連結會失效，請通知團購主重新分享。', 'warning' );
        }
    }
    echo '<div class="twshop-panel">';
    twshop_panel_head( 'users', '團購設定' );
    echo '<div class="twshop-panel-body"><form method="post">';
    wp_nonce_field( 'twshop_group_buy_settings' );
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">全站佣金級距</th><td>';
    twshop_group_buy_tier_rows_field( twshop_group_buy_global_tiers(), 'tier_rate', 'tier_from' );
    echo '<p class="twshop-hint">只填第一級就是固定比例。未個別設定的團購主使用這組規則；規則在下單當下記錄到訂單，之後修改不影響已成立的訂單。</p></td></tr>';
    echo '<tr><th scope="row"><label for="twshop-gb-base">網址前綴</label></th><td><code>' . esc_html( trailingslashit( home_url() ) ) . '</code>';
    echo '<input id="twshop-gb-base" class="regular-text" name="twshop_group_buy_base" type="text" maxlength="30" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="' . esc_attr( twshop_group_buy_base() ) . '"><code>/自訂網址/</code>';
    echo '<p class="twshop-hint">所有團購主共用，預設 group-buy。修改後舊前綴的連結會失效，不能與網站既有頁面或商品、分類網址相同。</p></td></tr>';
    echo '<tr><th scope="row"><label for="twshop-gb-template">預設頁面版型</label></th><td>';
    twshop_group_buy_template_field( 'twshop_group_buy_template_id', twshop_group_buy_default_template(), false );
    echo '</td></tr>';
    echo '<tr><th scope="row">計算規則</th><td>';
    echo '<p class="twshop-hint">佣金＝從專屬頁加入的團購商品實付金額（含稅、儲值金折抵視為實付；不含運費；全單點數折抵、滿額折扣依商品金額比例分攤）× 比例，訂單「完成」時入帳。</p>';
    echo '<p class="twshop-hint">累進級距採超額累進：依團購主的累計業績（已完成團購訂單的計佣金額，扣除取消與退款）分段計算。例如門檻 30,000 元、累計從 25,000 增加到 35,000，前 5,000 用第一級、後 5,000 用第二級。已入帳的訂單不會因之後升級而回溯重算。</p>';
    echo '<p class="twshop-hint">取消、失敗或全額退款全數扣回；部分退款時，逐項退款依該團購商品退款金額扣回，只填金額的退款依比例扣回。已結算的佣金被扣回時記為負數，於下次結算扣除。團購主本人下單仍享團購價但不計佣。</p>';
    echo '<details class="twshop-help"><summary>詳細說明：專屬頁、購物車與快取</summary><p>團員必須在團購主的專屬頁（/網址前綴/自訂網址/）把商品加入購物車，該商品才以團購價計算並歸屬該團購主；直接在商店或商品頁購買維持原價。團購資格記在購物車項目上，同一張訂單可同時含有不同團購主的商品，各自計佣。團購主停用或商品被移出指定清單時，購物車裡的對應商品自動恢復原價。團購價不疊加商品折扣規則；滿額折扣、點數、儲值金與優惠券照常。專屬頁對所有訪客內容相同，可交給 CDN 或伺服器快取；購物車與結帳頁維持原本的不快取設定即可。</p></details>';
    echo '</td></tr></tbody></table>';
    submit_button( '儲存設定', 'primary', 'twshop_group_buy_settings_save' );
    echo '</form></div></div>';
}

/**
 * 頁面版型選單。$per_leader 為 true 時多一個「使用預設」（值為空字串，存成 NULL）。
 * Elementor 未啟用時只顯示說明、不輸出欄位，儲存時保留原本設定。
 */
function twshop_group_buy_template_field( $name, $selected, $per_leader ) {
    if ( ! twshop_group_buy_elementor_active() ) {
        echo '<p class="twshop-hint">安裝並啟用 Elementor 後，可用 Elementor 範本設計團購頁；目前使用內建版型。</p>';
        return;
    }
    $options = twshop_group_buy_template_options();
    $current = null === $selected || '' === $selected ? '' : (string) (int) $selected;
    echo '<select id="twshop-gb-template" name="' . esc_attr( $name ) . '">';
    if ( $per_leader ) {
        $default = twshop_group_buy_valid_template( twshop_group_buy_default_template() );
        $label = $default && isset( $options[ $default ] ) ? $options[ $default ] : '內建版型';
        echo '<option value=""' . selected( $current, '', false ) . '>使用預設（' . esc_html( $label ) . '）</option>';
    }
    echo '<option value="0"' . selected( $current, '0', false ) . '>內建版型</option>';
    foreach ( $options as $id => $title ) {
        echo '<option value="' . (int) $id . '"' . selected( $current, (string) $id, false ) . '>' . esc_html( $title ) . '</option>';
    }
    if ( '' !== $current && '0' !== $current && ! isset( $options[ (int) $current ] ) ) {
        echo '<option value="' . esc_attr( $current ) . '" selected>（範本已刪除或未發布，將使用內建版型）</option>';
    }
    echo '</select>';
    $chosen = '' === $current ? twshop_group_buy_valid_template( twshop_group_buy_default_template() ) : twshop_group_buy_valid_template( $current );
    $links = array( '<a href="' . esc_url( admin_url( 'edit.php?post_type=elementor_library&tabs_group=library' ) ) . '" target="_blank" rel="noopener">管理 Elementor 範本</a>' );
    if ( $chosen ) {
        $document = \Elementor\Plugin::instance()->documents->get( $chosen );
        if ( $document ) $links[] = '<a href="' . esc_url( $document->get_edit_url() ) . '" target="_blank" rel="noopener">用 Elementor 編輯這個範本</a>';
    }
    echo ' ' . implode( '　', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已跳脫。
    echo '<p class="twshop-hint">在 Elementor 新增「頁面」「區段」或「容器」類型的範本，放入左側「團購」分類的小工具（團購名稱、團購簡介、團購商品），其他區塊可自由設計。'
        . '底部購物車列由系統固定加上；團購頁仍不顯示網站選單、頁首與頁尾，範本裡請勿放連到其他頁面的按鈕或選單。</p>';
}

/**
 * 級距編輯器：第一級門檻固定 0；其他列可新增／刪除（admin/group-buy-admin.js）。
 * 只輸出已設定的級距，空白列由「新增級距」按鈕加入；兩欄都留空的列儲存時略過。
 */
function twshop_group_buy_tier_rows_field( array $tiers, $rate_name, $from_name ) {
    twshop_enqueue_asset_script( 'admin/group-buy-admin' );
    $symbol = html_entity_decode( get_woocommerce_currency_symbol() );
    $row = function ( $from, $rate, $first ) use ( $rate_name, $from_name, $symbol ) {
        $html = '<tr><td>';
        if ( $first ) {
            $html .= '<span class="twshop-gb-tier-start">' . esc_html( $symbol ) . '0 起</span><input type="hidden" name="' . esc_attr( $from_name ) . '[]" value="0">';
        } else {
            $html .= '<span class="twshop-gb-tier-input">' . esc_html( $symbol ) . ' <input name="' . esc_attr( $from_name ) . '[]" type="number" min="0.01" step="0.01" value="' . esc_attr( '' === $from ? '' : (float) $from ) . '" aria-label="累計業績門檻"> 起</span>';
        }
        $html .= '</td><td><span class="twshop-gb-tier-input"><input class="twshop-gb-tier-rate" name="' . esc_attr( $rate_name ) . '[]" type="number" min="0" max="100" step="0.01" value="' . esc_attr( '' === $rate ? '' : (float) $rate ) . '"' . ( $first ? ' required' : '' ) . ' aria-label="佣金比例"> %</span></td><td class="twshop-gb-tier-remove">';
        if ( ! $first ) $html .= '<button type="button" class="button-link button-link-delete" aria-label="刪除此級距">刪除</button>';
        return $html . '</td></tr>';
    };
    echo '<div class="twshop-gb-tier-editor"><table class="widefat twshop-gb-tier-table"><thead><tr><th>累計業績達</th><th>佣金比例</th><th><span class="screen-reader-text">操作</span></th></tr></thead><tbody>';
    foreach ( array_values( $tiers ) as $i => $tier ) echo $row( $tier['from'], $tier['rate'], 0 === $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 列內容已跳脫。
    if ( ! $tiers ) echo $row( '', '', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 列內容已跳脫。
    echo '</tbody></table>';
    echo '<template class="twshop-gb-tier-template">' . $row( '', '', false ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 列內容已跳脫。
    echo '<p><button type="button" class="button twshop-gb-tier-add">新增級距</button></p>';
    echo '</div>';
}
