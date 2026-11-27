<?php
/**
 * @var array $all_categories
 * @var WP_Post[] $excluded_post_objects
 * @var WP_Term[] $excluded_category_objects
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap bmfr-wrap">
	<h1><?php esc_html_e( 'Post Rotator — Exclusions', 'featured-post-rotator' ); ?></h1>

	<div class="bmfr-card" id="bmfr-global-exclusions">
		<h2><?php esc_html_e( 'Global exclusions', 'featured-post-rotator' ); ?></h2>
		<p class="description"><?php esc_html_e( 'These add to each slot\'s own exclusions (set on the Settings tab): a post or category excluded here will never be a candidate in either Trend Posts or Spotlight.', 'featured-post-rotator' ); ?></p>

		<div class="bmfr-exclusion-adders">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bmfr-post-search">
				<input type="hidden" name="action" value="bmfr_add_global_exclusion" />
				<input type="hidden" name="exclusion_type" value="post" />
				<?php wp_nonce_field( 'bmfr_exclusion_action' ); ?>
				<label for="bmfr-post-search-input"><?php esc_html_e( 'Search post by title', 'featured-post-rotator' ); ?></label><br />
				<input type="text" id="bmfr-post-search-input" class="bmfr-post-search-input regular-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Type at least 2 characters…', 'featured-post-rotator' ); ?>" />
				<input type="hidden" name="exclusion_id" class="bmfr-post-search-id" value="" />
				<ul class="bmfr-post-search-results" hidden></ul>
				<button type="submit" class="button bmfr-post-search-submit" disabled><?php esc_html_e( 'Exclude this post everywhere', 'featured-post-rotator' ); ?></button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bmfr_add_global_exclusion" />
				<input type="hidden" name="exclusion_type" value="category" />
				<?php wp_nonce_field( 'bmfr_exclusion_action' ); ?>
				<label for="bmfr-global-add-category"><?php esc_html_e( 'Exclude a category everywhere', 'featured-post-rotator' ); ?></label><br />
				<select id="bmfr-global-add-category" name="exclusion_id">
					<?php foreach ( $all_categories as $category ) : ?>
						<option value="<?php echo esc_attr( $category->term_id ); ?>"><?php echo esc_html( $category->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Exclude category', 'featured-post-rotator' ); ?></button>
			</form>
		</div>

		<table class="widefat striped bmfr-exclusion-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Posts excluded everywhere', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Category', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Published on', 'featured-post-rotator' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $excluded_post_objects ) ) : ?>
					<tr><td colspan="4">—</td></tr>
				<?php endif; ?>
				<?php foreach ( $excluded_post_objects as $excluded_post ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $excluded_post->ID ) ); ?>"><?php echo esc_html( get_the_title( $excluded_post ) ); ?></a></td>
						<td><?php echo esc_html( wp_strip_all_tags( get_the_category_list( ', ', '', $excluded_post->ID ) ) ); ?></td>
						<td><?php echo esc_html( get_the_date( '', $excluded_post ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="bmfr_remove_global_exclusion" />
								<input type="hidden" name="exclusion_type" value="post" />
								<input type="hidden" name="exclusion_id" value="<?php echo esc_attr( $excluded_post->ID ); ?>" />
								<?php wp_nonce_field( 'bmfr_exclusion_action' ); ?>
								<button type="submit" class="button-link-delete"><?php esc_html_e( 'Remove', 'featured-post-rotator' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<table class="widefat striped bmfr-exclusion-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Categories excluded everywhere', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'No. of posts', 'featured-post-rotator' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $excluded_category_objects ) ) : ?>
					<tr><td colspan="3">—</td></tr>
				<?php endif; ?>
				<?php foreach ( $excluded_category_objects as $excluded_term ) : ?>
					<tr>
						<td><?php echo esc_html( $excluded_term->name ); ?></td>
						<td><?php echo esc_html( $excluded_term->count ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="bmfr_remove_global_exclusion" />
								<input type="hidden" name="exclusion_type" value="category" />
								<input type="hidden" name="exclusion_id" value="<?php echo esc_attr( $excluded_term->term_id ); ?>" />
								<?php wp_nonce_field( 'bmfr_exclusion_action' ); ?>
								<button type="submit" class="button-link-delete"><?php esc_html_e( 'Remove', 'featured-post-rotator' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
