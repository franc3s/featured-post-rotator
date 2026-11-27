<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates one rotation run for one slot: select -> diff against the
 * currently tagged posts -> apply (unless dry-run) -> log -> notify.
 *
 * Cron and manual "Esegui ora" both call this exact same method, so there is
 * no behavioral divergence between automatic and supervised runs.
 */
class BMFR_Rotator {

	/**
	 * @param string $slot_key
	 * @param string $trigger  'cron' | 'manual'
	 * @param bool   $dry_run  When true, no writes happen at all — this is
	 *                         what powers the admin Preview page.
	 * @return array|WP_Error
	 */
	public static function run( $slot_key, $trigger = 'manual', $dry_run = false ) {
		if ( ! BMFR_Slots::get_slot( $slot_key ) ) {
			return new WP_Error( 'bmfr_invalid_slot', __( 'Invalid slot.', 'featured-post-rotator' ) );
		}

		// New installs start with slots that have no tag yet: nothing to
		// rotate until one is chosen in Impostazioni → Slot.
		if ( ! BMFR_Slots::has_tag( $slot_key ) ) {
			return new WP_Error( 'bmfr_slot_no_tag', __( 'This slot has no tag yet: choose one in Settings → Slots.', 'featured-post-rotator' ) );
		}

		// A dry run writes nothing, so it never needs (or waits for) the lock.
		if ( $dry_run ) {
			return self::execute( $slot_key, $trigger, true );
		}

		if ( ! BMFR_Lock::acquire( self::lock_name( $slot_key ) ) ) {
			return new WP_Error( 'bmfr_locked', __( 'A rotation of this slot is already running: try again in a few minutes.', 'featured-post-rotator' ) );
		}

		try {
			return self::execute( $slot_key, $trigger, false );
		} finally {
			BMFR_Lock::release( self::lock_name( $slot_key ) );
		}
	}

	/**
	 * Name of the BMFR_Lock that serializes every tag-changing operation on
	 * one slot (run, top_up, swap_out, enforce_exclusions). Public so the
	 * cron tick can hold it while it re-checks whether the slot is still due.
	 */
	public static function lock_name( $slot_key ) {
		return 'slot_' . $slot_key;
	}

	/**
	 * Public hook fired after the slot tags actually changed (any path:
	 * rotation, top-up, swap, exclusion). BMFR_Cache listens to it to purge
	 * the page cache; anything else (e.g. a future cross-domain sync) can too.
	 */
	private static function fire_applied( $slot_key, array $added, array $removed, $trigger ) {
		do_action( 'bmfr_rotation_applied', $slot_key, array_values( $added ), array_values( $removed ), $trigger );
	}

