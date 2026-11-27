<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cross-request mutex backed by a row in wp_options, so two processes (a
 * manual "Esegui ora" and the cron tick, or a WP-Cron that fires twice)
 * can never rotate the same slot at the same time.
 *
 * Deliberately NOT built on add_option()/get_option(): add_option() uses
 * INSERT ... ON DUPLICATE KEY UPDATE (so it never "fails" on a concurrent
 * insert) and get_option() may answer from a stale object cache. Here the
 * lock row is only ever touched through $wpdb: INSERT IGNORE is atomic on
 * the unique option_name key, and a stale lock (process died while holding
 * it) is taken over with a compare-and-swap UPDATE, so only one contender
 * can win it.
 *
 * Re-entrant within one request: a method that already holds the lock can
 * call another one that asks for it (e.g. swap_out() -> top_up()), and the
 * row is only deleted when the outermost holder releases it.
 */
class BMFR_Lock {

	const PREFIX      = 'bmfr_lock_';
	const DEFAULT_TTL = 300;

	private static $held = array();

	/**
	 * @param string $name Lock name, e.g. "slot_trend_posts".
	 * @param int    $ttl  Seconds after which a lock left behind by a dead
	 *                     process is considered stale and can be taken over.
	 * @return bool True if this request now holds the lock.
	 */
	public static function acquire( $name, $ttl = self::DEFAULT_TTL ) {
		if ( ! empty( self::$held[ $name ] ) ) {
			++self::$held[ $name ];
			return true;
		}

		global $wpdb;

		$option = self::PREFIX . $name;
		$now    = (string) time();

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option,
				$now
			)
		);

		if ( ! $inserted ) {
			$current = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
			);

			if ( null === $current ) {
				// Released between our INSERT and SELECT: just try once more.
				$inserted = $wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
						$option,
						$now
					)
				);

				if ( ! $inserted ) {
					return false;
				}
			} else {
				if ( ( (int) $now - (int) $current ) < $ttl ) {
					return false;
				}

				$taken = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
						$now,
						$option,
						$current
					)
				);

				if ( ! $taken ) {
					return false;
				}
			}
		}

		self::$held[ $name ] = 1;

		return true;
	}

	public static function release( $name ) {
		if ( empty( self::$held[ $name ] ) ) {
			return;
		}

		--self::$held[ $name ];

		if ( self::$held[ $name ] > 0 ) {
			return;
		}

		unset( self::$held[ $name ] );

		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::PREFIX . $name ), array( '%s' ) );
	}
}
