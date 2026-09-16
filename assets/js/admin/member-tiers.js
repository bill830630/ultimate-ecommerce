/**
 * 會員與自動贈禮設定頁：等級卡片的展開收合與贈禮清單編輯。
 *
 * 自 page-member-tiers.php 的內嵌 <script> 抽出（2026-08-21）。
 */
jQuery(document).ready(function($) {
    $('#tier-repeater-container').sortable({ handle: '.drag-handle', axis: 'y', opacity: 0.8 });

    function renderGifts($wrap) {
        let val = $wrap.find('.gifts-json').val() || '[]';
        let arr = JSON.parse(val);
        let html = '';
        arr.forEach((g, i) => {
            let tName = g.type === 'percent' ? '打折(%)' : (g.type === 'points' ? twshopMemberTiers.pointsTerm : '折抵($)');
            html += `<span style="display:inline-block; background:#fff; border:1px solid #ccc; padding:4px 8px; border-radius:4px; font-size:12px; margin:4px 6px 4px 0;">${tName}: ${g.amount} <a href="#" class="remove-gift-btn twshop-text-danger" data-idx="${i}" style="text-decoration:none; margin-left:8px; font-weight:bold;">[移除]</a></span>`;
        });
        $wrap.find('.gifts-list').html(html);
    }
    $('.gifts-section').each(function(){ renderGifts($(this)); });

    $(document).on('click', '.add-gift-btn', function(){
        let $wrap = $(this).closest('.gifts-section');
        let type = $wrap.find('.gift-add-type').val();
        let val = $wrap.find('.gift-add-val').val();
        if(!val) return alert('請輸入優惠額度');
        let $input = $wrap.find('.gifts-json');
        let arr = JSON.parse($input.val() || '[]');
        arr.push({type: type, amount: parseFloat(val)});
        $input.val(JSON.stringify(arr));
        renderGifts($wrap);
        $wrap.find('.gift-add-val').val('');
    });

    $(document).on('click', '.remove-gift-btn', function(e){
        e.preventDefault();
        let $wrap = $(this).closest('.gifts-section');
        let idx = $(this).data('idx');
        let $input = $wrap.find('.gifts-json');
        let arr = JSON.parse($input.val() || '[]');
        arr.splice(idx, 1);
        $input.val(JSON.stringify(arr));
        renderGifts($wrap);
    });

    $('#add-tier-row').on('click', function() {
        var newRow = $($('#tier-template').html());
        $('#tier-repeater-container').append(newRow);
        newRow.find('.twshop-card-body').show();
        newRow.find('.twshop-card-toggle-icon').addClass('is-open');
    });
    $(document).on('click', '.remove-tier-row', function() { if ($('#tier-repeater-container .twshop-tier-card').length > 1) $(this).closest('.twshop-tier-card').remove(); else alert('至少保留一個等級！'); });
    $(document).on('click', '.twshop-card-header', function(e) {
        if($(e.target).is('input, button, select, a, span.drag-handle')) return;
        $(this).next('.twshop-card-body').slideToggle();
        $(this).find('.twshop-card-toggle-icon').toggleClass('is-open');
    });
});
