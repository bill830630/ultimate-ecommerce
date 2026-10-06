/**
 * 退換貨後台：訂單編輯頁「退換貨申請」metabox 裡的審核操作（核准／拒絕／標記收到／建立退款／換貨完成／備註）。
 * 同一張訂單可能有多筆申請，每筆是一個 .twshop-returns-actions 面板（data-return-id），事件委派到各自的面板。
 * 一律走 admin-ajax（twshop_returns_admin），成功後重新整理頁面顯示新狀態。
 */
jQuery(function ($) {
    var cfg = window.twshopReturnsAdmin || {};

    function msg($panel, text, isError) {
        $panel.find('.twshop-returns-admin-msg').text(text || '').toggleClass('twshop-text-danger', !!isError);
    }

    // 退款金額：勾「一併退運費」時換成含運費的試算金額
    $(document).on('change', '.twshop-returns-actions [data-field="include_shipping"]', function () {
        var $amount = $(this).closest('.twshop-returns-actions').find('[data-field="amount"]');
        $amount.val($(this).is(':checked') ? $amount.data('amount-ship') : $amount.data('amount-no-ship'));
    });

    $(document).on('click', '.twshop-returns-actions .twshop-returns-op', function () {
        var $btn = $(this), $panel = $btn.closest('.twshop-returns-actions');
        var data = { action: 'twshop_returns_admin', twshop_nonce: cfg.nonce, return_id: $panel.data('return-id'), op: $btn.data('op') };
        $panel.find('[data-field]').each(function () {
            var $f = $(this), key = $f.data('field');
            data[key] = $f.is(':checkbox') ? ($f.is(':checked') ? '1' : '0') : $f.val();
        });
        $btn.prop('disabled', true);
        msg($panel, '處理中…', false);
        $.post(cfg.ajaxUrl, data, null, 'json').done(function (res) {
            if (res && res.success) {
                msg($panel, res.data.msg || '已完成', false);
                window.setTimeout(function () { window.location.reload(); }, 600);
            } else {
                msg($panel, (res && res.data && res.data.msg) || '操作失敗', true);
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            msg($panel, '連線失敗，請稍後再試。', true);
            $btn.prop('disabled', false);
        });
    });
});
