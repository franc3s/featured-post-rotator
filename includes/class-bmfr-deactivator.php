<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMFR_Deactivator {

	public static function deactivate() {
		wp_clear_scheduled_hook( BMFR_Cron::TICK_HOOK );
	}
}
