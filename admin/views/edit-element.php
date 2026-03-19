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

$validation_warnings = get_transient( 'vb_es_validation_warnings_' . get_current_user_id() );
if ( $validation_warnings ) {
    delete_transient( 'vb_es_validation_warnings_' . get_current_user_id() );
}

$import_results = get_transient( 'vb_es_import_results_' . get_current_user_id() );
if ( $import_results ) {
    delete_transient( 'vb_es_import_results_' . get_current_user_id() );
}
?>

<div class="wrap vb-es-wrap">
    <h1><?php echo esc_html( $page_title ); ?></h1>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Element saved successfully.</p></div>
    <?php endif; ?>

    <?php if ( isset( $_GET['imported'] ) && ! empty( $import_results ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Import completed.</strong></p>
            <?php if ( ! empty( $import_results['created'] ) ) : ?>
                <ul style="margin: 0.5em 0 0 1.25em; list-style: disc;">
                    <?php foreach ( $import_results['created'] as $created_element ) : ?>
                        <li><?php echo esc_html( $created_element['name'] . ' (' . $created_element['slug'] . ')' ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ( ! empty( $import_results['page_url'] ) ) : ?>
                <p>Page updated: <a href="<?php echo esc_url( $import_results['page_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $import_results['page_url'] ); ?></a></p>
            <?php endif; ?>
        </div>
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

    <?php if ( ! empty( $import_results['errors'] ) ) : ?>
        <div class="notice notice-error is-dismissible">
            <p><strong>Import issues:</strong></p>
            <ul style="margin: 0.5em 0 0 1.25em; list-style: disc;">
                <?php foreach ( $import_results['errors'] as $error_message ) : ?>
                    <li><?php echo esc_html( $error_message ); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $import_results['warnings'] ) ) : ?>
        <div class="notice notice-warning is-dismissible">
            <p><strong>Imported element warnings:</strong></p>
            <ul style="margin: 0.5em 0 0 1.25em; list-style: disc;">
                <?php foreach ( $import_results['warnings'] as $warning_slug => $warning_list ) : ?>
                    <li>
                        <strong><?php echo esc_html( $warning_slug ); ?></strong>
                        <ul style="margin: 0.5em 0 0 1.25em; list-style: disc;">
                            <?php foreach ( (array) $warning_list as $warning_message ) : ?>
                                <li><?php echo esc_html( $warning_message ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $validation_warnings ) ) : ?>
        <div class="notice notice-warning is-dismissible">
            <p><strong>Validation warnings:</strong></p>
            <ul style="margin: 0.5em 0 0 1.25em; list-style: disc;">
                <?php foreach ( $validation_warnings as $warning ) : ?>
                    <li><?php echo esc_html( $warning ); ?></li>
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

    <?php if ( $is_edit ) : ?>
        <form method="post" action="" id="vb-es-element-form">
            <?php wp_nonce_field( 'vb_es_save_element', '_vb_es_element_nonce' ); ?>
            <input type="hidden" name="element_id" value="<?php echo esc_attr( $el_id ); ?>" />
            <input type="hidden" name="vb_es_params_json" id="vb-es-params-json" value="<?php echo esc_attr( $params_json ); ?>" />

            <div class="vb-es-section">
                <h2>Element Info</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="element_name">Element Name</label></th>
                        <td><input type="text" id="element_name" name="element_name" value="<?php echo esc_attr( $name ); ?>" class="regular-text" required /></td>
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
                        <td><textarea id="element_description" name="element_description" rows="3" class="large-text"><?php echo esc_textarea( $description ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="element_category">WPBakery Category</label></th>
                        <td><input type="text" id="element_category" name="element_category" value="<?php echo esc_attr( $category ); ?>" class="regular-text" /></td>
                    </tr>
                </table>
            </div>

            <div class="vb-es-section">
                <h2>Paste HTML</h2>
                <p class="description">Paste your AI-generated HTML here. Then click Auto-detect Parameters below.</p>
                <textarea id="element_raw_html" name="element_raw_html" rows="12" class="large-text code vb-es-code-textarea"><?php echo esc_textarea( $raw_html ); ?></textarea>
            </div>

            <div class="vb-es-section">
                <h2>Paste CSS</h2>
                <p class="description">Paste component CSS here. It will be automatically scoped to this element so it won't conflict with the rest of your site.</p>
                <textarea id="element_raw_css" name="element_raw_css" rows="12" class="large-text code vb-es-code-textarea"><?php echo esc_textarea( $raw_css ); ?></textarea>
            </div>

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

            <div class="vb-es-section">
                <h2>HTML Template</h2>
                <p class="description">Tokens like <code>{{heading_text}}</code> will be replaced with the parameter values below. You can edit this manually.</p>
                <textarea id="element_html_template" name="element_html_template" rows="12" class="large-text code vb-es-code-textarea"><?php echo esc_textarea( $html_template ); ?></textarea>
            </div>

            <div class="vb-es-section">
                <h2>Parameters</h2>
                <p class="description">Use standard params for single values, or choose <code>param_group</code> for repeaters like cards, FAQs, or team members. Repeater defaults should be a JSON array of item objects.</p>
                <div id="vb-es-params-container"></div>
                <p><button type="button" id="vb-es-add-param" class="button button-secondary">+ Add Parameter</button></p>
            </div>

            <?php submit_button( 'Save Element', 'primary', 'vb_es_save_element' ); ?>
        </form>
    <?php else : ?>
        <form method="post" action="" id="vb-es-import-form" data-default-category="<?php echo esc_attr( $default_category ); ?>">
            <?php wp_nonce_field( 'vb_es_save_import', '_vb_es_import_nonce' ); ?>
            <input type="hidden" name="vb_es_import_candidates_json" id="vb-es-import-candidates-json" value="[]" />

            <div class="vb-es-section">
                <h2>Paste Full Snippet</h2>
                <p class="description">Paste a combined HTML/CSS snippet from your AI tool. VB Element Studio will extract styles, detect reusable sections, generate params, and let you create multiple elements in one flow.</p>
                <textarea id="vb-es-combined-snippet" rows="18" class="large-text code vb-es-code-textarea" placeholder="<section>...</section><style>...</style>"></textarea>
                <p>
                    <button type="button" id="vb-es-analyze-snippet-btn" class="button button-secondary button-hero" <?php echo $api_key_set ? '' : 'disabled'; ?>>Analyze Snippet</button>
                    <span id="vb-es-import-spinner" class="spinner" style="float: none;"></span>
                </p>
                <div id="vb-es-import-status"></div>
                <p class="description">Analysis uses <?php echo esc_html( $selected_provider_label ); ?> (model: <code><?php echo esc_html( $selected_model ); ?></code>) and will split the snippet into candidate elements for review.</p>
            </div>

            <div class="vb-es-section vb-es-import-review" id="vb-es-import-review" style="display:none;">
                <h2>Review Candidate Elements</h2>
                <p class="description">Rename, exclude, or tweak detected sections before creating them as VB elements. Advanced fields are available per candidate if you need to inspect HTML, CSS, template, or params JSON.</p>
                <div id="vb-es-import-global-warnings"></div>
                <div id="vb-es-import-candidates"></div>
            </div>

            <div class="vb-es-section vb-es-import-placement" id="vb-es-import-placement" style="display:none;">
                <h2>Optional Page Placement</h2>
                <label for="vb_es_place_after_create">
                    <input type="checkbox" id="vb_es_place_after_create" name="vb_es_place_after_create" value="1" />
                    Place newly created elements onto a page after import
                </label>
                <div class="vb-es-placement-fields">
                    <div class="vb-es-field">
                        <label for="vb_es_page_target">Page ID, slug, or title</label>
                        <input type="text" id="vb_es_page_target" name="vb_es_page_target" class="regular-text" placeholder="homepage or 42" />
                    </div>
                    <div class="vb-es-field">
                        <label for="vb_es_page_position">Placement Position</label>
                        <select id="vb_es_page_position" name="vb_es_page_position">
                            <option value="append">Append</option>
                            <option value="prepend">Prepend</option>
                        </select>
                    </div>
                </div>
            </div>

            <?php submit_button( 'Create Selected Elements', 'primary', 'vb_es_save_import', 'submit', true, [ 'id' => 'vb-es-import-submit', 'disabled' => 'disabled' ] ); ?>
        </form>
    <?php endif; ?>
</div>
