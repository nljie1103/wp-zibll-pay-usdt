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

    $('body').on('click', '.jiuliu-crypto-copy', function () {
        var $button = $(this);
        var text = String($button.data('copy') || '');
        var done = function (ok) {
            var original = $button.data('original-text') || $button.text();
            $button.data('original-text', original).text(ok ? jiuliuCrypto.copyOk : jiuliuCrypto.copyFail);
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

    $('body').on('submit', '.jiuliu-crypto-tx-form', function (event) {
        event.preventDefault();
        var $form = $(this);
        var $result = $form.find('.jiuliu-crypto-tx-result');
        var $button = $form.find('button[type="submit"]');
        var data = $form.serializeArray();
        data.push({ name: 'action', value: 'jiuliu_crypto_submit_txid' });

        $button.prop('disabled', true);
        showMessage($result, jiuliuCrypto.checking, 'loading');

        $.ajax({
            url: jiuliuCrypto.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: data
        }).done(function (response) {
            var payload = response && response.data ? response.data : {};
            showMessage($result, payload.message || jiuliuCrypto.error, response.success ? 'success' : 'error');
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            var payload = response.data || {};
            showMessage($result, payload.message || jiuliuCrypto.error, 'error');
        }).always(function () {
            $button.prop('disabled', false);
        });
    });

    // Zibll only removes its built-in wechat/alipay classes when switching
    // gateways. Route membership comes from the server-side allowlist.
    $(document).ajaxSuccess(function (event, xhr, settings, response) {
        if (!response || typeof response !== 'object' || !response.payment_method) {
            return;
        }
        var $payment = $('#zibpay_modal .pay-payment');
        var methods = $.isArray(jiuliuCrypto.methods) ? jiuliuCrypto.methods : [];
        if (response.jiuliu_crypto || methods.indexOf(response.payment_method) !== -1) {
            $payment.addClass('jiuliu_crypto');
            window.setTimeout(function () {
                $payment.find('.pay-notice .notice').text(jiuliuCrypto.waiting);
                var label = $payment.find('.jiuliu-crypto-details').data('route-label') || response.jiuliu_crypto_label || '';
                var $header = $payment.find('.pay-logo-header');
                $header.find('.jiuliu-crypto-route-label').remove();
                if (label) {
                    $('<span class="jiuliu-crypto-route-label"></span>').text(label).appendTo($header);
                }
            }, 0);
        } else {
            $payment.removeClass('jiuliu_crypto');
            $payment.find('.jiuliu-crypto-route-label').remove();
        }
    });
})(jQuery);
