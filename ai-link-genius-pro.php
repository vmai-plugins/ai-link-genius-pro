<?php
/**
 * Plugin Name:       AI Link Genius Pro
 * Plugin URI:        https://ailinkgenius.com
 * Description:       The most advanced AI-powered internal linking plugin for WordPress. Features OpenAI, Google Gemini, OpenRouter & Ollama integration, smart automation, semantic matching, and full link intelligence dashboard.
 * Version:           2.1.6
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            AI Link Genius
 * Author URI:        https://ailinkgenius.com
 * License:           GPL v2 or later
 * Text Domain:       ai-link-genius-pro
 */

defined( 'ABSPATH' ) || exit;

// ─── Constants ───────────────────────────────────────────────────────────────
define( 'AILG_VERSION',    '2.1.6' );
define( 'AILG_FILE',       __FILE__ );
define( 'AILG_DIR',        plugin_dir_path( __FILE__ ) );
define( 'AILG_URL',        plugin_dir_url( __FILE__ ) );
define( 'AILG_SLUG',       'ai-link-genius-pro' );
define( 'AILG_BASENAME',   plugin_basename( __FILE__ ) );
define( 'AILG_DB_VERSION', '1.9' );

/**
 * Basic Autoloader for internal classes
 */
spl_autoload_register( function ( $class ) {
    $prefix = 'AILG_';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }

    $file = strtolower( str_replace( [ $prefix, '_' ], [ '', '-' ], $class ) );
    $path = AILG_DIR . 'includes/class-ailg-' . $file . '.php';

    if ( file_exists( $path ) ) {
        require_once $path;
    }
} );

// ─── Activation / Deactivation ───────────────────────────────────────────────
register_activation_hook(   __FILE__, [ 'AILG_Core', 'activate'   ] );
register_deactivation_hook( __FILE__, [ 'AILG_Core', 'deactivate' ] );

// ─── Boot ────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', [ 'AILG_Core', 'init' ] );