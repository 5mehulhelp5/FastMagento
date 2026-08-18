/**
 * FastMagento storefront — autocomplete + instant search.
 *
 * THEME-AGNOSTIC BY DESIGN. This file has NO dependencies: no jQuery, no RequireJS, no Alpine,
 * no Knockout. It is a plain <script defer> that boots itself off JSON config embedded in the
 * page, so the identical build runs on:
 *
 *   - default Magento (Luma / Blank)  — RequireJS is present but unused
 *   - Swissup Breeze                  — its jQuery shim is present but unused
 *   - Hyvä                            — which ships NEITHER jQuery NOR RequireJS
 *
 * It previously bootstrapped through `<script type="text/x-magento-init">`, which only executes
 * if Magento's RequireJS `mage/apply/main` is on the page. On Hyvä that tag is inert, so the
 * markup rendered and nothing ever read it — autocomplete never appeared and the search results
 * grid stayed empty. Self-bootstrapping removes the dependency instead of adding a second
 * theme-specific implementation to keep in sync.
 */
(function () {
    'use strict';

    /* ── tiny DOM/util helpers (replacing the jQuery this used to need) ──────────────────── */

    function readConfig(id) {
        var el = document.getElementById(id);
        if (!el) {
            return null;
        }
        try {
            return JSON.parse(el.textContent || el.innerText || '{}');
        } catch (e) {
            return null;
        }
    }

    var decoder = null;

    /**
     * Catalogue data legitimately contains HTML entities — Magento's own sample data ships
     * "Lumaflex&trade;". Escaping that directly renders the literal text "&trade;", so decode
     * once first. Safe: decoding happens in a detached textarea (no parsing of tags, no script
     * execution), and the result is escaped immediately afterwards, so real markup in the data
     * still ends up inert.
     */
    function decodeEntities(str) {
        if (str.indexOf('&') === -1) {
            return str;
        }
        if (!decoder) {
            decoder = document.createElement('textarea');
        }
        decoder.innerHTML = str;
        return decoder.value;
    }

    function escapeHtml(str) {
        // Escapes for BOTH text and quoted-attribute contexts — values below are concatenated
        // into href="..."/src="..." so quotes must be encoded too.
        return decodeEntities(String(str === null || str === undefined ? '' : str))
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function param(name) {
        var m = new RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);
        return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
    }

    function buildQuery(obj, prefix) {
        var parts = [];
        Object.keys(obj).forEach(function (key) {
            var value = obj[key],
                k = prefix ? prefix + '[' + key + ']' : key;
            if (value === null || value === undefined) {
                return;
            }
            if (Array.isArray(value)) {
                value.forEach(function (v) {
                    parts.push(encodeURIComponent(k + '[]') + '=' + encodeURIComponent(v));
                });
            } else if (typeof value === 'object') {
                var nested = buildQuery(value, k);
                if (nested) {
                    parts.push(nested);
                }
            } else {
                parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(value));
            }
        });
        return parts.join('&');
    }

    /**
     * Locate the site search input across themes. Luma, Breeze and Hyvä all use id="search",
     * but Hyvä renders a second input inside its slide-out search panel and custom themes rename
     * things, so fall back to the form field name the Magento search route actually reads.
     */
    function findSearchInputs(selector) {
        var found = [];
        (selector ? [selector] : []).concat([
            '#search',
            'input[name="q"][type="search"]',
            'input[name="q"]'
        ]).forEach(function (sel) {
            Array.prototype.forEach.call(document.querySelectorAll(sel), function (el) {
                if (el.tagName === 'INPUT' && found.indexOf(el) === -1) {
                    found.push(el);
                }
            });
        });
        return found;
    }

    /* ── autocomplete ───────────────────────────────────────────────────────────────────── */

    function initAutocomplete(config, input) {
        var minChars = config.minChars || 2,
            delay = config.delay || 200,
            timer = null,
            controller = null,
            activeIndex = -1,
            panel = document.createElement('div');

        panel.className = 'fm-autocomplete';
        panel.setAttribute('role', 'listbox');
        panel.setAttribute('aria-label', 'Search suggestions');
        panel.hidden = true;

        input.setAttribute('autocomplete', 'off');

        // Anchor the panel to the closest positioned-able wrapper. Themes differ wildly here, so
        // walk a short list and fall back to the input's own parent.
        var host = input.closest('.control, .block-content, .minisearch, .field.search, form') || input.parentNode;
        if (getComputedStyle(host).position === 'static') {
            host.style.position = 'relative';
        }
        host.appendChild(panel);

        function hide() {
            panel.hidden = true;
            panel.innerHTML = '';
            activeIndex = -1;
        }

        function show() {
            panel.hidden = false;
        }

        function options() {
            return panel.querySelectorAll('[role="option"]');
        }

        function render(data) {
            var products = data.products || [],
                categories = data.categories || [],
                html = '';

            if (!products.length && !categories.length) {
                panel.innerHTML = '<div class="fm-ac-empty">No results for <strong>'
                    + escapeHtml(data.query) + '</strong></div>';
                show();
                return;
            }

            if (categories.length) {
                html += '<div class="fm-ac-section"><div class="fm-ac-heading">Categories</div>';
                categories.forEach(function (c) {
                    html += '<a class="fm-ac-cat" role="option" href="' + escapeHtml(c.url) + '">'
                        + '<span class="fm-ac-cat-name">' + escapeHtml(c.name) + '</span>'
                        + '<span class="fm-ac-cat-count">' + escapeHtml(c.count) + '</span></a>';
                });
                html += '</div>';
            }

            if (products.length) {
                html += '<div class="fm-ac-section"><div class="fm-ac-heading">Products</div>';
                products.forEach(function (p) {
                    var price = p.regular_price_formatted
                        ? '<span class="fm-ac-price-old">' + escapeHtml(p.regular_price_formatted)
                            + '</span> <span class="fm-ac-price fm-ac-price-special">'
                            + escapeHtml(p.price_formatted) + '</span>'
                        : '<span class="fm-ac-price">' + escapeHtml(p.price_formatted) + '</span>';
                    html += '<a class="fm-ac-product" role="option" href="' + escapeHtml(p.url) + '">'
                        + '<span class="fm-ac-thumb">'
                        + (p.image ? '<img src="' + escapeHtml(p.image) + '" alt="' + escapeHtml(p.name)
                            + '" loading="lazy"/>' : '')
                        + '</span><span class="fm-ac-info"><span class="fm-ac-name">'
                        + escapeHtml(p.name) + '</span>' + price
                        + (p.in_stock ? '' : '<span class="fm-ac-oos">Out of stock</span>')
                        + '</span></a>';
                });
                html += '</div>';
            }

            if (data.total > products.length) {
                html += '<a class="fm-ac-all" href="' + escapeHtml(config.resultsUrl) + '?q='
                    + encodeURIComponent(data.query) + '">View all ' + escapeHtml(data.total)
                    + ' results</a>';
            }

            panel.innerHTML = html;
            show();
            activeIndex = -1;
        }

        function fetchSuggestions(q) {
            if (controller) {
                controller.abort();
            }
            controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            var url = config.suggestUrl + (config.suggestUrl.indexOf('?') > -1 ? '&' : '?')
                + 'q=' + encodeURIComponent(q);

            fetch(url, {
                signal: controller ? controller.signal : undefined,
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin'
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    // Ignore a response that lost the race with newer typing.
                    if (data && input.value.trim() === q) {
                        render(data);
                    }
                })
                .catch(function () { /* aborted or offline — leave the panel as-is */ });
        }

        function move(dir) {
            var items = options();
            if (!items.length) {
                return;
            }
            if (activeIndex > -1 && items[activeIndex]) {
                items[activeIndex].classList.remove('fm-ac-active');
            }
            activeIndex = (activeIndex + dir + items.length) % items.length;
            items[activeIndex].classList.add('fm-ac-active');
            items[activeIndex].scrollIntoView({block: 'nearest'});
        }

        input.addEventListener('input', function () {
            var q = input.value.trim();
            clearTimeout(timer);
            if (q.length < minChars) {
                hide();
                return;
            }
            timer = setTimeout(function () { fetchSuggestions(q); }, delay);
        });

        input.addEventListener('keydown', function (e) {
            if (panel.hidden) {
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                move(1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                move(-1);
            } else if (e.key === 'Enter') {
                var items = options();
                if (activeIndex > -1 && items[activeIndex]) {
                    e.preventDefault();
                    window.location.href = items[activeIndex].getAttribute('href');
                }
            } else if (e.key === 'Escape') {
                hide();
            }
        });

        input.addEventListener('focus', function () {
            if (input.value.trim().length >= minChars && panel.children.length) {
                show();
            }
        });

        document.addEventListener('click', function (e) {
            if (e.target !== input && !panel.contains(e.target)) {
                hide();
            }
        });
    }

    /* ── instant search results page ────────────────────────────────────────────────────── */

    function initInstantSearch(config, root) {
        var pageSize = config.pageSize || 12,
            controller = null,
            timer = null,
            state = {
                q: config.initialQuery || param('q') || '',
                page: parseInt(param('p'), 10) || 1,
                filters: {}
            };

        function requestUrl() {
            var data = {q: state.q, p: state.page, page_size: pageSize, filter: {}};
            Object.keys(state.filters).forEach(function (code) {
                if (state.filters[code] && state.filters[code].length) {
                    data.filter[code] = state.filters[code];
                }
            });
            return config.instantUrl + (config.instantUrl.indexOf('?') > -1 ? '&' : '?') + buildQuery(data);
        }

        function syncUrl() {
            var params = ['q=' + encodeURIComponent(state.q)];
            if (state.page > 1) {
                params.push('p=' + state.page);
            }
            Object.keys(state.filters).forEach(function (code) {
                if (state.filters[code] && state.filters[code].length) {
                    params.push('filter[' + code + ']=' + encodeURIComponent(state.filters[code].join(',')));
                }
            });
            window.history.replaceState({}, '', window.location.pathname + '?' + params.join('&'));
        }

        function renderFacets(facets) {
            if (!facets || !facets.length) {
                return '';
            }
            var html = '<div class="fm-facets">';
            facets.forEach(function (facet) {
                html += '<div class="fm-facet"><div class="fm-facet-title">'
                    + escapeHtml(facet.label) + '</div><ul>';
                (facet.options || []).forEach(function (opt) {
                    var selected = (state.filters[facet.attribute] || []).indexOf(String(opt.value)) > -1;
                    html += '<li><label class="fm-facet-opt' + (selected ? ' selected' : '') + '">'
                        + '<input type="checkbox" data-facet="' + escapeHtml(facet.attribute)
                        + '" value="' + escapeHtml(opt.value) + '"' + (selected ? ' checked' : '') + '/> '
                        + '<span class="fm-facet-label">' + escapeHtml(opt.label) + '</span>'
                        + '<span class="fm-facet-count">' + escapeHtml(opt.count) + '</span></label></li>';
                });
                html += '</ul></div>';
            });
            return html + '</div>';
        }

        function renderProducts(products) {
            if (!products.length) {
                return '<div class="fm-no-results">No products found for <strong>'
                    + escapeHtml(state.q) + '</strong>.</div>';
            }
            var html = '<ol class="products list items product-items fm-grid">';
            products.forEach(function (p) {
                var price = p.regular_price_formatted
                    ? '<span class="old-price"><span class="price">'
                        + escapeHtml(p.regular_price_formatted) + '</span></span> '
                        + '<span class="special-price"><span class="price">'
                        + escapeHtml(p.price_formatted) + '</span></span>'
                    : '<span class="price">' + escapeHtml(p.price_formatted) + '</span>';
                html += '<li class="item product product-item"><div class="product-item-info">'
                    + '<a class="product-item-photo" href="' + escapeHtml(p.url) + '">'
                    + (p.image ? '<img class="product-image-photo" src="' + escapeHtml(p.image)
                        + '" alt="' + escapeHtml(p.name) + '" loading="lazy"/>' : '')
                    + '</a><div class="product-item-details">'
                    + '<strong class="product-item-name"><a class="product-item-link" href="'
                    + escapeHtml(p.url) + '">' + escapeHtml(p.name) + '</a></strong>'
                    + '<div class="price-box">' + price + '</div>'
                    + (p.in_stock ? '' : '<div class="stock unavailable">Out of stock</div>')
                    + '</div></div></li>';
            });
            return html + '</ol>';
        }

        function renderPagination(data) {
            if (data.pages <= 1) {
                return '';
            }
            var html = '<div class="fm-pagination">',
                start = Math.max(1, data.page - 2),
                end = Math.min(data.pages, start + 4),
                i;
            if (data.page > 1) {
                html += '<button type="button" class="fm-page" data-page="' + (data.page - 1) + '">‹ Prev</button>';
            }
            for (i = start; i <= end; i++) {
                html += '<button type="button" class="fm-page' + (i === data.page ? ' current' : '')
                    + '" data-page="' + i + '">' + i + '</button>';
            }
            if (data.page < data.pages) {
                html += '<button type="button" class="fm-page" data-page="' + (data.page + 1) + '">Next ›</button>';
            }
            return html + '</div>';
        }

        function render(data) {
            root.innerHTML = '<div class="fm-results-header"><span class="fm-count">'
                + escapeHtml(data.total) + ' result' + (data.total === 1 ? '' : 's')
                + (data.query ? ' for “' + escapeHtml(data.query) + '”' : '')
                + '</span></div><div class="fm-results-body"><aside class="fm-sidebar">'
                + renderFacets(data.facets) + '</aside><div class="fm-results-main">'
                + renderProducts(data.products || []) + renderPagination(data) + '</div></div>';
        }

        function load() {
            if (controller) {
                controller.abort();
            }
            controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            root.classList.add('fm-loading');
            fetch(requestUrl(), {
                signal: controller ? controller.signal : undefined,
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin'
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (data) {
                        render(data);
                        syncUrl();
                    }
                })
                .catch(function () { /* aborted or offline */ })
                .then(function () { root.classList.remove('fm-loading'); });
        }

        // Delegated so the handlers survive every re-render.
        root.addEventListener('change', function (e) {
            var el = e.target;
            if (!el.matches || !el.matches('input[type="checkbox"][data-facet]')) {
                return;
            }
            var code = el.getAttribute('data-facet'),
                value = String(el.value),
                list = state.filters[code] || [],
                idx = list.indexOf(value);
            if (idx > -1) {
                list.splice(idx, 1);
            } else {
                list.push(value);
            }
            state.filters[code] = list;
            state.page = 1;
            load();
        });

        root.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.fm-page') : null;
            if (!btn) {
                return;
            }
            state.page = parseInt(btn.getAttribute('data-page'), 10);
            load();
            root.scrollIntoView({behavior: 'smooth', block: 'start'});
        });

        // As-you-type from whichever header search box the theme rendered.
        findSearchInputs(config.searchInputSelector).forEach(function (input) {
            input.addEventListener('input', function () {
                var q = input.value.trim();
                clearTimeout(timer);
                timer = setTimeout(function () {
                    if (q !== state.q) {
                        state.q = q;
                        state.page = 1;
                        state.filters = {};
                        load();
                    }
                }, 300);
            });
            if (input.form) {
                input.form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    state.q = input.value.trim();
                    state.page = 1;
                    load();
                });
            }
        });

        load();
    }

    /* ── boot ───────────────────────────────────────────────────────────────────────────── */

    function boot() {
        var instantConfig = readConfig('fm-instant-config'),
            instantRoot = document.getElementById('fm-instant-results');

        // On the results page the header box drives the live grid, so the dropdown would fight
        // it — same rule the old layout expressed by removing the autocomplete block there.
        if (instantConfig && instantRoot) {
            initInstantSearch(instantConfig, instantRoot);
            return;
        }

        var acConfig = readConfig('fm-autocomplete-config');
        if (acConfig && acConfig.suggestUrl) {
            findSearchInputs(acConfig.searchInputSelector).forEach(function (input) {
                if (!input.dataset.fmAutocomplete) {
                    input.dataset.fmAutocomplete = '1';
                    initAutocomplete(acConfig, input);
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
