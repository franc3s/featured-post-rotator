<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMFR_Activator {

	public static function activate() {
		self::create_history_table();
		self::seed_default_options();
		BMFR_Cron::schedule_tick();

		// One-shot nudge to the Tour page on the very next admin page load;
		// see BMFR_Admin::maybe_redirect_to_tour(). The Tour page itself
		// stays in the menu permanently, independent of this flag.
		update_option( BMFR_Admin::TOUR_REDIRECT_OPTION, true, false );
	}

	private static function create_history_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'bmfr_rotation_history';
		$charset_collate = $wpdb->get_charset_collate();

		// trigger_source (not "trigger") avoids the MySQL reserved keyword.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id CHAR(36) NOT NULL,
			slot VARCHAR(50) NOT NULL,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			action VARCHAR(10) NOT NULL,
			score DECIMAL(10,6) NULL,
			score_breakdown LONGTEXT NULL,
			category_id BIGINT(20) UNSIGNED NULL,
			trigger_source VARCHAR(10) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY slot_created (slot, created_at),
			KEY post_id (post_id),
			KEY run_id (run_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Fresh install: the table was just created in the current format.
		// Existing install being re-activated: keep its stored version and
		// let the upgrader convert its data, instead of stamping the new
		// version on rows that were never converted.
		if ( false === get_option( 'bmfr_db_version' ) ) {
			add_option( 'bmfr_db_version', BMFR_DB_VERSION );
		}

		BMFR_Upgrader::maybe_upgrade();
	}

	private static function seed_default_options() {
		// add_option() never overwrites what is already saved.
		add_option( BMFR_Slots::OPTION, BMFR_Slots::get_definitions(), '', true );
		BMFR_Slots::flush_cache();

		foreach ( BMFR_Slots::get_slot_keys() as $slot_key ) {
			add_option(
				BMFR_Settings::OPTION_PREFIX . $slot_key,
				BMFR_Settings::get_default_slot_settings( $slot_key ),
				'',
				false
			);
		}

		add_option( BMFR_Settings::NOTIFICATION_OPTION, array( 'recipients' => array() ), '', false );
		add_option(
			BMFR_Settings::GLOBAL_EXCLUSIONS_OPTION,
			array(
				'excluded_categories' => array(),
				'excluded_post_ids'   => array(),
			),
			'',
			false
		);
	}
}
