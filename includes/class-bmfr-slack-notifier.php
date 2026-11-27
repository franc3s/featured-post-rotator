<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Posts the "rotation is coming up" warning to a Slack Incoming Webhook,
 * ahead of the actual rotation — purely informational, no action links, so
 * the only way to change what will happen is to go edit it in wp-admin
 * before the due time passes. Separate from BMFR_Notifier, which only
 * reports what already happened, by email, after a real run.
 *
 * The panel of candidates (score, past-rotation count, link) is computed
 * from a dry run taken at send time, same pipeline as the admin Preview
 * page — it is therefore a snapshot, not a guarantee: the weighted sampling
 * has some randomness and the eligible pool can still shift before the real
 * due time, so the actual rotation may pick slightly differently. Good
 * enough for "here's what it looks like right now, go check if you want to
 * change something."
 */
class BMFR_Slack_Notifier {

	public static function send_pre_rotation_notice( $slot_key, $due_at ) {
		$slot        = BMFR_Slots::get_slot( $slot_key );
		$slot_label  = $slot ? $slot['label'] : $slot_key;
		$preview_url = admin_url( 'admin.php?page=bmfr-preview&slot=' . rawurlencode( $slot_key ) );

		$preview = BMFR_Rotator::run( $slot_key, 'cron', true );
		$preview = is_wp_error( $preview ) ? null : $preview;

		$fallback_text = sprintf(
			/* translators: 1: blog title, 2: slot label, 3: formatted date/time the rotation will run */
			__( '%1$s — :bell: The rotation of %2$s is scheduled for %3$s, unless you step in first.', 'featured-post-rotator' ),
			get_bloginfo( 'name' ),
			$slot_label,
			wp_date( 'd/m/Y H:i', $due_at )
		);

		$blocks = self::build_pre_rotation_blocks( $slot_key, $slot_label, $due_at, $preview_url, $preview );

		return self::post( $fallback_text, $blocks );
	}

	/**
	 * Fired from the Impostazioni page's "Invia notifica di prova" button, to
	 * let an admin confirm the webhook actually posts to the right channel
	 * without waiting for a real slot to enter its pre-rotation lead window.
	 */
	public static function send_test_message() {
		$text = sprintf(
			"*%1\$s*\n%2\$s",
			self::site_title(),
			__( ':white_check_mark: Test successful: the Featured Post Rotator webhook is correctly connected to this channel.', 'featured-post-rotator' )
		);

		return self::post( $text );
	}

	/**
	 * Which blog this message is about, bolded as the first line of every
	 * message this class sends — useful once a shared channel also carries
	 * notices from other sites/sources.
	 */
	private static function site_title() {
		return self::escape( get_bloginfo( 'name' ) );
	}

	private static function build_pre_rotation_blocks( $slot_key, $slot_label, $due_at, $preview_url, $preview ) {
		$blocks = array(
			array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => '*' . self::site_title() . '*',
				),
			),
			array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => sprintf(
						/* translators: 1: slot label, 2: formatted date/time the rotation will run */
						__( ':bell: The rotation of *%1$s* is scheduled for *%2$s*, unless you step in first.', 'featured-post-rotator' ),
						self::escape( $slot_label ),
						wp_date( 'd/m/Y H:i', $due_at )
					),
				),
			),
		);

		if ( $preview ) {
			$history_counts = BMFR_History::get_in_counts_for_posts(
				$slot_key,
				array_merge( $preview['to_add'], $preview['to_remove'] )
			);

			if ( ! empty( $preview['to_add'] ) ) {
				$blocks[] = array( 'type' => 'divider' );
				$blocks[] = array(
					'type' => 'section',
					'text' => array( 'type' => 'mrkdwn', 'text' => __( '*Coming in*', 'featured-post-rotator' ) ),
				);

				foreach ( $preview['to_add'] as $post_id ) {
					$blocks[] = self::build_candidate_block( $post_id, $preview['scored'], $history_counts );
				}
			}

			if ( ! empty( $preview['to_remove'] ) ) {
				$blocks[] = array( 'type' => 'divider' );
				$lines    = array( __( '*Going out*', 'featured-post-rotator' ) );

				foreach ( $preview['to_remove'] as $post_id ) {
					$lines[] = '• ' . self::format_post_link( $post_id );
				}

				$blocks[] = array(
					'type' => 'section',
					'text' => array( 'type' => 'mrkdwn', 'text' => implode( "\n", $lines ) ),
				);
			}
		}

		$blocks[] = array( 'type' => 'divider' );
		$blocks[] = array(
			'type' => 'section',
			'text' => array(
				'type' => 'mrkdwn',
				'text' => sprintf(
					/* translators: %s: URL of the admin preview page for this slot */
					__( 'To exclude a candidate or review who will come in: <%s|Open Preview>', 'featured-post-rotator' ),
					esc_url( $preview_url )
				),
			),
		);

		return $blocks;
	}

	/**
	 * One "row" of the entering-candidates panel: post title (linked to the
	 * published article) alongside its final score and how many times it has
	 * already rotated into this slot before, side by side as Block Kit
	 * fields — the closest Incoming Webhooks get to a small table.
	 */
	private static function build_candidate_block( $post_id, array $scored, array $history_counts ) {
		$score = isset( $scored[ $post_id ] ) ? round( (float) $scored[ $post_id ]['final'], 3 ) : null;
		$count = isset( $history_counts[ $post_id ] ) ? $history_counts[ $post_id ] : 0;

		return array(
			'type'   => 'section',
			'fields' => array(
				array(
					'type' => 'mrkdwn',
					'text' => self::format_post_link( $post_id ),
				),
				array(
					'type' => 'mrkdwn',
					'text' => sprintf(
						/* translators: 1: final score, 2: number of times this post has previously rotated into this slot */
						__( 'Score: *%1$s* · Previous rotations: *%2$d*', 'featured-post-rotator' ),
						null !== $score ? $score : '—',
						$count
					),
				),
			),
		);
	}

	private static function format_post_link( $post_id ) {
		$title = self::escape( get_the_title( $post_id ) );
		$url   = get_permalink( $post_id );

		return $url ? '<' . esc_url( $url ) . '|' . $title . '>' : $title;
	}

	/**
	 * Slack's own mrkdwn escaping rule (not HTML escaping): only these three
	 * characters are special in a text/mrkdwn field, since & < > would
	 * otherwise collide with Slack's own entity and link syntax.
	 */
	private static function escape( $text ) {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), (string) $text );
	}

	private static function post( $text, array $blocks = array() ) {
		$settings = BMFR_Settings::get_slack_settings();

		if ( empty( $settings['webhook_url'] ) ) {
			return false;
		}

		$body = array( 'text' => $text );

		if ( ! empty( $blocks ) ) {
			$body['blocks'] = $blocks;
		}

		$response = wp_remote_post(
			$settings['webhook_url'],
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
	}
}
