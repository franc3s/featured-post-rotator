<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Candidate gathering, eligibility filtering, scoring orchestration and the
 * final weighted-random selection for one slot.
 */
class BMFR_Selector {

	/**
	 * Builds the WP_Date_Query clauses for a slot's age window (min_age_days
	 * floor, max_age_days/max_age_date ceiling depending on max_age_mode).
	 * Shared by get_eligible_candidates() (an actual rotation) and
	 * get_slot_candidate_pool() (the Tour page's full-universe sweep) so
	 * both agree on what "within the configured age window" means.
	 */
	private static function build_age_date_query( array $settings ) {
		$date_query = array();

		if ( ! empty( $settings['min_age_days'] ) ) {
			$date_query[] = array(
				'before'    => gmdate( 'Y-m-d H:i:s', time() - ( $settings['min_age_days'] * DAY_IN_SECONDS ) ),
				'inclusive' => true,
			);
		}

		$max_age_mode = isset( $settings['max_age_mode'] ) ? $settings['max_age_mode'] : 'none';

		if ( 'relative' === $max_age_mode && ! empty( $settings['max_age_days'] ) ) {
			$date_query[] = array(
				'after'     => gmdate( 'Y-m-d H:i:s', time() - ( $settings['max_age_days'] * DAY_IN_SECONDS ) ),
				'inclusive' => true,
			);
		} elseif ( 'absolute' === $max_age_mode && ! empty( $settings['max_age_date'] ) ) {
			$date_query[] = array(
				'after'     => $settings['max_age_date'] . ' 00:00:00',
				'inclusive' => true,
			);
		}

		return $date_query;
	}

	/**
	 * Every published post inside the slot's age window, full stop: no
	 * exclusion, seasonal, cross-slot or cooldown filtering. That's
	 * deliberate — age is a fixed editorial rule ("too old/too new to ever
	 * run here"), while the other filters are all rotation STATE that
	 * shifts constantly (cooldown cycles every post in and out every few
	 * days, season is a calendar window, cross-slot depends on what's
	 * tagged right now). A one-time sweep for permanent exclusions (the
	 * Tour page) needs the former and must ignore the latter, or an
	 * operator would have to re-run the sweep every time cooldown or the
	 * season shifts just to see the same posts again.
	 */
	public static function get_slot_candidate_pool( $slot_key, array $settings ) {
		$args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'fields'              => 'ids',
			'posts_per_page'      => -1,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		);

		$date_query = self::build_age_date_query( $settings );

		if ( ! empty( $date_query ) ) {
			$args['date_query'] = $date_query;
		}

