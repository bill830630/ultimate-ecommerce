<?php
/**
 * 訂單管理後台強化（自訂物流狀態、列表欄位、批次操作）
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 訂單管理後台強化（自訂物流狀態、列表欄位、批次操作）
// =========================================================================

/**
 * 註冊「配送中」「已出貨」兩個自訂訂單狀態（post_status，HPOS 訂單清單頁也吃這套機制顯示狀態下拉與計數）。
 */
function twshop_register_order_statuses() {
    register_post_status( 'wc-twshop-in-transit', array(
        'label'                     => '配送中',
        'public'                    => true,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => false,
        /* translators: %s: 訂單數量 */
        'label_count'               => _n_noop( '配送中 <span class="count">(%s)</span>', '配送中 <span class="count">(%s)</span>', 'twshop' ),
    ) );
    register_post_status( 'wc-twshop-shipped', array(
        'label'                     => '已出貨',
        'public'                    => true,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => false,
        /* translators: %s: 訂單數量 */
        'label_count'               => _n_noop( '已出貨 <span class="count">(%s)</span>', '已出貨 <span class="count">(%s)</span>', 'twshop' ),
    ) );
}

/**
 * 把自訂狀態插入訂單狀態清單，緊接在「處理中」之後。
 */
function twshop_add_custom_order_statuses( $order_statuses ) {
    $new_statuses = array();
    foreach ( $order_statuses as $key => $label ) {
        $new_statuses[ $key ] = $label;
        if ( 'wc-processing' === $key ) {
            $new_statuses['wc-twshop-in-transit'] = '配送中';
            $new_statuses['wc-twshop-shipped']    = '已出貨';
        }
    }
    return $new_statuses;
}

/**
 * 舊版報表的狀態清單是不帶 wc- 的值陣列（例如 array('completed','processing','on-hold')），
 * 也可能是 false。只在清單含「處理中」時補上兩個自訂狀態，退款報表等其他清單不動。
 * v25.8.107 前誤用 twshop_add_custom_order_statuses()（處理的是 key=>label 格式），
 * 收到 false 會噴 foreach 警告，收到陣列也不會有任何效果。
 */
function twshop_add_custom_report_statuses( $statuses ) {
    if ( ! is_array( $statuses ) || ! in_array( 'processing', $statuses, true ) ) return $statuses;
    return array_values( array_unique( array_merge( $statuses, array( 'twshop-in-transit', 'twshop-shipped' ) ) ) );
}

/**
 * 配送中／已出貨都是處理中之後才會進入的狀態，訂單當下必然已付款，納入已付款狀態清單避免營收報表漏算。
 */
function twshop_add_custom_paid_statuses( $statuses ) {
    $statuses[] = 'twshop-in-transit';
    $statuses[] = 'twshop-shipped';
    return array_unique( $statuses );
}

/**
 * 在訂單列表新增「金流單號」「物流單號」欄位，插入在寄送地址欄位之後（找不到則接在帳單地址欄位後，兩者都沒有就接在最後）。
 */
function twshop_order_list_columns( $columns ) {
    if ( array_key_exists( 'shipping_address', $columns ) ) {
        $anchor = 'shipping_address';
    } elseif ( array_key_exists( 'billing_address', $columns ) ) {
        $anchor = 'billing_address';
    } else {
        $anchor = null;
    }

    $new_columns = array(
        'twshop_payment_no'  => '金流單號',
        'twshop_shipping_no' => '物流單號',
    );

    if ( null === $anchor ) {
        return array_merge( $columns, $new_columns );
    }

    $result = array();
    foreach ( $columns as $key => $label ) {
        $result[ $key ] = $label;
        if ( $key === $anchor ) {
            $result = array_merge( $result, $new_columns );
        }
    }
    return $result;
}

/**
 * 從 twshop_render_order_logistics_info() 讀取的同一批 ecpay-ecommerce-for-woocommerce meta 取物流單號，
 * 優先順序：超商寄貨編號 > 宅配托運單號 > 綠界物流編號。
 */
function twshop_get_order_shipping_no( $order ) {
    $cvs_payment_no = $order->get_meta( '_wooecpay_logistic_CVSPaymentNo' );
    if ( $cvs_payment_no ) {
        return $cvs_payment_no;
    }

    $booking_note = $order->get_meta( '_wooecpay_logistic_BookingNote' );
    if ( $booking_note ) {
        return $booking_note;
    }

    return $order->get_meta( '_wooecpay_logistic_AllPayLogisticsID' );
}

/**
 * 輸出訂單列表自訂欄位內容。地址欄位沿用 WooCommerce 內建輸出（priority 10）後，用 priority 11 附加電話。
 */
