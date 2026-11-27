<?php
/**
 * @var BMFR_History_List_Table $list_table
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$selected_slot = isset( $_GET['slot'] ) ? sanitize_key( wp_unslash( $_GET['slot'] ) ) : '';
?>
<div class="wrap bmfr-wrap">
	<h1><?php esc_html_e( 'Rotazione Articoli — Cronologia', 'featured-post-rotator' ); ?></h1>

	<form method="get">
		<input type="hidden" name="page" value="bmfr-history" />
		<select name="slot">
			<option value=""><?php esc_html_e( 'Tutti gli slot', 'featured-post-rotator' ); ?></option>
			<?php foreach ( BMFR_Slots::get_slots() as $slot_key => $slot ) : ?>
				<option value="<?php echo esc_attr( $slot_key ); ?>" <?php selected( $selected_slot, $slot_key ); ?>><?php echo esc_html( $slot['label'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php submit_button( __( 'Filtra', 'featured-post-rotator' ), 'secondary', '', false ); ?>

		<?php $list_table->display(); ?>
	</form>
</div>
