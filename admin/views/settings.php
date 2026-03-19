<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$provider_labels     = VB_ES_AI_Detector::get_provider_labels();
$supported_models    = VB_ES_AI_Detector::get_supported_models();
$selected_provider   = VB_ES_AI_Detector::get_selected_provider();
$selected_model      = sanitize_text_field( (string) get_option( 'vb_es_ai_model', VB_ES_AI_Detector::get_default_model( $selected_provider ) ) );
$custom_model        = get_option( 'vb_es_ai_custom_model', '' );
$anthropic_api_key   = get_option( 'vb_es_anthropic_api_key', '' );
$openai_api_key      = get_option( 'vb_es_openai_api_key', '' );
$gemini_api_key      = get_option( 'vb_es_gemini_api_key', '' );
$default_category    = get_option( 'vb_es_default_category', 'VB Elements' );
$allow_unfiltered    = get_option( 'vb_es_allow_unfiltered_html', '0' );
?>

<div class="wrap vb-es-wrap">
    <h1>VB Element Studio Settings</h1>

    <?php
    if ( ! empty( $errors ) ) {
        foreach ( $errors as $error ) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr( $error['type'] ),
                esc_html( $error['message'] )
            );
        }
    }
    ?>

    <form method="post" action="">
        <?php wp_nonce_field( 'vb_es_settings', '_vb_es_settings_nonce' ); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="vb_es_ai_provider">AI Provider</label>
                </th>
                <td>
                    <select id="vb_es_ai_provider" name="vb_es_ai_provider">
                        <?php foreach ( $provider_labels as $provider_key => $provider_name ) : ?>
                            <option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $selected_provider, $provider_key ); ?>>
                                <?php echo esc_html( $provider_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Choose which provider powers Auto-detect Parameters.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="vb_es_ai_model">Model</label>
                </th>
                <td>
                    <select id="vb_es_ai_model" name="vb_es_ai_model"></select>
                    <p class="description">Pick a model preset for the selected provider.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="vb_es_ai_custom_model">Custom Model ID (Optional)</label>
                </th>
                <td>
                    <input
                        type="text"
                        id="vb_es_ai_custom_model"
                        name="vb_es_ai_custom_model"
                        value="<?php echo esc_attr( $custom_model ); ?>"
                        class="regular-text"
                        placeholder="e.g. gpt-5.4-mini or gemini-2.5-flash"
                    />
                    <p class="description">If set, this overrides the preset model above.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="vb_es_anthropic_api_key">Anthropic API Key</label>
                </th>
                <td>
                    <input
                        type="password"
                        id="vb_es_anthropic_api_key"
                        name="vb_es_anthropic_api_key"
                        value="<?php echo esc_attr( $anthropic_api_key ); ?>"
                        class="regular-text"
                        autocomplete="off"
                    />
                    <p class="description">Used when provider is set to Anthropic.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="vb_es_openai_api_key">OpenAI API Key</label>
                </th>
                <td>
                    <input
                        type="password"
                        id="vb_es_openai_api_key"
                        name="vb_es_openai_api_key"
                        value="<?php echo esc_attr( $openai_api_key ); ?>"
                        class="regular-text"
                        autocomplete="off"
                    />
                    <p class="description">Used when provider is set to OpenAI.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="vb_es_gemini_api_key">Google Gemini API Key</label>
                </th>
                <td>
                    <input
                        type="password"
                        id="vb_es_gemini_api_key"
                        name="vb_es_gemini_api_key"
                        value="<?php echo esc_attr( $gemini_api_key ); ?>"
                        class="regular-text"
                        autocomplete="off"
                    />
                    <p class="description">Used when provider is set to Google Gemini.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="vb_es_default_category">Default WPBakery Category</label>
                </th>
                <td>
                    <input
                        type="text"
                        id="vb_es_default_category"
                        name="vb_es_default_category"
                        value="<?php echo esc_attr( $default_category ); ?>"
                        class="regular-text"
                    />
                    <p class="description">The default category name for new elements in WPBakery.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Allow Unfiltered HTML</th>
                <td>
                    <label for="vb_es_allow_unfiltered_html">
                        <input
                            type="checkbox"
                            id="vb_es_allow_unfiltered_html"
                            name="vb_es_allow_unfiltered_html"
                            value="1"
                            <?php checked( $allow_unfiltered, '1' ); ?>
                        />
                        Skip <code>wp_kses_post()</code> sanitisation on template output
                    </label>
                    <p class="description">Only enable if you trust all HTML entered into your element templates. Default: unchecked.</p>
                </td>
            </tr>
        </table>

        <?php submit_button( 'Save Settings', 'primary', 'vb_es_save_settings' ); ?>
    </form>
</div>

<script>
(function () {
    var providerSelect = document.getElementById('vb_es_ai_provider');
    var modelSelect = document.getElementById('vb_es_ai_model');
    var selectedModel = <?php echo wp_json_encode( $selected_model ); ?>;
    var supportedModels = <?php echo wp_json_encode( $supported_models ); ?>;

    function renderModelOptions() {
        if (!providerSelect || !modelSelect) {
            return;
        }

        var provider = providerSelect.value;
        var models = supportedModels[provider] || {};
        var optionsHtml = '';
        var firstModel = '';
        var hasSelected = false;

        Object.keys(models).forEach(function (modelId) {
            if (!firstModel) {
                firstModel = modelId;
            }

            var isSelected = modelId === selectedModel;
            if (isSelected) {
                hasSelected = true;
            }

            optionsHtml += '<option value="' + escapeHtml(modelId) + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(models[modelId]) + '</option>';
        });

        modelSelect.innerHTML = optionsHtml;

        if (!hasSelected && firstModel) {
            selectedModel = firstModel;
        }

        if (selectedModel) {
            modelSelect.value = selectedModel;
        }
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    if (providerSelect) {
        providerSelect.addEventListener('change', function () {
            selectedModel = '';
            renderModelOptions();
        });
    }

    if (modelSelect) {
        modelSelect.addEventListener('change', function () {
            selectedModel = modelSelect.value;
        });
    }

    renderModelOptions();
})();
</script>
