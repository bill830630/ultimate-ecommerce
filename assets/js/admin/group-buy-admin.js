/**
 * 團購後台：佣金級距新增／刪除列；團購主編輯頁依佣金規則只顯示對應的輸入區，
 * 隱藏區的欄位設為 disabled，避免隱藏的必填欄位擋住送出；團購折扣依類型切換單位並即時換算。
 */
jQuery(document).ready(function($) {
    $('.twshop-gb-tier-editor').each(function () {
        var $editor = $(this);
        var $body = $editor.find('.twshop-gb-tier-table tbody');
        var tpl = $editor.find('.twshop-gb-tier-template').html();
        $editor.on('click', '.twshop-gb-tier-add', function () {
            var $row = $(tpl).appendTo($body);
            $row.find('input').first().trigger('focus');
        });
        $editor.on('click', '.twshop-gb-tier-remove button', function () {
            $(this).closest('tr').remove();
        });
    });

    $('.twshop-gb-discount').each(function () {
        var $box = $(this);
        var $type = $box.find('select[name="discount_type"]');
        var $value = $box.find('input[name="discount_value"]');
        var $preview = $box.find('.twshop-gb-discount-preview');
        function update() {
            var type = $type.val();
            var v = parseFloat($value.val());
            $box.find('.twshop-gb-discount-value').toggle(type !== '');
            $value.prop('disabled', type === '');
            $box.find('[data-type]').each(function () { $(this).toggle($(this).data('type') === type); });
            var text = '';
            if (type === 'percent' && v > 0 && v < 100) {
                text = '＝打 ' + parseFloat(((100 - v) / 10).toFixed(2)) + ' 折';
            } else if (type === 'fixed' && v > 0) {
                text = '＝每件減 ' + v;
            }
            $preview.text(text);
        }
        $type.on('change', update);
        $value.on('input', update);
        update();
    });

    // 個別商品價格表：依「個別價 > 團購折扣 > 商品團購價」即時算出團購價，須低於目前售價才生效（與 PHP 相同規則）。
    var $table = $('.twshop-gb-price-table');
    if ($table.length) {
        var decimals = parseInt($table.data('decimals'), 10) || 0;
        var formatPrice = function (n) {
            var parts = n.toFixed(decimals).split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, String($table.data('thousand')));
            var amount = parts.join(String($table.data('decimal')));
            return String($table.data('format')).replace('%1$s', String($table.data('symbol'))).replace('%2$s', amount).replace(/&nbsp;/g, ' ');
        };
        var round = function (n) { var f = Math.pow(10, decimals); return Math.round(n * f) / f; };
        var recalc = function () {
            var type = $('select[name="discount_type"]').val() || '';
            var value = parseFloat($('input[name="discount_value"]').val());
            $table.find('.twshop-gb-price-row').each(function () {
                var $row = $(this);
                var price = parseFloat($row.data('price'));
                var raw = parseFloat($row.data('raw'));
                var own = parseFloat($row.find('input').val());
                var result = NaN, source = '';
                if (own > 0) {
                    result = own; source = '個別價';
                } else if (type === 'percent' && value > 0 && value < 100) {
                    result = round(price * (100 - value) / 100); source = '團購折扣';
                } else if (type === 'fixed' && value > 0) {
                    result = round(price - value); source = '團購折扣';
                } else if (raw > 0) {
                    result = raw; source = '商品團購價';
                }
                var $cell = $row.find('.twshop-gb-price-result').empty();
                if (!(price > 0) || !(result > 0) || result >= price) {
                    $cell.append($('<span class="twshop-hint">').text(source && result >= price ? '原價（' + source + '未低於售價）' : '原價'));
                    return;
                }
                var zhe = parseFloat((result / price * 10).toFixed(1));
                $cell.append($('<strong>').text(formatPrice(result)))
                    .append($('<div class="twshop-hint">').text(source + '・' + zhe + ' 折'));
            });
        };
        $table.on('input', 'input', recalc);
        $('select[name="discount_type"], input[name="discount_value"]').on('change input', recalc);
        recalc();
    }

    // 分享封面圖片：開媒體庫選一張圖，存附件 ID
    $('.twshop-gb-cover').each(function () {
        var $box = $(this);
        var $input = $box.find('input[name="cover_id"]');
        var $preview = $box.find('.twshop-gb-cover-preview');
        var $select = $box.find('.twshop-gb-cover-select');
        var $remove = $box.find('.twshop-gb-cover-remove');
        var frame;
        $select.on('click', function () {
            if (!window.wp || !wp.media) return;
            if (!frame) {
                frame = wp.media({ title: '選擇分享封面圖片', library: { type: 'image' }, button: { text: '使用這張圖片' }, multiple: false });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    var size = (att.sizes && (att.sizes.medium || att.sizes.full)) || att;
                    $input.val(att.id);
                    $preview.empty().append($('<img>', { src: size.url, alt: '' }).css({ display: 'block', maxWidth: '300px', height: 'auto', marginBottom: '8px' }));
                    $select.text('更換圖片');
                    $remove.prop('hidden', false);
                });
            }
            frame.open();
        });
        $remove.on('click', function () {
            $input.val('');
            $preview.empty();
            $select.text('選擇圖片');
            $remove.prop('hidden', true);
        });
    });

    var $rule = $('.twshop-gb-rule');
    if (!$rule.length) return;
    function sync() {
        var mode = $rule.find('input[name="commission_mode"]:checked').val();
        $rule.find('.twshop-gb-rule-panel').each(function () {
            var on = $(this).data('mode') === mode;
            $(this).toggle(on).find('input, button').prop('disabled', !on);
        });
    }
    $rule.on('change', 'input[name="commission_mode"]', sync);
    sync();
});
