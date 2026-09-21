/**
 * 商品頁頁籤順序與顯示：jQuery UI sortable 拖曳，勾選同步到隱藏欄位。
 * 比照 account-tabs.js（會員中心頁籤排序），沿用 .twshop-tab-row 樣式。
 */
jQuery(document).ready(function($) {
    var $box = $('#product-tabs-repeater-container');
    $box.sortable({
        axis: 'y',
        opacity: 0.8,
        cancel: 'input, label, button',
        placeholder: 'twshop-tab-row-placeholder',
        forcePlaceholderSize: true,
        tolerance: 'pointer'
    });
    $box.on('change', '.tab-enabled-checkbox', function () {
        $(this).closest('.twshop-tab-row').find('.tab-enabled-input').val(this.checked ? 'yes' : 'no');
    });
});
