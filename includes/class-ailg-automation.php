<?php
/**
 * Automation Class
 */

defined( 'ABSPATH' ) || exit;

class AILG_Automation {

    public static function ajax_save_rule(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;
        $data = [
            'rule_name'    => sanitize_text_field( (string) ( $_POST['rule_name']    ?? '' ) ),
            'trigger_type' => sanitize_key(        (string) ( $_POST['trigger_type'] ?? 'manual' ) ),
            'post_types'   => wp_json_encode( array_map( 'sanitize_key', (array) ( $_POST['post_types'] ?? [] ) ) ),
            'ai_provider'  => sanitize_key(        (string) ( $_POST['ai_provider']  ?? 'openai' ) ),
            'ai_model'     => sanitize_text_field( (string) ( $_POST['ai_model']     ?? '' ) ),
            'max_links'    => (int)   ( $_POST['max_links']  ?? 3 ),
            'min_score'    => (float) ( $_POST['min_score']  ?? 0.7 ),
            'auto_insert'  => isset( $_POST['auto_insert'] ) ? 1 : 0,
            'anchor_mode'  => sanitize_key( (string) ( $_POST['anchor_mode'] ?? 'ai_optimal' ) ),
            'is_active'    => isset( $_POST['is_active'] ) ? 1 : 0,
        ];

        if ( empty( $data['rule_name'] ) ) wp_send_json_error( 'Rule name is required.' );

        $rule_id = (int) ( $_POST['rule_id'] ?? 0 );
        if ( $rule_id ) {
            $wpdb->update( "{$wpdb->prefix}ailg_automation_rules", $data, [ 'id' => $rule_id ] );
        } else {
            $wpdb->insert( "{$wpdb->prefix}ailg_automation_rules", $data );
            $rule_id = (int) $wpdb->insert_id;
        }

        wp_send_json_success( [ 'id' => $rule_id ] );
    }

    /**
     * Install a pre-defined automation template.
     */
    public static function ajax_install_template(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $template_id = sanitize_key( (string) ( $_POST['template'] ?? '' ) );
        global $wpdb;

        $rule = [];
        switch ( $template_id ) {
            case 'authority_builder':
                $rule = [
                    'rule_name'    => 'Authority Builder: Push Support to Pillars',
                    'trigger_type' => 'on_publish',
                    'post_types'   => wp_json_encode(['post']),
                    'max_links'    => 3,
                    'min_score'    => 0.8,
                    'auto_insert'  => 0,
                    'anchor_mode'  => 'ai_optimal'
                ];
                break;
            case 'revenue_funnel':
                $rule = [
                    'rule_name'    => 'Revenue Funnel: Blog to Service/Product',
                    'trigger_type' => 'on_publish',
                    'post_types'   => wp_json_encode(['post']),
                    'max_links'    => 2,
                    'min_score'    => 0.75,
                    'auto_insert'  => 0,
                    'anchor_mode'  => 'ai_optimal'
                ];
                break;
            case 'page1_booster':
                $rule = [
                    'rule_name'    => 'Page 1 Booster: Boost Striking Distance',
                    'trigger_type' => 'daily',
                    'post_types'   => wp_json_encode(['post', 'page']),
                    'max_links'    => 5,
                    'min_score'    => 0.85,
                    'auto_insert'  => 0,
                    'anchor_mode'  => 'ai_optimal'
                ];
                break;
            case 'hands_free':
                $rule = [
                    'rule_name'    => 'Hands-Free Auto-Link (Strict)',
                    'trigger_type' => 'on_publish',
                    'post_types'   => wp_json_encode(['post']),
                    'max_links'    => 2,
                    'min_score'    => 0.92,
                    'auto_insert'  => 1,
                    'anchor_mode'  => 'ai_optimal'
                ];
                break;
        }

        if ( empty($rule) ) wp_send_json_error( 'Invalid template selected.' );

        $rule['ai_provider'] = get_option('ailg_default_provider', 'aipuffer');
        $rule['is_active']   = 1;

        $wpdb->insert( "{$wpdb->prefix}ailg_automation_rules", $rule );

        wp_send_json_success( [ 'message' => 'Template installed successfully!' ] );
    }

