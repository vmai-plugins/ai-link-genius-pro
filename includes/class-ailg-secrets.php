<?php
/**
 * Credential storage.
 *
 * Every API key, the Google OAuth client secret and the GSC refresh token were
 * stored as plain options and echoed straight back into the settings form:
 *
 *     <input type="password" value="<?php echo esc_attr( get_option( 'ailg_openai_key' ) ); ?>">
 *
 * type="password" hides a value from the screen. It does not hide it from
 * view-source, from a browser extension, from anything that reads the DOM, or
 * from anyone who can read the options table - which on a shared host includes
 * every other plugin.
 *
 * Keys are now encrypted at rest with material derived from the site's own
 * AUTH_KEY, and the form is rendered with a mask that the save handler knows
 * to ignore.
 */

defined( 'ABSPATH' ) || exit;

class AILG_Secrets {

    /** The bullet run shown in place of a stored credential. */
    const MASK = '••••••••••••';

    /**
     * Every option that holds a credential. One list, so storage, retrieval
     * and redaction cannot drift apart.
     */
    const KEYS = [
        'ailg_openai_key',
        'ailg_google_key',
        'ailg_openrouter_key',
        'ailg_aipuffer_key',
        'ailg_omniroute_key',
        'ailg_gsc_client_secret',
        'ailg_gsc_refresh_token',
        'ailg_license_key',
        'ailg_github_token',
    ];

    public static function is_secret( string $option ): bool {
        return in_array( $option, self::KEYS, true );
    }

    /** Read and decrypt. Returns '' when nothing is stored. Checks constants, local storage, and ecosystem plugins. */
    public static function get( string $option ): string {
        // 1. wp-config.php constants override all
        $const_map = [
            'ailg_openai_key'     => [ 'AILG_OPENAI_KEY', 'VMSB_OPENAI_KEY', 'VMSAI_OPENAI_KEY' ],
            'ailg_google_key'     => [ 'AILG_GOOGLE_KEY', 'AILG_GEMINI_KEY', 'VMSB_GEMINI_KEY', 'VMSAI_GEMINI_KEY' ],
            'ailg_openrouter_key' => [ 'AILG_OPENROUTER_KEY', 'VMSB_OPENROUTER_KEY', 'VMSAI_OPENROUTER_KEY' ],
            'ailg_aipuffer_key'   => [ 'AILG_AIPUFFER_KEY', 'VMSB_AIPUFFER_KEY', 'VMSAI_AIPUFFER_KEY' ],
            'ailg_omniroute_key'  => [ 'AILG_OMNIROUTE_KEY', 'VMSB_OMNIROUTE_KEY', 'VMSAI_OMNIROUTE_KEY' ],
            'ailg_github_token'   => [ 'AILG_GITHUB_TOKEN', 'VMSB_GITHUB_TOKEN', 'VMSAI_GITHUB_TOKEN' ],
        ];
        if ( isset( $const_map[ $option ] ) ) {
            foreach ( $const_map[ $option ] as $c ) {
                if ( defined( $c ) && constant( $c ) ) {
                    return (string) constant( $c );
                }
            }
        }

        // 2. Local encrypted storage
        $stored = (string) get_option( $option, '' );
        if ( '' !== $stored ) {
            $decrypted = self::decrypt( $stored );
            if ( '' !== $decrypted ) {
                return $decrypted;
            }
        }

        // 3. Fallback to ecosystem plugins: VM SEO Brain and VM Social AI
        return self::get_ecosystem_key( $option );
    }

