/**
 * 團購頁購物車面板：點底部購物車列展開／收合，在面板裡改數量或移除商品。
 * 面板內容與 nonce 由 WooCommerce 購物車片段（div.twshop-gb-cartpanel__items）帶入，頁面本身可被快取。
 * 更新走 wc-ajax=twshop_gb_cart_update，回傳的片段直接替換，並觸發 removed_from_cart 讓 wc-cart-fragments 更新 sessionStorage。
 */
jQuery(function ($) {
    var $panel = $('#twshop-gb-cartpanel');
    var $toggle = $('.twshop-gb-cartbar__toggle');
    var $bar = $('.twshop-gb-cartbar');
    if (!$panel.length || !$toggle.length || typeof twshopGroupBuyCart === 'undefined') return;
    var $error = $panel.find('.twshop-gb-cartpanel__error');
    var timers = {};

    // 面板與 LINE 浮動按鈕都依購物車列的實際高度定位（手機上小計換行時列會變高）
    function place() {
        var h = Math.round($bar.outerHeight());
        $panel.css('bottom', h + 'px');
        document.documentElement.style.setProperty('--twshop-gb-cartbar-h', h + 'px');
    }
    function open(show) {
        var empty = String($('.twshop-gb-cartbar__summary').attr('data-count')) === '0';
        show = show && !empty;
        $panel.prop('hidden', !show);
        $toggle.attr('aria-expanded', show ? 'true' : 'false');
        document.body.classList.toggle('twshop-gb-cart-open', show);
        if (show) { place(); $(document.body).trigger('wc_fragment_refresh'); }
    }
    function showError(message) {
        $error.text(message || '更新失敗，請稍後再試。').prop('hidden', false);
    }
    function applyFragments(data) {
        if (!data || !data.fragments) return;
        $.each(data.fragments, function (selector, html) { $(selector).replaceWith(html); });
        $(document.body).trigger('removed_from_cart', [data.fragments, data.cart_hash, $()]);
    }
    function update(key, quantity) {
        var $items = $panel.find('.twshop-gb-cartpanel__items');
        $error.prop('hidden', true);
        $items.addClass('is-busy');
        $.post(twshopGroupBuyCart.endpoint, { cart_item_key: key, quantity: quantity, nonce: $items.attr('data-nonce') || '' })
            .done(function (res) {
                if (res && res.success === false) { showError(res.data && res.data.message); $(document.body).trigger('wc_fragment_refresh'); return; }
                applyFragments(res);
                if (String($('.twshop-gb-cartbar__summary').attr('data-count')) === '0') open(false);
            })
            .fail(function (xhr) {
                var res = xhr.responseJSON;
                showError(res && res.data && res.data.message);
                $(document.body).trigger('wc_fragment_refresh');
            })
            .always(function () { $panel.find('.twshop-gb-cartpanel__items').removeClass('is-busy'); });
    }

    $toggle.on('click', function () { open($panel.prop('hidden')); });
    $toggle.on('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open($panel.prop('hidden')); }
    });
    $panel.on('click', '.twshop-gb-cartpanel__close', function () { open(false); $toggle.trigger('focus'); });
    $(document).on('keydown', function (e) { if (e.key === 'Escape' && !$panel.prop('hidden')) open(false); });
    $(window).on('resize', place);
    if (window.ResizeObserver) new ResizeObserver(place).observe($bar[0]);
    place();

    $panel.on('click', '.twshop-gb-qty-btn', function () {
        var $input = $(this).siblings('.twshop-gb-qty');
        var max = parseInt($input.attr('max'), 10);
        var next = Math.max(0, (parseInt($input.val(), 10) || 0) + parseInt($(this).data('step'), 10));
        if (max > 0) next = Math.min(next, max);
        $input.val(next).trigger('change');
    });
    // 連續點 +／− 時等 400ms 再送出最後的數量
    $panel.on('change', '.twshop-gb-qty', function () {
        var key = $(this).closest('.twshop-gb-cartpanel__item').data('key');
        var qty = Math.max(0, parseInt($(this).val(), 10) || 0);
        clearTimeout(timers[key]);
        timers[key] = setTimeout(function () { update(key, qty); }, 400);
    });
    $panel.on('click', '.twshop-gb-cartpanel__remove', function () {
        update($(this).closest('.twshop-gb-cartpanel__item').data('key'), 0);
    });
    // 從團購頁加入購物車後（整頁 POST）或其他頁更新片段時，同步收合狀態
    $(document.body).on('wc_fragments_refreshed wc_fragments_loaded', function () {
        if (String($('.twshop-gb-cartbar__summary').attr('data-count')) === '0') open(false);
    });
});
