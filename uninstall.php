<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table_name = $wpdb->prefix . 'bmfr_rotation_history';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

wp_clear_scheduled_hook( 'bmfr_cron_tick' );

$bmfr_options = array(
	'bmfr_slots',
	'bmfr_notification_settings',
	'bmfr_global_exclusions',
	'bmfr_cron_time',
	'bmfr_slack_settings',
	'bmfr_seasonal_calendar',
	'bmfr_shared_priority',
	'bmfr_priority_model_version',
	'bmfr_last_tick_run',
	'bmfr_tour_redirect_pending',
	'bmfr_db_version',
);

foreach ( $bmfr_options as $bmfr_option ) {
	delete_option( $bmfr_option );
}

// Per-slot settings (bmfr_slot_settings_<key>, including slots removed from
// the list, whose data is kept until uninstall), lock rows (bmfr_lock_*) and
// per-user cached previews/rankings (_transient_bmfr_dry_run_*,
// _transient_bmfr_priority_pool_*), whose names aren't known in advance.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'bmfr\\_slot\\_settings\\_%'
	    OR option_name LIKE 'bmfr\\_lock\\_%'
	    OR option_name LIKE '\\_transient\\_bmfr\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_bmfr\\_%'"
);
