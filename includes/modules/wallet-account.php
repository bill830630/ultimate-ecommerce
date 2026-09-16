<?php
/**
 * 儲值金：會員中心「我的儲值金」頁籤。
 *
 * 手動加扣 UI 原本在使用者個人資料頁（`profile_personal_options` 等 hook），v25.8.66 起
 * 搬到後台「儲值金 ▸ 會員餘額」頁籤（`twshop_wallet_balances_tab()`，`page-wallet.php`），
 * 管理員找會員餘額跟調整餘額現在是同一個地方，不用再跳去使用者編輯頁。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 會員中心「我的儲值金」頁籤內容，掛 woocommerce_account_my-wallet_endpoint（見 init.php）。
 *
 * v25.8.67 起這裡不再有「立即儲值」按鈕區塊——線上儲值改用「儲值金商品」，顧客直接在
 * 商店頁/商品頁把儲值金商品加進購物車購買即可，跟買其他商品完全一樣，不需要會員中心
 * 另外提供專屬的購買入口。
 */
function twshop_my_wallet_endpoint_content() {
    $user_id = get_current_user_id();
    $balance = twshop_wallet_get_balance( $user_id );
    $history = twshop_wallet_get_ledger( $user_id, 30 );
    ?>
    <div class="twshop-wallet-account">
        <p style="font-size:22px; font-weight:bold; margin-bottom:4px;">
            NT$<?php echo esc_html( number_format( $balance, 2 ) ); ?>
        </p>

        <h4>交易紀錄</h4>
        <?php if ( empty( $history ) ) : ?>
            <p>目前尚無交易紀錄。</p>
        <?php else : ?>
            <table class="woocommerce-table shop_table twshop-wallet-history">
                <thead>
                    <tr>
                        <th>時間</th><th>類型</th><th>金額</th><th>備註</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $history as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row['created_at'] ); ?></td>
                        <td><?php echo esc_html( twshop_wallet_type_label( $row['type'] ) ); ?></td>
                        <td><?php echo esc_html( twshop_wallet_signed_amount( $row['amount_paid'] ) ); ?></td>
                        <td><?php echo esc_html( $row['note'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
