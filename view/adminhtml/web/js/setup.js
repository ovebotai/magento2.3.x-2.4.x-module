/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

// Setup wizard: Connect -> Website pages -> Products -> Go live. The configuration comes from the template,
// through text/x-magento-init; it is read defensively, so a missing key cannot throw.
define(['jquery'], function ($) {
    'use strict';

    var HIDDEN = 'ovebotai-hidden';

    return function (config, element) {
        var cfg = config || {},
            i18n = cfg.i18n || {},
            ajaxUrls = cfg.ajaxUrls || {},
            $root = $(element),
            // [1,2,3,4] - Connect, Website pages, Products, Go live
            stepsSeq = Array.isArray(cfg.stepsSequence) && cfg.stepsSequence.length ?
                cfg.stepsSequence.map(function (n) {
                    return parseInt(n, 10);
                }) : [1, 2, 3, 4],
            current = parseInt(cfg.initialStep, 10) || stepsSeq[0],
            isConnected = cfg.isConnected === true || parseInt(cfg.isConnected, 10) === 1,
            navigating = false,
            // the step whose "Next" starts the sync: second to last; the last one is always the finish panel
            finishStep = stepsSeq[stepsSeq.length - 2];

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
         * @param {Number} step
         * @return {Number}
         */
        function posOf(step) {
            var index = stepsSeq.indexOf(step);

            return index === -1 ? 0 : index;
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
         * @param {String} action
         * @param {Object} data
         * @return {jQuery.Deferred}
         */
        function post(action, data) {
            return $.ajax({
                url: ajaxUrls[action],
                type: 'POST',
                dataType: 'json',
                showLoader: false,
                data: $.extend({
                    'form_key': cfg.formKey
                }, data || {})
            });
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

        // ── Product count message ─────────────────────────────────────────────

        /**
         * Dim the product count while a custom feed is selected: the count is about OUR feed.
         */
        function reflectProductMode() {
            var external = find('#oveProductsExternal').is(':checked');

            find('#oveProductMsg, #oveProductVariantsNote').toggleClass('ovebotai-msg-dimmed', external);
        }

        /**
         * Show how many products the built-in feed sends.
         */
        function updateProductMessage() {
            var counts = cfg.productCounts;

            if (!counts) {
                return;
            }

            if (parseInt(counts.total, 10) === 0) {
                find('#oveProductMsg').text(i18n.noProducts);
                // nothing to count, so the "per variation" explanation is noise
                toggle(find('#oveProductVariantsNote'), false);

                return;
            }

            toggle(find('#oveProductVariantsNote'), true);
            find('#oveProductMsg').html(
                '<span class="ovebotai-count-badge">' + escapeHtml(counts['feed_count']) + '</span> ' +
                escapeHtml(i18n.productsWillBeIndexed)
            );
        }

        // ── Step navigation ───────────────────────────────────────────────────

        /**
         * @param {Number} step
         */
        function renderStep(step) {
            var pos = posOf(step),
                isLast = pos === stepsSeq.length - 1,
                percent = stepsSeq.length > 1 ? pos / (stepsSeq.length - 1) * 100 : 100;

            current = step;

            toggle(find('.ovebotai-panel'), false);
            toggle(find('.ovebotai-panel[data-panel="' + step + '"]'), true);

            find('.ovebotai-step-dot').each(function () {
                var number = parseInt($(this).data('step'), 10);

                $(this).toggleClass('is-active', number === step);
                $(this).toggleClass('is-done', posOf(number) < pos);
            });

            find('#oveProgressBar').css('width', percent + '%');

            toggle(find('#oveSetupNav'), !isLast);
            toggle(find('#ovePrevBtn'), pos > 0);
            // step 1: no Next until connected
            toggle(find('#oveNextBtn'), step !== 1 || isConnected);

            if (step === finishStep) {
                find('#oveNextBtn').text(i18n.finish + ' →');

                if (step === 3) {
                    updateProductMessage();
                    reflectProductMode();
                }
            } else {
                find('#oveNextBtn').text(i18n.next + ' →');
            }
        }

        // ── Step 2: page sync ─────────────────────────────────────────────────

        /**
         * @param {Object} failedMap page id => message
         */
        function applyPageFailures(failedMap) {
            Object.keys(failedMap).forEach(function (pageId) {
                var $row = find('.ovebotai-page-row[data-page-id="' + parseInt(pageId, 10) + '"]');

                $row.find('input[name="kb_pages[]"]').prop('checked', false);
                toggle($row.find('.ovebotai-page-error').removeClass('is-success').text(failedMap[pageId]), true);
            });
        }

        /**
         * Pages stopped by the plan limit have no message of their own: the banner shows the text of the API,
         * and each of them gets a fixed text.
         *
         * @param {Array} ids
         * @return {Object}
         */
        function indexKbLimitIds(ids) {
            var map = {};

            ids.forEach(function (id) {
                map[id] = i18n.kbLimitPageSkipped;
            });

            return map;
        }

        /**
         * Mark in green the pages that went through while others failed.
         *
         * @param {Array} pageIds
         */
        function applyPageSuccess(pageIds) {
            pageIds.forEach(function (pageId) {
                var $row = find('.ovebotai-page-row[data-page-id="' + parseInt(pageId, 10) + '"]');

                toggle($row.find('.ovebotai-page-error').addClass('is-success').text(i18n.pageUpdated), true);
            });
        }

        /**
         * Runs on every "Next" of step 2. What the server reports as not sent gets unchecked and marked; the
         * wizard stays on step 2, and the next click, with those boxes unchecked, goes on.
         */
        function syncPages() {
            var pageIds = [];

            navigating = true;

            toggle(find('.ovebotai-page-error').text('').removeClass('is-success'), false);
            toggle(find('#oveKbLimitNotice').text(''), false);

            find('input[name="kb_pages[]"]:checked').each(function () {
                pageIds.push(String($(this).val()));
            });

            find('#oveNextBtn').prop('disabled', true).text(i18n.syncingPages);
            find('#ovePrevBtn').prop('disabled', true);

            post('SyncPages', {
                'page_ids': pageIds
            }).done(function (resp) {
                var failedIds, succeededIds;

                navigating = false;
                find('#oveNextBtn, #ovePrevBtn').prop('disabled', false);

                if (!resp || !resp.success) {
                    renderStep(2);
                    toggle(find('#oveKbLimitNotice').text(responseError(resp) || i18n.error), true);

                    return;
                }

                applyPageFailures(resp.failed || {});
                applyPageFailures(indexKbLimitIds(resp['kb_limit_ids'] || []));

                if (resp['kb_limit']) {
                    toggle(find('#oveKbLimitNotice').text(resp['kb_limit']), true);
                }

                if (resp.clean) {
                    renderStep(3);

                    return;
                }

                failedIds = Object.keys(resp.failed || {}).concat((resp['kb_limit_ids'] || []).map(String));
                succeededIds = pageIds.filter(function (id) {
                    return failedIds.indexOf(id) === -1;
                });
                applyPageSuccess(succeededIds);
                renderStep(2);
            }).fail(function (jqXHR) {
                navigating = false;
                find('#oveNextBtn, #ovePrevBtn').prop('disabled', false);
                // renderStep(2) also restores the "Next" label replaced by the progress text
                renderStep(2);
                toggle(find('#oveKbLimitNotice').text(xhrError(jqXHR)), true);
            });
        }

        // ── Finish (go live) ──────────────────────────────────────────────────

        /**
         * @param {String} message
         */
        function showSyncError(message) {
            find('#oveSyncErrorMsg').html('<p>' + escapeHtml(message).replace(/\n/g, '<br>') + '</p>');
            toggle(find('#oveSyncError'), true);
            toggle(find('#oveNextBtn').text(i18n.retry), true);
            toggle(find('#ovePrevBtn'), true);
            toggle(find('#oveSetupNav'), true);
        }

        /**
         * Send the setup to Ovebot.ai.
         */
        function doSync() {
            toggle(find('#oveSetupNav, #oveSyncIdle, #oveSyncError'), false);
            toggle(find('#oveSyncLoading'), true);

            post('Finish', {
                // 1 = our built-in feed is the product source, 0 = the merchant supplies a feed in the account
                'products_builtin': find('#oveProductsIntegrated').is(':checked') ? 1 : 0
            }).done(function (resp) {
                toggle(find('#oveSyncLoading'), false);

                if (resp && resp.success) {
                    toggle(find('#oveSyncDone'), true);
                    // every dot, the last one included, turns green
                    find('.ovebotai-step-dot').removeClass('is-active').addClass('is-done');
                } else {
                    showSyncError(responseError(resp) || i18n.error);
                }
            }).fail(function (jqXHR) {
                toggle(find('#oveSyncLoading'), false);
                showSyncError(xhrError(jqXHR));
            });
        }

        /**
         * Keep a double click from skipping a step.
         */
        function holdNavigation() {
            navigating = true;
            setTimeout(function () {
                navigating = false;
            }, 500);
        }

        /**
         * "Next", "Finish setup" or "Retry".
         */
        function handleNext() {
            if (navigating) {
                return;
            }

            if (current === 2) {
                syncPages();

                return;
            }

            holdNavigation();

            if (current === 4) {
                // Next is visible on step 4 only after a failed sync: retry
                doSync();
            } else if (current === finishStep) {
                renderStep(4);
                doSync();
            } else {
                renderStep(stepsSeq[posOf(current) + 1]);
            }
        }

        /**
         * "Previous".
         */
        function handlePrev() {
            if (navigating) {
                return;
            }

            holdNavigation();
            renderStep(stepsSeq[Math.max(0, posOf(current) - 1)]);
        }

        renderStep(current);

        find('#oveNextBtn').on('click', handleNext);
        find('#ovePrevBtn').on('click', handlePrev);
        find('input[name="products_mode"]').on('change', reflectProductMode);
    };
});
