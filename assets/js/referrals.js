/* HTTPS 使用 Clipboard API；HTTP 在點擊事件內使用相容複製方式。 */
(function () {
    'use strict';
    var button = document.getElementById('twshop-copy-referral-url');
    var input = document.getElementById('twshop-referral-url');
    var status = document.getElementById('twshop-referral-copy-status');
    if (!button || !input || !status) return;

    var defaultLabel = button.textContent;
    var resetTimer;

    function showResult(copied) {
        button.disabled = false;
        button.dataset.copyState = copied ? 'success' : 'error';
        status.dataset.copyState = button.dataset.copyState;
        button.textContent = copied ? '✓ 已複製連結' : '重新複製連結';
        status.textContent = copied ? '' : '瀏覽器未允許複製，請按 Ctrl／Command + C 複製已選取的連結。';
        if (copied) {
            resetTimer = setTimeout(function () {
                button.textContent = defaultLabel;
                delete button.dataset.copyState;
                delete status.dataset.copyState;
                status.textContent = '';
            }, 3000);
        }
    }

    function copyWithSelection() {
        input.focus();
        input.select();
        input.setSelectionRange(0, input.value.length);
        var copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }
        if (copied) {
            input.setSelectionRange(0, 0);
        }
        showResult(copied);
        if (copied) button.focus();
    }

    button.addEventListener('click', function () {
        clearTimeout(resetTimer);
        button.disabled = true;
        button.textContent = '複製中…';
        delete button.dataset.copyState;
        delete status.dataset.copyState;
        status.textContent = '';
        if (!navigator.clipboard || !window.isSecureContext) {
            // 保留同步點擊事件的使用者授權，讓 HTTP 網站也能直接複製。
            copyWithSelection();
            return;
        }
        try {
            navigator.clipboard.writeText(input.value).then(function () {
                showResult(true);
            }, copyWithSelection);
        } catch (error) {
            copyWithSelection();
        }
    });
}());
