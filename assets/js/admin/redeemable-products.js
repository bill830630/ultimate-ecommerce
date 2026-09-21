/**
 * 紅利點數頁「點數兌換商品」欄位：新增/移除項目並同步回 JSON 隱藏欄位。
 *
 * 自 page-points.php 的內嵌 <script> 抽出（2026-08-21）。
 * v25.8.15：項目從「只能是單一商品」擴充成也能是「商品分類」/「商品標籤」，
 * 資料形狀從 {product_id, points_cost} 改成 {type, id, points_cost}
 * （PHP 端已在輸出隱藏欄位前正規化成新形狀，見 twshop_normalize_redeemable_entry()）。
 * v25.8.17：分類/標籤不再手動填點數——同分類底下商品售價通常不同，統一點數等於把
 * 貴的商品賤賣，改成讀取端依各商品售價自動換算（twshop_calc_redeem_cost_from_price()，
 * includes/modules/points-engine.php）。選「分類」/「標籤」時「所需點數」欄位隱藏、
 * 送出的 points_cost 固定是 0（後端 sanitize 對這兩種 type 本來就不驗證這個值）。
 * v25.8.27：
 *   1. 「單一商品」改用 twshop_render_product_search_field()（AJAX 搜尋 wc-product-search，
 *      見 includes/admin/ui-components.php），取代原本一次性撈最多 200 筆商品塞進 <select>
 *      的陽春下拉。每筆項目的顯示名稱改由 PHP 端在 twshop_get_redeemable_entry_display_name()
 *      解析好、直接存進隱藏欄位 JSON 的 name 鍵——AJAX 搜尋模式下 <select> 不會預先塞滿
 *      選項，不能再像以前那樣查 DOM 裡的 <option> 文字。
 *   2. 清單渲染改用外掛既有的 .twshop-chip 共用樣式（chip-field.js／twshop-admin.css），
 *      跟其他頁面視覺一致；改用 jQuery 節點 + .text() 組裝，不再是字串拼接 innerHTML
 *      （商品名稱含 <、& 等字元時不會被誤判成 HTML）。
 *   3. 已加入項目（僅 type === 'product'）的點數可以點擊就地編輯，不用先移除再重新加入。
 * v25.8.32：新增 max_qty（單次兌換上限數量）欄位，三種 type 都適用，一樣走「就地點擊編輯」，
 * 跟點數欄位共用同一套 commit/cancel 邏輯（抽出 startInlineEdit() 共用，避免兩份幾乎一樣的
 * 程式碼各自維護）。
 */
