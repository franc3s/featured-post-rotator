<?php
/**
 * @var array $weight_labels array( signal_key => label )
 * @var array $slots         BMFR_Slots::get_slots()
 * @var array $slot_data     array( slot_key => BMFR_Priority_Page::get_ranking() result )
 * @var array $client_data   BMFR_Priority_Page::build_client_data() result, read by initPriorityBoard()
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$first_slot  = array_key_first( $slots );
$computed_at = 0;
foreach ( $slot_data as $ranking ) {
	if ( $ranking && ( ! $computed_at || $ranking['computed_at'] < $computed_at ) ) {
		$computed_at = $ranking['computed_at'];
	}
}
?>
<div class="wrap bmfr-wrap">
	<h1><?php esc_html_e( 'Priority and weights', 'featured-post-rotator' ); ?></h1>
	<p class="bmfr-lede">
		<?php esc_html_e( 'Above, the weights of the four signals for each slot. Below, all posts in the slot\'s window, ordered by score. Every change reorders the ranking immediately. A higher score means a higher chance of being picked at the next rotation, not a guarantee.', 'featured-post-rotator' ); ?>
	</p>

	<div class="bmfr-priority-grid">
		<?php
		foreach ( $slots as $slot_key => $slot ) :
			$ranking = $slot_data[ $slot_key ];

			if ( ! $ranking ) {
				continue;
			}

			$slot_weights  = BMFR_Settings::get_slot_settings( $slot_key )['weights'];
			$weight_shares = BMFR_Priority_Page::normalize_weights( $slot_weights );
			?>
			<section class="bmfr-card bmfr-priority-slot">
				<header class="bmfr-priority-slot-header">
					<h2><?php echo esc_html( $slot['label'] ); ?></h2>
					<span class="bmfr-priority-slot-meta">
						<?php
						printf(
							/* translators: 1: posts eligible now, 2: posts in the slot's age window */
							esc_html__( '%1$d eligible now out of %2$d in the window', 'featured-post-rotator' ),
							(int) $ranking['candidate_count'],
							(int) $ranking['window_count']
						);
						?>
					</span>
				</header>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bmfr-weight-editor" data-weight-editor data-slot="<?php echo esc_attr( $slot_key ); ?>">
					<input type="hidden" name="action" value="bmfr_save_weights" />
					<input type="hidden" name="slot" value="<?php echo esc_attr( $slot_key ); ?>" />
					<?php wp_nonce_field( 'bmfr_save_weights_' . $slot_key ); ?>

					<?php foreach ( $weight_labels as $weight_key => $weight_label ) : ?>
						<div class="bmfr-weight-row">
							<span class="bmfr-weight-dot" style="background:<?php echo esc_attr( BMFR_Priority_Page::SIGNAL_COLORS[ $weight_key ] ); ?>" aria-hidden="true"></span>
							<label class="bmfr-weight-row-label" for="bmfr-weight-<?php echo esc_attr( $slot_key . '-' . $weight_key ); ?>"><?php echo esc_html( $weight_label ); ?></label>
							<input
								type="range"
								id="bmfr-weight-<?php echo esc_attr( $slot_key . '-' . $weight_key ); ?>"
								class="bmfr-weight-range"
								min="0" max="1" step="0.05"
								value="<?php echo esc_attr( $slot_weights[ $weight_key ] ); ?>"
								name="bmfr_weights[<?php echo esc_attr( $weight_key ); ?>]"
								data-weight="<?php echo esc_attr( $weight_key ); ?>"
								style="accent-color:<?php echo esc_attr( BMFR_Priority_Page::SIGNAL_COLORS[ $weight_key ] ); ?>"
							/>
							<output class="bmfr-weight-pct" data-weight-editor-pct="<?php echo esc_attr( $weight_key ); ?>"><?php echo esc_html( round( $weight_shares[ $weight_key ] * 100 ) . '%' ); ?></output>
						</div>
					<?php endforeach; ?>

					<div class="bmfr-composition-bar bmfr-weight-preview-bar" aria-hidden="true">
						<?php foreach ( $weight_labels as $weight_key => $weight_label ) : ?>
							<span class="bmfr-composition-seg" data-weight-editor-seg="<?php echo esc_attr( $weight_key ); ?>" style="width:<?php echo esc_attr( round( $weight_shares[ $weight_key ] * 100, 2 ) ); ?>%;background:<?php echo esc_attr( BMFR_Priority_Page::SIGNAL_COLORS[ $weight_key ] ); ?>"></span>
						<?php endforeach; ?>
					</div>

					<p class="description"><?php esc_html_e( 'Weights normalize themselves: the bar shows the real proportion. Moving a slider updates the ranking below immediately, but the rotation uses the new weights only after you save.', 'featured-post-rotator' ); ?></p>

					<div class="bmfr-weight-actions">
						<?php submit_button( __( 'Save weights', 'featured-post-rotator' ), 'secondary', 'submit', false ); ?>
						<span class="bmfr-weight-dirty" data-weight-dirty hidden><?php esc_html_e( 'Unsaved changes', 'featured-post-rotator' ); ?></span>
					</div>
				</form>
			</section>
		<?php endforeach; ?>
	</div>

	<section class="bmfr-card bmfr-board" data-priority-board>
		<header class="bmfr-board-header">
			<h2><?php esc_html_e( 'Ranking and priority', 'featured-post-rotator' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bmfr-priority-recalc-form">
				<input type="hidden" name="action" value="bmfr_priority_recalculate" />
				<input type="hidden" name="slot" value="all" />
				<?php wp_nonce_field( 'bmfr_priority_recalculate_all' ); ?>
				<span class="bmfr-board-age">
					<?php
					printf(
						/* translators: %s: relative time since the candidate data was computed */
						esc_html__( 'Data calculated %s ago', 'featured-post-rotator' ),
						esc_html( human_time_diff( $computed_at ) )
					);
					?>
				</span>
				<button type="submit" class="button button-small"><?php esc_html_e( 'Recalculate', 'featured-post-rotator' ); ?></button>
			</form>
		</header>

		<div class="bmfr-board-legend">
			<span><b class="bmfr-legend-key bmfr-legend-shared"></b><?php esc_html_e( 'Shared: applies to both slots, up to ±0.30', 'featured-post-rotator' ); ?></span>
			<span><b class="bmfr-legend-key bmfr-legend-offset"></b><?php esc_html_e( 'This slot only: adjustment up to ±0.15', 'featured-post-rotator' ); ?></span>
			<span><?php esc_html_e( 'Drag a row by its handle to place it: the correction needed is calculated automatically.', 'featured-post-rotator' ); ?></span>
		</div>

		<div class="bmfr-board-toolbar">
			<span class="bmfr-board-seg" role="group" aria-label="<?php esc_attr_e( 'Sort by slot', 'featured-post-rotator' ); ?>">
				<?php foreach ( $slots as $slot_key => $slot ) : ?>
					<button type="button" class="button" data-board-view="<?php echo esc_attr( $slot_key ); ?>" aria-pressed="<?php echo $slot_key === $first_slot ? 'true' : 'false'; ?>">
						<?php
						printf(
							/* translators: 1: slot label, 2: number of featured positions */
							esc_html__( '%1$s · %2$d places', 'featured-post-rotator' ),
							esc_html( $slot['label'] ),
							(int) $slot['count']
						);
						?>
					</button>
				<?php endforeach; ?>
			</span>
			<label class="screen-reader-text" for="bmfr-board-search"><?php esc_html_e( 'Search by title', 'featured-post-rotator' ); ?></label>
			<input type="search" id="bmfr-board-search" class="bmfr-board-search" placeholder="<?php esc_attr_e( 'Search by title', 'featured-post-rotator' ); ?>" data-board-search />
			<label class="bmfr-board-check"><input type="checkbox" id="bmfr-board-only-priority" data-board-only-priority /> <?php esc_html_e( 'Only with priority', 'featured-post-rotator' ); ?></label>
			<label class="bmfr-board-check"><input type="checkbox" id="bmfr-board-show-out" data-board-show-out checked /> <?php esc_html_e( 'Show out of the running', 'featured-post-rotator' ); ?></label>
		</div>

		<div class="bmfr-board-status" data-board-status role="status" aria-live="polite"></div>

		<div class="bmfr-board-scroll">
			<div class="bmfr-board-list" data-board-list>
				<p class="bmfr-board-loading"><?php esc_html_e( 'Loading the ranking…', 'featured-post-rotator' ); ?></p>
			</div>
		</div>

		<script type="application/json" id="bmfr-priority-data"><?php echo wp_json_encode( $client_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?></script>
	</section>
</div>
