<?php
/**
 * Environment health checks.
 *
 * Everything here answers a question the plugin previously assumed it knew the
 * answer to. All of them are assumptions that hold on a laptop and quietly
 * stop holding on a live server.
 *
 * Scheduled work is the clearest example. Post-save scanning is queued with
 * wp_schedule_single_event( time() + 60, ... ), which is not a promise that
 * anything runs in sixty seconds - it is a note that the job is eligible after
 * sixty seconds. WP-Cron only fires when somebody loads a page, so on a quiet
 * site it can lag for hours; and if DISABLE_WP_CRON is set without a real
 * crontab entry to replace it, it never fires at all. Either way the plugin
 * looked like it was working and simply produced nothing.
 */

defined( 'ABSPATH' ) || exit;

class AILG_Health {

    /**
     * @return array<int,array{level:string,title:string,detail:string}>
     */
    public static function checks(): array {
        return array_values( array_filter( [
            self::check_cron(),
            self::check_providers(),
            self::check_index(),
            self::check_secrets(),
        ] ) );
    }

    /** Any check that is not 'ok'. */
    public static function problems(): array {
        return array_values( array_filter( self::checks(), static fn( $c ) => 'ok' !== $c['level'] ) );
    }

    /* ----------------------------------------------------------------- cron */

    private static function check_cron(): array {
        $last = (int) get_option( 'ailg_cron_last_run', 0 );
        $age  = $last ? time() - $last : 0;

        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            // Not a fault in itself - it is the recommended setup - but only
            // when something external actually calls wp-cron.php. The
            // heartbeat is the only way to tell the two cases apart.
            if ( ! $last || $age > 2 * DAY_IN_SECONDS ) {
                return [
                    'level'  => 'error',
                    'title'  => 'Scheduled tasks are not running',
                    'detail' => 'DISABLE_WP_CRON is set, which means WordPress relies on a server cron job calling wp-cron.php. '
                        . ( $last
                            ? 'The last run was ' . human_time_diff( $last ) . ' ago.'
                            : 'It has never run.' )
                        . ' Add a crontab entry such as: */15 * * * * curl -s ' . esc_url( site_url( 'wp-cron.php?doing_wp_cron' ) ) . ' >/dev/null',
                ];
            }

            return [
                'level'  => 'ok',
                'title'  => 'Scheduled tasks',
                'detail' => 'Running from a server cron job; last run ' . human_time_diff( $last ) . ' ago.',
            ];
        }

        if ( ! wp_next_scheduled( 'ailg_cron_daily' ) ) {
            return [
                'level'  => 'warning',
                'title'  => 'Daily task is not scheduled',
                'detail' => 'Deactivate and reactivate the plugin to restore it.',
            ];
        }

        if ( $last && $age > 3 * DAY_IN_SECONDS ) {
            return [
                'level'  => 'warning',
                'title'  => 'Scheduled tasks are running late',
                'detail' => 'The daily task last completed ' . human_time_diff( $last ) . ' ago. WP-Cron only fires when somebody visits the site, '
                    . 'so on a low-traffic site it drifts. A real cron job calling wp-cron.php makes it dependable.',
            ];
        }

        return [
            'level'  => 'ok',
            'title'  => 'Scheduled tasks',
            'detail' => $last ? 'Last run ' . human_time_diff( $last ) . ' ago.' : 'Scheduled; has not run yet.',
        ];
    }

    /* ------------------------------------------------------------ providers */

    private static function check_providers(): array {
        $usable  = AILG_AiRouter::get_chain();
        $skipped = AILG_AiRouter::skipped_providers();

        if ( ! $usable ) {
            return [
                'level'  => 'error',
                'title'  => 'No usable AI provider',
                'detail' => 'Every configured provider was skipped: '
                    . implode( ' ', array_map(
                        static fn( $p, $why ) => "{$p} - {$why}",
                        array_keys( $skipped ),
                        $skipped
                    ) ),
            ];
        }

        if ( $skipped ) {
            return [
                'level'  => 'warning',
                'title'  => 'Some providers are being skipped',
                'detail' => implode( ' ', array_map(
                    static fn( $p, $why ) => "{$p} - {$why}",
                    array_keys( $skipped ),
                    $skipped
                ) ) . ' They are skipped before the request is made, so they cost no time.',
            ];
        }

        return [
            'level'  => 'ok',
            'title'  => 'AI providers',
            'detail' => 'Chain: ' . implode( ' then ', $usable ) . '.',
        ];
    }

    /* ---------------------------------------------------------------- index */

    private static function check_index(): array {
        $p = AILG_LinkIndex::progress();

        if ( ! $p['ready'] ) {
            return [
                'level'  => 'warning',
                'title'  => 'Link index is still building',
                'detail' => "{$p['done']} of {$p['total']} posts read ({$p['percent']}%). Orphan and broken-link reports are incomplete until this finishes.",
            ];
        }

        return [
            'level'  => 'ok',
            'title'  => 'Link index',
            'detail' => "{$p['total']} posts indexed.",
        ];
    }

    /* -------------------------------------------------------------- secrets */

    private static function check_secrets(): array {
        if ( get_transient( 'ailg_decrypt_failed' ) ) {
            return [
                'level'  => 'error',
                'title'  => 'Stored API keys cannot be read',
                'detail' => 'Decryption failed, which almost always means AUTH_KEY in wp-config.php changed since the keys were saved - '
                    . 'common when copying a site between environments. Re-enter the keys in Settings.',
            ];
        }

        return [ 'level' => 'ok', 'title' => 'Credentials', 'detail' => 'Readable.' ];
    }

    /* --------------------------------------------------------------- notice */

    public static function admin_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || false === strpos( (string) $screen->id, 'ailg' ) ) {
            return; // Only on this plugin's own screens.
        }

        foreach ( self::problems() as $problem ) {
            printf(
                '<div class="notice notice-%s"><p><strong>%s.</strong> %s</p></div>',
                'error' === $problem['level'] ? 'error' : 'warning',
                esc_html( $problem['title'] ),
                esc_html( $problem['detail'] )
            );
        }
    }
}
