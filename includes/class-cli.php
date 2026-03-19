<?php

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI_Command' ) ) {
    return;
}

/**
 * Manage VB Element Studio elements from the command line.
 *
 * ## EXAMPLES
 *
 *     # List all elements
 *     wp vb-element list
 *
 *     # Create an element from files
 *     wp vb-element create --name="Hero" --html=@hero.html --css=@hero.css --params=@params.json
 *
 *     # Place an element on a page
 *     wp vb-element place vb_hero_section --page=42 --atts='{"heading":"Hello"}'
 */
class VB_ES_CLI_Command extends WP_CLI_Command {

    /**
     * Create a new element.
     *
     * ## OPTIONS
     *
     * --name=<name>
     * : Element display name.
     *
     * [--slug=<slug>]
     * : Shortcode tag (auto-generated from name if omitted).
     *
     * [--html=<html>]
     * : HTML template with {{param_name}} placeholders. Prefix with @ to read from a file.
     *
     * [--css=<css>]
     * : CSS rules (auto-scoped). Prefix with @ to read from a file.
     *
     * [--params=<params>]
     * : Parameter definitions as JSON. Prefix with @ to read from a file.
     *
     * [--category=<category>]
     * : WPBakery category. Default: VB Elements.
     *
     * [--description=<description>]
     * : Element description.
     *
     * [--icon=<icon>]
     * : Icon class. Default: dashicons-editor-code.
     *
     * [--require-params]
     * : Reject creation if no --params are provided.
     *
     * ## EXAMPLES
     *
     *     wp vb-element create --name="Hero Section" --html=@hero.html --css=@hero.css --params=@params.json
     *     wp vb-element create --name="Banner" --html='<div class="banner"><h2>{{heading}}</h2></div>' --require-params
     */
    public function create( $args, $assoc_args ) {
        $assoc_args = $this->read_file_args( $assoc_args, [ 'html', 'css', 'params' ] );
        $assoc_args = $this->decode_params_arg( $assoc_args );

        if ( isset( $assoc_args['require-params'] ) ) {
            if ( empty( $assoc_args['params'] ) ) {
                WP_CLI::error( 'The --require-params flag is set but no --params were provided. Every element must define params for all editable text.' );
            }
            unset( $assoc_args['require-params'] );
        }

        $result = VB_ES_API::create_element( $assoc_args );

        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        $post_id = is_array( $result ) ? $result['post_id'] : $result;
        WP_CLI::success( "Element created (ID: {$post_id})." );

        $this->run_post_creation_validation( $post_id );
    }

    /**
     * List all elements.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Accepts: table, json, csv, yaml, ids, count. Default: table.
     *
     * [--fields=<fields>]
     * : Comma-separated list of fields. Default: id,name,slug,category.
     *
     * ## EXAMPLES
     *
     *     wp vb-element list
     *     wp vb-element list --format=json
     *     wp vb-element list --fields=id,name,slug
     *
     * @subcommand list
     */
    public function list_( $args, $assoc_args ) {
        $elements = VB_ES_API::list_elements();

        if ( empty( $elements ) ) {
            WP_CLI::log( 'No elements found.' );
            return;
        }

        $fields = $assoc_args['fields'] ?? 'id,name,slug,category';
        $field_list = array_map( 'trim', explode( ',', $fields ) );

        $items = array_map( function ( $el ) use ( $field_list ) {
            $row = [];
            foreach ( $field_list as $f ) {
                $row[ $f ] = $el[ $f ] ?? '';
            }
            return $row;
        }, $elements );

        $formatter = new \WP_CLI\Formatter( $assoc_args, $field_list );
        $formatter->display_items( $items );
    }

