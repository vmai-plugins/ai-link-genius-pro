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

    public static function ajax_run_automation(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;
        $rule_id = (int) ( $_POST['rule_id'] ?? 0 );
        $rule    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ailg_automation_rules WHERE id = %d",
            $rule_id
        ) );
        if ( ! $rule ) wp_send_json_error( 'Rule not found' );

        $post_types = json_decode( $rule->post_types ?: '["post"]', true );
        if ( ! is_array( $post_types ) ) $post_types = [ 'post' ];

        $posts = get_posts( [
            'post_type'      => $post_types,
            'posts_per_page' => 15, // Reduced for AJAX timeout safety
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ] );

        $applied = 0;
        foreach ( $posts as $post_id ) {
            if ( $applied >= (int) $rule->max_links ) break;
            $suggestions = AILG_AI::generate_suggestions( $post_id, $rule->ai_provider );
            foreach ( $suggestions as $s ) {
                if ( $applied >= (int) $rule->max_links ) break;
                if ( $s['score'] < (float) $rule->min_score ) continue;

                if ( $rule->auto_insert ) {
                    $post    = get_post( $post_id );
                    if ( ! $post ) continue;
                    $content = $post->post_content;
                    $url     = (string) get_permalink( $s['target_id'] );
                    $link    = '<a href="' . esc_url( $url ) . '">' . esc_html( $s['anchor_text'] ) . '</a>';
                    $new     = AILG_LinkHelper::insert( $content, $s['anchor_text'], $link );
                    if ( $new !== $content ) {
                        wp_update_post( [ 'ID' => $post_id, 'post_content' => $new ] );
                        $applied++;
                    }
                }

                $wpdb->insert( "{$wpdb->prefix}ailg_suggestions", [
                    'post_id'     => $s['post_id'],
                    'target_id'   => $s['target_id'],
                    'anchor_text' => $s['anchor_text'],
                    'context'     => $s['context'],
                    'score'       => $s['score'],
                    'provider'    => $s['provider'],
                    'model_used'  => $s['model_used'],
                    'status'      => $rule->auto_insert ? 'auto_applied' : 'pending',
                ] );
            }
        }

        $wpdb->update(
            "{$wpdb->prefix}ailg_automation_rules",
            [
                'last_run'      => current_time( 'mysql' ),
                'total_applied' => (int) $rule->total_applied + $applied,
            ],
            [ 'id' => $rule_id ]
        );

        wp_send_json_success( [ 'applied' => $applied, 'posts_processed' => count( $posts ) ] );
    }

    public static function run_scheduled_tasks(): void {
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
            $posts = get_posts( [ 'post_type' => $post_types, 'posts_per_page' => 20, 'fields' => 'ids' ] );
            foreach ( $posts as $pid ) {
                AILG_Scanner::scan_post( $pid );
            }
            $wpdb->update(
                "{$wpdb->prefix}ailg_automation_rules",
                [ 'last_run' => current_time( 'mysql' ) ],
                [ 'id' => $rule->id ]
            );
        }
    }
}