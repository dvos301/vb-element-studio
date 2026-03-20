<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VB_ES_VC_Registrar {

    private $element_manager;

    public function __construct( VB_ES_Element_Manager $element_manager ) {
        $this->element_manager = $element_manager;
        add_action( 'vc_before_init', [ $this, 'register_elements' ] );
    }

    public function register_elements() {
        $elements = $this->element_manager->get_all_elements();

        foreach ( $elements as $el ) {
            $element_data = $this->element_manager->get_element( $el->ID );
            if ( ! $element_data ) {
                continue;
            }

            $params = $this->build_params_array( $element_data );

            vc_map([
                'name'        => $element_data['name'],
                'base'        => $element_data['slug'],
                'description' => $element_data['description'],
                'category'    => $element_data['category'] ?: 'VB Elements',
                'icon'        => $element_data['icon'] ?: 'dashicons dashicons-editor-code',
                'params'      => $params,
            ]);
        }
    }

    private function build_params_array( $element_data ) {
        $params = [];

        foreach ( $element_data['params'] as $param ) {
            $vc_param = [
                'type'        => $param['type'],
                'heading'     => $param['heading'] ?? $param['param_name'],
                'param_name'  => $param['param_name'],
                'description' => $param['description'] ?? '',
            ];

            if ( $param['type'] === 'param_group' && ! empty( $param['params'] ) ) {
                $group_params = [];
                foreach ( $param['params'] as $sub_param ) {
                    $sub_vc = [
                        'type'        => $sub_param['type'] ?? 'textfield',
                        'heading'     => $sub_param['heading'] ?? $sub_param['param_name'],
                        'param_name'  => $sub_param['param_name'],
                        'description' => $sub_param['description'] ?? '',
                    ];
                    if ( isset( $sub_param['default'] ) && $sub_param['default'] !== '' ) {
                        $sub_vc['std'] = $sub_param['default'];
                    }
                    if ( ( $sub_param['type'] ?? '' ) === 'dropdown' && ! empty( $sub_param['options'] ) ) {
                        $opts_str = is_array( $sub_param['options'] ) ? implode( ',', $sub_param['options'] ) : $sub_param['options'];
                        $opts = array_map( 'trim', explode( ',', $opts_str ) );
                        $val_arr = [];
                        foreach ( $opts as $o ) {
                            $val_arr[ $o ] = $o;
                        }
                        $sub_vc['value'] = $val_arr;
                    }
                    if ( ( $sub_param['type'] ?? '' ) === 'checkbox' ) {
                        $sub_vc['value'] = [ 'Yes' => 'true' ];
                    }
                    $group_params[] = $sub_vc;
                }
                $vc_param['params'] = $group_params;

                if ( isset( $param['default'] ) && is_array( $param['default'] ) ) {
                    $vc_param['value'] = urlencode( wp_json_encode( $param['default'], JSON_UNESCAPED_UNICODE ) );
                }

                $params[] = $vc_param;
                continue;
            }

            if ( isset( $param['default'] ) && $param['default'] !== '' ) {
                $vc_param['std'] = $param['default'];
            }

            if ( $param['type'] === 'dropdown' && ! empty( $param['options'] ) ) {
                $options_str = is_array( $param['options'] ) ? implode( ',', $param['options'] ) : $param['options'];
                $options = array_map( 'trim', explode( ',', $options_str ) );
                $value_array = [];
                foreach ( $options as $opt ) {
                    $value_array[ $opt ] = $opt;
                }
                $vc_param['value'] = $value_array;
            }

            if ( $param['type'] === 'checkbox' ) {
                $vc_param['value'] = [ 'Yes' => 'true' ];
            }

            $params[] = $vc_param;
        }

        $params[] = [
            'type'        => 'animation_style',
            'heading'     => 'CSS Animation',
            'param_name'  => 'css_animation',
            'description' => 'Select a CSS animation for the element.',
        ];

        $params[] = [
            'type'        => 'textfield',
            'heading'     => 'Extra Class Name',
            'param_name'  => 'el_class',
            'description' => 'Add an extra class name to the wrapper element.',
        ];

        return $params;
    }
}
