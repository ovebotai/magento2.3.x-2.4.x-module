/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

// "Add to cart" from the chat and the cart sync. Loaded by the widget block only while the "Add to cart" switch
// is on; widget.js names window.ovebotaiAddToCart in the chat options. Plain JavaScript: the add goes through the
// add-to-cart of the theme when it is there (Luma: the catalogAddToCart widget, so its events, the mini-cart and
// the messages run as for a click), and straight to checkout/cart/add otherwise. After every change of the cart
// the cart is asked from the shop and sent to the chat as ['cart', {count, items}] when it differs.
(function (window, document) {
    'use strict';

    var configNode = document.getElementById('ovebot-chat-config'),
        config = null,
        cart = null,
        addWait = 20000,
        syncDelay = 300,
        syncTimer = null,
        syncing = false,
        syncAgain = false;

    /**
     * @param {String} text
     * @return {Object|null}
     */
    function parseJson(text) {
        try {
            return JSON.parse(text);
        } catch (e) {
            return null;
        }
    }

    /**
     * The queue of the scripts of Ovebot.ai.
     *
     * @return {Array}
     */
    function queue() {
        if (Object.prototype.toString.call(window.ovebot_ai) !== '[object Array]') {
            window.ovebot_ai = [];
        }

        return window.ovebot_ai;
    }

    /**
     * The form key of the visitor: the cookie Magento sets on every storefront page, or a form of the page.
     *
     * @return {String}
     */
    function formKey() {
        var match = /(?:^|;\s*)form_key=([^;]+)/.exec(document.cookie || ''),
            input;

        if (match) {
            return decodeURIComponent(match[1]);
        }
        input = document.querySelector('input[name="form_key"]');

        return input ? input.value : '';
    }

    /**
     * Same-origin POST, as Magento expects from a script (X-Requested-With), with a JSON answer.
     *
     * @param {String} url
     * @param {String} body form-encoded
     * @param {Function} done called with the JSON, or null on a failure
     */
    function post(url, body, done) {
        var xhr = new XMLHttpRequest();

        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                done(xhr.status >= 200 && xhr.status < 300 ? parseJson(xhr.responseText) : null);
            }
        };
        xhr.send(body || '');
    }

    // ── Cart sync ────────────────────────────────────────────────────────────

    /**
     * Ask the shop for the cart and send it to the chat when it differs from what the chat knows.
     */
    function syncCart() {
        if (syncing) {
            syncAgain = true;

            return;
        }
        syncing = true;

        post(cart.url, '', function (json) {
            var next;

            syncing = false;
            if (json && typeof json.count === 'number' && json.items) {
                next = JSON.stringify({ count: json.count, items: json.items });
                if (next !== window.ovebotChatCartState) {
                    window.ovebotChatCartState = next;
                    queue().push(['cart', { count: json.count, items: json.items }]);
                }
            }
            if (syncAgain) {
                syncAgain = false;
                scheduleSync();
            }
        });
    }

    /**
     * Several cart requests usually fire together (add, mini-cart refresh): one sync after the last of them.
     */
    function scheduleSync() {
        window.clearTimeout(syncTimer);
        syncTimer = window.setTimeout(syncCart, syncDelay);
    }

    /**
     * Listen to the changes of the cart: the cart section of Magento (Luma), the requests that touch the cart
     * (jQuery), the events of Hyvä. Any of them ends in a sync, which sends the cart only when it changed.
     */
    function watchCart() {
        var $ = window.jQuery;

        if (typeof window.require === 'function') {
            try {
                window.require(['Magento_Customer/js/customer-data'], function (customerData) {
                    customerData.get('cart').subscribe(scheduleSync);
                }, function () {});
            } catch (e) {
                // no customer-data on this theme: the other listeners remain
            }
        }
        if ($ && typeof $.fn === 'object') {
            $(document).ajaxComplete(function (event, xhr, settings) {
                var url = settings && settings.url ? String(settings.url) : '';

                if (url && url !== cart.url && /\/(checkout\/(cart|sidebar)\/|customer\/section\/load)/.test(url)) {
                    scheduleSync();
                }
            });
        }
        window.addEventListener('private-content-loaded', scheduleSync);
        window.addEventListener('reload-customer-section-data', scheduleSync);
    }

    // ── Add to cart ──────────────────────────────────────────────────────────

    /**
     * Whether the page already shows the cart: the cart page and the checkout (by route and by address).
     *
     * @return {Boolean}
     */
    function onCheckoutPage() {
        return /checkout/i.test(window.location.href)
            || /(^|\s)checkout-/.test(document.body ? document.body.className : '');
    }

    /**
     * @param {Object|Boolean} result
     */
    function reloadIfCheckout(result) {
        if (result === true && onCheckoutPage()) {
            window.location.reload();
        }
    }

    /**
     * @param {String} url
     * @return {Boolean} whether the URL is the cart page
     */
    function isCartUrl(url) {
        return /\/checkout\/cart\/?(?:[?#]|$)/.test(url);
    }

    /**
     * What the chat expects back from the answer of checkout/cart/add: true, false, or {redirect: url}.
     *
     * A successful add answers {} or, when the shop goes to the cart after an add, the URL of the cart. A product
     * that needs options, or cannot be added, answers the URL of its page (or of the page the visitor is on).
     *
     * @param {Object|null} json
     * @return {Object|Boolean}
     */
    function outcome(json) {
        if (!json || typeof json !== 'object') {
            return false;
        }
        if (typeof json.backUrl === 'string' && json.backUrl !== '') {
            return isCartUrl(json.backUrl) ? true : { redirect: json.backUrl };
        }
        if (json.product && json.product.statusText) {
            return false;
        }

        return true;
    }

    /**
     * The fields of the add-to-cart request. The reference of a variant is "{parent id}-{child id}"; the
     * options the variant is chosen by are in the fragment of its URL, as the product page reads them.
     *
     * @param {Object} product {ref, sku, quantity, name, url, price, currency}
     * @return {Object} name => value
     */
    function fields(product) {
        var ref = String(product.ref || ''),
            parts = ref.split('-'),
            fragment = typeof product.url === 'string' ? product.url.split('#')[1] || '' : '',
            pairs = fragment ? fragment.split('&') : [],
            data = {},
            pair,
            i;

        data.product = parts[0];
        data.qty = parseInt(product.quantity, 10) || 1;
        data.form_key = formKey();
        if (parts.length === 2 && /^\d+$/.test(parts[0]) && /^\d+$/.test(parts[1])) {
            for (i = 0; i < pairs.length; i++) {
                pair = pairs[i].split('=');
                if (pair.length === 2 && /^\d+$/.test(pair[0]) && /^\d+$/.test(pair[1])) {
                    data['super_attribute[' + pair[0] + ']'] = pair[1];
                }
            }
            if (!fragment) {
                // no options known: the simple product itself
                data.product = parts[1];
            }
        }

        return data;
    }

    /**
     * @param {Object} data name => value
     * @return {String} form-encoded
     */
    function encode(data) {
        var parts = [],
            name;

        for (name in data) {
            if (Object.prototype.hasOwnProperty.call(data, name)) {
                parts.push(encodeURIComponent(name) + '=' + encodeURIComponent(data[name]));
            }
        }

        return parts.join('&');
    }

    /**
     * The add-to-cart of Luma: a form given to the catalogAddToCart widget, which posts it and triggers the
     * events ajax:addToCart / ajax:addToCart:error that the mini-cart, the messages and tracking listen to.
     * Its own redirect (to the cart, or to the page of a product that needs options) is read, not followed:
     * the chat decides what to do with it.
     *
     * @param {Object} data fields of the request
     * @param {String} sku
     * @param {Function} done called with true, false or {redirect}
     * @return {Boolean} false when the theme has no such add-to-cart
     */
    function nativeAdd(data, sku, done) {
        if (typeof window.require !== 'function' || typeof window.jQuery === 'undefined') {
            return false;
        }

        window.require(['jquery', 'catalogAddToCart'], function ($) {
            var $form = $('<form method="post"></form>').attr('action', cart.addUrl).attr('data-product-sku', sku),
                settled = false,
                timer = null,
                widget,
                name;

            function finish(value) {
                if (settled) {
                    return;
                }
                settled = true;
                window.clearTimeout(timer);
                $(document).off('ajax:addToCart', onAdded).off('ajax:addToCart:error', onFailed);
                $form.remove();
                done(value);
            }

            function onAdded(event, info) {
                if (info && info.form && info.form[0] === $form[0]) {
                    finish(outcome(info.response));
                }
            }

            function onFailed(event, info) {
                if (info && info.form && info.form[0] === $form[0]) {
                    finish(false);
                }
            }

            for (name in data) {
                if (Object.prototype.hasOwnProperty.call(data, name)) {
                    $('<input type="hidden">').attr('name', name).val(String(data[name])).appendTo($form);
                }
            }
            $form.hide().appendTo(document.body);
            $(document).on('ajax:addToCart', onAdded).on('ajax:addToCart:error', onFailed);
            timer = window.setTimeout(function () {
                finish(false);
            }, addWait);

            try {
                $form.catalogAddToCart({ bindSubmit: false });
                widget = $form.data('mageCatalogAddToCart');
                if (widget) {
                    widget._redirect = function () {};
                }
                $form.catalogAddToCart('submitForm', $form);
            } catch (e) {
                finish(false);
            }
        }, function () {
            // the widget of Luma is not on this theme: straight to the endpoint
            directAdd(data, done);
        });

        return true;
    }

    /**
     * The add-to-cart endpoint of Magento itself, for themes without the widget of Luma (Hyvä). The theme is
     * told to read its cart again the way Hyvä does.
     *
     * @param {Object} data fields of the request
     * @param {Function} done called with true, false or {redirect}
     */
    function directAdd(data, done) {
        post(cart.addUrl, encode(data), function (json) {
            var value = outcome(json);

            if (value === true) {
                try {
                    window.dispatchEvent(new CustomEvent('reload-customer-section-data'));
                } catch (e) {
                    // an old browser without CustomEvent: the sync below still runs
                }
            }
            scheduleSync();
            done(value);
        });
    }

    /**
     * Add a product of the chat to the cart.
     *
     * product = {ref, sku, quantity, name, url, price, currency}; ref is the "ref" of the product feed. The
     * promise gives true, false or {redirect: url} (a product that needs options is chosen on its page).
     *
     * @param {Object} product
     * @return {Promise}
     */
    window.ovebotaiAddToCart = function (product) {
        return new Promise(function (resolve) {
            var data = fields(product || {}),
                sku = product && product.sku ? String(product.sku) : '';

            function done(value) {
                resolve(value);
                reloadIfCheckout(value);
            }

            if (!cart || !/^\d+$/.test(String(data.product))) {
                resolve(false);

                return;
            }
            if (!nativeAdd(data, sku, done)) {
                directAdd(data, done);
            }
        });
    };

    if (!configNode || window.ovebotChatCartLoaded) {
        return;
    }
    config = parseJson(configNode.getAttribute('data-config'));
    if (!config || !config.cart || typeof config.cart !== 'object'
        || typeof config.cart.url !== 'string' || typeof config.cart.addUrl !== 'string'
    ) {
        return;
    }
    window.ovebotChatCartLoaded = true;
    cart = config.cart;

    watchCart();
}(window, document));
