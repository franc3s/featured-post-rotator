<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Paginated, slot-filterable viewer for the rotation history table. Using
 * WP_List_Table buys pagination/consistent admin styling for a handful of
 * boilerplate methods, cheaper than a hand-rolled HTML table.
 */
class BMFR_History_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'rotation_event',
				'plural'   => 'rotation_events',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'created_at'     => __( 'Data', 'featured-post-rotator' ),
			'slot'           => __( 'Slot', 'featured-post-rotator' ),
			'post'           => __( 'Post', 'featured-post-rotator' ),
			'action'         => __( 'Azione', 'featured-post-rotator' ),
			'score'          => __( 'Punteggio', 'featured-post-rotator' ),
			'trigger_source' => __( 'Trigger', 'featured-post-rotator' ),
			'breakdown'      => __( 'Dettaglio', 'featured-post-rotator' ),
		);
	}

	public function prepare_items() {
		$per_page    = 20;
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$slot_filter = isset( $_GET['slot'] ) ? sanitize_key( wp_unslash( $_GET['slot'] ) ) : '';

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$result = BMFR_History::get_history(
			array(
				'slot'     => $slot_filter,
				'per_page' => $per_page,
				'paged'    => $paged,
			)
		);

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'created_at':
				// Stored in UTC (since v1.19.1), shown in the site's time zone.
				return esc_html( get_date_from_gmt( $item->created_at ) );

			case 'slot':
				$slot = BMFR_Slots::get_slot( $item->slot );
				return esc_html( $slot ? $slot['label'] : $item->slot );

			case 'post':
				if ( 0 === (int) $item->post_id ) {
					return '—';
				}
				$title = get_the_title( $item->post_id );
				$link  = get_edit_post_link( $item->post_id );
				return $link ? '<a href="' . $link . '">' . esc_html( $title ) . '</a>' : esc_html( $title );

			case 'action':
				$labels = array(
					'in'      => __( 'Entrato', 'featured-post-rotator' ),
					'out'     => __( 'Uscito', 'featured-post-rotator' ),
					'warning' => __( 'Avviso', 'featured-post-rotator' ),
				);
				return isset( $labels[ $item->action ] ) ? esc_html( $labels[ $item->action ] ) : esc_html( $item->action );

			case 'score':
				return null !== $item->score ? esc_html( round( (float) $item->score, 3 ) ) : '—';

			case 'trigger_source':
				return esc_html( $item->trigger_source );

			case 'breakdown':
				if ( empty( $item->score_breakdown ) ) {
					return '—';
				}
				return '<details><summary>' . esc_html__( 'JSON', 'featured-post-rotator' ) . '</summary><pre>' . esc_html( $item->score_breakdown ) . '</pre></details>';

			default:
				return '—';
		}
	}
}
