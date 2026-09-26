<?php
/**
 * 介面：一般設定（前台文字設定、會員中心頁籤排序與開關）
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// -------------------------------------------------------------------------
// 介面：一般設定（前台文字設定、會員中心頁籤排序與開關）
// -------------------------------------------------------------------------
function twshop_marketing_coupons_tab() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足。' );

    $coupon_noun = twshop_option( 'wc_general_coupon_noun' );
    $page_title  = twshop_option( 'wc_general_coupon_page_title' );
    $page_desc   = twshop_option( 'wc_general_coupon_page_desc' );
    $no_msg      = twshop_option( 'wc_general_no_coupon_msg' );

    $btn_used_text        = twshop_option( 'wc_coupon_btn_used_text' );
    $btn_shop_text        = twshop_option( 'wc_coupon_btn_shop_text' );
    $btn_unavailable_text = twshop_option( 'wc_coupon_btn_unavailable_text' );
    $btn_remove_text      = twshop_option( 'wc_coupon_btn_remove_text' );
    $btn_apply_text       = twshop_option( 'wc_coupon_btn_apply_text' );
    $dialog_trigger_none    = twshop_option( 'wc_coupon_dialog_trigger_none_text' );
    $dialog_trigger_applied = twshop_option( 'wc_coupon_dialog_trigger_applied_text' );
    $dialog_heading         = twshop_option( 'wc_coupon_dialog_heading' );
    ?>
        <form action="options.php" method="post">
            <?php settings_fields( 'wc_marketing_coupons_group' ); ?>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'ticket', '優惠券前台文字' ); ?>
                <div class="twshop-panel-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">「優惠券」名稱</th>
                            <td>
                                <input type="text" name="wc_general_coupon_noun" value="<?php echo esc_attr( $coupon_noun ); ?>" class="regular-text" placeholder="優惠券" />
                                <p class="description">統一定義前台顯示的名詞（例如：折扣碼、優惠、票券）。此設定作為其他欄位的參考依據，並用於系統錯誤提示中。</p>
                            </td>
                        </tr>
                        <tr><th scope="row">優惠券頁面大標題</th><td><input type="text" name="wc_general_coupon_page_title" value="<?php echo esc_attr( $page_title ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">優惠券頁面輔助敘述</th><td><textarea name="wc_general_coupon_page_desc" rows="3" class="regular-text"><?php echo esc_html( $page_desc ); ?></textarea></td></tr>
                        <tr><th scope="row">無優惠券時的提示</th><td><input type="text" name="wc_general_no_coupon_msg" value="<?php echo esc_attr( $no_msg ); ?>" class="regular-text" /></td></tr>
                    </table>
                </div>
            </div>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'pencil', '優惠券卡片文字', '設定優惠券彈窗與卡片的按鈕文字；<code>{noun}</code> 代表優惠券名稱。' ); ?>
                <div class="twshop-panel-body">
                    <table class="form-table">
                        <tr><th scope="row">帳戶頁「已使用」按鈕</th><td><input type="text" name="wc_coupon_btn_used_text" value="<?php echo esc_attr( $btn_used_text ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">帳戶頁「去購物」按鈕</th><td><input type="text" name="wc_coupon_btn_shop_text" value="<?php echo esc_attr( $btn_shop_text ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">購物車頁「暫不可用」按鈕</th><td><input type="text" name="wc_coupon_btn_unavailable_text" value="<?php echo esc_attr( $btn_unavailable_text ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">購物車頁「取消套用」按鈕</th><td><input type="text" name="wc_coupon_btn_remove_text" value="<?php echo esc_attr( $btn_remove_text ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">購物車頁「點擊套用」按鈕</th><td><input type="text" name="wc_coupon_btn_apply_text" value="<?php echo esc_attr( $btn_apply_text ); ?>" class="regular-text" /></td></tr>
                        <tr>
                            <th scope="row">彈窗開啟按鈕（未套用）</th>
                            <td>
                                <input type="text" name="wc_coupon_dialog_trigger_none_text" value="<?php echo esc_attr( $dialog_trigger_none ); ?>" class="regular-text" />
                                <p class="description">可用 <code>{count}</code> 代表目前可用張數。</p>
                            </td>
                        </tr>
                        <tr><th scope="row">彈窗開啟按鈕（已套用）</th><td><input type="text" name="wc_coupon_dialog_trigger_applied_text" value="<?php echo esc_attr( $dialog_trigger_applied ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">彈窗標題</th><td><input type="text" name="wc_coupon_dialog_heading" value="<?php echo esc_attr( $dialog_heading ); ?>" class="regular-text" /></td></tr>
                    </table>
                </div>
            </div>

            <?php submit_button( '儲存優惠券設定' ); ?>
        </form>
    <?php
}

function twshop_system_general_tab() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足。' );

    $login_btn_text    = get_option( 'wc_login_btn_text', '' );
    $register_btn_text = get_option( 'wc_register_btn_text', '' );
    $badge_enabled      = twshop_option( 'wc_badge_enabled' );
    $badge_text_template = get_option( 'wc_badge_text_template', '' );

    // 商品網址：待處理數與方向都是即時算的，不存任何進度狀態（見 twshop_product_slug_batch_mode()）
    $slug_use_id  = twshop_product_slug_use_id_enabled() ? 'yes' : 'no';
    $slug_mode    = twshop_product_slug_batch_mode();
    $slug_pending = twshop_count_pending_product_slugs();
    ?>
        <form action="options.php" method="post">
            <?php settings_fields( 'wc_system_general_group' ); ?>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'user-check', '登入／註冊按鈕文字' ); ?>
                <div class="twshop-panel-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">登入按鈕文字</th>
                            <td>
                                <input type="text" name="wc_login_btn_text" value="<?php echo esc_attr( $login_btn_text ); ?>" class="regular-text" placeholder="登入" />
                                <p class="description">會員中心「我的帳號」頁面登入表單的送出按鈕文字，留空則沿用預設「登入」。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">註冊按鈕文字</th>
                            <td>
                                <input type="text" name="wc_register_btn_text" value="<?php echo esc_attr( $register_btn_text ); ?>" class="regular-text" placeholder="註冊" />
                                <p class="description">會員中心「我的帳號」頁面註冊表單的送出按鈕文字，留空則沿用預設「註冊」。</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'badge', '商品折扣徽章', '只替換商店列表與商品頁特價角標的文字，保留主題樣式。' ); ?>
                <div class="twshop-panel-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">啟用徽章</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_badge_enabled" value="yes" <?php checked( $badge_enabled, 'yes' ); ?> />
                                    在有折扣的商品上顯示徽章
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">徽章文字</th>
                            <td>
                                <input type="text" name="wc_badge_text_template" value="<?php echo esc_attr( $badge_text_template ); ?>" class="regular-text" placeholder="-{percent}%" />
                                <p class="description">可用 <code>{percent}</code> 代表折扣百分比數字（例如商品打 8 折會顯示 20），留空則使用預設「-{percent}%」。僅適用於單一售價的商品，可變商品（多規格浮動區間價）仍沿用主題原生的特價角標。</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'shopping-cart', '傳統購物車自動顯示區塊', '僅適用傳統短代碼購物車；不影響 WooCommerce Cart Block。' ); ?>
                <div class="twshop-panel-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">優惠券區塊</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_classic_cart_show_coupons" value="yes" <?php checked( twshop_option( 'wc_classic_cart_show_coupons' ), 'yes' ); ?> />
                                    在購物車頁面自動顯示優惠券區塊
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">加購區塊</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_classic_cart_show_addons" value="yes" <?php checked( twshop_option( 'wc_classic_cart_show_addons' ), 'yes' ); ?> />
                                    在購物車頁面自動顯示加購區塊
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">滿額進度區塊</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_classic_cart_show_progress" value="yes" <?php checked( twshop_option( 'wc_classic_cart_show_progress' ), 'yes' ); ?> />
                                    在購物車頁面自動顯示滿額/滿件進度提示
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">點數折抵區塊</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_classic_cart_show_points" value="yes" <?php checked( twshop_option( 'wc_classic_cart_show_points' ), 'yes' ); ?> />
                                    在購物車頁面自動顯示點數折抵區塊
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">儲值金折抵區塊</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_classic_cart_show_wallet" value="yes" <?php checked( twshop_option( 'wc_classic_cart_show_wallet' ), 'yes' ); ?> />
                                    在購物車頁面自動顯示儲值金折抵區塊
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'tag', '加購商品顯示文字' ); ?>
                <div class="twshop-panel-body">
                    <table class="form-table">
                        <tr><th scope="row">加購區塊標題</th><td><input type="text" name="wc_addon_section_title" value="<?php echo esc_attr( twshop_option( 'wc_addon_section_title' ) ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">加入加購按鈕文字</th><td><input type="text" name="wc_addon_btn_add_text" value="<?php echo esc_attr( twshop_option( 'wc_addon_btn_add_text' ) ); ?>" class="regular-text" /></td></tr>
                        <tr>
                            <th scope="row">移除按鈕文字（已在購物車時）</th>
                            <td>
                                <input type="text" name="wc_addon_btn_incart_text" value="<?php echo esc_attr( twshop_option( 'wc_addon_btn_incart_text' ) ); ?>" class="regular-text" />
                                <p class="description">商品已在購物車時，按鈕將變為紅色移除鍵，顯示此文字。</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'package', '運送與付款方式名稱', '修改結帳頁顯示名稱；留空沿用原名稱，已成立的訂單不受影響。' ); ?>
                <div class="twshop-panel-body">
                    <?php
                    $shipping_titles = get_option( 'wc_shipping_method_titles', array() );
                    $shipping_rows   = twshop_get_all_shipping_zone_methods();
                    ?>
                    <h3 style="margin-top:0;">運送方式</h3>
                    <?php if ( empty( $shipping_rows ) ) : ?>
                    <p class="twshop-hint">尚未設定任何運送方式。請先到「WooCommerce ▸ 設定 ▸ 運送」新增運送區域與方式。</p>
                    <?php else : ?>
                    <table class="form-table">
                        <?php foreach ( $shipping_rows as $row ) :
                            $method  = $row['method'];
                            // rate id 的組成方式跟 WC_Shipping_Rate 一致（method_id:instance_id），
                            // 才對得上 woocommerce_package_rates 傳進來的陣列 key。
                            $rate_id = $method->id . ':' . $method->instance_id;
                        ?>
                        <tr>
                            <th scope="row">
                                <?php echo esc_html( $method->get_title() ); ?>
                                <p class="description" style="font-weight:normal;"><?php echo esc_html( $row['zone_name'] ); ?></p>
                            </th>
                            <td>
                                <input type="text" class="regular-text"
                                       name="wc_shipping_method_titles[<?php echo esc_attr( $rate_id ); ?>]"
                                       value="<?php echo esc_attr( $shipping_titles[ $rate_id ] ?? '' ); ?>"
                                       placeholder="<?php echo esc_attr( $method->get_title() ); ?>">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php endif; ?>

                    <?php
                    $payment_titles = get_option( 'wc_payment_method_titles', array() );
                    // 只列出「已啟用」的付款方式：綠界一家就掛了 13 個 gateway，全部列出來會是
                    // 一整面幾乎都用不到的欄位，反而找不到要改的那一個。
                    $gateways = array_filter(
                        WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array(),
                        function ( $g ) { return 'yes' === $g->enabled; }
                    );
                    ?>
                    <h3>付款方式</h3>
                    <?php if ( empty( $gateways ) ) : ?>
                    <p class="twshop-hint">目前沒有已啟用的付款方式。請先到「WooCommerce ▸ 設定 ▸ 付款」啟用。</p>
                    <?php else : ?>
                    <table class="form-table">
                        <?php foreach ( $gateways as $gateway ) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html( $gateway->get_title() ); ?></th>
                            <td>
                                <input type="text" class="regular-text"
                                       name="wc_payment_method_titles[<?php echo esc_attr( $gateway->id ); ?>]"
                                       value="<?php echo esc_attr( $payment_titles[ $gateway->id ] ?? '' ); ?>"
                                       placeholder="<?php echo esc_attr( $gateway->get_title() ); ?>">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <p class="twshop-hint">只列出已啟用的付款方式。在「WooCommerce ▸ 設定 ▸ 付款」啟用其他方式後，會自動出現在這裡。</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'tag', '商品頁頁籤', '拖曳調整順序、勾選是否顯示，或自訂名稱；名稱留空沿用原名稱。' ); ?>
                <div class="twshop-panel-body">
                    <?php
                    $tab_titles   = get_option( 'wc_product_tab_titles', array() );
                    $tab_defaults = array(
                        'description'            => array( '描述', '描述' ),
                        'additional_information' => array( '額外資訊', '額外資訊' ),
                        'reviews'                => array( '評價', '評價 ({count})' ),
                    );
                    ?>
                    <div id="product-tabs-repeater-container">
                        <?php foreach ( twshop_get_product_tabs_settings() as $tab_slug => $tab_enabled ) : ?>
                        <div class="twshop-tab-row">
                            <span class="drag-handle twshop-text-muted" style="cursor:move;"><?php echo twshop_get_account_tab_icon_svg( 'grip-vertical' ); ?></span>
                            <input type="hidden" name="wc_product_tabs_settings[slug][]" value="<?php echo esc_attr( $tab_slug ); ?>" />
                            <input type="hidden" class="tab-enabled-input" name="wc_product_tabs_settings[enabled][]" value="<?php echo esc_attr( $tab_enabled ); ?>" />
                            <span class="twshop-tab-row-name">
                                <input type="text" class="regular-text" name="wc_product_tab_titles[<?php echo esc_attr( $tab_slug ); ?>]"
                                       value="<?php echo esc_attr( $tab_titles[ $tab_slug ] ?? '' ); ?>" placeholder="<?php echo esc_attr( $tab_defaults[ $tab_slug ][1] ); ?>">
                                <code class="twshop-text-muted"><?php echo esc_html( $tab_defaults[ $tab_slug ][0] ); ?></code>
                            </span>
                            <label class="twshop-tab-row-toggle">
                                <input type="checkbox" class="tab-enabled-checkbox" <?php checked( 'yes', $tab_enabled ); ?> /> 顯示
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="twshop-hint">評價名稱可用 {count} 帶入評價數量。其他外掛新增的頁籤不受影響。</p>
                    <?php twshop_enqueue_asset_script( 'admin/product-tabs' ); ?>
                </div>
            </div>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'tag', '商品圖片 alt', '開啟後，商品圖片的 alt 一律依下列格式產生，不使用媒體庫裡原本的 alt（不會修改媒體庫）。' ); ?>
                <div class="twshop-panel-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">啟用</th>
                            <td><label><input type="checkbox" name="wc_product_image_alt_enabled" value="yes" <?php checked( get_option( 'wc_product_image_alt_enabled', 'no' ), 'yes' ); ?> /> 商品圖片 alt 統一由格式產生</label></td>
                        </tr>
                        <tr>
                            <th scope="row">主圖</th>
                            <td><input type="text" class="regular-text" name="wc_product_image_alt_main" value="<?php echo esc_attr( twshop_option( 'wc_product_image_alt_main' ) ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row">圖庫（第 2 張起）</th>
                            <td><input type="text" class="regular-text" name="wc_product_image_alt_gallery" value="<?php echo esc_attr( twshop_option( 'wc_product_image_alt_gallery' ) ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row">規格圖</th>
                            <td><input type="text" class="regular-text" name="wc_product_image_alt_variation" value="<?php echo esc_attr( twshop_option( 'wc_product_image_alt_variation' ) ); ?>" /></td>
                        </tr>
                    </table>
                    <p class="twshop-hint">可用 <code>{name}</code> 商品名稱、<code>{n}</code> 第幾張（主圖為 1）、<code>{attributes}</code> 規格值（例如「紅色 L」）。</p>
                </div>
            </div>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'tag', '商品網址（slug）', '啟用後以商品編號產生網址，避免中文名稱轉成難辨識的編碼。' ); ?>
                <div class="twshop-panel-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">網址格式</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_product_slug_use_id" value="yes" <?php checked( $slug_use_id, 'yes' ); ?> />
                                    商品網址改用商品編號
                                </label>
                                <p class="description">
                                    儲存後會自動把全站既有商品一併轉換。<strong>轉換前的網址會保留下來</strong>，舊連結（顧客的收藏、搜尋引擎收錄的、已寄出的通知信）會自動轉向新網址，不會變成 404。
                                    取消勾選並儲存則會自動還原成轉換前的網址。
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">目前狀態</th>
                            <td>
                                <div id="twshop-slug-batch"
                                     data-remaining="<?php echo esc_attr( $slug_pending ); ?>"
                                     data-mode="<?php echo esc_attr( $slug_mode ); ?>">
                                    <?php if ( $slug_pending > 0 ) : ?>
                                        <span class="twshop-badge twshop-badge--warn">
                                            尚有 <span class="twshop-slug-remaining"><?php echo (int) $slug_pending; ?></span> 個商品的網址還沒<?php echo 'convert' === $slug_mode ? '轉換' : '還原'; ?>
                                        </span>
                                        <p class="twshop-hint twshop-slug-status">正在處理，請不要關閉這個頁面…</p>
                                    <?php else : ?>
                                        <span class="twshop-badge twshop-badge--ok">全部商品的網址都已是目前設定的格式</span>
                                    <?php endif; ?>
                                </div>
                                <p class="description">商品數量多時會分批處理，中途離開頁面也不會壞掉——剩餘數量是即時計算的，回到這一頁會自動繼續。</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            <?php twshop_enqueue_asset_script( 'admin/product-slug', array(
                'twshopProductSlug' => array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( 'twshop_admin_action' ),
                ),
            ) ); ?>

            <?php submit_button( '儲存一般設定' ); ?>
        </form>
    <?php
}

function twshop_member_tabs_tab() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足。' );

    $account_tabs_labels   = twshop_get_all_registered_account_tabs();
    $account_tabs_settings = twshop_get_account_tabs_settings( array_keys( $account_tabs_labels ) );
    $account_tabs_icons    = get_option( 'wc_account_tab_icons', twshop_get_account_tab_icon_defaults() );
    $tab_mobile_scroll     = twshop_option( 'wc_account_tab_mobile_scroll' );
    ?>
        <?php if (isset($_GET['account_tabs_reset'])) : ?>
            <div class="notice notice-success is-dismissible"><p>已將會員中心頁籤排序、開關與名稱恢復為預設值。</p></div>
        <?php endif; ?>

        <form action="options.php" method="post">
            <?php settings_fields( 'wc_member_tabs_group' ); ?>

            <div class="twshop-panel">
                <?php twshop_panel_head( 'list', '會員中心頁籤排序與開關', '拖曳排序、勾選啟用狀態，或直接修改頁籤名稱；留空沿用預設名稱。', array( 'url' => wp_nonce_url( twshop_admin_url( 'system', array( 'tab' => 'tabs', 'reset_account_tabs' => 1 ) ), 'twshop_reset_account_tabs' ), 'label' => '恢復預設值', 'class' => 'button button-secondary', 'confirm' => '確定要將頁籤排序、開關與名稱恢復為預設值嗎？此動作會立即儲存，無法復原。' ) ); ?>
                <div class="twshop-panel-body">
                    <div id="account-tabs-repeater-container">
                        <?php foreach ( $account_tabs_settings as $row ) {
                            if ( ! isset( $account_tabs_labels[ $row['slug'] ] ) ) continue; // 對應外掛已停用，略過孤兒設定
                            echo twshop_get_account_tab_row_html( $row['slug'], $account_tabs_labels[ $row['slug'] ], $row['enabled'], $account_tabs_icons[ $row['slug'] ] ?? '' );
                        } ?>
                    </div>
                    <?php twshop_render_account_tab_icon_picker_assets(); ?>

                    <p style="margin:14px 0 0;">
                        <label>
                            <input type="checkbox" name="wc_account_tab_mobile_scroll" value="yes" <?php checked( $tab_mobile_scroll, 'yes' ); ?> />
                            手機版頁籤改為橫向滑動
                        </label>
                    </p>
                </div>
            </div>

            <?php submit_button( '儲存頁籤設定' ); ?>
        </form>
    <?php twshop_enqueue_asset_script( 'admin/account-tabs' ); ?>
    <?php
}

/**
 * 判斷兩筆規則是否「角色相容 ＋ 生效時間有重疊 ＋ 限制條件範圍有交集」，三項皆符合才視為可能同時命中。
 * 只用於後台重疊提示（純資訊性），不影響任何實際計算。
 */
