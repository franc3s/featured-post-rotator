<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data/schema upgrades keyed on the bmfr_db_version option, run on every
 * load (cheap version compare) instead of only on activation, since a
 * plugin updated by uploading a new zip never re-fires the activation hook.
 *
 * 1.0.0 -> 1.1.0 (plugin v1.19.1): history created_at switches from site
 * local time to UTC. Existing rows are converted once, each with the
 * site's UTC offset on that row's own date (so DST is handled per row).
 *
 * 1.1.0 -> 1.2.0 (plugin v1.21.0): slots become admin-editable. The
 * default slot list (same keys as before, no tag) is written into the
 * bmfr_slots option; the admin then picks each slot's tag in Impostazioni.
 * Per-slot settings, priorities and history keep working, since the slot
 * keys don't change.
 */
class BMFR_Upgrader {

	const LOCK = 'db_upgrade';

	public static function maybe_upgrade() {
		if ( version_compare( (string) get_option( 'bmfr_db_version', '0' ), BMFR_DB_VERSION, '>=' ) ) {
			return;
		}

		if ( ! BMFR_Lock::acquire( self::LOCK, 10 * MINUTE_IN_SECONDS ) ) {
			return;
		}

		try {
			// Re-read past any cache: another request may have finished the
			// upgrade between our check above and getting the lock.
			$version = self::read_db_version_uncached();

			if ( version_compare( $version, '1.1.0', '<' ) ) {
				self::migrate_history_to_utc();
				update_option( 'bmfr_db_version', '1.1.0' );
			}

			if ( version_compare( $version, '1.2.0', '<' ) ) {
				self::store_slot_definitions();
				update_option( 'bmfr_db_version', '1.2.0' );
			}
		} finally {
			BMFR_Lock::release( self::LOCK );
		}
	}

	/**
	 * Writes the slot list in effect into the option. Never overwrites a
	 * list that was already saved.
	 */
	private static function store_slot_definitions() {
		if ( false === get_option( BMFR_Slots::OPTION ) ) {
			add_option( BMFR_Slots::OPTION, BMFR_Slots::get_definitions(), '', true );
			BMFR_Slots::flush_cache();
		}
	}

	private static function read_db_version_uncached() {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'bmfr_db_version' )
		);

		return null === $value ? '0' : (string) $value;
	}

	private static function migrate_history_to_utc() {
		global $wpdb;

		$table = BMFR_History::table_name();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$rows = $wpdb->get_results( "SELECT id, created_at FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.

		$changes = array();

		foreach ( (array) $rows as $row ) {
			$utc = get_gmt_from_date( $row->created_at );

			if ( $utc && $utc !== $row->created_at ) {
				$changes[ (int) $row->id ] = $utc;
			}
		}

		foreach ( array_chunk( $changes, 200, true ) as $chunk ) {
			$cases  = array();
			$params = array();

			foreach ( $chunk as $id => $utc ) {
				$cases[]  = 'WHEN %d THEN %s';
				$params[] = $id;
				$params[] = $utc;
			}

			$ids          = array_keys( $chunk );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql          = "UPDATE {$table} SET created_at = CASE id " . implode( ' ', $cases ) . " END WHERE id IN ({$placeholders})";

			$wpdb->query( $wpdb->prepare( $sql, array_merge( $params, $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from placeholders only.
		}
	}
}
