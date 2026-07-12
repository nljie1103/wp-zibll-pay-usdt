(function ($) {
    'use strict';

    function showMessage($target, message, type) {
        $target.removeClass('is-success is-error is-loading')
            .addClass(type ? 'is-' + type : '')
            .text(message);
    }

    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(textarea);
        return ok;
    }

    $('body').on('click', '.jiuliu-usdt-copy', function () {
        var $button = $(this);
        var text = String($button.data('copy') || '');
        var done = function (ok) {
            var original = $button.data('original-text') || $button.text();
            $button.data('original-text', original).text(ok ? jiuliuUsdt.copyOk : jiuliuUsdt.copyFail);
            window.setTimeout(function () {
                $button.text(original);
            }, 1500);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                done(true);
            }).catch(function () {
                done(fallbackCopy(text));
            });
        } else {
            done(fallbackCopy(text));
        }
    });

    $('body').on('submit', '.jiuliu-usdt-tx-form', function (event) {
        event.preventDefault();
        var $form = $(this);
        var $result = $form.find('.jiuliu-usdt-tx-result');
        var $button = $form.find('button[type="submit"]');
        var data = $form.serializeArray();
        data.push({ name: 'action', value: 'jiuliu_usdt_submit_txid' });

        $button.prop('disabled', true);
        showMessage($result, jiuliuUsdt.checking, 'loading');

        $.ajax({
            url: jiuliuUsdt.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: data
        }).done(function (response) {
            var payload = response && response.data ? response.data : {};
            showMessage($result, payload.message || jiuliuUsdt.error, response.success ? 'success' : 'error');
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            var payload = response.data || {};
            showMessage($result, payload.message || jiuliuUsdt.error, 'error');
        }).always(function () {
            $button.prop('disabled', false);
        });
    });

    // Zibll only removes its built-in wechat/alipay classes when switching
    // gateways. Explicitly remove our custom class on the next non-USDT
    // response so a reused cashier modal always has the correct layout.
    $(document).ajaxSuccess(function (event, xhr, settings, response) {
        if (!response || typeof response !== 'object' || !response.payment_method) {
            return;
        }
        var $payment = $('#zibpay_modal .pay-payment');
        if (response.payment_method === 'usdt_trc20') {
            $payment.addClass('usdt_trc20');
            window.setTimeout(function () {
                $payment.find('.pay-notice .notice').text(jiuliuUsdt.waiting);
            }, 0);
        } else {
            $payment.removeClass('usdt_trc20');
        }
    });
})(jQuery);
