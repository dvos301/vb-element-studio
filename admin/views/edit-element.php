<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_edit    = ! empty( $element );
$page_title = $is_edit ? 'Edit Element' : 'Add New Element';

$name          = $is_edit ? $element['name'] : '';
$slug          = $is_edit ? $element['slug'] : '';
$description   = $is_edit ? $element['description'] : '';
$category      = $is_edit ? $element['category'] : $default_category;
$raw_html      = $is_edit ? $element['raw_html'] : '';
$raw_css       = $is_edit ? $element['raw_css'] : '';
$html_template = $is_edit ? $element['html_template'] : '';
$params_json   = $is_edit ? $element['params_json'] : '[]';
$el_id         = $is_edit ? $element['id'] : 0;

$errors = get_transient( 'vb_es_element_errors' );
if ( $errors ) {
    delete_transient( 'vb_es_element_errors' );
}

$sanitization_notes = get_transient( 'vb_es_sanitization_notes_' . get_current_user_id() );
if ( $sanitization_notes ) {
    delete_transient( 'vb_es_sanitization_notes_' . get_current_user_id() );
}
?>

<div class="wrap vb-es-wrap">
    <h1><?php echo esc_html( $page_title ); ?></h1>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Element saved successfully.</p></div>
    <?php endif; ?>

    <?php if ( ! empty( $sanitization_notes ) ) : ?>
        <div class="notice notice-info is-dismissible">
            <p><strong>Automatic cleanup was applied to keep this component WPBakery-friendly:</strong></p>
            <ul style="margin: 0.5em 0 0 1.25em; list-style: disc;">
                <?php foreach ( $sanitization_notes as $note ) : ?>
                    <li><?php echo esc_html( $note ); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $errors ) ) :
        foreach ( $errors as $error ) :
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr( $error['type'] ),
                esc_html( $error['message'] )
            );
        endforeach;
    endif; ?>

    <?php if ( ! $api_key_set ) : ?>
        <div class="notice notice-warning">
            <p><?php echo esc_html( $selected_provider_label ); ?> API key is not configured. <a href="<?php echo esc_url( admin_url( 'admin.php?page=vb-es-settings' ) ); ?>">Set it in Settings</a> to use the Auto-detect Parameters feature.</p>
        </div>
    <?php endif; ?>

    <form method="post" action="" id="vb-es-element-form">
        <?php wp_nonce_field( 'vb_es_save_element', '_vb_es_element_nonce' ); ?>
        <input type="hidden" name="element_id" value="<?php echo esc_attr( $el_id ); ?>" />
        <input type="hidden" name="vb_es_params_json" id="vb-es-params-json" value="<?php echo esc_attr( $params_json ); ?>" />

        <!-- Section 1: Element Info -->
        <div class="vb-es-section">
            <h2>Element Info</h2>
            <table class="form-table">
                <tr>
                    <th><label for="element_name">Element Name</label></th>
                    <td>
                        <input type="text" id="element_name" name="element_name" value="<?php echo esc_attr( $name ); ?>" class="regular-text" required />
                    </td>
                </tr>
                <tr>
                    <th><label for="element_slug">Shortcode Slug</label></th>
                    <td>
                        <input type="text" id="element_slug" name="element_slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" placeholder="Auto-generated from name if left blank" />
                        <p class="description">Lowercase with underscores (e.g. <code>vb_my_card</code>). Prefixed with <code>vb_</code> automatically. Used as the shortcode tag.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="element_description">Description</label></th>
                    <td>
                        <textarea id="element_description" name="element_description" rows="3" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th><label for="element_category">WPBakery Category</label></th>
                    <td>
                        <input type="text" id="element_category" name="element_category" value="<?php echo esc_attr( $category ); ?>" class="regular-text" />
                    </td>
                </tr>
            </table>
        </div>

        <!-- Section 2: Raw HTML Input -->
        <div class="vb-es-section">
            <h2>Paste HTML</h2>
            <p class="description">Paste your AI-generated HTML here. Then click Auto-detect Parameters below.</p>
            <textarea id="element_raw_html" name="element_raw_html" rows="12" class="large-text code vb-es-code-textarea"><?php echo esc_textarea( $raw_html ); ?></textarea>
        </div>

        <!-- Section 3: CSS -->
        <div class="vb-es-section">
            <h2>Paste CSS</h2>
            <p class="description">Paste component CSS here. It will be automatically scoped to this element so it won't conflict with the rest of your site.</p>
            <textarea id="element_raw_css" name="element_raw_css" rows="12" class="large-text code vb-es-code-textarea"><?php echo esc_textarea( $raw_css ); ?></textarea>
        </div>

        <!-- Section 4: AI Parameter Detection -->
        <div class="vb-es-section">
            <h2>AI Parameter Detection</h2>
            <p>
                <button type="button" id="vb-es-detect-btn" class="button button-secondary button-hero" <?php echo $api_key_set ? '' : 'disabled'; ?>>
                    &#10024; Auto-detect Parameters
                </button>
                <span id="vb-es-detect-spinner" class="spinner" style="float: none;"></span>
            </p>
            <div id="vb-es-detect-status"></div>
            <p class="description">
                This uses <?php echo esc_html( $selected_provider_label ); ?> (model: <code><?php echo esc_html( $selected_model ); ?></code>) to analyse your HTML and suggest editable parameters. You can review and adjust the results before saving.
            </p>
        </div>

        <!-- Section 5: HTML Template + Parameters -->
        <div class="vb-es-section">
            <h2>HTML Template</h2>
            <p class="description">Tokens like <code>{{heading_text}}</code> will be replaced with the parameter values below. You can edit this manually.</p>
            <textarea id="element_html_template" name="element_html_template" rows="12" class="large-text code vb-es-code-textarea"><?php echo esc_textarea( $html_template ); ?></textarea>
        </div>

        <div class="vb-es-section">
            <h2>Parameters</h2>
            <div id="vb-es-params-container">
                <!-- Param rows populated by JS -->
            </div>
            <p>
                <button type="button" id="vb-es-add-param" class="button button-secondary">+ Add Parameter</button>
            </p>
        </div>

        <?php submit_button( 'Save Element', 'primary', 'vb_es_save_element' ); ?>
    </form>