function twshop_rules_may_overlap( $rule_a, $rule_b ) {
    // 角色：其中一個是 all，或兩者角色相同，才算相容
    $role_a = $rule_a['role'] ?? 'all'; $role_b = $rule_b['role'] ?? 'all';
    if ( $role_a !== 'all' && $role_b !== 'all' && $role_a !== $role_b ) return false;

    // 生效時間：任一方未設定起訖視為永遠有效；否則兩個區間需有交集
    $start_a = ! empty( $rule_a['start_time'] ) ? strtotime( $rule_a['start_time'] ) : null;
    $end_a   = ! empty( $rule_a['end_time'] ) ? strtotime( $rule_a['end_time'] ) : null;
    $start_b = ! empty( $rule_b['start_time'] ) ? strtotime( $rule_b['start_time'] ) : null;
    $end_b   = ! empty( $rule_b['end_time'] ) ? strtotime( $rule_b['end_time'] ) : null;
    if ( $end_a !== null && $start_b !== null && $end_a < $start_b ) return false;
    if ( $end_b !== null && $start_a !== null && $end_b < $start_a ) return false;

    // 限制條件範圍：其中一方無限制＝涵蓋對方全部範圍；同 condition_type 才比對 condition_values 是否有交集，
    // 不同類型（例如一個限定商品、一個限定分類）保守視為不重疊，避免誤判。
    $cond_type_a = $rule_a['condition_type'] ?? ''; $cond_values_a = (array) ( $rule_a['condition_values'] ?? array() );
    $cond_type_b = $rule_b['condition_type'] ?? ''; $cond_values_b = (array) ( $rule_b['condition_values'] ?? array() );
    if ( empty( $cond_type_a ) || empty( $cond_type_b ) ) return true;
    if ( $cond_type_a !== $cond_type_b ) return false;
    $intersect = array_intersect( array_map( 'strval', $cond_values_a ), array_map( 'strval', $cond_values_b ) );
    return ! empty( $intersect );
}

