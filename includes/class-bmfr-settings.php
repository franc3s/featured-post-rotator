<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get/save/sanitize for the per-slot options and the shared notification
 * recipients option. One option row per slot, autoload disabled since these
 * are only read from admin screens and the hourly cron tick.
 */
class BMFR_Settings {

	const OPTION_PREFIX          = 'bmfr_slot_settings_';
	const NOTIFICATION_OPTION    = 'bmfr_notification_settings';
	const GLOBAL_EXCLUSIONS_OPTION = 'bmfr_global_exclusions';
	const CRON_TIME_OPTION        = 'bmfr_cron_time';
	const SLACK_OPTION            = 'bmfr_slack_settings';
	const SHARED_PRIORITY_OPTION  = 'bmfr_shared_priority';
	const PRIORITY_MODEL_OPTION   = 'bmfr_priority_model_version';

	public static function get_default_slot_settings( $slot_key ) {
		$defaults = array(
			'enabled'                => false,
			'cadence_days'           => 3,
			'cooldown_days'          => 14,
			'min_age_days'           => 1,
			// 'relative' = non più vecchio di max_age_days da oggi (finestra mobile).
			// 'absolute' = non più vecchio di max_age_date (soglia fissa).
			// 'none'     = nessun tetto di età, solo il decadimento recency.
			'max_age_mode'           => 'relative',
			'max_age_days'           => 730,
			'max_age_date'           => '',
			'recency_half_life_days' => 30,
			'weights'                => array(
				'recency'          => 0.3,
				'category_balance' => 0.2,
				'engagement'       => 0.2,
				'seasonal_fit'     => 0.3,
			),
			'excluded_categories'    => array(),
			'excluded_post_ids'      => array(),
			// Per-cycle Tour decisions (BMFR_Tour_Page), scoped to this one
			// slot so the same post can be "mantenuto" in one slot and
			// "escluso" in another instead of a single all-or-nothing call.
			// kept_post_ids is bookkeeping only (no eligibility effect, same
			// as the old flat tour-kept list it replaces); excluded_post_ids
			// above is the one that actually removes a post from this slot's
			// eligible pool.
			'kept_post_ids'          => array(),
			// This slot's own priority offset: post_id => float in
			// [-BMFR_Scoring::SLOT_OFFSET_MAX, +MAX]. Added, together with the
			// post's shared priority, to the algorithmic score in
			// BMFR_Selector::score_candidates(). See get_manual_score_boost().
			'manual_score_boosts'    => array(),
			'last_run'               => 0,
			'next_run'               => 0,
			// Timestamp of the due_at this slot was last pre-notified for on
			// Slack, so the lead-time window in BMFR_Cron::run_tick() doesn't
			// re-send the same warning on every hourly tick. A new last_run
			// produces a new due_at, which naturally differs from this stored
			// value and re-arms the warning for the next cycle.
			'pre_notified_due_at'    => 0,
		);

		if ( BMFR_Slots::SPOTLIGHT === $slot_key ) {
			$defaults['cadence_days']           = 7;
			$defaults['recency_half_life_days'] = 90;
			$defaults['weights']              = array(
				'recency'          => 0.25,
				'category_balance' => 0.35,
				'engagement'       => 0.3,
				'seasonal_fit'     => 0.1,
			);
		}

		return $defaults;
	}