	private static function execute( $slot_key, $trigger, $dry_run ) {
		$slot = BMFR_Slots::get_slot( $slot_key );

		// Under the lock, read past this request's option cache so a run that
		// another process just finished (new last_run) is never missed.
		$settings = $dry_run ? BMFR_Settings::get_slot_settings( $slot_key ) : BMFR_Settings::get_slot_settings_fresh( $slot_key );

		// The cron tick already checks this before calling run(), but this
		// guard makes BMFR_Rotator::run() safe to call directly too.
		if ( 'cron' === $trigger && empty( $settings['enabled'] ) ) {
			return new WP_Error( 'bmfr_slot_disabled', __( 'Slot turned off, cron run skipped.', 'featured-post-rotator' ) );
		}

		$warnings   = array();
		$candidates = BMFR_Selector::get_eligible_candidates( $slot_key, $settings, $warnings );
		$scored     = BMFR_Selector::score_candidates( $slot_key, $settings, $candidates );
		$selected   = BMFR_Selector::weighted_sample( $scored, $slot['count'] );

		if ( count( $candidates ) < $slot['count'] ) {
			$warnings[] = sprintf(
				/* translators: 1: eligible candidates, 2: posts required, 3: slot label */
				__( 'Only %1$d eligible posts out of %2$d required for the slot "%3$s".', 'featured-post-rotator' ),
				count( $candidates ),
				$slot['count'],
				$slot['label']
			);
		}

		$term              = self::get_or_create_slot_term( $slot, $dry_run );
		$currently_tagged  = $term ? self::get_posts_with_term( $term->term_id ) : array();

		$to_add    = array_values( array_diff( $selected, $currently_tagged ) );
		$to_remove = array_values( array_diff( $currently_tagged, $selected ) );
		$unchanged = array_values( array_intersect( $selected, $currently_tagged ) );

		$result = array(
			'slot'            => $slot_key,
			'trigger'         => $trigger,
			'dry_run'         => $dry_run,
			'selected'        => $selected,
			'to_add'          => $to_add,
			'to_remove'       => $to_remove,
			'unchanged'       => $unchanged,
			'scored'          => $scored,
			'warnings'        => $warnings,
			'candidate_count' => count( $candidates ),
		);

		if ( $dry_run ) {
			return $result;
		}

		$run_id = wp_generate_uuid4();

		foreach ( $to_add as $post_id ) {
			wp_set_post_terms( $post_id, array( (int) $term->term_id ), 'post_tag', true );

			$data = isset( $scored[ $post_id ] ) ? $scored[ $post_id ] : null;
			BMFR_History::log_event(
				$slot_key,
				$run_id,
				$post_id,
				'in',
				$data ? $data['final'] : null,
				$data ? $data['breakdown'] : array(),
				$data ? $data['breakdown']['category_id'] : null,
				$trigger
			);
		}

		foreach ( $to_remove as $post_id ) {
			wp_remove_object_terms( $post_id, (int) $term->term_id, 'post_tag' );
			BMFR_History::log_event( $slot_key, $run_id, $post_id, 'out', null, array(), null, $trigger );
		}

		foreach ( $warnings as $warning_message ) {
			BMFR_History::log_event(
				$slot_key,
				$run_id,
				0,
				'warning',
				null,
				array( 'message' => $warning_message ),
				null,
				$trigger
			);
		}

		$settings['last_run'] = time();
		$settings['next_run'] = time() + ( max( 1, (int) $settings['cadence_days'] ) * DAY_IN_SECONDS );
		BMFR_Settings::update_slot_settings( $slot_key, $settings );

		if ( ( count( $to_add ) + count( $to_remove ) ) > 0 ) {
			self::fire_applied( $slot_key, $to_add, $to_remove, $trigger );
			BMFR_Notifier::send_rotation_summary( $slot_key, $to_add, $to_remove, $scored, $trigger );
		}

		$result['run_id'] = $run_id;

		return $result;
	}

	/**
	 * Tops a slot back up to its target count without touching the members
	 * already in it — used right after an exclusion strips a live post, so a
	 * removed post is replaced immediately instead of leaving the slot
	 * under-filled until the next scheduled rotation. Reuses the same
	 * eligibility + scoring + weighted-sampling pipeline as a full run(), so
	 * the replacement obeys every existing rule (cooldown, seasonal window,
	 * cross-slot, exclusions...).
	 */
	public static function top_up( $slot_key, array $exclude_ids = array() ) {
		if ( ! BMFR_Slots::get_slot( $slot_key ) ) {
			return;
		}

		// Re-entrant: swap_out() and enforce_exclusions() already hold it.
		if ( ! BMFR_Lock::acquire( self::lock_name( $slot_key ) ) ) {
			return;
		}

		try {
			self::do_top_up( $slot_key, $exclude_ids );
		} finally {
			BMFR_Lock::release( self::lock_name( $slot_key ) );
		}
	}

