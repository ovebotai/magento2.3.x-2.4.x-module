/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

// Behaviour shared by every view of the module page (setup, dashboard, settings).
define(['jquery'], function ($) {
    'use strict';

    var bound = false;

    /**
     * Send a POST request to a URL, with the form key of the admin session.
     *
     * @param {String} url
     */
    function post(url) {
        var $form = $('<form>', {
            method: 'post',
            action: url,
            'class': 'ovebotai-hidden'
        });

        $('<input>', {
            type: 'hidden',
            name: 'form_key',
            value: window.FORM_KEY
        }).appendTo($form);

        $form.appendTo(document.body);
        $form[0].submit();
    }

    return function () {
        if (bound) {
            return;
        }
        bound = true;

        // data-ovebotai-confirm="message" asks before going on, so a misclick cannot cut the connection.
        // data-ovebotai-post sends the link as a POST request: actions that change something are POST only.
        $(document).on('click', '[data-ovebotai-confirm], [data-ovebotai-post]', function (event) {
            var $element = $(this),
                message = $element.data('ovebotai-confirm');

            if (message && !window.confirm(message)) { // eslint-disable-line no-alert
                event.preventDefault();
                event.stopImmediatePropagation();

                return;
            }

            if ($element.data('ovebotai-post')) {
                event.preventDefault();
                post($element.attr('href'));
            }
        });
    };
});
