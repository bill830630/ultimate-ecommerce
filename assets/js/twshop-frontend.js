/* 強化版購物 - 前台互動腳本 */
(function ($) {
    'use strict';

    // Blocks 購物車/結帳頁「已套用優惠券」有兩處文字需要改寫：
    // 1. 區塊列標題（`.wc-block-components-totals-item__label`，固定顯示 WooCommerce Blocks
    //    內建翻譯的「Coupons」/「折價券」，跟 classic 版型的 wc_cart_totals_coupon_label() 是
    //    完全不同的翻譯來源，PHP filter 完全介入不到）改成後台「一般設定」頁設定的
    //    `wc_general_coupon_noun`（跟本外掛其他地方用詞一致，例如「優惠券」）。
    // 2. Chip 文字固定顯示大寫代碼（Store API 的 CartCouponSchema 只回傳 code，沒有標題欄位），
    //    依 twshopData.couponTitles（代碼→標題，後端用 twshop_get_visual_coupon_titles_map()
    //    產生）改寫成跟卡片一致的標題。
    // 兩者都用文字比對是否已相同才寫入，避免每次 wp.data 觸發都無謂操作 DOM。
    function rewrite_block_coupon_labels() {
        if (typeof twshopData === 'undefined') return;

        if (twshopData.couponNoun) {
            $('.wc-block-cart, .wc-block-checkout')
                .find('.wc-block-components-totals-discount__coupon-list')
                .closest('.wc-block-components-totals-item')
                .find('.wc-block-components-totals-item__label')
                .each(function () {
                    var $label = $(this);
                    if ($label.text() !== twshopData.couponNoun) {
                        $label.text(twshopData.couponNoun);
                    }
                });
        }

        if (twshopData.couponTitles) {
            $('.wc-block-cart, .wc-block-checkout')
                .find('.wc-block-components-totals-discount__coupon-list-item .wc-block-components-chip__text')
                .each(function () {
                    var $text = $(this);
                    var code  = $.trim($text.text()).toUpperCase();
                    var title = twshopData.couponTitles[code];
                    if (title && $text.text() !== title) {
                        $text.text(title);
                    }
                });
        }
    }

    function refresh_twshop_components() {
        var loc              = $('form.checkout').length ? 'checkout' : 'cart';
        var hasWrappers      = $('.twshop-visual-coupons-wrapper, .twshop-cart-addons-wrapper, .twshop-cart-progress-wrapper, .twshop-points-redemption-wrapper, .twshop-points-redeem-products-wrapper, .twshop-wallet-redemption-wrapper').length > 0;
        var isBlocksCart     = $('.wp-block-woocommerce-cart, .wp-block-woocommerce-checkout').length > 0;

        // 若頁面上既無短代碼容器也非 Blocks 購物車，則跳過
        if (!hasWrappers && !isBlocksCart) return;

        // S10：後端 twshop_ajax_refresh_components() 已補上 check_ajax_referer( 'twshop_frontend_action', 'twshop_nonce' )，
        // 這裡必須同步送出 twshop_nonce，否則所有請求都會被擋下、購物車 AJAX 更新後三個區塊不再刷新。
        $.post(twshopData.ajaxUrl, { action: 'twshop_refresh_components', location: loc, twshop_nonce: twshopData.nonce }, function (res) {
            if (!res.success || !res.data) return;

            var $couponsWrapper = $('.twshop-visual-coupons-wrapper');
            if ($couponsWrapper.length) {
                $couponsWrapper.replaceWith(res.data.coupons_html);
            }
            var $addonsWrapper = $('.twshop-cart-addons-wrapper');
            if ($addonsWrapper.length) {
                $addonsWrapper.replaceWith(res.data.addons_html);
            }
            var $progressWrapper = $('.twshop-cart-progress-wrapper');
            if ($progressWrapper.length && res.data.progress_html !== undefined) {
                $progressWrapper.replaceWith(res.data.progress_html);
            }

            // 點數折抵區塊：若 wrapper 已在 DOM 則替換；若被 React 清除則重新注入
            var $pointsWrapper = $('.twshop-points-redemption-wrapper');
            if (res.data.points_html !== undefined && $pointsWrapper.length) {
                $pointsWrapper.replaceWith(res.data.points_html);
            }

            // 點數兌換商品區塊（v25.8.18 起獨立於點數折抵之外，classic 購物車掛在商品列表
            // 下方，見 twshop_classic_cart_redeem_products()）：若 wrapper 已在 DOM 則替換。
            var $redeemProductsWrapper = $('.twshop-points-redeem-products-wrapper');
            if (res.data.redeem_products_html !== undefined && $redeemProductsWrapper.length) {
                $redeemProductsWrapper.replaceWith(res.data.redeem_products_html);
            }

            // 儲值金折抵區塊（v25.8.62 新增），跟點數折抵同一套替換邏輯。
            var $walletWrapper = $('.twshop-wallet-redemption-wrapper');
            if (res.data.wallet_html !== undefined && $walletWrapper.length) {
                $walletWrapper.replaceWith(res.data.wallet_html);
            }

            // Blocks 購物車：這幾個區塊都沒有既有的 classic wrapper 可以替換（block 版型
            // 不會觸發 woocommerce_after_cart_table／woocommerce_before_cart_totals 這些
            // classic 模板 hooks），退而求其次一起注入到訂單摘要總計區塊最前面——沒有
            // 「商品列表下方」這個位置可以掛，維持改動前既有的 Blocks 購物車行為。
            if (!$pointsWrapper.length && !$redeemProductsWrapper.length && !$walletWrapper.length) {
                var $totals = $('.wp-block-woocommerce-cart-order-summary-totals-block');
                if ($totals.length) {
                    if (res.data.points_html !== undefined) $totals.prepend(res.data.points_html);
                    if (res.data.redeem_products_html !== undefined) $totals.prepend(res.data.redeem_products_html);
                    if (res.data.wallet_html !== undefined) $totals.prepend(res.data.wallet_html);
                }
            }

        });
    }

    // WooCommerce 核心常對同一次使用者操作（例如改數量、切換運送方式）連續觸發不只一次
    // updated_cart_totals/updated_checkout，加上 Blocks 購物車另有一條 wp.data.subscribe
    // 觸發路徑，實務上可能對同一次操作觸發兩次以上完整的三區塊重渲染 AJAX。用短 debounce
    // 把同一時間窗內的多次觸發合併成一次請求，減少不必要的伺服器往返。
    var refreshComponentsTimer = null;
    function refresh_twshop_components_debounced() {
        if (refreshComponentsTimer) clearTimeout(refreshComponentsTimer);
        refreshComponentsTimer = setTimeout(function () {
            refreshComponentsTimer = null;
            refresh_twshop_components();
        }, 200);
    }

    // Legacy WooCommerce Cart/Checkout Updates
    $(document.body).on('updated_cart_totals updated_checkout', function () {
        refresh_twshop_components_debounced();
    });

    // 頁面首次載入時 Chip 可能已經渲染完成（沒有經過下面 subscribe 的 loading 狀態轉換），
    // 先跑一次確保初始畫面就是標題而非代碼；之後每次 wp.data 觸發都會再同步一次。
    $(rewrite_block_coupon_labels);

    // WooCommerce Blocks Cart Updates Subscription
    if (typeof wp !== 'undefined' && wp.data && wp.data.subscribe) {
        var previousIsCartLoading = false;
        wp.data.subscribe(function () {
            rewrite_block_coupon_labels();
            try {
                var cartStore = wp.data.select('wc/store/cart');
                if (cartStore) {
                    var isCartLoading = false;
                    if (typeof cartStore.isCartDataPending === 'function') {
                        isCartLoading = cartStore.isCartDataPending();
                    } else if (typeof cartStore.hasFinishedResolution === 'function') {
                        isCartLoading = !cartStore.hasFinishedResolution('getCartData');
                    }
                    if (previousIsCartLoading === true && isCartLoading === false) {
                        refresh_twshop_components_debounced();
                    }
                    previousIsCartLoading = isCartLoading;
                }
            } catch (error) {
                console.warn('強化版購物: 無法訂閱區塊狀態', error);
            }
        });
    }

    // 購物車/結帳頁優惠券彈出視窗：原生 <dialog>，showModal() 自帶遮罩與 ESC 關閉，不需額外套件
    $(document.body).on('click', '.twshop-coupons-open-btn', function (e) {
        e.preventDefault();
        var sel = $(this).data('dialog');
        var dialog = sel ? document.querySelector(sel) : null;
        if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
    });
    $(document.body).on('click', '.twshop-coupons-dialog-close', function (e) {
        e.preventDefault();
        var dialog = $(this).closest('.twshop-coupons-dialog')[0];
        if (dialog) dialog.close();
    });
    // 點擊對話框本身以外的遮罩區域（::backdrop）也能關閉：click 事件目標若剛好是 <dialog> 本身
    // （而非裡面的子元素冒泡上來），代表點在遮罩上
    $(document.body).on('click', '.twshop-coupons-dialog', function (e) {
        if (e.target === this) this.close();
    });

    // Visual Coupon Interactivity
    $(document.body).on('click', '.apply-v-coupon-btn', function (e) {
        e.preventDefault();
        var $btn       = $(this),
            $card      = $btn.closest('.visual-coupon-card'),
            $msg       = $card.find('.v-coupon-message'),
            code       = $btn.data('code'),
            actionType = $btn.data('action');

        if (actionType === 'none' || actionType === 'link') return;
        var ajaxAction = (actionType === 'remove') ? 'remove_visual_coupon' : 'apply_visual_coupon';

        $btn.text('處理中...').prop('disabled', true);
        $msg.hide().removeClass('woocommerce-message woocommerce-error');

        $.ajax({
            url:  twshopData.ajaxUrl,
            type: 'POST',
            data: { action: ajaxAction, coupon_code: code, twshop_nonce: twshopData.nonce },
            success: function (response) {
                $msg.text(response.data.message).show();
                if (response.success) {
                    $msg.css('color', 'green');
                    $btn.text('成功');
                    location.reload();
                } else {
                    $msg.css('color', 'red');
                    $btn.text((actionType === 'remove') ? twshopData.couponBtnRemoveText : twshopData.couponBtnApplyText).prop('disabled', false);
                }
            },
            error: function () {
                $msg.text('系統錯誤').css('color', 'red').show();
                $btn.prop('disabled', false);
            }
        });
    });

    // Cart Addon Remove Button
    $(document.body).on('click', '.twshop-remove-addon-btn', function (e) {
        e.preventDefault();
        var $btn      = $(this);
        var productId = $btn.data('product_id');
        var origText  = $btn.text();

        $btn.text('移除中...').prop('disabled', true);

        $.post(twshopData.ajaxUrl, {
            action:       'twshop_remove_addon',
            product_id:   productId,
            twshop_nonce: twshopData.nonce
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                $btn.text(origText).prop('disabled', false);
                alert((response.data && response.data.message) || '移除失敗，請重新整理頁面後再試。');
            }
        }).fail(function () {
            $btn.text(origText).prop('disabled', false);
            alert('移除失敗，請重新整理頁面後再試。');
        });
    });

    // CVS Shipping - Relocate shipping selector + Hide/Show Address Fields
    //
    // 這整塊（運送方式選單搬到地址欄位前＋超商取貨免填地址）都受後台「系統 ▸ 一般 ▸
    // 結帳頁欄位客製化」總開關控制（twshop_checkout_field_customization_enabled()）。
    // 關閉時 twshopData.checkoutFieldCustomization 為 false，整段直接不執行，運送方式選單
    // 維持 WooCommerce 原生位置（不搬到地址欄位前），地址欄位也維持原生必填狀態——
    // 不能只看 twshopData.cvsMethods 是否為空來判斷，因為「開關開著、但目前沒有任何運送方式
    // 被勾選為超商取貨」也會讓 cvsMethods 是空陣列，這種情況搬運送方式選單的行為仍要照常執行。
    if (twshopData.checkoutFieldCustomization) {
        var ADDRESS_ROWS    = '#billing_address_1_field, #billing_address_2_field, #billing_city_field, #billing_state_field, #billing_postcode_field';
        var REQUIRED_FIELDS = '#billing_address_1_field input, #billing_city_field input, #billing_postcode_field input, #billing_state_field select';

        var twshopIsCvs = function (method) {
            return (twshopData.cvsMethods || []).indexOf(method) !== -1;
        };

        var twshopCvsToggleAddress = function () {
            var method = $('input[name="shipping_method[0]"]:checked').val()
                      || $('input[name="shipping_method[0]"][type="hidden"]').val()
                      || '';
            var isCVS = twshopIsCvs(method);
            if (isCVS) {
                $(ADDRESS_ROWS).hide().find('input, select').removeAttr('required');
            } else {
                $(ADDRESS_ROWS).show();
                $(REQUIRED_FIELDS).attr('required', 'required');
                // 郵遞區號在「台灣地址下拉選單連動」模式下是自動帶入＋隱藏的
                // （.twshop-postcode-auto，見 twshop_taiwan_hide_postcode_field()）。
                // 上一行會把 required 一律加回所有地址欄位，隱藏欄位帶著 required 會讓瀏覽器
                // 原生驗證擋下送出、又因為欄位不可見而無法對焦，畫面上沒有任何提示，顧客只會
                // 覺得「按了送出沒反應」。這裡把它拿回來。
                $('.twshop-postcode-auto').find('input, select').removeAttr('required');
            }
        };

        // 把 order review 裡的 ul#shipping_method 移到地址欄位之前
        //
        // 注意：一定要先確認 #order_review 裡有新的 ul 可以搬，才可以移除 placeholder 裡舊的那份。
        // WooCommerce 的 update_checkout 有一個片段快取（wc_checkout_form.fragments），如果這次
        // AJAX 回應算出來的 HTML 跟上次一模一樣（例如運送方式在 A/B 之間來回切換、或短時間內重複點
        // 同一個），WC 會直接跳過 replaceWith()，#order_review 裡就不會有新的 ul#shipping_method
        // 可以撿。如果這裡不管三七二十一先把 placeholder 裡舊的移除，就會變成「舊的丟了、新的沒有」，
        // 運送方式選項整個消失，且無法回復，需要重新整理頁面。
        var twshopRelocateShipping = function () {
            var $placeholder = $('#twshop-shipping-placeholder');
            if (!$placeholder.length) return;

            var $freshUl = $('#order_review ul#shipping_method');
            if (!$freshUl.length) {
                // 這次沒有新的可以搬（很可能是上述的 WC 片段快取跳過更新），
                // 代表 placeholder 裡現有的那份仍是最新狀態，維持原樣、什麼都不做。
                return;
            }

            // 確定有新的了，才移除 placeholder 裡的舊 UL（updated_checkout 後 order_review 已重建，舊的已是孤兒）
            $placeholder.find('ul#shipping_method').remove();

            // 隱藏 order review 裡的那列（保留其他費用/稅金）
            $freshUl.closest('tr').addClass('twshop-shipping-hidden');

            $placeholder.append($freshUl);
        };

        var twshopInitShippingPlaceholder = function () {
            // 購物車全部品項皆為虛擬商品（例如只買儲值金商品）時，WC_Cart::needs_shipping()
            // 為 false，WooCommerce 核心根本不會輸出 #order_review ul#shipping_method，
            // 這裡若還是無條件插入 placeholder，畫面上會留下一個寫著「運送方式」卻永遠是空的
            // 區塊（twshopRelocateShipping() 找不到東西可搬，直接原地不動）。
            if (!twshopData.needsShipping) return;
            if ($('#twshop-shipping-placeholder').length) return;
            // 這裡只是借用「台灣結帳第一個地址欄是 billing_postcode（priority 45）」這件事
            // 找一個插入點，不代表運送方式只在台灣才需要排最前面——本站銷售地區不只台灣
            // （見 woocommerce_specific_allowed_countries），換成其他國家時 WooCommerce 的
            // address-i18n.js（country_to_state_changing）會依該國 locale 的 priority
            // 重新排序地址欄位列，而 placeholder 本身也是一個 .form-row，會被一併排進去。
            // 若不指定 priority，它會在排序前的「補預設值」那段拿到「排在它前一列的
            // priority + 1」，這個值是台灣的 postcode priority（45）算出來的，換到別國、
            // 別國欄位改用別的 priority 之後，排序結果就不保證還在最前面。
            //
            // 「國家」欄位本身（type=country）的 priority 固定是 40（WooCommerce 核心，
            // 三個銷售地區 CN/TW/HK 都沒有覆寫這個值），本外掛的 twshop_taiwan_address_locale()
            // 把台灣郵遞區號訂為 45，是目前三國裡「地址欄位」最低的 priority；CN／HK 都沒有
            // 覆寫任何欄位的 priority，維持 WC 預設（address_1=50 起跳）。運送方式要排在
            // 「國家」之後、所有地址欄位之前，priority 必須落在 (40, 45) 這個區間——用 42。
            // 若日後新增銷售地區、或改動台灣郵遞區號的 priority，要重新確認這個區間還成立。
            var $anchor = $('#billing_postcode_field');
            if (!$anchor.length) return;
            var $placeholder = $('<div id="twshop-shipping-placeholder" class="form-row form-row-wide"><p class="twshop-shipping-label">運送方式</p></div>');
            $placeholder.data('priority', 42);
            $anchor.before($placeholder);
        };

        $(document.body).on('updated_checkout', function () {
            twshopRelocateShipping();
            twshopCvsToggleAddress();
        });
        $(document.body).on('change', 'input[name^="shipping_method"]', twshopCvsToggleAddress);
        // 只在結帳頁執行「插入運送方式 placeholder＋搬移選單」：twshop-frontend.js 也會在
        // 「我的帳號 ▸ 編輯地址」頁載入（兩者共用同一套 #billing_postcode_field 等地址欄位 id），
        // 但編輯地址頁沒有 #order_review ul#shipping_method 這種東西可搬，若不限定頁面，
        // 會在那裡留下一個寫著「運送方式」卻永遠是空的區塊（見 twshopData.isCheckout 的說明）。
        // twshopCvsToggleAddress() 在編輯地址頁執行雖然本身無害（找不到已選運送方式時只會
        // 強制顯示/必填地址欄位，跟編輯地址頁原生行為一致），但語意上這整組本來就是結帳頁
        // 專屬功能，一併限定在同一個條件內。
        if (twshopData.isCheckout) {
            $(document).ready(function () {
                twshopInitShippingPlaceholder();
                twshopRelocateShipping();
                twshopCvsToggleAddress();
            });
        }
    }

    // 自訂登入／註冊按鈕文字：改由 twshop_login_register_btn_text_inline_js()（twshop.php）
    // 在 wp_footer 印一段獨立、不依賴 jQuery 的 sitewide 小段 JS 處理，這裡不再重複套用。
    // 原因見 CLAUDE.md「前端 JS」章節「登入／註冊按鈕文字為什麼用 JS 覆蓋」段落——
    // 這支 twshop-frontend.js 只在購物車/結帳/我的帳號等特定頁面才會載入，
    // 涵蓋不到佈景主題（如 Blocksy）頁首彈出登入視窗可以從任何頁面開啟的情況。

    // Points Redemption Interactivity
    $(document.body).on('click', '#twshop_apply_points_btn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var originalText = $btn.text();
        var points = $('#twshop_points_input').val();
        $btn.text('處理中...').prop('disabled', true);
        $.post(twshopData.ajaxUrl, {
            action:        'twshop_apply_points',
            points:        points,
            twshop_nonce:  twshopData.nonce
        }, function (res) {
            if (res && res.success === false) {
                alert((res.data && res.data.message) || '套用失敗，請重新輸入。');
                $btn.text(originalText).prop('disabled', false);
                return;
            }
            if (res && res.data && res.data.actual_points !== undefined) {
                $('#twshop_points_input').val(res.data.actual_points);
            }
            location.reload();
        });
    });

    // Wallet（儲值金）Redemption Interactivity
    $(document.body).on('click', '#twshop_apply_wallet_btn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var originalText = $btn.text();
        var amount = $('#twshop_wallet_input').val();
        $btn.text('處理中...').prop('disabled', true);
        $.post(twshopData.ajaxUrl, {
            action:        'twshop_apply_wallet',
            amount:        amount,
            twshop_nonce:  twshopData.nonce
        }, function (res) {
            if (res && res.success === false) {
                alert((res.data && res.data.message) || '套用失敗，請重新輸入。');
                $btn.text(originalText).prop('disabled', false);
                return;
            }
            if (res && res.data && res.data.actual_amount !== undefined) {
                $('#twshop_wallet_input').val(res.data.actual_amount);
            }
            location.reload();
        });
    });

    // Wallet（儲值金）線上儲值 v25.8.67 起改用「儲值金商品」——顧客直接在商店頁把
    // 儲值金商品加進購物車、走正常結帳流程，不再需要這裡的專屬 AJAX 建單按鈕，原本的
    // twshopCreateWalletTopupOrder() 與方案/自訂金額按鈕處理已整個移除。

    // Points-for-Product Redemption
    $(document.body).on('click', '.twshop-redeem-product-btn', function (e) {
        e.preventDefault();
        var $btn      = $(this);
        var productId = $btn.data('product_id');
        var origText  = $btn.text();

        // 數量下拉只在該商品的單次兌換上限（max_qty）大於 1 時才會渲染（見
        // twshop_render_points_redeemable_products_section()），找不到就預設 1 個，
        // 跟改版前行為一致。用 data-product_id 對應，因為同一頁可能同時列出多個可兌換商品。
        var $qtySelect = $btn.siblings('.twshop-redeem-qty-select[data-product_id="' + productId + '"]');
        var qty = $qtySelect.length ? parseInt($qtySelect.val(), 10) : 1;
        if (!qty || qty <= 0) qty = 1;

        $btn.text('兌換中...').prop('disabled', true);
        $qtySelect.prop('disabled', true);

        $.post(twshopData.ajaxUrl, {
            action:       'twshop_redeem_points_product',
            product_id:   productId,
            qty:          qty,
            twshop_nonce: twshopData.nonce
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                $btn.text(origText).prop('disabled', false);
                $qtySelect.prop('disabled', false);
                alert((response.data && response.data.message) || '兌換失敗，請重新整理頁面後再試。');
            }
        }).fail(function () {
            $btn.text(origText).prop('disabled', false);
            $qtySelect.prop('disabled', false);
            alert('兌換失敗，請重新整理頁面後再試。');
        });
    });

}(jQuery));
