<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMFR_Plugin {

	public static function init() {
		load_plugin_textdomain( 'featured-post-rotator', false, dirname( plugin_basename( BMFR_PLUGIN_FILE ) ) . '/languages' );

		BMFR_Upgrader::maybe_upgrade();
		BMFR_Settings::migrate_priority_model();

		BMFR_Cron::init();
		BMFR_Cache::init();

		if ( is_admin() ) {
			BMFR_Admin::init();
			BMFR_Post_Metabox::init();
		}
	}
}
