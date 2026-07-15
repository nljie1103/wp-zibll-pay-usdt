(function () {
    'use strict';

    var unloadingAllowed = false;

    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }

        callback();
    }

    function normalized(value) {
        return String(value || '').toLocaleLowerCase().trim();
    }

    function booleanValue(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    }

    function findById(manager, id) {
        var element = id ? document.getElementById(id) : null;
        return element && manager.contains(element) ? element : null;
    }

    function routeConfig(manager, routeId) {
        var match = null;

        manager.querySelectorAll('[data-route-config]').forEach(function (details) {
            if (!match && details.getAttribute('data-route-id') === routeId) {
                match = details;
            }
        });

        return match;
    }

    function routeEnabledInput(config) {
        if (!config) {
            return null;
        }

        return config.querySelector('[data-route-enabled-input], input[type="checkbox"][id^="jiuliu-route-enabled-"]');
    }

    function routeControls(config) {
        if (!config) {
            return [];
        }

        return Array.prototype.filter.call(
            config.querySelectorAll('input, select, textarea'),
            function (control) {
                return !control.disabled && control.type !== 'button' && control.type !== 'submit';
            }
        );
    }

    function controlState(control) {
        if (control.type === 'checkbox' || control.type === 'radio') {
            return control.checked ? '1' : '0';
        }

        if (control.tagName === 'SELECT' && control.multiple) {
            return Array.prototype.map.call(control.options, function (option) {
                return option.selected ? '1' : '0';
            }).join('');
        }

        return String(control.value || '');
    }

    function captureSnapshot(config) {
        config._jiuliuRouteSnapshot = routeControls(config).map(function (control) {
            return {
                control: control,
                value: controlState(control)
            };
        });
    }

    function routeIsDirty(config) {
        var snapshot = config && config._jiuliuRouteSnapshot;
        if (!snapshot) {
            return false;
        }

        return snapshot.some(function (item) {
            return controlState(item.control) !== item.value;
        });
    }

    function restoreSnapshot(config) {
        var snapshot = config && config._jiuliuRouteSnapshot;
        if (!snapshot) {
            return;
        }

        snapshot.forEach(function (item) {
            var control = item.control;

            if (control.type === 'checkbox' || control.type === 'radio') {
                control.checked = item.value === '1';
                return;
            }

            if (control.tagName === 'SELECT' && control.multiple) {
                Array.prototype.forEach.call(control.options, function (option, index) {
                    option.selected = item.value.charAt(index) === '1';
                });
                return;
            }

            control.value = item.value;
        });
    }

    function updateSnapshotControl(config, control) {
        var snapshot = config && config._jiuliuRouteSnapshot;
        if (!snapshot || !control) {
            return;
        }

        snapshot.forEach(function (item) {
            if (item.control === control) {
                item.value = controlState(control);
            }
        });
    }

    function savedEnabled(config) {
        var checkbox = routeEnabledInput(config);
        var snapshot = config && config._jiuliuRouteSnapshot;
        var result = config ? config.getAttribute('data-route-enabled') === '1' : false;

        if (!checkbox || !snapshot) {
            return result;
        }

        snapshot.forEach(function (item) {
            if (item.control === checkbox) {
                result = item.value === '1';
            }
        });

        return result;
    }

    function summaryElements(manager, routeId) {
        return Array.prototype.filter.call(manager.querySelectorAll('[data-route-summary]'), function (summary) {
            return summary.getAttribute('data-route-id') === routeId;
        });
    }

    function runtimeLabel(summary) {
        return summary.querySelector('[data-route-runtime-label]')
            || summary.querySelector('.jiuliu-route-runtime-state')
            || summary.querySelector('.jiuliu-route-state');
    }

    function renderPendingState(manager, config) {
        if (!config) {
            return;
        }

        var dirty = routeIsDirty(config);
        var checkbox = routeEnabledInput(config);
        var initialEnabled = savedEnabled(config);
        var pendingRuntime = checkbox && checkbox.checked !== initialEnabled
            ? (checkbox.checked ? 'enable' : 'disable')
            : '';
        var dirtyLabel = config.querySelector('[data-route-dirty-status]');
        var pendingLabel = config.querySelector('[data-route-pending-status]');
        var routeId = config.getAttribute('data-route-id');

        config.classList.toggle('is-dirty', dirty);
        config.setAttribute('data-route-dirty', dirty ? '1' : '0');
        config.setAttribute('data-route-pending-runtime', pendingRuntime);

        if (dirtyLabel) {
            dirtyLabel.textContent = dirty ? '有未保存的修改' : '';
        }
        if (pendingLabel) {
            pendingLabel.textContent = pendingRuntime === 'enable'
                ? '待保存启用'
                : (pendingRuntime === 'disable' ? '待保存停用' : '');
        }

        summaryElements(manager, routeId).forEach(function (summary) {
            var state = runtimeLabel(summary);
            summary.classList.toggle('is-dirty', dirty);
            summary.setAttribute('data-route-dirty', dirty ? '1' : '0');
            summary.setAttribute('data-route-pending-runtime', pendingRuntime);

            if (!state) {
                return;
            }

            var stateContainer = state.closest('.jiuliu-route-runtime-state') || state;

            if (!state.hasAttribute('data-route-saved-label')) {
                state.setAttribute('data-route-saved-label', state.textContent);
            }
            state.textContent = pendingRuntime === 'enable'
                ? '待保存启用'
                : (pendingRuntime === 'disable'
                    ? '待保存停用'
                    : state.getAttribute('data-route-saved-label'));
            state.classList.toggle('is-pending', pendingRuntime !== '');
            stateContainer.classList.toggle('is-pending', pendingRuntime !== '');
        });
    }

    function anyRouteDirty(manager) {
        return Array.prototype.some.call(manager.querySelectorAll('[data-route-config]'), routeIsDirty);
    }

    function announce(manager, message, isError) {
        var live = manager.querySelector('[data-route-live]');
        if (live) {
            live.textContent = '';
            window.setTimeout(function () {
                live.textContent = message;
            }, 10);
        }

        manager.classList.toggle('has-route-error', Boolean(isError));
    }

    function updateExpandedState(manager) {
        var configurations = manager.querySelector('[data-route-configurations]');

        manager.querySelectorAll('[data-route-open]').forEach(function (button) {
            var target = findById(manager, button.getAttribute('data-route-open'));
            button.setAttribute('aria-expanded', target && target.open && (!configurations || configurations.open) ? 'true' : 'false');
        });

        var available = manager.querySelector('[data-route-available]');
        manager.querySelectorAll('[data-route-toggle-available]').forEach(function (button) {
            button.setAttribute('aria-expanded', available && available.open ? 'true' : 'false');
        });
    }

    function revealRoute(manager, target, focusSummary, smoothScroll) {
        if (!target || !target.matches('[data-route-config]')) {
            return;
        }

        var configurations = manager.querySelector('[data-route-configurations]');
        if (configurations) {
            configurations.open = true;
        }

        manager.querySelectorAll('[data-route-config]').forEach(function (details) {
            if (details !== target) {
                details.open = false;
            }
        });

        target.open = true;
        updateExpandedState(manager);

        var summary = target.querySelector(':scope > summary');
        if (summary && focusSummary) {
            summary.focus({ preventScroll: true });
        }

        target.scrollIntoView({
            behavior: smoothScroll && !window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'smooth' : 'auto',
            block: 'start'
        });
    }

    function openRoute(manager, button) {
        revealRoute(manager, findById(manager, button.getAttribute('data-route-open')), true, true);
    }

    function openRouteFromHash(manager) {
        if (!window.location.hash || window.location.hash.length < 2) {
            return;
        }

        var targetId = window.location.hash.slice(1);
        try {
            targetId = decodeURIComponent(targetId);
        } catch (error) {
            return;
        }

        var target = findById(manager, targetId);
        if (target && target.matches('[data-route-config]')) {
            revealRoute(manager, target, false, false);
        }
    }

    function filterRoutes(manager) {
        var searchControl = manager.querySelector('input[data-route-search]');
        var legacyStatusControl = manager.querySelector('select[data-route-status]');
        var configControl = manager.querySelector('select[data-route-config-filter]');
        var runtimeControl = manager.querySelector('select[data-route-runtime-filter]');
        var assetControl = manager.querySelector('select[data-route-asset]');
        var search = normalized(searchControl ? searchControl.value : '');
        var legacyStatus = legacyStatusControl ? String(legacyStatusControl.value || 'all') : 'all';
        var configState = configControl ? String(configControl.value || 'all') : 'all';
        var runtimeState = runtimeControl ? String(runtimeControl.value || 'all') : legacyStatus;
        var asset = assetControl ? String(assetControl.value || 'all') : 'all';
        var visible = 0;
        var total = 0;

        manager.querySelectorAll('[data-route-summary]').forEach(function (summary) {
            var summaryConfig = summary.getAttribute('data-route-config-state') || 'unconfigured';
            var summaryRuntime = summary.getAttribute('data-route-runtime-state') || summary.getAttribute('data-route-status') || 'disabled';
            var matchesSearch = !search || normalized(summary.getAttribute('data-route-search')).indexOf(search) !== -1;
            var matchesConfig = configState === 'all' || summaryConfig === configState;
            var matchesRuntime = runtimeState === 'all' || summaryRuntime === runtimeState;
            var matchesAsset = asset === 'all' || summary.getAttribute('data-route-asset') === asset;
            var show = matchesSearch && matchesConfig && matchesRuntime && matchesAsset;

            summary.hidden = !show;
            total += 1;
            visible += show ? 1 : 0;
        });

        manager.querySelectorAll('[data-route-group]').forEach(function (group) {
            var hasVisibleRoute = Array.prototype.some.call(
                group.querySelectorAll('[data-route-summary]'),
                function (summary) { return !summary.hidden; }
            );

            group.hidden = !hasVisibleRoute;
            if (search && hasVisibleRoute) {
                group.open = true;
            }
        });

        announce(manager, visible === total
            ? '共 ' + total + ' 条支付路线'
            : '已显示 ' + visible + ' / ' + total + ' 条支付路线', false);

        var visibleAvailable = 0;
        var totalAvailable = 0;
        manager.querySelectorAll('[data-route-summary]').forEach(function (summary) {
            var state = summary.getAttribute('data-route-config-state') || '';
            if (state !== 'configured') {
                totalAvailable += 1;
                visibleAvailable += summary.hidden ? 0 : 1;
            }
        });
        manager.querySelectorAll('[data-route-no-results]').forEach(function (empty) {
            empty.hidden = totalAvailable === 0 || visibleAvailable > 0;
        });

        var available = manager.querySelector('[data-route-available]');
        if (available && visibleAvailable > 0 && (search || configState !== 'all' || runtimeState !== 'all' || asset !== 'all')) {
            available.open = true;
            updateExpandedState(manager);
        }
    }

    function copyFallback(value) {
        var textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '0';
        document.body.appendChild(textarea);
        textarea.select();

        var copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }

        document.body.removeChild(textarea);
        return copied ? Promise.resolve() : Promise.reject(new Error('copy failed'));
    }

    function copyText(value) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(value).catch(function () {
                return copyFallback(value);
            });
        }

        return copyFallback(value);
    }

    function announceCopy(manager, button, successful) {
        var original = button.getAttribute('data-copy-original-label');
        if (!original) {
            original = button.textContent;
            button.setAttribute('data-copy-original-label', original);
        }

        window.clearTimeout(button._jiuliuCopyTimer);
        button.textContent = successful ? '已复制' : '复制失败';
        announce(manager, successful ? '已复制到剪贴板' : '复制失败，请手动选择并复制', !successful);

        button._jiuliuCopyTimer = window.setTimeout(function () {
            button.textContent = original;
        }, 1600);
    }

    function copySource(manager, button) {
        var source = findById(manager, button.getAttribute('data-copy-source'));
        if (!source) {
            announceCopy(manager, button, false);
            return;
        }

        var value = 'value' in source ? source.value : source.textContent;
        value = String(value || '').trim();
        if (!value) {
            announceCopy(manager, button, false);
            return;
        }

        copyText(value).then(function () {
            announceCopy(manager, button, true);
        }).catch(function () {
            announceCopy(manager, button, false);
        });
    }

    function clearFieldErrors(config) {
        if (!config) {
            return;
        }

        config.querySelectorAll('[data-route-field-error="1"]').forEach(function (error) {
            error.remove();
        });
        config.querySelectorAll('[data-route-original-describedby]').forEach(function (field) {
            var describedBy = field.getAttribute('data-route-original-describedby');
            if (describedBy) {
                field.setAttribute('aria-describedby', describedBy);
                var hasServerError = describedBy.split(/\s+/).some(function (id) {
                    var describedElement = document.getElementById(id);
                    return describedElement && describedElement.getAttribute('data-route-field-error') === 'server';
                });
                if (!hasServerError) {
                    field.removeAttribute('aria-invalid');
                }
            } else {
                field.removeAttribute('aria-describedby');
                field.removeAttribute('aria-invalid');
            }
            field.removeAttribute('data-route-original-describedby');
        });
    }

    function clearFieldError(field) {
        if (!field || field.getAttribute('aria-invalid') !== 'true') {
            return;
        }

        var ids = String(field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        var retained = [];
        ids.forEach(function (id) {
            var error = document.getElementById(id);
            if (error && error.hasAttribute('data-route-field-error')) {
                error.remove();
            } else {
                retained.push(id);
            }
        });
        field.removeAttribute('aria-invalid');
        if (retained.length) {
            field.setAttribute('aria-describedby', retained.join(' '));
        } else {
            field.removeAttribute('aria-describedby');
        }
        field.removeAttribute('data-route-original-describedby');
    }

    function addFieldError(field, message, index) {
        var container = field.closest('td') || field.parentNode;
        var error = document.createElement('p');
        var errorId = (field.id || 'jiuliu-route-field') + '-error-' + index;
        var original = field.getAttribute('aria-describedby') || '';

        error.id = errorId;
        error.className = 'jiuliu-route-field-error';
        error.setAttribute('role', 'alert');
        error.setAttribute('data-route-field-error', '1');
        error.textContent = message;
        field.setAttribute('data-route-original-describedby', original);
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', (original ? original + ' ' : '') + errorId);

        var action = field.closest('.jiuliu-route-input-action');
        if (action && action.parentNode === container) {
            action.insertAdjacentElement('afterend', error);
        } else {
            field.insertAdjacentElement('afterend', error);
        }
    }

    function validHttpsUrl(value) {
        try {
            return new URL(value, window.location.href).protocol === 'https:' && /^https:\/\//i.test(value);
        } catch (error) {
            return false;
        }
    }

    function validJsonObject(value) {
        if (!String(value || '').trim()) {
            return true;
        }

        try {
            var parsed = JSON.parse(value);
            return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed);
        } catch (error) {
            return false;
        }
    }

    function validateRoute(manager, config, requireComplete) {
        clearFieldErrors(config);

        var errors = [];
        var adapter = config.getAttribute('data-route-adapter');
        var address = config.querySelector('[name$="[receive_address]"]');
        var rpc = config.querySelector('[name$="[rpc_url]"]');
        var headers = config.querySelector('[name$="[rpc_headers_json]"]');
        var addressValue = address ? String(address.value || '').trim() : '';

        if (!adapter) {
            adapter = rpc && rpc.type !== 'hidden' ? 'evm' : 'tron';
        }

        if (requireComplete && !addressValue) {
            errors.push({ field: address, message: '收款地址不能为空。' });
        } else if (addressValue && adapter === 'tron' && !/^T[1-9A-HJ-NP-Za-km-z]{33}$/.test(addressValue)) {
            errors.push({ field: address, message: '请输入有效的 34 位 TRON 收款地址。' });
        } else if (addressValue && adapter === 'evm' && !/^0x[0-9a-fA-F]{40}$/.test(addressValue)) {
            errors.push({ field: address, message: '请输入有效的 0x 开头 EVM 收款地址。' });
        }

        if (adapter === 'evm') {
            var rpcValue = rpc ? String(rpc.value || '').trim() : '';
            if (requireComplete && !rpcValue) {
                errors.push({ field: rpc, message: '启用 EVM 路线前必须填写 HTTPS JSON-RPC。' });
            } else if (rpcValue && !validHttpsUrl(rpcValue)) {
                errors.push({ field: rpc, message: 'JSON-RPC 必须使用有效的 HTTPS URL。' });
            }

            if (headers && !validJsonObject(headers.value)) {
                errors.push({ field: headers, message: 'RPC 请求头必须是合法的 JSON 对象。' });
            }
        }

        errors = errors.filter(function (error) { return Boolean(error.field); });
        errors.forEach(function (error, index) {
            addFieldError(error.field, error.message, index + 1);
        });

        if (!errors.length) {
            return true;
        }

        config.open = true;
        updateExpandedState(manager);
        errors[0].field.focus({ preventScroll: true });
        errors[0].field.scrollIntoView({
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            block: 'center'
        });
        announce(manager, '请修正当前路线中标出的配置错误后再保存。', true);
        return false;
    }

    function setHiddenValue(form, selector, name, value) {
        var field = form.querySelector(selector);
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = name;
            form.appendChild(field);
        }
        field.value = value;
    }

    function requestNativeSubmit(form) {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
        }

        var fallback = document.createElement('button');
        fallback.type = 'submit';
        fallback.hidden = true;
        form.appendChild(fallback);
        fallback.click();
        window.setTimeout(function () {
            if (fallback.parentNode) {
                fallback.parentNode.removeChild(fallback);
            }
        }, 0);
    }

    function gatewayInput(manager) {
        var form = manager.closest('form');
        return (form && form.querySelector('[data-gateway-enabled]'))
            || (form && form.querySelector('input[type="checkbox"][name="settings[enabled]"]'));
    }

    function routeReady(config) {
        var checkbox = routeEnabledInput(config);
        if (!checkbox || !checkbox.checked) {
            return false;
        }

        var adapter = config.getAttribute('data-route-adapter');
        var address = config.querySelector('[name$="[receive_address]"]');
        var rpc = config.querySelector('[name$="[rpc_url]"]');
        var headers = config.querySelector('[name$="[rpc_headers_json]"]');
        var addressValue = address ? String(address.value || '').trim() : '';

        if (!adapter) {
            adapter = rpc && rpc.type !== 'hidden' ? 'evm' : 'tron';
        }
        if (adapter === 'tron') {
            return /^T[1-9A-HJ-NP-Za-km-z]{33}$/.test(addressValue);
        }
        if (!/^0x[0-9a-fA-F]{40}$/.test(addressValue)) {
            return false;
        }
        if (!rpc || !validHttpsUrl(String(rpc.value || '').trim())) {
            return false;
        }
        return !headers || validJsonObject(headers.value);
    }

    function clearGatewayError(manager) {
        var error = manager.closest('form').querySelector('[data-gateway-error]');
        if (error) {
            error.textContent = '';
            error.hidden = true;
        }
    }

    function showGatewayError(manager) {
        var form = manager.closest('form');
        var gateway = gatewayInput(manager);
        var error = form.querySelector('[data-gateway-error]');
        var message = '请先配置并启用至少一条支付路线，再开启数字货币支付总网关。';

        if (!error) {
            error = document.createElement('p');
            error.className = 'jiuliu-gateway-error';
            error.setAttribute('data-gateway-error', '1');
            error.setAttribute('role', 'alert');
            (gateway && gateway.closest('td') ? gateway.closest('td') : form).appendChild(error);
        }
        error.hidden = false;
        error.textContent = message;

        var available = manager.querySelector('[data-route-available]');
        if (available) {
            available.open = true;
        }
        updateExpandedState(manager);
        if (gateway) {
            gateway.focus({ preventScroll: true });
            gateway.scrollIntoView({ block: 'center' });
        }
        announce(manager, message, true);
    }

    function gatewayCanSubmit(manager) {
        var gateway = gatewayInput(manager);
        if (!gateway || !gateway.checked) {
            clearGatewayError(manager);
            return true;
        }

        var ready = Array.prototype.some.call(manager.querySelectorAll('[data-route-config]'), routeReady);
        if (ready) {
            clearGatewayError(manager);
            return true;
        }

        showGatewayError(manager);
        return false;
    }

    function handleRouteSave(manager, button) {
        if (manager.getAttribute('data-route-submitting') === '1') {
            return;
        }

        var action = button.getAttribute('data-route-save');
        var config = button.closest('[data-route-config]');
        var form = manager.closest('form');
        var routeId = config ? config.getAttribute('data-route-id') : '';
        var checkbox = routeEnabledInput(config);

        if (!form || !config || !routeId || ['save_route', 'save_and_enable_route', 'disable_route'].indexOf(action) === -1) {
            return;
        }

        setHiddenValue(form, '[data-close-gateway], [name="jiuliu_close_gateway"]', 'jiuliu_close_gateway', '0');

        if (action === 'save_and_enable_route' && !validateRoute(manager, config, true)) {
            return;
        }
        if (action === 'save_route' && !validateRoute(manager, config, false)) {
            return;
        }

        if (action === 'disable_route'
            && savedEnabled(config)
            && Number(manager.getAttribute('data-enabled-count') || 0) <= 1
            && booleanValue(manager.getAttribute('data-gateway-enabled'))) {
            if (!window.confirm('这是当前最后一条已启用路线。停用后将没有可用的数字货币支付路线，是否同时关闭数字货币支付总网关？')) {
                return;
            }
            var gateway = gatewayInput(manager);
            if (gateway) {
                gateway.checked = false;
            }
            setHiddenValue(form, '[data-close-gateway], [name="jiuliu_close_gateway"]', 'jiuliu_close_gateway', '1');
        }

        if (checkbox) {
            if (action === 'save_and_enable_route') {
                checkbox.checked = true;
            } else if (action === 'disable_route') {
                checkbox.checked = false;
            } else {
                checkbox.checked = savedEnabled(config);
            }
            renderPendingState(manager, config);
        }

        setHiddenValue(form, '[data-settings-action], [name="jiuliu_settings_action"]', 'jiuliu_settings_action', action);
        setHiddenValue(form, '[data-settings-route-id], [name="jiuliu_settings_route_id"]', 'jiuliu_settings_route_id', routeId);
        requestNativeSubmit(form);
    }

    function cancelRoute(manager, button) {
        var config = button.closest('[data-route-config]');
        if (!config) {
            return;
        }

        restoreSnapshot(config);
        clearFieldErrors(config);
        renderPendingState(manager, config);
        config.open = false;
        updateExpandedState(manager);
        announce(manager, '已取消当前路线的未保存修改。', false);

        var opener = manager.querySelector('[data-route-open="' + config.id + '"]');
        if (opener) {
            opener.focus({ preventScroll: true });
        }
    }

    function updateEnabledCount(manager, enabled) {
        var count = Number(manager.getAttribute('data-enabled-count') || 0);
        count += enabled ? 1 : -1;
        count = Math.max(0, count);
        renderEnabledCount(manager, count);
    }

    function renderEnabledCount(manager, count) {
        count = Math.max(0, Number(count) || 0);
        manager.setAttribute('data-enabled-count', String(count));

        var label = manager.querySelector('[data-route-count-label]');
        if (label) {
            var total = manager.querySelectorAll('[data-route-config]').length;
            var configured = manager.querySelectorAll('[data-route-configured="1"]').length;
            label.textContent = '共 ' + total + ' 条路线，已配置 ' + configured + ' 条，已启用 ' + count + ' 条';
        }
    }

    function updateRouteAfterToggle(manager, routeId, enabled, gatewayEnabled, enabledCount) {
        var config = routeConfig(manager, routeId);
        var checkbox = routeEnabledInput(config);
        var wasEnabled = config ? config.getAttribute('data-route-enabled') === '1' : !enabled;

        if (config) {
            config.setAttribute('data-route-enabled', enabled ? '1' : '0');
            if (checkbox) {
                checkbox.checked = enabled;
                checkbox.defaultChecked = enabled;
                updateSnapshotControl(config, checkbox);
            }
        }

        summaryElements(manager, routeId).forEach(function (summary) {
            summary.setAttribute('data-route-runtime-state', enabled ? 'enabled' : 'disabled');
            summary.setAttribute('data-route-status', enabled ? 'enabled' : 'disabled');
            var state = runtimeLabel(summary);
            if (state) {
                var stateContainer = state.closest('.jiuliu-route-runtime-state') || state;
                state.textContent = enabled ? '已启用' : '已停用';
                state.setAttribute('data-route-saved-label', state.textContent);
                state.classList.toggle('is-enabled', enabled);
                state.classList.toggle('is-disabled', !enabled);
                state.classList.remove('is-pending');
                stateContainer.classList.toggle('is-enabled', enabled);
                stateContainer.classList.toggle('is-disabled', !enabled);
                stateContainer.classList.remove('is-pending');
            }
            var toggle = summary.querySelector('[data-route-toggle]');
            if (toggle) {
                toggle.setAttribute('data-route-toggle', enabled ? '0' : '1');
                toggle.textContent = enabled ? '停用' : '启用';
                toggle.classList.toggle('button-link-delete', enabled);
                toggle.classList.toggle('button-primary', !enabled);
            }
        });

        if (typeof enabledCount === 'number' && Number.isFinite(enabledCount)) {
            renderEnabledCount(manager, enabledCount);
        } else if (wasEnabled !== enabled) {
            updateEnabledCount(manager, enabled);
        }
        manager.setAttribute('data-gateway-enabled', gatewayEnabled ? '1' : '0');
        var gateway = gatewayInput(manager);
        if (gateway) {
            gateway.checked = gatewayEnabled;
            gateway.defaultChecked = gatewayEnabled;
        }
        var gatewayLabel = manager.closest('form').querySelector('[data-gateway-runtime-label]');
        if (gatewayLabel) {
            gatewayLabel.textContent = gatewayEnabled ? '已开启' : '已关闭';
        }
        if (gatewayEnabled) {
            gatewayCanSubmit(manager);
        } else {
            clearGatewayError(manager);
        }
        renderPendingState(manager, config);
        filterRoutes(manager);
    }

    function toggleErrorData(payload) {
        return payload && payload.data && typeof payload.data === 'object' ? payload.data : {};
    }

    function performRouteToggle(manager, button, closeGateway) {
        var routeId = button.getAttribute('data-route-id');
        var enable = button.getAttribute('data-route-toggle') === '1';
        var url = manager.getAttribute('data-route-toggle-url');
        var nonce = manager.getAttribute('data-route-toggle-nonce');
        var originalLabel = button.getAttribute('data-route-toggle-label') || button.textContent;
        var params = new URLSearchParams();

        params.set('action', 'jiuliu_crypto_toggle_route');
        params.set('nonce', nonce || '');
        params.set('route_id', routeId || '');
        params.set('enabled', enable ? '1' : '0');
        if (closeGateway) {
            params.set('close_gateway', '1');
        }

        button.setAttribute('data-route-toggle-label', originalLabel);
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.textContent = enable ? '正在启用…' : '正在停用…';

        return window.fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString()
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error('服务器返回了无法识别的响应。');
            });
        }).then(function (payload) {
            if (!payload || !payload.success) {
                var errorData = toggleErrorData(payload);
                if (errorData.code === 'last_enabled_route' && !closeGateway) {
                    if (window.confirm('这是当前最后一条已启用路线。停用后将没有可用的数字货币支付路线，是否同时关闭数字货币支付总网关？')) {
                        return performRouteToggle(manager, button, true);
                    }
                    throw new Error('已取消停用。');
                }
                throw new Error(errorData.message || '路线状态保存失败，请稍后重试。');
            }

            var data = payload.data || {};
            var savedEnabled = Object.prototype.hasOwnProperty.call(data, 'enabled') ? booleanValue(data.enabled) : enable;
            var gatewayEnabled = Object.prototype.hasOwnProperty.call(data, 'gateway_enabled')
                ? booleanValue(data.gateway_enabled)
                : booleanValue(manager.getAttribute('data-gateway-enabled'));
            var enabledCount = Object.prototype.hasOwnProperty.call(data, 'enabled_count')
                ? Number(data.enabled_count)
                : null;
            updateRouteAfterToggle(manager, routeId, savedEnabled, gatewayEnabled, enabledCount);
            announce(manager, data.message || (savedEnabled ? '路线已启用。' : '路线已停用，原配置已保留。'), false);
        }).catch(function (error) {
            announce(manager, error && error.message ? error.message : '路线状态保存失败，请稍后重试。', true);
        }).finally(function () {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.textContent = button.getAttribute('data-route-toggle') === '1' ? '启用' : '停用';
            button._jiuliuTogglePending = false;
        });
    }

    function handleRouteToggle(manager, button) {
        var routeId = button.getAttribute('data-route-id');
        var config = routeConfig(manager, routeId);

        if (!routeId || button._jiuliuTogglePending) {
            return;
        }
        if (routeIsDirty(config)) {
            announce(manager, '当前路线还有未保存的配置修改，请先保存或取消修改后再切换状态。', true);
            config.open = true;
            updateExpandedState(manager);
            config.scrollIntoView({ block: 'start' });
            return;
        }
        if (!window.fetch || !manager.getAttribute('data-route-toggle-url')) {
            announce(manager, '当前浏览器无法执行快捷启停，请在详细配置中修改并使用“保存全部设置”。', true);
            return;
        }

        button._jiuliuTogglePending = true;
        performRouteToggle(manager, button, false);
    }

    function initialiseManager(manager) {
        if (manager.getAttribute('data-route-js-ready') === '1') {
            return;
        }

        manager.setAttribute('data-route-js-ready', '1');
        manager.classList.add('is-js');
        manager.querySelectorAll('[data-route-config]').forEach(function (details) {
            captureSnapshot(details);
            details.setAttribute('data-route-dirty', '0');
            details.addEventListener('toggle', function () {
                updateExpandedState(manager);
            });
        });

        filterRoutes(manager);
        updateExpandedState(manager);

        manager.addEventListener('input', function (event) {
            if (event.target.matches('[data-route-search]')) {
                filterRoutes(manager);
                return;
            }

            var config = event.target.closest('[data-route-config]');
            if (config && event.target.matches('input, select, textarea')) {
                clearFieldError(event.target);
                renderPendingState(manager, config);
            }
        });

        manager.addEventListener('change', function (event) {
            if (event.target.matches('[data-route-status], [data-route-config-filter], [data-route-runtime-filter], [data-route-asset]')) {
                filterRoutes(manager);
                return;
            }

            var config = event.target.closest('[data-route-config]');
            if (config && event.target.matches('input, select, textarea')) {
                clearFieldError(event.target);
                renderPendingState(manager, config);
            }
        });

        manager.addEventListener('click', function (event) {
            var button = event.target.closest('button, [role="button"]');
            if (!button || !manager.contains(button)) {
                return;
            }

            if (button.hasAttribute('data-route-toggle-available')) {
                event.preventDefault();
                var available = manager.querySelector('[data-route-available]');
                if (available) {
                    available.open = true;
                    updateExpandedState(manager);
                    available.scrollIntoView({
                        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                        block: 'start'
                    });
                }
                return;
            }

            if (button.hasAttribute('data-route-open')) {
                event.preventDefault();
                openRoute(manager, button);
                return;
            }

            if (button.hasAttribute('data-route-toggle')) {
                event.preventDefault();
                handleRouteToggle(manager, button);
                return;
            }

            if (button.hasAttribute('data-route-save')) {
                event.preventDefault();
                handleRouteSave(manager, button);
                return;
            }

            if (button.hasAttribute('data-route-cancel')) {
                event.preventDefault();
                cancelRoute(manager, button);
                return;
            }

            if (button.hasAttribute('data-copy-source')) {
                event.preventDefault();
                copySource(manager, button);
                return;
            }

            if (button.hasAttribute('data-route-expand-all')) {
                event.preventDefault();
                manager.querySelectorAll('[data-route-config]').forEach(function (details) {
                    details.open = true;
                });
                updateExpandedState(manager);
                return;
            }

            if (button.hasAttribute('data-route-collapse-all')) {
                event.preventDefault();
                manager.querySelectorAll('[data-route-config]').forEach(function (details) {
                    details.open = false;
                });
                updateExpandedState(manager);
            }
        });

        var form = manager.closest('form');
        if (form && form.getAttribute('data-route-form-ready') !== '1') {
            form.setAttribute('data-route-form-ready', '1');
            form.addEventListener('click', function (event) {
                var saveAll = event.target.closest('[data-save-all]');
                if (!saveAll || !form.contains(saveAll)) {
                    return;
                }

                event.preventDefault();
                if (manager.getAttribute('data-route-submitting') === '1') {
                    return;
                }
                setHiddenValue(form, '[data-settings-action], [name="jiuliu_settings_action"]', 'jiuliu_settings_action', 'save_all');
                setHiddenValue(form, '[data-settings-route-id], [name="jiuliu_settings_route_id"]', 'jiuliu_settings_route_id', '');
                setHiddenValue(form, '[data-close-gateway], [name="jiuliu_close_gateway"]', 'jiuliu_close_gateway', '0');
                requestNativeSubmit(form);
            });
            form.addEventListener('change', function (event) {
                var gateway = gatewayInput(manager);
                if (!gateway || event.target !== gateway) {
                    return;
                }
                if (gateway.checked) {
                    gatewayCanSubmit(manager);
                } else {
                    clearGatewayError(manager);
                }
            });
            form.addEventListener('submit', function (event) {
                if (manager.getAttribute('data-route-submitting') === '1') {
                    event.preventDefault();
                    return;
                }
                if (!gatewayCanSubmit(manager)) {
                    event.preventDefault();
                    return;
                }

                manager.setAttribute('data-route-submitting', '1');
                unloadingAllowed = true;
                manager.querySelectorAll('[data-route-save], [data-route-toggle]').forEach(function (action) {
                    action.disabled = true;
                });
            });
        }

        var available = manager.querySelector('[data-route-available]');
        if (available) {
            available.addEventListener('toggle', function () {
                updateExpandedState(manager);
            });
        }

        var configurations = manager.querySelector('[data-route-configurations]');
        if (configurations) {
            configurations.addEventListener('toggle', function () {
                updateExpandedState(manager);
            });
        }

        openRouteFromHash(manager);
    }

    window.addEventListener('beforeunload', function (event) {
        if (unloadingAllowed) {
            return;
        }

        var dirty = Array.prototype.some.call(document.querySelectorAll('[data-route-manager]'), anyRouteDirty);
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    onReady(function () {
        document.querySelectorAll('[data-route-manager]').forEach(initialiseManager);
    });
}());
