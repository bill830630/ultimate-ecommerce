/**
 * 訂單編輯頁「儲值金」metabox：手動退回儲值金按鈕。
 *
 * 自 wallet-checkout.php 的內嵌 <script> 抽出（v25.8.107），nonce 由 twshopOrderWallet 提供。
 */
jQuery(function($){
    $('#twshop-wallet-manual-return').on('click', function(){
        var $btn = $(this);
        if (!confirm('確定要把尚未退回的儲值金全部退回給這位會員嗎？')) return;
        $btn.prop('disabled', true).text('處理中…');
        $.post(ajaxurl, {
            action: 'twshop_wallet_manual_return',
            order_id: $btn.data('order-id'),
            twshop_nonce: twshopOrderWallet.nonce
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
