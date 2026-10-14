/**
 * 團購專屬頁：商品描述收合、期間倒數。
 */

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
    function format(ms) {
        var s = Math.max(0, Math.floor(ms / 1000));
        var d = Math.floor(s / 86400); s -= d * 86400;
        var h = Math.floor(s / 3600); s -= h * 3600;
        var m = Math.floor(s / 60); s -= m * 60;
        return (d ? d + ' 天 ' : '') + pad(h) + ':' + pad(m) + ':' + pad(s);
    }
    function stale(now) {
        var key = 'twshop-gb-reload:' + location.pathname + ':' + state;
        var tried = false;
        try { tried = sessionStorage.getItem(key) === '1'; sessionStorage.setItem(key, '1'); } catch (e) { tried = true; }
        if (!tried) { location.reload(); return; }
        var text = box.querySelector('.twshop-gb-period__text');
        if (text) text.textContent = actualState(now) === 'ended' ? '團購已結束' : '團購已開始，請重新整理頁面';
        if (countdown) countdown.textContent = '';
        document.body.classList.add('twshop-gb-is-stale');
        clearInterval(timer);
    }
    function tick() {
        var now = Date.now();
        if (actualState(now) !== state) { stale(now); return; }
        if (countdown && target) countdown.textContent = (state === 'upcoming' ? '倒數 ' : '剩餘 ') + format(target - now);
    }
    var timer = setInterval(tick, 1000);
    tick();
})();
