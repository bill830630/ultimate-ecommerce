/**
 * 團購頁購物車面板：點底部購物車列展開／收合，在面板裡改數量或移除商品。
 * 面板內容與 nonce 由 WooCommerce 購物車片段（div.twshop-gb-cartpanel__items）帶入，頁面本身可被快取。
 * 更新走 wc-ajax=twshop_gb_cart_update，回傳的片段直接替換，並觸發 removed_from_cart 讓 wc-cart-fragments 更新 sessionStorage。
 * 整頁送出加入購物車後，依購物車列的 data-just-added 捲回剛加入的商品。
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
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var closeTimer;
    // 展開：先取消 hidden 再加 is-open 觸發滑入；收合：拿掉 is-open 等滑出結束才設回 hidden
    function open(show) {
        var empty = String($('.twshop-gb-cartbar__summary').attr('data-count')) === '0';
        show = show && !empty;
        var isOpen = $panel.hasClass('is-open');
        $toggle.attr('aria-expanded', show ? 'true' : 'false');
        document.body.classList.toggle('twshop-gb-cart-open', show);
        clearTimeout(closeTimer);
        if (show) {
            $panel.prop('hidden', false);
            place();
            if (!isOpen) {
                void $panel[0].offsetWidth; // 讓瀏覽器先套用起始狀態，滑入才有轉場
                $panel.addClass('is-open');
                $(document.body).trigger('wc_fragment_refresh');
            }
        } else {
            $panel.removeClass('is-open');
            closeTimer = setTimeout(function () { $panel.prop('hidden', true); }, reduceMotion ? 0 : 220);
        }
    }
    function isPanelOpen() { return $panel.hasClass('is-open'); }
    function snapshot() {
        var map = {};
        $panel.find('.twshop-gb-cartpanel__item').each(function () {
            map[$(this).data('key')] = $.trim($(this).find('.twshop-gb-qty').val() || $(this).find('.twshop-gb-cartpanel__qty-fixed').text()) + '|' + $.trim($(this).find('.twshop-gb-cartpanel__subtotal').text());
        });
        map.__total = $.trim($panel.find('.twshop-gb-cartpanel__total').text());
        return map;
    }
    function flash($el) {
        $el.removeClass('is-updated');
        if (!$el.length) return;
        void $el[0].offsetWidth;
        $el.addClass('is-updated');
    }
    // 片段替換後，數量或小計有變的那幾列與合計亮一下
    function markChanges(before) {
        $panel.find('.twshop-gb-cartpanel__item').each(function () {
            var key = $(this).data('key');
            var now = $.trim($(this).find('.twshop-gb-qty').val() || $(this).find('.twshop-gb-cartpanel__qty-fixed').text()) + '|' + $.trim($(this).find('.twshop-gb-cartpanel__subtotal').text());
            if (before[key] !== undefined && before[key] !== now) flash($(this));
        });
        var $total = $panel.find('.twshop-gb-cartpanel__total');
        if (before.__total && before.__total !== $.trim($total.text())) flash($total);
    }
    // 移除的那一列先滑出、收起高度，再換成新內容
    function collapse($item, done) {
        var el = $item[0];
        if (!el || reduceMotion || !el.animate) { done(); return; }
        var h = el.offsetHeight;
        var anim = el.animate([
            { height: h + 'px', opacity: 1, transform: 'translateX(0)' },
            { height: h + 'px', opacity: 0, transform: 'translateX(24px)', offset: .55 },
            { height: '0px', opacity: 0, transform: 'translateX(24px)', paddingTop: '0px', paddingBottom: '0px', borderBottomWidth: '0px' }
        ], { duration: 320, easing: 'ease-in', fill: 'forwards' });
        var finished = false;
        function finish() { if (finished) return; finished = true; done(); }
        anim.onfinish = finish;
        setTimeout(finish, 450); // 分頁在背景時 onfinish 可能延後
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
        var $item = $items.find('.twshop-gb-cartpanel__item').filter(function () { return $(this).data('key') === key; });
        $error.prop('hidden', true);
        $items.addClass('is-busy');
        $item.addClass('is-pending');
        $.post(twshopGroupBuyCart.endpoint, { cart_item_key: key, quantity: quantity, nonce: $items.attr('data-nonce') || '' })
            .done(function (res) {
                if (res && res.success === false) { showError(res.data && res.data.message); $(document.body).trigger('wc_fragment_refresh'); return; }
                var before = snapshot();
                var apply = function () {
                    applyFragments(res);
                    markChanges(before);
                    if (String($('.twshop-gb-cartbar__summary').attr('data-count')) === '0') open(false);
                };
                if (quantity === 0) collapse($item, apply); else apply();
            })
            .fail(function (xhr) {
                var res = xhr.responseJSON;
                showError(res && res.data && res.data.message);
                $(document.body).trigger('wc_fragment_refresh');
            })
            .always(function () { $panel.find('.twshop-gb-cartpanel__items').removeClass('is-busy').find('.is-pending').removeClass('is-pending'); });
    }

    $toggle.on('click', function () { open(!isPanelOpen()); });
    $toggle.on('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(!isPanelOpen()); }
    });
    $panel.on('click', '.twshop-gb-cartpanel__close', function () { open(false); $toggle.trigger('focus'); });
    $(document).on('keydown', function (e) { if (e.key === 'Escape' && isPanelOpen()) open(false); });
    $(window).on('resize', place);
    if (window.ResizeObserver) new ResizeObserver(place).observe($bar[0]);
    place();

    $panel.on('click', '.twshop-gb-qty-btn', function () {
        var $input = $(this).siblings('.twshop-gb-qty');
        var max = parseInt($input.attr('max'), 10);
        var next = Math.max(0, (parseInt($input.val(), 10) || 0) + parseInt($(this).data('step'), 10));
        if (max > 0) next = Math.min(next, max);
        if (String(next) !== String($input.val())) flash($input);
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
    // 整頁送出加入購物車後，瀏覽器載入 POST 回應會停在頁首；捲回剛加入的商品
    var justAdded = parseInt($bar.attr('data-just-added'), 10);
    if (justAdded) {
        var $added = $('.twshop-gb__card[data-product-id="' + justAdded + '"]').first();
        if ($added.length && $added[0].scrollIntoView) $added[0].scrollIntoView({ block: 'center' });
    }

    // 從團購頁加入購物車後（整頁 POST）或其他頁更新片段時，同步收合狀態
    $(document.body).on('wc_fragments_refreshed wc_fragments_loaded', function () {
        if (String($('.twshop-gb-cartbar__summary').attr('data-count')) === '0') open(false);
    });
});
