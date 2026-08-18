<?php
/**
 * AI Router Class for managing multiple providers and fallbacks.
 */

defined( 'ABSPATH' ) || exit;

class AILG_AiRouter {

    private static $last_error = '';

    public static function get_last_error() {
        return self::$last_error;
    }

    /**
     * Generate text using the configured provider chain.
     */
    public static function generate( $prompt, array $args = [] ) {
        $args = wp_parse_args( $args, [
            'system'      => '',
            'temperature' => 0.7,
            'max_tokens'  => 2000,
        ] );

        $chain = self::get_chain();
        $errors = [];

        foreach ( $chain as $provider ) {
            $method = 'call_' . $provider;
            if ( ! method_exists( 'AILG_AI', $method ) ) continue;

            $result = AILG_AI::$method( $prompt, $args );

            if ( ! empty( $result['ok'] ) && ! empty( $result['text'] ) ) {
                return $result;
            }

            $errors[$provider] = $result['error'] ?? 'Unknown error';
        }

        self::$last_error = implode( ' | ', $errors );
        return [ 'ok' => false, 'error' => self::$last_error ];
    }

    private static function get_chain() {
        $primary   = get_option( 'ailg_default_provider', 'aipuffer' );
        $fallbacks = (array) get_option( 'ailg_fallback_providers', [ 'openai', 'google' ] );

        $chain = array_merge( [ $primary ], $fallbacks );
        return array_values( array_unique( array_filter( $chain ) ) );
    }
}
