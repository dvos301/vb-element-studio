(function ($) {
    'use strict';

    var $paramsContainer, $paramsJsonInput, $legacyForm;
    var $importForm, $importCandidatesInput, importCandidates = [];
    var PARAM_TYPES = [
        { value: 'textfield', label: 'Text Field' },
        { value: 'textarea', label: 'Text Area' },
        { value: 'colorpicker', label: 'Color Picker' },
        { value: 'attach_image', label: 'Image' },
        { value: 'dropdown', label: 'Dropdown' },
        { value: 'checkbox', label: 'Checkbox' },
        { value: 'param_group', label: 'Repeater / Param Group' }
    ];
    var SUB_PARAM_TYPES = PARAM_TYPES.filter(function (type) {
        return type.value !== 'param_group';
    });

    function init() {
        $importForm = $('#vb-es-import-form');
        if ($importForm.length) {
            initImportMode();
            return;
        }

        $paramsContainer = $('#vb-es-params-container');
        $paramsJsonInput = $('#vb-es-params-json');
        $legacyForm = $('#vb-es-element-form');

        if (!$legacyForm.length) {
            return;
        }

        loadExistingParams();
        bindLegacyEvents();
    }

    function initImportMode() {
        $importCandidatesInput = $('#vb-es-import-candidates-json');
        bindImportEvents();
    }

    function bindImportEvents() {
        $('#vb-es-analyze-snippet-btn').on('click', handleImportAnalysis);

        $importForm.on('change input', '.vb-es-import-field', function () {
            var $card = $(this).closest('.vb-es-import-card');
            updateImportCandidateFromCard($card);
        });

        $importForm.on('change', '.vb-es-import-include', function () {
            var $card = $(this).closest('.vb-es-import-card');
            updateImportCandidateFromCard($card);
        });

        $importForm.on('submit', function (event) {
            if (!syncImportCandidatesJson()) {
                event.preventDefault();
            }
        });
    }

    function handleImportAnalysis() {
        var $btn = $('#vb-es-analyze-snippet-btn');
        var $spinner = $('#vb-es-import-spinner');
        var $status = $('#vb-es-import-status');
        var snippet = $('#vb-es-combined-snippet').val();

        if (!snippet.trim()) {
            showStatus($status, 'error', 'Paste a combined HTML/CSS snippet before analyzing.');
            return;
        }

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $status.empty();

        $.ajax({
            url: vbEsAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'vb_es_ingest_snippet',
                nonce: vbEsAdmin.nonce,
                snippet: snippet
            },
            success: function (response) {
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);

                if (!response.success) {
                    showStatus($status, 'error', response.data.message || 'Snippet analysis failed.');
                    return;
                }

                importCandidates = Array.isArray(response.data.candidates) ? response.data.candidates : [];
                renderImportReview(response.data);

                if (!importCandidates.length) {
                    showStatus($status, 'error', 'No candidate sections were detected.');
                    return;
                }

                showStatus($status, 'success', 'Snippet analyzed successfully. Review the detected elements below, then create the selected ones.');
                $('html, body').animate({
                    scrollTop: $('#vb-es-import-review').offset().top - 40
                }, 400);
            },
            error: function () {
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);
                showStatus($status, 'error', 'Request failed. Please check your connection and try again.');
            }
        });
    }

    function renderImportReview(result) {
        var $review = $('#vb-es-import-review');
        var $placement = $('#vb-es-import-placement');
        var $container = $('#vb-es-import-candidates');
        var $globalWarnings = $('#vb-es-import-global-warnings');

        $container.empty();
        $globalWarnings.empty();

        if (Array.isArray(result.warnings) && result.warnings.length) {
            $globalWarnings.append(buildWarningNotice('Import warnings', result.warnings));
        }

        importCandidates.forEach(function (candidate, index) {
            candidate._include = candidate._include !== false;
            $container.append(buildImportCandidateCard(candidate, index));
        });

        $review.show();
        $placement.show();
        syncImportCandidatesJson();
    }

    function buildImportCandidateCard(candidate, index) {
        var warningHtml = '';
        if (Array.isArray(candidate.warnings) && candidate.warnings.length) {
            warningHtml = buildWarningNotice('Candidate warnings', candidate.warnings);
        }

        return [
            '<div class="vb-es-import-card" data-index="', index, '">',
                '<div class="vb-es-import-card__header">',
                    '<label class="vb-es-import-card__toggle"><input type="checkbox" class="vb-es-import-field vb-es-import-include" ', candidate._include === false ? '' : 'checked', ' /> Include this element</label>',
                    '<strong class="vb-es-import-card__title">', escHtml(candidate.name || ('Imported Section ' + (index + 1))), '</strong>',
                '</div>',
                warningHtml,
                '<div class="vb-es-import-card__grid">',
                    '<div class="vb-es-field">',
                        '<label>Name</label>',
                        '<input type="text" class="vb-es-import-field" data-key="name" value="', escAttr(candidate.name || ''), '" />',
                    '</div>',
                    '<div class="vb-es-field">',
                        '<label>Slug</label>',
                        '<input type="text" class="vb-es-import-field" data-key="slug" value="', escAttr(candidate.slug || ''), '" placeholder="Auto-generate from name" />',
                    '</div>',
                    '<div class="vb-es-field">',
                        '<label>Category</label>',
                        '<input type="text" class="vb-es-import-field" data-key="category" value="', escAttr(candidate.category || $importForm.data('default-category') || ''), '" />',
                    '</div>',
                    '<div class="vb-es-field vb-es-field-wide">',
                        '<label>Description</label>',
                        '<input type="text" class="vb-es-import-field" data-key="description" value="', escAttr(candidate.description || ''), '" />',
                    '</div>',
                '</div>',
                '<details class="vb-es-import-card__details">',
                    '<summary>Advanced</summary>',
                    '<div class="vb-es-import-card__advanced">',
                        '<div class="vb-es-field vb-es-field-wide">',
                            '<label>Raw HTML</label>',
                            '<textarea class="vb-es-import-field code" data-key="raw_html" rows="8">', escHtml(candidate.raw_html || ''), '</textarea>',
                        '</div>',
                        '<div class="vb-es-field vb-es-field-wide">',
                            '<label>Raw CSS</label>',
                            '<textarea class="vb-es-import-field code" data-key="raw_css" rows="8">', escHtml(candidate.raw_css || ''), '</textarea>',
                        '</div>',
                        '<div class="vb-es-field vb-es-field-wide">',
                            '<label>HTML Template</label>',
                            '<textarea class="vb-es-import-field code" data-key="html_template" rows="8">', escHtml(candidate.html_template || ''), '</textarea>',
                        '</div>',
                        '<div class="vb-es-field vb-es-field-wide">',
                            '<label>Params JSON</label>',
                            '<textarea class="vb-es-import-field vb-es-import-params-json code" data-key="params_json" rows="10">', escHtml(JSON.stringify(candidate.params || [], null, 2)), '</textarea>',
                            '<p class="description">Edit the generated params schema directly if needed.</p>',
                        '</div>',
                    '</div>',
                '</details>',
            '</div>'
        ].join('');
    }

    function updateImportCandidateFromCard($card) {
        var index = parseInt($card.data('index'), 10);
        if (isNaN(index) || !importCandidates[index]) {
            return;
        }

        var candidate = importCandidates[index];
        candidate._include = $card.find('.vb-es-import-include').is(':checked');
        candidate.name = valueOrEmpty($card.find('[data-key="name"]').val());
        candidate.slug = valueOrEmpty($card.find('[data-key="slug"]').val());
        candidate.category = valueOrEmpty($card.find('[data-key="category"]').val());
        candidate.description = valueOrEmpty($card.find('[data-key="description"]').val());
        candidate.raw_html = valueOrEmpty($card.find('[data-key="raw_html"]').val());
        candidate.raw_css = valueOrEmpty($card.find('[data-key="raw_css"]').val());
        candidate.html_template = valueOrEmpty($card.find('[data-key="html_template"]').val());

        var paramsText = valueOrEmpty($card.find('[data-key="params_json"]').val());
        var parsedParams = parseJsonArray(paramsText);
        candidate._paramsValid = parsedParams.valid;
        candidate.params = parsedParams.value;

        $card.find('.vb-es-import-params-json').toggleClass('vb-es-is-invalid', !parsedParams.valid);
        syncImportCandidatesJson();
    }

    function syncImportCandidatesJson() {
        if (!$importCandidatesInput || !$importCandidatesInput.length) {
            return true;
        }

        var selectedCandidates = [];
        var hasInvalidParams = false;

        importCandidates.forEach(function (candidate) {
            if (candidate._include === false) {
                return;
            }

            if (candidate._paramsValid === false) {
                hasInvalidParams = true;
                return;
            }

            selectedCandidates.push({
                name: candidate.name || 'Imported Element',
                slug: candidate.slug || '',
                description: candidate.description || '',
                category: candidate.category || ($importForm.data('default-category') || ''),
                raw_html: candidate.raw_html || '',
                raw_css: candidate.raw_css || '',
                html_template: candidate.html_template || candidate.raw_html || '',
                params: Array.isArray(candidate.params) ? candidate.params : []
            });
        });

        $importCandidatesInput.val(JSON.stringify(selectedCandidates));
        updateImportSubmitState(selectedCandidates.length > 0 && !hasInvalidParams);

        if (hasInvalidParams) {
            showStatus($('#vb-es-import-status'), 'error', 'One or more candidate Params JSON fields are invalid. Fix them before creating elements.');
            return false;
        }

        if (!selectedCandidates.length) {
            updateImportSubmitState(false);
        }

        return selectedCandidates.length > 0;
    }

    function updateImportSubmitState(isEnabled) {
        $('#vb-es-import-submit').prop('disabled', !isEnabled);
    }

    function buildWarningNotice(title, warnings) {
        var items = (warnings || []).map(function (warning) {
            return '<li>' + escHtml(warning) + '</li>';
        }).join('');

        return '<div class="notice notice-warning inline"><p><strong>' + escHtml(title) + ':</strong></p><ul style="margin: 0.5em 0 0 1.25em; list-style: disc;">' + items + '</ul></div>';
    }

    function parseJsonArray(value) {
        var trimmed = $.trim(value);
        if (!trimmed) {
            return { valid: true, value: [] };
        }

        try {
            var parsed = JSON.parse(trimmed);
            return {
                valid: Array.isArray(parsed),
                value: Array.isArray(parsed) ? parsed : []
            };
        } catch (e) {
            return { valid: false, value: [] };
        }
    }

    function loadExistingParams() {
        var json = $paramsJsonInput.val();
        if (!json || json === '[]') {
            return;
        }

        try {
            var params = JSON.parse(json);
            if (!Array.isArray(params)) {
                return;
            }

            params.forEach(function (param) {
                addParamRow(param);
            });
        } catch (e) {
            // Silently ignore malformed JSON on load.
        }
    }

    function bindLegacyEvents() {
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
                updateParamRowState($row);
            }

            syncParamsJson();
        });

        $paramsContainer.on('click', '.vb-es-add-sub-param', function () {
            var $row = $(this).closest('.vb-es-param-row');
            addSubParamRow($row, {});
        });

        $paramsContainer.on('click', '.vb-es-remove-sub-param', function () {
            $(this).closest('.vb-es-sub-param-row').remove();
            syncParamsJson();
        });

        $paramsContainer.on('change input', '.vb-es-sub-param-field', function () {
            var $row = $(this).closest('.vb-es-sub-param-row');

            if ($(this).data('key') === 'type') {
                updateSubParamRowState($row);
            }

            syncParamsJson();
        });

        $paramsContainer.on('input', '.vb-es-param-group-default', function () {
            parseGroupDefault($(this));
            syncParamsJson();
        });

        $legacyForm.on('submit', function () {
            syncParamsJson();
        });

        $('#vb-es-detect-btn').on('click', handleDetection);
    }

    function addParamRow(data) {
        var normalized = normalizeParamData(data);
        var $row = $(buildParamRowMarkup(normalized));

        $paramsContainer.append($row);
        renderSubParams($row, normalized.params);
        updateParamRowState($row);
        parseGroupDefault($row.find('.vb-es-param-group-default'));
        syncParamsJson();
    }

    function addSubParamRow($parentRow, data) {
        var normalized = normalizeSubParamData(data);
        var $row = $(buildSubParamRowMarkup(normalized));

        $parentRow.find('.vb-es-sub-params-container').append($row);
        updateSubParamRowState($row);
        syncParamsJson();
    }

    function renderSubParams($parentRow, params) {
        var $container = $parentRow.find('.vb-es-sub-params-container');
        $container.empty();

        (params || []).forEach(function (param) {
            addSubParamRow($parentRow, param);
        });
    }

    function updateParamRowState($row) {
        var type = $row.find('.vb-es-param-type-select').val();
        var isDropdown = type === 'dropdown';
        var isGroup = type === 'param_group';

        $row.find('.vb-es-options-field').toggle(isDropdown);
        $row.find('.vb-es-default-field').toggle(!isGroup);
        $row.find('.vb-es-param-group-config').toggle(isGroup);
    }

    function updateSubParamRowState($row) {
        var type = $row.find('.vb-es-sub-param-type-select').val();
        $row.find('.vb-es-sub-options-field').toggle(type === 'dropdown');
    }

    function syncParamsJson() {
        var params = [];

        $paramsContainer.find('.vb-es-param-row').each(function () {
            var $row = $(this);
            var param = {
                param_name: valueOrEmpty($row.find('[data-key="param_name"]').val()),
                heading: valueOrEmpty($row.find('[data-key="heading"]').val()),
                type: valueOrEmpty($row.find('[data-key="type"]').val()) || 'textfield',
                description: valueOrEmpty($row.find('[data-key="description"]').val())
            };

            if (!param.param_name) {
                return;
            }

            if (param.type === 'param_group') {
                param.default = parseGroupDefault($row.find('.vb-es-param-group-default'));
                param.params = collectSubParams($row);
            } else {
                param.default = valueOrEmpty($row.find('[data-key="default"]').val());
                if (param.type === 'dropdown') {
                    param.options = valueOrEmpty($row.find('[data-key="options"]').val());
                }
            }

            params.push(param);
        });

        $paramsJsonInput.val(JSON.stringify(params));
    }

    function collectSubParams($parentRow) {
        var params = [];

        $parentRow.find('.vb-es-sub-param-row').each(function () {
            var $row = $(this);
            var param = {
                param_name: valueOrEmpty($row.find('[data-key="param_name"]').val()),
                heading: valueOrEmpty($row.find('[data-key="heading"]').val()),
                type: valueOrEmpty($row.find('[data-key="type"]').val()) || 'textfield',
                description: valueOrEmpty($row.find('[data-key="description"]').val()),
                default: valueOrEmpty($row.find('[data-key="default"]').val())
            };

            if (!param.param_name) {
                return;
            }

            if (param.type === 'dropdown') {
                param.options = valueOrEmpty($row.find('[data-key="options"]').val());
            }

            params.push(param);
        });

        return params;
    }

    function parseGroupDefault($textarea) {
        if (!$textarea.length) {
            return [];
        }

        var raw = $.trim($textarea.val());
        if (!raw) {
            setGroupDefaultValidity($textarea, true);
            return [];
        }

        try {
            var parsed = JSON.parse(raw);
            var isValid = Array.isArray(parsed);
            setGroupDefaultValidity($textarea, isValid);
            return isValid ? parsed : [];
        } catch (e) {
            setGroupDefaultValidity($textarea, false);
            return [];
        }
    }

    function setGroupDefaultValidity($textarea, isValid) {
        $textarea.toggleClass('vb-es-is-invalid', !isValid);
        $textarea.attr('title', isValid ? '' : 'Repeater defaults must be valid JSON array syntax.');
    }

    function normalizeParamData(data) {
        data = data || {};

        return {
            param_name: data.param_name || '',
            heading: data.heading || '',
            type: data.type || 'textfield',
            default: data.type === 'param_group' ? formatGroupDefault(data.default) : valueOrEmpty(data.default),
            description: data.description || '',
            options: valueOrEmpty(data.options),
            params: Array.isArray(data.params) ? data.params : []
        };
    }

    function normalizeSubParamData(data) {
        data = data || {};

        return {
            param_name: data.param_name || '',
            heading: data.heading || '',
            type: data.type && data.type !== 'param_group' ? data.type : 'textfield',
            default: valueOrEmpty(data.default),
            description: data.description || '',
            options: valueOrEmpty(data.options)
        };
    }

    function formatGroupDefault(value) {
        if (Array.isArray(value)) {
            return JSON.stringify(value, null, 2);
        }

        if (typeof value === 'string' && $.trim(value)) {
            try {
                var parsed = JSON.parse(value);
                if (Array.isArray(parsed)) {
                    return JSON.stringify(parsed, null, 2);
                }
            } catch (e) {
                return value;
            }
        }

        return '[]';
    }

    function buildParamRowMarkup(data) {
        return [
            '<div class="vb-es-param-row">',
                '<div class="vb-es-param-row-header">',
                    '<span class="vb-es-param-row-title">Parameter: <strong class="vb-es-param-label-preview">', escHtml(data.heading || 'Untitled'), '</strong></span>',
                    '<button type="button" class="button button-link-delete vb-es-remove-param">Remove</button>',
                '</div>',
                '<div class="vb-es-param-row-fields">',
                    '<div class="vb-es-field">',
                        '<label>Param Name (slug)</label>',
                        '<input type="text" class="vb-es-param-field" data-key="param_name" value="', escAttr(data.param_name), '" placeholder="heading_text" />',
                    '</div>',
                    '<div class="vb-es-field">',
                        '<label>Label</label>',
                        '<input type="text" class="vb-es-param-field" data-key="heading" value="', escAttr(data.heading), '" placeholder="Heading Text" />',
                    '</div>',
                    '<div class="vb-es-field">',
                        '<label>Type</label>',
                        '<select class="vb-es-param-field vb-es-param-type-select" data-key="type">', buildTypeOptions(data.type, true), '</select>',
                    '</div>',
                    '<div class="vb-es-field vb-es-default-field">',
                        '<label>Default Value</label>',
                        '<input type="text" class="vb-es-param-field" data-key="default" value="', escAttr(data.default), '" />',
                    '</div>',
                    '<div class="vb-es-field vb-es-field-wide">',
                        '<label>Description</label>',
                        '<input type="text" class="vb-es-param-field" data-key="description" value="', escAttr(data.description), '" />',
                    '</div>',
                    '<div class="vb-es-field vb-es-field-wide vb-es-options-field" style="display:none;">',
                        '<label>Options (comma-separated)</label>',
                        '<input type="text" class="vb-es-param-field" data-key="options" value="', escAttr(data.options), '" placeholder="option1,option2,option3" />',
                    '</div>',
                    '<div class="vb-es-field vb-es-field-wide vb-es-param-group-config" style="display:none;">',
                        '<div class="vb-es-param-group-box">',
                            '<p class="description">Use a repeater when your template includes <code>{{#items}}...{{/items}}</code> style blocks.</p>',
                            '<label>Default Items (JSON Array)</label>',
                            '<textarea class="vb-es-param-group-default code" rows="6">', escHtml(data.default), '</textarea>',
                            '<div class="vb-es-sub-params">',
                                '<div class="vb-es-sub-params-header">',
                                    '<strong>Repeater Fields</strong>',
                                    '<button type="button" class="button button-secondary vb-es-add-sub-param">+ Add Repeater Field</button>',
                                '</div>',
                                '<div class="vb-es-sub-params-container"></div>',
                            '</div>',
                        '</div>',
                    '</div>',
                '</div>',
            '</div>'
        ].join('');
    }

    function buildSubParamRowMarkup(data) {
        return [
            '<div class="vb-es-sub-param-row">',
                '<div class="vb-es-sub-param-grid">',
                    '<div class="vb-es-field">',
                        '<label>Field Name</label>',
                        '<input type="text" class="vb-es-sub-param-field" data-key="param_name" value="', escAttr(data.param_name), '" placeholder="title" />',
                    '</div>',
                    '<div class="vb-es-field">',
                        '<label>Label</label>',
                        '<input type="text" class="vb-es-sub-param-field" data-key="heading" value="', escAttr(data.heading), '" placeholder="Title" />',
                    '</div>',
                    '<div class="vb-es-field">',
                        '<label>Type</label>',
                        '<select class="vb-es-sub-param-field vb-es-sub-param-type-select" data-key="type">', buildTypeOptions(data.type, false), '</select>',
                    '</div>',
                    '<div class="vb-es-field">',
                        '<label>Default</label>',
                        '<input type="text" class="vb-es-sub-param-field" data-key="default" value="', escAttr(data.default), '" />',
                    '</div>',
                    '<div class="vb-es-field vb-es-field-wide">',
                        '<label>Description</label>',
                        '<input type="text" class="vb-es-sub-param-field" data-key="description" value="', escAttr(data.description), '" />',
                    '</div>',
                    '<div class="vb-es-field vb-es-field-wide vb-es-sub-options-field" style="display:none;">',
                        '<label>Options (comma-separated)</label>',
                        '<input type="text" class="vb-es-sub-param-field" data-key="options" value="', escAttr(data.options), '" placeholder="option1,option2" />',
                    '</div>',
                '</div>',
                '<p><button type="button" class="button button-link-delete vb-es-remove-sub-param">Remove Repeater Field</button></p>',
            '</div>'
        ].join('');
    }

    function buildTypeOptions(selectedValue, includeGroup) {
        var types = includeGroup ? PARAM_TYPES : SUB_PARAM_TYPES;

        return types.map(function (type) {
            var selected = type.value === selectedValue ? ' selected' : '';
            return '<option value="' + escAttr(type.value) + '"' + selected + '>' + escHtml(type.label) + '</option>';
        }).join('');
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
            error: function () {
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

    function valueOrEmpty(value) {
        return value == null ? '' : String(value);
    }

    function escAttr(str) {
        if (!str) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function escHtml(str) {
        if (!str) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    $(document).ready(init);
})(jQuery);
