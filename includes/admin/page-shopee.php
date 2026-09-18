<?php
/**
 * 蝦皮串接：「系統設定 ▸ 蝦皮串接」頁籤（總開關 + 授權/商品對應/同步設定/同步紀錄
 * 四個子頁籤）＋所有 AJAX handler。
 *
 * v25.8.65 起不再是獨立頂層選單、不再受「系統設定 ▸ 模組開關」影響——蝦皮串接需要
 * 另外向蝦皮申請 partner key 才能真正運作，跟其餘一啟用就能用的功能模組性質不同，
 * 改成頁籤自己的獨立開關（`wc_shopee_sync_enabled`），詳見 CLAUDE.md「蝦皮串接模組」。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 頁面外框
// =========================================================================

/**
 * 被 twshop_system_section()（pages.php）以 `tab=shopee` 呼叫，是「系統設定」頁的
 * 其中一個頁籤內容，不再自帶 twshop_render_admin_page() 外框（外層已經包過一次）。
 *
 * 內部的四個子頁籤（授權/商品對應/同步設定/同步紀錄）用獨立的 `subtab` GET 參數導覽，
 * 不能沿用共用的 twshop_render_admin_tabs()／twshop_get_current_admin_tab()——那兩支
 * 寫死讀寫 `$_GET['tab']`，跟外層「系統設定」自己的 `tab=shopee` 會互相踩到。
 *
 * 總開關關閉時完全不顯示四個子頁籤（連同它們暗示的授權/同步功能一起隱藏），而不是
 * 顯示出來但背後的 AJAX handler 因為開關關閉而沒有註冊——避免「已知踩坑：跨模組共用
 * 的 AJAX action」那類「按鈕點了沒反應、沒有任何錯誤訊息」的情境從一開始就不會發生。
 */
function twshop_shopee_settings_tab() {
    $enabled = twshop_shopee_sync_enabled();
    ?>
    <div class="twshop-panel">
        <?php twshop_panel_head( 'plug', '蝦皮串接總開關' ); ?>
        <div class="twshop-panel-body">
            <form action="options.php" method="post">
                <?php settings_fields( 'wc_shopee_enable_group' ); ?>
                <label><input type="checkbox" name="wc_shopee_sync_enabled" value="yes" <?php checked( $enabled, 'yes' ); ?>> 啟用蝦皮串接</label>
                <p class="description">開啟後才會出現「授權」「商品對應」「同步設定」「同步紀錄」頁籤，並開始背景排程（推送庫存/價格、匯入訂單）。需要先向蝦皮申請 Partner ID / Partner Key 才能實際使用，見下方「授權」頁籤說明。</p>
                <?php submit_button( '儲存設定', 'primary', 'submit', false ); ?>
            </form>
        </div>
    </div>
    <?php
    if ( ! $enabled ) {
        echo '<div class="notice notice-info inline"><p>蝦皮串接目前未啟用，勾選上方「啟用蝦皮串接」並儲存後，才能設定授權與同步選項。</p></div>';
        return;
    }

    $sub_tabs = array(
        'auth'    => '授權',
        'mapping' => '商品對應',
        'sync'    => '同步設定',
        'log'     => '同步紀錄',
    );
    $requested   = isset( $_GET['subtab'] ) ? sanitize_key( wp_unslash( $_GET['subtab'] ) ) : '';
    $current_sub = ( $requested && isset( $sub_tabs[ $requested ] ) ) ? $requested : 'auth';
    ?>
    <h2 class="nav-tab-wrapper" style="margin-top:10px;">
        <?php foreach ( $sub_tabs as $slug => $label ) :
            // v25.8.81 起改呼叫 twshop_shopee_admin_url()（唯一入口）取代自己重複組字串，
            // 這裡原本沒帶 section 參數，選單收攏成單一入口後若漏這一步，點子頁籤會被
            // twshop_get_current_admin_section() 判定 section 不存在而退回儀表板。
            $url   = twshop_shopee_admin_url( $slug );
            $class = 'nav-tab' . ( $slug === $current_sub ? ' nav-tab-active' : '' );
            ?>
            <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></a>
        <?php endforeach; ?>
    </h2>
    <?php
    if ( 'auth' === $current_sub ) {
        twshop_shopee_auth_tab();
    } elseif ( 'mapping' === $current_sub ) {
        twshop_shopee_mapping_tab();
    } elseif ( 'sync' === $current_sub ) {
        twshop_shopee_sync_tab();
    } elseif ( 'log' === $current_sub ) {
        twshop_shopee_log_tab();
    }
}

