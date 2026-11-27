<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMFR_Exclusions_Page {

	public static function render() {
		if ( ! current_user_can( BMFR_Admin::VIEW_CAP ) ) {
			wp_die( esc_html__( 'Non hai i permessi per accedere a questa pagina.', 'featured-post-rotator' ) );
		}

		$global_exclusions = BMFR_Settings::get_global_exclusions();
		$all_categories    = get_categories( array( 'hide_empty' => false ) );

		$excluded_post_objects     = array_filter( array_map( 'get_post', $global_exclusions['excluded_post_ids'] ) );
		$excluded_category_objects = array_filter(
			array_map(
				static function ( $term_id ) {
					$term = get_term( $term_id, 'category' );
					return ( $term && ! is_wp_error( $term ) ) ? $term : null;
				},
				$global_exclusions['excluded_categories']
			)
		);

		include BMFR_PLUGIN_DIR . 'admin/views/exclusions-page.php';
	}
}
