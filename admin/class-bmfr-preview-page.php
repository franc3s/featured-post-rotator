<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMFR_Preview_Page {

	public static function render() {
		if ( ! current_user_can( BMFR_Admin::VIEW_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'featured-post-rotator' ) );
		}

		$slots = BMFR_Slots::get_slots();

		$requested_slot = isset( $_GET['slot'] ) ? sanitize_key( wp_unslash( $_GET['slot'] ) ) : '';
		$current_slot    = BMFR_Slots::get_slot( $requested_slot ) ? $requested_slot : BMFR_Slots::get_default_slot_key();

		$preview = get_transient( 'bmfr_dry_run_' . get_current_user_id() );
		if ( ! is_array( $preview ) || $preview['slot'] !== $current_slot ) {
			$preview = self::compute_and_cache_preview( $current_slot );
		}

		$currently_tagged = BMFR_Rotator::get_currently_tagged_post_ids( $current_slot );
		$next_run          = BMFR_Cron::get_next_run_estimate( $current_slot );

		include BMFR_PLUGIN_DIR . 'admin/views/preview-page.php';
	}

	/**
	 * Computes a fresh dry-run so the forecast for the next rotation is on
	 * screen immediately, with no "Calcola anteprima" click required. A
	 * click on "Ricalcola" (or an exclusion made from this page) still
	 * recomputes on top of this, since BMFR_Selector::weighted_sample()'s
	 * random draw isn't otherwise stable between calls — caching it for a
	 * few minutes is what keeps the forecast from reshuffling on every
	 * plain page reload.
	 */
	private static function compute_and_cache_preview( $slot_key ) {
		$result = BMFR_Rotator::run( $slot_key, 'manual', true );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		set_transient( 'bmfr_dry_run_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}
}