function twshop_order_list_column_content( $column, $post_id ) {
    $order = wc_get_order( $post_id );
    if ( ! $order ) {
        return;
    }

    switch ( $column ) {
        case 'twshop_payment_no':
            echo esc_html( $order->get_transaction_id() );
            break;

        case 'twshop_shipping_no':
            echo esc_html( twshop_get_order_shipping_no( $order ) );
            break;

        case 'billing_address':
            if ( $order->get_billing_phone() ) {
                echo '<span class="twshop-order-phone" style="display:block;">電話 ' . esc_html( $order->get_billing_phone() ) . '</span>';
            }
            break;

        case 'shipping_address':
            if ( $order->get_shipping_phone() ) {
                echo '<span class="twshop-order-phone" style="display:block;">電話 ' . esc_html( $order->get_shipping_phone() ) . '</span>';
            }
            break;
    }
}

/**
 * 訂單列表批次操作新增「變更為已出貨」「變更為配送中」。
 */
function twshop_order_bulk_actions( $actions ) {
    $actions['mark_twshop-shipped']    = '變更為已出貨';
    $actions['mark_twshop-in-transit'] = '變更為配送中';
    return $actions;
}

/**
 * 處理批次變更訂單狀態，處理結果透過 redirect URL 帶給 twshop_order_bulk_admin_notice() 顯示。
 */
function twshop_handle_order_bulk_status_update( $redirect_to, $action, $ids ) {
    if ( ! in_array( $action, array( 'mark_twshop-shipped', 'mark_twshop-in-transit' ), true ) ) {
        return $redirect_to;
    }

    $status    = str_replace( 'mark_', '', $action );
    $processed = 0;

    foreach ( $ids as $id ) {
        $order = wc_get_order( $id );
        if ( $order ) {
            $order->update_status( $status );
            $processed++;
        }
    }

    return add_query_arg( array(
        'twshop_bulk_processed' => $processed,
        'twshop_bulk_status'    => $status,
    ), $redirect_to );
}

/**
 * 顯示批次變更訂單狀態後的成功提示。
 */
function twshop_order_bulk_admin_notice() {
    if ( empty( $_REQUEST['twshop_bulk_processed'] ) || empty( $_REQUEST['twshop_bulk_status'] ) ) {
        return;
    }

    $count  = intval( $_REQUEST['twshop_bulk_processed'] );
    $status = sanitize_text_field( wp_unslash( $_REQUEST['twshop_bulk_status'] ) );
    $labels = array(
        'twshop-shipped'    => '已出貨',
        'twshop-in-transit' => '配送中',
    );

    printf(
        '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
        esc_html( sprintf( '%d 筆訂單已更新為 %s', $count, $labels[ $status ] ?? $status ) )
    );
}

/**
 * ecpay-ecommerce-for-woocommerce 的 logistic_status_response()（見上方「訂單物流資訊」）收到綠界物流貨態
 * 回傳時，只會把結果寫成一則「物流貨態回傳:{RtnMsg} ({RtnCode})」系統備註，不會觸發任何 hook 給其他外掛接手。
 * 這裡改掛 WooCommerce 核心的 woocommerce_order_note_added（add_order_note() 寫入備註後一定會觸發），
 * 解析出該筆備註裡的 RtnCode，命中「取件/送達完成」代碼時自動把訂單狀態轉為已完成——完全不呼叫任何
 * ECPay API、不修改 ecpay-ecommerce-for-woocommerce 本身，純粹被動反應它已經寫好的資料。
 *
 * 代碼來源：ECPay 官方物流狀態代碼表（https://github.com/ECPay/SDK_PHP/blob/master/example/Logistics/logistics_status.xlsx）。
 * 2067=超商取件成功(B2C) 3022=超商取件成功(C2C) 3308/3309=郵局已送達 3003=黑貓宅急便配達完成
 */
function twshop_maybe_auto_complete_order_from_logistic_note( $note_id, $order ) {
    if ( ! $order instanceof WC_Order ) {
        return;
    }
    if ( $order->has_status( array( 'completed', 'cancelled', 'refunded', 'failed' ) ) ) {
        return;
    }

    $note = get_comment( $note_id );
    if ( ! $note ) {
        return;
    }

    if ( ! preg_match( '/^物流貨態回傳:.*\((\d+)\)\s*$/u', $note->comment_content, $matches ) ) {
        return;
    }

    $completed_codes = array( '2067', '3022', '3308', '3309', '3003' );
    if ( ! in_array( $matches[1], $completed_codes, true ) ) {
        return;
    }

    $order->update_status( 'completed', '物流貨態顯示取件/送達完成，系統自動將訂單標記為已完成。' );
}