</div>

<script type="text/html" id="tmpl-vb-es-param-row">
    <div class="vb-es-param-row" data-index="{{data.index}}">
        <div class="vb-es-param-row-header">
            <span class="vb-es-param-row-title">Parameter: <strong class="vb-es-param-label-preview">{{data.heading}}</strong></span>
            <button type="button" class="button button-link-delete vb-es-remove-param">Remove</button>
        </div>
        <div class="vb-es-param-row-fields">
            <div class="vb-es-field">
                <label>Param Name (slug)</label>
                <input type="text" class="vb-es-param-field" data-key="param_name" value="{{data.param_name}}" placeholder="heading_text" />
            </div>
            <div class="vb-es-field">
                <label>Label</label>
                <input type="text" class="vb-es-param-field" data-key="heading" value="{{data.heading}}" placeholder="Heading Text" />
            </div>
            <div class="vb-es-field">
                <label>Type</label>
                <select class="vb-es-param-field vb-es-param-type-select" data-key="type">
                    <option value="textfield" {{data.type_textfield}}>Text Field</option>
                    <option value="textarea" {{data.type_textarea}}>Text Area</option>
                    <option value="colorpicker" {{data.type_colorpicker}}>Color Picker</option>
                    <option value="attach_image" {{data.type_attach_image}}>Image</option>
                    <option value="dropdown" {{data.type_dropdown}}>Dropdown</option>
                    <option value="checkbox" {{data.type_checkbox}}>Checkbox</option>
                </select>
            </div>
            <div class="vb-es-field">
                <label>Default Value</label>
                <input type="text" class="vb-es-param-field" data-key="default" value="{{data.default}}" />
            </div>
            <div class="vb-es-field vb-es-field-wide">
                <label>Description</label>
                <input type="text" class="vb-es-param-field" data-key="description" value="{{data.description}}" />
            </div>
            <div class="vb-es-field vb-es-field-wide vb-es-options-field" style="{{data.options_display}}">
                <label>Options (comma-separated)</label>
                <input type="text" class="vb-es-param-field" data-key="options" value="{{data.options}}" placeholder="option1,option2,option3" />
            </div>
        </div>
    </div>
</script>
