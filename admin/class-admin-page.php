<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VB_ES_Admin_Page {

    private $element_manager;
    private $hook_suffixes = [];

    public function __construct( VB_ES_Element_Manager $element_manager ) {
        $this->element_manager = $element_manager;

        add_action( 'admin_menu', [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init', [ $this, 'handle_form_submissions' ] );
    }

    public function register_menus() {
        $this->hook_suffixes[] = add_menu_page(
            'VB Element Studio',
            'VB Element Studio',
            'manage_options',
            'vb-element-studio',
            [ $this, 'render_list_page' ],
            'dashicons-editor-code',
            58
        );

        $this->hook_suffixes[] = add_submenu_page(
            'vb-element-studio',
            'All Elements',
            'All Elements',
            'manage_options',
            'vb-element-studio',
            [ $this, 'render_list_page' ]
        );

        $this->hook_suffixes[] = add_submenu_page(
            'vb-element-studio',
            'Add New Element',
            'Add New',
            'manage_options',
            'vb-es-edit',
            [ $this, 'render_edit_page' ]
        );

        $this->hook_suffixes[] = add_submenu_page(
            'vb-element-studio',
            'Settings',
            'Settings',
            'manage_options',
            'vb-es-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, $this->hook_suffixes, true ) ) {
            return;
        }

        wp_enqueue_style(
            'vb-es-admin',
            VB_ES_URL . 'assets/admin.css',
            [],
            VB_ES_VERSION
        );

        wp_enqueue_script(
            'vb-es-admin',
            VB_ES_URL . 'assets/admin.js',
            [ 'jquery' ],
            VB_ES_VERSION,
            true
        );

        wp_localize_script( 'vb-es-admin', 'vbEsAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'vb_es_ajax_nonce' ),
        ]);
    }

    public function handle_form_submissions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->handle_settings_save();
        $this->handle_element_save();
        $this->handle_element_delete();
    }

    private function handle_settings_save() {
        if ( ! isset( $_POST['vb_es_save_settings'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['_vb_es_settings_nonce'] ?? '', 'vb_es_settings' ) ) {
            wp_die( 'Security check failed.' );
        }

        $provider = sanitize_key( $_POST['vb_es_ai_provider'] ?? 'anthropic' );
        $provider_labels = VB_ES_AI_Detector::get_provider_labels();
        if ( ! isset( $provider_labels[ $provider ] ) ) {
            $provider = 'anthropic';
        }

        $supported_models = VB_ES_AI_Detector::get_supported_models();
        $model = sanitize_text_field( $_POST['vb_es_ai_model'] ?? '' );
        if ( ! isset( $supported_models[ $provider ][ $model ] ) ) {
            $model = VB_ES_AI_Detector::get_default_model( $provider );
        }

        update_option( 'vb_es_ai_provider', $provider );
        update_option( 'vb_es_ai_model', $model );
        update_option( 'vb_es_ai_custom_model', sanitize_text_field( $_POST['vb_es_ai_custom_model'] ?? '' ) );
        update_option( 'vb_es_anthropic_api_key', sanitize_text_field( $_POST['vb_es_anthropic_api_key'] ?? '' ) );
        update_option( 'vb_es_openai_api_key', sanitize_text_field( $_POST['vb_es_openai_api_key'] ?? '' ) );
        update_option( 'vb_es_gemini_api_key', sanitize_text_field( $_POST['vb_es_gemini_api_key'] ?? '' ) );
        update_option( 'vb_es_default_category', sanitize_text_field( $_POST['vb_es_default_category'] ?? 'VB Elements' ) );
        update_option( 'vb_es_allow_unfiltered_html', isset( $_POST['vb_es_allow_unfiltered_html'] ) ? '1' : '0' );

        add_settings_error( 'vb_es_settings', 'settings_saved', 'Settings saved successfully.', 'success' );
        set_transient( 'vb_es_settings_errors', get_settings_errors( 'vb_es_settings' ), 30 );
    }

    private function handle_element_save() {
        if ( ! isset( $_POST['vb_es_save_element'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['_vb_es_element_nonce'] ?? '', 'vb_es_save_element' ) ) {
            wp_die( 'Security check failed.' );
        }

        $data = [
            'id'            => absint( $_POST['element_id'] ?? 0 ) ?: null,
            'name'          => $_POST['element_name'] ?? '',
            'slug'          => $_POST['element_slug'] ?? '',
            'description'   => $_POST['element_description'] ?? '',
            'category'      => $_POST['element_category'] ?? '',
            'raw_html'      => $_POST['element_raw_html'] ?? '',
            'raw_css'       => $_POST['element_raw_css'] ?? '',
            'html_template' => $_POST['element_html_template'] ?? '',
            'params_json'   => $_POST['vb_es_params_json'] ?? '[]',
        ];

        $result = $this->element_manager->save_element( $data );

        if ( is_wp_error( $result ) ) {
            add_settings_error( 'vb_es_element', 'save_failed', 'Failed to save element: ' . $result->get_error_message(), 'error' );
            set_transient( 'vb_es_element_errors', get_settings_errors( 'vb_es_element' ), 30 );
            return;
        }

        $post_id = is_array( $result ) ? absint( $result['post_id'] ?? 0 ) : absint( $result );
        if ( $post_id <= 0 ) {
            add_settings_error( 'vb_es_element', 'save_failed', 'Failed to save element: invalid save response.', 'error' );
            set_transient( 'vb_es_element_errors', get_settings_errors( 'vb_es_element' ), 30 );
            return;
        }

        $notes = is_array( $result ) ? (array) ( $result['sanitization_notes'] ?? [] ) : [];
        if ( ! empty( $notes ) ) {
            set_transient( 'vb_es_sanitization_notes_' . get_current_user_id(), $notes, 120 );
        }

        wp_redirect( admin_url( 'admin.php?page=vb-es-edit&element_id=' . $post_id . '&saved=1' ) );
        exit;
    }

    private function handle_element_delete() {
        if ( ! isset( $_GET['vb_es_delete'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'vb_es_delete_element' ) ) {
            wp_die( 'Security check failed.' );
        }

        $post_id = absint( $_GET['vb_es_delete'] );
        $this->element_manager->delete_element( $post_id );

        wp_redirect( admin_url( 'admin.php?page=vb-element-studio&deleted=1' ) );
        exit;
    }

    public function render_list_page() {
        $elements = $this->element_manager->get_all_elements();
        include VB_ES_PATH . 'admin/views/list-elements.php';
    }

    public function render_edit_page() {
        $element_id = absint( $_GET['element_id'] ?? 0 );
        $element = $element_id ? $this->element_manager->get_element( $element_id ) : null;
        $default_category = get_option( 'vb_es_default_category', 'VB Elements' );
        $api_key_set = VB_ES_AI_Detector::has_valid_ai_configuration();
        $selected_provider = VB_ES_AI_Detector::get_selected_provider();
        $provider_labels = VB_ES_AI_Detector::get_provider_labels();
        $selected_provider_label = $provider_labels[ $selected_provider ] ?? 'Selected provider';
        $selected_model = VB_ES_AI_Detector::get_selected_model( $selected_provider );

        include VB_ES_PATH . 'admin/views/edit-element.php';
    }

    public function render_settings_page() {
        $errors = get_transient( 'vb_es_settings_errors' );
        if ( $errors ) {
            delete_transient( 'vb_es_settings_errors' );
        }

        include VB_ES_PATH . 'admin/views/settings.php';
    }
}
