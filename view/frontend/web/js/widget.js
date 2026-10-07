/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

// Storefront chat widget. Plain JavaScript, without RequireJS or jQuery, so it runs on any theme (Luma, Hyvä).
// It fills the queue that the scripts of Ovebot.ai read, window.ovebot_ai, then loads them:
//   ['chat', {options}]      read by chat-loader.js
//   ['purchase', {order}]    read by event.js, only on the order success page
// With the "Add to cart" switch on, the chat options also name the add-to-cart function (cart.js) and carry the
// cart of the visitor; the page is the same for every visitor (full page cache), so the cart is asked for here.
(function () {
    'use strict';

    var configNode = document.getElementById('ovebot-chat-config'),
        config,
        booted = false,
        // a preview link of the admin ends with #ovebot_preview={token}
        previewPattern = /(?:^#|&)ovebot_preview=([a-f0-9]{32})(?:&|$)/,
        previewWait = 4000,
        // the chat must not wait long for the cart: it can be sent later, by cart.js
        cartWait = 1500;

    /**
     * Read the JSON of a data attribute.
     *
     * @param {HTMLElement} node
     * @param {String} name
     * @return {*} null when the value is not JSON
     */
    function readJson(node, name) {
        try {
            return JSON.parse(node.getAttribute(name));
        } catch (e) {
            return null;
        }
    }

    /**
     * Whether the value is an array.
     *
     * @param {*} value
     * @return {Boolean}
     */
    function isArray(value) {
        return Object.prototype.toString.call(value) === '[object Array]';
    }

    /**
     * Add a script of Ovebot.ai to the page.
     *
     * @param {String} src
     * @param {Boolean} async
     */
    function load(src, async) {
        var script = document.createElement('script');

        script.src = src;
        script.async = async;
        (document.body || document.head).appendChild(script);
    }

    /**
     * Purchases to report, written by the order success page.
     *
     * @return {Array}
     */
    function purchases() {
        var node = document.getElementById('ovebot-chat-purchase'),
            list = node ? readJson(node, 'data-purchases') : null;

        return isArray(list) ? list : [];
    }

    /**
     * Whether the page offers the cart to the chat (the "Add to cart" switch is on).
     *
     * @return {Boolean}
     */
    function hasCart() {
        return !!config.cart && typeof config.cart === 'object' && typeof config.cart.url === 'string';
    }

    /**
     * Ask the shop for the cart of the visitor: {count, items}. Answers null when it cannot be had in time.
     *
     * @param {Function} done
     */
    function fetchCart(done) {
        var settled = false,
            timer;

        function finish(cart) {
            if (settled) {
                return;
            }
            settled = true;
            window.clearTimeout(timer);
            done(cart);
        }

        if (!hasCart() || typeof window.fetch !== 'function') {
            done(null);

            return;
        }

        timer = window.setTimeout(function () {
            finish(null);
        }, cartWait);

        window.fetch(config.cart.url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (data) {
            finish(data && typeof data.count === 'number' && isArray(data.items) ? data : null);
        })['catch'](function () {
            finish(null);
        });
    }

    /**
     * Fill the queue and load the scripts of Ovebot.ai.
     *
     * @param {Boolean} autoOpen open the chat at once (preview from the admin)
     * @param {Object|null} cart the cart of the visitor, when it was had in time
     */
    function start(autoOpen, cart) {
        var chat = config.chat && typeof config.chat === 'object' ? config.chat : {},
            list = purchases(),
            i;

        if (autoOpen) {
            chat.auto_open = 'true';
        }
        if (hasCart()) {
            // chat-loader.js reads the first 'chat' entry only: the cart keys go with the appearance
            chat.add_to_cart = config.cart.add;
            chat.cart_url = config.cart.cartUrl;
            chat.checkout_url = config.cart.checkoutUrl;
            if (cart) {
                chat.cart_count = cart.count;
                chat.cart_items = cart.items;
                // what the chat knows: cart.js sends the cart again only when it differs from this
                window.ovebotChatCartState = JSON.stringify({ count: cart.count, items: cart.items });
            }
        }
        if (!isArray(window.ovebot_ai)) {
            window.ovebot_ai = [];
        }
        window.ovebot_ai.push(['chat', chat]);
        for (i = 0; i < list.length; i++) {
            window.ovebot_ai.push(['purchase', list[i]]);
        }

        load(config.base + 'chat-loader.js', true);
        if (list.length) {
            load(config.base + 'event.js', false);
        }
    }

    /**
     * Boot the chat, with the cart when the page offers it; runs once.
     *
     * @param {Boolean} autoOpen open the chat at once (preview from the admin)
     */
    function boot(autoOpen) {
        if (booted) {
            return;
        }
        booted = true;

        fetchCart(function (cart) {
            start(autoOpen, cart);
        });
    }

    /**
     * Token of an admin preview, from the address of the page.
     *
     * @return {String} empty when the page was not opened as a preview
     */
    function previewToken() {
        var match = previewPattern.exec(window.location.hash || '');

        return match ? match[1] : '';
    }

    /**
     * Ask the shop whether the preview token is valid. The page itself may come from the full page cache, so
     * the check is a request of its own, never cached.
     *
     * @param {String} token
     */
    function checkPreview(token) {
        var url = config.previewUrl + (config.previewUrl.indexOf('?') === -1 ? '?' : '&') + 'token=' + token,
            // the chat must not wait for a slow answer
            timer = window.setTimeout(function () {
                boot(false);
            }, previewWait);

        window.fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json'
            }
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (data) {
            window.clearTimeout(timer);
            boot(!!data && data.valid === true);
        })['catch'](function () {
            window.clearTimeout(timer);
            boot(false);
        });
    }

    if (!configNode || window.ovebotChatWidgetLoaded) {
        return;
    }
    config = readJson(configNode, 'data-config');
    if (!config || typeof config.base !== 'string' || config.base.indexOf('https://') !== 0) {
        return;
    }
    window.ovebotChatWidgetLoaded = true;

    if (previewToken() !== '' && typeof config.previewUrl === 'string' && typeof window.fetch === 'function') {
        checkPreview(previewToken());
    } else {
        boot(false);
    }
}());
