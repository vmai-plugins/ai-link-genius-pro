<?php
/**
 * Link Helper.
 *
 * The insertion logic moved to AILG_Inserter, which locates the anchor by
 * byte offset outside every protected region instead of pattern-matching
 * against raw markup. These wrappers stay because several callers use them,
 * and because insert() is also used to build preview markup that never
 * reaches the database.
 */

defined( 'ABSPATH' ) || exit;

class AILG_LinkHelper {

    /**
     * Insert $link at the first safe occurrence of $anchor.
     *
     * Same signature and same contract as before - content in, content out,
     * unchanged when there is nowhere safe to put it - so the front-end
     * AutoLinker and anything else calling this keeps working.
     */
    public static function insert( string $content, string $anchor, string $link ): string {
        $blocked = AILG_Inserter::protected_ranges( $content );
        $hit     = AILG_Inserter::locate( $content, $anchor, $blocked );

        if ( ! $hit ) {
            return $content;
        }

        // Rebuild the anchor from the text as it appears in the content rather
        // than the caller's copy, so entities are not double-escaped.
        $matched = substr( $content, $hit['offset'], $hit['length'] );
        $link    = preg_replace( '/(<a\b[^>]*>).*?(<\/a>)/is', '$1' . str_replace( '$', '\\$', $matched ) . '$2', $link, 1 );

        return substr( $content, 0, $hit['offset'] )
            . $link
            . substr( $content, $hit['offset'] + $hit['length'] );
    }

    /**
     * Wrap an image with a given alt text in a link.
     *
     * The old version wrapped *every* matching image and had a comment saying
     * it ensured the image was not already linked, which it did not do - so
     * running it twice produced a link inside a link.
     */
    public static function wrap_image( string $content, string $alt, string $link_url ): string {
        $pattern = '/<img[^>]+alt=["\']' . preg_quote( $alt, '/' ) . '["\'][^>]*>/i';

        if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
            return $content;
        }

        // Only images that are not already inside an anchor.
        $anchors = [];
        if ( preg_match_all( '/<a\b[^>]*>.*?<\/a>/is', $content, $a_matches, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $a_matches[0] as $m ) {
                $anchors[] = [ $m[1], $m[1] + strlen( $m[0] ) ];
            }
        }

        foreach ( $matches[0] as $match ) {
            $start = $match[1];
            $end   = $start + strlen( $match[0] );

            $inside = false;
            foreach ( $anchors as $range ) {
                if ( $start >= $range[0] && $end <= $range[1] ) {
                    $inside = true;
                    break;
                }
            }
            if ( $inside ) {
                continue;
            }

            return substr( $content, 0, $start )
                . '<a href="' . esc_url( $link_url ) . '" class="ailg-link" data-ailg="1">' . $match[0] . '</a>'
                . substr( $content, $end );
        }

        return $content;
    }
}
