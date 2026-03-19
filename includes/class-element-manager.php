<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VB_ES_Element_Manager {

    public function __construct() {
        add_action( 'init', [ $this, 'register_post_type' ] );
    }

    public static function register_post_type() {
        register_post_type( 'vb_element', [
            'labels' => [
                'name'          => 'VB Elements',
                'singular_name' => 'VB Element',
            ],
            'public'       => false,
            'show_ui'      => false,
            'show_in_menu' => false,
            'supports'     => [ 'title' ],
            'rewrite'      => false,
            'query_var'    => false,
        ]);
    }

    public function save_element( $data ) {
        $raw_html_in      = (string) ( $data['raw_html'] ?? '' );
        $raw_css_in       = (string) ( $data['raw_css'] ?? '' );
        $html_template_in = (string) ( $data['html_template'] ?? '' );

        $sanitized = $this->sanitize_component_input( $raw_html_in, $html_template_in, $raw_css_in );
        $params = $this->decode_params_json( $data['params_json'] ?? '[]' );
        if ( is_wp_error( $params ) ) {
            return $params;
        }

        $post_data = [
            'post_type'   => 'vb_element',
            'post_status' => 'publish',
            'post_title'  => sanitize_text_field( $data['name'] ),
        ];

        if ( ! empty( $data['id'] ) ) {
            $post_data['ID'] = absint( $data['id'] );
            $post_id = wp_update_post( $post_data, true );
        } else {
            $post_id = wp_insert_post( $post_data, true );
        }

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        $base_tag = $this->generate_base_tag( $data, $post_id );
        update_post_meta( $post_id, '_vb_base_tag', $base_tag );

        update_post_meta( $post_id, '_vb_description', sanitize_textarea_field( $data['description'] ?? '' ) );
        update_post_meta( $post_id, '_vb_icon', sanitize_text_field( $data['icon'] ?? 'dashicons-editor-code' ) );
        update_post_meta( $post_id, '_vb_category', sanitize_text_field( $data['category'] ?? 'VB Elements' ) );
        update_post_meta( $post_id, '_vb_raw_html', wp_kses( $sanitized['raw_html'], self::allowed_html() ) );
        update_post_meta( $post_id, '_vb_raw_css', (string) $sanitized['raw_css'] );
        update_post_meta( $post_id, '_vb_html_template', wp_kses( $sanitized['html_template'], self::allowed_html() ) );
        update_post_meta( $post_id, '_vb_sanitization_notes', wp_json_encode( $sanitized['notes'] ) );
        update_post_meta( $post_id, '_vb_params', wp_json_encode( $params ) );

        $scope_id = get_post_meta( $post_id, '_vb_scope_id', true );
        if ( empty( $scope_id ) ) {
            $scope_id = 'vb-el-' . substr( md5( $post_id . time() ), 0, 6 );
            update_post_meta( $post_id, '_vb_scope_id', $scope_id );
        }

        $raw_css = $sanitized['raw_css'];
        if ( ! empty( $raw_css ) ) {
            $scoper = new VB_ES_CSS_Scoper();
            $scoped_css = $scoper->scope( $raw_css, $scope_id );
            update_post_meta( $post_id, '_vb_scoped_css', $scoped_css );
        } else {
            update_post_meta( $post_id, '_vb_scoped_css', '' );
        }

        return [
            'post_id'            => $post_id,
            'sanitization_notes' => $sanitized['notes'],
        ];
    }

    public function get_element( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'vb_element' ) {
            return null;
        }

        $params_raw = get_post_meta( $post_id, '_vb_params', true );
        $params = json_decode( $params_raw, true );
        if ( ! is_array( $params ) ) {
            $params = [];
        }

        $base_tag = get_post_meta( $post_id, '_vb_base_tag', true );

        return [
            'id'            => $post->ID,
            'name'          => $post->post_title,
            'slug'          => $base_tag ?: $post->post_name,
            'description'   => get_post_meta( $post_id, '_vb_description', true ),
            'icon'          => get_post_meta( $post_id, '_vb_icon', true ),
            'category'      => get_post_meta( $post_id, '_vb_category', true ),
            'raw_html'      => get_post_meta( $post_id, '_vb_raw_html', true ),
            'raw_css'       => get_post_meta( $post_id, '_vb_raw_css', true ),
            'html_template' => get_post_meta( $post_id, '_vb_html_template', true ),
            'scoped_css'    => get_post_meta( $post_id, '_vb_scoped_css', true ),
            'scope_id'      => get_post_meta( $post_id, '_vb_scope_id', true ),
            'params'        => $params,
            'params_json'   => $params_raw ?: '[]',
            'sanitization_notes' => json_decode( (string) get_post_meta( $post_id, '_vb_sanitization_notes', true ), true ) ?: [],
        ];
    }

    public function delete_element( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'vb_element' ) {
            return false;
        }
        return wp_delete_post( $post_id, true );
    }

    public function get_all_elements() {
        $query = new WP_Query([
            'post_type'      => 'vb_element',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        return $query->posts;
    }

    public function get_element_by_slug( $slug ) {
        $query = new WP_Query([
            'post_type'      => 'vb_element',
            'post_status'    => 'publish',
            'meta_key'       => '_vb_base_tag',
            'meta_value'     => $slug,
            'posts_per_page' => 1,
        ]);

        if ( $query->have_posts() ) {
            return $this->get_element( $query->posts[0]->ID );
        }
        return null;
    }

    private function generate_base_tag( $data, $post_id ) {
        if ( ! empty( $data['slug'] ) ) {
            $tag = sanitize_key( $data['slug'] );
        } else {
            $tag = sanitize_key( str_replace( '-', '_', sanitize_title( $data['name'] ) ) );
        }

        if ( strpos( $tag, 'vb_' ) !== 0 ) {
            $tag = 'vb_' . $tag;
        }

        return $tag;
    }

    private function decode_params_json( $params_json ) {
        $params_json = trim( (string) wp_unslash( $params_json ) );
        if ( $params_json === '' ) {
            return [];
        }

        $params = json_decode( $params_json, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'invalid_params_json', 'Parameters JSON is invalid: ' . json_last_error_msg() );
        }

        if ( ! is_array( $params ) ) {
            return new WP_Error( 'invalid_params_json', 'Parameters JSON must decode to an array.' );
        }

        return $this->sanitize_params_schema( $params );
    }

    private function sanitize_params_schema( $params ) {
        $clean_params = [];

        foreach ( $params as $param ) {
            if ( ! is_array( $param ) ) {
                continue;
            }

            $param_name = sanitize_key( $param['param_name'] ?? '' );
            if ( $param_name === '' ) {
                continue;
            }

            $type = sanitize_key( $param['type'] ?? 'textfield' );
            if ( ! in_array( $type, self::allowed_param_types(), true ) ) {
                $type = 'textfield';
            }

            $clean_param = [
                'param_name'  => $param_name,
                'type'        => $type,
                'heading'     => sanitize_text_field( $param['heading'] ?? $param_name ),
                'description' => sanitize_text_field( $param['description'] ?? '' ),
            ];

            if ( $type === 'param_group' ) {
                $child_params = $param['params'] ?? [];
                if ( ! is_array( $child_params ) ) {
                    $child_params = [];
                }

                $default_items = $this->normalize_param_group_default( $param['default'] ?? [] );
                $clean_param['default'] = $default_items;
                $clean_param['params']  = $this->sanitize_params_schema( $child_params );
                $clean_params[] = $clean_param;
                continue;
            }

            if ( $type === 'dropdown' ) {
                $clean_param['options'] = $this->sanitize_dropdown_options( $param['options'] ?? '' );
            }

            $clean_param['default'] = $this->sanitize_param_default( $param['default'] ?? '', $type );
            $clean_params[] = $clean_param;
        }

        return $clean_params;
    }

    private function normalize_param_group_default( $default ) {
        if ( is_string( $default ) ) {
            $decoded = json_decode( wp_unslash( $default ), true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                $default = $decoded;
            }
        }

        if ( ! is_array( $default ) ) {
            return [];
        }

        $items = [];
        foreach ( $default as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $clean_item = [];
            foreach ( $item as $key => $value ) {
                $clean_key = sanitize_key( $key );
                if ( $clean_key === '' ) {
                    continue;
                }
                $clean_item[ $clean_key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
            }

            if ( ! empty( $clean_item ) ) {
                $items[] = $clean_item;
            }
        }

        return $items;
    }

    private function sanitize_dropdown_options( $options ) {
        if ( is_array( $options ) ) {
            $options = implode( ',', $options );
        }

        $parts = array_filter( array_map( 'trim', explode( ',', (string) $options ) ), 'strlen' );
        $parts = array_map( 'sanitize_text_field', $parts );

        return implode( ',', $parts );
    }

    private function sanitize_param_default( $default, $type ) {
        switch ( $type ) {
            case 'textarea':
                return sanitize_textarea_field( (string) $default );

            case 'colorpicker':
                $color = sanitize_hex_color( (string) $default );
                return $color ? $color : sanitize_text_field( (string) $default );

            case 'checkbox':
                return ( $default === true || $default === 'true' || $default === '1' ) ? 'true' : 'false';

            case 'attach_image':
            case 'dropdown':
            case 'textfield':
            default:
                return sanitize_text_field( (string) $default );
        }
    }

    private static function allowed_param_types() {
        return [ 'textfield', 'textarea', 'colorpicker', 'attach_image', 'dropdown', 'checkbox', 'param_group' ];
    }

    public static function allowed_html() {
        $svg_attrs = [
            'viewbox'            => true,
            'xmlns'              => true,
            'fill'               => true,
            'stroke'             => true,
            'stroke-width'       => true,
            'stroke-linecap'     => true,
            'stroke-linejoin'    => true,
            'stroke-dasharray'   => true,
            'stroke-dashoffset'  => true,
            'stroke-opacity'     => true,
            'fill-opacity'       => true,
            'fill-rule'          => true,
            'clip-rule'          => true,
            'opacity'            => true,
            'transform'          => true,
            'd'                  => true,
            'cx'                 => true,
            'cy'                 => true,
            'r'                  => true,
            'rx'                 => true,
            'ry'                 => true,
            'x'                  => true,
            'y'                  => true,
            'x1'                 => true,
            'y1'                 => true,
            'x2'                 => true,
            'y2'                 => true,
            'width'              => true,
            'height'             => true,
            'points'             => true,
            'class'              => true,
            'id'                 => true,
            'style'              => true,
            'preserveaspectratio' => true,
        ];

        $allowed = wp_kses_allowed_html( 'post' );

        $allowed['svg']      = $svg_attrs;
        $allowed['path']     = $svg_attrs;
        $allowed['circle']   = $svg_attrs;
        $allowed['ellipse']  = $svg_attrs;
        $allowed['line']     = $svg_attrs;
        $allowed['rect']     = $svg_attrs;
        $allowed['polygon']  = $svg_attrs;
        $allowed['polyline'] = $svg_attrs;
        $allowed['g']        = $svg_attrs;
        $allowed['defs']     = $svg_attrs;
        $allowed['use']      = array_merge( $svg_attrs, [ 'href' => true, 'xlink:href' => true ] );
        $allowed['symbol']   = $svg_attrs;
        $allowed['text']     = $svg_attrs;
        $allowed['tspan']    = $svg_attrs;
        $allowed['clippath'] = $svg_attrs;
        $allowed['mask']     = $svg_attrs;
        $allowed['linearGradient'] = $svg_attrs;
        $allowed['radialGradient'] = $svg_attrs;
        $allowed['stop']     = array_merge( $svg_attrs, [ 'offset' => true, 'stop-color' => true, 'stop-opacity' => true ] );

        $allowed['section']  = [ 'class' => true, 'id' => true, 'style' => true ];
        $allowed['link']     = [ 'rel' => true, 'href' => true, 'type' => true, 'media' => true ];

        return $allowed;
    }

    private function sanitize_component_input( $raw_html, $html_template, $raw_css ) {
        $notes = [];

        $clean_raw_html = $this->sanitize_html_fragment( (string) $raw_html, $notes, 'raw HTML' );
        $clean_template = $this->sanitize_html_fragment( (string) $html_template, $notes, 'HTML template' );
        $clean_css      = $this->sanitize_css_fragment( (string) $raw_css, $notes );

        return [
            'raw_html'      => $clean_raw_html,
            'html_template' => $clean_template,
            'raw_css'       => $clean_css,
            'notes'         => array_values( array_unique( $notes ) ),
        ];
    }

    private function sanitize_html_fragment( $html, &$notes, $label ) {
        $before = $html;

        $html = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $html );
        if ( $html !== $before ) {
            $notes[] = sprintf( 'Removed <script> tags from %s.', $label );
            $before = $html;
        }

        $html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $html );
        if ( $html !== $before ) {
            $notes[] = sprintf( 'Removed inline <style> blocks from %s (paste CSS into the CSS field).', $label );
            $before = $html;
        }

        $html = preg_replace( '/<link\b[^>]*rel\s*=\s*["\'](?:preconnect|stylesheet)["\'][^>]*\/?>/is', '', $html );
        if ( $html !== $before ) {
            $notes[] = sprintf( 'Removed <link rel="preconnect|stylesheet"> tags from %s.', $label );
            $before = $html;
        }

        $html = preg_replace( '/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html );
        if ( $html !== $before ) {
            $notes[] = sprintf( 'Removed inline event handler attributes (onclick/onerror/etc.) from %s.', $label );
        }

        return trim( (string) $html );
    }

    private function sanitize_css_fragment( $css, &$notes ) {
        $before = $css;

        $css = preg_replace( '/@import\s+url\((.*?)\)\s*;?/i', '', $css );
        if ( $css !== $before ) {
            $notes[] = 'Removed CSS @import rules (use template font links or theme enqueue instead).';
            $before = $css;
        }

        $css = preg_replace( '/@charset\s+["\'][^"\']*["\']\s*;?/i', '', $css );
        if ( $css !== $before ) {
            $notes[] = 'Removed CSS @charset declarations.';
        }

        return trim( (string) $css );
    }
}
