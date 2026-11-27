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
	<h1><?php esc_html_e( 'Rotazione Articoli — Calendario stagionale', 'featured-post-rotator' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Se un post corrisponde a una voce (per parola chiave nel titolo o per tag) e la data di oggi è fuori dalla finestra indicata, il post viene escluso automaticamente da tutti gli slot. Se la data di oggi è dentro la finestra, il post riceve invece un bonus "in tema" nel punteggio — il peso di questo bonus si imposta per ciascuno slot nella pagina Impostazioni.', 'featured-post-rotator' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="bmfr_save_calendar" />
		<?php wp_nonce_field( 'bmfr_save_calendar' ); ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Etichetta', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Parole chiave nel titolo (separate da virgola)', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Tag (slug, separati da virgola)', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Inizio (MM-GG)', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Fine (MM-GG)', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Attivo', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Elimina', 'featured-post-rotator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php for ( $i = 0; $i < $total_rows; $i++ ) : ?>
					<?php $entry = isset( $calendar[ $i ] ) ? $calendar[ $i ] : array( 'label' => '', 'keywords' => array(), 'tag_slugs' => array(), 'start' => '', 'end' => '', 'enabled' => true ); ?>
					<tr>
						<td><input type="text" name="bmfr_calendar[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $entry['label'] ); ?>" class="regular-text" <?php echo empty( $entry['label'] ) ? 'placeholder="' . esc_attr__( 'Nuova voce', 'featured-post-rotator' ) . '"' : ''; ?> /></td>
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
		<p class="description"><?php esc_html_e( 'Le righe vuote (senza etichetta) vengono ignorate: usale per aggiungere nuove voci.', 'featured-post-rotator' ); ?></p>

		<?php submit_button( __( 'Salva calendario', 'featured-post-rotator' ) ); ?>
	</form>
</div>