    /**
     * Get a single element's details.
     *
     * ## OPTIONS
     *
     * <id-or-slug>
     * : Element post ID or shortcode slug.
     *
     * [--format=<format>]
     * : Output format. Accepts: table, json, yaml. Default: json.
     *
     * [--fields=<fields>]
     * : Comma-separated list of fields.
     *
     * ## EXAMPLES
     *
     *     wp vb-element get 42
     *     wp vb-element get vb_hero_section
     *     wp vb-element get vb_hero_section --format=json
     */
    public function get( $args, $assoc_args ) {
        $element = VB_ES_API::get_element( $args[0] );
        if ( ! $element ) {
            WP_CLI::error( 'Element not found: ' . $args[0] );
        }

        $assoc_args = wp_parse_args( $assoc_args, [ 'format' => 'json' ] );

        if ( $assoc_args['format'] === 'json' ) {
            WP_CLI::log( wp_json_encode( $element, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
            return;
        }

        $fields = isset( $assoc_args['fields'] )
            ? array_map( 'trim', explode( ',', $assoc_args['fields'] ) )
            : array_keys( $element );

        $formatter = new \WP_CLI\Formatter( $assoc_args, $fields );
        $formatter->display_item( $element );
    }

    /**
     * Update an existing element.
     *
     * ## OPTIONS
     *
     * <id-or-slug>
     * : Element post ID or shortcode slug.
     *
     * [--name=<name>]
     * : New element name.
     *
     * [--html=<html>]
     * : New HTML template. Prefix with @ to read from a file.
     *
     * [--css=<css>]
     * : New CSS. Prefix with @ to read from a file.
     *
     * [--params=<params>]
     * : New parameter definitions as JSON. Prefix with @ to read from a file.
     *
     * [--category=<category>]
     * : New WPBakery category.
     *
     * [--description=<description>]
     * : New description.
     *
     * [--icon=<icon>]
     * : New icon class.
     *
     * ## EXAMPLES
     *
     *     wp vb-element update vb_hero_section --html=@hero-v2.html --css=@hero-v2.css
     *     wp vb-element update 42 --name="Updated Hero"
     */
    public function update( $args, $assoc_args ) {
        $assoc_args = $this->read_file_args( $assoc_args, [ 'html', 'css', 'params' ] );
        $assoc_args = $this->decode_params_arg( $assoc_args );

        $result = VB_ES_API::update_element( $args[0], $assoc_args );

        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        WP_CLI::success( 'Element updated.' );
    }

    /**
     * Delete an element.
     *
     * ## OPTIONS
     *
     * <id-or-slug>
     * : Element post ID or shortcode slug.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp vb-element delete vb_hero_section --yes
     *     wp vb-element delete 42
     */
    public function delete( $args, $assoc_args ) {
        $element = VB_ES_API::get_element( $args[0] );
        if ( ! $element ) {
            WP_CLI::error( 'Element not found: ' . $args[0] );
        }

        WP_CLI::confirm( "Delete element \"{$element['name']}\" (ID: {$element['id']})?", $assoc_args );

        $result = VB_ES_API::delete_element( $element['id'] );
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        WP_CLI::success( "Element \"{$element['name']}\" deleted." );
    }

    /**
     * Export an element as JSON.
     *
     * ## OPTIONS
     *
     * <id-or-slug>
     * : Element post ID or shortcode slug.
     *
     * ## EXAMPLES
     *
     *     wp vb-element export vb_hero_section > hero.json
     *     wp vb-element export 42
     */
    public function export( $args, $assoc_args ) {
        $data = VB_ES_API::export_element( $args[0] );
        if ( is_wp_error( $data ) ) {
            WP_CLI::error( $data->get_error_message() );
        }

        WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * Import an element from a JSON file.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to a JSON file containing the element definition.
     *
     * ## EXAMPLES
     *
     *     wp vb-element import hero.json
     *     cat hero.json | wp vb-element import -
     */
    public function import( $args, $assoc_args ) {
        $file = $args[0];

        if ( $file === '-' ) {
            $json = file_get_contents( 'php://stdin' );
        } else {
            if ( ! file_exists( $file ) ) {
                WP_CLI::error( "File not found: {$file}" );
            }
            $json = file_get_contents( $file );
        }

        $result = VB_ES_API::import_element( $json );
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        $post_id = is_array( $result ) ? $result['post_id'] : $result;
        WP_CLI::success( "Element imported (ID: {$post_id})." );
    }

    /**
     * Place an element on a page inside a WPBakery row/column wrapper.
     *
     * ## OPTIONS
     *
     * <element>
     * : Element shortcode slug or ID.
     *
     * --page=<page>
     * : Target page ID, slug, or title.
     *
     * [--atts=<atts>]
     * : Shortcode attributes as a JSON object. Example: '{"heading":"Hello World"}'
     *
     * [--position=<position>]
     * : Where to insert: append (default), prepend, or after:<shortcode_tag>.
     *
     * ## EXAMPLES
     *
     *     wp vb-element place vb_hero_section --page=homepage
     *     wp vb-element place vb_hero_section --page=42 --atts='{"heading":"Hello"}' --position=prepend
     *     wp vb-element place vb_cta_banner --page=about --position=after:vb_hero_section
     */
    public function place( $args, $assoc_args ) {
        $page = $assoc_args['page'] ?? '';
        if ( empty( $page ) ) {
            WP_CLI::error( 'The --page flag is required.' );
        }

        $atts = [];
        if ( ! empty( $assoc_args['atts'] ) ) {
            $atts = json_decode( $assoc_args['atts'], true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                WP_CLI::error( 'Invalid JSON in --atts: ' . json_last_error_msg() );
            }
        }

        $position = $assoc_args['position'] ?? 'append';

        $result = VB_ES_API::place_on_page( $page, $args[0], $atts, $position );
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        WP_CLI::success( 'Element placed on page.' );
    }

    /**
     * List available bundled templates.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Accepts: table, json, csv, yaml. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp vb-element templates
     *     wp vb-element templates --format=json
     */
    public function templates( $args, $assoc_args ) {
        $templates = VB_ES_API::get_templates();

        if ( empty( $templates ) ) {
            WP_CLI::log( 'No templates found.' );
            return;
        }

        $items = array_map( function ( $t ) {
            return [
                'slug'        => $t['template_slug'],
                'name'        => $t['name'],
                'description' => $t['description'] ?? '',
                'category'    => $t['category'] ?? 'VB Elements',
                'params'      => count( $t['params'] ?? [] ),
            ];
        }, $templates );

        $formatter = new \WP_CLI\Formatter( $assoc_args, [ 'slug', 'name', 'description', 'category', 'params' ] );
        $formatter->display_items( $items );
    }

    /**
     * Create an element from a bundled template.
     *
     * ## OPTIONS
     *
     * <template>
     * : Template slug (filename without .json).
     *
     * [--name=<name>]
     * : Override the template's default name.
     *
     * [--category=<category>]
     * : Override the template's default category.
     *
     * ## EXAMPLES
     *
     *     wp vb-element create-from-template hero-section
     *     wp vb-element create-from-template hero-section --name="Custom Hero"
     *
     * @subcommand create-from-template
     */
    public function create_from_template( $args, $assoc_args ) {
        $result = VB_ES_API::create_from_template( $args[0], $assoc_args );

        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        $post_id = is_array( $result ) ? $result['post_id'] : $result;
        WP_CLI::success( "Element created from template \"{$args[0]}\" (ID: {$post_id})." );
    }

    /**
     * Validate an element for hardcoded text that should be parameterized.
     *
     * Scans the HTML template for text nodes longer than 3 words that are
     * not wrapped in {{param}} placeholders and reports warnings.
     *
     * ## OPTIONS
     *
     * <id-or-slug>
     * : Element post ID or shortcode slug.
     *
     * [--format=<format>]
     * : Output format. Accepts: table, json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp vb-element validate vb_hero_section
     *     wp vb-element validate 42 --format=json
     */
    public function validate( $args, $assoc_args ) {
        $result = VB_ES_API::validate_element( $args[0] );

        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        $format = $assoc_args['format'] ?? 'table';

        if ( $format === 'json' ) {
            WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
            return;
        }

        WP_CLI::log( "Element: {$result['element']} ({$result['slug']})" );
        WP_CLI::log( "Parameters: {$result['param_count']}" );

        if ( $result['valid'] ) {
            WP_CLI::success( 'No hardcoded text detected.' );
        } else {
            WP_CLI::warning( count( $result['warnings'] ) . ' issue(s) found:' );
            foreach ( $result['warnings'] as $i => $warning ) {
                WP_CLI::log( '  ' . ( $i + 1 ) . '. ' . $warning );
            }
        }
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Run validation on a freshly created element and output warnings.
     */
    private function run_post_creation_validation( $post_id ) {
        $result = VB_ES_API::validate_element( $post_id );
        if ( is_wp_error( $result ) || $result['valid'] ) {
            return;
        }

        WP_CLI::log( '' );
        WP_CLI::warning( 'Post-creation validation found ' . count( $result['warnings'] ) . ' issue(s):' );
        foreach ( $result['warnings'] as $warning ) {
            WP_CLI::log( '  ⚠ ' . $warning );
        }
        WP_CLI::log( 'Run "wp vb-element validate ' . $result['slug'] . '" for details.' );
    }

    /**
     * For specified keys, if the value starts with @ treat it as a file path
     * and replace with the file's contents.
     */
    private function read_file_args( $assoc_args, $keys ) {
        foreach ( $keys as $key ) {
            if ( ! isset( $assoc_args[ $key ] ) ) {
                continue;
            }
            $val = $assoc_args[ $key ];
            if ( is_string( $val ) && strlen( $val ) > 1 && $val[0] === '@' ) {
                $file = substr( $val, 1 );
                if ( ! file_exists( $file ) ) {
                    WP_CLI::error( "File not found: {$file}" );
                }
                $assoc_args[ $key ] = file_get_contents( $file );
            }
        }
        return $assoc_args;
    }

    /**
     * Decode the --params argument from JSON string to array.
     */
    private function decode_params_arg( $assoc_args ) {
        if ( isset( $assoc_args['params'] ) && is_string( $assoc_args['params'] ) ) {
            $decoded = json_decode( $assoc_args['params'], true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                WP_CLI::error( 'Invalid JSON in --params: ' . json_last_error_msg() );
            }
            $assoc_args['params'] = $decoded;
        }
        return $assoc_args;
    }
}
