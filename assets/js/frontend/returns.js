/**
 * 退換貨（會員中心）：申請表單送出、填寫寄回單號、取消申請。
 * 會員中心頁籤是 twshop-account-nav.js 用 AJAX 局部切換內容，所以全部用事件委派綁在 document 上，
 * 不能只綁頁面載入當下已存在的元素。
 */
jQuery(function ($) {
    var cfg = window.twshopReturns || {};

    function message($scope, text, isError) {
        $scope.find('.twshop-returns-message').first()
            .text(text || '')
            .toggleClass('is-error', !!isError);
    }

    function post(data, $scope, $btn) {
        $btn.prop('disabled', true);
        message($scope, '處理中…', false);
        var opts = { url: cfg.ajaxUrl, method: 'POST', data: data, dataType: 'json' };
        if (data instanceof FormData) { opts.processData = false; opts.contentType = false; }
        $.ajax(opts).done(function (res) {
            if (res && res.success) {
                message($scope, res.data.msg || '完成', false);
                if (res.data.redirect) window.location.href = res.data.redirect;
            } else {
                message($scope, (res && res.data && res.data.msg) || '送出失敗，請稍後再試。', true);
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            message($scope, '連線失敗，請檢查網路後再試。', true);
            $btn.prop('disabled', false);
        });
    }

    // 換貨才顯示「想換成」欄位
    function syncType($form) {
        var type = $form.find('input[name="type"]:checked').val() || $form.find('input[name="type"]').val();
        $form.find('.twshop-returns-exchange-field').toggle(type === 'exchange');
    }
    $(document).on('change', '.twshop-returns-form input[name="type"]', function () { syncType($(this).closest('form')); });
    $('.twshop-returns-form').each(function () { syncType($(this)); });

    $(document).on('submit', '.twshop-returns-form', function (e) {
        e.preventDefault();
        var $form = $(this), $btn = $form.find('.twshop-returns-submit');
        var $qty = $form.find('.twshop-returns-qty');
        if ($qty.length) { // 取消訂單申請是整張訂單，沒有商品數量欄
            var any = false;
            $qty.each(function () { if (parseInt($(this).val(), 10) > 0) any = true; });
            if (!any) { message($form, '請選擇要申請的商品與數量。', true); return; }
        }
        if (!$form.find('select[name="reason"]').val()) { message($form, '請選擇申請原因。', true); return; }

        var fd = new FormData(this);
        fd.append('action', 'twshop_returns_submit');
        fd.append('twshop_nonce', cfg.nonce);
        post(fd, $form, $btn);
    });

    $(document).on('submit', '.twshop-returns-ship-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        post({
            action: 'twshop_returns_ship',
            twshop_nonce: cfg.nonce,
            return_id: $form.data('return-id'),
            carrier: $form.find('input[name="carrier"]').val(),
            tracking_no: $form.find('input[name="tracking_no"]').val()
        }, $form, $form.find('button[type="submit"]'));
    });

    $(document).on('click', '.twshop-returns-cancel', function () {
        var $btn = $(this);
        if (!window.confirm('確定要取消這筆申請嗎？')) return;
        post({ action: 'twshop_returns_cancel', twshop_nonce: cfg.nonce, return_id: $btn.data('return-id') }, $btn.parent(), $btn);
    });
});