function twshop_shopee_render_not_configured_notice() {
    ?>
    <div class="twshop-panel">
        <?php twshop_panel_head( 'lock', '請先完成授權' ); ?>
        <div class="twshop-panel-body">
            <p>請先到「授權」頁籤設定 Partner ID / Partner Key，並完成蝦皮賣場授權。</p>
        </div>
    </div>
    <?php
}

// =========================================================================
// 頁籤：授權
// =========================================================================

function twshop_shopee_auth_tab() {
    $creds = twshop_shopee_credentials();
    $shop  = twshop_shopee_shop();
    ?>
    <?php if ( isset( $_GET['shopee_authorized'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>蝦皮賣場授權成功。</p></div>
    <?php elseif ( isset( $_GET['refresh'] ) ) : ?>
        <?php if ( 'success' === $_GET['refresh'] ) : ?>
            <div class="notice notice-success is-dismissible"><p>Token 續期成功。</p></div>
        <?php else : ?>
            <div class="notice notice-error is-dismissible"><p>Token 續期失敗，請檢查憑證或稍後再試。</p></div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'twshop_shopee_credentials_group' ); ?>
        <div class="twshop-panel">
            <?php
            twshop_panel_head(
                'lock',
                '蝦皮 API 憑證',
                // $hint 會被 twshop_panel_head() 包進 <p class="twshop-panel-hint">，
                // 這裡不能再自己包一層 <p>（瀏覽器遇到巢狀 <p> 會提前關閉外層，說明文字
                // 就拿不到 .twshop-panel-hint 的樣式）。
                '台灣蝦皮 Open API 目前只開放給商城賣家（Shopee Mall）或第三方系統供應商（ERP）申請，一般賣場帳號多半申請不過。尚未取得 <code>partner_id</code>/<code>partner_key</code> 前，以下設定僅供離線驗證架構，實際打蝦皮端點會失敗。<br>timestamp 是秒級 Unix time，蝦皮容忍誤差很小，本機（伺服器）時鐘偏移會讓全部簽章失敗。'
            );
            ?>
            <div class="twshop-panel-body">
            <table class="form-table">
                <tr>
                    <th><label for="twshop_shopee_partner_id">Partner ID</label></th>
                    <td><input type="text" id="twshop_shopee_partner_id" name="twshop_shopee_credentials[partner_id]" value="<?php echo esc_attr( $creds['partner_id'] ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="twshop_shopee_partner_key">Partner Key</label></th>
                    <td>
                        <input type="password" id="twshop_shopee_partner_key" name="twshop_shopee_credentials[partner_key]" value="<?php echo esc_attr( ! empty( $creds['partner_key'] ) ? TWSHOP_SHOPEE_KEY_MASK : '' ); ?>" class="regular-text" autocomplete="new-password">
                        <p class="description">已設定時顯示遮罩，不需更改請保持原樣直接儲存；要更換金鑰請整段清除後貼上新值。</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="twshop_shopee_env">環境</label></th>
                    <td>
                        <select id="twshop_shopee_env" name="twshop_shopee_credentials[env]">
                            <option value="sandbox" <?php selected( $creds['env'], 'sandbox' ); ?>>沙盒（測試，test-stable）</option>
                            <option value="live" <?php selected( $creds['env'], 'live' ); ?>>正式</option>
                        </select>
                    </td>
                </tr>
            </table>
            </div>
        </div>
        <?php submit_button( '儲存憑證' ); ?>
    </form>

    <div class="twshop-panel">
        <?php twshop_panel_head( 'network', '賣場授權狀態' ); ?>
        <div class="twshop-panel-body">
        <?php if ( empty( $shop['access_token'] ) ) : ?>
            <p>尚未授權任何蝦皮賣場。</p>
            <?php if ( twshop_shopee_has_credentials() ) : ?>
                <a href="<?php echo esc_url( twshop_shopee_get_auth_url() ); ?>" class="button button-primary">前往蝦皮授權</a>
            <?php else : ?>
                <p class="description">請先儲存 Partner ID / Partner Key 才能開始授權。</p>
            <?php endif; ?>
        <?php else : ?>
            <table class="widefat" style="max-width:640px;">
                <tbody>
                    <tr><th style="width:160px;">賣場 ID</th><td><?php echo esc_html( $shop['shop_id'] ); ?></td></tr>
                    <tr><th>Token 到期時間</th><td><?php echo esc_html( $shop['expire_at'] ? date_i18n( 'Y-m-d H:i', $shop['expire_at'] ) : '—' ); ?></td></tr>
                    <tr><th>上次續期時間</th><td><?php echo esc_html( $shop['refreshed_at'] ? date_i18n( 'Y-m-d H:i', $shop['refreshed_at'] ) : '—' ); ?></td></tr>
                    <tr><th>授權時間</th><td><?php echo esc_html( $shop['authorized_at'] ? date_i18n( 'Y-m-d H:i', $shop['authorized_at'] ) : '—' ); ?></td></tr>
                </tbody>
            </table>
            <p style="margin-top:12px;">
                <a href="<?php echo esc_url( wp_nonce_url( twshop_shopee_admin_url( 'auth', array( 'twshop_shopee_manual_refresh' => 1 ) ), 'twshop_shopee_manual_refresh' ) ); ?>" class="button">手動續期 Token</a>
            </p>
        <?php endif; ?>
        </div>
    </div>
    <?php
}

// =========================================================================
// 頁籤：商品對應
// =========================================================================

function twshop_shopee_mapping_tab() {
    if ( ! twshop_shopee_has_credentials() ) {
        twshop_shopee_render_not_configured_notice();
        return;
    }

    global $wpdb;
    $table = twshop_shopee_items_table();

    $status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
    $where = '';
    if ( in_array( $status_filter, array( 'linked', 'unlinked', 'conflict' ), true ) ) {
        $where = $wpdb->prepare( ' WHERE status = %s', $status_filter );
    }
    $rows = $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT 200", ARRAY_A );

    twshop_enqueue_asset_script( 'admin/shopee-mapping', array(
        'twshopShopeeMapping' => array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'twshop_admin_action' ),
            'i18n'    => array(
                'working'       => '處理中…',
                'done'          => '完成',
                'error'         => '發生錯誤',
                'confirmUnlink' => '確定要解除這筆綁定嗎？',
                'confirmClear'  => '確定要清空所有同步紀錄嗎？此動作無法復原。',
                'needProductId' => '請輸入有效的 Woo 商品 ID',
            ),
        ),
    ) );
    ?>
    <div class="twshop-panel">
        <?php
        twshop_panel_head(
            'package',
            '商品對應表',
            // 同樣不能自己包 <p>，理由見「授權」頁籤那個 twshop_panel_head() 的註解。
            '只做「已存在的雙邊商品依 SKU 對應綁定」，不做跨平台上架。SKU 在 Woo 端出現重複時會標記為「衝突」，不會自動綁定。'
        );
        ?>
        <div class="twshop-panel-body">
            <p>
                <button type="button" class="button" id="twshop-shopee-fetch-items">重新抓取蝦皮商品並自動配對</button>
                <button type="button" class="button" id="twshop-shopee-push-all">立即全量推送</button>
                <span id="twshop-shopee-mapping-status" style="margin-left:8px;"></span>
            </p>
            <p>
                <a href="<?php echo esc_url( twshop_shopee_admin_url( 'mapping' ) ); ?>">全部</a> |
                <a href="<?php echo esc_url( twshop_shopee_admin_url( 'mapping', array( 'status' => 'linked' ) ) ); ?>">已綁定</a> |
                <a href="<?php echo esc_url( twshop_shopee_admin_url( 'mapping', array( 'status' => 'unlinked' ) ) ); ?>">未綁定</a> |
                <a href="<?php echo esc_url( twshop_shopee_admin_url( 'mapping', array( 'status' => 'conflict' ) ) ); ?>">衝突</a>
            </p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>蝦皮商品 ID</th><th>規格 ID</th><th>SKU</th><th>狀態</th>
                        <th>對應 Woo 商品</th><th>上次同步</th><th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="7">目前沒有資料，請先按「重新抓取蝦皮商品並自動配對」。</td></tr>
                <?php else : foreach ( $rows as $row ) :
                    $product = $row['product_id'] ? wc_get_product( $row['product_id'] ) : null;
                ?>
                    <tr data-row-id="<?php echo esc_attr( $row['id'] ); ?>">
                        <td><?php echo esc_html( $row['item_id'] ); ?></td>
                        <td><?php echo esc_html( $row['model_id'] ); ?></td>
                        <td><?php echo esc_html( $row['sku'] ); ?></td>
                        <td><?php echo esc_html( $row['status'] ); ?></td>
                        <td><?php echo $product ? esc_html( $product->get_name() . ' (#' . $product->get_id() . ')' ) : '—'; ?></td>
                        <td><?php echo esc_html( $row['last_synced_at'] ?: '—' ); ?></td>
                        <td>
                            <?php if ( 'linked' === $row['status'] ) : ?>
                                <button type="button" class="button twshop-shopee-unlink" data-id="<?php echo esc_attr( $row['id'] ); ?>">解除綁定</button>
                            <?php else : ?>
                                <input type="number" min="1" class="small-text twshop-shopee-link-product-id" placeholder="Woo 商品ID">
                                <button type="button" class="button twshop-shopee-link" data-id="<?php echo esc_attr( $row['id'] ); ?>">綁定</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

// =========================================================================
// 頁籤：同步設定
// =========================================================================

function twshop_shopee_sync_tab() {
    if ( ! twshop_shopee_has_credentials() ) {
        twshop_shopee_render_not_configured_notice();
        return;
    }

    $settings       = twshop_shopee_sync_settings();
    $order_statuses = array( 'UNPAID', 'READY_TO_SHIP', 'PROCESSED', 'SHIPPED', 'COMPLETED', 'CANCELLED', 'TO_RETURN', 'INVOICE_PENDING' );

    twshop_enqueue_asset_script( 'admin/shopee-mapping', array(
        'twshopShopeeMapping' => array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'twshop_admin_action' ),
            'i18n'    => array(
                'working' => '處理中…',
                'done'    => '完成',
                'error'   => '發生錯誤',
            ),
        ),
    ) );
    ?>
    <form method="post" action="options.php">
        <?php settings_fields( 'twshop_shopee_sync_group' ); ?>
        <div class="twshop-panel">
            <?php twshop_panel_head( 'settings', '同步設定' ); ?>
            <div class="twshop-panel-body">
            <table class="form-table">
                <tr>
                    <th>庫存推送</th>
                    <td><label><input type="checkbox" name="twshop_shopee_sync_settings[stock_push_enabled]" value="yes" <?php checked( $settings['stock_push_enabled'], 'yes' ); ?>> 啟用（Woo → 蝦皮，永遠不回寫）</label></td>
                </tr>
                <tr>
                    <th>價格推送</th>
                    <td><label><input type="checkbox" name="twshop_shopee_sync_settings[price_push_enabled]" value="yes" <?php checked( $settings['price_push_enabled'], 'yes' ); ?>> 啟用</label></td>
                </tr>
                <tr>
                    <th>訂單匯入</th>
                    <td><label><input type="checkbox" name="twshop_shopee_sync_settings[order_import_enabled]" value="yes" <?php checked( $settings['order_import_enabled'], 'yes' ); ?>> 啟用</label></td>
                </tr>
                <tr>
                    <th>要匯入的蝦皮訂單狀態</th>
                    <td>
                    <?php foreach ( $order_statuses as $status ) : ?>
                        <label style="display:inline-block;margin:2px 12px 2px 0;">
                            <input type="checkbox" name="twshop_shopee_sync_settings[order_import_status][]" value="<?php echo esc_attr( $status ); ?>" <?php checked( in_array( $status, (array) $settings['order_import_status'], true ) ); ?>>
                            <?php echo esc_html( $status ); ?>
                        </label>
                    <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="twshop_shopee_default_order_status">Woo 訂單初始狀態</label></th>
                    <td>
                        <select id="twshop_shopee_default_order_status" name="twshop_shopee_sync_settings[default_order_status]">
                            <?php foreach ( wc_get_order_statuses() as $key => $label ) : $slug = str_replace( 'wc-', '', $key ); ?>
                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['default_order_status'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="twshop_shopee_stock_buffer">安全庫存（緩衝量）</label></th>
                    <td><input type="number" min="0" id="twshop_shopee_stock_buffer" name="twshop_shopee_sync_settings[stock_buffer]" value="<?php echo esc_attr( $settings['stock_buffer'] ); ?>" class="small-text"> 推到蝦皮時，庫存會先扣掉這個緩衝量</td>
                </tr>
                <tr>
                    <th><label for="twshop_shopee_log_retention_days">紀錄保留天數</label></th>
                    <td><input type="number" min="1" id="twshop_shopee_log_retention_days" name="twshop_shopee_sync_settings[log_retention_days]" value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>" class="small-text"></td>
                </tr>
            </table>
            </div>
        </div>
        <?php submit_button( '儲存同步設定' ); ?>
    </form>

    <div class="twshop-panel">
        <?php twshop_panel_head( 'clipboard-list', '手動觸發' ); ?>
        <div class="twshop-panel-body">
            <button type="button" class="button" id="twshop-shopee-pull-orders">立即匯入蝦皮訂單</button>
            <span id="twshop-shopee-sync-status" style="margin-left:8px;"></span>
        </div>
    </div>
    <?php
}

