/**
 * 折扣與贈品管理頁：規則卡片的新增/複製/儲存/刪除/拖曳排序（全部走 admin-ajax）。
 *
 * 自 page-discount-rules.php 的內嵌 <script> 抽出（2026-08-21）；v25.8.38 改為就地顯示狀態文字
 * （不再跳 alert）、未儲存提示、複製規則、依型別顯示數值單位與即時說明。
 */
var twshopAdminNonce = twshopDiscountRules.nonce;
jQuery(document).ready(function($) {
    var $container = $('#discount-repeater-container');
    var STACKABLE_TYPES = ['percent', 'fixed_product', 'cart_percent', 'cart_discount', 'tiered_cart'];
    var PRODUCT_LEVEL_TYPES = ['percent', 'fixed_product'];

    // 初始化（含 selectWoo／條件類型的程式觸發 change）期間不算使用者修改
    var dirtyTrackingOn = false;

    // ── 狀態文字 ─────────────────────────────────────────────
    function showStatus($el, type, msg) {
        clearTimeout($el.data('twshopStatusTimer'));
        $el.removeClass('is-success is-error').addClass(type === 'error' ? 'is-error' : 'is-success').text(msg).show();
        if (type !== 'error') {
            $el.data('twshopStatusTimer', setTimeout(function(){ $el.fadeOut(); }, 6000));
        }
    }
    function showCardStatus($card, type, msg) { showStatus($card.find('.twshop-card-header .twshop-rule-status'), type, msg); }
    // 儲存按鈕在卡片最下方，儲存結果顯示在按鈕旁邊（卡片收合時看不到，所以另外同步到標題列）
    function showSaveStatus($card, type, msg) {
        showStatus($card.find('.twshop-rule-footer-status'), type, msg);
        showCardStatus($card, type, msg);
    }
    function showToolbarStatus(type, msg) { showStatus($('#twshop-rule-toolbar-status'), type, msg); }
    function ajaxErrorMsg(res, fallback) { return (res && res.data && res.data.msg) || fallback; }

    // ── 未儲存標記 ───────────────────────────────────────────
    function setDirty($card, dirty) {
        $card.toggleClass('is-dirty', dirty);
        $card.find('.twshop-rule-dirty-badge').toggle(dirty);
    }
    $container.on('input change', '.twshop-rule-form :input', function() {
        if (!dirtyTrackingOn) return;
        // 已儲存規則的標題列切換鈕會立刻存檔，不算未儲存的修改
        if ($(this).closest('.twshop-card-header-controls').length && $(this).closest('.twshop-rule-form').find('input[name="rule_id"]').val()) return;
        setDirty($(this).closest('.twshop-rule-form'), true);
    });
    // 商品/分類/標籤三種欄位都是 selectWoo 多選，移除已選項目（點 tag 上的 x）本身就會
    // 對底層 <select> 觸發原生 change 事件，上面的委派已經接住，這裡只剩階梯列本身不會
    // 觸發 input/change 事件的新增/移除需要另外標記未儲存。
    $container.on('click', '.twshop-add-tier-row, .twshop-remove-tier-row', function() {
        if (dirtyTrackingOn) setDirty($(this).closest('.twshop-rule-form'), true);
    });
    window.addEventListener('beforeunload', function(e) {
        if (!$container.children('.twshop-rule-form.is-dirty').length) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // ── 數值欄單位與即時說明 ─────────────────────────────────
    function percentHint(raw) {
        if (raw === '' || isNaN(parseFloat(raw))) return { text: '填顧客實付的比例，例如 90 = 打 9 折', warn: false };
        var v = parseFloat(raw);
        if (v >= 100) return { text: '= 沒有折扣（顧客付全額）', warn: true };
        if (v <= 0) return { text: '= 免費（顧客不用付錢）', warn: true };
        var zhe = (v % 10 === 0 || v < 10) ? v / 10 : v;
        return { text: '= 打 ' + zhe + ' 折（顧客付 ' + v + '%，省 ' + Math.round((100 - v) * 100) / 100 + '%）', warn: false };
    }
    function renderHint($hint, hint) {
        $hint.text(hint ? hint.text : '').toggleClass('is-warn', !!(hint && hint.warn)).toggle(!!hint);
    }
    function updateValueHint($card) {
        var type = $card.find('.twshop-rule-type').val();
        var raw = $card.find('.twshop-rule-value').val();
        var $hint = $card.find('.rule-value-wrap .twshop-rule-value-hint');
        if (type === 'percent' || type === 'cart_percent') {
            renderHint($hint, percentHint(raw));
        } else if (type === 'fixed_product') {
            renderHint($hint, { text: '每件符合的商品減去這個金額', warn: false });
        } else if (type === 'cart_discount') {
            renderHint($hint, { text: '整筆訂單減去這個金額', warn: false });
        } else if (type === 'addon_product') {
            renderHint($hint, { text: '符合條件時，顧客可用這個價格加購 1 件', warn: false });
        } else {
            renderHint($hint, null);
        }
    }
    function updateTierHint($row) {
        var isPercent = $row.find('.twshop-tier-type').val() === 'percent';
        renderHint($row.find('.twshop-tier-hint'), isPercent ? percentHint($row.find('.twshop-tier-value').val()) : null);
    }

    var VALUE_LABELS = {
        percent: '顧客實付比例 (%)',
        cart_percent: '顧客實付比例 (%)',
        fixed_product: '每件折抵金額 ($)',
        cart_discount: '整筆訂單折抵金額 ($)',
        addon_product: '加購特價金額 ($)'
    };
    var CONDITION_HINTS = {
        product: '只有符合範圍的商品會打折；有填小計滿額時，購物車小計也要達標。',
        cart: '購物車裡有符合範圍的商品（且小計達標）時，這條規則才成立。',
        buy_x_get_y: '必填：選擇哪些商品的購買數量算進 N。'
    };

    // ── 規則分類（兩層選單：先選分類，篩出「折扣與贈品類型」的選項） ──────────
    // 分類 => 哪些 type 屬於它，唯一登記處是每個 <option data-group="..."> 屬性（PHP 端，
    // page-discount-rules.php），這裡只讀不重複維護一份對照表。
    function filterTypeOptions($card) {
        var $typeSelect = $card.find('.twshop-rule-type');
        var group = $card.find('.twshop-rule-type-group').val();
        var $options = $typeSelect.find('option');
        $options.prop('hidden', function() { return $(this).data('group') !== group; });
        // 目前選的類型不屬於新分類（使用者剛切換分類）：跳到該分類第一個選項並觸發
        // change，讓 applyTypeLayout() 等既有邏輯照常重算卡片其餘部分。
        if ($typeSelect.find('option:selected').data('group') !== group) {
            $typeSelect.val($options.filter('[data-group="' + group + '"]').first().val()).trigger('change');
        }
    }
    // 初始化／載入既有規則時：依目前 type 反推它屬於哪個分類，讓「規則分類」選單顯示正確值。
    function syncTypeGroup($card) {
        var group = $card.find('.twshop-rule-type option:selected').data('group');
        $card.find('.twshop-rule-type-group').val(group);
        filterTypeOptions($card);
    }

    function applyTypeLayout($card) {
        var type = $card.find('.twshop-rule-type').val();
        var $valueWrap = $card.find('.rule-value-wrap');

        $valueWrap.toggle(!!VALUE_LABELS[type]);
        if (VALUE_LABELS[type]) $valueWrap.find('.rule-value-label').text(VALUE_LABELS[type]);
        $card.find('.rule-gift-wrap').toggle(type === 'free_gift' || type === 'addon_product');
        $card.find('.rule-shipping-methods-wrap').toggle(type === 'free_shipping');
        $card.find('.rule-bxgy-wrap').toggle(type === 'buy_x_get_y');
        $card.find('.rule-tiers-wrap').toggle(type === 'tiered_cart');
        $card.find('.rule-stack-wrap').toggle(STACKABLE_TYPES.indexOf(type) !== -1);
        // 階梯式折扣的門檻寫在每一階裡，商品適用範圍/小計滿額對它沒有意義；但「套用對象」
        // （會員等級）任何類型都要能設定，所以只隱藏範圍相關欄位（.rule-scope-toggle），
        // 不整個隱藏「2. 套用對象與適用範圍」區塊（不然 tiered_cart 規則會連套用對象都改不了）。
        var showScope = type !== 'tiered_cart';
        $card.find('.rule-scope-toggle').toggle(showScope);
        // 重新顯示時，商品/分類/標籤三選一的欄位要交回「適用範圍」下拉選單目前的值決定
        // 顯示哪一個——上面那行 .toggle() 對所有 .condition-values-wrap 一視同仁地顯示，
        // 還原不出「同時只顯示一種」的規則，靠這行 change 事件重新收斂回正確狀態。
        if (showScope) $card.find('.twshop-condition-type').trigger('change');

        var hintKey = type === 'buy_x_get_y' ? 'buy_x_get_y' : (PRODUCT_LEVEL_TYPES.indexOf(type) !== -1 ? 'product' : 'cart');
        $card.find('.rule-condition-hint').text(CONDITION_HINTS[hintKey]);

        updateValueHint($card);
        updateLogicVisibility($card);
    }

    // AND/OR 在範圍、小計滿額、數量門檻至少設定兩項時顯示。
    function updateLogicVisibility($card) {
        var hasScope = !!$card.find('.twshop-condition-type').val();
        var hasMin = parseFloat($card.find('.twshop-rule-min-amount').val()) > 0;
        var hasQty = parseInt($card.find('.twshop-rule-min-qty').val(), 10) > 0;
        $card.find('.twshop-rule-logic-wrap').toggle((hasScope ? 1 : 0) + (hasMin ? 1 : 0) + (hasQty ? 1 : 0) >= 2);
    }

    // ── 規則名稱自動產生 ─────────────────────────────────────
    // 名稱依卡片設定即時產生；使用者手動改過就不再覆蓋（nameAuto=false），清空名稱即恢復自動。
    function selectedTexts($select) {
        return $select.find('option:selected').map(function() {
            if (!$(this).val()) return null;
            // WooCommerce 商品搜尋回傳的文字是「名稱 (SKU)」或「名稱 (#ID)」，只取名稱
            return $.trim($(this).text()).replace(/\s*\([^()]*\)$/, '');
        }).get().filter(Boolean);
    }
    function joinNames(names) {
        if (!names.length) return '';
        return names.length > 2 ? names.slice(0, 2).join('、') + ' 等' + names.length + '項' : names.join('、');
    }
    function fmtNum(raw) {
        var n = parseFloat(raw);
        return isNaN(n) ? '' : String(Math.round(n * 100) / 100);
    }
    function zheText(raw) {
        var v = parseFloat(raw);
        if (isNaN(v) || v <= 0 || v >= 100) return '';
        return String((v % 10 === 0 || v < 10) ? v / 10 : v);
    }

    function generateRuleName($card) {
        var type = $card.find('.twshop-rule-type').val();
        var value = $card.find('.twshop-rule-value').val();
        var min = parseFloat($card.find('.twshop-rule-min-amount').val()) || 0;
        var minQty = parseInt($card.find('.twshop-rule-min-qty').val(), 10) || 0;
        var scopeType = $card.find('.twshop-condition-type').val();
        var scope = '';
        if (scopeType === 'product') scope = joinNames(selectedTexts($card.find('select[name="condition_values_product[]"]')));
        if (scopeType === 'category') scope = joinNames(selectedTexts($card.find('select[name="condition_values_category[]"]')));
        if (scopeType === 'tag') scope = joinNames(selectedTexts($card.find('select[name="condition_values_tag[]"]')));
        var gift = selectedTexts($card.find('select[name="gift_product_id"]'))[0] || '';
        var $role = $card.find('select[name="role"]');
        var role = $role.val() !== 'all' ? $.trim($role.find('option:selected').text()) : '';
        var zhe = zheText(value);
        var amount = fmtNum(value);

        var core;
        switch (type) {
            case 'percent':       core = (scope || '全館商品') + (zhe ? ' 打' + zhe + '折' : ' 打折'); break;
            case 'fixed_product': core = (scope || '全館商品') + ' 每件折' + (amount ? amount + '元' : '抵'); break;
            case 'cart_percent':  core = '全單' + (zhe ? '打' + zhe + '折' : '打折'); break;
            case 'cart_discount': core = '全單折' + (amount ? amount + '元' : '抵'); break;
            case 'free_shipping': core = '免運'; break;
            case 'free_gift':     core = gift ? '送「' + gift + '」' : '贈品'; break;
            case 'addon_product': core = gift ? '加購「' + gift + '」' + (amount ? amount + '元' : '') : '加購優惠'; break;
            case 'buy_x_get_y':
                var n = $card.find('input[name="buy_qty"]').val(), m = $card.find('input[name="free_qty"]').val();
                core = (scope ? scope + ' ' : '') + '買' + (parseInt(n, 10) > 0 ? n : 'N') + '送' + (parseInt(m, 10) > 0 ? m : 'M');
                break;
            case 'tiered_cart':
                var mins = $card.find('input[name="tiers_min[]"]').map(function() { return parseFloat($(this).val()); }).get()
                    .filter(function(x) { return x > 0; }).sort(function(a, b) { return a - b; });
                core = mins.length ? '階梯折扣（滿' + fmtNum(mins[0]) + '起）' : '階梯折扣';
                break;
            default: core = '折扣規則';
        }

        var parts = [];
        if (role) parts.push('【' + role + '】');
        // 購物車層規則的範圍語意是「購物車含有」（商品層與買N送N 已經寫在 core 裡）
        if (scope && ['cart_percent', 'cart_discount', 'free_shipping', 'free_gift', 'addon_product'].indexOf(type) !== -1) parts.push('含' + scope);
        if (min > 0 && type !== 'tiered_cart') parts.push('滿' + fmtNum(min));
        if (minQty > 0 && type !== 'tiered_cart') parts.push('滿' + minQty + '件');
        parts.push(core);
        return parts.join(' ');
    }

    function refreshAutoName($card) {
        if (!$card.data('nameAuto')) return;
        var name = generateRuleName($card);
        var $input = $card.find('.twshop-rule-name-input');
        if ($input.val() === name) return;
        $input.val(name);
        $card.attr('data-rule-name', name);
        if (dirtyTrackingOn) setDirty($card, true);
    }

    $container.on('input', '.twshop-rule-name-input', function() {
        $(this).closest('.twshop-rule-form').data('nameAuto', $.trim($(this).val()) === '');
    });
    $container.on('blur', '.twshop-rule-name-input', function() {
        refreshAutoName($(this).closest('.twshop-rule-form'));
    });
    $container.on('input change', '.twshop-card-body :input', function() {
        refreshAutoName($(this).closest('.twshop-rule-form'));
    });
    // 階梯列增減不會觸發 input/change 事件，等 DOM 更新完再重算（商品/分類/標籤已改用
    // selectWoo，移除已選項目會對 <select> 觸發原生 change，上面的委派已經接住）
    $container.on('click', '.twshop-add-tier-row, .twshop-remove-tier-row', function() {
        var $card = $(this).closest('.twshop-rule-form');
        setTimeout(function() { refreshAutoName($card); }, 0);
    });

    // ── 初始化 ───────────────────────────────────────────────
    function initCards($cards) {
        $cards.find('.twshop-datetime-picker').each(function() {
            if (!this._flatpickr) $(this).flatpickr({ enableTime: true, time_24hr: true, dateFormat: "Y-m-d H:i" });
        });
        // 動態插入的 wc-product-search 欄位也要套用 selectWoo（wc-enhanced-select.js 會略過已初始化的元素）
        $(document.body).trigger('wc-enhanced-select-init');
        $cards.each(function() {
            var $card = $(this);
            syncTypeGroup($card);
            applyTypeLayout($card);
            rememberSavedSchedule($card);
            var currentName = $.trim($card.find('.twshop-rule-name-input').val());
            $card.data('nameAuto', currentName === '' || currentName === generateRuleName($card));
            refreshAutoName($card);
            $card.find('.twshop-tier-row').each(function() { updateTierHint($(this)); });
        });
    }

    $container.on('change', '.twshop-rule-type-group', function() { filterTypeOptions($(this).closest('.twshop-rule-form')); });
    $container.on('change', '.twshop-rule-type', function() { applyTypeLayout($(this).closest('.twshop-rule-form')); });
    $container.on('input change', '.twshop-rule-value', function() { updateValueHint($(this).closest('.twshop-rule-form')); });
    $container.on('input change', '.twshop-rule-min-amount, .twshop-rule-min-qty', function() { updateLogicVisibility($(this).closest('.twshop-rule-form')); });
    $container.on('change', '.twshop-condition-type', function() { updateLogicVisibility($(this).closest('.twshop-rule-form')); });
    $container.on('input change', '.twshop-tier-type, .twshop-tier-value', function() { updateTierHint($(this).closest('.twshop-tier-row')); });


    function applyEnabledLook($card, isEnabled) {
        $card.toggleClass('twshop-rule-disabled', !isEnabled);
        $card.find('.twshop-switch-text').text(isEnabled ? '啟用' : '停用');
        $card.attr('data-rule-enabled', isEnabled ? 'yes' : 'no');
    }

    // 標題列的啟用切換鈕：已儲存的規則切換後立刻存檔（只改這一個欄位，不會連帶送出
    // 卡片裡其他未儲存的修改）；還沒存過的新規則沒有 rule_id，維持跟著「儲存規則」一起送出。
    function saveHeaderToggle($toggle, onType, offType, onMsg, offMsg, applyLook) {
        var $card = $toggle.closest('.twshop-rule-card');
        var isOn = $toggle.is(':checked');
        if (applyLook) applyLook($card, isOn);

        var ruleId = $card.find('input[name="rule_id"]').val();
        if (!ruleId || !dirtyTrackingOn) return;

        var revert = function(msg) {
            $toggle.prop('checked', !isOn);
            if (applyLook) applyLook($card, !isOn);
            showCardStatus($card, 'error', msg);
        };
        $toggle.prop('disabled', true);
        $.post(twshopDiscountRules.ajaxUrl, { action: 'twshop_batch_update_rules', action_type: isOn ? onType : offType, rule_ids: [ruleId], twshop_nonce: twshopAdminNonce })
            .done(function(res) {
                if (res && res.success) showCardStatus($card, 'success', isOn ? onMsg : offMsg);
                else revert(ajaxErrorMsg(res, '切換失敗，請重新整理頁面後再試'));
            })
            .fail(function() { revert('切換失敗，請檢查網路連線後重試'); })
            .always(function() { $toggle.prop('disabled', false); });
    }

    // 標題列的開始/結束時間：選好或清除後立即存檔（兩個欄位一起送，短延遲合併「清除時間」同時觸發的兩次變更）
    function scheduleSave($card) {
        clearTimeout($card.data('twshopScheduleTimer'));
        $card.data('twshopScheduleTimer', setTimeout(function() {
            var ruleId = $card.find('input[name="rule_id"]').val();
            var start = $card.find('input[name="start_time"]').val();
            var end = $card.find('input[name="end_time"]').val();
            if (!ruleId) { setDirty($card, true); return; }
            if (start && end && end <= start) { showCardStatus($card, 'error', '結束時間必須晚於開始時間'); return; }
            if (start === $card.data('twshopSavedStart') && end === $card.data('twshopSavedEnd')) return;
            $.post(twshopDiscountRules.ajaxUrl, { action: 'twshop_batch_update_rules', action_type: 'schedule', rule_ids: [ruleId], start_time: start, end_time: end, twshop_nonce: twshopAdminNonce })
                .done(function(res) {
                    if (res && res.success) {
                        $card.data('twshopSavedStart', start).data('twshopSavedEnd', end);
                        showCardStatus($card, 'success', (start || end) ? '✓ 已更新時間' : '✓ 已清除時間');
                    } else {
                        showCardStatus($card, 'error', ajaxErrorMsg(res, '時間儲存失敗'));
                    }
                })
                .fail(function() { showCardStatus($card, 'error', '時間儲存失敗，請檢查網路連線後重試'); });
        }, 300));
    }
    function rememberSavedSchedule($card) {
        $card.data('twshopSavedStart', $card.find('input[name="start_time"]').val())
             .data('twshopSavedEnd', $card.find('input[name="end_time"]').val());
    }
    $container.on('change', '.twshop-card-header-controls .twshop-datetime-picker', function() {
        if (dirtyTrackingOn) scheduleSave($(this).closest('.twshop-rule-form'));
    });

    $container.on('change', '.twshop-rule-enabled-toggle', function() {
        saveHeaderToggle($(this), 'enable', 'disable', '✓ 已啟用', '✓ 已停用', applyEnabledLook);
    });


    function openAndScrollTo($card, focusSelector) {
        $card.find('.twshop-card-body').show();
        $card.find('.twshop-card-toggle-icon').addClass('is-open');
        $('html, body').animate({ scrollTop: $card.offset().top - 50 }, 300, function() {
            if (focusSelector) $card.find(focusSelector).first().trigger('focus');
        });
    }

    initCards($container.children('.twshop-rule-form'));
    // 其他檔案（chip-field.js 等）的 document ready 初始化也會觸發 change，等它們都跑完才開始追蹤修改
    setTimeout(function() { dirtyTrackingOn = true; }, 0);

    // ── 排序 ─────────────────────────────────────────────────
    $container.sortable({
        handle: '.drag-handle',
        axis: 'y',
        opacity: 0.8,
        update: function() {
            var order = [];
            $container.children('.twshop-rule-form').each(function() {
                var id = $(this).find('input[name="rule_id"]').val();
                if (id) order.push(id);
            });
            if (!order.length) return;
            $.post(twshopDiscountRules.ajaxUrl, { action: 'twshop_reorder_rules', order: order, twshop_nonce: twshopAdminNonce })
                .done(function(res) {
                    if (res && res.success) showToolbarStatus('success', '✓ 優先順序已儲存');
                    else showToolbarStatus('error', ajaxErrorMsg(res, '排序儲存失敗，請重新整理頁面後再試'));
                })
                .fail(function() { showToolbarStatus('error', '排序儲存失敗，請檢查網路連線後重試'); });
        }
    });

    // ── 新增 ─────────────────────────────────────────────────
    $('#add-rule-row').on('click', function() {
        dirtyTrackingOn = false;
        $container.append(document.getElementById('discount-rule-template').innerHTML);
        var $newRow = $container.children('.twshop-rule-form').last();
        initCards($newRow);
        setDirty($newRow, true);
        dirtyTrackingOn = true;
        openAndScrollTo($newRow, '.twshop-rule-name-input');
    });

    // ── 收合 ─────────────────────────────────────────────────
    $container.on('click', '.twshop-card-header', function(e) {
        if ($(e.target).closest('input, button, select, label, a, .drag-handle').length) return;
        $(this).next('.twshop-card-body').slideToggle();
        $(this).find('.twshop-card-toggle-icon').toggleClass('is-open');
    });

    // ── 儲存 ─────────────────────────────────────────────────
    // 表單設了 novalidate（見 twshop_get_rule_row_html()），前端只檢查名稱，其餘由後端驗證
    function validateRuleForm($form) {
        var $name = $form.find('.twshop-rule-name-input');
        if (!$.trim($name.val())) { $name.trigger('focus'); return '請輸入規則名稱'; }
        return '';
    }

    $container.on('submit', '.twshop-rule-form', function(e) {
        e.preventDefault();
        var $form = $(this), $btn = $form.find('.save-rule-btn');
        var invalidMsg = validateRuleForm($form);
        if (invalidMsg) { showSaveStatus($form, 'error', invalidMsg); return; }
        $btn.text('儲存中…').prop('disabled', true);
        $.post(twshopDiscountRules.ajaxUrl, $form.serialize() + '&action=twshop_save_rule')
            .done(function(res) {
                if (res && res.success) {
                    $form.find('input[name="rule_id"]').val(res.data.rule_id);
                    $form.attr('data-rule-type', $form.find('select[name="type"]').val());
                    $form.find('.twshop-duplicate-rule').prop('disabled', false).attr('title', '複製一份（預設停用）');
                    setDirty($form, false);
                    rememberSavedSchedule($form);
                    showSaveStatus($form, 'success', '✓ 已儲存');
                } else {
                    showSaveStatus($form, 'error', ajaxErrorMsg(res, '儲存失敗'));
                }
            })
            .fail(function() { showSaveStatus($form, 'error', '儲存失敗，請檢查網路連線後重試'); })
            .always(function() { $btn.text('儲存規則').prop('disabled', false); });
    });

    // ── 複製 ─────────────────────────────────────────────────
    $container.on('click', '.twshop-duplicate-rule', function() {
        var $form = $(this).closest('.twshop-rule-form');
        var ruleId = $form.find('input[name="rule_id"]').val();
        if (!ruleId) return;
        if ($form.hasClass('is-dirty')) {
            showCardStatus($form, 'error', '這條規則有未儲存的修改，請先儲存再複製');
            return;
        }
        var $btn = $(this).prop('disabled', true);
        $.post(twshopDiscountRules.ajaxUrl, { action: 'twshop_duplicate_rule', rule_id: ruleId, twshop_nonce: twshopAdminNonce })
            .done(function(res) {
                if (!res || !res.success) { showCardStatus($form, 'error', ajaxErrorMsg(res, '複製失敗')); return; }
                dirtyTrackingOn = false;
                var $copy = $($.parseHTML(res.data.html, document, false)).filter('.twshop-rule-form');
                $form.after($copy);
                initCards($copy);
                $copy.find('.twshop-condition-type').trigger('change');
                dirtyTrackingOn = true;
                showCardStatus($copy, 'success', '已複製（預設停用，確認後再啟用）');
                openAndScrollTo($copy, '.twshop-rule-name-input');
            })
            .fail(function() { showCardStatus($form, 'error', '複製失敗，請檢查網路連線後重試'); })
            .always(function() { $btn.prop('disabled', false); });
    });

    // ── 階梯列 ───────────────────────────────────────────────
    $container.on('click', '.twshop-add-tier-row', function() {
        var $row = $(document.getElementById('twshop-tier-row-template').innerHTML);
        $(this).closest('.rule-tiers-wrap').find('.twshop-tiers-rows').append($row);
        updateTierHint($row.filter('.twshop-tier-row'));
    });
    $container.on('click', '.twshop-remove-tier-row', function() {
        $(this).closest('.twshop-tier-row').remove();
    });

    // ── 清除時間 ─────────────────────────────────────────────
    $container.on('click', '.twshop-clear-datetime', function(e) {
        e.preventDefault();
        var $card = $(this).closest('.twshop-rule-form');
        $card.find('.twshop-datetime-picker').each(function() {
            if (this._flatpickr) this._flatpickr.clear();
        });
        scheduleSave($card);
    });

    // ── 刪除 ─────────────────────────────────────────────────
    $container.on('click', '.remove-rule-row', function() {
        var $form = $(this).closest('.twshop-rule-form');
        var name = $form.find('input[name="name"]').val() || '新規則';
        if (!confirm('確定要刪除「' + name + '」嗎？此操作無法復原。')) return;
        var ruleId = $form.find('input[name="rule_id"]').val();
        var removeCard = function() {
            $form.fadeOut(200, function() { $form.remove(); });
            showToolbarStatus('success', '✓ 已刪除「' + name + '」');
        };
        if (!ruleId) { removeCard(); return; }
        var deleteNonce = $form.find('input[name="twshop_nonce"]').val() || twshopAdminNonce;
        $.post(twshopDiscountRules.ajaxUrl, { action: 'twshop_delete_rule', rule_id: ruleId, twshop_nonce: deleteNonce })
            .done(function(res) {
                if (res && res.success) removeCard();
                else showCardStatus($form, 'error', ajaxErrorMsg(res, '刪除失敗，請重新整理頁面後再試'));
            })
            .fail(function() { showCardStatus($form, 'error', '刪除失敗，請檢查網路連線後重試'); });
    });
});
