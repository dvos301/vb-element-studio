<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Consider sabberworm/php-css-parser for production-grade parsing
class VB_ES_CSS_Scoper {

    public function scope( $css, $scope_id ) {
        if ( empty( trim( $css ) ) ) {
            return '';
        }

        $css = $this->strip_comments( $css );

        $scope_selector = '#' . $scope_id;
        $output = '';
        $pos = 0;
        $len = strlen( $css );

        while ( $pos < $len ) {
            $pos = $this->skip_whitespace( $css, $pos, $len );
            if ( $pos >= $len ) {
                break;
            }

            if ( $this->starts_with_at_rule( $css, $pos ) ) {
                $result = $this->process_at_rule( $css, $pos, $len, $scope_selector );
                $output .= $result['output'];
                $pos = $result['pos'];
            } else {
                $result = $this->process_rule_block( $css, $pos, $len, $scope_selector );
                $output .= $result['output'];
                $pos = $result['pos'];
            }
        }

        return trim( $output );
    }

    private function strip_comments( $css ) {
        return preg_replace( '#/\*.*?\*/#s', '', $css );
    }

    private function skip_whitespace( $css, $pos, $len ) {
        while ( $pos < $len && ctype_space( $css[ $pos ] ) ) {
            $pos++;
        }
        return $pos;
    }

    private function starts_with_at_rule( $css, $pos ) {
        return $css[ $pos ] === '@';
    }

    private function process_at_rule( $css, $pos, $len, $scope_selector ) {
        $at_rule_start = $pos;
        $brace_pos = strpos( $css, '{', $pos );
        $semicolon_pos = strpos( $css, ';', $pos );

        if ( $brace_pos === false && $semicolon_pos === false ) {
            return [ 'output' => substr( $css, $pos ), 'pos' => $len ];
        }

        if ( $semicolon_pos !== false && ( $brace_pos === false || $semicolon_pos < $brace_pos ) ) {
            $rule = substr( $css, $pos, $semicolon_pos - $pos + 1 );
            return [ 'output' => $rule . "\n", 'pos' => $semicolon_pos + 1 ];
        }

        $at_header = trim( substr( $css, $pos, $brace_pos - $pos ) );

        if ( $this->is_keyframes_rule( $at_header ) ) {
            $block_end = $this->find_matching_brace( $css, $brace_pos, $len );
            $full_rule = substr( $css, $pos, $block_end - $pos + 1 );
            return [ 'output' => $full_rule . "\n", 'pos' => $block_end + 1 ];
        }

        $block_end = $this->find_matching_brace( $css, $brace_pos, $len );
        $inner_css = substr( $css, $brace_pos + 1, $block_end - $brace_pos - 1 );

        $scoped_inner = $this->scope_inner_css( $inner_css, $scope_selector );

        return [
            'output' => $at_header . " {\n" . $scoped_inner . "}\n",
            'pos'    => $block_end + 1,
        ];
    }

    private function is_keyframes_rule( $header ) {
        return (bool) preg_match( '/^@(-webkit-|-moz-)?keyframes\s/i', $header );
    }

    private function scope_inner_css( $css, $scope_selector ) {
        $output = '';
        $pos = 0;
        $len = strlen( $css );

        while ( $pos < $len ) {
            $pos = $this->skip_whitespace( $css, $pos, $len );
            if ( $pos >= $len ) {
                break;
            }

            if ( $this->starts_with_at_rule( $css, $pos ) ) {
                $result = $this->process_at_rule( $css, $pos, $len, $scope_selector );
                $output .= $result['output'];
                $pos = $result['pos'];
            } else {
                $result = $this->process_rule_block( $css, $pos, $len, $scope_selector );
                $output .= $result['output'];
                $pos = $result['pos'];
            }
        }

        return $output;
    }

    private function process_rule_block( $css, $pos, $len, $scope_selector ) {
        $brace_pos = strpos( $css, '{', $pos );
        if ( $brace_pos === false ) {
            return [ 'output' => '', 'pos' => $len ];
        }

        $selector_str = trim( substr( $css, $pos, $brace_pos - $pos ) );
        if ( empty( $selector_str ) ) {
            $block_end = $this->find_matching_brace( $css, $brace_pos, $len );
            return [ 'output' => '', 'pos' => $block_end + 1 ];
        }

        $block_end = $this->find_matching_brace( $css, $brace_pos, $len );
        $declarations = substr( $css, $brace_pos + 1, $block_end - $brace_pos - 1 );

        $selectors = array_map( 'trim', explode( ',', $selector_str ) );
        $scoped_selectors = [];

        foreach ( $selectors as $selector ) {
            $scoped = $this->scope_single_selector( $selector, $scope_selector );
            if ( $scoped !== null ) {
                $scoped_selectors[] = $scoped;
            }
        }

        if ( empty( $scoped_selectors ) ) {
            return [ 'output' => '', 'pos' => $block_end + 1 ];
        }

        $output = implode( ",\n", $scoped_selectors ) . " {" . $declarations . "}\n";

        return [ 'output' => $output, 'pos' => $block_end + 1 ];
    }

    private function scope_single_selector( $selector, $scope_selector ) {
        $selector = trim( $selector );

        if ( empty( $selector ) ) {
            return $scope_selector;
        }

        if ( preg_match( '/^\*(\s*,|\s*{|\s*$)/', $selector ) || $selector === '*' ) {
            return null;
        }
        if ( preg_match( '/^\*::/', $selector ) ) {
            return null;
        }

        if ( preg_match( '/^:root\b/', $selector ) ) {
            $selector = preg_replace( '/^:root\b/', '', $selector );
            $selector = trim( $selector );
            if ( empty( $selector ) ) {
                return $scope_selector;
            }
            return $scope_selector . ' ' . $selector;
        }

        if ( preg_match( '/^(html|body)\b/i', $selector ) ) {
            $selector = preg_replace( '/^(html|body)\s*/i', '', $selector );
            $selector = trim( $selector );
            if ( empty( $selector ) ) {
                return $scope_selector;
            }
            return $scope_selector . ' ' . $selector;
        }

        return $scope_selector . ' ' . $selector;
    }

    private function find_matching_brace( $css, $open_pos, $len ) {
        $depth = 1;
        $pos = $open_pos + 1;

        while ( $pos < $len && $depth > 0 ) {
            $char = $css[ $pos ];

            if ( $char === '"' || $char === "'" ) {
                $pos = $this->skip_string( $css, $pos, $len );
                continue;
            }

            if ( $char === '{' ) {
                $depth++;
            } elseif ( $char === '}' ) {
                $depth--;
            }
            $pos++;
        }

        return $pos - 1;
    }

    private function skip_string( $css, $pos, $len ) {
        $quote = $css[ $pos ];
        $pos++;
        while ( $pos < $len ) {
            if ( $css[ $pos ] === '\\' ) {
                $pos += 2;
                continue;
            }
            if ( $css[ $pos ] === $quote ) {
                return $pos + 1;
            }
            $pos++;
        }
        return $pos;
    }
}
