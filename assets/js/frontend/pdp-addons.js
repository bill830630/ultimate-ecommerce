/**
 * 商品頁加購：整張商品卡片都可以點選來勾選／取消（v25.8.140）。
 * 勾選框（input[name="twshop_pdp_addon[]"]）仍是真正送出的欄位；這裡只負責
 * (1) 點卡片任何位置（含圖片、商品名稱連結）就切換勾選，不跳去加購品自己的商品頁，
 * (2) 依勾選狀態在卡片加上 is-selected，樣式見 twshop-frontend.css。
 * 點到勾選框或它的文字標籤時交給瀏覽器原生行為，不重複切換。
 */
jQuery(function ($) {
    var $cards = $('.twshop-pdp-addons li.product');
    if (!$cards.length) return;

    function sync($card) {
        $card.toggleClass('is-selected', $card.find('input[name="twshop_pdp_addon[]"]').prop('checked'));
    }
    $cards.each(function () { sync($(this)); });

    $cards.on('change', 'input[name="twshop_pdp_addon[]"]', function () {
        sync($(this).closest('li.product'));
    });

    $cards.on('click', function (e) {
        var $target = $(e.target);
        if ($target.closest('label, input').length) return; // 勾選框本身與文字標籤（畫面上看不到，只有鍵盤/螢幕閱讀器會碰到）：原生行為
        e.preventDefault(); // 圖片／名稱連結：改成切換勾選，不離開商品頁
        var $input = $(this).find('input[name="twshop_pdp_addon[]"]');
        $input.prop('checked', !$input.prop('checked')).trigger('change');
    });
});