	private static function do_top_up( $slot_key, array $exclude_ids ) {
		$slot = BMFR_Slots::get_slot( $slot_key );

		$currently_tagged = self::get_currently_tagged_post_ids( $slot_key );
		$needed            = $slot['count'] - count( $currently_tagged );

		if ( $needed <= 0 ) {
			return;
		}

		$settings   = BMFR_Settings::get_slot_settings( $slot_key );
		$warnings   = array();
		$candidates = BMFR_Selector::get_eligible_candidates( $slot_key, $settings, $warnings );
		$candidates = array_values( array_diff( $candidates, $currently_tagged, array_map( 'absint', $exclude_ids ) ) );

		if ( empty( $candidates ) ) {
			return;
		}

		$scored   = BMFR_Selector::score_candidates( $slot_key, $settings, $candidates );
		$selected = BMFR_Selector::weighted_sample( $scored, $needed );

		$term = self::get_or_create_slot_term( $slot, false );

		if ( ! $term ) {
			return;
		}

		$run_id = wp_generate_uuid4();

		foreach ( $selected as $post_id ) {
			wp_set_post_terms( $post_id, array( (int) $term->term_id ), 'post_tag', true );

			$data = isset( $scored[ $post_id ] ) ? $scored[ $post_id ] : null;
			BMFR_History::log_event(
				$slot_key,
				$run_id,
				$post_id,
				'in',
				$data ? $data['final'] : null,
				$data ? $data['breakdown'] : array(),
				$data ? $data['breakdown']['category_id'] : null,
				'topup'
			);
		}

		if ( ! empty( $selected ) ) {
			self::fire_applied( $slot_key, $selected, array(), 'topup' );
		}
	}

	/**
	 * "Cambia": takes one post out of a slot it is live in and replaces it
	 * with a fresh pick, WITHOUT adding it to any exclusion list — it stays a
	 * normal candidate for future rotations. The removed post is kept out of
	 * this one replacement draw only (so it can't be re-picked on the spot,
	 * even via the cooldown safety valve).
	 *
	 * @return bool True if the post was in the slot and got removed.
	 */
	public static function swap_out( $slot_key, $post_id ) {
		if ( ! BMFR_Slots::get_slot( $slot_key ) ) {
			return false;
		}

		if ( ! BMFR_Lock::acquire( self::lock_name( $slot_key ) ) ) {
			return false;
		}

		try {
			return self::do_swap_out( $slot_key, $post_id );
		} finally {
			BMFR_Lock::release( self::lock_name( $slot_key ) );
		}
	}

	private static function do_swap_out( $slot_key, $post_id ) {
		$slot    = BMFR_Slots::get_slot( $slot_key );
		$post_id = absint( $post_id );

		if ( ! $slot || ! $post_id || ! in_array( $post_id, self::get_currently_tagged_post_ids( $slot_key ), true ) ) {
			return false;
		}

		$term = get_term_by( 'slug', $slot['tag_slug'], 'post_tag' );

		if ( ! $term ) {
			return false;
		}

		wp_remove_object_terms( $post_id, (int) $term->term_id, 'post_tag' );
		BMFR_History::log_event(
			$slot_key,
			wp_generate_uuid4(),
			$post_id,
			'out',
			null,
			array( 'message' => __( 'Replaced by hand (Swap), not excluded', 'featured-post-rotator' ) ),
			null,
			'swap'
		);

		self::fire_applied( $slot_key, array(), array( $post_id ), 'swap' );
		self::top_up( $slot_key, array( $post_id ) );

		return true;
	}

	/**
	 * Re-checks every currently-tagged post in the given slot (or every slot,
	 * if none specified) against the current exclusion lists (global + that
	 * slot's own), strips any that are now excluded, and tops the slot back
	 * up. Call this right after any exclusion list changes — adding a global
	 * post/category exclusion, or the post-edit metabox — so an exclusion
	 * takes effect on the live site immediately rather than waiting for the
	 * next scheduled rotation.
	 */
	public static function enforce_exclusions( $slot_key = null ) {
		$slot_keys = $slot_key ? array( $slot_key ) : BMFR_Slots::get_slot_keys();

		foreach ( $slot_keys as $key ) {
			if ( ! BMFR_Slots::get_slot( $key ) ) {
				continue;
			}

			if ( ! BMFR_Lock::acquire( self::lock_name( $key ) ) ) {
				// A rotation of this slot is running right now: it reads the
				// exclusion lists itself, so the excluded post won't be picked.
				continue;
			}

			try {
				self::enforce_exclusions_for_slot( $key );
			} finally {
				BMFR_Lock::release( self::lock_name( $key ) );
			}
		}
	}

