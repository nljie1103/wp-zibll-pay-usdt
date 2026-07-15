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

    function choiceValue(choice, keys, fallback) {
        var value = fallback || '';
        $.each(keys, function (index, key) {
            if (choice && choice[key] !== undefined && choice[key] !== null && String(choice[key]) !== '') {
                value = String(choice[key]);
                return false;
            }
        });
        return value;
    }

    function normalizedRouteChoices() {
        var raw = $.isArray(jiuliuCrypto.routeChoices) ? jiuliuCrypto.routeChoices : [];
        var enabledMethods = $.isArray(jiuliuCrypto.methods) ? jiuliuCrypto.methods : [];
        var seen = {};
        var choices = [];

        $.each(raw, function (index, item) {
            var method = choiceValue(item, ['method', 'paymentMethod', 'payment_method']);
            var asset = choiceValue(item, ['assetSymbol', 'asset_symbol', 'symbol']).toUpperCase();
            var network = choiceValue(item, ['networkLabel', 'network_label', 'network']);
            if (!method || !asset || !network || seen[method]) {
                return;
            }
            if (enabledMethods.length && enabledMethods.indexOf(method) === -1) {
                return;
            }
            seen[method] = true;
            choices.push({
                method: method,
                routeId: choiceValue(item, ['routeId', 'route_id', 'id']),
                asset: asset,
                assetLabel: choiceValue(item, ['assetLabel', 'asset_label', 'assetName', 'asset_name'], asset),
                network: network,
                issuer: choiceValue(item, ['issuerLabel', 'issuer_label']),
                assetType: choiceValue(item, ['assetType', 'asset_type'])
            });
        });
        return choices;
    }

    var routeChoices = normalizedRouteChoices();
    var routeChoiceByMethod = {};
    $.each(routeChoices, function (index, choice) {
        routeChoiceByMethod[choice.method] = choice;
    });

    function sourceMethod($source, type) {
        if ('regular' === type) {
            return String($source.attr('data-value') || '');
        }
        return String($source.attr('data-jiuliu-method') || $source.find('.jiuliu-crypto-method-marker[data-jiuliu-method]').first().attr('data-jiuliu-method') || '');
    }

    function sourceIsActive($source, type) {
        return 'regular' === type
            ? $source.hasClass('active')
            : $source.find('.div-checkbox.checked').length > 0;
    }

    function selectedChoice(group) {
        return routeChoiceByMethod[group.selectedMethod] || null;
    }

    function issuerSuffix(choice) {
        var parts = [];
        if (choice.issuer) {
            parts.push(choice.issuer);
        }
        if ('custodial_peg' === choice.assetType) {
            parts.push(jiuliuCrypto.custodialPegLabel || '托管锚定');
        }
        return parts.length ? ' · ' + parts.join(' / ') : '';
    }

    function updateGroupSummary(group) {
        var label = jiuliuCrypto.gatewayLabel || jiuliuCrypto.cryptoPayment || '数字货币支付';
        var choice = selectedChoice(group);
        if (group.$label.text() !== label) {
            group.$label.text(label);
        }
        var summary = choice ? choice.asset + ' · ' + choice.network + issuerSuffix(choice) : '';
        if (group.$summary.text() !== summary) {
            group.$summary.text(summary);
        }
    }

    function populateNetworks(group, asset, preferredMethod) {
        var firstMethod = '';
        group.$network.empty();
        $.each(routeChoices, function (index, choice) {
            if (choice.asset !== asset) {
                return;
            }
            if (!firstMethod) {
                firstMethod = choice.method;
            }
            $('<option></option>')
                .attr('value', choice.method)
                .text(choice.network + issuerSuffix(choice))
                .appendTo(group.$network);
        });
        if (preferredMethod && group.$network.find('option[value="' + preferredMethod.replace(/"/g, '\\"') + '"]').length) {
            group.$network.val(preferredMethod);
        } else {
            group.$network.val(firstMethod);
        }
        group.selectedMethod = String(group.$network.val() || '');
        updateGroupSummary(group);
    }

    function setGroupChoice(group, method) {
        var choice = routeChoiceByMethod[method];
        if (!choice) {
            return;
        }
        group.$asset.val(choice.asset);
        populateNetworks(group, choice.asset, choice.method);
    }

    function syncGroup(group) {
        var activeMethod = '';
        group.$sources.each(function () {
            var $source = $(this);
            if (!activeMethod && sourceIsActive($source, group.type)) {
                activeMethod = sourceMethod($source, group.type);
            }
        });

        if (activeMethod && routeChoiceByMethod[activeMethod]) {
            if (group.selectedMethod !== activeMethod || String(group.$network.val() || '') !== activeMethod) {
                setGroupChoice(group, activeMethod);
            }
            group.$unified.addClass('active');
            group.$panel.prop('hidden', false);
        } else {
            group.$unified.removeClass('active');
            if (!group.locked) {
                group.$panel.prop('hidden', true);
            }
        }
        updateGroupSummary(group);
    }

    function activateRealMethod(group, method) {
        if (group.locked || !routeChoiceByMethod[method]) {
            return;
        }
        var $target = group.$sources.filter(function () {
            return sourceMethod($(this), group.type) === method;
        }).first();
        if (!$target.length) {
            return;
        }
        group.selectedMethod = method;
        setGroupChoice(group, method);
        $target.trigger('click');
        window.setTimeout(function () {
            syncGroup(group);
        }, 0);
    }

    function setGroupLocked(group, locked) {
        group.locked = !!locked;
        group.$asset.prop('disabled', group.locked);
        group.$network.prop('disabled', group.locked);
        group.$panel.toggleClass('is-locked', group.locked);
        group.$lockNotice.prop('hidden', !group.locked);
        if (group.locked) {
            group.$panel.prop('hidden', false);
        }
    }

    function createUnifiedGroup($host, $sources, type) {
        var group = $host.data('jiuliuCryptoRouteGroup');
        if (group && group.$unified && $.contains(document, group.$unified[0])) {
            group.$sources = group.$sources.filter(function () {
                return $.contains(document, this);
            }).add($sources);
            return group;
        }
        if (group) {
            if (group.$unified) {
                group.$unified.remove();
            }
            if (group.$panel) {
                group.$panel.remove();
            }
            $host.removeData('jiuliuCryptoRouteGroup');
        }

        var $unified = $('<div></div>')
            .addClass('jiuliu-crypto-unified-method pointer')
            .attr({'role': 'button', 'tabindex': '0', 'aria-expanded': 'false'});
        var logoUrl = jiuliuCrypto.logoUrl || $sources.find('.jiuliu-crypto-method-logo').first().attr('src') || '';
        var $logo = $('<img>')
            .addClass('jiuliu-crypto-method-logo')
            .attr({'src': logoUrl, 'alt': ''});
        var $label = $('<span></span>').addClass('jiuliu-crypto-unified-label');
        if ('regular' === type) {
            $unified.addClass('flex jc hh hollow-radio flex-auto');
            $unified.append($logo, $label);
        } else {
            $unified.addClass('payment-methods');
            $unified.append(
                $('<div></div>').addClass('flex jsb ac').append(
                    $('<div></div>').addClass('flex ac').append(
                        $('<div></div>').addClass('mr6 payment-icon').append($logo),
                        $('<div></div>').addClass('muted-color').append($label)
                    ),
                    $('<div></div>').addClass('cart-col-checkbox').append($('<div></div>').addClass('div-checkbox jiuliu-crypto-unified-check'))
                )
            );
        }

        var $asset = $('<select></select>').addClass('jiuliu-crypto-asset-select').attr('aria-label', jiuliuCrypto.selectAsset || jiuliuCrypto.chooseAsset || '选择币种');
        var assets = {};
        $.each(routeChoices, function (index, choice) {
            if (!assets[choice.asset]) {
                assets[choice.asset] = true;
                $('<option></option>').attr('value', choice.asset).text(choice.assetLabel || choice.asset).appendTo($asset);
            }
        });
        var $network = $('<select></select>').addClass('jiuliu-crypto-network-select').attr('aria-label', jiuliuCrypto.selectNetwork || jiuliuCrypto.chooseNetwork || '选择网络');
        var $summary = $('<div></div>').addClass('jiuliu-crypto-selector-summary');
        var $lockNotice = $('<div></div>')
            .addClass('jiuliu-crypto-selector-lock')
            .text(jiuliuCrypto.routeLocked || '支付单已按当前币种和网络锁定；如需换币或换链，请关闭本单后重新创建。')
            .prop('hidden', true);
        var $panel = $('<div></div>')
            .addClass('jiuliu-crypto-route-selector')
            .prop('hidden', true)
            .append(
                $('<div></div>').addClass('jiuliu-crypto-selector-title').text(jiuliuCrypto.selectorTitle || '选择币种和支付网络'),
                $('<div></div>').addClass('jiuliu-crypto-selector-controls').append(
                    $('<label></label>').append($('<span></span>').text(jiuliuCrypto.assetLabel || jiuliuCrypto.chooseAsset || '币种'), $asset),
                    $('<label></label>').append($('<span></span>').text(jiuliuCrypto.networkLabel || jiuliuCrypto.chooseNetwork || '网络'), $network)
                ),
                $summary,
                $('<div></div>').addClass('jiuliu-crypto-selector-fee').append(
                    $('<strong></strong>').text(jiuliuCrypto.exactAmountTitle || '网站必须完整收到支付单显示的精确金额。'),
                    $('<span></span>').text(jiuliuCrypto.payerFeeNotice || '网络费和交易所提币费由付款方另行承担，不得从显示金额中扣除。')
                ),
                $lockNotice
            );

        if ('regular' === type) {
            $sources.first().before($unified);
            $host.after($panel);
        } else {
            $sources.first().before($unified);
            $unified.after($panel);
        }

        group = {
            type: type,
            $host: $host,
            $sources: $sources,
            $unified: $unified,
            $label: $label,
            $panel: $panel,
            $asset: $asset,
            $network: $network,
            $summary: $summary,
            $lockNotice: $lockNotice,
            selectedMethod: routeChoices.length ? routeChoices[0].method : '',
            locked: false
        };
        $host.data('jiuliuCryptoRouteGroup', group);
        if (routeChoices.length) {
            setGroupChoice(group, group.selectedMethod);
        }

        $unified.on('click keydown', function (event) {
            if ('keydown' === event.type && 13 !== event.which && 32 !== event.which) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            modalOpener = this;
            group.$panel.prop('hidden', false);
            group.$unified.attr('aria-expanded', 'true');
            if (!group.locked) {
                activateRealMethod(group, group.selectedMethod || String(group.$network.val() || ''));
            }
        });
        $asset.on('change', function () {
            if (group.locked) {
                return;
            }
            populateNetworks(group, String($(this).val() || ''), '');
            activateRealMethod(group, String(group.$network.val() || ''));
        });
        $network.on('change', function () {
            activateRealMethod(group, String($(this).val() || ''));
        });
        return group;
    }

    function initializeUnifiedSelectors() {
        if (!routeChoices.length) {
            return;
        }
        var regularHosts = [];
        $('.payment-method-radio[data-value]').each(function () {
            var $source = $(this);
            var method = String($source.attr('data-value') || '');
            if (!routeChoiceByMethod[method]) {
                return;
            }
            $source.addClass('jiuliu-crypto-source-method').attr('aria-hidden', 'true');
            var host = $source.parent()[0];
            if (host && regularHosts.indexOf(host) === -1) {
                regularHosts.push(host);
            }
        });
        $.each(regularHosts, function (index, host) {
            var $host = $(host);
            var $sources = $host.children('.payment-method-radio[data-value]').filter(function () {
                return !!routeChoiceByMethod[String($(this).attr('data-value') || '')];
            });
            var group = createUnifiedGroup($host, $sources, 'regular');
            syncGroup(group);
        });

        var shopHosts = [];
        $('.jiuliu-crypto-method-marker[data-jiuliu-method]').each(function () {
            var $marker = $(this);
            var method = String($marker.attr('data-jiuliu-method') || '');
            var $source = $marker.closest('.payment-methods');
            if (!$source.length || !routeChoiceByMethod[method]) {
                return;
            }
            $source.attr({'data-jiuliu-method': method, 'aria-hidden': 'true'}).addClass('jiuliu-crypto-source-method');
            var host = $source.parent()[0];
            if (host && shopHosts.indexOf(host) === -1) {
                shopHosts.push(host);
            }
        });
        $.each(shopHosts, function (index, host) {
            var $host = $(host);
            var $sources = $host.children('.payment-methods.jiuliu-crypto-source-method');
            var group = createUnifiedGroup($host, $sources, 'shop');
            syncGroup(group);
        });

        refreshSelectorLocks();
    }

    function eachUnifiedGroup(callback) {
        $('.jiuliu-crypto-unified-method').each(function () {
            var $host = $(this).parent();
            var group = $host.data('jiuliuCryptoRouteGroup');
            if (group) {
                callback(group);
            }
        });
    }

    function refreshSelectorLocks(forceLock) {
        var locked = !!forceLock || $('.jiuliu-crypto-details:visible').length > 0;
        eachUnifiedGroup(function (group) {
            setGroupLocked(group, locked && group.$unified.hasClass('active'));
        });
    }

    var selectorRefreshQueued = false;
    function queueSelectorRefresh() {
        if (selectorRefreshQueued) {
            return;
        }
        selectorRefreshQueued = true;
        window.setTimeout(function () {
            selectorRefreshQueued = false;
            initializeUnifiedSelectors();
        }, 0);
    }

    var cryptoModalSelector = '#zibpay_modal';
    var cryptoModalClass = 'jiuliu-crypto-modal-active';
    var modalStateQueued = false;
    var modalOpener = null;

    function cryptoModal() {
        return $(cryptoModalSelector);
    }

    function ensureModalCloseButton($modal) {
        var $payment = $modal.find('.pay-payment').first();
        var $nativeClose = $payment.find('.pay-qrcon [data-dismiss="modal"]').first();
        if (!$payment.length || !$nativeClose.length) {
            return $();
        }
        $nativeClose.addClass('jiuliu-crypto-native-modal-close');
        var $close = $payment.children('.jiuliu-crypto-modal-close').first();
        if (!$close.length) {
            $close = $('<button></button>')
                .addClass('jiuliu-crypto-modal-close but cir hollow nowave')
                .append($('<span></span>').attr('aria-hidden', 'true').text('\u00d7'))
                .prependTo($payment);
        }
        $close
            .attr({
                'type': 'button',
                'aria-label': '关闭支付窗口',
                'title': '关闭支付窗口'
            });
        return $close;
    }

    function setCryptoModalActive(active) {
        var $modal = cryptoModal();
        if (!$modal.length) {
            return;
        }
        if (active) {
            var wasActive = $modal.hasClass(cryptoModalClass);
            $modal.addClass(cryptoModalClass);
            if (!wasActive) {
                $modal.find('.modal-pay-body').scrollTop(0);
            }
            ensureModalCloseButton($modal);
            window.setTimeout(function () {
                if (!$modal.hasClass(cryptoModalClass) || !$modal.hasClass('in')) {
                    return;
                }
                var $close = ensureModalCloseButton($modal);
                if ($close.length) {
                    $close.trigger('focus');
                }
            }, 0);
            return;
        }
        $modal.removeClass(cryptoModalClass).removeData('jiuliuCryptoHiding');
        $modal.find('.jiuliu-crypto-modal-close').remove();
        $modal.find('.jiuliu-crypto-native-modal-close').removeClass('jiuliu-crypto-native-modal-close');
    }

    function hideCryptoModal() {
        var $modal = cryptoModal();
        if (!$modal.length || !$modal.hasClass(cryptoModalClass) || $modal.data('jiuliuCryptoHiding')) {
            return;
        }
        $modal.data('jiuliuCryptoHiding', true);
        // Use Zibll/Bootstrap's normal UI hide path. This function deliberately
        // does not call any order, invoice, cancellation or monitoring endpoint.
        if ($.isFunction($modal.modal)) {
            $modal.modal('hide');
        } else {
            $modal.removeData('jiuliuCryptoHiding');
        }
    }

    function responseIsCrypto(response, responseMethod) {
        var methods = $.isArray(jiuliuCrypto.methods) ? jiuliuCrypto.methods : [];
        return !!(response && response.jiuliu_crypto) || methods.indexOf(responseMethod) !== -1;
    }

    function queueModalStateCheck() {
        if (modalStateQueued) {
            return;
        }
        modalStateQueued = true;
        window.setTimeout(function () {
            modalStateQueued = false;
            var $modal = cryptoModal();
            if ($modal.hasClass(cryptoModalClass) && !$modal.find('.jiuliu-crypto-details').length) {
                setCryptoModalActive(false);
                $modal.find('.pay-payment').removeClass('jiuliu_crypto');
                $modal.find('.jiuliu-crypto-route-label').remove();
            }
        }, 0);
    }

    function clickedMethod($source) {
        if ($source.hasClass('payment-method-radio')) {
            return String($source.attr('data-value') || '');
        }
        return String($source.attr('data-jiuliu-method') || $source.find('.jiuliu-crypto-method-marker[data-jiuliu-method]').first().attr('data-jiuliu-method') || '');
    }

    function bindCryptoModalEvents() {
        $('body')
            .off('.jiuliuCryptoModal')
            .on('click.jiuliuCryptoModal', cryptoModalSelector + '.' + cryptoModalClass, function (event) {
                if (event.target !== this) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                hideCryptoModal();
            })
            .on('click.jiuliuCryptoModal', cryptoModalSelector + '.' + cryptoModalClass + ' .jiuliu-crypto-modal-close', function (event) {
                event.preventDefault();
                event.stopPropagation();
                hideCryptoModal();
            })
            .on('click.jiuliuCryptoModal', '.modal-backdrop', function (event) {
                var $modal = cryptoModal();
                if (event.target !== this || !$modal.hasClass(cryptoModalClass) || !$modal.hasClass('in')) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                hideCryptoModal();
            });

        $(document)
            .off('keydown.jiuliuCryptoModal')
            .on('keydown.jiuliuCryptoModal', function (event) {
                if ((27 === event.which || 'Escape' === event.key) && cryptoModal().hasClass(cryptoModalClass)) {
                    event.preventDefault();
                    hideCryptoModal();
                }
            });
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
        if (!response || typeof response !== 'object') {
            return;
        }
        var responseMethod = response.payment_method ? String(response.payment_method) : '';
        if (!response.jiuliu_crypto && !responseMethod) {
            return;
        }
        var $payment = $(cryptoModalSelector + ' .pay-payment');
        if (responseIsCrypto(response, responseMethod)) {
            $payment.addClass('jiuliu_crypto');
            setCryptoModalActive(true);
            window.setTimeout(function () {
                $payment.find('.pay-notice .notice').text(jiuliuCrypto.waiting);
                var label = $payment.find('.jiuliu-crypto-details').data('route-label') || response.jiuliu_crypto_label || '';
                var $header = $payment.find('.pay-logo-header');
                $header.find('.jiuliu-crypto-route-label').remove();
                if (label) {
                    $('<span class="jiuliu-crypto-route-label"></span>').text(label).appendTo($header);
                }
                refreshSelectorLocks(true);
            }, 0);
        } else {
            setCryptoModalActive(false);
            $payment.removeClass('jiuliu_crypto');
            $payment.find('.jiuliu-crypto-route-label').remove();
        }
    });

    $('body').on('click', '.payment-method-radio[data-value], .payment-methods', function () {
        if ($(this).hasClass('jiuliu-crypto-unified-method')) {
            return;
        }
        var method = clickedMethod($(this));
        if (!method || !routeChoiceByMethod[method]) {
            setCryptoModalActive(false);
        }
        window.setTimeout(function () {
            eachUnifiedGroup(syncGroup);
        }, 0);
    });

    $('body').on('hidden.bs.modal', '#zibpay_modal', function () {
        setCryptoModalActive(false);
        $(this).find('.pay-payment').removeClass('jiuliu_crypto');
        $(this).find('.jiuliu-crypto-route-label').remove();
        eachUnifiedGroup(function (group) {
            setGroupLocked(group, false);
            syncGroup(group);
        });
        if (modalOpener && $.contains(document, modalOpener)) {
            $(modalOpener).trigger('focus');
        }
        modalOpener = null;
    }).on('shown.bs.modal', '#zibpay_modal', function () {
        refreshSelectorLocks();
    });

    $(function () {
        bindCryptoModalEvents();
        initializeUnifiedSelectors();
        if (window.MutationObserver && document.body) {
            new window.MutationObserver(function (mutations) {
                var relevant = false;
                var selector = '.payment-method-radio, .payment-methods, .jiuliu-crypto-details, #zibpay_modal';
                $.each(mutations, function (index, mutation) {
                    $.each([].slice.call(mutation.addedNodes || []).concat([].slice.call(mutation.removedNodes || [])), function (nodeIndex, node) {
                        if (1 !== node.nodeType) {
                            return;
                        }
                        var $node = $(node);
                        if ($node.is(selector) || $node.find(selector).length) {
                            relevant = true;
                            return false;
                        }
                    });
                    return !relevant;
                });
                if (relevant) {
                    queueSelectorRefresh();
                    queueModalStateCheck();
                }
            }).observe(document.body, {
                childList: true,
                subtree: true
            });
        }
    });
})(jQuery);
