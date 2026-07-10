define(['jquery'], function ($) {
    'use strict';

    return function (config) {
        var $button = $('#' + config.buttonId),
            $result = $('#' + config.resultId);

        $button.on('click', function (event) {
            event.preventDefault();

            $result.css('color', '').text($.mage.__('Sending...'));
            $button.prop('disabled', true);

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                timeout: 30000,
                data: {
                    form_key: window.FORM_KEY
                }
            }).done(function (response) {
                if (response.success) {
                    $result.css('color', 'green')
                        .text($.mage.__('Success (HTTP %1)').replace('%1', response.status));
                } else {
                    $result.css('color', 'red')
                        .text($.mage.__('Failed: %1').replace('%1', response.message));
                }
            }).fail(function (jqXHR, textStatus) {
                if (textStatus === 'timeout') {
                    $result.css('color', 'red').text($.mage.__('Request timed out after 30 seconds.'));
                } else {
                    $result.css('color', 'red').text($.mage.__('Request failed.'));
                }
            }).always(function () {
                $button.prop('disabled', false);
            });
        });
    };
});
