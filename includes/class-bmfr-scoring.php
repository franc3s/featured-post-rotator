<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-signal score calculators. Cooldown is deliberately NOT one of these —
 * it is a hard eligibility filter in BMFR_Selector, not a weighted signal,
 * so it can never be out-voted by a post with very high recency/engagement.
 */
class BMFR_Scoring {

	// Tour page's per-cycle manual score correction: still a bounded range
	// so an editor can nudge the algorithm's ranking without being able to
	// force any post to the very top or bottom outright (that's a separate,
	// explicit action, not this dial) — but wide enough, across 6 steps
	// each way, to let a real priority order emerge between several boosted
	// posts instead of everyone piling up against the same old +/-0.15 wall.
	const MANUAL_BOOST_STEP = 0.05;
	const MANUAL_BOOST_MAX  = 0.30;

	// Priority model since v1.19.0: the correction a post gets in a slot is
	// shared priority (one value per post, same in every slot, capped at
	// MANUAL_BOOST_MAX) + that slot's own small offset (capped here). The
	// shared part covers the common case ("push this article") with one
	// action; the offset is for the exception ("only in Spotlight").
	const SLOT_OFFSET_MAX = 0.15;

	// Largest total correction a post can carry in one slot.
	const TOTAL_BOOST_MAX = 0.45;

	/**
	 * Exponential decay, floored at 0.05 so evergreen posts never hit zero
	 * and stay selectable, they just rank lower.
	 */
	public static function recency_score( $post_id, $half_life_days ) {
		$published = get_post_time( 'U', true, $post_id );

		if ( ! $published ) {
			return 0.05;
		}

		$days_old       = max( 0, ( time() - $published ) / DAY_IN_SECONDS );
		$half_life_days = max( 1, (float) $half_life_days );
		$score          = exp( -$days_old / $half_life_days );

		return max( 0.05, min( 1, $score ) );
	}

	public static function get_primary_category_id( $post_id ) {
		$yoast_primary = get_post_meta( $post_id, '_yoast_wpseo_primary_category', true );

		if ( ! empty( $yoast_primary ) ) {
			return (int) $yoast_primary;
		}

		$categories = get_the_category( $post_id );

		if ( ! empty( $categories ) ) {
			return (int) $categories[0]->term_id;
		}

		return 0;
	}

	/**
	 * Favors categories under-represented in the slot's recent rotation
	 * history (not just within the current 3-4 picks, which is too small a
	 * sample to "balance" meaningfully on its own). No history yet -> neutral
	 * 0.5 for everyone, so the very first run carries no category bias.
	 */
	public static function category_balance_scores( array $post_ids, $slot_key, $lookback_days ) {
		$counts    = BMFR_History::get_category_counts( $slot_key, $lookback_days );
		$max_count = empty( $counts ) ? 0 : max( $counts );

		$scores = array();

		foreach ( $post_ids as $post_id ) {
			if ( $max_count <= 0 ) {
				$scores[ $post_id ] = 0.5;
				continue;
			}

			$category_id        = self::get_primary_category_id( $post_id );
			$count               = isset( $counts[ $category_id ] ) ? $counts[ $category_id ] : 0;
			$scores[ $post_id ] = 1 - ( $count / $max_count );
		}

		return $scores;
	}

	/**
	 * comment_count normalized min-max across the current eligible pool, with
	 * a 0.1 floor so zero-comment posts aren't fully zeroed out when the
	 * engagement weight is high.
	 */
	public static function engagement_scores( array $post_ids ) {
		if ( empty( $post_ids ) ) {
			return array();
		}

		$raw = array();
		foreach ( $post_ids as $post_id ) {
			$raw[ $post_id ] = (int) get_post_field( 'comment_count', $post_id );
		}

		$min = min( $raw );
		$max = max( $raw );

		$scores = array();
		foreach ( $raw as $post_id => $count ) {
			$normalized         = ( $max === $min ) ? 0.5 : ( $count - $min ) / ( $max - $min );
			$scores[ $post_id ] = 0.1 + ( 0.9 * $normalized );
		}

		return $scores;
	}

	/**
	 * Weighted average of the four signals. If every weight is 0, falls back
	 * to equal quarters rather than producing an undefined/zero score.
	 */
	public static function combine( array $weights, $recency, $category_balance, $engagement, $seasonal_fit ) {
		$w_recency    = isset( $weights['recency'] ) ? (float) $weights['recency'] : 0;
		$w_category   = isset( $weights['category_balance'] ) ? (float) $weights['category_balance'] : 0;
		$w_engagement = isset( $weights['engagement'] ) ? (float) $weights['engagement'] : 0;
		$w_seasonal   = isset( $weights['seasonal_fit'] ) ? (float) $weights['seasonal_fit'] : 0;

		$sum_weights = $w_recency + $w_category + $w_engagement + $w_seasonal;

		if ( $sum_weights <= 0 ) {
			$w_recency    = 0.25;
			$w_category   = 0.25;
			$w_engagement = 0.25;
			$w_seasonal   = 0.25;
			$sum_weights  = 1;
		}

		$final = (
			( $w_recency * $recency ) +
			( $w_category * $category_balance ) +
			( $w_engagement * $engagement ) +
			( $w_seasonal * $seasonal_fit )
		) / $sum_weights;

		return array(
			'final'        => $final,
			'weights_used' => array(
				'recency'          => $w_recency,
				'category_balance' => $w_category,
				'engagement'       => $w_engagement,
				'seasonal_fit'     => $w_seasonal,
			),
		);
	}
}
