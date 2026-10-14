/**
 * 團購專屬頁：商品描述收合、商品圖片輪播、期間倒數。
 */

/**
 * 商品圖片輪播：滑動本身靠 CSS scroll-snap（沒有 JS 也能用手指滑），
 * 這裡補上左右箭頭、圓點，以及選規格時切到該規格的圖片（取消選擇時還原）。
 */
(function () {
    var galleries = document.querySelectorAll('.twshop-gb__gallery');
    Array.prototype.forEach.call(galleries, function (gallery) {
        var track = gallery.querySelector('.twshop-gb__slides');
        var slides = track ? track.children : [];
        if (slides.length < 2) return;

        function current() { return Math.round(track.scrollLeft / (track.clientWidth || 1)); }
        function go(i) {
            i = Math.max(0, Math.min(slides.length - 1, i));
            track.scrollTo({ left: i * track.clientWidth, behavior: 'smooth' });
        }
        function button(cls, label, delta) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'twshop-gb__nav ' + cls;
            b.setAttribute('aria-label', label);
            b.addEventListener('click', function () { go(current() + delta); });
            gallery.appendChild(b);
            return b;
        }
        var prev = button('twshop-gb__nav--prev', '上一張圖片', -1);
        var next = button('twshop-gb__nav--next', '下一張圖片', 1);
        var dots = document.createElement('div');
        dots.className = 'twshop-gb__dots';
        dots.setAttribute('aria-hidden', 'true');
        for (var i = 0; i < slides.length; i++) dots.appendChild(document.createElement('span'));
        gallery.appendChild(dots);

        function update() {
            var idx = current();
            Array.prototype.forEach.call(dots.children, function (dot, n) { dot.classList.toggle('is-active', n === idx); });
            prev.disabled = idx <= 0;
            next.disabled = idx >= slides.length - 1;
        }
        var pending = false;
        track.addEventListener('scroll', function () {
            if (pending) return;
            pending = true;
            requestAnimationFrame(function () { pending = false; update(); });
        }, { passive: true });
        track.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') { e.preventDefault(); go(current() - 1); }
            if (e.key === 'ArrowRight') { e.preventDefault(); go(current() + 1); }
        });
        window.addEventListener('resize', function () { track.scrollLeft = current() * track.clientWidth; update(); });
        update();
    });

    // 規格換圖：WooCommerce 在表單上以 jQuery 觸發 found_variation／reset_data
    var $ = window.jQuery;
    if (!$) return;
    var ATTRS = ['src', 'srcset', 'sizes', 'alt'];
    function firstImage(form) {
        var card = form.closest('.twshop-gb__card');
        return card ? card.querySelector('.twshop-gb__image img') : null;
    }
    function scrollToFirst(img) {
        var track = img.closest('.twshop-gb__slides');
        if (track) track.scrollTo({ left: 0, behavior: 'smooth' });
    }
    $(document.body).on('found_variation', '.twshop-gb__card form.variations_form', function (e, variation) {
        var img = firstImage(this);
        if (!img || !variation || !variation.image || !variation.image.src) return;
        if (!img.hasAttribute('data-twshop-o-src')) {
            ATTRS.forEach(function (a) { img.setAttribute('data-twshop-o-' + a, img.getAttribute(a) || ''); });
        }
        ATTRS.forEach(function (a) {
            var v = variation.image[a] || '';
            if (v) img.setAttribute(a, v); else img.removeAttribute(a);
        });
        img.removeAttribute('loading');
        scrollToFirst(img);
    });
    $(document.body).on('reset_data', '.twshop-gb__card form.variations_form', function () {
        var img = firstImage(this);
        if (!img || !img.hasAttribute('data-twshop-o-src')) return;
        ATTRS.forEach(function (a) {
            var v = img.getAttribute('data-twshop-o-' + a);
            if (v) img.setAttribute(a, v); else img.removeAttribute(a);
        });
    });
})();

