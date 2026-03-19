<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VB_ES_AI_Detector {

    private const ANTHROPIC_API_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_API_VERSION  = '2023-06-01';
    private const OPENAI_API_ENDPOINT    = 'https://api.openai.com/v1/chat/completions';
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
                'claude-3-5-sonnet-latest' => 'Claude 3.5 Sonnet (latest)',
                'claude-3-7-sonnet-latest' => 'Claude 3.7 Sonnet (latest)',
                'claude-3-5-haiku-latest'  => 'Claude 3.5 Haiku (latest)',
            ],
            'openai' => [
                'gpt-4o-mini' => 'GPT-4o mini',
                'gpt-4o'      => 'GPT-4o',
                'gpt-4.1'     => 'GPT-4.1',
                'gpt-4.1-mini'=> 'GPT-4.1 mini',
            ],
            'gemini' => [
                'gemini-2.0-flash'      => 'Gemini 2.0 Flash',
                'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash-Lite',
                'gemini-1.5-pro'        => 'Gemini 1.5 Pro',
            ],
        ];
    }

    public static function get_default_model( $provider ) {
        $provider = sanitize_key( $provider );
        $models = self::get_supported_models();
        if ( ! isset( $models[ $provider ] ) || empty( $models[ $provider ] ) ) {
            return 'claude-3-5-sonnet-latest';
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
      "type": "textfield|textarea|colorpicker|attach_image|dropdown|checkbox",
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

        $ai_text = trim( $ai_text );
        if ( strpos( $ai_text, '```' ) !== false ) {
            $ai_text = preg_replace( '/^```(?:json)?\s*/m', '', $ai_text );
            $ai_text = preg_replace( '/\s*```\s*$/m', '', $ai_text );
            $ai_text = trim( $ai_text );
        }

        $parsed = $this->safe_json_decode( $ai_text );

        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        if ( ! isset( $parsed['tokenised_html'] ) || ! isset( $parsed['params'] ) ) {
            return new WP_Error( 'invalid_response', 'AI response is missing required fields (tokenised_html, params).' );
        }

        $allowed_types = [ 'textfield', 'textarea', 'colorpicker', 'attach_image', 'dropdown', 'checkbox' ];
        $clean_params = [];
        foreach ( $parsed['params'] as $param ) {
            if ( empty( $param['param_name'] ) || empty( $param['type'] ) ) {
                continue;
            }
            if ( ! in_array( $param['type'], $allowed_types, true ) ) {
                $param['type'] = 'textfield';
            }
            $clean_params[] = [
                'param_name'  => sanitize_key( $param['param_name'] ),
                'type'        => $param['type'],
                'heading'     => sanitize_text_field( $param['heading'] ?? $param['param_name'] ),
                'description' => sanitize_text_field( $param['description'] ?? '' ),
                'default'     => sanitize_text_field( $param['default'] ?? '' ),
            ];
        }

        return [
            'tokenised_html' => $parsed['tokenised_html'],
            'params'         => $clean_params,
        ];
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
            'model' => $model,
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => $system_prompt,
                ],
                [
                    'role'    => 'user',
                    'content' => $user_message,
                ],
            ],
            'max_tokens' => 4096,
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

        if ( empty( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'api_empty_response', 'OpenAI API returned an empty response.' );
        }

        return (string) $data['choices'][0]['message']['content'];
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
        $parsed = json_decode( $text, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
            return $parsed;
        }
        $tier1_error = json_last_error_msg();

        $nuked = preg_replace( '/[\x00-\x1f]+/', ' ', $text );
        if ( is_string( $nuked ) ) {
            $parsed = json_decode( $nuked, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
                return $parsed;
            }
        }
        $tier2_error = json_last_error_msg();

        $fixed = $this->escape_control_chars_in_strings( $text );
        $parsed = json_decode( $fixed, true );
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
