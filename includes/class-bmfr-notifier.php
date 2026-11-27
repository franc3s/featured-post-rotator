<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composes and sends the per-rotation summary email. Only called after a
 * real (non-dry-run) run that actually changed something.
 */
class BMFR_Notifier {

	public static function send_rotation_summary( $slot_key, array $to_add, array $to_remove, array $scored, $trigger ) {
		$settings   = BMFR_Settings::get_notification_settings();
		$recipients = $settings['recipients'];

		if ( empty( $recipients ) ) {
			return false;
		}

		$slot          = BMFR_Slots::get_slot( $slot_key );
		$slot_label    = $slot ? $slot['label'] : $slot_key;
		$total_changes = count( $to_add ) + count( $to_remove );

		$subject = sprintf(
			/* translators: 1: site/brand name (Impostazioni, defaults to the site title), 2: slot label, 3: number of changes */
			__( '[%1$s] Rotation "%2$s" — %3$d changes', 'featured-post-rotator' ),
			BMFR_Settings::get_email_subject_prefix(),
			$slot_label,
			$total_changes
		);

		$body  = '<p>' . sprintf(
			/* translators: 1: slot label, 2: trigger (cron/manual) */
			esc_html__( 'Rotation completed for %1$s (trigger: %2$s).', 'featured-post-rotator' ),
			'<strong>' . esc_html( $slot_label ) . '</strong>',
			esc_html( $trigger )
		) . '</p>';

		$body .= '<p><strong>' . esc_html__( 'Added', 'featured-post-rotator' ) . ':</strong></p>';
		$body .= self::render_post_list( $to_add, $scored );

		$body .= '<p><strong>' . esc_html__( 'Removed', 'featured-post-rotator' ) . ':</strong></p>';
		$body .= self::render_post_list( $to_remove, $scored );

		$history_url = admin_url( 'admin.php?page=bmfr-history&slot=' . rawurlencode( $slot_key ) );
		$body       .= '<p><a href="' . esc_url( $history_url ) . '">' . esc_html__( 'See the full history', 'featured-post-rotator' ) . '</a></p>';

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );
		wp_mail( $recipients, $subject, $body );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );

		return true;
	}

	public static function set_html_content_type() {
		return 'text/html';
	}

	private static function render_post_list( array $post_ids, array $scored ) {
		if ( empty( $post_ids ) ) {
			return '<p><em>' . esc_html__( 'None', 'featured-post-rotator' ) . '</em></p>';
		}

		$html = '<ul>';

		foreach ( $post_ids as $post_id ) {
			$title = get_the_title( $post_id );
			// Built by hand: get_edit_post_link() returns null with no logged-in
			// user, which is always the case in a cron-triggered rotation.
			$link  = esc_url( admin_url( 'post.php?post=' . absint( $post_id ) . '&action=edit' ) );
			$score = isset( $scored[ $post_id ] ) ? round( (float) $scored[ $post_id ]['final'], 3 ) : null;

			$html .= '<li>' . esc_html( $title );

			if ( null !== $score ) {
				$html .= ' (' . esc_html__( 'score', 'featured-post-rotator' ) . ': ' . esc_html( $score ) . ')';
			}

			if ( $link ) {
				$html .= ' — <a href="' . $link . '">' . esc_html__( 'edit', 'featured-post-rotator' ) . '</a>';
			}

			$html .= '</li>';
		}

		$html .= '</ul>';

		return $html;
	}
}
