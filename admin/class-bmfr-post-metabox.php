<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sidebar meta box on the Post edit screen: lets an editor exclude the post
 * itself, and/or any of its categories, from the rotation directly while
 * writing — without needing to go find it by title in Impostazioni. Writes
 * to the same global exclusion lists used everywhere else, then runs
 * BMFR_Rotator::enforce_exclusions() so a change takes effect immediately.
 */
class BMFR_Post_Metabox {

	const NONCE_ACTION = 'bmfr_post_exclusions';
	const NONCE_FIELD   = 'bmfr_post_exclusions_nonce';

	public static function init() {
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save' ) );
	}

	public static function add_metabox() {
		if ( ! current_user_can( BMFR_Admin::WRITE_CAP ) ) {
			return;
		}

		add_meta_box(
			'bmfr_exclusions',
			__( 'Rotazione Articoli — Esclusioni', 'featured-post-rotator' ),
			array( __CLASS__, 'render' ),
			'post',
			'side',
			'default'
		);
	}

	public static function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$global        = BMFR_Settings::get_global_exclusions();
		$post_excluded = in_array( (int) $post->ID, array_map( 'absint', (array) $global['excluded_post_ids'] ), true );
		$categories    = get_the_category( $post->ID );
		?>
		<p>
			<label>
				<input type="checkbox" name="bmfr_exclude_post" value="1" <?php checked( $post_excluded ); ?> />
				<?php esc_html_e( 'Escludi questo post dalle rotazioni (Trend Posts / Spotlight)', 'featured-post-rotator' ); ?>
			</label>
		</p>
		<?php if ( ! empty( $categories ) ) : ?>
			<p><strong><?php esc_html_e( 'Escludi intere categorie ovunque:', 'featured-post-rotator' ); ?></strong></p>
			<?php foreach ( $categories as $category ) : ?>
				<?php $cat_excluded = in_array( (int) $category->term_id, array_map( 'absint', (array) $global['excluded_categories'] ), true ); ?>
				<p style="margin:4px 0;">
					<label>
						<input type="checkbox" name="bmfr_exclude_categories[]" value="<?php echo esc_attr( $category->term_id ); ?>" <?php checked( $cat_excluded ); ?> />
						<?php
						printf(
							/* translators: %s: category name */
							esc_html__( '"%s" — incide su tutti i post della categoria, non solo su questo', 'featured-post-rotator' ),
							esc_html( $category->name )
						);
						?>
					</label>
				</p>
			<?php endforeach; ?>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Nessuna categoria assegnata.', 'featured-post-rotator' ); ?></p>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Un post o una categoria esclusi non entrano più nelle rotazioni; se già in evidenza vengono rimossi e sostituiti subito al salvataggio.', 'featured-post-rotator' ); ?></p>
		<?php
	}

	public static function save( $post_id ) {
		if (
			! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( BMFR_Admin::WRITE_CAP ) ) {
			return;
		}

		if ( ! empty( $_POST['bmfr_exclude_post'] ) ) {
			BMFR_Settings::add_global_excluded_post( $post_id );
		} else {
			BMFR_Settings::remove_global_excluded_post( $post_id );
		}

		$submitted_categories = isset( $_POST['bmfr_exclude_categories'] ) && is_array( $_POST['bmfr_exclude_categories'] )
			? array_map( 'absint', wp_unslash( $_POST['bmfr_exclude_categories'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();

		// Only ever act on THIS post's own categories — the ones actually
		// offered as checkboxes — so (un)ticking never touches the exclusion
		// state of a category this post doesn't even belong to.
		$post_category_ids = wp_list_pluck( get_the_category( $post_id ), 'term_id' );

		foreach ( $post_category_ids as $category_id ) {
			$category_id = (int) $category_id;

			if ( in_array( $category_id, $submitted_categories, true ) ) {
				BMFR_Settings::add_global_excluded_category( $category_id );
			} else {
				BMFR_Settings::remove_global_excluded_category( $category_id );
			}
		}

		BMFR_Rotator::enforce_exclusions();
		BMFR_Admin::invalidate_preview_cache();
	}
}
