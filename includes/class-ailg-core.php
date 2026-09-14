<?php
/**
 * Core Plugin Class
 */

defined( 'ABSPATH' ) || exit;

class AILG_Core {

    private static ?self $instance = null;

    public static function init(): void {
        if ( self::$instance ) return;
        self::$instance = new self();
    }

    private function __construct() {
        $this->maybe_upgrade_db();
        self::check_site_moved();
        add_action( 'admin_notices', [ self::class, 'site_moved_notice' ] );
        add_action( 'admin_notices', [ 'AILG_Health', 'admin_notice' ] );
        $this->handle_gsc_callback();
        AILG_LinkIndex::boot();
        AILG_Github_Updater::init();

        add_action( 'admin_menu',            [ $this, 'register_menus'     ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'     ] );
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_gutenberg_assets' ] );
        add_action( 'add_meta_boxes',        [ $this, 'register_metaboxes' ] );
        add_action( 'transition_post_status', [ $this, 'on_post_status_transition' ], 10, 3 );

        // AJAX actions
        add_action( 'wp_ajax_ailg_get_suggestions',     [ 'AILG_Suggester',  'ajax_suggestions'    ] );
        add_action( 'wp_ajax_ailg_get_eeat_suggestions', [ 'AILG_Suggester',  'ajax_eeat_suggestions' ] );
        add_action( 'wp_ajax_ailg_insert_external_link', [ 'AILG_Suggester',  'ajax_insert_external_link' ] );
        add_action( 'wp_ajax_ailg_insert_link',         [ 'AILG_Suggester',  'ajax_insert_link'    ] );
        add_action( 'wp_ajax_ailg_accept_suggestion',   [ 'AILG_Suggester',  'ajax_accept_suggestion' ] );
        add_action( 'wp_ajax_ailg_dismiss_suggestion',  [ 'AILG_Suggester',  'ajax_dismiss'        ] );
        add_action( 'wp_ajax_ailg_bulk_accept_suggs',   [ 'AILG_Suggester',  'ajax_bulk_accept'    ] );
        add_action( 'wp_ajax_ailg_sync_models',         [ 'AILG_AI',         'ajax_sync_models'    ] );
        add_action( 'wp_ajax_ailg_sync_aipuffer',       [ 'AILG_AIPuffer',   'ajax_sync_bots'      ] );
        add_action( 'wp_ajax_ailg_sync_vmsb_gsc',      [ 'AILG_VMSB_Integration', 'ajax_sync_gsc' ] );
        add_action( 'wp_ajax_ailg_get_striking_distance', [ 'AILG_VMSB_Integration', 'ajax_get_striking_distance' ] );
        add_action( 'ailg_autolink_on_publish_event',   [ 'AILG_AutoLinker', 'auto_link_newly_published_post' ] );
        add_action( 'wp_ajax_ailg_test_connection',     [ 'AILG_AI',         'ajax_test_connection'] );
        add_action( 'wp_ajax_ailg_get_report_data',     [ 'AILG_Reports',    'ajax_report_data'    ] );
        add_action( 'wp_ajax_ailg_get_cannibalization', [ 'AILG_Reports',    'ajax_get_cannibalization' ] );
        add_action( 'wp_ajax_ailg_save_rule',           [ 'AILG_Automation', 'ajax_save_rule'      ] );
        add_action( 'wp_ajax_ailg_delete_rule',         [ 'AILG_Automation', 'ajax_delete_rule'    ] );
        add_action( 'wp_ajax_ailg_get_automation_queue', [ 'AILG_Automation', 'ajax_get_automation_queue' ] );
        add_action( 'wp_ajax_ailg_run_automation_single', [ 'AILG_Automation', 'ajax_run_automation_single' ] );
        add_action( 'wp_ajax_ailg_install_template',    [ 'AILG_Automation', 'ajax_install_template'] );
        add_action( 'wp_ajax_ailg_get_orphaned',        [ 'AILG_Reports',    'ajax_orphaned'       ] );
        add_action( 'wp_ajax_ailg_fix_orphaned',        [ 'AILG_Reports',    'ajax_fix_orphaned'   ] );
        add_action( 'wp_ajax_ailg_get_broken_queue',    [ 'AILG_Reports',    'ajax_get_broken_queue' ] );
        add_action( 'wp_ajax_ailg_scan_broken_single',  [ 'AILG_Reports',    'ajax_scan_broken_single' ] );
        add_action( 'wp_ajax_ailg_unlink_broken',       [ 'AILG_Reports',    'ajax_unlink_broken'  ] );
        add_action( 'wp_ajax_ailg_get_link_graph',      [ 'AILG_Reports',    'ajax_get_link_graph' ] );
        add_action( 'wp_ajax_ailg_semantic_audit',      [ 'AILG_Reports',    'ajax_semantic_audit' ] );
        add_action( 'wp_ajax_ailg_get_link_decay',     [ 'AILG_Reports',    'ajax_get_link_decay' ] );
        add_action( 'wp_ajax_ailg_groom_redirects',     [ 'AILG_Reports',    'ajax_groom_redirects'] );
        add_action( 'wp_ajax_ailg_global_swap',         [ 'AILG_Reports',    'ajax_global_swap'    ] );
        add_action( 'wp_ajax_ailg_get_scan_queue',      [ 'AILG_Scanner',    'ajax_get_scan_queue' ] );
        add_action( 'wp_ajax_ailg_scan_single_item',    [ 'AILG_Scanner',    'ajax_scan_single_item' ] );
        add_action( 'wp_ajax_ailg_run_index_batch',     [ 'AILG_LinkIndex',  'ajax_run_batch'      ] );
        add_action( 'wp_ajax_ailg_save_settings',       [ $this,             'ajax_save_settings'  ] );
        add_action( 'wp_ajax_ailg_get_dashboard_stats', [ 'AILG_Reports',    'ajax_dashboard_stats'] );
        add_action( 'wp_ajax_ailg_get_logs',           [ 'AILG_Log',        'ajax_get_logs'       ] );
        add_action( 'wp_ajax_ailg_clear_logs',         [ 'AILG_Log',        'ajax_clear_logs'     ] );
        add_action( 'wp_ajax_ailg_get_revisions',      [ 'AILG_Revisions',  'ajax_get_revisions'  ] );
        add_action( 'wp_ajax_ailg_restore_revision',   [ 'AILG_Revisions',  'ajax_restore_revision' ] );
        add_action( 'wp_ajax_ailg_restore_batch',      [ 'AILG_Revisions',  'ajax_restore_batch'  ] );

        // Cron
        add_action( 'ailg_cron_daily',          [ 'AILG_Automation', 'run_scheduled_tasks' ] );
        add_action( 'ailg_cron_daily',          [ 'AILG_Revisions',  'prune' ] );
        add_action( 'ailg_scan_single_post',    [ 'AILG_Scanner',    'scan_post'           ] );
        add_action( 'ailg_run_automation_post', [ 'AILG_Automation', 'handle_post_trigger' ], 10, 2 );

        // Front-end
        add_filter( 'the_content', [ 'AILG_AutoLinker', 'process_content' ], 20 );

        // REST
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // Front-end tracking
        add_action( 'wp_footer', [ $this, 'inject_tracking_script' ] );

        // Schedule daily cron if not already scheduled
        if ( ! wp_next_scheduled( 'ailg_cron_daily' ) ) {
            wp_schedule_event( time(), 'daily', 'ailg_cron_daily' );
        }
    }

    public function inject_tracking_script(): void {
        if ( is_admin() ) return;
        ?>
        <script>
        document.addEventListener('click', function(e) {
            var link = e.target.closest('a');
            if (!link || !link.href) return;

            // Check if it's an internal link
            if (link.hostname === window.location.hostname) {
                // We'll look for custom data attributes that our plugin might add in the future,
                // or just track clicks to known internal pages.
                // For a "Genius" feature, we can track all internal clicks.
                fetch('<?php echo esc_url( rest_url( 'ailg/v1/track' ) ); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        post_id: <?php echo (int) get_the_ID(); ?>,
                        target_url: link.href // We'll resolve this on the server
                    })
                });
            }
        });
        </script>
        <?php
    }

    private function handle_gsc_callback(): void {
        if ( ! is_admin() || ! isset( $_GET['code'], $_GET['state'] ) || ! current_user_can( 'manage_options' ) ) return;
        if ( ! wp_verify_nonce( sanitize_key( $_GET['state'] ), 'ailg_gsc_oauth' ) ) return;

        $code = sanitize_text_field( $_GET['code'] );
        $client_id     = get_option( 'ailg_gsc_client_id' );
        $client_secret = AILG_Secrets::get( 'ailg_gsc_client_secret' );

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => admin_url( 'admin.php?page=ailg-settings' ),
                'grant_type'    => 'authorization_code',
            ],
        ] );

        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $data['refresh_token'] ) ) {
                AILG_Secrets::set( 'ailg_gsc_refresh_token', (string) $data['refresh_token'] );
                set_transient( 'ailg_gsc_access_token', $data['access_token'], 3500 );
            }
        }

        wp_redirect( admin_url( 'admin.php?page=ailg-settings&gsc_connected=1' ) );
        exit;
    }

    public static function activate(): void {
        self::create_tables();
        self::seed_default_options();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'ailg_cron_daily' );
        flush_rewrite_rules();
    }

    /**
     * Bring the schema up to date.
     *
     * This used to trust the stored version string on its own. That is fine
     * until new tables are added without the constant being bumped - which is
     * exactly what happened with ailg_link_index, ailg_revisions and
     * ailg_logs: the stored version already read '1.5', so the check passed,
     * create_tables() never ran, and the first call into the link index died
     * with "Table 'ailg_link_index' doesn't exist".
     *
     * A version match is now a hint, not proof. The tables are checked
     * directly, so a schema missing for any reason - a failed activation, a
     * partial restore, a newer plugin folder dropped over an older install -
     * repairs itself on the next admin request.
     */
    private function maybe_upgrade_db(): void {
        if ( get_option( 'ailg_db_version' ) === AILG_DB_VERSION && self::schema_intact() ) {
            return;
        }

        self::dedupe_links();
        self::create_tables();
        self::drop_retired_columns();
        AILG_Secrets::migrate_plaintext();
        update_option( 'ailg_db_version', AILG_DB_VERSION );
    }

    /**
     * Are the tables this build needs actually present?
     *
     * Cached for an hour so the check is not paid on every admin request, and
     * cleared whenever create_tables() runs.
     */
    public static function schema_intact(): bool {
        if ( '1' === get_transient( 'ailg_schema_ok' ) ) {
            return true;
        }

        global $wpdb;

        foreach ( self::table_names() as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                return false;
            }
        }

        set_transient( 'ailg_schema_ok', '1', HOUR_IN_SECONDS );
        return true;
    }

    /**
     * Is this a local or development install?
     *
     * The plugin used to disable TLS certificate verification unconditionally,
     * with a comment explaining it was needed for local domains. That
     * concession shipped to production, where it meant the AI Puffer request -
     * which carries the API key in an Authorization header - would accept any
     * certificate presented to it. A self-signed cert is a legitimate reason
     * to skip verification on a laptop and never a reason to skip it on a live
     * site, so the decision now depends on where the code is running.
     */
    public static function is_local_env(): bool {
        if ( defined( 'AILG_ALLOW_INSECURE_SSL' ) && AILG_ALLOW_INSECURE_SSL ) {
            return true;
        }
        if ( function_exists( 'wp_get_environment_type' ) ) {
            return in_array( wp_get_environment_type(), [ 'local', 'development' ], true );
        }
        return false;
    }

    /** Whether outbound requests should verify TLS certificates. */
    public static function ssl_verify(): bool {
        return ! self::is_local_env();
    }

    /**
     * Detect a site-address change and rebuild the link index.
     *
     * The index stores an absolute target_url for every link, and resolves
     * target_id from it at scan time. Both are correct only for the domain the
     * scan ran on. Move the site - local to staging, staging to live, http to
     * https - and every stored URL still points at the old host: internal
     * links stop resolving, so unresolved() reports the entire site as broken
     * internal links, orphans() reports everything as an orphan, and PageRank
     * collapses to nothing.
     *
     * Nothing detected this. The index would sit there confidently wrong until
     * somebody happened to press Rebuild. So the home URL is recorded
     * alongside the index, and a mismatch discards it and starts again.
     */
    public static function check_site_moved(): void {
        $seen = get_option( 'ailg_indexed_home_url' );
        $now  = home_url();

        if ( $seen === $now ) {
            return;
        }

        if ( $seen && class_exists( 'AILG_LinkIndex' ) ) {
            AILG_LinkIndex::rebuild();

            if ( class_exists( 'AILG_Log' ) ) {
                AILG_Log::warn( 'index', "Site address changed from {$seen} to {$now}; the link index was rebuilt." );
            }

            // Cached URL statuses were recorded against the old domain.
            //
            // Deleting the rows with raw SQL is not enough: WordPress caches
            // options in memory, and on a site with a persistent object cache
            // (Redis, Memcached) the values survive the DELETE entirely. Read
            // the names, then let delete_transient() handle each one so both
            // the row and every cache layer above it are cleared.
            global $wpdb;
            $names = $wpdb->get_col(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_ailg\_url\_%'"
            );
            foreach ( $names as $name ) {
                delete_transient( substr( $name, strlen( '_transient_' ) ) );
            }

            set_transient( 'ailg_site_moved_notice', $seen, WEEK_IN_SECONDS );
        }

        update_option( 'ailg_indexed_home_url', $now, false );
    }

    /** Tell the operator why the index emptied itself. */
    public static function site_moved_notice(): void {
        $old = get_transient( 'ailg_site_moved_notice' );
        if ( ! $old || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        printf(
            '<div class="notice notice-warning is-dismissible"><p><strong>AI Link Genius:</strong> %s</p></div>',
            esc_html( sprintf(
                'This site moved from %s. The internal link index was rebuilt, and is re-reading your content in the background. Orphan and broken-link reports will be incomplete until it finishes.',
                $old
            ) )
        );
    }

    /**
     * Remove columns that were declared and never used.
     *
     * ailg_link_analytics carried impressions and ctr from the first version.
     * Nothing ever wrote to either - the table records clicks only, so a CTR
     * could not be derived even in principle - and no query read them. dbDelta
     * adds columns but never removes them, so they have to go explicitly.
     * Safe by inspection: both were NOT NULL DEFAULT 0 and no code path set
     * them, so every row holds zero.
     */
    private static function drop_retired_columns(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ailg_link_analytics';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        foreach ( [ 'impressions', 'ctr' ] as $column ) {
            $exists = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
            if ( $exists ) {
                $wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `{$column}`" );
            }
        }
    }

    /**
     * Collapse duplicate rows in ailg_links.
     *
     * Three call sites insert with $wpdb->replace(), which only replaces on a
     * PRIMARY or UNIQUE conflict. ailg_links had neither on (post_id,
     * target_id) - just a bare PRIMARY KEY on an auto-increment id - so every
     * "replace" was really an insert, and the table gained a fresh row each
     * time the same suggestion was re-accepted. That inflated the dashboard
     * link count and drew duplicate edges on the Link Map.
     *
     * The UNIQUE key that makes replace() behave as intended is added by
     * dbDelta, but MySQL refuses to create it while duplicates exist, so this
     * has to run first. The newest row for each pair is kept.
     */
    private static function dedupe_links(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ailg_links';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // Already unique, so already deduped.
        if ( $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'post_target'" ) ) {
            return;
        }

        $wpdb->query(
            "DELETE a FROM `{$table}` a
             INNER JOIN `{$table}` b
                ON a.post_id = b.post_id AND a.target_id = b.target_id AND a.id < b.id"
        );
    }

    /** Every table this plugin owns, fully prefixed. */
    public static function table_names(): array {
        global $wpdb;

        return array_map(
            static fn( $name ) => $wpdb->prefix . 'ailg_' . $name,
            [ 'suggestions', 'links', 'automation_rules', 'broken_links', 'link_analytics', 'revisions', 'link_index', 'logs' ]
        );
    }

    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Re-verified on the next request rather than trusted from before.
        delete_transient( 'ailg_schema_ok' );

        dbDelta( "CREATE TABLE {$wpdb->prefix}ailg_suggestions (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id     BIGINT UNSIGNED NOT NULL,
            target_id   BIGINT UNSIGNED NOT NULL,
            anchor_text VARCHAR(500)    NOT NULL DEFAULT '',
            context     TEXT,
            score       FLOAT           NOT NULL DEFAULT 0,
            is_bridge   TINYINT(1)      NOT NULL DEFAULT 0,
            is_image    TINYINT(1)      NOT NULL DEFAULT 0,
            is_ghost    TINYINT(1)      NOT NULL DEFAULT 0,
            provider    VARCHAR(50)     NOT NULL DEFAULT 'ai',
            model_used  VARCHAR(100),
            status      ENUM('pending','accepted','dismissed','auto_applied') NOT NULL DEFAULT 'pending',
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY target_id (target_id),
            KEY status (status)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}ailg_links (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id     BIGINT UNSIGNED NOT NULL,
            target_id   BIGINT UNSIGNED NOT NULL,
            anchor_text VARCHAR(500)    NOT NULL DEFAULT '',
            target_url  VARCHAR(2083)   NOT NULL DEFAULT '',
            link_type   ENUM('internal','external') NOT NULL DEFAULT 'internal',
            nofollow    TINYINT(1)      NOT NULL DEFAULT 0,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_target (post_id, target_id),
            KEY post_id (post_id),
            KEY target_id (target_id),
            KEY created_at (created_at)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}ailg_automation_rules (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_name     VARCHAR(255)    NOT NULL,
            trigger_type  VARCHAR(50)     NOT NULL DEFAULT 'on_publish',
            post_types    TEXT,
            categories    TEXT,
            tags          TEXT,
            ai_provider   VARCHAR(50)     NOT NULL DEFAULT 'openai',
            ai_model      VARCHAR(100),
            max_links     TINYINT         NOT NULL DEFAULT 3,
            min_score     FLOAT           NOT NULL DEFAULT 0.7,
            auto_insert   TINYINT(1)      NOT NULL DEFAULT 0,
            anchor_mode   VARCHAR(50)     NOT NULL DEFAULT 'ai_optimal',
            avoid_noindex TINYINT(1)      NOT NULL DEFAULT 1,
            is_active     TINYINT(1)      NOT NULL DEFAULT 1,
            last_run      DATETIME,
            total_applied BIGINT          NOT NULL DEFAULT 0,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}ailg_broken_links (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id     BIGINT UNSIGNED NOT NULL,
            url         VARCHAR(2083)   NOT NULL,
            http_code   SMALLINT        NOT NULL DEFAULT 0,
            anchor_text VARCHAR(500),
            last_check  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status      ENUM('broken','redirect','ok','pending') NOT NULL DEFAULT 'pending',
            PRIMARY KEY (id),
            KEY post_id (post_id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}ailg_link_analytics (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id     BIGINT UNSIGNED NOT NULL,
            target_id   BIGINT UNSIGNED NOT NULL,
            clicks      BIGINT          NOT NULL DEFAULT 0,
            last_update DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_target (post_id, target_id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}ailg_revisions (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id      BIGINT UNSIGNED NOT NULL,
            batch_id     VARCHAR(50)     NOT NULL DEFAULT '',
            before_value LONGTEXT        NOT NULL,
            reason       VARCHAR(255)    NOT NULL DEFAULT '',
            status       VARCHAR(20)     NOT NULL DEFAULT 'active',
            user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY batch_id (batch_id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}ailg_link_index (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id   BIGINT UNSIGNED NOT NULL,
            source_type VARCHAR(50)     NOT NULL DEFAULT 'post',
            target_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            target_url  VARCHAR(2083)   NOT NULL DEFAULT '',
            target_host VARCHAR(255)    NOT NULL DEFAULT '',
            anchor_text VARCHAR(500)    NOT NULL DEFAULT '',
            is_internal TINYINT(1)      NOT NULL DEFAULT 1,
            is_managed  TINYINT(1)      NOT NULL DEFAULT 0,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source_id (source_id),
            KEY target_id (target_id),
            KEY is_internal (is_internal)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}ailg_logs (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level       VARCHAR(20)     NOT NULL DEFAULT 'info',
            component   VARCHAR(50)     NOT NULL DEFAULT 'system',
            message     TEXT            NOT NULL,
            data        LONGTEXT,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY level (level),
            KEY component (component)
        ) $charset;" );
    }

    public static function seed_default_options(): void {
        $defaults = [
            'ailg_openai_key'            => '',
            'ailg_openai_model'          => 'gpt-4o',
            'ailg_openai_models_list'    => [],
            'ailg_google_key'            => '',
            'ailg_google_model'          => 'gemini-2.0-flash',
            'ailg_google_models_list'    => [],
            'ailg_openrouter_key'        => '',
            'ailg_openrouter_model'      => 'anthropic/claude-3-5-sonnet',
            'ailg_openrouter_models_list'=> [],
            'ailg_aipuffer_url'          => '',
            'ailg_aipuffer_key'          => '',
            'ailg_aipuffer_bot_id'       => '',
            'ailg_aipuffer_kb_id'        => '',
            'ailg_gsc_client_id'         => '',
            'ailg_gsc_client_secret'     => '',
            'ailg_gsc_refresh_token'     => '',
            'ailg_gsc_property'          => '',
            'ailg_ollama_host'           => 'http://localhost:11434',
            'ailg_ollama_model'          => 'llama3.2',
            'ailg_ollama_models_list'    => [],
            'ailg_omniroute_url'         => '',
            'ailg_omniroute_key'         => '',
            'ailg_omniroute_model'       => 'gpt-4o',
            'ailg_omniroute_models_list' => [],
            'ailg_default_provider'      => 'aipuffer',
            'ailg_fallback_providers'    => [ 'openai', 'google' ],
            'ailg_max_suggestions'       => 5,
            'ailg_min_score'             => 0.65,
            'ailg_auto_link_enabled'     => false,
            'ailg_auto_link_post_types'  => [ 'post', 'page' ],
            'ailg_exclude_post_ids'      => [],
            'ailg_exclude_categories'    => [],
            'ailg_link_limit_per_post'   => 10,
            'ailg_same_link_limit'       => 2,
            'ailg_ignore_words'          => [ 'click here', 'read more', 'here', 'this', 'link' ],
            'ailg_nofollow_external'     => false,
            'ailg_open_external_new_tab' => true,
            'ailg_scan_on_publish'       => true,
            'ailg_restrict_to_silo'      => false,
            'ailg_business_dna'          => '',
            'ailg_topical_niche'         => '',
            'ailg_topical_entities'      => '',
            'ailg_aipuffer_failures'     => 0,
            'ailg_aipuffer_last_fail'    => 0,
            'ailg_custom_prompt'         => '',
            'ailg_license_key'           => '',
        ];
        foreach ( $defaults as $key => $val ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $val );
            }
        }
    }

    public function register_menus(): void {
        $icon_svg = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>' );

        add_menu_page( 'AI Link Genius Pro', 'Link Genius', 'manage_options', 'ailg-dashboard', [ $this, 'page_dashboard' ], $icon_svg, 30 );
        add_submenu_page( 'ailg-dashboard', 'Dashboard',          'Dashboard',          'manage_options', 'ailg-dashboard',   [ $this, 'page_dashboard'   ] );
        add_submenu_page( 'ailg-dashboard', 'Suggestions',        'Suggestions',        'manage_options', 'ailg-suggestions', [ $this, 'page_suggestions' ] );
        add_submenu_page( 'ailg-dashboard', 'Automation Rules',   'Automation',         'manage_options', 'ailg-automation',  [ $this, 'page_automation'  ] );
        add_submenu_page( 'ailg-dashboard', 'Reports & Analytics','Reports',            'manage_options', 'ailg-reports',     [ $this, 'page_reports'     ] );
        add_submenu_page( 'ailg-dashboard', 'Orphaned Content',   'Orphaned Content',   'manage_options', 'ailg-orphaned',    [ $this, 'page_orphaned'    ] );
        add_submenu_page( 'ailg-dashboard', 'Broken Links',       'Broken Links',       'manage_options', 'ailg-broken',      [ $this, 'page_broken'      ] );
        add_submenu_page( 'ailg-dashboard', 'Link Map',           'Link Map',           'manage_options', 'ailg-linkmap',     [ $this, 'page_linkmap'     ] );
        add_submenu_page( 'ailg-dashboard', 'System Logs',        'Activity Logs',      'manage_options', 'ailg-logs',        [ $this, 'page_logs'        ] );
        add_submenu_page( 'ailg-dashboard', 'Settings',           'Settings',           'manage_options', 'ailg-settings',    [ $this, 'page_settings'    ] );
    }

    public function enqueue_assets( string $hook ): void {
        // Only load on our plugin pages and post-editing screens
        if ( strpos( $hook, 'ailg' ) === false && strpos( $hook, 'post' ) === false ) return;

        wp_enqueue_style(  'ailg-admin', AILG_URL . 'assets/css/admin.css', [], AILG_VERSION );
        wp_enqueue_script( 'ailg-admin', AILG_URL . 'assets/js/admin.js', [ 'jquery' ], AILG_VERSION, true );

        wp_localize_script( 'ailg-admin', 'AILG', [
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'admin_url' => admin_url(),
            'nonce'     => wp_create_nonce( 'ailg_nonce' ),
            'version'   => AILG_VERSION,
        ] );
    }

    public function enqueue_gutenberg_assets(): void {
        wp_enqueue_script(
            'ailg-gutenberg',
            AILG_URL . 'assets/js/gutenberg.js',
            [ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-compose', 'wp-i18n', 'wp-blocks' ],
            AILG_VERSION,
            true
        );

        wp_localize_script( 'ailg-gutenberg', 'AILG', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ailg_nonce' ),
        ] );
    }

    public function register_metaboxes(): void {
        $post_types = (array) get_option( 'ailg_auto_link_post_types', [ 'post', 'page' ] );
        foreach ( $post_types as $pt ) {
            add_meta_box( 'ailg_link_box', '🔗 AI Link Genius — Link Suggestions', [ $this, 'render_metabox' ], $pt, 'normal', 'high' );
        }
    }

    public function render_metabox( WP_Post $post ): void {
        $post_id = $post->ID;
        echo '<div id="ailg-metabox" data-post-id="' . esc_attr( $post_id ) . '">';
        echo '<div class="ailg-metabox-header"><span class="ailg-badge">AI Powered</span><button class="ailg-btn ailg-btn-primary" id="ailg-analyze-btn" data-post-id="' . esc_attr( $post_id ) . '">🤖 Analyze &amp; Suggest Links</button></div>';
        echo '<div id="ailg-metabox-results" class="ailg-metabox-results"><p class="ailg-hint">Click "Analyze &amp; Suggest Links" to get AI-powered internal linking suggestions for this post.</p></div>';
        echo '</div>';
        wp_nonce_field( 'ailg_nonce', 'ailg_metabox_nonce' );
    }

    public function on_post_status_transition( string $new_status, string $old_status, WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( 'publish' !== $new_status ) return;

        $post_types = (array) get_option( 'ailg_auto_link_post_types', [ 'post', 'page' ] );
        if ( ! in_array( $post->post_type, $post_types, true ) ) return;

        $post_id = $post->ID;
        $trigger = ( 'publish' !== $old_status ) ? 'on_publish' : 'on_update';

        // 1. Basic Scan (legacy behavior, always runs if enabled)
        if ( get_option( 'ailg_scan_on_publish', true ) ) {
            if ( ! wp_next_scheduled( 'ailg_scan_single_post', [ $post_id ] ) ) {
                wp_schedule_single_event( time() + 60, 'ailg_scan_single_post', [ $post_id ] );
            }
        }

        // 2. Automation Rules
        if ( ! wp_next_scheduled( 'ailg_run_automation_post', [ $post_id, $trigger ] ) ) {
            wp_schedule_single_event( time() + 65, 'ailg_run_automation_post', [ $post_id, $trigger ] );
        }

        // 3. Zero-Touch Auto-Link Pipeline on publish
        if ( 'publish' !== $old_status && get_option( 'ailg_auto_link_on_publish', true ) ) {
            if ( ! wp_next_scheduled( 'ailg_autolink_on_publish_event', [ $post_id ] ) ) {
                wp_schedule_single_event( time() + 5, 'ailg_autolink_on_publish_event', [ $post_id ] );
            }
        }
    }

    public function ajax_save_settings(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $scalar_fields = [
            'ailg_openai_key', 'ailg_openai_model', 'ailg_google_key', 'ailg_google_model',
            'ailg_openrouter_key', 'ailg_openrouter_model', 'ailg_ollama_host', 'ailg_ollama_model',
            'ailg_omniroute_url', 'ailg_omniroute_key', 'ailg_omniroute_model',
            'ailg_aipuffer_url', 'ailg_aipuffer_key', 'ailg_aipuffer_bot_id', 'ailg_aipuffer_kb_id',
            'ailg_gsc_client_id', 'ailg_gsc_client_secret', 'ailg_gsc_property',
            'ailg_default_provider', 'ailg_max_suggestions', 'ailg_min_score',
            'ailg_link_limit_per_post', 'ailg_same_link_limit',
            'ailg_custom_prompt',
            'ailg_business_dna', 'ailg_topical_niche', 'ailg_topical_entities',
            'ailg_license_key', 'ailg_github_token',
        ];

        foreach ( $scalar_fields as $field ) {
            if ( ! isset( $_POST[ $field ] ) ) {
                continue;
            }

            $val = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );

            // Credentials go through AILG_Secrets, which encrypts at rest. The
            // form renders a mask rather than the stored key, so a submission
            // that still contains the mask means "leave it alone" - saving it
            // verbatim would replace a working key with a row of bullets.
            if ( AILG_Secrets::is_secret( $field ) ) {
                if ( ! AILG_Secrets::is_masked( $val ) ) {
                    AILG_Secrets::set( $field, $val );
                }
                continue;
            }

            update_option( $field, $val );
        }

        // Toggle (checkbox) fields — must explicitly save 0 if not present
        $toggle_fields = [
            'ailg_auto_link_enabled',
            'ailg_auto_link_on_publish',
            'ailg_scan_on_publish',
            'ailg_restrict_to_silo',
            'ailg_nofollow_external',
            'ailg_open_external_new_tab',
        ];

        foreach ( $toggle_fields as $field ) {
            update_option( $field, isset( $_POST[ $field ] ) ? 1 : 0 );
        }

        // Array fields
        if ( isset( $_POST['ailg_auto_link_post_types'] ) ) {
            update_option( 'ailg_auto_link_post_types', array_map( 'sanitize_key', (array) $_POST['ailg_auto_link_post_types'] ) );
        } else {
            update_option( 'ailg_auto_link_post_types', [] );
        }

        if ( isset( $_POST['ailg_fallback_providers'] ) ) {
            update_option( 'ailg_fallback_providers', array_map( 'sanitize_key', (array) $_POST['ailg_fallback_providers'] ) );
        }

        if ( isset( $_POST['ailg_ignore_words'] ) ) {
            $words = array_map( 'sanitize_text_field', explode( "\n", wp_unslash( $_POST['ailg_ignore_words'] ) ) );
            update_option( 'ailg_ignore_words', array_filter( array_map( 'trim', $words ) ) );
        }

        wp_send_json_success( [ 'message' => 'Settings saved successfully!' ] );
    }

    public function register_rest_routes(): void {
        register_rest_route( 'ailg/v1', '/suggestions/(?P<post_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ 'AILG_Suggester', 'rest_get_suggestions' ],
            'permission_callback' => fn( $r ) => current_user_can( 'edit_post', (int) $r['post_id'] ),
            'args'                => [ 'post_id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) ] ],
        ] );
        register_rest_route( 'ailg/v1', '/stats', [
            'methods'             => 'GET',
            'callback'            => [ 'AILG_Reports', 'rest_get_stats' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );
        register_rest_route( 'ailg/v1', '/track', [
            'methods'             => 'POST',
            'callback'            => [ 'AILG_Reports', 'rest_track_click' ],
            'permission_callback' => '__return_true', // Public
        ] );
    }

    // ─── Pages ───────────────────────────────────────────────────────────────
    public function page_dashboard():   void { echo $this->render_page( 'dashboard'   ); }
    public function page_suggestions(): void { echo $this->render_page( 'suggestions' ); }
    public function page_automation():  void { echo $this->render_page( 'automation'  ); }
    public function page_reports():     void { echo $this->render_page( 'reports'     ); }
    public function page_orphaned():    void { echo $this->render_page( 'orphaned'    ); }
    public function page_broken():      void { echo $this->render_page( 'broken'      ); }
    public function page_linkmap():     void { echo $this->render_page( 'linkmap'     ); }
    public function page_logs():        void { echo $this->render_page( 'logs'        ); }
    public function page_settings():    void { echo $this->render_page( 'settings'    ); }

    private function render_page( string $page ): string {
        ob_start();
        switch ( $page ) {
            case 'dashboard':   AILG_Pages::dashboard();   break;
            case 'suggestions': AILG_Pages::suggestions(); break;
            case 'automation':  AILG_Pages::automation();  break;
            case 'reports':     AILG_Pages::reports();     break;
            case 'orphaned':    AILG_Pages::orphaned();    break;
            case 'broken':      AILG_Pages::broken();      break;
            case 'linkmap':     AILG_Pages::linkmap();     break;
            case 'logs':        AILG_Pages::logs();        break;
            case 'settings':    AILG_Pages::settings();    break;
        }
        return (string) ob_get_clean();
    }
}