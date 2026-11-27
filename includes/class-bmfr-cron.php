<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single recurring "tick" instead of one scheduled event per slot.
 *
 * Changing a slot's cadence in the admin is just an option update: there is
 * nothing to re-schedule. The tick's "is it overdue?" check is also
 * self-healing against a WP-Cron that only fires on traffic (this site
 * already shows delayed Action Scheduler runs) — a missed hourly window is
 * simply caught at the next tick instead of silently skipping a rotation.
 *
 * The admin can optionally pin rotations to a preferred hour of day
 * (BMFR_Settings::get_cron_time()). That, too, is just an option update: the
 * tick keeps ticking every hour regardless, it just gates whether an overdue
 * slot is allowed to act on THIS tick — see compute_due_at().
 *
 * Cadence counting with a preferred hour set is anchored to the *calendar
 * day* of the last run, not its exact timestamp: due_at is always
 * "last run's day + cadence_days, at the preferred hour". Anchoring to the
 * exact timestamp instead (last_run + cadence_days*DAY_IN_SECONDS, then
 * "next occurrence of the preferred hour on/after that") looks equivalent
 * but isn't — WP-Cron never fires exactly on the minute, so last_run always
 * lands a few minutes after the preferred hour, which pushes every single
 * cycle one full day later than intended, forever. Using the calendar day
 * instead of the exact time avoids that drift entirely.
 */
class BMFR_Cron {

	const TICK_HOOK = 'bmfr_cron_tick';

	/**
	 * Timestamp of the last time run_tick() actually executed, recorded
	 * unconditionally (even if every slot was off-cadence that hour). This is
	 * what the health check compares against "now" to tell a merely-quiet
	 * slot apart from a tick that isn't firing at all.
	 */
	const LAST_TICK_OPTION = 'bmfr_last_tick_run';

	public static function init() {
		add_action( self::TICK_HOOK, array( __CLASS__, 'run_tick' ) );
	}

