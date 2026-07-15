(function () {
    'use strict';

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

    function findById(manager, id) {
        var element = document.getElementById(id);
        return element && manager.contains(element) ? element : null;
    }

    function updateExpandedState(manager) {
        manager.querySelectorAll('[data-route-open]').forEach(function (button) {
            var target = findById(manager, button.getAttribute('data-route-open'));
            button.setAttribute('aria-expanded', target && target.open ? 'true' : 'false');
        });

        var available = manager.querySelector('[data-route-available]');
        manager.querySelectorAll('[data-route-toggle-available]').forEach(function (button) {
            button.setAttribute('aria-expanded', available && available.open ? 'true' : 'false');
        });
    }

    function routeCheckbox(manager, button, target) {
        var routeId = button.getAttribute('data-route-id') || (target ? target.getAttribute('data-route-id') : '');
        var checkbox = routeId ? findById(manager, 'jiuliu-route-enabled-' + routeId) : null;

        if (!target && routeId) {
            manager.querySelectorAll('[data-route-config]').forEach(function (details) {
                if (!target && details.getAttribute('data-route-id') === routeId) {
                    target = details;
                }
            });
        }

        if (!checkbox && target) {
            checkbox = target.querySelector('input[type="checkbox"][id^="jiuliu-route-enabled-"]');
        }

        return checkbox;
    }

    function configForRoute(manager, routeId) {
        var match = null;
        manager.querySelectorAll('[data-route-config]').forEach(function (details) {
            if (!match && details.getAttribute('data-route-id') === routeId) {
                match = details;
            }
        });
        return match;
    }

    function updatePendingStatus(config, checkbox) {
        if (!config || !checkbox) {
            return;
        }

        var pending = config.querySelector('[data-route-pending-status]');
        if (!pending) {
            return;
        }

        var current = checkbox.checked ? '1' : '0';
        pending.textContent = current === checkbox.getAttribute('data-route-initial-enabled')
            ? ''
            : (checkbox.checked ? '保存后启用' : '保存后停用');
    }

    function openRoute(manager, button) {
        var target = findById(manager, button.getAttribute('data-route-open'));
        if (!target || !target.matches('[data-route-config]')) {
            return;
        }

        manager.querySelectorAll('[data-route-config]').forEach(function (details) {
            if (details !== target) {
                details.open = false;
            }
        });

        var requestedState = button.getAttribute('data-route-set-enabled');
        if (requestedState === '1' || requestedState === '0') {
            var checkbox = routeCheckbox(manager, button, target);
            if (checkbox) {
                checkbox.checked = requestedState === '1';
                checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                updatePendingStatus(target, checkbox);
            }
        }

        target.open = true;
        updateExpandedState(manager);

        var summary = target.querySelector(':scope > summary');
        if (summary) {
            summary.focus({ preventScroll: true });
        }

        target.scrollIntoView({
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            block: 'start'
        });
    }

    function filterRoutes(manager) {
        var searchControl = manager.querySelector('input[data-route-search]');
        var statusControl = manager.querySelector('select[data-route-status]');
        var assetControl = manager.querySelector('select[data-route-asset]');
        var search = normalized(searchControl ? searchControl.value : '');
        var status = statusControl ? String(statusControl.value || 'all') : 'all';
        var asset = assetControl ? String(assetControl.value || 'all') : 'all';
        var visible = 0;
        var total = 0;

        manager.querySelectorAll('[data-route-summary]').forEach(function (summary) {
            var matchesSearch = !search || normalized(summary.getAttribute('data-route-search')).indexOf(search) !== -1;
            var matchesStatus = status === 'all' || summary.getAttribute('data-route-status') === status;
            var matchesAsset = asset === 'all' || summary.getAttribute('data-route-asset') === asset;
            var show = matchesSearch && matchesStatus && matchesAsset;

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

        var live = manager.querySelector('[data-route-live]');
        if (live) {
            live.textContent = visible === total
                ? '共 ' + total + ' 条支付路线'
                : '已显示 ' + visible + ' / ' + total + ' 条支付路线';
        }

        var visibleAvailable = 0;
        var totalAvailable = 0;
        manager.querySelectorAll('[data-route-summary][data-route-status="disabled"]').forEach(function (summary) {
            totalAvailable += 1;
            visibleAvailable += summary.hidden ? 0 : 1;
        });
        manager.querySelectorAll('[data-route-no-results]').forEach(function (empty) {
            empty.hidden = totalAvailable === 0 || visibleAvailable > 0;
        });

        var available = manager.querySelector('[data-route-available]');
        if (available && visibleAvailable > 0 && (search || status === 'disabled' || asset !== 'all')) {
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

        var live = manager.querySelector('[data-route-live]');
        if (live) {
            live.textContent = successful ? '已复制到剪贴板' : '复制失败，请手动选择并复制';
        }

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

    function initialiseManager(manager) {
        manager.classList.add('is-js');
        manager.querySelectorAll('[data-route-config] input[type="checkbox"][id^="jiuliu-route-enabled-"]').forEach(function (checkbox) {
            checkbox.setAttribute('data-route-initial-enabled', checkbox.checked ? '1' : '0');
        });

        filterRoutes(manager);
        updateExpandedState(manager);

        manager.addEventListener('input', function (event) {
            if (event.target.matches('[data-route-search]')) {
                filterRoutes(manager);
            }
        });

        manager.addEventListener('change', function (event) {
            if (event.target.matches('[data-route-status], [data-route-asset]')) {
                filterRoutes(manager);
            }
            if (event.target.matches('[data-route-config] input[type="checkbox"][id^="jiuliu-route-enabled-"]')) {
                updatePendingStatus(event.target.closest('[data-route-config]'), event.target);
            }
        });

        manager.addEventListener('click', function (event) {
            var availableButton = event.target.closest('[data-route-toggle-available]');
            if (availableButton && manager.contains(availableButton)) {
                event.preventDefault();
                var available = manager.querySelector('[data-route-available]');
                if (available) {
                    available.open = true;
                    var availableSummary = available.querySelector(':scope > summary');
                    if (availableSummary) {
                        availableSummary.focus({ preventScroll: true });
                    }
                    available.scrollIntoView({
                        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                        block: 'start'
                    });
                }
                return;
            }

            var routeButton = event.target.closest('[data-route-open]');
            if (routeButton && manager.contains(routeButton)) {
                event.preventDefault();
                openRoute(manager, routeButton);
                return;
            }

            var stateButton = event.target.closest('[data-route-set-enabled]');
            if (stateButton && manager.contains(stateButton)) {
                event.preventDefault();
                var routeId = stateButton.getAttribute('data-route-id');
                var config = configForRoute(manager, routeId);
                var checkbox = routeCheckbox(manager, stateButton, config);
                if (checkbox) {
                    checkbox.checked = stateButton.getAttribute('data-route-set-enabled') === '1';
                    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                    updatePendingStatus(config, checkbox);
                    var stateLive = manager.querySelector('[data-route-live]');
                    if (stateLive) {
                        stateLive.textContent = checkbox.checked ? '已标记启用，保存设置后生效' : '已标记停用，保存设置后生效';
                    }
                }
                return;
            }

            var copyButton = event.target.closest('[data-copy-source]');
            if (copyButton && manager.contains(copyButton)) {
                event.preventDefault();
                copySource(manager, copyButton);
                return;
            }

            if (event.target.closest('[data-route-expand-all]')) {
                manager.querySelectorAll('[data-route-config]').forEach(function (details) {
                    details.open = true;
                });
                updateExpandedState(manager);
                return;
            }

            if (event.target.closest('[data-route-collapse-all]')) {
                manager.querySelectorAll('[data-route-config]').forEach(function (details) {
                    details.open = false;
                });
                updateExpandedState(manager);
            }
        });

        manager.querySelectorAll('[data-route-config]').forEach(function (details) {
            details.addEventListener('toggle', function () {
                updateExpandedState(manager);
            });
        });
        var available = manager.querySelector('[data-route-available]');
        if (available) {
            available.addEventListener('toggle', function () {
                updateExpandedState(manager);
            });
        }
    }

    onReady(function () {
        document.querySelectorAll('[data-route-manager]').forEach(initialiseManager);
    });
}());
