/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
// Settings page. The configuration comes from the template, through text/x-magento-init; it is read
// defensively, so a missing key cannot throw.
define(['jquery'], function ($) {
    'use strict';

    var HIDDEN = 'ovebotai-hidden',
        NOTICE_CLASSES = 'ovebotai-notice-success ovebotai-notice-error ovebotai-notice-warning';

    return function (config, element) {
        var cfg = config || {},
            i18n = cfg.i18n || {},
            ajaxUrls = cfg.ajaxUrls || {},
            $root = $(element),
            $form = $root.find('#oveSettingsForm'),
            $saveBtn = $root.find('#oveSaveBtn'),
            saveBtnText = $saveBtn.text(),
            initialSerialized = $form.serialize(),
            timers = {},
            switches = {
                'chat_status': ['#oveChatStatus', '#oveChatStatusLbl'],
                'products_builtin': ['#oveProductsBuiltin', '#oveProductsBuiltinLbl'],
                'products_recommend': ['#oveProductsRecommend', '#oveProductsRecommendLbl'],
                'add_to_cart': ['#oveAddToCart', '#oveAddToCartLbl'],
                'order_enabled': ['#oveOrderEnabled', '#oveOrderEnabledLbl']
            };

        /**
         * @param {String} selector
         * @return {jQuery}
         */
        function find(selector) {
            return $root.find(selector);
        }

        /**
         * Show or hide with a class, so elements keep the display set by the stylesheet.
         *
         * @param {jQuery} $elements
         * @param {Boolean} visible
         * @return {jQuery}
         */
        function toggle($elements, visible) {
            return $elements.toggleClass(HIDDEN, !visible);
        }

        /**
         * The controllers answer {error: "..."} for access failures and {success: false, message: "..."} for the
         * rest; both carry a text worth showing.
         *
         * @param {Object} resp
         * @return {String}
         */
        function responseError(resp) {
            if (!resp) {
                return '';
            }

            if (resp.error) {
                return String(resp.error);
            }

            if (!resp.success && resp.message) {
                return String(resp.message);
            }

            return '';
        }

        /**
         * An answer that is not 2xx can still carry a JSON body; its text is better than the generic error.
         *
         * @param {Object} jqXHR
         * @return {String}
         */
        function xhrError(jqXHR) {
            var json = jqXHR && jqXHR.responseJSON;

            if (!json && jqXHR && jqXHR.responseText) {
                try {
                    json = JSON.parse(jqXHR.responseText);
                } catch (err) {
                    json = null;
                }
            }

            return responseError(json) || i18n.error;
        }

        /**
         * The answer may carry true/false, 1/0 or "1"/"0".
         *
         * @param {*} value
         * @return {Boolean}
         */
        function toBool(value) {
            return value === true || value === 1 || value === '1' || value === 'true';
        }

        /**
         * @param {String} text
         * @return {String}
         */
        function escapeHtml(text) {
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        /**
         * @param {String} action
         * @param {Object|String} data
         * @return {jQuery.Deferred}
         */
        function post(action, data) {
            return $.ajax({
                url: ajaxUrls[action],
                type: 'POST',
                dataType: 'json',
                showLoader: false,
                data: data
            });
        }

        /**
         * @param {jQuery} $target
         * @param {Number} offset
         */
        function scrollTo($target, offset) {
            $('html, body').animate({
                scrollTop: Math.max(0, $target.offset().top - offset)
            }, 300);
        }

        // ── Notices ───────────────────────────────────────────────────────────

        /**
         * @param {Boolean} ok
         * @param {String} message
         * @param {String} [type] 'warning' for a save that went through with a caveat
         */
        function showNotice(ok, message, type) {
            var $notice = find('#oveSettingsNotice'),
                cssClass = 'ovebotai-notice-error';

            if (type === 'warning') {
                cssClass = 'ovebotai-notice-warning';
            } else if (ok) {
                cssClass = 'ovebotai-notice-success';
            }

            $notice.removeClass(NOTICE_CLASSES).addClass(cssClass).html('<p>' + escapeHtml(message) + '</p>');
            toggle($notice, true);
            scrollTo($notice, 120);

            clearTimeout(timers.notice);
            timers.notice = setTimeout(function () {
                toggle($notice, false);
            }, type === 'warning' ? 7000 : 4000);
        }

        /**
         * @param {Array|null} warnings
         */
        function showWarnings(warnings) {
            var $warnings = find('#oveSettingsWarnings');

            clearTimeout(timers.warnings);

            if (!warnings || !warnings.length) {
                toggle($warnings, false);

                return;
            }

            $warnings.html('<p>' + warnings.map(escapeHtml).join('<br>') + '</p>');
            toggle($warnings, true);
            timers.warnings = setTimeout(function () {
                toggle($warnings, false);
            }, 9000);
        }

        // ── Unsaved changes ───────────────────────────────────────────────────

        /**
         * No change tracking to keep in sync: the form is compared with its saved state when the question is
         * asked, so an edit that was undone does not count as a change.
         *
         * @return {Boolean}
         */
        function isDirty() {
            return $form.serialize() !== initialSerialized;
        }

        /**
         * Pulse the Save button while there are unsaved changes.
         */
        function updateSaveAttention() {
            $saveBtn.toggleClass('ovebotai-attn', isDirty());
        }

        // ── Switches ──────────────────────────────────────────────────────────

        /**
         * @param {String} key
         */
        function reflectSwitch(key) {
            var enabled = find(switches[key][0]).is(':checked');

            find(switches[key][1]).text(enabled ? i18n.enabled : i18n.disabled);

            if (key === 'products_builtin') {
                toggle(find('#oveFeedUrlField'), enabled);
            }
        }

        /**
         * After a save the controller reports the values the three mirrored switches ended up with: the
         * Ovebot.ai account can refuse a change. The switches follow that answer BEFORE the saved state is
         * taken, otherwise the form would count as changed by an edit the merchant never made.
         *
         * @param {Object} effective
         */
        function applyEffective(effective) {
            if (!effective || typeof effective !== 'object') {
                return;
            }

            Object.keys(switches).forEach(function (key) {
                if (!Object.prototype.hasOwnProperty.call(effective, key)) {
                    return;
                }

                find(switches[key][0]).prop('checked', toBool(effective[key]));
                reflectSwitch(key);
            });
        }

        // ── Save ──────────────────────────────────────────────────────────────

        /**
         * Give the Save button back.
         */
        function restoreSaveBtn() {
            updateSaveAttention();
            $saveBtn.prop('disabled', false).text(saveBtnText);
        }

        /**
         * @param {Object} resp
         */
        function onSaved(resp) {
            var ok = !!(resp && resp.success),
                warnings = ok ? resp.warnings : null,
                hasWarnings = !!(warnings && warnings.length),
                // a reconnect caveat has no list of its own: it takes over the main notice
                needsReconnect = ok && !!resp['needs_reconnect'] && !hasWarnings,
                message = ok ? resp.message || i18n.saved : responseError(resp) || i18n.error;

            showNotice(ok && !needsReconnect, message, needsReconnect ? 'warning' : null);
            showWarnings(hasWarnings ? warnings : null);

            if (ok) {
                applyEffective(resp.effective);
                // the saved state is the new reference for "unsaved changes"
                initialSerialized = $form.serialize();
            }

            // only a clean save goes back to the dashboard; a caveat must stay readable
            if (ok && !hasWarnings && !resp['needs_reconnect'] && cfg.dashboardUrl) {
                setTimeout(function () {
                    window.location.href = cfg.dashboardUrl;
                }, 1200);

                return;
            }

            restoreSaveBtn();
        }

        $form.on('submit', function (event) {
            event.preventDefault();

            $saveBtn.prop('disabled', true).removeClass('ovebotai-attn').text(i18n.saving);

            // the form carries its own form key
            post('SaveSettings', $form.serialize()).done(onSaved).fail(function (jqXHR) {
                showNotice(false, xhrError(jqXHR));
                showWarnings(null);
                restoreSaveBtn();
            });
        });

        $(window).on('beforeunload.ovebotaiSettings', function (event) {
            var native;

            if (!isDirty()) {
                return undefined;
            }

            native = event.originalEvent || event;

            if (native.preventDefault) {
                native.preventDefault();
            }
            native.returnValue = '';

            return '';
        });

        $form.on('input change', 'input, select', updateSaveAttention);

        Object.keys(switches).forEach(function (key) {
            find(switches[key][0]).on('change', function () {
                reflectSwitch(key);
            });
        });

        // ── Appearance ────────────────────────────────────────────────────────

        find('#oveAppearanceToggle').on('click', function () {
            var $panel = find('#oveAppearancePanel'),
                open = $panel.hasClass(HIDDEN);

            toggle($panel, open);
            toggle(find('#oveAppearanceIconMore'), !open);
            toggle(find('#oveAppearanceIconLess'), open);
            $(this).attr('aria-expanded', open ? 'true' : 'false');
        });

        find('#ove_color_picker').on('input', function () {
            find('#ove_accent_color').val(String($(this).val()).toUpperCase());
            updateSaveAttention();
        });

        find('#ove_accent_color').on('input', function () {
            var value = $(this).val();

            if (/^#[0-9a-f]{6}$/i.test(value)) {
                find('#ove_color_picker').val(value.toLowerCase());
            }
        });

        // ── Copy buttons ──────────────────────────────────────────────────────

        $root.on('click', '.ovebotai-copy-btn', function () {
            var $btn = $(this),
                input = document.getElementById($btn.data('target')),
                label = $btn.data('ovebotaiLabel') || $.trim($btn.text()),
                value;

            if (!input) {
                return;
            }
            value = String($(input).val());

            /**
             * Select the read-only input and copy the selection.
             */
            function fallbackCopy() {
                try {
                    input.focus();
                    input.select();

                    if (input.setSelectionRange) {
                        input.setSelectionRange(0, value.length);
                    }
                    document.execCommand('copy');
                } catch (err) {
                    // the text is selected, so it can still be copied by hand
                }
            }

            // navigator.clipboard exists only in secure contexts (https, localhost)
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(null, fallbackCopy);
            } else {
                fallbackCopy();
            }

            // kept on the button, so a second click during "Copied!" does not take that text as the label
            $btn.data('ovebotaiLabel', label).text(i18n.copied);
            clearTimeout($btn.data('ovebotaiTimer'));
            $btn.data('ovebotaiTimer', setTimeout(function () {
                $btn.text(label);
            }, 1800));
        });

        // ── Regenerations ─────────────────────────────────────────────────────

        /**
         * @param {jQuery} $btn
         * @param {String} action
         * @param {String} question
         * @param {Function} apply receives the answer of a successful call
         */
        function regenerate($btn, action, question, apply) {
            if (!window.confirm(question)) { // eslint-disable-line no-alert
                return;
            }

            $btn.prop('disabled', true);

            post(action, {
                'form_key': cfg.formKey
            }).done(function (resp) {
                if (resp && resp.success) {
                    apply(resp);
                    showNotice(true, resp.message || i18n.saved);
                } else {
                    showNotice(false, responseError(resp) || i18n.error);
                }
            }).fail(function (jqXHR) {
                showNotice(false, xhrError(jqXHR));
            }).always(function () {
                $btn.prop('disabled', false);
            });
        }

        find('.ovebotai-regen-hash-btn').on('click', function () {
            regenerate($(this), 'RegenFeedHash', i18n.confirmRegenHash, function (resp) {
                find('#oveFeedUrl').val(resp.url);
            });
        });

        find('.ovebotai-regen-creds-btn').on('click', function () {
            regenerate($(this), 'RegenOrderCreds', i18n.confirmRegenCreds, function (resp) {
                find('#oveApiUser').val(resp.user);
                find('#oveApiPass').val(resp.pass);
            });
        });

        // ── Field a link pointed at ───────────────────────────────────────────

        /**
         * Bring the field into view and pulse it a few times, so it is obvious what to look at.
         *
         * @param {String} fieldId
         */
        function highlight(fieldId) {
            var $target = fieldId ? find('#' + fieldId) : $(),
                $highlight,
                rect;

            if (!$target.length) {
                return;
            }

            // the control itself rather than its whole row: the pulse points at the thing to click
            $highlight = $target.closest('.ovebotai-switch');

            if (!$highlight.length) {
                $highlight = $target.closest('.ovebotai-field');
            }

            if (!$highlight.length) {
                $highlight = $target;
            }

            if ($highlight.closest('#oveAppearancePanel').length && find('#oveAppearancePanel').hasClass(HIDDEN)) {
                find('#oveAppearanceToggle').trigger('click');
            }

            $highlight.addClass('ovebotai-highlight-pulse');

            rect = $highlight[0].getBoundingClientRect();

            if (rect.top < 0 || rect.bottom > (window.innerHeight || document.documentElement.clientHeight)) {
                scrollTo($highlight, 160);
            }

            setTimeout(function () {
                $highlight.removeClass('ovebotai-highlight-pulse');
            }, 4500);
        }

        // the id was checked on the server: letters, digits, dash and underscore
        highlight(/^[A-Za-z][A-Za-z0-9_-]*$/.test(String(cfg.highlight || '')) ? String(cfg.highlight) : '');
    };
});