    public static function ajax_delete_rule(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
        global $wpdb;
        $id = (int) ( $_POST['rule_id'] ?? 0 );
        if ( $id ) $wpdb->delete( "{$wpdb->prefix}ailg_automation_rules", [ 'id' => $id ] );
        wp_send_json_success();
    }

    public static function ajax_get_automation_queue(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;
        $rule_id = (int) ( $_POST['rule_id'] ?? 0 );

        AILG_Log::info('Getting automation queue for rule ' . $rule_id, 'Automation');

        $rule    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ailg_automation_rules WHERE id = %d",
            $rule_id
        ) );

        if ( ! $rule ) {
            AILG_Log::error('Rule not found: ' . $rule_id, 'Automation');
            wp_send_json_error( 'Rule not found' );
        }

        $post_types = json_decode( $rule->post_types ?: '["post"]', true );
        if ( ! is_array( $post_types ) ) $post_types = [ 'post' ];

        $ids = get_posts( [
            'post_type'      => $post_types,
            'posts_per_page' => -1, // Full queue for manual trigger, JS handles batching
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'orderby'        => 'modified',
            'order'          => 'DESC'
        ] );

        wp_send_json_success( [ 'ids' => $ids ] );
    }

    public static function ajax_run_automation_single(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;
        $rule_id = (int) ( $_POST['rule_id'] ?? 0 );
        $post_id = (int) ( $_POST['post_id'] ?? 0 );

        AILG_Log::info("Running automation for rule $rule_id on post $post_id", 'Automation');

        $rule    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ailg_automation_rules WHERE id = %d",
            $rule_id
        ) );

        if ( ! $rule || ! $post_id ) {
            AILG_Log::warn("Invalid request. Rule: " . ($rule ? 'Exists' : 'Missing') . ", Post: $post_id", 'Automation');
            wp_send_json_error( 'Invalid request' );
        }

        $applied = self::run_rule_on_post( $rule, $post_id );

        wp_send_json_success( [ 'applied' => $applied ] );
    }

    public static function ajax_run_automation(): void {
        wp_send_json_error( 'Legacy runner disabled. Use the Queue system.' );
    }

    /**
     * Run rules triggered by post lifecycle events.
     */
    public static function handle_post_trigger( int $post_id, string $trigger ): void {
        global $wpdb;
        $rules = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ailg_automation_rules
             WHERE is_active = 1 AND trigger_type = %s",
            $trigger
        ) );

        if ( empty( $rules ) ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        foreach ( $rules as $rule ) {
            $post_types = json_decode( $rule->post_types ?: '["post"]', true );
            if ( ! is_array( $post_types ) || ! in_array( $post->post_type, $post_types, true ) ) {
                continue;
            }

            // We reuse the single run logic
            self::run_rule_on_post( $rule, $post_id );
        }
    }

    /**
     * Is this post excluded from search results?
     *
     * Checks the two SEO plugins that own this setting on most sites, plus a
     * filter for anything else, so a noindex set anywhere is respected.
     */
    private static function is_noindex( int $post_id ): bool {
        if ( ! $post_id ) {
            return false;
        }

        // Rank Math stores a serialised array of robots directives.
        $rm = get_post_meta( $post_id, 'rank_math_robots', true );
        if ( is_array( $rm ) && in_array( 'noindex', $rm, true ) ) {
            return true;
        }
        if ( is_string( $rm ) && str_contains( $rm, 'noindex' ) ) {
            return true;
        }

        // Yoast uses 0/1 on _yoast_wpseo_meta-robots-noindex.
        if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
            return true;
        }

        return (bool) apply_filters( 'ailg_is_noindex', false, $post_id );
    }

    private static function run_rule_on_post( $rule, int $post_id ): int {
        global $wpdb;
        $applied = 0;
        $ai_args = [];
        if ( ! empty( $rule->ai_model ) ) {
            $ai_args['model'] = $rule->ai_model;
        }

        $suggestions = AILG_AI::generate_suggestions( $post_id, $rule->ai_provider, '', $ai_args );

        // The rule's avoid_noindex flag defaults to on and the settings screen
        // presents it as a safety net, but nothing ever read it - automation
        // linked to noindex pages regardless. Passing internal authority to a
        // page you have told search engines to ignore is wasted at best.
        $avoid_noindex = ! isset( $rule->avoid_noindex ) || 1 === (int) $rule->avoid_noindex;

        foreach ( $suggestions as $s ) {
            if ( $s['score'] < (float) $rule->min_score ) continue;
            if ( $applied >= (int) $rule->max_links ) break;

            if ( $avoid_noindex && self::is_noindex( (int) $s['target_id'] ) ) continue;

            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ailg_suggestions WHERE post_id=%d AND target_id=%d AND anchor_text=%s LIMIT 1",
                $post_id, $s['target_id'], $s['anchor_text']
            ) );
            if ( $exists ) continue;

            if ( $rule->auto_insert ) {
                $result = AILG_Inserter::insert( $post_id, (int) $s['target_id'], [
                    'anchor' => $s['anchor_text'],
                    'reason' => 'Auto-applied rule: ' . $rule->rule_name,
                ] );
                if ( ! is_wp_error( $result ) ) {
                    $applied++;

                    // Also record in the links table so other features know about it
                    $wpdb->replace( "{$wpdb->prefix}ailg_links", [
                        'post_id'     => $post_id,
                        'target_id'   => (int) $s['target_id'],
                        'anchor_text' => $s['anchor_text'],
                        'target_url'  => get_permalink( (int) $s['target_id'] ),
                        'link_type'   => 'internal',
                    ] );
                }
            }

            $wpdb->insert( "{$wpdb->prefix}ailg_suggestions", [
                'post_id'     => $post_id,
                'target_id'   => $s['target_id'],
                'anchor_text' => $s['anchor_text'],
                'context'     => $s['context'],
                'score'       => $s['score'],
                'provider'    => $s['provider'],
                'model_used'  => $s['model_used'],
                'status'      => $rule->auto_insert ? 'auto_applied' : 'pending',
            ] );
        }

        if ( $applied > 0 ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}ailg_automation_rules
                 SET total_applied = total_applied + %d, last_run = %s
                 WHERE id = %d",
                $applied, current_time( 'mysql' ), $rule->id
            ) );
        }
        return $applied;
    }

    public static function run_scheduled_tasks(): void {
        // Heartbeat. Without a record of the last successful run there is no
        // way to tell a quiet site from a cron that has not fired in a month.
        update_option( 'ailg_cron_last_run', time(), false );

        global $wpdb;
        $rules = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ailg_automation_rules
             WHERE is_active = 1 AND trigger_type IN ('daily','weekly')"
        );

        foreach ( $rules as $rule ) {
            if ( $rule->trigger_type === 'weekly' ) {
                if ( $rule->last_run && strtotime( $rule->last_run ) > strtotime( '-7 days' ) ) continue;
            }

            $post_types = json_decode( $rule->post_types ?: '["post"]', true );
            if ( ! is_array( $post_types ) ) $post_types = [ 'post' ];

            $in = "'" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "'";

            // Fetch posts that haven't been scanned recently
            $posts = $wpdb->get_col( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
                 WHERE p.post_status = 'publish' AND p.post_type IN ({$in})
                   AND ( m.meta_value IS NULL OR m.meta_value < %d )
                 ORDER BY m.meta_value ASC, p.post_modified_gmt DESC
                 LIMIT 10",
                AILG_LinkIndex::SCAN_META,
                strtotime( '-7 days' )
            ) );

            foreach ( $posts as $pid ) {
                self::run_rule_on_post( $rule, $pid );
            }
        }
    }
}