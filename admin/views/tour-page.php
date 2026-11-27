<?php
/**
 * @var array  $page_candidates  array of array( 'post_id', 'slot_keys', 'global_exclusion', 'cycles' )
 *                               — see BMFR_Tour_Page::attach_cycle_view_data().
 * @var array  $tab_counts       array( BMFR_Tour_Page::TAB_* => int )
 * @var string $current_tab      one of BMFR_Tour_Page::TAB_*
 * @var int    $current_page
 * @var int    $total_pages
 * @var bool   $is_welcome
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bmfr_tabs = array(
	BMFR_Tour_Page::TAB_REVIEW   => __( 'To review', 'featured-post-rotator' ),
	BMFR_Tour_Page::TAB_KEPT     => __( 'Candidates', 'featured-post-rotator' ),
	BMFR_Tour_Page::TAB_EXCLUDED => __( 'Excluded', 'featured-post-rotator' ),
);

$bmfr_empty_messages = array(
	BMFR_Tour_Page::TAB_REVIEW   => __( 'No posts to review: you have gone through every possible candidate — great job!', 'featured-post-rotator' ),
	BMFR_Tour_Page::TAB_KEPT     => __( 'No posts confirmed with "Keep" yet.', 'featured-post-rotator' ),
	BMFR_Tour_Page::TAB_EXCLUDED => __( 'No posts excluded so far.', 'featured-post-rotator' ),
);
?>
<div class="wrap bmfr-wrap">
	<h1><?php esc_html_e( 'Post Rotator — Candidate tour', 'featured-post-rotator' ); ?></h1>

	<?php if ( $is_welcome ) : ?>
		<div class="bmfr-card bmfr-tour-welcome">
			<h2><?php esc_html_e( 'Welcome to Post Rotator', 'featured-post-rotator' ); ?></h2>
			<p><?php esc_html_e( 'Before turning on automatic rotation, work through this list until it is empty: for each cycle a post belongs to, mark "Keep" (it is fine) or "Exclude" (it must never appear there). It is a one-off — once all of a post\'s cycles are decided, the row moves to the matching tab and never comes back to the ones to review.', 'featured-post-rotator' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=bmfr-settings' ) ); ?>"><?php esc_html_e( 'I\'m done, go to Settings', 'featured-post-rotator' ); ?></a>
			</p>
		</div>
	<?php else : ?>
		<p class="bmfr-lede"><?php esc_html_e( 'All posts that fall within the age window configured for Trend Posts and/or Spotlight, regardless of seasonality, cooldown or current assignment to a cycle: the complete list, not just what can be picked right now. Decide "Keep" or "Exclude" for each cycle — a post can be right for one and not for the other — to keep future interventions to a minimum.', 'featured-post-rotator' ); ?></p>
	<?php endif; ?>

	<ul class="subsubsub">
		<?php
		$bmfr_tab_items = array();

		foreach ( $bmfr_tabs as $tab_key => $tab_label ) {
			$url   = add_query_arg(
				array(
					'page'     => 'bmfr-tour',
					'tour_tab' => $tab_key,
				),
				admin_url( 'admin.php' )
			);
			$class = ( $tab_key === $current_tab ) ? ' class="current"' : '';
			$count = ( $tab_key === $current_tab )
				? '<span class="count">(<span data-tour-remaining="' . (int) $tab_counts[ $tab_key ] . '">' . (int) $tab_counts[ $tab_key ] . '</span>)</span>'
				: '<span class="count">(' . (int) $tab_counts[ $tab_key ] . ')</span>';

			$bmfr_tab_items[] = '<li><a href="' . esc_url( $url ) . '"' . $class . '>' . esc_html( $tab_label ) . ' ' . $count . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $count built entirely from (int) casts above.
		}

		echo implode( ' | ', $bmfr_tab_items ); // phpcs:ignore WordPress.Security.EscapeOutput -- built entirely from esc_url/esc_html/(int) above.
		?>
	</ul>
	<br class="clear" />

	<div class="bmfr-card">
		<table class="widefat striped bmfr-tour-table" data-empty-text="<?php echo esc_attr( $bmfr_empty_messages[ $current_tab ] ); ?>">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Image', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Title', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Cycles', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Summary', 'featured-post-rotator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $page_candidates ) ) : ?>
					<tr><td colspan="4"><?php echo esc_html( $bmfr_empty_messages[ $current_tab ] ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $page_candidates as $row ) : ?>
					<?php
					$post_id  = $row['post_id'];
					$thumb_id = get_post_thumbnail_id( $post_id );
					$excerpt  = wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post_id ) ), 24, '…' );

					$open_cycle_count = 0;
					if ( empty( $row['global_exclusion'] ) ) {
						foreach ( $row['cycles'] as $cycle ) {
							if ( 'open' === $cycle['decision'] ) {
								++$open_cycle_count;
							}
						}
					}
					?>
					<tr id="bmfr-tour-row-<?php echo esc_attr( $post_id ); ?>">
						<td class="bmfr-tour-thumb-cell">
							<?php if ( $thumb_id ) : ?>
								<?php echo wp_get_attachment_image( $thumb_id, array( 60, 60 ), false, array( 'class' => 'bmfr-tour-thumb', 'alt' => '' ) ); ?>
							<?php else : ?>
								<span class="bmfr-tour-thumb-placeholder" aria-hidden="true"><span class="dashicons dashicons-format-image"></span></span>
							<?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><strong><?php echo esc_html( get_the_title( $post_id ) ); ?></strong></a><br />
							<span class="bmfr-tour-meta"><?php echo esc_html( get_the_date( '', $post_id ) ); ?></span>
						</td>
						<td class="bmfr-tour-cicli-cell">
							<?php if ( ! empty( $row['global_exclusion'] ) ) : ?>
								<div class="bmfr-cycle-stack">
									<?php foreach ( $row['cycles'] as $cycle ) : ?>
										<span class="bmfr-pill bmfr-pill-slot"><?php echo esc_html( $cycle['label'] ); ?></span>
									<?php endforeach; ?>
								</div>
								<div class="bmfr-apply-all">
									<?php echo BMFR_Tour_Page::render_action_form( 'bmfr_remove_global_exclusion', 'bmfr_tour_unexclude_post', __( 'Readmit', 'featured-post-rotator' ), $post_id, array( 'exclusion_type' => 'post' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup from render_action_form(). ?>
								</div>
							<?php else : ?>
								<div class="bmfr-cycle-stack">
									<?php foreach ( $row['cycles'] as $cycle ) : ?>
										<?php echo BMFR_Tour_Page::render_cycle_card( $post_id, $cycle, $current_tab ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup from render_cycle_card(). ?>
									<?php endforeach; ?>
								</div>
								<?php if ( $open_cycle_count > 1 ) : ?>
									<div class="bmfr-apply-all">
										<?php echo BMFR_Tour_Page::render_action_form( 'bmfr_tour_keep_post', 'bmfr_tour_keep_post', __( 'Keep all', 'featured-post-rotator' ), $post_id, array( 'current_tab' => $current_tab ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup from render_action_form(). ?>
										<?php echo BMFR_Tour_Page::render_action_form( 'bmfr_tour_exclude_all_open_cycles', 'bmfr_tour_exclude_all_open_cycles', __( 'Exclude all', 'featured-post-rotator' ), $post_id, array( 'current_tab' => $current_tab ), 'button button-small', __( 'Exclude this post from all cycles not yet decided? If it is already featured in one of those cycles, it is removed immediately.', 'featured-post-rotator' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup from render_action_form(). ?>
									</div>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td class="bmfr-tour-excerpt"><?php echo esc_html( $excerpt ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput -- paginate_links() escapes its own output.
						array(
							'base'      => add_query_arg(
								array(
									'tour_tab'  => $current_tab,
									'tour_page' => '%#%',
								)
							),
							'format'    => '',
							'current'   => $current_page,
							'total'     => $total_pages,
							'prev_text' => esc_html__( '« Previous', 'featured-post-rotator' ),
							'next_text' => esc_html__( 'Next »', 'featured-post-rotator' ),
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>