// =========================================================================
// 頁籤：同步紀錄
// =========================================================================

function twshop_shopee_log_tab() {
    if ( ! twshop_shopee_has_credentials() ) {
        twshop_shopee_render_not_configured_notice();
        return;
    }

    global $wpdb;
    $table = twshop_shopee_log_table();
    $rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A );

    twshop_enqueue_asset_script( 'admin/shopee-mapping', array(
        'twshopShopeeMapping' => array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'twshop_admin_action' ),
            'i18n'    => array(
                'working'      => '處理中…',
                'done'         => '完成',
                'error'        => '發生錯誤',
                'confirmClear' => '確定要清空所有同步紀錄嗎？此動作無法復原。',
            ),
        ),
    ) );
    ?>
    <div class="twshop-panel">
        <?php twshop_panel_head( 'clipboard-list', '同步紀錄' ); ?>
        <div class="twshop-panel-body">
            <p>
                <button type="button" class="button" id="twshop-shopee-clear-log">清空紀錄</button>
                <span id="twshop-shopee-log-status" style="margin-left:8px;"></span>
            </p>
            <table class="widefat striped">
                <thead><tr><th>時間</th><th>方向</th><th>端點</th><th>對象</th><th>結果</th><th>訊息</th></tr></thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="6">目前沒有紀錄。</td></tr>
                <?php else : foreach ( $rows as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row['created_at'] ); ?></td>
                        <td><?php echo esc_html( $row['direction'] ); ?></td>
                        <td><?php echo esc_html( $row['endpoint'] ); ?></td>
                        <td><?php echo esc_html( $row['subject'] ); ?></td>
                        <td><?php echo $row['success'] ? '成功' : '失敗'; ?></td>
                        <td><?php echo esc_html( mb_substr( (string) $row['message'], 0, 120 ) ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

// =========================================================================
// AJAX handlers（全部 current_user_can('manage_woocommerce') + check_ajax_referer）
// =========================================================================

function twshop_shopee_ajax_guard() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'msg' => '權限不足' ) );
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );
}

