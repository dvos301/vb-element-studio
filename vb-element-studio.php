<?php
/**
 * Plugin Name: VB Element Studio
 * Plugin URI:  https://example.com/vb-element-studio
 * Description: Create custom WPBakery Page Builder elements from AI-generated HTML/CSS with automatic parameter detection.
 * Version:     1.2.0
 * Author:      VB Element Studio
 * Author URI:  https://example.com
 * License:     GPL-2.0-or-later
 * Text Domain: vb-element-studio
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'VB_ES_VERSION', '1.2.0' );
define( 'VB_ES_PATH', plugin_dir_path( __FILE__ ) );
define( 'VB_ES_URL', plugin_dir_url( __FILE__ ) );

require_once VB_ES_PATH . 'includes/class-element-manager.php';
require_once VB_ES_PATH . 'includes/class-css-scoper.php';
require_once VB_ES_PATH . 'includes/class-ai-detector.php';
require_once VB_ES_PATH . 'includes/class-shortcode-handler.php';
require_once VB_ES_PATH . 'includes/class-vc-registrar.php';
require_once VB_ES_PATH . 'includes/class-api.php';
require_once VB_ES_PATH . 'admin/class-admin-page.php';

function vb_es_activate() {
    if ( ! defined( 'WPB_VC_VERSION' ) ) {
        set_transient( 'vb_es_wpbakery_missing', true, 30 );
    }
    VB_ES_Element_Manager::register_post_type();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'vb_es_activate' );

function vb_es_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'vb_es_deactivate' );

add_action( 'admin_notices', function () {
    if ( get_transient( 'vb_es_wpbakery_missing' ) ) {
        echo '<div class="notice notice-error"><p><strong>VB Element Studio</strong> requires WPBakery Page Builder to be installed and activated.</p></div>';
        delete_transient( 'vb_es_wpbakery_missing' );
    }

    if ( ! defined( 'WPB_VC_VERSION' ) && current_user_can( 'activate_plugins' ) ) {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>VB Element Studio:</strong> WPBakery Page Builder is not active. Elements will not appear in the page builder until WPBakery is activated.</p></div>';
    }
});

add_action( 'plugins_loaded', function () {
    $element_manager  = new VB_ES_Element_Manager();
    $admin_page       = new VB_ES_Admin_Page( $element_manager );
    $ai_detector      = new VB_ES_AI_Detector();
    $shortcode_handler = new VB_ES_Shortcode_Handler( $element_manager );

    VB_ES_API::init( $element_manager );

    if ( defined( 'WPB_VC_VERSION' ) ) {
        $vc_registrar = new VB_ES_VC_Registrar( $element_manager );
    }
});

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once VB_ES_PATH . 'includes/class-cli.php';
    WP_CLI::add_command( 'vb-element', 'VB_ES_CLI_Command' );
}
