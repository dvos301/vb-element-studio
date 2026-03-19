<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VB_ES_API {

    private static $manager = null;

    public static function init( $manager = null ) {
        if ( $manager instanceof VB_ES_Element_Manager ) {
            self::$manager = $manager;
        }
    }

    private static function manager() {
        if ( ! self::$manager ) {
            self::$manager = new VB_ES_Element_Manager();
        }
        return self::$manager;
    }

    /**
     * Resolve an element by numeric ID or shortcode slug (base_tag).
     *
     * @param int|string $id_or_slug
     * @return array|null Element data array or null.
     */
    public static function resolve_element( $id_or_slug ) {
        if ( is_numeric( $id_or_slug ) ) {
            return self::manager()->get_element( (int) $id_or_slug );
        }
        return self::manager()->get_element_by_slug( (string) $id_or_slug );
    }

    /**
     * Create a new element.
     *
     * Accepts friendly key names (html, css, params) and translates
     * them to the internal format used by VB_ES_Element_Manager.
     *
     * @param array $args {
     *     @type string       $name          Required. Element display name.
     *     @type string       $slug          Shortcode tag (auto-generated from name if empty).
     *     @type string       $description   Element description shown in WPBakery.
     *     @type string       $category      WPBakery category. Default 'VB Elements'.
     *     @type string       $icon          Dashicon class. Default 'dashicons-editor-code'.
     *     @type string       $html          HTML template with {{param_name}} placeholders.
     *     @type string       $css           CSS rules (auto-scoped to the element wrapper).
     *     @type string       $html_template Alias for html. Takes precedence if both given.
     *     @type array|string $params        Parameter definitions array or JSON string.
     * }
     * @return array|WP_Error Array with 'post_id' key on success.
     */
    public static function create_element( $args ) {
        $args = self::normalize_args( $args );

        if ( empty( $args['name'] ) ) {
            return new WP_Error( 'missing_name', 'Element name is required.' );
        }
        if ( empty( $args['html_template'] ) && empty( $args['raw_html'] ) ) {
            return new WP_Error( 'missing_html', 'HTML content is required (provide "html" or "html_template").' );
        }

        $data = [
            'name'          => $args['name'],
            'slug'          => $args['slug'] ?? '',
            'description'   => $args['description'] ?? '',
            'category'      => $args['category'] ?? 'VB Elements',
            'icon'          => $args['icon'] ?? 'dashicons-editor-code',
            'raw_html'      => $args['raw_html'] ?? $args['html_template'] ?? '',
            'raw_css'       => $args['raw_css'] ?? '',
            'html_template' => $args['html_template'] ?? $args['raw_html'] ?? '',
            'params_json'   => $args['params_json'] ?? '[]',
        ];

        return self::manager()->save_element( $data );
    }

    /**
     * Update an existing element. Only provided fields are changed;
     * omitted fields keep their current values.
     *
     * @param int|string $id_or_slug Element post ID or shortcode slug.
     * @param array      $args       Fields to update (same keys as create_element).
     * @return array|WP_Error
     */
    public static function update_element( $id_or_slug, $args ) {
        $existing = self::resolve_element( $id_or_slug );
        if ( ! $existing ) {
            return new WP_Error( 'not_found', 'Element not found: ' . $id_or_slug );
        }

        $args = self::normalize_args( $args );

        $data = [
            'id'            => $existing['id'],
            'name'          => $args['name'] ?? $existing['name'],
            'slug'          => $args['slug'] ?? $existing['slug'],
            'description'   => $args['description'] ?? $existing['description'],
            'category'      => $args['category'] ?? $existing['category'],
            'icon'          => $args['icon'] ?? $existing['icon'],
            'raw_html'      => $args['raw_html'] ?? $existing['raw_html'],
            'raw_css'       => $args['raw_css'] ?? $existing['raw_css'],
            'html_template' => $args['html_template'] ?? $existing['html_template'],
            'params_json'   => $args['params_json'] ?? $existing['params_json'],
        ];

        return self::manager()->save_element( $data );
    }

    /**
     * Delete an element by ID or slug.
     *
     * @param int|string $id_or_slug
     * @return true|WP_Error
     */
    public static function delete_element( $id_or_slug ) {
        $element = self::resolve_element( $id_or_slug );
        if ( ! $element ) {
            return new WP_Error( 'not_found', 'Element not found: ' . $id_or_slug );
        }
        $result = self::manager()->delete_element( $element['id'] );
        return $result ? true : new WP_Error( 'delete_failed', 'Failed to delete element.' );
    }

    /**
     * Get a single element's full data by ID or slug.
     *
     * @param int|string $id_or_slug
     * @return array|null
     */
    public static function get_element( $id_or_slug ) {
        return self::resolve_element( $id_or_slug );
    }

    /**
     * List all registered elements.
     *
     * @return array[] Array of element data arrays.
     */
    public static function list_elements() {
        $posts    = self::manager()->get_all_elements();
        $elements = [];
        foreach ( $posts as $post ) {
            $el = self::manager()->get_element( $post->ID );
            if ( $el ) {
                $elements[] = $el;
            }
        }
        return $elements;
    }

    /**
     * Export an element as a portable array suitable for JSON serialization.
     *
     * @param int|string $id_or_slug
     * @return array|WP_Error
     */
    public static function export_element( $id_or_slug ) {
        $element = self::resolve_element( $id_or_slug );
        if ( ! $element ) {
            return new WP_Error( 'not_found', 'Element not found: ' . $id_or_slug );
        }

        return [
            'name'          => $element['name'],
            'slug'          => $element['slug'],
            'description'   => $element['description'],
            'category'      => $element['category'],
            'icon'          => $element['icon'],
            'html'          => $element['raw_html'],
            'css'           => $element['raw_css'],
            'html_template' => $element['html_template'],
            'params'        => $element['params'],
        ];
    }

    /**
     * Import an element from a portable array or JSON string.
     *
     * @param array|string $data Element data (as returned by export_element) or JSON string.
     * @return array|WP_Error
     */
    public static function import_element( $data ) {
        if ( is_string( $data ) ) {
            $data = json_decode( $data, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return new WP_Error( 'invalid_json', 'Failed to parse JSON: ' . json_last_error_msg() );
            }
        }

        if ( ! is_array( $data ) || empty( $data['name'] ) ) {
            return new WP_Error( 'invalid_data', 'Import data must include at least a "name" field.' );
        }

        return self::create_element( $data );
    }

    /**
     * Insert an element shortcode into a page's post_content.
     *
     * WPBakery requires elements to live inside [vc_row][vc_column]...[/vc_column][/vc_row].
     * This method automatically wraps the shortcode in that structure.
     *
     * @param int|string $page_id     Page post ID, slug, or title.
     * @param string     $element_tag Element shortcode tag or ID.
     * @param array      $atts        Shortcode attributes.
     * @param string     $position    'append' (default), 'prepend', or 'after:<shortcode_tag>'.
     * @return true|WP_Error
     */
    public static function place_on_page( $page_id, $element_tag, $atts = [], $position = 'append' ) {
        $page = self::resolve_page( $page_id );
        if ( ! $page ) {
            return new WP_Error( 'page_not_found', 'Page not found: ' . $page_id );
        }

        $element = self::resolve_element( $element_tag );
        if ( ! $element ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_tag );
        }

        $shortcode = self::build_shortcode( $element['slug'], $atts );
        $wrapped   = '[vc_row][vc_column]' . $shortcode . '[/vc_column][/vc_row]';
        $content   = $page->post_content;

        if ( $position === 'prepend' ) {
            $content = $wrapped . "\n" . $content;
        } elseif ( strpos( $position, 'after:' ) === 0 ) {
            $after_tag = substr( $position, 6 );
            $content   = self::insert_after_element( $content, $after_tag, $wrapped );
        } else {
            $content = rtrim( $content ) . "\n" . $wrapped;
        }

        $result = wp_update_post( [
            'ID'           => $page->ID,
            'post_content' => $content,
        ], true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return get_permalink( $page->ID );
    }

    /**
     * Remove an element's shortcode (and its wrapping [vc_row]) from a page.
     *
     * @param int|string $page_id     Page post ID, slug, or title.
     * @param string     $element_tag Element shortcode tag or ID.
     * @param int        $occurrence  Which occurrence to remove (1-based). Default 1 (first).
     * @return string|WP_Error Page permalink on success.
     */
    public static function remove_from_page( $page_id, $element_tag, $occurrence = 1 ) {
        $page = self::resolve_page( $page_id );
        if ( ! $page ) {
            return new WP_Error( 'page_not_found', 'Page not found: ' . $page_id );
        }

        $element = self::resolve_element( $element_tag );
        if ( ! $element ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_tag );
        }

        $tag     = $element['slug'];
        $content = $page->post_content;

        $pattern = '/\[vc_row\]\[vc_column\]\[' . preg_quote( $tag, '/' ) . '[^\]]*\]\[\/vc_column\]\[\/vc_row\]\n?/';

        $count   = 0;
        $new_content = preg_replace_callback( $pattern, function ( $match ) use ( $occurrence, &$count ) {
            $count++;
            return ( $count === $occurrence ) ? '' : $match[0];
        }, $content );

        if ( $count === 0 ) {
            return new WP_Error( 'not_on_page', "Shortcode [{$tag}] not found on page \"{$page->post_title}\" (ID: {$page->ID})." );
        }

        $new_content = trim( $new_content );

        $result = wp_update_post( [
            'ID'           => $page->ID,
            'post_content' => $new_content,
        ], true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return get_permalink( $page->ID );
    }

    /**
     * Render an element shortcode to HTML for preview/debugging.
     *
     * @param int|string $id_or_slug Element ID or slug.
     * @param array      $atts       Shortcode attribute overrides.
     * @return string|WP_Error Rendered HTML string.
     */
    public static function preview_element( $id_or_slug, $atts = [] ) {
        $element = self::resolve_element( $id_or_slug );
        if ( ! $element ) {
            return new WP_Error( 'not_found', 'Element not found: ' . $id_or_slug );
        }

        $shortcode = self::build_shortcode( $element['slug'], $atts );
        return do_shortcode( $shortcode );
    }

    /**
     * List available bundled templates from the templates/ directory.
     *
     * @return array[] Each entry includes template_slug plus all template fields.
     */
    public static function get_templates() {
        $dir = VB_ES_PATH . 'templates/';
        if ( ! is_dir( $dir ) ) {
            return [];
        }

        $templates = [];
        foreach ( glob( $dir . '*.json' ) as $file ) {
            $basename = basename( $file, '.json' );
            if ( strpos( $basename, '_' ) === 0 ) {
                continue;
            }
            $data = json_decode( file_get_contents( $file ), true );
            if ( is_array( $data ) && ! empty( $data['name'] ) ) {
                $data['template_slug'] = $basename;
                $templates[] = $data;
            }
        }

        return $templates;
    }

    /**
     * Get a single bundled template by its filename slug.
     *
     * @param string $slug Template filename without .json extension.
     * @return array|null
     */
    public static function get_template( $slug ) {
        $file = VB_ES_PATH . 'templates/' . sanitize_file_name( $slug ) . '.json';
        if ( ! file_exists( $file ) ) {
            return null;
        }
        $data = json_decode( file_get_contents( $file ), true );
        if ( is_array( $data ) && ! empty( $data['name'] ) ) {
            $data['template_slug'] = $slug;
            return $data;
        }
        return null;
    }

    /**
     * Create an element from a bundled template, with optional overrides.
     *
     * @param string $template_slug Template filename slug.
     * @param array  $overrides     Fields to override from the template defaults.
     *     Supports 'override_defaults' key: a map of param_name => new_default
     *     that merges into the template's params without replacing the full array.
     * @return array|WP_Error
     */
    public static function create_from_template( $template_slug, $overrides = [] ) {
        $template = self::get_template( $template_slug );
        if ( ! $template ) {
            return new WP_Error( 'template_not_found', 'Template not found: ' . $template_slug );
        }

        $override_defaults = [];
        if ( isset( $overrides['override_defaults'] ) ) {
            $override_defaults = $overrides['override_defaults'];
            unset( $overrides['override_defaults'] );
        }

        $args = array_merge( $template, $overrides );
        unset( $args['template_slug'] );

        if ( ! empty( $override_defaults ) && ! empty( $args['params'] ) ) {
            foreach ( $args['params'] as &$param ) {
                $pname = $param['param_name'] ?? '';
                if ( isset( $override_defaults[ $pname ] ) ) {
                    $param['default'] = $override_defaults[ $pname ];
                }
            }
            unset( $param );
        }

        return self::create_element( $args );
    }

    /**
     * Create multiple elements in a single call.
     *
     * @param array[] $elements Array of element definition arrays (same format as create_element).
     * @return array[] Results array, each with 'index', 'name', 'success', and 'post_id' or 'error'.
     */
    public static function create_batch( $elements ) {
        $results = [];
        foreach ( $elements as $i => $args ) {
            $result = self::create_element( $args );
            if ( is_wp_error( $result ) ) {
                $results[] = [
                    'index'   => $i,
                    'name'    => $args['name'] ?? '(unnamed)',
                    'success' => false,
                    'error'   => $result->get_error_message(),
                ];
            } else {
                $post_id = is_array( $result ) ? $result['post_id'] : $result;
                $results[] = [
                    'index'   => $i,
                    'name'    => $args['name'] ?? '(unnamed)',
                    'success' => true,
                    'post_id' => $post_id,
                ];
            }
        }
        return $results;
    }

    /**
     * Place multiple elements on a page in a single post_content update.
     *
     * @param int|string $page_id  Page post ID, slug, or title.
     * @param array      $elements Array of element entries. Each can be:
     *     - A string (shortcode slug)
     *     - An array with 'slug'/'element' and optional 'atts' keys
     * @param string     $position 'append' (default) or 'prepend'.
     * @return true|WP_Error
     */
    public static function place_batch( $page_id, $elements, $position = 'append' ) {
        $page = self::resolve_page( $page_id );
        if ( ! $page ) {
            return new WP_Error( 'page_not_found', 'Page not found: ' . $page_id );
        }

        $blocks = [];
        foreach ( $elements as $i => $entry ) {
            $tag  = is_string( $entry ) ? $entry : ( $entry['slug'] ?? $entry['element'] ?? '' );
            $atts = is_array( $entry ) ? ( $entry['atts'] ?? [] ) : [];

            if ( empty( $tag ) ) {
                return new WP_Error( 'invalid_entry', 'Element entry at index ' . $i . ' has no slug.' );
            }

            $element = self::resolve_element( $tag );
            if ( ! $element ) {
                return new WP_Error( 'element_not_found', 'Element not found: ' . $tag . ' (index ' . $i . ')' );
            }

            $shortcode = self::build_shortcode( $element['slug'], $atts );
            $blocks[]  = '[vc_row][vc_column]' . $shortcode . '[/vc_column][/vc_row]';
        }

        $new_content = implode( "\n", $blocks );
        $content     = $page->post_content;

        if ( $position === 'prepend' ) {
            $content = $new_content . "\n" . $content;
        } else {
            $content = rtrim( $content ) . "\n" . $new_content;
        }

        $result = wp_update_post( [
            'ID'           => $page->ID,
            'post_content' => $content,
        ], true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return get_permalink( $page->ID );
    }

    /**
     * Validate an element's HTML template for hardcoded text that should
     * be parameterized. Returns warnings for any text node longer than
     * 3 words that is not wrapped in a {{param}} placeholder.
     *
     * @param int|string $id_or_slug
     * @return array|WP_Error Array with 'valid', 'warnings', etc.
     */
    public static function validate_element( $id_or_slug ) {
        $element = self::resolve_element( $id_or_slug );
        if ( ! $element ) {
            return new WP_Error( 'not_found', 'Element not found: ' . $id_or_slug );
        }

        return self::build_validation_result(
            $element['name'],
            $element['slug'],
            $element['html_template'],
            $element['raw_css'] ?? '',
            $element['params']
        );
    }

    /**
     * Validate an unsaved element definition array.
     *
     * @param array $definition Element-like array with html_template/raw_css/params fields.
     * @return array|WP_Error
     */
    public static function validate_definition( $definition ) {
        if ( ! is_array( $definition ) ) {
            return new WP_Error( 'invalid_definition', 'Element definition must be an array.' );
        }

        return self::build_validation_result(
            $definition['name'] ?? 'Unsaved Element',
            $definition['slug'] ?? '',
            $definition['html_template'] ?? '',
            $definition['raw_css'] ?? '',
            $definition['params'] ?? []
        );
    }

    private static function build_validation_result( $name, $slug, $template, $raw_css, $params ) {
        $warnings = [];
        $template = (string) $template;
        $raw_css  = (string) $raw_css;
        $params   = is_array( $params ) ? $params : [];
        $param_names = self::collect_param_names( $params );
        $template_tokens = self::extract_placeholder_tokens( $template );
        $css_tokens = self::extract_placeholder_tokens( $raw_css );

        if ( empty( $params ) ) {
            $warnings[] = 'Element has no parameters defined. All user-facing text must be parameterized with {{param_name}} placeholders.';
        }

        foreach ( $params as $param ) {
            if ( ( $param['type'] ?? '' ) === 'param_group' && empty( $param['params'] ) ) {
                $warnings[] = 'Param group {{' . ( $param['param_name'] ?? 'items' ) . '}} has no nested params defined.';
            }
        }

        $stripped = preg_replace( '/\{\{[#\/]?\w+\}\}/', '', $template );

        preg_match_all( '/>([^<]+)</', $stripped, $text_matches );
        foreach ( $text_matches[1] as $text ) {
            $text = trim( $text );
            if ( empty( $text ) ) {
                continue;
            }
            $words = array_filter( preg_split( '/\s+/', $text ), function ( $w ) {
                return strlen( trim( $w ) ) > 0;
            } );
            if ( count( $words ) > 3 ) {
                $preview = mb_substr( $text, 0, 80 );
                if ( mb_strlen( $text ) > 80 ) {
                    $preview .= '...';
                }
                $warnings[] = 'Hardcoded text: "' . $preview . '" — should be a {{param}}.';
            }
        }

        preg_match_all( '/\b(alt|title|placeholder|aria-label)\s*=\s*"([^"]*)"/i', $template, $attr_matches, PREG_SET_ORDER );
        foreach ( $attr_matches as $match ) {
            $attr = strtolower( $match[1] );
            $val  = trim( $match[2] );
            if ( empty( $val ) || self::contains_placeholder( $val ) ) {
                continue;
            }
            $warnings[] = 'Hardcoded attribute ' . $attr . '="' . mb_substr( $val, 0, 80 ) . '" — should be a {{param}}.';
        }

        preg_match_all( '/\b(href|src)\s*=\s*"([^"]*)"/i', $template, $url_matches, PREG_SET_ORDER );
        foreach ( $url_matches as $match ) {
            $attr = strtolower( $match[1] );
            $val  = trim( $match[2] );
            if ( empty( $val ) || self::contains_placeholder( $val ) ) {
                continue;
            }
            $warnings[] = 'Hardcoded ' . $attr . '="' . mb_substr( $val, 0, 80 ) . '" — URLs and media sources should be parameterized.';
        }

        $css_without_placeholders = preg_replace( '/\{\{[^}]+\}\}/', '', $raw_css );
        preg_match_all( '/#[0-9a-fA-F]{3,8}\b/', $css_without_placeholders, $color_matches );
        foreach ( array_unique( $color_matches[0] ?? [] ) as $color ) {
            $warnings[] = 'Hardcoded CSS color ' . $color . ' — customizable colors should use {{param}} placeholders.';
        }

        foreach ( $template_tokens as $token ) {
            if ( ! in_array( $token, $param_names, true ) ) {
                $warnings[] = 'Template placeholder {{' . $token . '}} has no matching param definition.';
            }
        }

        foreach ( $css_tokens as $token ) {
            if ( ! in_array( $token, $param_names, true ) ) {
                $warnings[] = 'CSS placeholder {{' . $token . '}} has no matching param definition — it will render as literal text.';
            }
        }

        $used_tokens = array_unique( array_merge( $template_tokens, $css_tokens ) );
        foreach ( $param_names as $param_name ) {
            if ( ! in_array( $param_name, $used_tokens, true ) ) {
                $warnings[] = 'Param "' . $param_name . '" is defined but not referenced in the template or CSS.';
            }
        }

        return [
            'element'     => $name,
            'slug'        => $slug,
            'param_count' => count( $params ),
            'warnings'    => array_values( array_unique( $warnings ) ),
            'valid'       => empty( $warnings ),
        ];
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function normalize_args( $args ) {
        if ( isset( $args['html'] ) && ! isset( $args['raw_html'] ) ) {
            $args['raw_html'] = $args['html'];
            unset( $args['html'] );
        }
        if ( isset( $args['css'] ) && ! isset( $args['raw_css'] ) ) {
            $args['raw_css'] = $args['css'];
            unset( $args['css'] );
        }

        if ( empty( $args['html_template'] ) && ! empty( $args['raw_html'] ) ) {
            $args['html_template'] = $args['raw_html'];
        }
        if ( empty( $args['raw_html'] ) && ! empty( $args['html_template'] ) ) {
            $args['raw_html'] = $args['html_template'];
        }

        if ( isset( $args['params'] ) ) {
            $params = $args['params'];
            if ( is_array( $params ) ) {
                $args['params_json'] = wp_json_encode( $params );
            } elseif ( is_string( $params ) ) {
                $args['params_json'] = $params;
            }
            unset( $args['params'] );
        }

        return $args;
    }

    private static function collect_param_names( $params ) {
        $names = [];

        foreach ( $params as $param ) {
            if ( ! is_array( $param ) ) {
                continue;
            }

            $name = sanitize_key( $param['param_name'] ?? '' );
            if ( $name !== '' ) {
                $names[] = $name;
            }

            if ( ( $param['type'] ?? '' ) === 'param_group' && ! empty( $param['params'] ) && is_array( $param['params'] ) ) {
                $names = array_merge( $names, self::collect_param_names( $param['params'] ) );
            }
        }

        return array_values( array_unique( $names ) );
    }

    private static function extract_placeholder_tokens( $content ) {
        preg_match_all( '/\{\{[#\/]?(\w+)\}\}/', (string) $content, $matches );

        return array_values( array_unique( $matches[1] ?? [] ) );
    }

    private static function contains_placeholder( $value ) {
        return (bool) preg_match( '/\{\{[^}]+\}\}/', (string) $value );
    }

    private static function resolve_page( $page_id ) {
        if ( is_numeric( $page_id ) ) {
            $page = get_post( (int) $page_id );
            if ( $page && ! in_array( $page->post_status, [ 'trash', 'auto-draft' ], true ) ) {
                return $page;
            }
            return null;
        }

        $page = get_page_by_path( (string) $page_id, OBJECT, [ 'page', 'post' ] );
        if ( $page ) {
            return $page;
        }

        $query = new WP_Query( [
            'post_type'      => [ 'page', 'post' ],
            'title'          => (string) $page_id,
            'post_status'    => 'any',
            'posts_per_page' => 1,
        ] );
        return $query->have_posts() ? $query->posts[0] : null;
    }

    private static function build_shortcode( $tag, $atts = [] ) {
        if ( empty( $atts ) ) {
            return '[' . $tag . ']';
        }
        $parts = [];
        foreach ( $atts as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = urlencode( wp_json_encode( $value ) );
            }
            $parts[] = sanitize_key( $key ) . '="' . esc_attr( $value ) . '"';
        }
        return '[' . $tag . ' ' . implode( ' ', $parts ) . ']';
    }

    /**
     * Insert $new_block after the [/vc_row] that contains $after_tag.
     */
    private static function insert_after_element( $content, $after_tag, $new_block ) {
        $tag_pos = strpos( $content, '[' . $after_tag );
        if ( $tag_pos === false ) {
            return rtrim( $content ) . "\n" . $new_block;
        }

        $close_row = strpos( $content, '[/vc_row]', $tag_pos );
        if ( $close_row === false ) {
            return rtrim( $content ) . "\n" . $new_block;
        }

        $insert_pos = $close_row + strlen( '[/vc_row]' );
        return substr( $content, 0, $insert_pos ) . "\n" . $new_block . substr( $content, $insert_pos );
    }
}