function twshop_ajax_shopee_fetch_items() {
    twshop_shopee_ajax_guard();

    if ( ! twshop_shopee_has_credentials() ) wp_send_json_error( array( 'msg' => '尚未設定蝦皮憑證' ) );

    $result = twshop_shopee_fetch_items();
    if ( is_wp_error( $result ) ) wp_send_json_error( array( 'msg' => $result->get_error_message() ) );

    twshop_shopee_auto_match();

    wp_send_json_success( array( 'msg' => '已抓取並自動配對', 'count' => is_array( $result ) ? count( $result ) : 0 ) );
}

function twshop_ajax_shopee_link_item() {
    twshop_shopee_ajax_guard();

    $row_id = absint( $_POST['row_id'] ?? 0 );
    if ( ! $row_id ) wp_send_json_error( array( 'msg' => '缺少列 ID' ) );

    global $wpdb;
    $table = twshop_shopee_items_table();

    if ( ! empty( $_POST['unlink'] ) ) {
        $wpdb->update( $table, array( 'status' => 'unlinked', 'product_id' => 0 ), array( 'id' => $row_id ), array( '%s', '%d' ), array( '%d' ) );
        wp_send_json_success( array( 'msg' => '已解除綁定' ) );
    }

    $product_id = absint( $_POST['product_id'] ?? 0 );
    if ( ! $product_id || ! wc_get_product( $product_id ) ) {
        wp_send_json_error( array( 'msg' => '請輸入有效的 Woo 商品 ID' ) );
    }

    $wpdb->update( $table, array( 'status' => 'linked', 'product_id' => $product_id ), array( 'id' => $row_id ), array( '%s', '%d' ), array( '%d' ) );
    wp_send_json_success( array( 'msg' => '已綁定' ) );
}

