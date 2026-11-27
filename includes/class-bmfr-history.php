<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * $wpdb access layer for the rotation history table. Only "in"/"out"/
 * "warning" events are ever written here (unchanged posts are a deliberate
 * no-op elsewhere), so this table is signal-only.
 */
class BMFR_History {

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'bmfr_rotation_history';
	}

	/**
	 * $post_id may be 0 for a "warning" row that isn't tied to a specific post
	 * (e.g. "pool too small"). There is no FK constraint on this log table.
	 */
	public static function log_event( $slot_key, $run_id, $post_id, $action, $score, array $breakdown, $category_id, $trigger ) {
		global $wpdb;

		$wpdb->insert(
			self::table_name(),
			array(
				'run_id'          => $run_id,
				'slot'            => $slot_key,
				'post_id'         => $post_id,
				'action'          => $action,
				'score'           => $score,
				'score_breakdown' => wp_json_encode( $breakdown ),
				'category_id'     => $category_id ? $category_id : null,
				'trigger_source'  => $trigger,
				'created_at'      => current_time( 'mysql', true ), // UTC since v1.19.1 (DB 1.1.0), same clock as the gmdate() cutoffs below.
			),
			array( '%s', '%s', '%d', '%s', '%f', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Returns post_id => last "in" timestamp (mysql datetime string) for posts
	 * that entered this slot within the last $cooldown_days, ordered oldest
	 * first — used both as the exclusion set and, when the eligible pool is
	 * too small, as the re-admission order for the safety valve.
	 */
	public static function get_posts_in_cooldown( $slot_key, $cooldown_days ) {
		global $wpdb;

		if ( $cooldown_days <= 0 ) {
			return array();
		}

		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $cooldown_days * DAY_IN_SECONDS ) );

		$sql = $wpdb->prepare(
			"SELECT post_id, MAX(created_at) as last_in FROM {$table}
			 WHERE slot = %s AND action = 'in' AND created_at >= %s
			 GROUP BY post_id
			 ORDER BY last_in ASC",
			$slot_key,
			$cutoff
		);

		$rows   = $wpdb->get_results( $sql );
		$result = array();

		foreach ( $rows as $row ) {
			$result[ (int) $row->post_id ] = $row->last_in;
		}

		return $result;
	}

	/**
	 * How many times each category entered this slot within $lookback_days —
	 * feeds the category-balance score (categories absent from history score
	 * highest, i.e. "due").
	 */
	public static function get_category_counts( $slot_key, $lookback_days ) {
		global $wpdb;

		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $lookback_days * DAY_IN_SECONDS ) );

		$sql = $wpdb->prepare(
			"SELECT category_id, COUNT(*) as cnt FROM {$table}
			 WHERE slot = %s AND action = 'in' AND created_at >= %s AND category_id IS NOT NULL
			 GROUP BY category_id",
			$slot_key,
			$cutoff
		);

		$rows   = $wpdb->get_results( $sql );
		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ (int) $row->category_id ] = (int) $row->cnt;
		}

		return $counts;
	}

	/**
	 * All-time count of "in" events per post, for this slot only — the
	 * "rotazioni precedenti" column in the Slack pre-rotation panel, so an
	 * editor can tell a long-standing favourite from a first-timer at a
	 * glance. Batched by post_id to avoid one query per candidate.
	 */
	public static function get_in_counts_for_posts( $slot_key, array $post_ids ) {
		global $wpdb;

		$post_ids = array_values( array_unique( array_map( 'absint', $post_ids ) ) );

		if ( empty( $post_ids ) ) {
			return array();
		}

		$table        = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$sql = $wpdb->prepare(
			"SELECT post_id, COUNT(*) as cnt FROM {$table}
			 WHERE slot = %s AND action = 'in' AND post_id IN ({$placeholders})
			 GROUP BY post_id",
			array_merge( array( $slot_key ), $post_ids )
		);

		$rows   = $wpdb->get_results( $sql );
		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ (int) $row->post_id ] = (int) $row->cnt;
		}

		return $counts;
	}

	public static function get_history( $args = array() ) {
		global $wpdb;
		$table = self::table_name();

		$args = wp_parse_args(
			$args,
			array(
				'slot'     => '',
				'per_page' => 20,
				'paged'    => 1,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		);

		$where  = 'WHERE 1=1';
		$params = array();

		if ( ! empty( $args['slot'] ) ) {
			$where   .= ' AND slot = %s';
			$params[] = $args['slot'];
		}

		$allowed_orderby = array( 'created_at', 'slot', 'action', 'score' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['paged'] - 1 ) * $per_page );

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$data_sql  = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		if ( ! empty( $params ) ) {
			$total        = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
			$data_params  = array_merge( $params, array( $per_page, $offset ) );
			$items        = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input, static WHERE 1=1.
			$items = $wpdb->get_results( $wpdb->prepare( $data_sql, $per_page, $offset ) );
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
