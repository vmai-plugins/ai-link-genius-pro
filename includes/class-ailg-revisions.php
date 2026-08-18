<?php
/**
 * Undo log for every content change this plugin makes.
 *
 * Nothing was reversible before. The plugin inserted links, stripped links,
 * swapped URLs site-wide and rewrote redirect targets across every post - all
 * with wp_update_post() and no record of what the content had been. If a bulk
 * operation went wrong the only recovery was WordPress's own revisions, which
 * are disabled or capped on a great many production sites.
 *
 * Every write now stores the previous content first. Operations that touch
 * many posts share a batch id, so an entire bulk run can be undone in one go.
 */

defined( 'ABSPATH' ) || exit;

class AILG_Revisions {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ailg_revisions';
    }

    /**
     * Save the content of $post_id as it is right now.
     *
     * @param string $batch Optional batch id, so a bulk operation can be
     *                      rolled back as a unit.
     * @return int Revision row id, or 0 on failure.
     */
    public static function record( int $post_id, string $before, string $reason = '', string $batch = '' ): int {
        global $wpdb;

        $wpdb->insert( self::table(), [
            'post_id'      => $post_id,
            'batch_id'     => $batch,
            'before_value' => $before,
            'reason'       => mb_substr( $reason, 0, 250 ),
            'user_id'      => get_current_user_id(),
            'created_at'   => current_time( 'mysql', true ),
        ] );

        return (int) $wpdb->insert_id;
    }

    /** Start a batch. Returns the id to pass to record(). */
    public static function new_batch( string $label ): string {
        return substr( sanitize_key( $label ), 0, 24 ) . '-' . wp_generate_password( 8, false, false );
    }

    /**
     * Restore one revision.
     *
     * @return true|WP_Error
     */
    public static function restore( int $revision_id ): bool|WP_Error {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $revision_id ) );
        if ( ! $row ) {
            return new WP_Error( 'ailg_revision', 'That revision no longer exists.' );
        }
        if ( 'restored' === $row->status ) {
            return new WP_Error( 'ailg_revision', 'That change has already been undone.' );
        }
        if ( ! get_post( (int) $row->post_id ) ) {
            return new WP_Error( 'ailg_revision', 'The post this change belonged to has been deleted.' );
        }

        $saved = AILG_Inserter::save_content( (int) $row->post_id, (string) $row->before_value );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        $wpdb->update( self::table(), [ 'status' => 'restored' ], [ 'id' => $revision_id ] );

        // The index is derived from content, so undoing a content change has
        // to undo the edges that change created.
        AILG_LinkIndex::scan_post( (int) $row->post_id );

        return true;
    }

    /**
     * Undo an entire bulk operation, newest first so overlapping edits to the
     * same post unwind in the right order.
     *
     * @return array{restored:int,failed:int}
     */
    public static function restore_batch( string $batch_id ): array {
        global $wpdb;

        $ids = $wpdb->get_col( $wpdb->prepare(
            'SELECT id FROM ' . self::table() . " WHERE batch_id = %s AND status = 'active' ORDER BY id DESC",
            $batch_id
        ) );

        $restored = 0;
        $failed   = 0;
        foreach ( $ids as $id ) {
            if ( is_wp_error( self::restore( (int) $id ) ) ) {
                $failed++;
                continue;
            }
            $restored++;
        }

        return [ 'restored' => $restored, 'failed' => $failed ];
    }

    /** Recent changes, grouped so bulk runs show as one row. */
    public static function recent( int $limit = 50 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            'SELECT r.id, r.post_id, r.batch_id, r.reason, r.status, r.created_at, p.post_title,
                    ( SELECT COUNT(*) FROM ' . self::table() . " b WHERE b.batch_id = r.batch_id AND r.batch_id != '' ) AS batch_size
             FROM " . self::table() . " r
             LEFT JOIN {$wpdb->posts} p ON p.ID = r.post_id
             ORDER BY r.id DESC LIMIT %d",
            $limit
        ) );
    }

    /**
     * Drop old rows. These store whole post bodies, so an unpruned log is the
     * largest table this plugin owns.
     */
    public static function prune( int $days = 30 ): int {
        global $wpdb;
        return (int) $wpdb->query( $wpdb->prepare(
            'DELETE FROM ' . self::table() . ' WHERE created_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )',
            $days
        ) );
    }
}