	public static function schedule_tick() {
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::TICK_HOOK );
		}
	}

	public static function run_tick() {
		$now = time();
		update_option( self::LAST_TICK_OPTION, $now, false );

		$cron_time = BMFR_Settings::get_cron_time();

		foreach ( BMFR_Slots::get_slot_keys() as $slot_key ) {
			$settings = BMFR_Settings::get_slot_settings( $slot_key );

			// No tag yet (fresh install): nothing to rotate or pre-announce.
			if ( empty( $settings['enabled'] ) || ! BMFR_Slots::has_tag( $slot_key ) ) {
				continue;
			}

			$last_run = (int) $settings['last_run'];

			if ( $last_run > 0 ) {
				$due_at = self::compute_due_at( $last_run, $settings['cadence_days'], $cron_time );

				self::maybe_send_pre_rotation_notice( $slot_key, $settings, $due_at, $now );

				if ( $now < $due_at ) {
					continue;
				}
			}

			self::run_if_still_due( $slot_key, $cron_time, $now );
		}
	}

	/**
	 * Takes the slot lock, then re-reads the settings and re-checks "due"
	 * before rotating. Without this, a WP-Cron that fires twice (two
	 * requests both deciding the slot is due from the same old last_run)
	 * would rotate it twice in a row: the second would simply wait for the
	 * first to release the lock and then run anyway. If another process
	 * holds the lock, this tick skips the slot; the next tick retries.
	 */
	private static function run_if_still_due( $slot_key, $cron_time, $now ) {
		$lock = BMFR_Rotator::lock_name( $slot_key );

		if ( ! BMFR_Lock::acquire( $lock ) ) {
			return;
		}

		try {
			$settings = BMFR_Settings::get_slot_settings_fresh( $slot_key );

			if ( empty( $settings['enabled'] ) ) {
				return;
			}

			$last_run = (int) $settings['last_run'];

			if ( $last_run > 0 && $now < self::compute_due_at( $last_run, $settings['cadence_days'], $cron_time ) ) {
				return;
			}

			BMFR_Rotator::run( $slot_key, 'cron', false );
		} finally {
			BMFR_Lock::release( $lock );
		}
	}

	/**
	 * When a slot with $last_run becomes due again, given its cadence and the
	 * (optional) admin-configured preferred hour.
	 *
	 * With no preferred hour, this is the historical behaviour: due exactly
	 * $cadence_days after $last_run, at any hour.
	 *
	 * With a preferred hour, cadence is counted in calendar days from
	 * $last_run's *date* (its time of day is deliberately ignored) and
	 * pinned to that hour — "$cadence_days days after the day it last ran,
	 * at HH:MM". This is what keeps a daily cadence landing on the same
	 * clock time every single day: counting from the exact $last_run
	 * timestamp instead would almost always land a few minutes past HH:MM
	 * (WP-Cron ticks are never minute-exact), which would then roll over to
	 * the day after on every cycle — see the class docblock.
	 */
	private static function compute_due_at( $last_run, $cadence_days, $cron_time ) {
		$cadence_days = max( 1, (int) $cadence_days );

		if ( '' === $cron_time ) {
			return $last_run + ( $cadence_days * DAY_IN_SECONDS );
		}

		$target_hour = (int) substr( $cron_time, 0, 2 );
		$target_min  = (int) substr( $cron_time, 3, 2 );

		$due = new DateTime( wp_date( 'Y-m-d', $last_run ), wp_timezone() );
		$due->modify( "+{$cadence_days} day" );
		$due->setTime( $target_hour, $target_min, 0 );

		return $due->getTimestamp();
	}

	/**
	 * Fires the Slack "rotation coming up" warning once per cycle, as soon
	 * as $now enters the admin-configured lead window before $due_at —
	 * never after, since the whole point is advance notice. Guarded by
	 * pre_notified_due_at so the warning isn't re-sent on every hourly tick
	 * while still inside that window; a new $due_at (after the next real
	 * run) naturally differs from the stored value and re-arms it.
	 */
	private static function maybe_send_pre_rotation_notice( $slot_key, array $settings, $due_at, $now ) {
		$slack = BMFR_Settings::get_slack_settings();

		if ( empty( $slack['webhook_url'] ) ) {
			return;
		}

		if ( (int) $settings['pre_notified_due_at'] === (int) $due_at ) {
			return;
		}

		$lead_window_start = $due_at - ( $slack['lead_hours'] * HOUR_IN_SECONDS );

		if ( $now < $lead_window_start || $now >= $due_at ) {
			return;
		}

		// Marked only once Slack accepted it: a failed post (webhook down,
		// timeout) is retried on the next hourly tick while still inside the
		// lead window, instead of being silently dropped for this cycle.
		if ( BMFR_Slack_Notifier::send_pre_rotation_notice( $slot_key, $due_at ) ) {
			BMFR_Settings::mark_pre_notified( $slot_key, $due_at );
		}
	}

	/**
	 * null    = slot disabled, cron will never touch it.
	 * 0       = due already (never run before) — will fire at the next
	 *           hourly tick, whenever that actually happens.
	 * int     = unix timestamp of the earliest moment it becomes due.
	 */
	public static function get_next_run_estimate( $slot_key ) {
		$settings = BMFR_Settings::get_slot_settings( $slot_key );

		if ( empty( $settings['enabled'] ) ) {
			return null;
		}

		$last_run = (int) $settings['last_run'];

		if ( $last_run <= 0 ) {
			return 0;
		}

		$cron_time = BMFR_Settings::get_cron_time();

		return self::compute_due_at( $last_run, $settings['cadence_days'], $cron_time );
	}

	/**
	 * When the hourly "is anything due?" tick itself is next scheduled to
	 * fire, per WP-Cron's own bookkeeping. Actual firing still depends on
	 * site traffic (or a real server cron hitting wp-cron.php) — this is
	 * when WP-Cron intends to run it, not a guarantee.
	 */
	public static function get_next_tick_timestamp() {
		$next = wp_next_scheduled( self::TICK_HOOK );
		return $next ? (int) $next : null;
	}

	/**
	 * Traffic-light read on whether the automatic rotation can actually run,
	 * for display in the admin UI. Checks are passive (based on WP-Cron's own
	 * bookkeeping and the last recorded tick), not an active network probe —
	 * see get_next_tick_timestamp()'s docblock for why "scheduled" and
	 * "actually fires" are different things on this kind of hosting.
	 *
	 * Returns array( 'status' => 'green'|'yellow'|'red'|'neutral', 'message' => string ).
	 */
	public static function get_health() {
		$any_enabled = false;
		foreach ( BMFR_Slots::get_slot_keys() as $slot_key ) {
			$settings = BMFR_Settings::get_slot_settings( $slot_key );
			if ( ! empty( $settings['enabled'] ) ) {
				$any_enabled = true;
				break;
			}
		}

		if ( ! $any_enabled ) {
			return array(
				'status'  => 'neutral',
				'message' => __( 'Nessuno slot ha il cron attivo al momento: non c\'è nulla da verificare.', 'featured-post-rotator' ),
			);
		}

		$next_tick = self::get_next_tick_timestamp();

		if ( ! $next_tick ) {
			return array(
				'status'  => 'red',
				'message' => __( 'Il tick orario non risulta pianificato in WP-Cron: la rotazione automatica non partirà. Controlla eventuali plugin di cache/sicurezza che svuotano i cron pianificati, o riattiva il plugin.', 'featured-post-rotator' ),
			);
		}

		$disable_wp_cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$last_tick_run   = (int) get_option( self::LAST_TICK_OPTION, 0 );
		$now             = time();

		if ( 0 === $last_tick_run ) {
			if ( $next_tick > $now ) {
				return array(
					'status'  => 'yellow',
					'message' => __( 'Il tick orario non è ancora mai partito. Se il plugin è stato attivato di recente è normale: tornerà verde dopo la prima esecuzione.', 'featured-post-rotator' ),
				);
			}

			return array(
				'status'  => 'red',
				'message' => $disable_wp_cron
					? __( 'DISABLE_WP_CRON è attivo in wp-config.php e il tick non è mai partito: serve un cron reale lato server che chiami wp-cron.php, altrimenti la rotazione non scatterà mai.', 'featured-post-rotator' )
					: __( 'Il tick doveva già essere scattato ma non ha mai eseguito nulla: verifica che wp-cron.php sia raggiungibile (non bloccato da cache/firewall) e che il sito riceva traffico.', 'featured-post-rotator' ),
			);
		}

		$delay = $now - $last_tick_run;

		if ( $disable_wp_cron && $delay > 2 * HOUR_IN_SECONDS ) {
			return array(
				'status'  => 'red',
				'message' => __( 'DISABLE_WP_CRON è attivo e l\'ultimo tick risale a più di due ore fa: il cron reale lato server che dovrebbe chiamare wp-cron.php non sta girando (o non abbastanza spesso).', 'featured-post-rotator' ),
			);
		}

		if ( $delay <= 2 * HOUR_IN_SECONDS ) {
			return array(
				'status'  => 'green',
				'message' => __( 'Il tick orario esegue regolarmente: gli strumenti per far girare il cron sono settati correttamente.', 'featured-post-rotator' ),
			);
		}

		if ( $delay <= 6 * HOUR_IN_SECONDS ) {
			return array(
				'status'  => 'yellow',
				/* translators: %s: hours since the last tick actually ran */
				'message' => sprintf( __( 'L\'ultimo tick risale a circa %s fa: probabile traffico insufficiente a far scattare il pseudo-cron di WordPress. Valuta un cron reale lato server su wp-cron.php per orari puntuali.', 'featured-post-rotator' ), human_time_diff( $last_tick_run, $now ) ),
			);
		}

		return array(
			'status'  => 'red',
			/* translators: %s: hours since the last tick actually ran */
			'message' => sprintf( __( 'L\'ultimo tick eseguito risale a %s fa: il cron non sta più girando. Controlla DISABLE_WP_CRON, un cron reale lato server, e se cache/firewall bloccano wp-cron.php.', 'featured-post-rotator' ), human_time_diff( $last_tick_run, $now ) ),
		);
	}
}