jQuery(document).ready(function($){
    var TYPE_LABEL = { category: '分類', tag: '標籤' };

    function readList($wrap) {
        var $input = $wrap.find('.redeem-products-json');
        return JSON.parse($input.val() || '[]');
    }

    function writeList($wrap, arr) {
        $wrap.find('.redeem-products-json').val(JSON.stringify(arr));
    }

    var TYPE_NAME = { product: '商品', category: '分類', tag: '標籤' };
    var FILTER_THRESHOLD = 8; // 項目多到這個數量才顯示篩選欄

    function renderRedeemProducts($wrap) {
        var arr = readList($wrap);
        var $list = $wrap.find('.redeem-products-list').empty();
        var kw = $.trim($wrap.find('.redeem-filter').val() || '').toLowerCase();
        var shown = 0;

        arr.forEach(function(item, i) {
            var label = item.name || (TYPE_LABEL[item.type] ? ('#' + item.id) : ('#' + item.id));
            if (kw && label.toLowerCase().indexOf(kw) === -1) return;
            shown++;

            var $tr = $('<tr></tr>');
            $tr.append($('<td class="col-type"></td>').append($('<span class="twshop-redeem-type"></span>').addClass('is-' + item.type).text(TYPE_NAME[item.type] || '商品')));
            $tr.append($('<td class="col-name"></td>').text(label));

            var $cost = $('<td class="col-cost"></td>');
            if (item.type === 'product') {
                $cost.append(
                    $('<span class="redeem-chip-cost" tabindex="0" title="點擊修改"></span>')
                        .attr('data-idx', i).attr('data-field', 'points_cost')
                        .text(item.points_cost + ' 點')
                );
            } else {
                $cost.append($('<span class="redeem-chip-cost-note"></span>').text('依售價換算'));
            }
            $tr.append($cost);

            $tr.append($('<td class="col-qty"></td>').append(
                $('<span class="redeem-chip-maxqty" tabindex="0" title="點擊修改"></span>')
                    .attr('data-idx', i).attr('data-field', 'max_qty')
                    .text(item.max_qty || 1)
            ));
            $tr.append($('<td class="col-del"></td>').append(
                $('<a href="#" class="twshop-chip-remove remove-redeem-product-btn" title="移除">&times;</a>').attr('data-idx', i)
            ));
            $list.append($tr);
        });

        if (!shown) {
            $list.append($('<tr class="redeem-empty"><td colspan="5"></td></tr>').find('td').text(arr.length ? '沒有符合的項目' : '尚未設定兌換項目').end());
        }
        $wrap.find('.twshop-redeem-toolbar').toggle(arr.length > FILTER_THRESHOLD);
        $wrap.find('.redeem-count').text(kw ? ('顯示 ' + shown + ' / 共 ' + arr.length + ' 項') : ('共 ' + arr.length + ' 項'));
    }

    $(document).on('input', '.redeem-filter', function(){
        renderRedeemProducts($(this).closest('.twshop-redeem-products-section'));
    });
    $('.twshop-redeem-products-section').each(function(){ renderRedeemProducts($(this)); });

    function togglePointsField($wrap, type) {
        var isProduct = type === 'product';
        $wrap.find('.redeem-product-add-points').toggle(isProduct);
        $wrap.find('.redeem-category-cost-note').toggle(!isProduct);
    }

    $(document).on('change', '.redeem-item-type-select', function(){
        var $wrap = $(this).closest('.twshop-redeem-products-section');
        var type = $(this).val();
        // 「單一商品」欄位改切換外層 wrapper，不要直接切換 <select> 本身——selectWoo 會在
        // 原本的 <select> 旁邊插入獨立的 .select2-container 顯示 UI，對 <select> 呼叫
        // .toggle() 不會連動隱藏那個容器（twshop-tw-postcode.js 已踩過同一種陷阱）。
        $wrap.find('.redeem-product-add-select-wrap').toggle(type === 'product');
        $wrap.find('.redeem-category-add-select').toggle(type === 'category');
        $wrap.find('.redeem-tag-add-select').toggle(type === 'tag');
        togglePointsField($wrap, type);
    });

    $(document).on('click', '.add-redeem-product-btn', function(){
        var $wrap = $(this).closest('.twshop-redeem-products-section');
        var type = $wrap.find('.redeem-item-type-select').val() || 'product';
        var $select = type === 'product'
            ? $wrap.find('.redeem-product-add-select-wrap select.wc-product-search')
            : $wrap.find('.redeem-' + type + '-add-select');
        var id = $select.val();
        if (!id) { alert('請選擇項目'); return; }
        var name = $select.find('option:selected').text() || ('#' + id);

        var pts = 0;
        if (type === 'product') {
            pts = $wrap.find('.redeem-product-add-points').val();
            if (!pts || pts <= 0) { alert('請輸入所需點數'); return; }
        }

        var maxQty = parseInt($wrap.find('.redeem-product-add-maxqty').val(), 10);
        if (!maxQty || maxQty <= 0) { maxQty = 1; }

        var arr = readList($wrap);
        if (arr.some(function(it){ return it.type === type && String(it.id) === String(id); })) { alert('此項目已在兌換清單中'); return; }
        arr.push({ type: type, id: parseInt(id, 10), name: name, points_cost: type === 'product' ? parseInt(pts, 10) : 0, max_qty: maxQty });
        writeList($wrap, arr);
        renderRedeemProducts($wrap);
        $wrap.find('.redeem-product-add-points').val('');
        $wrap.find('.redeem-product-add-maxqty').val(1);

        if (type === 'product') {
            // selectWoo 需要 .trigger('change') 畫面才會同步清空，直接改 .val() 沒有用。
            $select.val(null).trigger('change');
        } else {
            $select.val('');
        }
    });

    $(document).on('click', '.remove-redeem-product-btn', function(e){
        e.preventDefault();
        var $wrap = $(this).closest('.twshop-redeem-products-section');
        var idx = $(this).data('idx');
        var arr = readList($wrap);
        arr.splice(idx, 1);
        writeList($wrap, arr);
        renderRedeemProducts($wrap);
    });

    // 就地編輯所需點數／單次兌換上限：點擊 chip 上的文字，原地換成數字輸入框，
    // Enter/失焦寫回、Esc 取消。data-field 決定寫回 item 物件的哪個鍵
    // （'points_cost' 或 'max_qty'），兩種欄位共用同一套 commit/cancel 邏輯。
    function startInlineEdit($span) {
        if ($span.find('input').length) return; // 已經在編輯中，不重複進入

        var $wrap = $span.closest('.twshop-redeem-products-section');
        var idx   = $span.data('idx');
        var field = $span.data('field');
        var current = parseInt($span.text().replace(/[^0-9]/g, ''), 10) || 0;
        var $inputEl = $('<input type="number" min="1" class="redeem-chip-cost-input">').val(current);
        // 移除仍保有焦點的 <input>（無論是 Enter 提交後、還是 Esc 取消後的重新渲染）多半會讓
        // 瀏覽器再補觸發一次 blur——沒有這個旗標擋，Esc 取消後緊接著的那次 blur 還是會呼叫
        // commit() 把剛剛想取消的值寫回去，等於 Esc 完全沒作用。
        var done = false;

        function commit() {
            if (done) return;
            done = true;
            var val = parseInt($inputEl.val(), 10);
            if (val && val > 0) {
                var arr = readList($wrap);
                arr[idx][field] = val;
                writeList($wrap, arr);
            }
            renderRedeemProducts($wrap);
        }

        function cancel() {
            if (done) return;
            done = true;
            renderRedeemProducts($wrap);
        }

        $inputEl.on('blur', commit);
        $inputEl.on('keydown', function(e){
            if (e.key === 'Enter') { e.preventDefault(); commit(); }
            if (e.key === 'Escape') { cancel(); }
        });

        $span.empty().append($inputEl);
        $inputEl.trigger('focus').trigger('select');
    }

    $(document).on('click', '.redeem-chip-cost, .redeem-chip-maxqty', function(){
        startInlineEdit($(this));
    });
});
