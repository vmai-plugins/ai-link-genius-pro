<?php
/**
 * AI Router: provider chain, environment filtering, and a request-wide time
 * budget.
 *
 * Two problems this fixes.
 *
 * The chain was walked blindly. Every provider in it got a full HTTP attempt
 * even when it obviously could not answer - no API key stored, or Ollama still
 * pointed at http://localhost:11434 on a live server where nothing is
 * listening. Each of those cost a whole connect timeout before the router
 * moved on, so the common "deployed to live, forgot to take ollama out of the
 * fallbacks" case added ten dead seconds to every single call.
 *
 * And there was no ceiling. remote_post() used a hardcoded 60-second timeout
 * and generate() tried every provider in turn, so a bad afternoon upstream
 * could hold a request open for minutes. The binding limit is almost never
 * max_execution_time; it is whatever proxy holds the connection, which gives
 * up at 60 seconds, or 100 for Cloudflare. Past that the work is discarded
 * whether or not PHP was still happily waiting for it.
 */

defined( 'ABSPATH' ) || exit;

class AILG_AiRouter {

    private static $last_error = '';

    /** Float unix time after which no new upstream call may start. */
    private static $deadline = 0.0;

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

        self::start_budget();

        $chain = self::get_chain();
        if ( ! empty( $args['provider'] ) && self::is_provider_usable( $args['provider'] ) ) {
            $chain = array_values( array_unique( array_merge( [ $args['provider'] ], $chain ) ) );
        }
        $errors = [];

        if ( ! $chain ) {
            self::$last_error = 'No usable AI provider is configured. Add an API key in Settings.';
            return [ 'ok' => false, 'error' => self::$last_error ];
        }

        foreach ( $chain as $provider ) {
            $method = 'call_' . $provider;
            if ( ! method_exists( 'AILG_AI', $method ) ) {
                continue;
            }

            // Do not start a provider there is no time left to hear back from.
            // Returning the errors collected so far beats running past the
            // point where the gateway has stopped listening.
            if ( self::remaining() < 3 ) {
                $errors['budget'] = 'ran out of time before trying ' . $provider;
                break;
            }

            $result = AILG_AI::$method( $prompt, $args );

            if ( ! empty( $result['ok'] ) && ! empty( $result['text'] ) ) {
                return $result;
            }

            $errors[ $provider ] = $result['error'] ?? 'Unknown error';
        }

        self::$last_error = implode( ' | ', array_map(
            static fn( $k, $v ) => "{$k}: {$v}",
            array_keys( $errors ),
            $errors
        ) );

        return [ 'ok' => false, 'error' => self::$last_error ];
    }

    /* --------------------------------------------------------------- budget */

    /**
     * Open a time budget for this request.
     *
     * Unattended work can afford to wait; a browser cannot. Whatever does not
     * fit comes back as an error the operator can read, rather than as an
     * empty gateway timeout page.
     */
    public static function start_budget( ?int $seconds = null ): void {
        if ( null === $seconds ) {
            $seconds = self::default_budget();
        }
        self::$deadline = microtime( true ) + max( 5, $seconds );
    }

    public static function default_budget(): int {
        $max = (int) ini_get( 'max_execution_time' );

        if ( self::is_unattended() ) {
            return $max > 0 ? max( 60, (int) ( $max * 0.7 ) ) : 300;
        }

        // Comfortably inside a 60s proxy read timeout and far inside
        // Cloudflare's 100s, so the request always returns something.
        return $max > 0 ? min( 45, max( 15, (int) ( $max * 0.7 ) ) ) : 45;
    }

    /** Seconds left. Falls back to a full budget when none was opened. */
    public static function remaining(): float {
        if ( self::$deadline <= 0 ) {
            return (float) self::default_budget();
        }
        return max( 0.0, self::$deadline - microtime( true ) );
    }

    /**
     * How long one upstream request may take. Never longer than the time
     * actually left, so the last provider in a chain cannot overrun alone.
     */
    public static function request_timeout( int $cap = 0 ): int {
        if ( $cap <= 0 ) {
            $cap = self::is_unattended() ? 60 : 30;
        }
        return (int) max( 3, min( $cap, floor( self::remaining() ) ) );
    }

    public static function is_unattended(): bool {
        return ( defined( 'WP_CLI' ) && WP_CLI )
            || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
            || 'cli' === PHP_SAPI;
    }

    /* ---------------------------------------------------------------- chain */

    /**
     * The providers worth trying, in order. Anything that cannot possibly
     * answer is dropped before it costs a timeout rather than after.
     */
    public static function get_chain(): array {
        $primary   = get_option( 'ailg_default_provider', 'aipuffer' );
        $fallbacks = (array) get_option( 'ailg_fallback_providers', [ 'openai', 'google' ] );

        $chain = array_values( array_unique( array_filter( array_merge( [ $primary ], $fallbacks ) ) ) );

        return array_values( array_filter( $chain, [ self::class, 'is_provider_usable' ] ) );
    }

    /** Can this provider be reached from here, with what is stored? */
    public static function is_provider_usable( string $provider ): bool {
        switch ( $provider ) {
            case 'openai':
                return '' !== AILG_Secrets::get( 'ailg_openai_key' );

            case 'google':
                return '' !== AILG_Secrets::get( 'ailg_google_key' );

            case 'openrouter':
                return '' !== AILG_Secrets::get( 'ailg_openrouter_key' );

            case 'omniroute':
                return '' !== AILG_Secrets::get( 'ailg_omniroute_key' );

            case 'aipuffer':
                return '' !== trim( (string) get_option( 'ailg_aipuffer_url', '' ) );

            case 'ollama':
                // Ollama's default host is a loopback address: correct on a
                // laptop, unreachable on a live server, where leaving it in
                // the chain bought nothing but a connect timeout per call.
                return ! self::host_is_loopback( (string) get_option( 'ailg_ollama_host', '' ) )
                    || AILG_Core::is_local_env();
        }

        return true;
    }

    public static function host_is_loopback( string $url ): bool {
        $host = strtolower( (string) wp_parse_url( $url ?: 'http://localhost:11434', PHP_URL_HOST ) );

        return in_array( $host, [ 'localhost', '127.0.0.1', '::1', '0.0.0.0' ], true )
            || str_ends_with( $host, '.local' )
            || str_ends_with( $host, '.test' );
    }

    /**
     * Which configured providers are being skipped, and why - so the settings
     * screen can turn "it just never answers" into a sentence.
     */
    public static function skipped_providers(): array {
        $primary    = get_option( 'ailg_default_provider', 'aipuffer' );
        $fallbacks  = (array) get_option( 'ailg_fallback_providers', [] );
        $configured = array_values( array_unique( array_filter( array_merge( [ $primary ], $fallbacks ) ) ) );

        $skipped = [];
        foreach ( $configured as $provider ) {
            if ( self::is_provider_usable( $provider ) ) {
                continue;
            }
            $skipped[ $provider ] = ( 'ollama' === $provider )
                ? 'Points at a loopback address, which nothing on this server can reach.'
                : 'No API key or endpoint is stored.';
        }

        return $skipped;
    }
}