function twshop_ajax_shopee_push_now() {
    twshop_shopee_ajax_guard();

    if ( ! twshop_shopee_has_credentials() ) wp_send_json_error( array( 'msg' => '尚未設定蝦皮憑證' ) );

    $product_id = absint( $_POST['product_id'] ?? 0 );

    if ( $product_id ) {
        twshop_shopee_queue_push( $product_id );
    } else {
        global $wpdb;
        $ids = $wpdb->get_col( "SELECT DISTINCT product_id FROM " . twshop_shopee_items_table() . " WHERE status='linked' AND product_id > 0" );
        foreach ( $ids as $id ) twshop_shopee_queue_push( (int) $id );
    }

    twshop_shopee_process_push_queue();

    wp_send_json_success( array( 'msg' => '已推送目前批次（剩餘會由排程接續處理）' ) );
}

function twshop_ajax_shopee_pull_orders() {
    twshop_shopee_ajax_guard();

    if ( ! twshop_shopee_has_credentials() ) wp_send_json_error( array( 'msg' => '尚未設定蝦皮憑證' ) );

    twshop_shopee_pull_orders();

    wp_send_json_success( array( 'msg' => '已觸發訂單匯入' ) );
}

function twshop_ajax_shopee_clear_log() {
    twshop_shopee_ajax_guard();

    global $wpdb;
    $wpdb->query( 'TRUNCATE TABLE ' . twshop_shopee_log_table() );

    wp_send_json_success( array( 'msg' => '已清空紀錄' ) );
}
