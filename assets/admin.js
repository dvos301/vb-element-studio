(function ($) {
    'use strict';

    var $paramsContainer, $paramsJsonInput, $form;

    function init() {
        $paramsContainer = $('#vb-es-params-container');
        $paramsJsonInput = $('#vb-es-params-json');
        $form = $('#vb-es-element-form');

        if (!$form.length) {
            return;
        }

        loadExistingParams();
        bindEvents();
    }

    function loadExistingParams() {
        var json = $paramsJsonInput.val();
        if (!json || json === '[]') {
            return;
        }

        try {
            var params = JSON.parse(json);
            if (!Array.isArray(params)) return;
            params.forEach(function (param) {
                addParamRow(param);
            });
        } catch (e) {
            // Silently ignore malformed JSON on load
        }
    }

    function bindEvents() {
        $('#vb-es-add-param').on('click', function () {
            addParamRow({});
        });

        $paramsContainer.on('click', '.vb-es-remove-param', function () {
            $(this).closest('.vb-es-param-row').slideUp(200, function () {
                $(this).remove();
                syncParamsJson();
            });
        });

        $paramsContainer.on('change input', '.vb-es-param-field', function () {
            var $row = $(this).closest('.vb-es-param-row');
            var key = $(this).data('key');

            if (key === 'heading') {
                $row.find('.vb-es-param-label-preview').text($(this).val() || 'Untitled');
            }

            if (key === 'type') {
                var isDropdown = $(this).val() === 'dropdown';
                $row.find('.vb-es-options-field').toggle(isDropdown);
            }

            syncParamsJson();
        });

        $form.on('submit', function () {
            syncParamsJson();
        });

        $('#vb-es-detect-btn').on('click', handleDetection);
    }

    function addParamRow(data) {
        var index = $paramsContainer.children().length;
        var templateHtml = $('#tmpl-vb-es-param-row').html();

        var rendered = templateHtml
            .replace(/\{\{data\.index\}\}/g, index)
            .replace(/\{\{data\.param_name\}\}/g, escAttr(data.param_name || ''))
            .replace(/\{\{data\.heading\}\}/g, escAttr(data.heading || ''))
            .replace(/\{\{data\.default\}\}/g, escAttr(data['default'] || ''))
            .replace(/\{\{data\.description\}\}/g, escAttr(data.description || ''))
            .replace(/\{\{data\.options\}\}/g, escAttr(data.options || ''))
            .replace(/\{\{data\.options_display\}\}/g, data.type === 'dropdown' ? '' : 'display:none')
            .replace(/\{\{data\.type_textfield\}\}/g, data.type === 'textfield' || !data.type ? 'selected' : '')
            .replace(/\{\{data\.type_textarea\}\}/g, data.type === 'textarea' ? 'selected' : '')
            .replace(/\{\{data\.type_colorpicker\}\}/g, data.type === 'colorpicker' ? 'selected' : '')
            .replace(/\{\{data\.type_attach_image\}\}/g, data.type === 'attach_image' ? 'selected' : '')
            .replace(/\{\{data\.type_dropdown\}\}/g, data.type === 'dropdown' ? 'selected' : '')
            .replace(/\{\{data\.type_checkbox\}\}/g, data.type === 'checkbox' ? 'selected' : '');

        $paramsContainer.append(rendered);
        syncParamsJson();
    }

    function syncParamsJson() {
        var params = [];
        $paramsContainer.find('.vb-es-param-row').each(function () {
            var $row = $(this);
            var param = {};

            $row.find('.vb-es-param-field').each(function () {
                var key = $(this).data('key');
                param[key] = $(this).val();
            });

            if (param.param_name) {
                params.push(param);
            }
        });

        $paramsJsonInput.val(JSON.stringify(params));
    }

    function handleDetection() {
        var $btn = $('#vb-es-detect-btn');
        var $spinner = $('#vb-es-detect-spinner');
        var $status = $('#vb-es-detect-status');
        var html = $('#element_raw_html').val();
        var css = $('#element_raw_css').val();

        if (!html.trim()) {
            showStatus($status, 'error', 'Please paste HTML content before running detection.');
            return;
        }

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $status.empty();

        $.ajax({
            url: vbEsAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'vb_es_detect_params',
                nonce: vbEsAdmin.nonce,
                html: html,
                css: css
            },
            success: function (response) {
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);

                if (!response.success) {
                    showStatus($status, 'error', response.data.message || 'Detection failed.');
                    return;
                }

                var result = response.data;

                if (result.tokenised_html) {
                    $('#element_html_template').val(result.tokenised_html);
                }

                if (result.params && Array.isArray(result.params)) {
                    $paramsContainer.empty();
                    result.params.forEach(function (param) {
                        addParamRow(param);
                    });
                }

                showStatus($status, 'success', 'Parameters detected successfully. Review and adjust below, then save.');

                $('html, body').animate({
                    scrollTop: $('#element_html_template').offset().top - 50
                }, 400);
            },
            error: function (xhr) {
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);
                showStatus($status, 'error', 'Request failed. Please check your connection and try again.');
            }
        });
    }

    function showStatus($el, type, message) {
        var cssClass = type === 'error' ? 'notice-error' : 'notice-success';
        $el.html('<div class="notice ' + cssClass + ' inline"><p>' + escHtml(message) + '</p></div>');
    }

    function escAttr(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    $(document).ready(init);

})(jQuery);
