<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "seasonal dictionary": a small admin-editable list of recurring themes
 * (San Valentino, Black Friday, ...), each matched against posts by title
 * keyword and/or tag slug, with a MM-DD date window.
 *
 * A post matching an entry is HARD-EXCLUDED from candidacy in both slots
 * whenever today falls outside that entry's window (e.g. never show a
 * "San Valentino" post in November) — this is a non-negotiable sanity filter,
 * not a per-slot preference. When today falls inside the window, the post
 * instead gets a "seasonal_fit" score boost, whose weight is configurable
 * per slot (high for Trend Posts, low for Spotlight), reflecting that Trend
 * is meant to feel "in tema ora" while Spotlight is an evergreen editorial
 * pick that doesn't need to chase the calendar.
 *
 * No AI involved: this is plain keyword/tag matching plus a date-range
 * check, entirely transparent and editable from wp-admin.
 */
class BMFR_Seasonal {

	const OPTION = 'bmfr_seasonal_calendar';

	public static function get_default_calendar() {
		return array(
			array(
				'label'     => 'San Valentino',
				'keywords'  => array( 'san valentino' ),
				'tag_slugs' => array(),
				'start'     => '01-25',
				'end'       => '02-16',
				'enabled'   => true,
			),
			array(
				'label'     => 'Festa della Donna',
				'keywords'  => array( 'festa della donna', '8 marzo' ),
				'tag_slugs' => array(),
				'start'     => '03-01',
				'end'       => '03-10',
				'enabled'   => true,
			),
			array(
				'label'     => 'Halloween',
				'keywords'  => array( 'halloween' ),
				'tag_slugs' => array(),
				'start'     => '10-10',
				'end'       => '11-02',
				'enabled'   => true,
			),
			array(
				'label'     => 'Black Friday',
				'keywords'  => array( 'black friday', 'cyber monday' ),
				'tag_slugs' => array(),
				'start'     => '11-10',
				'end'       => '12-02',
				'enabled'   => true,
			),
			array(
				'label'     => 'Natale e Capodanno',
				'keywords'  => array( 'natale', 'capodanno', 'buone feste' ),
				'tag_slugs' => array(),
				'start'     => '12-01',
				'end'       => '01-06', // wraps the year boundary, see is_date_in_window().
				'enabled'   => true,
			),
		);
	}

	public static function get_calendar() {
		$saved = get_option( self::OPTION, null );
		return is_array( $saved ) ? $saved : self::get_default_calendar();
	}

	public static function update_calendar( array $entries ) {
		update_option( self::OPTION, $entries, false );
	}

	public static function parse_csv_list( $raw ) {
		$parts = array_map( 'trim', explode( ',', (string) $raw ) );
		return array_values(
			array_filter(
				$parts,
				static function ( $value ) {
					return '' !== $value;
				}
			)
		);
	}

	/**
	 * $start_md/$end_md are 'MM-DD' strings compared lexically (safe since
	 * both are zero-padded to 2 digits). $end_md < $start_md means the window
	 * wraps the year boundary (e.g. 12-01 to 01-06 for Natale/Capodanno).
	 */
	public static function is_date_in_window( $start_md, $end_md, $today_md = null ) {
		$today_md = $today_md ? $today_md : current_time( 'm-d' );

		if ( $start_md <= $end_md ) {
			return ( $today_md >= $start_md && $today_md <= $end_md );
		}

		return ( $today_md >= $start_md || $today_md <= $end_md );
	}

	public static function match_entry( $post_id, array $entry ) {
		if ( empty( $entry['enabled'] ) ) {
			return false;
		}

		$title = function_exists( 'mb_strtolower' ) ? mb_strtolower( get_the_title( $post_id ) ) : strtolower( get_the_title( $post_id ) );

		foreach ( (array) $entry['keywords'] as $keyword ) {
			$keyword = trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( $keyword ) : strtolower( $keyword ) );
			if ( '' !== $keyword && false !== strpos( $title, $keyword ) ) {
				return true;
			}
		}

		if ( ! empty( $entry['tag_slugs'] ) ) {
			$post_tags = wp_get_post_tags( $post_id, array( 'fields' => 'slugs' ) );
			if ( ! empty( array_intersect( (array) $entry['tag_slugs'], (array) $post_tags ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * post_id => matched entry label, for posts that match a seasonal entry
	 * AND fall outside that entry's window right now. The first matching
	 * entry wins; a post matching several entries at once is an edge case
	 * not worth resolving further.
	 */
	public static function get_out_of_season( array $post_ids ) {
		$calendar = self::get_calendar();
		$excluded = array();

		foreach ( $post_ids as $post_id ) {
			foreach ( $calendar as $entry ) {
				if ( self::match_entry( $post_id, $entry ) ) {
					if ( ! self::is_date_in_window( $entry['start'], $entry['end'] ) ) {
						$excluded[ $post_id ] = $entry['label'];
					}
					break;
				}
			}
		}

		return $excluded;
	}

	/**
	 * post_id => 1.0 if it matches a currently in-window seasonal entry, else
	 * 0.5 (neutral — non-seasonal content is never penalized for "not being
	 * seasonal"). Posts out of season never reach this: they are excluded
	 * earlier in BMFR_Selector.
	 */
	public static function get_fit_scores( array $post_ids ) {
		$calendar = self::get_calendar();
		$scores   = array();

		foreach ( $post_ids as $post_id ) {
			$scores[ $post_id ] = 0.5;

			foreach ( $calendar as $entry ) {
				if ( self::match_entry( $post_id, $entry ) && self::is_date_in_window( $entry['start'], $entry['end'] ) ) {
					$scores[ $post_id ] = 1.0;
					break;
				}
			}
		}

		return $scores;
	}
}
