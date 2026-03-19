<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VB_ES_Shortcode_Handler {

    private $element_manager;

    // V2: Collect all scoped CSS strings during the render pass and output them
    // once in wp_head to avoid duplicate <style> blocks if an element is used
    // multiple times on the same page.
    private $rendered_styles = [];
    private $enqueued_fonts = [];

    public function __construct( VB_ES_Element_Manager $element_manager ) {
        $this->element_manager = $element_manager;
        add_action( 'init', [ $this, 'register_shortcodes' ], 20 );
        add_action( 'wp_head', [ $this, 'output_font_links' ], 5 );
    }

    public function register_shortcodes() {
        $elements = $this->element_manager->get_all_elements();

        foreach ( $elements as $el ) {
            $base_tag = get_post_meta( $el->ID, '_vb_base_tag', true );
            if ( ! empty( $base_tag ) ) {
                add_shortcode( $base_tag, [ $this, 'render_shortcode' ] );
            }
        }
    }

    public function render_shortcode( $atts, $content, $tag ) {
        $element = $this->element_manager->get_element_by_slug( $tag );
        if ( ! $element ) {
            return '';
        }

        $html_template = $element['html_template'];
        $params        = $element['params'];
        $scoped_css    = $element['scoped_css'];
        $scope_id      = $element['scope_id'];

        $html_template = $this->extract_font_links( $html_template );
        $html_template = $this->strip_link_tags( $html_template );

        $defaults = [];
        $param_types = [];
        foreach ( $params as $param ) {
            $type = $param['type'] ?? 'textfield';
            if ( $type === 'param_group' ) {
                $default_val = $param['default'] ?? [];
                $defaults[ $param['param_name'] ] = is_array( $default_val )
                    ? urlencode( wp_json_encode( $default_val ) )
                    : $default_val;
            } else {
                $defaults[ $param['param_name'] ] = $param['default'] ?? '';
            }
            $param_types[ $param['param_name'] ] = $type;
        }
        $defaults['css_animation'] = '';
        $defaults['el_class'] = '';

        $atts = shortcode_atts( $defaults, $atts, $tag );

        $output = $this->render_repeater_blocks( $html_template, $params, $atts );

        foreach ( $params as $param ) {
            $name  = $param['param_name'];
            $type  = $param['type'] ?? 'textfield';
            if ( $type === 'param_group' ) {
                continue;
            }
            $value = $atts[ $name ] ?? '';

            $value = $this->sanitize_param_value( $value, $type );
            $output = str_replace( '{{' . $name . '}}', $value, $output );
        }

        $allow_unfiltered = get_option( 'vb_es_allow_unfiltered_html', '0' ) === '1';
        if ( ! $allow_unfiltered || ! current_user_can( 'unfiltered_html' ) ) {
            $output = wp_kses( $output, VB_ES_Element_Manager::allowed_html() );
        }

        $wrapper_classes = [];
        if ( ! empty( $atts['css_animation'] ) ) {
            $wrapper_classes[] = 'wpb_animate_when_almost_visible wpb_' . sanitize_html_class( $atts['css_animation'] );
        }
        if ( ! empty( $atts['el_class'] ) ) {
            $wrapper_classes[] = sanitize_html_class( $atts['el_class'] );
        }

        $class_attr = ! empty( $wrapper_classes ) ? ' class="' . esc_attr( implode( ' ', $wrapper_classes ) ) . '"' : '';

        $html = '';

        if ( ! empty( $scoped_css ) && ! isset( $this->rendered_styles[ $scope_id ] ) ) {
            $html .= "<style>\n" . $scoped_css . "\n</style>\n";
            $this->rendered_styles[ $scope_id ] = true;
        }

        $html .= '<div id="' . esc_attr( $scope_id ) . '"' . $class_attr . '>' . "\n";
        $html .= $output . "\n";
        $html .= '</div>';

        return $html;
    }

    /**
     * Extract Google Fonts and other stylesheet <link> tags from the template
     * and queue them for proper output in <head>.
     */
    private function extract_font_links( $html ) {
        $pattern = '/<link[^>]+href=["\']([^"\']*fonts\.googleapis\.com[^"\']*)["\'][^>]*\/?>/i';
        if ( preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $url = $match[1];
                if ( ! in_array( $url, $this->enqueued_fonts, true ) ) {
                    $this->enqueued_fonts[] = $url;
                }
                $html = str_replace( $match[0], '', $html );
            }
        }

        $preconnect = '/<link[^>]+rel=["\']preconnect["\'][^>]*\/?>/i';
        $html = preg_replace( $preconnect, '', $html );

        return $html;
    }

    /**
     * Strip remaining <link rel="stylesheet"> tags that reference
     * external CSS files (these won't resolve on the WP server).
     */
    private function strip_link_tags( $html ) {
        return preg_replace( '/<link[^>]+rel=["\']stylesheet["\'][^>]*\/?>/i', '', $html );
    }

    /**
     * Output collected Google Font links in wp_head so they load properly.
     */
    public function output_font_links() {
        foreach ( $this->enqueued_fonts as $url ) {
            echo '<link rel="preconnect" href="https://fonts.googleapis.com" />' . "\n";
            echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />' . "\n";
            echo '<link href="' . esc_url( $url ) . '" rel="stylesheet" />' . "\n";
            break; // preconnect only needs to be output once
        }
        $seen_preconnect = false;
        foreach ( $this->enqueued_fonts as $url ) {
            if ( ! $seen_preconnect ) {
                $seen_preconnect = true;
                continue;
            }
            echo '<link href="' . esc_url( $url ) . '" rel="stylesheet" />' . "\n";
        }
    }

    /**
     * Expand {{#param_name}}...{{/param_name}} repeater blocks using
     * WPBakery param_group data (URL-encoded JSON array of objects).
     */
    private function render_repeater_blocks( $template, $params, $atts ) {
        return preg_replace_callback(
            '/\{\{#(\w+)\}\}(.*?)\{\{\/\1\}\}/s',
            function ( $matches ) use ( $params, $atts ) {
                $param_name     = $matches[1];
                $inner_template = $matches[2];

                $param_def = null;
                foreach ( $params as $p ) {
                    if ( $p['param_name'] === $param_name ) {
                        $param_def = $p;
                        break;
                    }
                }

                if ( ! $param_def || ( $param_def['type'] ?? '' ) !== 'param_group' ) {
                    return '';
                }

                $items = $this->decode_param_group_value( $atts[ $param_name ] ?? '' );
                if ( empty( $items ) ) {
                    return '';
                }

                $sub_types = [];
                foreach ( $param_def['params'] ?? [] as $sp ) {
                    $sub_types[ $sp['param_name'] ] = $sp['type'] ?? 'textfield';
                }

                $output = '';
                foreach ( $items as $item ) {
                    $rendered = $inner_template;
                    foreach ( $item as $key => $val ) {
                        $type = $sub_types[ $key ] ?? 'textfield';
                        $val  = $this->sanitize_param_value( (string) $val, $type );
                        $rendered = str_replace( '{{' . $key . '}}', $val, $rendered );
                    }
                    $output .= $rendered;
                }

                return $output;
            },
            $template
        );
    }

    private function decode_param_group_value( $value ) {
        if ( empty( $value ) ) {
            return [];
        }
        if ( is_array( $value ) ) {
            return $value;
        }

        $decoded = json_decode( urldecode( $value ), true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }

        $decoded = json_decode( $value, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }

        if ( function_exists( 'vc_param_group_parse_atts' ) ) {
            $result = vc_param_group_parse_atts( $value );
            if ( is_array( $result ) ) {
                return $result;
            }
        }

        return [];
    }

    private function sanitize_param_value( $value, $type ) {
        switch ( $type ) {
            case 'textfield':
            case 'dropdown':
                return sanitize_text_field( $value );

            case 'textarea':
                return sanitize_textarea_field( $value );

            case 'colorpicker':
                $color = sanitize_hex_color( $value );
                return $color ? $color : sanitize_text_field( $value );

            case 'attach_image':
                $attachment_id = absint( $value );
                if ( $attachment_id > 0 ) {
                    $url = wp_get_attachment_url( $attachment_id );
                    return $url ? esc_url( $url ) : '';
                }
                return esc_url( $value );

            case 'checkbox':
                return $value === 'true' || $value === '1' ? 'true' : 'false';

            default:
                return sanitize_text_field( $value );
        }
    }
}
