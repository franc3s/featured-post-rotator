<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMFR_Settings_Page {

	public static function render() {
		if ( ! current_user_can( BMFR_Admin::VIEW_CAP ) ) {
			wp_die( esc_html__( 'Non hai i permessi per accedere a questa pagina.', 'featured-post-rotator' ) );
		}

		$slots                 = BMFR_Slots::get_slots();
		$notification_settings = BMFR_Settings::get_notification_settings();
		$all_categories        = get_categories( array( 'hide_empty' => false ) );
		$all_tags              = get_tags(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$all_tags              = is_array( $all_tags ) ? $all_tags : array();

		$slot_status = array();
		foreach ( $slots as $slot_key => $slot ) {
			$slot_settings             = BMFR_Settings::get_slot_settings( $slot_key );
			$slot_status[ $slot_key ] = array(
				'enabled'      => ! empty( $slot_settings['enabled'] ),
				'cadence_days' => $slot_settings['cadence_days'],
				'last_run'     => (int) $slot_settings['last_run'],
				'next_run'     => BMFR_Cron::get_next_run_estimate( $slot_key ),
			);
		}
		$next_tick      = BMFR_Cron::get_next_tick_timestamp();
		$cron_health    = BMFR_Cron::get_health();
		$cron_time      = BMFR_Settings::get_cron_time();
		$slack_settings = BMFR_Settings::get_slack_settings();

		include BMFR_PLUGIN_DIR . 'admin/views/settings-page.php';
	}
}
