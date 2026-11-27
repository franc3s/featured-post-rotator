<?php
/**
 * @var array       $slots
 * @var string      $current_slot
 * @var array|null  $preview
 * @var int[]        $currently_tagged
 * @var int|null     $next_run  Null|0|timestamp, see BMFR_Cron::get_next_run_estimate().
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bmfr_render_exclude_button = static function ( $post_id ) {
	ob_start();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" data-bmfr-confirm="<?php echo esc_attr( __( 'Escludere questo post da tutte le rotazioni future? Se è già in evidenza viene rimosso subito.', 'featured-post-rotator' ) ); ?>">
		<input type="hidden" name="action" value="bmfr_exclude_post" />
		<input type="hidden" name="exclusion_id" value="<?php echo esc_attr( $post_id ); ?>" />
		<?php wp_nonce_field( 'bmfr_exclusion_action' ); ?>
		<button type="submit" class="button button-small"><?php esc_html_e( 'Escludi', 'featured-post-rotator' ); ?></button>
	</form>
	<?php
	return ob_get_clean();
};

$bmfr_render_swap_button = static function ( $post_id, $slot_key ) {
	ob_start();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" data-bmfr-confirm="<?php echo esc_attr( __( 'Sostituire questo post nello slot con un altro candidato? Non viene escluso: potrà tornare nelle prossime rotazioni.', 'featured-post-rotator' ) ); ?>">
		<input type="hidden" name="action" value="bmfr_swap_post" />
		<input type="hidden" name="swap_id" value="<?php echo esc_attr( $post_id ); ?>" />
		<input type="hidden" name="slot" value="<?php echo esc_attr( $slot_key ); ?>" />
		<?php wp_nonce_field( 'bmfr_swap_post' ); ?>
		<button type="submit" class="button button-small"><?php esc_html_e( 'Cambia', 'featured-post-rotator' ); ?></button>
	</form>
	<?php
	return ob_get_clean();
};

// Next-rotation status strip: reuses the same green/yellow/neutral traffic
// light as the cron health check on the Impostazioni page, so "imminente"
// reads the same way everywhere in the plugin.
if ( null === $next_run ) {
	$bmfr_next_run_status  = 'neutral';
	$bmfr_next_run_message = esc_html__( 'Rotazione automatica disattivata per questo slot. Puoi comunque eseguirla manualmente qui sotto.', 'featured-post-rotator' );
} elseif ( 0 === $next_run ) {
	$bmfr_next_run_status  = 'yellow';
	$bmfr_next_run_message = esc_html__( 'Dovuta ora: scatterà al prossimo controllo orario del cron.', 'featured-post-rotator' );
} else {
	$bmfr_next_run_status  = ( $next_run - time() ) <= DAY_IN_SECONDS ? 'yellow' : 'green';
	$bmfr_next_run_message = sprintf(
		/* translators: 1: formatted date/time of the next rotation, 2: relative time until then */
		esc_html__( 'Prossima rotazione: %1$s (tra %2$s).', 'featured-post-rotator' ),
		esc_html( wp_date( 'd/m/Y H:i', $next_run ) ),
		esc_html( human_time_diff( time(), $next_run ) )
	);
}

// The dry-run result gives to_add/to_remove/unchanged as three separate
// lists sharing the exact same columns — merged here into one table with a
// status pill column instead of three stacked tables, with the incoming
// candidates (the most actionable rows) listed first.
$bmfr_forecast_rows = array();
if ( $preview ) {
	foreach ( $preview['to_add'] as $post_id ) {
		$bmfr_forecast_rows[] = array( 'post_id' => $post_id, 'status' => 'in' );
	}
	foreach ( $preview['unchanged'] as $post_id ) {
		$bmfr_forecast_rows[] = array( 'post_id' => $post_id, 'status' => 'unchanged' );
	}
	foreach ( $preview['to_remove'] as $post_id ) {
		$bmfr_forecast_rows[] = array( 'post_id' => $post_id, 'status' => 'out' );
	}
}