    /**
     * Pull API keys dynamically from sibling plugins if available.
     */
    public static function get_ecosystem_key( string $option ): string {
        // Check VM SEO Brain
        if ( class_exists( 'VMSB_Settings' ) ) {
            $vmsb_map = [
                'ailg_openai_key'     => 'openai_key',
                'ailg_google_key'     => 'gemini_key',
                'ailg_openrouter_key' => 'openrouter_key',
                'ailg_aipuffer_key'   => 'aipuffer_key',
                'ailg_omniroute_key'  => 'omniroute_key',
                'ailg_github_token'   => 'github_token',
            ];
            if ( isset( $vmsb_map[ $option ] ) ) {
                $val = (string) VMSB_Settings::get( $vmsb_map[ $option ] );
                if ( '' !== $val ) {
                    return $val;
                }
            }
        }

        // Check VM Social AI
        if ( class_exists( 'VMSAI_Settings' ) ) {
            $vmsai_map = [
                'ailg_openai_key'     => [ 'openai_api_key', 'openai_key' ],
                'ailg_google_key'     => [ 'gemini_api_key', 'gemini_key' ],
                'ailg_openrouter_key' => [ 'openrouter_api_key', 'openrouter_key' ],
                'ailg_aipuffer_key'   => [ 'aipuffer_api_key', 'aipuffer_key' ],
                'ailg_omniroute_key'  => [ 'omniroute_api_key', 'omniroute_key' ],
                'ailg_github_token'   => [ 'github_token', 'github_pat' ],
            ];
            if ( isset( $vmsai_map[ $option ] ) ) {
                foreach ( (array) $vmsai_map[ $option ] as $field ) {
                    $val = (string) VMSAI_Settings::get( $field );
                    if ( '' !== $val ) {
                        return $val;
                    }
                }
            }
        }

        return '';
    }

    /** Encrypt and store. An empty value clears the option. */
    public static function set( string $option, string $value ): void {
        $value = trim( $value );
        if ( '' === $value ) {
            update_option( $option, '' );
            return;
        }
        update_option( $option, self::encrypt( $value ) );
    }

    /**
     * What the settings form should render: a mask when something is stored,
     * an empty string when nothing is. The real key never reaches the page.
     */
    public static function masked( string $option ): string {
        return '' === self::get( $option ) ? '' : self::MASK;
    }

    /**
     * Is this submitted value the mask the form was rendered with, rather than
     * a key somebody typed? Saving the mask would overwrite the real
     * credential with a row of bullets.
     */
    public static function is_masked( string $value ): bool {
        return '' !== $value && ( str_contains( $value, '•' ) || str_contains( $value, "\u{2022}" ) );
    }

    /**
     * Encrypt anything currently stored in plain text. Safe to run repeatedly:
     * encrypt() stamps its output with a 'v1:' prefix and decrypt() returns
     * anything without that prefix untouched.
     *
     * @return int Number of credentials migrated.
     */
    public static function migrate_plaintext(): int {
        $migrated = 0;
        foreach ( self::KEYS as $option ) {
            $stored = (string) get_option( $option, '' );
            if ( '' === $stored || str_starts_with( $stored, 'v1:' ) ) {
                continue;
            }
            update_option( $option, self::encrypt( $stored ) );
            $migrated++;
        }
        return $migrated;
    }

    /* ------------------------------------------------------------- crypto */

    private static function key_material(): string {
        $salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'ailg-fallback-key';
        return hash( 'sha256', $salt . '|ailg', true );
    }

    public static function encrypt( string $plain ): string {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return $plain;
        }
        $iv = random_bytes( 16 );
        $ct = openssl_encrypt( $plain, 'aes-256-cbc', self::key_material(), OPENSSL_RAW_DATA, $iv );
        if ( false === $ct ) {
            return $plain;
        }
        return 'v1:' . base64_encode( $iv . $ct );
    }

    public static function decrypt( string $stored ): string {
        if ( ! str_starts_with( $stored, 'v1:' ) ) {
            return $stored; // Not yet migrated.
        }
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }

        $raw = base64_decode( substr( $stored, 3 ), true );
        if ( ! $raw || strlen( $raw ) < 17 ) {
            return '';
        }

        $out = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key_material(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );

        // Almost always means AUTH_KEY changed since the value was saved - a
        // wp-config rotation or a migration between environments. Without this
        // every key silently goes blank and every provider starts failing with
        // "not configured" instead of pointing at the cause.
        if ( false === $out ) {
            set_transient( 'ailg_decrypt_failed', 1, DAY_IN_SECONDS );
            return '';
        }

        return $out;
    }
}
