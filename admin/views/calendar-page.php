<?php
/**
 * @var array $calendar
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blank_rows = 3;
$total_rows = count( $calendar ) + $blank_rows;
?>
<div class="wrap bmfr-wrap">
	<h1><?php esc_html_e( 'Post Rotator — Seasonal calendar', 'featured-post-rotator' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'If a post matches an entry (by keyword in the title or by tag) and today\'s date is outside the given window, the post is automatically excluded from all slots. If today\'s date is inside the window, the post instead gets an "on topic" bonus in its score — the weight of this bonus is set for each slot on the Settings page.', 'featured-post-rotator' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="bmfr_save_calendar" />
		<?php wp_nonce_field( 'bmfr_save_calendar' ); ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Title keywords (comma-separated)', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Tags (slugs, comma-separated)', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Start (MM-DD)', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'End (MM-DD)', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Active', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Delete', 'featured-post-rotator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php for ( $i = 0; $i < $total_rows; $i++ ) : ?>
					<?php $entry = isset( $calendar[ $i ] ) ? $calendar[ $i ] : array( 'label' => '', 'keywords' => array(), 'tag_slugs' => array(), 'start' => '', 'end' => '', 'enabled' => true ); ?>
					<tr>
						<td><input type="text" name="bmfr_calendar[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $entry['label'] ); ?>" class="regular-text" <?php echo empty( $entry['label'] ) ? 'placeholder="' . esc_attr__( 'New entry', 'featured-post-rotator' ) . '"' : ''; ?> /></td>
						<td><input type="text" name="bmfr_calendar[<?php echo (int) $i; ?>][keywords]" value="<?php echo esc_attr( implode( ', ', (array) $entry['keywords'] ) ); ?>" class="regular-text" /></td>
						<td><input type="text" name="bmfr_calendar[<?php echo (int) $i; ?>][tag_slugs]" value="<?php echo esc_attr( implode( ', ', (array) $entry['tag_slugs'] ) ); ?>" class="regular-text" /></td>
						<td><input type="text" name="bmfr_calendar[<?php echo (int) $i; ?>][start]" value="<?php echo esc_attr( $entry['start'] ); ?>" placeholder="MM-GG" class="small-text" /></td>
						<td><input type="text" name="bmfr_calendar[<?php echo (int) $i; ?>][end]" value="<?php echo esc_attr( $entry['end'] ); ?>" placeholder="MM-GG" class="small-text" /></td>
						<td><input type="checkbox" name="bmfr_calendar[<?php echo (int) $i; ?>][enabled]" value="1" <?php checked( ! empty( $entry['enabled'] ) ); ?> /></td>
						<td><?php if ( isset( $calendar[ $i ] ) ) : ?><input type="checkbox" name="bmfr_calendar[<?php echo (int) $i; ?>][delete]" value="1" /><?php endif; ?></td>
					</tr>
				<?php endfor; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Empty rows (without a label) are ignored: use them to add new entries.', 'featured-post-rotator' ); ?></p>

		<?php submit_button( __( 'Save calendar', 'featured-post-rotator' ) ); ?>
	</form>
</div>