/**
 * 純讀取、不影響任何計算邏輯：兩兩比對「同一疊加群組」內已啟用的規則，抓出套用範圍可能重疊
 * 的組合，給後台一個視覺提示，避免管理員設定多筆規則時沒注意到彼此會同時套用。群組劃分沿用
 * 計算邏輯本身的疊加群組（product 層 percent/fixed_product、cart 層 cart_percent/cart_discount/
 * tiered_cart），另外 buy_x_get_y 也各自比對——這幾種型別才會發生「同一顧客同一商品被算兩次」
 * 的疊加，free_shipping/free_gift/addon_product 本質是各自獨立觸發的動作，不在此比對範圍內。
 */
function twshop_detect_rule_overlaps( $rules ) {
    $groups = array(
        array( 'percent', 'fixed_product' ),
        array( 'cart_percent', 'cart_discount', 'tiered_cart' ),
        array( 'buy_x_get_y' ),
    );

    $enabled_rules = array_values( array_filter( $rules, function( $r ) {
        return ( $r['enabled'] ?? 'yes' ) !== 'no';
    } ) );

    $overlaps = array();
    foreach ( $groups as $group_types ) {
        $group_rules = array_values( array_filter( $enabled_rules, function( $r ) use ( $group_types ) {
            return in_array( $r['type'], $group_types, true );
        } ) );
        $count = count( $group_rules );
        for ( $i = 0; $i < $count; $i++ ) {
            for ( $j = $i + 1; $j < $count; $j++ ) {
                if ( twshop_rules_may_overlap( $group_rules[ $i ], $group_rules[ $j ] ) ) {
                    $overlaps[] = array( $group_rules[ $i ], $group_rules[ $j ] );
                }
            }
        }
    }
    return $overlaps;
}

