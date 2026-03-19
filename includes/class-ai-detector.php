<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VB_ES_AI_Detector {

    private const ANTHROPIC_API_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_API_VERSION  = '2023-06-01';
    private const OPENAI_API_ENDPOINT    = 'https://api.openai.com/v1/responses';
    private const GEMINI_API_ENDPOINT    = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s';

    public static function get_provider_labels() {
        return [
            'anthropic' => 'Anthropic',
            'openai'    => 'OpenAI',
            'gemini'    => 'Google Gemini',
        ];
    }

    public static function get_supported_models() {
        return [
            'anthropic' => [
                'claude-sonnet-4-6'          => 'Claude Sonnet 4.6',
                'claude-opus-4-6'            => 'Claude Opus 4.6',
                'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5',
            ],
            'openai' => [
                'gpt-5.4-mini' => 'GPT-5.4 mini',
                'gpt-5.4'      => 'GPT-5.4',
                'gpt-5.4-nano' => 'GPT-5.4 nano',
            ],
            'gemini' => [
                'gemini-2.5-flash'      => 'Gemini 2.5 Flash',
                'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite',
                'gemini-2.5-pro'        => 'Gemini 2.5 Pro',
            ],
        ];
    }

    public static function get_default_model( $provider ) {
        $provider = sanitize_key( $provider );
        $models = self::get_supported_models();
        if ( ! isset( $models[ $provider ] ) || empty( $models[ $provider ] ) ) {
            return 'claude-sonnet-4-6';
        }

        return (string) array_key_first( $models[ $provider ] );
    }

    public static function get_selected_provider() {
        $provider = sanitize_key( get_option( 'vb_es_ai_provider', 'anthropic' ) );
        $labels = self::get_provider_labels();
        if ( ! isset( $labels[ $provider ] ) ) {
            return 'anthropic';
        }

        return $provider;
    }

    public static function get_selected_model( $provider = '' ) {
        if ( empty( $provider ) ) {
            $provider = self::get_selected_provider();
        }

        $provider = sanitize_key( $provider );
        $custom_model = trim( (string) get_option( 'vb_es_ai_custom_model', '' ) );
        if ( $custom_model !== '' ) {
            return sanitize_text_field( $custom_model );
        }

        $models = self::get_supported_models();
        $stored = sanitize_text_field( (string) get_option( 'vb_es_ai_model', '' ) );
        if ( isset( $models[ $provider ][ $stored ] ) ) {
            return $stored;
        }

        return self::get_default_model( $provider );
    }

    public static function get_api_key_for_provider( $provider ) {
        switch ( sanitize_key( $provider ) ) {
            case 'openai':
                return trim( (string) get_option( 'vb_es_openai_api_key', '' ) );
            case 'gemini':
                return trim( (string) get_option( 'vb_es_gemini_api_key', '' ) );
            case 'anthropic':
            default:
                return trim( (string) get_option( 'vb_es_anthropic_api_key', '' ) );
        }
    }

    public static function has_valid_ai_configuration() {
        $provider = self::get_selected_provider();
        $key = self::get_api_key_for_provider( $provider );
        return $key !== '';
    }

    public function __construct() {
        add_action( 'wp_ajax_vb_es_detect_params', [ $this, 'ajax_detect_params' ] );
        add_action( 'wp_ajax_vb_es_ingest_snippet', [ $this, 'ajax_ingest_snippet' ] );
    }

    public function ajax_detect_params() {
        check_ajax_referer( 'vb_es_ajax_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $provider = self::get_selected_provider();
        $provider_labels = self::get_provider_labels();
        $provider_label = $provider_labels[ $provider ] ?? 'Selected provider';
        $model = self::get_selected_model( $provider );
        $api_key = self::get_api_key_for_provider( $provider );

        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => $provider_label . ' API key is not configured. Please set it in VB Element Studio Settings.' ] );
        }

        $html = wp_unslash( $_POST['html'] ?? '' );
        $css  = wp_unslash( $_POST['css'] ?? '' );

        if ( empty( $html ) ) {
            wp_send_json_error( [ 'message' => 'HTML content is required for parameter detection.' ] );
        }

        $result = $this->detect( $html, $css, $provider, $model, $api_key );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    public function ajax_ingest_snippet() {
        check_ajax_referer( 'vb_es_ajax_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $provider = self::get_selected_provider();
        $provider_labels = self::get_provider_labels();
        $provider_label = $provider_labels[ $provider ] ?? 'Selected provider';
        $model = self::get_selected_model( $provider );
        $api_key = self::get_api_key_for_provider( $provider );

        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => $provider_label . ' API key is not configured. Please set it in VB Element Studio Settings.' ] );
        }

        $snippet = wp_unslash( $_POST['snippet'] ?? '' );
        if ( trim( $snippet ) === '' ) {
            wp_send_json_error( [ 'message' => 'Paste combined HTML/CSS before analyzing the snippet.' ] );
        }

        $result = $this->ingest_snippet( $snippet, $provider, $model, $api_key );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    public function detect( $html, $css, $provider, $model, $api_key ) {
        $system_prompt = <<<'PROMPT'
You are a WordPress developer assistant. Your job is to analyse HTML and CSS for a UI component and identify which parts should be editable parameters in a page builder.

You must respond ONLY with a valid JSON object — no preamble, no explanation, no markdown code fences. Just raw JSON.

The JSON must have this exact structure:
{
  "tokenised_html": "the full HTML with {{param_name}} tokens substituted in",
  "params": [
    {
      "param_name": "snake_case_name",
      "type": "textfield|textarea|colorpicker|attach_image|dropdown|checkbox|param_group",
      "heading": "Human Readable Label",
      "description": "Brief description of what this controls",
      "default": "default value as string"
    }
  ]
}

Rules for identifying parameters:
- Visible text content (headings, paragraphs, button labels) → textfield or textarea
- href values on links → textfield
- Hardcoded hex colours in inline styles or CSS → colorpicker
- src values on img tags → attach_image
- CSS class-driven variants if apparent → dropdown
- Repeating cards, features, FAQs, team members, steps, or similar list items → param_group
- For param_group, include:
  - "default" as an array of item objects
  - "params" as the nested field definitions for each item
- Use {{#group_name}}...{{/group_name}} blocks in tokenised_html for param_group repeaters
- Do NOT tokenise structural HTML attributes like id, class, data attributes
- Do NOT tokenise CSS class names
- Keep param_name values lowercase, snake_case, descriptive
- Be selective — only surface params a non-developer would reasonably want to edit
- IMPORTANT: All newlines inside JSON string values MUST be escaped as \n — never use literal newline characters inside strings
- WPBakery compatibility guardrails:
  - Do NOT include <script> tags
  - Do NOT include inline JS event attributes (onclick, onerror, onload, etc.)
  - Do NOT include <link rel="stylesheet"> or <link rel="preconnect"> tags in tokenised_html
  - Keep CSS component-scoped and class-based; avoid global selectors like *, html, body
PROMPT;

        $user_message = "Here is the HTML component:\n\n{$html}";
        if ( ! empty( $css ) ) {
            $user_message .= "\n\nHere is the CSS:\n\n{$css}";
        }
        $user_message .= "\n\nAnalyse this component and return the JSON as instructed.";

        $result = $this->request_ai_text( $provider, $model, $api_key, $system_prompt, $user_message );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $ai_text = $result;
        $ai_text = $this->prepare_ai_json_text( $ai_text );

        $parsed = $this->safe_json_decode( $ai_text );

        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        if ( ! isset( $parsed['tokenised_html'] ) || ! isset( $parsed['params'] ) ) {
            return new WP_Error( 'invalid_response', 'AI response is missing required fields (tokenised_html, params).' );
        }

        return [
            'tokenised_html' => $parsed['tokenised_html'],
            'params'         => $this->sanitize_ai_params( $parsed['params'] ),
        ];
    }

    public function ingest_snippet( $snippet, $provider, $model, $api_key ) {
        $parsed_snippet = $this->parse_combined_snippet( $snippet );
        if ( is_wp_error( $parsed_snippet ) ) {
            return $parsed_snippet;
        }

        $sections = $this->detect_candidate_sections( $parsed_snippet['html'] );
        if ( empty( $sections ) ) {
            return new WP_Error( 'no_sections_detected', 'Could not detect any usable sections in the pasted snippet.' );
        }

        $global_warnings = array_merge(
            $parsed_snippet['warnings'],
            $this->detect_shared_css_warnings( $parsed_snippet['css'], count( $sections ) )
        );

        $default_category = get_option( 'vb_es_default_category', 'VB Elements' );
        $candidates = [];

        foreach ( $sections as $index => $section_html ) {
            $candidate = [
                'name'          => $this->suggest_element_name( $section_html, $index ),
                'slug'          => '',
                'description'   => '',
                'category'      => $default_category,
                'raw_html'      => $section_html,
                'raw_css'       => $parsed_snippet['css'],
                'html_template' => $section_html,
                'params'        => [],
                'warnings'      => [],
            ];

            $detected = $this->detect( $section_html, $parsed_snippet['css'], $provider, $model, $api_key );
            if ( is_wp_error( $detected ) ) {
                $candidate['warnings'][] = 'AI detection fallback used: ' . $detected->get_error_message();
            } else {
                $candidate['html_template'] = $detected['tokenised_html'];
                $candidate['params'] = $detected['params'];
            }

            $validation = VB_ES_API::validate_definition( $candidate );
            if ( ! is_wp_error( $validation ) && ! empty( $validation['warnings'] ) ) {
                $candidate['warnings'] = array_merge( $candidate['warnings'], $validation['warnings'] );
            }

            if ( ! empty( $global_warnings ) ) {
                $candidate['warnings'] = array_merge( $candidate['warnings'], $global_warnings );
            }

            $candidate['warnings'] = array_values( array_unique( array_filter( $candidate['warnings'] ) ) );
            $candidates[] = $candidate;
        }

        return [
            'html'            => $parsed_snippet['html'],
            'css'             => $parsed_snippet['css'],
            'warnings'        => array_values( array_unique( array_filter( $global_warnings ) ) ),
            'section_count'   => count( $candidates ),
            'candidates'      => $candidates,
        ];
    }

    private function sanitize_ai_params( $params ) {
        if ( ! is_array( $params ) ) {
            return [];
        }

        $allowed_types = [ 'textfield', 'textarea', 'colorpicker', 'attach_image', 'dropdown', 'checkbox', 'param_group' ];
        $clean_params = [];

        foreach ( $params as $param ) {
            if ( ! is_array( $param ) || empty( $param['param_name'] ) ) {
                continue;
            }

            $type = sanitize_key( $param['type'] ?? 'textfield' );
            if ( ! in_array( $type, $allowed_types, true ) ) {
                $type = 'textfield';
            }

            $clean_param = [
                'param_name'  => sanitize_key( $param['param_name'] ),
                'type'        => $type,
                'heading'     => sanitize_text_field( $param['heading'] ?? $param['param_name'] ),
                'description' => sanitize_text_field( $param['description'] ?? '' ),
            ];

            if ( $clean_param['param_name'] === '' ) {
                continue;
            }

            if ( $type === 'param_group' ) {
                $default_items = $param['default'] ?? [];
                if ( ! is_array( $default_items ) ) {
                    $default_items = [];
                }

                $clean_default = [];
                foreach ( $default_items as $item ) {
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
                        $clean_default[] = $clean_item;
                    }
                }

                $clean_param['default'] = $clean_default;
                $clean_param['params']  = $this->sanitize_ai_params( $param['params'] ?? [] );
                $clean_params[] = $clean_param;
                continue;
            }

            if ( $type === 'dropdown' && isset( $param['options'] ) ) {
                if ( is_array( $param['options'] ) ) {
                    $param['options'] = implode( ',', array_map( 'sanitize_text_field', $param['options'] ) );
                } else {
                    $param['options'] = sanitize_text_field( (string) $param['options'] );
                }
                $clean_param['options'] = $param['options'];
            }

            $clean_param['default'] = $type === 'textarea'
                ? sanitize_textarea_field( $param['default'] ?? '' )
                : sanitize_text_field( $param['default'] ?? '' );

            $clean_params[] = $clean_param;
        }

        return $clean_params;
    }

    private function parse_combined_snippet( $snippet ) {
        $snippet = trim( (string) $snippet );
        if ( $snippet === '' ) {
            return new WP_Error( 'empty_snippet', 'The pasted snippet is empty.' );
        }

        $snippet = preg_replace( '/^\xEF\xBB\xBF/', '', $snippet );
        $warnings = [];
        $html = '';
        $css = '';

        if ( preg_match_all( '/```([a-zA-Z0-9_-]*)\s*([\s\S]*?)```/', $snippet, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $language = strtolower( trim( $match[1] ) );
                $content  = trim( $match[2] );

                if ( in_array( $language, [ 'css', 'scss', 'less' ], true ) ) {
                    $css .= "\n" . $content;
                } else {
                    $html .= "\n" . $content;
                }
            }

            if ( trim( $html ) !== '' || trim( $css ) !== '' ) {
                $warnings[] = 'Detected markdown code fences and extracted their contents automatically.';
            }
        }

        if ( trim( $html ) === '' && trim( $css ) === '' ) {
            $html = $snippet;
        }

        preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $html, $style_matches );
        foreach ( $style_matches[1] ?? [] as $style_block ) {
            $css .= "\n" . trim( $style_block );
        }
        $html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $html );

        if ( preg_match( '/<body\b[^>]*>(.*?)<\/body>/is', $html, $body_match ) ) {
            $html = $body_match[1];
            $warnings[] = 'Detected a full HTML document and extracted only the <body> contents.';
        }

        $html = preg_replace( '/<!doctype[^>]*>/i', '', $html );
        $html = preg_replace( '/<\/?(?:html|head|body|meta|title|link)[^>]*>/i', '', $html );
        $html = trim( $html );
        $css  = trim( $css );

        if ( $html === '' ) {
            return new WP_Error( 'missing_html', 'No usable HTML was found in the pasted snippet.' );
        }

        return [
            'html'     => $html,
            'css'      => $css,
            'warnings' => $warnings,
        ];
    }

    private function detect_candidate_sections( $html ) {
        $document = new DOMDocument();
        $internal_errors = libxml_use_internal_errors( true );
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="vb-es-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $internal_errors );

        if ( ! $loaded ) {
            return [ trim( $html ) ];
        }

        $root = $document->getElementById( 'vb-es-root' );
        if ( ! $root ) {
            return [ trim( $html ) ];
        }

        $top_level = $this->element_children( $root );
        if ( count( $top_level ) === 0 ) {
            return [ trim( $html ) ];
        }

        if ( count( $top_level ) > 1 ) {
            return array_map( [ $this, 'node_outer_html' ], $top_level );
        }

        $single = $top_level[0];
        $section_like_children = [];
        foreach ( $this->element_children( $single ) as $child ) {
            if ( $this->is_candidate_section_node( $child ) ) {
                $section_like_children[] = $child;
            }
        }

        if ( count( $section_like_children ) > 1 ) {
            return array_map( [ $this, 'node_outer_html' ], $section_like_children );
        }

        return [ $this->node_outer_html( $single ) ];
    }

    private function element_children( DOMNode $node ) {
        $children = [];

        foreach ( $node->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function is_candidate_section_node( DOMElement $node ) {
        $tag_name = strtolower( $node->tagName );
        if ( in_array( $tag_name, [ 'section', 'article', 'aside', 'header', 'footer', 'main' ], true ) ) {
            return true;
        }

        if ( $tag_name !== 'div' ) {
            return false;
        }

        return count( $this->element_children( $node ) ) > 0;
    }

    private function node_outer_html( DOMNode $node ) {
        return trim( $node->ownerDocument->saveHTML( $node ) );
    }

    private function suggest_element_name( $html, $index ) {
        if ( preg_match( '/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', $html, $matches ) ) {
            $heading = trim( wp_strip_all_tags( html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
            if ( $heading !== '' ) {
                return mb_substr( $heading, 0, 60 );
            }
        }

        if ( preg_match( '/class\s*=\s*"([^"]+)"/i', $html, $matches ) ) {
            $classes = preg_split( '/\s+/', trim( $matches[1] ) );
            $class_name = sanitize_text_field( str_replace( [ '-', '_' ], ' ', $classes[0] ?? '' ) );
            if ( $class_name !== '' ) {
                return ucwords( $class_name );
            }
        }

        return 'Imported Section ' . ( $index + 1 );
    }

    private function detect_shared_css_warnings( $css, $section_count ) {
        $warnings = [];
        if ( $section_count <= 1 || trim( (string) $css ) === '' ) {
            return $warnings;
        }

        if ( preg_match( '/(^|,)\s*(html|body|\*|:root)\b/im', $css ) ) {
            $warnings[] = 'Global selectors were detected in the pasted CSS. Those rules will be duplicated across imported sections and may need manual cleanup.';
        }

        if ( preg_match( '/@font-face|@keyframes|@property/i', $css ) ) {
            $warnings[] = 'Shared CSS at-rules were detected. Review each imported section to make sure duplicated animation or font rules are acceptable.';
        }

        if ( ! empty( $warnings ) ) {
            $warnings[] = 'The pasted CSS is currently applied to every detected section during import review.';
        }

        return $warnings;
    }

    private function request_ai_text( $provider, $model, $api_key, $system_prompt, $user_message ) {
        switch ( sanitize_key( $provider ) ) {
            case 'openai':
                return $this->request_openai( $model, $api_key, $system_prompt, $user_message );
            case 'gemini':
                return $this->request_gemini( $model, $api_key, $system_prompt, $user_message );
            case 'anthropic':
            default:
                return $this->request_anthropic( $model, $api_key, $system_prompt, $user_message );
        }
    }

    private function request_anthropic( $model, $api_key, $system_prompt, $user_message ) {
        $body = [
            'model'      => $model,
            'max_tokens' => 8192,
            'system'     => $system_prompt,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => $user_message,
                ],
            ],
        ];

        $response = wp_remote_post( self::ANTHROPIC_API_ENDPOINT, [
            'timeout' => 90,
            'headers' => [
                'x-api-key'          => $api_key,
                'anthropic-version'  => self::ANTHROPIC_API_VERSION,
                'content-type'       => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ]);

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'api_request_failed', 'Anthropic API request failed: ' . $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_raw    = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body_raw, true );

        if ( $status_code !== 200 ) {
            $error_msg = $data['error']['message'] ?? "API returned HTTP {$status_code}";
            return new WP_Error( 'api_error', 'Anthropic API error: ' . $error_msg );
        }

        if ( empty( $data['content'][0]['text'] ) ) {
            return new WP_Error( 'api_empty_response', 'Anthropic API returned an empty response.' );
        }

        return (string) $data['content'][0]['text'];
    }

    private function request_openai( $model, $api_key, $system_prompt, $user_message ) {
        $body = [
            'model'             => $model,
            'instructions'      => $system_prompt,
            'input'             => $user_message,
            'max_output_tokens' => 4096,
        ];

        $response = wp_remote_post( self::OPENAI_API_ENDPOINT, [
            'timeout' => 90,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'content-type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ]);

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'api_request_failed', 'OpenAI API request failed: ' . $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_raw    = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body_raw, true );

        if ( $status_code !== 200 ) {
            $error_msg = $data['error']['message'] ?? "API returned HTTP {$status_code}";
            return new WP_Error( 'api_error', 'OpenAI API error: ' . $error_msg );
        }

        $text = $this->extract_openai_response_text( $data );
        if ( trim( $text ) === '' ) {
            return new WP_Error( 'api_empty_response', 'OpenAI API returned an empty response.' );
        }

        return $text;
    }

    private function request_gemini( $model, $api_key, $system_prompt, $user_message ) {
        $endpoint = sprintf(
            self::GEMINI_API_ENDPOINT,
            rawurlencode( $model ),
            rawurlencode( $api_key )
        );

        $body = [
            'systemInstruction' => [
                'parts' => [
                    [
                        'text' => $system_prompt,
                    ],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => $user_message,
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => 8192,
                'temperature'     => 0.2,
                'responseMimeType'=> 'application/json',
            ],
        ];

        $response = wp_remote_post( $endpoint, [
            'timeout' => 90,
            'headers' => [
                'content-type' => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ]);

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'api_request_failed', 'Gemini API request failed: ' . $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_raw    = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body_raw, true );

        if ( $status_code !== 200 ) {
            $error_msg = $data['error']['message'] ?? "API returned HTTP {$status_code}";
            return new WP_Error( 'api_error', 'Gemini API error: ' . $error_msg );
        }

        if ( empty( $data['candidates'][0]['content']['parts'] ) || ! is_array( $data['candidates'][0]['content']['parts'] ) ) {
            return new WP_Error( 'api_empty_response', 'Gemini API returned an empty response.' );
        }

        $parts = $data['candidates'][0]['content']['parts'];
        $text = '';
        foreach ( $parts as $part ) {
            if ( isset( $part['text'] ) ) {
                $text .= (string) $part['text'];
            }
        }

        if ( trim( $text ) === '' ) {
            return new WP_Error( 'api_empty_response', 'Gemini API returned an empty response.' );
        }

        return $text;
    }

    private function extract_openai_response_text( $data ) {
        if ( ! is_array( $data ) ) {
            return '';
        }

        if ( ! empty( $data['output_text'] ) && is_string( $data['output_text'] ) ) {
            return $data['output_text'];
        }

        $text = '';
        foreach ( $data['output'] ?? [] as $output_item ) {
            if ( empty( $output_item['content'] ) || ! is_array( $output_item['content'] ) ) {
                continue;
            }

            foreach ( $output_item['content'] as $content_item ) {
                if ( isset( $content_item['text'] ) && is_string( $content_item['text'] ) ) {
                    $text .= $content_item['text'];
                }
            }
        }

        return $text;
    }

    private function prepare_ai_json_text( $text ) {
        $text = trim( (string) $text );
        $text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );

        if ( function_exists( 'iconv' ) ) {
            $converted = @iconv( 'UTF-8', 'UTF-8//IGNORE', $text );
            if ( is_string( $converted ) && $converted !== '' ) {
                $text = $converted;
            }
        }

        if ( strpos( $text, '```' ) !== false ) {
            $text = preg_replace( '/^```(?:json)?\s*/m', '', $text );
            $text = preg_replace( '/\s*```\s*$/m', '', $text );
            $text = trim( $text );
        }

        $extracted = $this->extract_first_json_object( $text );
        if ( $extracted !== null ) {
            $text = $extracted;
        }

        return trim( $text );
    }

    private function extract_first_json_object( $text ) {
        $start = strpos( $text, '{' );
        if ( $start === false ) {
            return null;
        }

        $depth = 0;
        $in_string = false;
        $escape_next = false;
        $len = strlen( $text );

        for ( $i = $start; $i < $len; $i++ ) {
            $char = $text[ $i ];

            if ( $escape_next ) {
                $escape_next = false;
                continue;
            }

            if ( $in_string && $char === '\\' ) {
                $escape_next = true;
                continue;
            }

            if ( $char === '"' ) {
                $in_string = ! $in_string;
                continue;
            }

            if ( $in_string ) {
                continue;
            }

            if ( $char === '{' ) {
                $depth++;
                continue;
            }

            if ( $char === '}' ) {
                $depth--;
                if ( $depth === 0 ) {
                    return substr( $text, $start, $i - $start + 1 );
                }
            }
        }

        return null;
    }

    /**
     * Attempt to decode JSON that may contain literal control characters
     * inside string values (common with LLM-generated JSON containing HTML).
     *
     * Tier 1: Raw json_decode.
     * Tier 2: Replace all control chars (0x00-0x1F) with spaces — safe because
     *         JSON structural characters are all > 0x1F, and space is valid both
     *         as whitespace between tokens and as a character inside strings.
     * Tier 3: Character-by-character walk that properly escapes control chars
     *         only inside quoted strings.
     *
     * @return array|WP_Error Parsed array on success, WP_Error on failure.
     */
    private function safe_json_decode( $text ) {
        $text = $this->prepare_ai_json_text( $text );

        $parsed = json_decode( $text, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
            return $parsed;
        }
        $tier1_error = json_last_error_msg();

        $nuked = preg_replace( '/[\x00-\x1f]+/', ' ', $text );
        if ( is_string( $nuked ) ) {
            $parsed = json_decode( $nuked, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
                return $parsed;
            }
        }
        $tier2_error = json_last_error_msg();

        $fixed = $this->escape_control_chars_in_strings( $text );
        $parsed = json_decode( $fixed, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
            return $parsed;
        }
        $tier3_error = json_last_error_msg();

        return new WP_Error(
            'json_parse_error',
            'Failed to parse AI response as JSON. ' .
            'Raw: ' . $tier1_error . ' | ' .
            'Sanitised: ' . $tier2_error . ' | ' .
            'Escaped: ' . $tier3_error
        );
    }

    private function escape_control_chars_in_strings( $text ) {
        $in_string = false;
        $escape_next = false;
        $result = '';
        $len = strlen( $text );

        for ( $i = 0; $i < $len; $i++ ) {
            $byte = $text[ $i ];
            $ord  = ord( $byte );

            if ( $escape_next ) {
                $result .= $byte;
                $escape_next = false;
                continue;
            }

            if ( $in_string && $byte === '\\' ) {
                $result .= $byte;
                $escape_next = true;
                continue;
            }

            if ( $byte === '"' ) {
                $in_string = ! $in_string;
                $result .= $byte;
                continue;
            }

            if ( $in_string && $ord < 0x20 ) {
                switch ( $ord ) {
                    case 0x0A: $result .= '\\n'; break;
                    case 0x0D: $result .= '\\r'; break;
                    case 0x09: $result .= '\\t'; break;
                    default:   $result .= sprintf( '\\u%04x', $ord ); break;
                }
                continue;
            }

            $result .= $byte;
        }

        return $result;
    }
}
