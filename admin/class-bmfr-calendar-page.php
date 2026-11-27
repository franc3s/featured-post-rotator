<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMFR_Calendar_Page {

	public static function render() {
		if ( ! current_user_can( BMFR_Admin::VIEW_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'featured-post-rotator' ) );
		}

		$calendar = BMFR_Seasonal::get_calendar();

		include BMFR_PLUGIN_DIR . 'admin/views/calendar-page.php';
	}
}