	private static function enforce_exclusions_for_slot( $key ) {
		$slot   = BMFR_Slots::get_slot( $key );
		$tagged = self::get_currently_tagged_post_ids( $key );

		if ( empty( $tagged ) ) {
			return;
		}

		$settings = BMFR_Settings::get_slot_settings( $key );
		$global   = BMFR_Settings::get_global_exclusions();

		$excluded_posts      = array_unique( array_map( 'absint', array_merge( (array) $settings['excluded_post_ids'], (array) $global['excluded_post_ids'] ) ) );
		$excluded_categories = array_unique( array_map( 'absint', array_merge( (array) $settings['excluded_categories'], (array) $global['excluded_categories'] ) ) );

		$term = get_term_by( 'slug', $slot['tag_slug'], 'post_tag' );

		if ( ! $term ) {
			return;
		}

		$removed = array();

		foreach ( $tagged as $post_id ) {
			$is_excluded_post     = in_array( $post_id, $excluded_posts, true );
			$post_category_ids    = wp_get_post_categories( $post_id );
			$is_excluded_category = ! empty( array_intersect( $post_category_ids, $excluded_categories ) );

			if ( ! $is_excluded_post && ! $is_excluded_category ) {
				continue;
			}

			wp_remove_object_terms( $post_id, (int) $term->term_id, 'post_tag' );
			BMFR_History::log_event(
				$key,
				wp_generate_uuid4(),
				$post_id,
				'out',
				null,
				array( 'message' => __( 'Removed because excluded (post or category)', 'featured-post-rotator' ) ),
				null,
				'exclusion'
			);
			$removed[] = $post_id;
		}

		if ( ! empty( $removed ) ) {
			self::fire_applied( $key, array(), $removed, 'exclusion' );
			self::top_up( $key );
		}
	}

	/**
	 * Read-only lookup used by BMFR_Selector for cross-slot exclusion: which
	 * posts currently carry this slot's tag. Never creates the term (a slot
	 * with no term yet simply has nothing tagged).
	 */
	public static function get_currently_tagged_post_ids( $slot_key ) {
		$slot = BMFR_Slots::get_slot( $slot_key );

		if ( ! $slot ) {
			return array();
		}

		$term = get_term_by( 'slug', $slot['tag_slug'], 'post_tag' );

		if ( ! $term ) {
			return array();
		}

		return self::get_posts_with_term( $term->term_id );
	}

	/**
	 * Resolves the slot's tag term, creating it only when actually applying a
	 * real run (never during a dry-run, which must not write anything).
	 */
	private static function get_or_create_slot_term( array $slot, $dry_run ) {
		if ( '' === $slot['tag_slug'] ) {
			return null;
		}

		$term = get_term_by( 'slug', $slot['tag_slug'], 'post_tag' );

		if ( $term ) {
			return $term;
		}

		if ( $dry_run ) {
			return null;
		}

		$created = wp_insert_term( $slot['tag_slug'], 'post_tag', array( 'slug' => $slot['tag_slug'] ) );

		if ( is_wp_error( $created ) ) {
			return null;
		}

		return get_term( $created['term_id'], 'post_tag' );
	}

	private static function get_posts_with_term( $term_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'post_tag',
						'field'    => 'term_id',
						'terms'    => (int) $term_id,
					),
				),
			)
		);

		return array_map( 'absint', $posts );
	}
}