		return array_map( 'absint', get_posts( $args ) );
	}

	/**
	 * Pool = ALL published posts minus exclusions/min-age, minus cooldown.
	 * The pool is never limited to posts already carrying the slot tag — the
	 * tag assignment is the algorithm's output, not its input.
	 *
	 * @param array $warnings Written by reference: human-readable strings for
	 *                         any safety-valve relaxation, surfaced in the
	 *                         admin preview and logged as "warning" rows.
	 */
	public static function get_eligible_candidates( $slot_key, array $settings, &$warnings ) {
		$warnings = array();

		$args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'fields'              => 'ids',
			'posts_per_page'      => -1,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		);

		$date_query = self::build_age_date_query( $settings );

		if ( ! empty( $date_query ) ) {
			// WP_Date_Query's default relation between top-level clauses is
			// AND, which is exactly "after max-age floor AND before min-age
			// ceiling" when both are present.
			$args['date_query'] = $date_query;
		}

		// Global exclusions (apply to every slot) are unioned with this
		// slot's own exclusion lists, so an editor doesn't have to repeat the
		// same post/category in both slots' settings.
		$global_exclusions   = BMFR_Settings::get_global_exclusions();
		$excluded_categories = array_unique( array_merge( (array) $settings['excluded_categories'], (array) $global_exclusions['excluded_categories'] ) );
		$excluded_post_ids   = array_unique( array_merge( (array) $settings['excluded_post_ids'], (array) $global_exclusions['excluded_post_ids'] ) );

		if ( ! empty( $excluded_categories ) ) {
			$args['category__not_in'] = array_map( 'absint', $excluded_categories );
		}

		if ( ! empty( $excluded_post_ids ) ) {
			$args['post__not_in'] = array_map( 'absint', $excluded_post_ids );
		}

		$pool = array_map( 'absint', get_posts( $args ) );

		// Hard rule: the current post (the most recently published one, which
		// the blog already shows at the top of the home) is never a candidate
		// in any slot, otherwise the same article appears twice side by side.
		// Applied to the pool before cooldown, so the cooldown safety valve
		// can never re-admit it either. min_age_days alone doesn't cover this:
		// once the latest post is older than the floor (no new article for a
		// few days) it would otherwise become eligible.
		$latest_post_id = self::get_latest_post_id();

		if ( $latest_post_id ) {
			$pool = array_values( array_diff( $pool, array( $latest_post_id ) ) );
		}

		// Hard sanity filter, independent of (and applied before) cooldown:
		// a post that reads as tied to a specific calendar window (e.g. "San
		// Valentino") is never shown while we're outside that window, in
		// either slot. See BMFR_Seasonal for the rationale.
		$out_of_season = BMFR_Seasonal::get_out_of_season( $pool );

		if ( ! empty( $out_of_season ) ) {
			$pool = array_values( array_diff( $pool, array_keys( $out_of_season ) ) );

			$warnings[] = sprintf(
				/* translators: 1: number of posts excluded, 2: comma-separated list of matched seasonal labels */
				__( '%1$d post esclusi perché fuori stagione (%2$s).', 'featured-post-rotator' ),
				count( $out_of_season ),
				implode( ', ', array_unique( array_values( $out_of_season ) ) )
			);
		}

		// Cross-slot exclusion: a post currently featured in ANOTHER slot is
		// never a candidate here, so Trend Posts and Spotlight never show the
		// same post at the same time. Checked against the other slot's
		// *current* tag assignment, so whichever slot rotates first in a
		// cron tick is respected by whichever runs second.
		$other_slot_tagged = array();
		foreach ( BMFR_Slots::get_slot_keys() as $other_slot_key ) {
			if ( $other_slot_key === $slot_key ) {
				continue;
			}
			$other_slot_tagged = array_merge( $other_slot_tagged, BMFR_Rotator::get_currently_tagged_post_ids( $other_slot_key ) );
		}
		$other_slot_tagged = array_unique( array_map( 'absint', $other_slot_tagged ) );
		$cross_slot_conflicts = array_intersect( $pool, $other_slot_tagged );

		if ( ! empty( $cross_slot_conflicts ) ) {
			$pool = array_values( array_diff( $pool, $other_slot_tagged ) );

			$warnings[] = sprintf(
				/* translators: %d: number of posts excluded because they are already featured in another slot */
				__( '%d post esclusi perché già in evidenza in un altro slot nello stesso momento.', 'featured-post-rotator' ),
				count( $cross_slot_conflicts )
			);
		}

		$cooldown_map = BMFR_History::get_posts_in_cooldown( $slot_key, (int) $settings['cooldown_days'] );
		$cooldown_ids = array_keys( $cooldown_map );

		$eligible = array_values( array_diff( $pool, $cooldown_ids ) );

		$slot_info    = BMFR_Slots::get_slot( $slot_key );
		$count_needed = $slot_info ? $slot_info['count'] : 0;

		if ( count( $eligible ) < $count_needed && ! empty( $cooldown_ids ) ) {
			// Safety valve: re-admit posts that left cooldown longest ago,
			// oldest "last_in" first, until we reach the required count.
			asort( $cooldown_map );

			foreach ( array_keys( $cooldown_map ) as $candidate_id ) {
				if ( count( $eligible ) >= $count_needed ) {
					break;
				}
				if ( in_array( $candidate_id, $pool, true ) && ! in_array( $candidate_id, $eligible, true ) ) {
					$eligible[] = $candidate_id;
				}
			}

			$warnings[] = sprintf(
				/* translators: 1: eligible candidates before relaxation, 2: posts required for the slot */
				__( 'Pool eleggibile insufficiente (%1$d candidati per %2$d posizioni): cooldown rilassato per raggiungere il numero richiesto.', 'featured-post-rotator' ),
				count( $pool ) - count( $cooldown_ids ),
				$count_needed
			);
		}

		return $eligible;
	}

	/**
	 * ID of the most recently published post (the blog's "current post"),
	 * or 0 if there is none. Ignores sticky flags and every rotator filter.
	 */
	public static function get_latest_post_id() {
		$latest = get_posts(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'fields'              => 'ids',
				'posts_per_page'      => 1,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => true,
			)
		);

		return ! empty( $latest ) ? absint( $latest[0] ) : 0;
	}

	public static function score_candidates( $slot_key, array $settings, array $candidate_ids ) {
		$category_scores   = BMFR_Scoring::category_balance_scores(
			$candidate_ids,
			$slot_key,
			max( 1, (int) $settings['cooldown_days'] * 3 )
		);
		$engagement_scores = BMFR_Scoring::engagement_scores( $candidate_ids );
		$seasonal_scores    = BMFR_Seasonal::get_fit_scores( $candidate_ids );

		$scored = array();

		foreach ( $candidate_ids as $post_id ) {
			$recency          = BMFR_Scoring::recency_score( $post_id, $settings['recency_half_life_days'] );
			$category_balance = isset( $category_scores[ $post_id ] ) ? $category_scores[ $post_id ] : 0.5;
			$engagement       = isset( $engagement_scores[ $post_id ] ) ? $engagement_scores[ $post_id ] : 0.5;
			$seasonal_fit     = isset( $seasonal_scores[ $post_id ] ) ? $seasonal_scores[ $post_id ] : 0.5;

			$combined     = BMFR_Scoring::combine( $settings['weights'], $recency, $category_balance, $engagement, $seasonal_fit );
			$manual_boost = BMFR_Settings::get_manual_score_boost( $slot_key, $post_id );
			$final        = max( 0, min( 1, $combined['final'] + $manual_boost ) );

			$scored[ $post_id ] = array(
				'post_id'      => $post_id,
				'final'        => $final,
				'manual_boost' => $manual_boost,
				'breakdown' => array(
					'recency'          => $recency,
					'category_balance' => $category_balance,
					'engagement'       => $engagement,
					'seasonal_fit'     => $seasonal_fit,
					'weights'          => $combined['weights_used'],
					'final'            => $combined['final'],
					'category_id'      => BMFR_Scoring::get_primary_category_id( $post_id ),
				),
			);
		}

		return $scored;
	}

	/**
	 * Weighted random sample without replacement via the Efraimidis-Spirakis
	 * "A-Res" algorithm: one O(n log n) pass, ties broken automatically by the
	 * random draw, no special-casing needed.
	 */
	public static function weighted_sample( array $scored, $n ) {
		$keys = array();

		foreach ( $scored as $post_id => $data ) {
			$weight       = max( 0.0001, (float) $data['final'] );
			$u            = wp_rand( 1, PHP_INT_MAX - 1 ) / PHP_INT_MAX;
			$keys[ $post_id ] = pow( $u, 1 / $weight );
		}

		arsort( $keys );

		return array_slice( array_keys( $keys ), 0, max( 0, (int) $n ) );
	}
}