	public static function get_slot_settings( $slot_key ) {
		$defaults = self::get_default_slot_settings( $slot_key );
		$saved    = get_option( self::OPTION_PREFIX . $slot_key, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = wp_parse_args( $saved, $defaults );
		$settings['weights'] = wp_parse_args(
			is_array( $settings['weights'] ) ? $settings['weights'] : array(),
			$defaults['weights']
		);

		return $settings;
	}

	/**
	 * Same as get_slot_settings(), but drops this request's cached copy of
	 * the option first. Used right after taking a slot lock (BMFR_Lock):
	 * another process may have just finished a rotation and written a new
	 * last_run, which a copy read earlier in this request would not show.
	 */
	public static function get_slot_settings_fresh( $slot_key ) {
		wp_cache_delete( self::OPTION_PREFIX . $slot_key, 'options' );
		return self::get_slot_settings( $slot_key );
	}

	public static function update_slot_settings( $slot_key, array $settings ) {
		update_option( self::OPTION_PREFIX . $slot_key, $settings, false );
	}

	/**
	 * Builds a clean settings array by starting from the currently saved
	 * settings and only overwriting fields actually present in $input, so a
	 * partial submission never wipes unrelated fields (e.g. last_run/next_run).
	 */
	public static function sanitize_slot_settings( array $input, $slot_key ) {
		$clean    = self::get_slot_settings( $slot_key );
		$defaults = self::get_default_slot_settings( $slot_key );

		$clean['enabled']                = ! empty( $input['enabled'] );
		$clean['cadence_days']           = max( 1, absint( $input['cadence_days'] ?? $defaults['cadence_days'] ) );
		$clean['cooldown_days']          = max( 0, absint( $input['cooldown_days'] ?? $defaults['cooldown_days'] ) );
		$clean['min_age_days']           = max( 0, absint( $input['min_age_days'] ?? $defaults['min_age_days'] ) );
		$clean['recency_half_life_days'] = max( 1, absint( $input['recency_half_life_days'] ?? $defaults['recency_half_life_days'] ) );

		$max_age_mode           = isset( $input['max_age_mode'] ) ? (string) $input['max_age_mode'] : $defaults['max_age_mode'];
		$clean['max_age_mode']  = in_array( $max_age_mode, array( 'relative', 'absolute', 'none' ), true ) ? $max_age_mode : $defaults['max_age_mode'];
		$clean['max_age_days']  = max( 0, absint( $input['max_age_days'] ?? $defaults['max_age_days'] ) );

		$raw_max_age_date      = isset( $input['max_age_date'] ) ? sanitize_text_field( $input['max_age_date'] ) : '';
		$clean['max_age_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_max_age_date ) ? $raw_max_age_date : '';

		$weights = array();
		foreach ( array( 'recency', 'category_balance', 'engagement', 'seasonal_fit' ) as $weight_key ) {
			$raw                  = isset( $input['weights'][ $weight_key ] ) ? $input['weights'][ $weight_key ] : $defaults['weights'][ $weight_key ];
			$weights[ $weight_key ] = min( 1, max( 0, (float) $raw ) );
		}
		$clean['weights'] = $weights;

		$clean['excluded_categories'] = isset( $input['excluded_categories'] ) && is_array( $input['excluded_categories'] )
			? array_values( array_unique( array_map( 'absint', $input['excluded_categories'] ) ) )
			: array();

		$clean['excluded_post_ids'] = self::parse_id_list(
			isset( $input['excluded_post_ids'] ) ? $input['excluded_post_ids'] : ''
		);

		return $clean;
	}

	public static function parse_id_list( $raw ) {
		$parts = preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$ids   = array_filter( array_map( 'absint', $parts ) );
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Exclusions that apply to every slot, unioned (not replacing) with each
	 * slot's own excluded_categories/excluded_post_ids — lets an editor
	 * exclude something everywhere once instead of repeating it per slot.
	 */
	public static function get_global_exclusions() {
		$saved = get_option( self::GLOBAL_EXCLUSIONS_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args(
			$saved,
			array(
				'excluded_categories' => array(),
				'excluded_post_ids'   => array(),
			)
		);
	}

	public static function update_global_exclusions( array $data ) {
		update_option( self::GLOBAL_EXCLUSIONS_OPTION, $data, false );
	}

	public static function add_global_excluded_post( $post_id ) {
		$data                      = self::get_global_exclusions();
		$data['excluded_post_ids'] = array_values( array_unique( array_merge( $data['excluded_post_ids'], array( absint( $post_id ) ) ) ) );
		self::update_global_exclusions( $data );
	}

	public static function remove_global_excluded_post( $post_id ) {
		$data                      = self::get_global_exclusions();
		$data['excluded_post_ids'] = array_values( array_diff( $data['excluded_post_ids'], array( absint( $post_id ) ) ) );
		self::update_global_exclusions( $data );
	}

	public static function add_global_excluded_category( $term_id ) {
		$data                        = self::get_global_exclusions();
		$data['excluded_categories'] = array_values( array_unique( array_merge( $data['excluded_categories'], array( absint( $term_id ) ) ) ) );
		self::update_global_exclusions( $data );
	}

	public static function remove_global_excluded_category( $term_id ) {
		$data                        = self::get_global_exclusions();
		$data['excluded_categories'] = array_values( array_diff( $data['excluded_categories'], array( absint( $term_id ) ) ) );
		self::update_global_exclusions( $data );
	}

	/**
	 * Adds/removes one post from a single slot's own excluded_post_ids —
	 * the same field the Impostazioni page's textarea edits, so a per-cycle
	 * "Escludi" on the Tour page and a manual settings edit both end up in
	 * one place and round-trip through that textarea without conflict.
	 */
	public static function add_slot_excluded_post( $slot_key, $post_id ) {
		$settings                      = self::get_slot_settings( $slot_key );
		$settings['excluded_post_ids'] = array_values( array_unique( array_merge( array_map( 'absint', (array) $settings['excluded_post_ids'] ), array( absint( $post_id ) ) ) ) );
		self::update_slot_settings( $slot_key, $settings );
	}

	public static function remove_slot_excluded_post( $slot_key, $post_id ) {
		$settings                      = self::get_slot_settings( $slot_key );
		$settings['excluded_post_ids'] = array_values( array_diff( array_map( 'absint', (array) $settings['excluded_post_ids'] ), array( absint( $post_id ) ) ) );
		self::update_slot_settings( $slot_key, $settings );
	}

	/**
	 * Post IDs the operator has explicitly marked "Mantieni" on the Tour
	 * page for this one slot: reviewed and confirmed fine to keep appearing
	 * there, as opposed to simply not-yet-excluded. Purely a bookkeeping aid
	 * for the Tour page's three tabs — it has no effect on eligibility or
	 * scoring, since a kept post was already eligible before being marked.
	 */
	public static function add_slot_kept_post( $slot_key, $post_id ) {
		$settings                  = self::get_slot_settings( $slot_key );
		$settings['kept_post_ids'] = array_values( array_unique( array_merge( array_map( 'absint', (array) $settings['kept_post_ids'] ), array( absint( $post_id ) ) ) ) );
		self::update_slot_settings( $slot_key, $settings );
	}

	public static function remove_slot_kept_post( $slot_key, $post_id ) {
		$settings                  = self::get_slot_settings( $slot_key );
		$settings['kept_post_ids'] = array_values( array_diff( array_map( 'absint', (array) $settings['kept_post_ids'] ), array( absint( $post_id ) ) ) );
		self::update_slot_settings( $slot_key, $settings );
	}

	/**
	 * Targeted weights-only write for the Priorità e pesi page, deliberately
	 * NOT routed through sanitize_slot_settings()/handle_save_settings(): that
	 * combined form treats every field it doesn't recognize as "reset to
	 * default" (see its docblock vs. its actual weights/excluded_categories
	 * fallbacks), so a form that only ever posts `weights` would silently
	 * wipe cadence/cooldown/age/exclusions back to defaults. This reads the
	 * current settings and overwrites only the one key, like
	 * add_slot_excluded_post() and friends above.
	 */
	public static function update_slot_weights( $slot_key, array $weights ) {
		$settings = self::get_slot_settings( $slot_key );
		$defaults = self::get_default_slot_settings( $slot_key );
		$clean    = array();

		foreach ( array( 'recency', 'category_balance', 'engagement', 'seasonal_fit' ) as $weight_key ) {
			$raw                   = isset( $weights[ $weight_key ] ) ? $weights[ $weight_key ] : $defaults['weights'][ $weight_key ];
			$clean[ $weight_key ] = min( 1, max( 0, (float) $raw ) );
		}

		$settings['weights'] = $clean;
		self::update_slot_settings( $slot_key, $settings );
	}

	/**
	 * Total manual correction for one post in one slot, as added to the
	 * algorithmic score by BMFR_Selector::score_candidates(): the post's
	 * shared priority (same in every slot) plus this slot's own offset.
	 *
	 * Storage: shared priority lives in SHARED_PRIORITY_OPTION (post_id =>
	 * float); each slot's offset keeps the historical per-slot key
	 * 'manual_score_boosts' (post_id => float). Before migrate_priority_model()
	 * runs, that key still holds the old full per-slot correction, which
	 * reads as "shared 0 + offset = old value": the same total, so scores
	 * never shift between upgrade and migration.
	 */
	public static function get_manual_score_boost( $slot_key, $post_id ) {
		return self::get_shared_priority( $post_id ) + self::get_slot_offset( $slot_key, $post_id );
	}

	public static function get_shared_priorities() {
		$saved = get_option( self::SHARED_PRIORITY_OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	public static function get_shared_priority( $post_id ) {
		$all     = self::get_shared_priorities();
		$post_id = absint( $post_id );

		return isset( $all[ $post_id ] ) ? (float) $all[ $post_id ] : 0.0;
	}

	public static function get_slot_offsets( $slot_key ) {
		$settings = self::get_slot_settings( $slot_key );
		return (array) $settings['manual_score_boosts'];
	}

	public static function get_slot_offset( $slot_key, $post_id ) {
		$offsets = self::get_slot_offsets( $slot_key );
		$post_id = absint( $post_id );

		return isset( $offsets[ $post_id ] ) ? (float) $offsets[ $post_id ] : 0.0;
	}

	/**
	 * Full priority state of one post: shared value plus every slot's offset.
	 */
	public static function get_priority_state( $post_id ) {
		$state = array(
			'shared'  => self::get_shared_priority( $post_id ),
			'offsets' => array(),
		);

		foreach ( BMFR_Slots::get_slot_keys() as $slot_key ) {
			$state['offsets'][ $slot_key ] = self::get_slot_offset( $slot_key, $post_id );
		}

		return $state;
	}

	/**
	 * Sets one post's shared priority and (optionally) some slot offsets to
	 * absolute values, clamped to their caps and rounded to 0.01. Values at
	 * ~0 are removed from storage, so "no entry" and "reset" are the same
	 * state. Absolute values (not deltas) so the Priorità page can send the
	 * exact state it already shows, whether it came from +/- or a drag.
	 *
	 * @param float|null $shared  Null leaves the shared value untouched.
	 * @param array      $offsets slot_key => float; slots not listed are untouched.
	 */
	public static function set_priority( $post_id, $shared = null, array $offsets = array() ) {
		$post_id = absint( $post_id );

		if ( null !== $shared ) {
			$all   = self::get_shared_priorities();
			$value = self::clamp_round( $shared, BMFR_Scoring::MANUAL_BOOST_MAX );

			if ( 0.0 === $value ) {
				unset( $all[ $post_id ] );
			} else {
				$all[ $post_id ] = $value;
			}

			update_option( self::SHARED_PRIORITY_OPTION, $all, false );
		}

		foreach ( $offsets as $slot_key => $offset ) {
			if ( ! BMFR_Slots::get_slot( $slot_key ) ) {
				continue;
			}

			self::write_slot_offset( $slot_key, $post_id, self::clamp_round( $offset, BMFR_Scoring::SLOT_OFFSET_MAX ) );
		}

		return self::get_priority_state( $post_id );
	}

	/**
	 * Tour page stepper: the Tour shows one card per slot, so its +/- moves
	 * that slot's offset only (the other slot is left alone). Returns the
	 * post's new total correction in that slot.
	 */
	public static function adjust_manual_score_boost( $slot_key, $post_id, $delta ) {
		$current = self::get_slot_offset( $slot_key, $post_id );
		self::write_slot_offset( $slot_key, absint( $post_id ), self::clamp_round( $current + (float) $delta, BMFR_Scoring::SLOT_OFFSET_MAX ) );

		return self::get_manual_score_boost( $slot_key, $post_id );
	}

	/**
	 * Tour page "reset": drops this slot's offset. The shared priority is
	 * managed on the Priorità e pesi page and stays as it is.
	 */
	public static function reset_manual_score_boost( $slot_key, $post_id ) {
		self::write_slot_offset( $slot_key, absint( $post_id ), 0.0 );
	}

	private static function write_slot_offset( $slot_key, $post_id, $value ) {
		$settings = self::get_slot_settings( $slot_key );

		if ( 0.0 === (float) $value ) {
			unset( $settings['manual_score_boosts'][ $post_id ] );
		} else {
			$settings['manual_score_boosts'][ $post_id ] = (float) $value;
		}

		self::update_slot_settings( $slot_key, $settings );
	}

	private static function clamp_round( $value, $max ) {
		$value = round( max( -$max, min( $max, (float) $value ) ), 2 );
		return abs( $value ) < 0.005 ? 0.0 : $value;
	}

	/**
	 * One-time split of the old per-slot corrections into shared priority +
	 * slot offsets (v1.19.0). Every post keeps exactly the same total in
	 * every slot whenever the two old values are at most
	 * 2 * SLOT_OFFSET_MAX apart (always true for same-sign values); beyond
	 * that the offsets hit their cap and the totals move closer together.
	 *
	 * Rule per post, with old values a (first slot) and b (second slot):
	 * start from the part both slots agree on (same sign: the smaller of the
	 * two; opposite signs: 0), then shift it just enough for both offsets
	 * to fit within +/-SLOT_OFFSET_MAX.
	 */
	public static function migrate_priority_model() {
		if ( (int) get_option( self::PRIORITY_MODEL_OPTION, 1 ) >= 2 ) {
			return;
		}

		$slot_keys = BMFR_Slots::get_slot_keys();
		$old       = array();

		foreach ( $slot_keys as $slot_key ) {
			$old[ $slot_key ] = self::get_slot_offsets( $slot_key );
		}

		$post_ids = array();
		foreach ( $old as $values ) {
			$post_ids = array_merge( $post_ids, array_map( 'absint', array_keys( $values ) ) );
		}
		$post_ids = array_unique( $post_ids );

		$shared_all = self::get_shared_priorities();
		$new_offsets = array_fill_keys( $slot_keys, array() );
		$cap         = BMFR_Scoring::SLOT_OFFSET_MAX;

		foreach ( $post_ids as $post_id ) {
			$values = array();
			foreach ( $slot_keys as $slot_key ) {
				$values[ $slot_key ] = isset( $old[ $slot_key ][ $post_id ] ) ? (float) $old[ $slot_key ][ $post_id ] : 0.0;
			}

			$min = min( $values );
			$max = max( $values );

			if ( $min >= 0 ) {
				$shared = $min;
			} elseif ( $max <= 0 ) {
				$shared = $max;
			} else {
				$shared = 0.0;
			}

			$low  = $max - $cap;
			$high = $min + $cap;

			if ( $low <= $high ) {
				$shared = max( $low, min( $high, $shared ) );
			} else {
				$shared = ( $min + $max ) / 2;
			}

			$shared = self::clamp_round( $shared, BMFR_Scoring::MANUAL_BOOST_MAX );

			if ( 0.0 !== $shared ) {
				$shared_all[ $post_id ] = $shared;
			}

			foreach ( $slot_keys as $slot_key ) {
				$offset = self::clamp_round( $values[ $slot_key ] - $shared, $cap );
				if ( 0.0 !== $offset ) {
					$new_offsets[ $slot_key ][ $post_id ] = $offset;
				}
			}
		}

		update_option( self::SHARED_PRIORITY_OPTION, $shared_all, false );

		foreach ( $slot_keys as $slot_key ) {
			$settings                        = self::get_slot_settings( $slot_key );
			$settings['manual_score_boosts'] = $new_offsets[ $slot_key ];
			self::update_slot_settings( $slot_key, $settings );
		}

		update_option( self::PRIORITY_MODEL_OPTION, 2 );
	}

	/**
	 * Admin-configured "HH:MM" hour at which due rotations are allowed to
	 * fire (see BMFR_Cron::run_tick()). Empty string = no restriction, i.e.
	 * run as soon as a slot is due, at any hour — the historical behaviour,
	 * kept as the default so upgrading never silently delays a rotation.
	 */
	public static function get_cron_time() {
		return self::sanitize_cron_time( get_option( self::CRON_TIME_OPTION, '' ) );
	}

	public static function update_cron_time( $time ) {
		update_option( self::CRON_TIME_OPTION, self::sanitize_cron_time( $time ), false );
	}

	public static function sanitize_cron_time( $raw ) {
		$raw = trim( (string) $raw );
		return preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $raw ) ? $raw : '';
	}

	/**
	 * Records that a slot's pending-rotation Slack warning was sent for a
	 * given due_at, so BMFR_Cron::run_tick() doesn't repeat it on every
	 * hourly tick while the slot sits inside the lead-time window.
	 */
	public static function mark_pre_notified( $slot_key, $due_at ) {
		$settings                         = self::get_slot_settings( $slot_key );
		$settings['pre_notified_due_at'] = (int) $due_at;
		self::update_slot_settings( $slot_key, $settings );
	}

	public static function get_slack_settings() {
		$saved = get_option( self::SLACK_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args(
			$saved,
			array(
				'webhook_url' => '',
				'lead_hours'  => 24,
			)
		);
	}

	public static function update_slack_settings( array $settings ) {
		update_option( self::SLACK_OPTION, $settings, false );
	}

	public static function sanitize_slack_settings( $raw_url, $raw_lead_hours ) {
		$url = trim( (string) $raw_url );

		return array(
			'webhook_url' => ( '' !== $url && wp_http_validate_url( $url ) ) ? esc_url_raw( $url ) : '',
			'lead_hours'  => min( 168, max( 1, absint( $raw_lead_hours ) ) ),
		);
	}

	public static function get_notification_settings() {
		$saved = get_option( self::NOTIFICATION_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args(
			$saved,
			array(
				'recipients'     => array(),
				// Text in [brackets] at the start of the email subject. Empty
				// means the site title.
				'subject_prefix' => '',
			)
		);
	}

	/**
	 * Name shown in the summary email subject: the saved prefix, or the
	 * site title when none is set.
	 */
	public static function get_email_subject_prefix() {
		$settings = self::get_notification_settings();
		$prefix   = trim( (string) $settings['subject_prefix'] );

		return '' !== $prefix ? $prefix : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	public static function update_notification_settings( array $settings ) {
		update_option( self::NOTIFICATION_OPTION, $settings, false );
	}

	/**
	 * @param string      $raw_text   Recipients, one per line or comma-separated.
	 * @param string|null $raw_prefix Email subject prefix; null keeps the saved one.
	 */
	public static function sanitize_notification_settings( $raw_text, $raw_prefix = null ) {
		$prefix = null === $raw_prefix
			? self::get_notification_settings()['subject_prefix']
			: sanitize_text_field( (string) $raw_prefix );

		$lines  = preg_split( '/[\r\n,]+/', (string) $raw_text, -1, PREG_SPLIT_NO_EMPTY );
		$emails = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line && is_email( $line ) ) {
				$emails[] = sanitize_email( $line );
			}
		}

		return array(
			'recipients'     => array_values( array_unique( $emails ) ),
			'subject_prefix' => $prefix,
		);
	}
}