/**
 * 在規則列表最上方輸出重疊提示（若有）。純資訊性，不阻擋任何操作——多筆規則的套用範圍重疊
 * 有時候是管理員刻意要疊加，不該一律視為錯誤，只是提醒「這些規則可能會同時套用」。
 */
function twshop_render_rule_overlap_warnings( $rules ) {
    $overlaps = twshop_detect_rule_overlaps( $rules );
    if ( empty( $overlaps ) ) return '';
    ob_start();
    ?>
    <div class="twshop-panel" style="margin-bottom:15px; border-color:#f0dfa8;">
        <div class="twshop-panel-body" style="padding:15px;">
            <p style="margin:0 0 8px;"><span class="twshop-badge twshop-badge--warn">⚠ 套用範圍重疊提示</span></p>
            <ul style="margin:0; padding-left:20px;">
                <?php foreach ( $overlaps as $pair ) : ?>
                    <li>規則「<?php echo esc_html( $pair[0]['name'] ?: '未命名規則' ); ?>」與「<?php echo esc_html( $pair[1]['name'] ?: '未命名規則' ); ?>」的套用範圍重疊，符合資格的顧客結帳時可能會同時套用兩者。</li>
                <?php endforeach; ?>
            </ul>
            <p class="description" style="margin:8px 0 0;">純提示訊息，不影響儲存——若是刻意要疊加套用可以忽略；若不是，可勾選其中一筆規則的「套用後不與同類型其他規則疊加」。</p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