/** 描述超過約 3 行時收合，並在下方加「看更多／收起」切換。 */
(function () {
    var descs = document.querySelectorAll('.twshop-gb__desc');
    Array.prototype.forEach.call(descs, function (desc) {
        desc.classList.add('is-clamped');
        // 多出不到半行就不收合，避免只為一點點內容出現按鈕
        if (desc.scrollHeight <= desc.clientHeight + 8) { desc.classList.remove('is-clamped'); return; }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'twshop-gb__more';
        btn.textContent = '看更多';
        btn.setAttribute('aria-expanded', 'false');
        btn.addEventListener('click', function () {
            var open = desc.classList.toggle('is-clamped') === false;
            btn.textContent = open ? '收起' : '看更多';
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        desc.parentNode.insertBefore(btn, desc.nextSibling);
    });
})();

/**
 * 期間倒數。頁面可能被 CDN 快取，所以狀態以瀏覽器當下時間判斷：
 * 跨過開始／結束時間時重新載入一次（sessionStorage 防止 CDN 仍回舊頁時無限重載），
 * 重載後仍是舊狀態就改顯示提示文字。加入購物車與結帳時伺服器另外驗證期間。
 */
(function () {
    var box = document.querySelector('.twshop-gb-period');
    if (!box) return;
    var state = box.getAttribute('data-state');
    var start = parseInt(box.getAttribute('data-start'), 10) * 1000 || 0;
    var end = parseInt(box.getAttribute('data-end'), 10) * 1000 || 0;
    var countdown = box.querySelector('.twshop-gb-period__countdown');
    var target = countdown ? parseInt(countdown.getAttribute('data-target'), 10) * 1000 : 0;

    function actualState(now) {
        if (start && now < start) return 'upcoming';
        if (end && now >= end) return 'ended';
        return 'open';
    }
    function pad(n) { return n < 10 ? '0' + n : String(n); }
    // 倒數以「天／時／分／秒」方塊呈現；結構只建一次，每秒只更新數字
    var parts = null;
    function build() {
        countdown.textContent = '';
        var label = document.createElement('span');
        label.className = 'twshop-gb-period__label';
        label.textContent = state === 'upcoming' ? '距離開始' : '剩餘';
        countdown.appendChild(label);
        parts = {};
        [['d', '天'], ['h', '時'], ['m', '分'], ['s', '秒']].forEach(function (u) {
            var seg = document.createElement('span');
            seg.className = 'twshop-gb-cd twshop-gb-cd--' + u[0];
            var num = document.createElement('span');
            num.className = 'twshop-gb-cd__num';
            var unit = document.createElement('span');
            unit.className = 'twshop-gb-cd__unit';
            unit.textContent = u[1];
            seg.appendChild(num);
            seg.appendChild(unit);
            countdown.appendChild(seg);
            parts[u[0]] = { seg: seg, num: num };
        });
    }
    function render(ms) {
        if (!parts) build();
        var s = Math.max(0, Math.floor(ms / 1000));
        var d = Math.floor(s / 86400); s -= d * 86400;
        var h = Math.floor(s / 3600); s -= h * 3600;
        var m = Math.floor(s / 60); s -= m * 60;
        parts.d.seg.hidden = d === 0;
        parts.d.num.textContent = String(d);
        parts.h.num.textContent = pad(h);
        parts.m.num.textContent = pad(m);
        parts.s.num.textContent = pad(s);
        // 結束前 24 小時內加強提醒
        box.classList.toggle('is-urgent', state === 'open' && ms < 86400000);
    }
    function stale(now) {
        var key = 'twshop-gb-reload:' + location.pathname + ':' + state;
        var tried = false;
        try { tried = sessionStorage.getItem(key) === '1'; sessionStorage.setItem(key, '1'); } catch (e) { tried = true; }
        if (!tried) { location.reload(); return; }
        var text = box.querySelector('.twshop-gb-period__text');
        var l10n = window.twshopGroupBuyPage || {};
        if (text) text.textContent = actualState(now) === 'ended' ? (l10n.ended || '團購已結束') : (l10n.started || '團購已開始，請重新整理頁面');
        if (countdown) { countdown.textContent = ''; parts = null; }
        box.classList.remove('is-urgent');
        document.body.classList.add('twshop-gb-is-stale');
        clearInterval(timer);
    }
    function tick() {
        var now = Date.now();
        if (actualState(now) !== state) { stale(now); return; }
        if (countdown && target) render(target - now);
    }
    var timer = setInterval(tick, 1000);
    tick();
})();