$bmfr_status_labels = array(
	'in'        => __( 'IN', 'featured-post-rotator' ),
	'out'       => __( 'OUT', 'featured-post-rotator' ),
	'unchanged' => __( 'INVARIATO', 'featured-post-rotator' ),
);
?>
<div class="wrap bmfr-wrap">
	<h1><?php esc_html_e( 'Rotazione Articoli — Anteprima', 'featured-post-rotator' ); ?></h1>

	<ul class="subsubsub">
		<?php
		$slot_items = array();
		foreach ( $slots as $slot_key => $slot ) {
			$url          = add_query_arg(
				array(
					'page' => 'bmfr-preview',
					'slot' => $slot_key,
				),
				admin_url( 'admin.php' )
			);
			$class        = ( $slot_key === $current_slot ) ? ' class="current"' : '';
			$slot_items[] = '<li><a href="' . esc_url( $url ) . '"' . $class . '>' . esc_html( $slot['label'] ) . '</a></li>';
		}
		echo implode( ' | ', $slot_items ); // phpcs:ignore WordPress.Security.EscapeOutput -- built entirely from esc_url/esc_html above.
		?>
	</ul>
	<br class="clear" />

	<div class="bmfr-card bmfr-preview-status">
		<p class="bmfr-health">
			<span class="bmfr-health-dot bmfr-health-dot-<?php echo esc_attr( $bmfr_next_run_status ); ?>" aria-hidden="true"></span>
			<span class="bmfr-health-text"><?php echo $bmfr_next_run_message; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_html__/esc_html above. ?></span>
		</p>

		<?php if ( ! BMFR_Slots::has_tag( $current_slot ) ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %s: link to the Slot section of the settings page */
						esc_html__( 'Questo slot non ha ancora un tag, quindi non può ruotare. Sceglilo nella sezione %s delle Impostazioni.', 'featured-post-rotator' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=bmfr-settings#bmfr-slots' ) ) . '">' . esc_html__( 'Slot', 'featured-post-rotator' ) . '</a>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bmfr-rotate-form" data-bmfr-confirm-template="<?php echo esc_attr( __( 'Confermi la rotazione reale per: %s? Verranno modificati i tag sui post in produzione.', 'featured-post-rotator' ) ); ?>">
			<input type="hidden" name="action" value="bmfr_rotate_now" />
			<input type="hidden" name="current_slot" value="<?php echo esc_attr( $current_slot ); ?>" />
			<?php wp_nonce_field( 'bmfr_rotate_now' ); ?>
			<fieldset class="bmfr-rotate-slots">
				<legend class="screen-reader-text"><?php esc_html_e( 'Slot da ruotare', 'featured-post-rotator' ); ?></legend>
				<?php foreach ( $slots as $slot_key => $slot ) : ?>
					<label class="bmfr-rotate-slot-choice">
						<input type="checkbox" name="slots[]" value="<?php echo esc_attr( $slot_key ); ?>" <?php checked( $slot_key === $current_slot && BMFR_Slots::has_tag( $slot_key ) ); ?> <?php disabled( ! BMFR_Slots::has_tag( $slot_key ) ); ?> />
						<?php echo esc_html( $slot['label'] ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<?php submit_button( __( 'Esegui ora (reale)', 'featured-post-rotator' ), 'primary', 'bmfr_rotate_submit', false ); ?>
		</form>
	</div>

	<div class="bmfr-preview-columns">
		<div class="bmfr-card bmfr-card-compact">
			<h2><?php esc_html_e( 'Attualmente in evidenza', 'featured-post-rotator' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<?php if ( empty( $currently_tagged ) ) : ?>
						<tr><td>—</td></tr>
					<?php endif; ?>
					<?php foreach ( $currently_tagged as $tagged_post_id ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $tagged_post_id ) ); ?>"><?php echo esc_html( get_the_title( $tagged_post_id ) ); ?></a>
								<?php echo $bmfr_render_swap_button( $tagged_post_id, $current_slot ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup built in the closure above. ?>
								<?php echo $bmfr_render_exclude_button( $tagged_post_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup built in the closure above. ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="bmfr-card">
			<div class="bmfr-table-actions-row">
				<h2>
					<?php esc_html_e( 'Prossimo turno', 'featured-post-rotator' ); ?>
					<?php if ( $preview ) : ?>
						<span class="bmfr-candidate-count">
							<?php
							printf(
								/* translators: %d: number of eligible candidates found */
								esc_html__( '(%d candidati eleggibili)', 'featured-post-rotator' ),
								(int) $preview['candidate_count']
							);
							?>
						</span>
					<?php endif; ?>
				</h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bmfr_dry_run" />
					<input type="hidden" name="slot" value="<?php echo esc_attr( $current_slot ); ?>" />
					<?php wp_nonce_field( 'bmfr_dry_run_' . $current_slot ); ?>
					<?php submit_button( __( 'Ricalcola', 'featured-post-rotator' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<?php if ( $preview && ! empty( $preview['warnings'] ) ) : ?>
				<div class="notice notice-warning inline">
					<ul>
						<?php foreach ( $preview['warnings'] as $warning ) : ?>
							<li><?php echo esc_html( $warning ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<table class="widefat striped bmfr-forecast-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post', 'featured-post-rotator' ); ?></th>
						<th><?php esc_html_e( 'Stato', 'featured-post-rotator' ); ?></th>
						<th><?php esc_html_e( 'Punteggio', 'featured-post-rotator' ); ?></th>
						<th><?php esc_html_e( 'Recency', 'featured-post-rotator' ); ?></th>
						<th><?php esc_html_e( 'Categoria', 'featured-post-rotator' ); ?></th>
						<th><?php esc_html_e( 'Engagement', 'featured-post-rotator' ); ?></th>
						<th><?php esc_html_e( 'Stagionalità', 'featured-post-rotator' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $bmfr_forecast_rows ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'Nessun candidato eleggibile al momento.', 'featured-post-rotator' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $bmfr_forecast_rows as $row ) : ?>
						<?php
						$post_id = $row['post_id'];
						$data    = isset( $preview['scored'][ $post_id ] ) ? $preview['scored'][ $post_id ] : null;
						?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a></td>
							<td><span class="bmfr-pill bmfr-pill-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $bmfr_status_labels[ $row['status'] ] ); ?></span></td>
							<td class="bmfr-score-final">
								<?php echo $data ? esc_html( round( $data['final'], 3 ) ) : '—'; ?>
								<?php if ( $data && abs( $data['manual_boost'] ) > 0.001 ) : ?>
									<span class="bmfr-cycle-manual" title="<?php echo esc_attr( sprintf(
										/* translators: %+.2f: the manual correction applied on the Tour page, with its sign */
										__( 'Corretto a mano sul Tour: %+.2f', 'featured-post-rotator' ),
										$data['manual_boost']
									) ); ?>"><?php esc_html_e( 'manuale', 'featured-post-rotator' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="bmfr-score-cell"><?php echo $data ? esc_html( round( $data['breakdown']['recency'], 3 ) ) : '—'; ?></td>
							<td class="bmfr-score-cell"><?php echo $data ? esc_html( round( $data['breakdown']['category_balance'], 3 ) ) : '—'; ?></td>
							<td class="bmfr-score-cell"><?php echo $data ? esc_html( round( $data['breakdown']['engagement'], 3 ) ) : '—'; ?></td>
							<td class="bmfr-score-cell"><?php echo $data ? esc_html( round( $data['breakdown']['seasonal_fit'], 3 ) ) : '—'; ?></td>
							<td><?php echo $bmfr_render_exclude_button( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup built in the closure above. ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
