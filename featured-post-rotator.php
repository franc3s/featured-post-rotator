<?php
/**
 * Plugin Name:       Featured Post Rotator
 * Plugin URI:        https://github.com/franc3s/featured-post-rotator
 * Description:       Rotates your featured posts in configurable tag-based slots, on a schedule and by editorial criteria (recency, cooldown, category balance, engagement, seasonal calendar), with rotation history and email/Slack notifications. Theme templates are never modified.
 * Version:           1.23.0
 * Requires at least: 5.3
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            franc3s
 * Author URI:        https://github.com/franc3s
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       featured-post-rotator
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMFR_VERSION', '1.23.0' );
define( 'BMFR_DB_VERSION', '1.2.0' );
define( 'BMFR_PLUGIN_FILE', __FILE__ );
define( 'BMFR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BMFR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	function ( $class_name ) {
		if ( strpos( $class_name, 'BMFR_' ) !== 0 ) {
			return;
		}

		$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

		$paths = array(
			BMFR_PLUGIN_DIR . 'includes/' . $file_name,
			BMFR_PLUGIN_DIR . 'admin/' . $file_name,
		);

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'BMFR_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BMFR_Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'BMFR_Plugin', 'init' ) );
