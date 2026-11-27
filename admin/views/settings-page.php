<?php
/**
 * @var array $slots
 * @var array $notification_settings
 * @var array $all_categories
 * @var WP_Term[] $all_tags
 * @var array $slot_status
 * @var int|null $next_tick
 * @var array $cron_health
 * @var string $cron_time
 * @var array $slack_settings
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$weight_labels = array(
	'recency'          => __( 'Recency', 'featured-post-rotator' ),
	'category_balance' => __( 'Bilanciamento categoria', 'featured-post-rotator' ),
	'engagement'       => __( 'Engagement (commenti)', 'featured-post-rotator' ),
	'seasonal_fit'     => __( 'Adattamento stagionale', 'featured-post-rotator' ),
);
?>
<div class="wrap bmfr-wrap">
	<h1><?php esc_html_e( 'Rotazione Articoli — Impostazioni', 'featured-post-rotator' ); ?></h1>
	<p class="bmfr-lede"><?php esc_html_e( 'Gli slot nascono disattivati. Usa la scheda Anteprima per calcolare un\'anteprima (dry-run) e per eseguire una rotazione reale supervisionata prima di attivare il cron automatico qui sotto.', 'featured-post-rotator' ); ?></p>

	<nav class="bmfr-quicknav" aria-label="<?php esc_attr_e( 'Naviga tra le sezioni', 'featured-post-rotator' ); ?>">
		<ul>
			<li><a href="#bmfr-status"><?php esc_html_e( 'Stato pianificazione', 'featured-post-rotator' ); ?></a></li>
			<li><a href="#bmfr-slots"><?php esc_html_e( 'Slot', 'featured-post-rotator' ); ?></a></li>
			<li><a href="#bmfr-slack"><?php esc_html_e( 'Avviso Slack', 'featured-post-rotator' ); ?></a></li>
			<?php foreach ( $slots as $slot_key => $slot ) : ?>
				<li><a href="#bmfr-slot-<?php echo esc_attr( $slot_key ); ?>"><?php echo esc_html( $slot['label'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>

	<div class="bmfr-card" id="bmfr-status">
		<h2><?php esc_html_e( 'Stato pianificazione', 'featured-post-rotator' ); ?></h2>

		<p class="bmfr-health">
			<span class="bmfr-health-dot bmfr-health-dot-<?php echo esc_attr( $cron_health['status'] ); ?>" aria-hidden="true"></span>
			<span class="bmfr-health-text"><?php echo esc_html( $cron_health['message'] ); ?></span>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Slot', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Attivo', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Cadenza', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Ultima esecuzione', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Prossima esecuzione prevista', 'featured-post-rotator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $slots as $slot_key => $slot ) : ?>
					<?php $status = $slot_status[ $slot_key ]; ?>
					<tr>
						<td><?php echo esc_html( $slot['label'] ); ?></td>
						<td>
							<span class="bmfr-pill <?php echo $status['enabled'] ? 'bmfr-pill-on' : 'bmfr-pill-off'; ?>">
								<?php echo $status['enabled'] ? esc_html__( 'Sì', 'featured-post-rotator' ) : esc_html__( 'No', 'featured-post-rotator' ); ?>
							</span>
						</td>
						<td>
							<?php
							printf(
								/* translators: %d: number of days */
								esc_html( _n( 'ogni %d giorno', 'ogni %d giorni', (int) $status['cadence_days'], 'featured-post-rotator' ) ),
								(int) $status['cadence_days']
							);
							?>
						</td>
						<td><?php echo $status['last_run'] ? esc_html( wp_date( 'd/m/Y H:i', $status['last_run'] ) ) : esc_html__( 'Mai', 'featured-post-rotator' ); ?></td>
						<td>
							<?php
							if ( ! $status['enabled'] ) {
								esc_html_e( 'Disattivato', 'featured-post-rotator' );
							} elseif ( 0 === $status['next_run'] ) {
								esc_html_e( 'Dovuta: scatterà al prossimo controllo orario del cron', 'featured-post-rotator' );
							} else {
								echo esc_html( wp_date( 'd/m/Y H:i', $status['next_run'] ) );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			if ( $next_tick ) {
				printf(
					/* translators: %s: formatted date/time of the next hourly cron tick */
					esc_html__( 'Il controllo orario del cron è schedulato per le %s. L\'esecuzione reale dipende dal traffico del sito (o da un cron reale lato server che chiama wp-cron.php): su un sito con poco traffico il tick può slittare oltre l\'orario previsto.', 'featured-post-rotator' ),
					esc_html( wp_date( 'd/m/Y H:i', $next_tick ) )
				);
			} else {
				esc_html_e( 'Nessun controllo orario del cron risulta schedulato al momento.', 'featured-post-rotator' );
			}
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bmfr-cron-time-form">
			<input type="hidden" name="action" value="bmfr_save_cron_time" />
			<?php wp_nonce_field( 'bmfr_save_cron_time' ); ?>
			<label for="bmfr-cron-time"><?php esc_html_e( 'Orario preferito di esecuzione', 'featured-post-rotator' ); ?></label>
			<input type="time" id="bmfr-cron-time" name="bmfr_cron_time" value="<?php echo esc_attr( $cron_time ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Salva orario', 'featured-post-rotator' ); ?></button>
			<p class="description">
				<?php esc_html_e( 'Se impostato, la cadenza viene contata in giorni di calendario dall\'ultima esecuzione e la rotazione scatta sempre a questo orario (il controllo orario del cron continua comunque a girare ogni ora). Lascia il campo vuoto per eseguire non appena dovuta, a qualsiasi ora — è il comportamento di prima.', 'featured-post-rotator' ); ?>
			</p>
		</form>
	</div>

	<div class="bmfr-card" id="bmfr-slots">
		<h2><?php esc_html_e( 'Slot', 'featured-post-rotator' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Ogni slot è un blocco di articoli in evidenza: il plugin assegna il suo tag agli articoli scelti e lo toglie a quelli che escono. Il tema mostra nel blocco gli articoli con quel tag. Uno slot senza tag non ruota.', 'featured-post-rotator' ); ?>
		</p>

		<?php
		$bmfr_tag_options = static function ( $selected ) use ( $all_tags ) {
			$html  = '<option value="">' . esc_html__( '— Nessun tag —', 'featured-post-rotator' ) . '</option>';
			$found = '' === $selected;

			foreach ( $all_tags as $tag ) {
				$found = $found || $tag->slug === $selected;
				$html .= sprintf(
					'<option value="%1$s"%2$s>%3$s (%4$s, %5$d)</option>',
					esc_attr( $tag->slug ),
					selected( $tag->slug, $selected, false ),
					esc_html( $tag->name ),
					esc_html( $tag->slug ),
					(int) $tag->count
				);
			}

			// The slot's current tag no longer exists: keep it selectable so
			// saving the form doesn't silently change it.
			if ( ! $found ) {
				$html .= sprintf( '<option value="%1$s" selected>%2$s</option>', esc_attr( $selected ), esc_html( $selected ) );
			}

			return $html;
		};
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bmfr_save_slots" />
			<?php wp_nonce_field( 'bmfr_save_slots' ); ?>

			<table class="widefat striped bmfr-slots-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Nome', 'featured-post-rotator' ); ?></th>
						<th><?php esc_html_e( 'Tag', 'featured-post-rotator' ); ?></th>
						<th><?php esc_html_e( 'Articoli', 'featured-post-rotator' ); ?></th>
						<th><?php esc_html_e( 'Rimuovi', 'featured-post-rotator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $slots as $slot_key => $slot ) : ?>
						<tr>
							<td>
								<input type="text" name="bmfr_slots[<?php echo esc_attr( $slot_key ); ?>][label]" value="<?php echo esc_attr( $slot['label'] ); ?>" class="regular-text" required aria-label="<?php esc_attr_e( 'Nome dello slot', 'featured-post-rotator' ); ?>" />
								<br /><code class="bmfr-slot-key"><?php echo esc_html( $slot_key ); ?></code>
							</td>
							<td>
								<select name="bmfr_slots[<?php echo esc_attr( $slot_key ); ?>][tag_slug]" aria-label="<?php esc_attr_e( 'Tag dello slot', 'featured-post-rotator' ); ?>">
									<?php echo $bmfr_tag_options( $slot['tag_slug'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure. ?>
								</select>
							</td>
							<td>
								<input type="number" min="1" max="<?php echo (int) BMFR_Slots::MAX_COUNT; ?>" step="1" name="bmfr_slots[<?php echo esc_attr( $slot_key ); ?>][count]" value="<?php echo (int) $slot['count']; ?>" class="small-text" aria-label="<?php esc_attr_e( 'Numero di articoli nello slot', 'featured-post-rotator' ); ?>" />
							</td>
							<td>
								<label>
									<input type="checkbox" name="bmfr_slots[<?php echo esc_attr( $slot_key ); ?>][remove]" value="1" />
									<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: slot label */ __( 'Rimuovi %s', 'featured-post-rotator' ), $slot['label'] ) ); ?></span>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( count( $slots ) < BMFR_Slots::MAX_SLOTS ) : ?>
						<tr class="bmfr-slot-new">
							<td>
								<input type="text" name="bmfr_new_slot[label]" value="" class="regular-text" placeholder="<?php esc_attr_e( 'Nuovo slot (lascia vuoto per non aggiungerne)', 'featured-post-rotator' ); ?>" aria-label="<?php esc_attr_e( 'Nome del nuovo slot', 'featured-post-rotator' ); ?>" />
							</td>
							<td>
								<select name="bmfr_new_slot[tag_slug]" aria-label="<?php esc_attr_e( 'Tag del nuovo slot', 'featured-post-rotator' ); ?>">
									<?php echo $bmfr_tag_options( '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure. ?>
								</select>
							</td>
							<td>
								<input type="number" min="1" max="<?php echo (int) BMFR_Slots::MAX_COUNT; ?>" step="1" name="bmfr_new_slot[count]" value="3" class="small-text" aria-label="<?php esc_attr_e( 'Numero di articoli nel nuovo slot', 'featured-post-rotator' ); ?>" />
							</td>
							<td></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'Il codice sotto il nome identifica lo slot e non cambia mai: puoi rinominare lo slot e cambiarne tag e numero di articoli senza perdere impostazioni, priorità e cronologia.', 'featured-post-rotator' ); ?>
				<?php esc_html_e( 'Rimuovere uno slot lo toglie dalla rotazione e dalle pagine, ma ne conserva i dati: se ricrei uno slot con lo stesso nome li ritrova. Il tag resta sugli articoli che lo hanno già.', 'featured-post-rotator' ); ?>
				<?php esc_html_e( 'Se cambi il tag di uno slot, gli articoli con il tag precedente lo conservano: toglilo a mano se serve.', 'featured-post-rotator' ); ?>
			</p>

			<?php submit_button( __( 'Salva slot', 'featured-post-rotator' ) ); ?>
		</form>
	</div>

	<div class="bmfr-card" id="bmfr-slack">
		<h2><?php esc_html_e( 'Avviso Slack pre-rotazione', 'featured-post-rotator' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Un messaggio puramente informativo, inviato una sola volta per ciclo quando manca il tempo di preavviso indicato alla rotazione automatica di uno slot attivo: "succederà, se non intervieni". Nessun bottone d\'azione nel messaggio — per escludere un candidato o rivedere chi entrerà si va nella pagina Anteprima, linkata nel messaggio stesso.', 'featured-post-rotator' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bmfr_save_slack_settings" />
			<?php wp_nonce_field( 'bmfr_save_slack_settings' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bmfr-slack-webhook"><?php esc_html_e( 'Slack Webhook URL', 'featured-post-rotator' ); ?></label></th>
					<td>
						<input type="url" id="bmfr-slack-webhook" name="bmfr_slack_webhook_url" value="<?php echo esc_attr( $slack_settings['webhook_url'] ); ?>" class="large-text" placeholder="https://hooks.slack.com/services/…" />
						<p class="description">
							<?php esc_html_e( 'URL del webhook creato in Slack (App Slack → Incoming Webhooks → attiva → scegli il canale dedicato → copia l\'URL). Il canale si sceglie lato Slack quando crei il webhook, non qui. Lascia vuoto per disattivare l\'avviso.', 'featured-post-rotator' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bmfr-slack-lead"><?php esc_html_e( 'Preavviso (ore prima della rotazione)', 'featured-post-rotator' ); ?></label></th>
					<td>
						<input type="number" min="1" max="168" step="1" id="bmfr-slack-lead" name="bmfr_slack_lead_hours" value="<?php echo esc_attr( $slack_settings['lead_hours'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Quanto tempo prima della rotazione dovuta arriva l\'avviso: deve essere tempo utile per intervenire sulla pagina Anteprima.', 'featured-post-rotator' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Salva impostazioni Slack', 'featured-post-rotator' ) ); ?>
		</form>

		<?php if ( ! empty( $slack_settings['webhook_url'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bmfr_send_slack_test" />
				<?php wp_nonce_field( 'bmfr_send_slack_test' ); ?>
				<?php submit_button( __( 'Invia notifica di prova', 'featured-post-rotator' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	</div>

	<?php
	foreach ( $slots as $slot_key => $slot ) :
		$settings      = BMFR_Settings::get_slot_settings( $slot_key );
		$excluded_cats = array_map( 'absint', $settings['excluded_categories'] );

		$weights_sum = array_sum( $settings['weights'] );
		if ( $weights_sum > 0 ) {
			$normalized_weights = array_map(
				static function ( $weight ) use ( $weights_sum ) {
					return $weight / $weights_sum;
				},
				$settings['weights']
			);
		} else {
			$normalized_weights = array_fill_keys( array_keys( $weight_labels ), 1 / count( $weight_labels ) );
		}
		?>
		<details class="bmfr-card bmfr-slot-card" id="bmfr-slot-<?php echo esc_attr( $slot_key ); ?>" open>
			<summary class="bmfr-slot-summary">
				<span class="bmfr-slot-summary-title"><?php echo esc_html( $slot['label'] ); ?></span>
				<span class="bmfr-slot-summary-meta">
					<?php
					if ( '' === $slot['tag_slug'] ) {
						printf(
							/* translators: %d: number of posts shown in the slot */
							esc_html__( '%d post, nessun tag: scegline uno nella sezione Slot', 'featured-post-rotator' ),
							(int) $slot['count']
						);
					} else {
						printf(
							/* translators: 1: number of posts shown in the slot, 2: WordPress tag slug */
							esc_html__( '%1$d post, tag %2$s', 'featured-post-rotator' ),
							(int) $slot['count'],
							'<code>' . esc_html( $slot['tag_slug'] ) . '</code>'
						);
					}
					?>
				</span>
			</summary>

			<div class="bmfr-slot-body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bmfr_save_settings" />
					<input type="hidden" name="slot" value="<?php echo esc_attr( $slot_key ); ?>" />
					<?php wp_nonce_field( 'bmfr_save_settings_' . $slot_key ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Attivo', 'featured-post-rotator' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="bmfr[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
									<?php esc_html_e( 'Abilita la rotazione automatica via cron per questo slot', 'featured-post-rotator' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-cadence-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Cadenza (giorni)', 'featured-post-rotator' ); ?></label></th>
							<td><input type="number" min="1" step="1" id="bmfr-cadence-<?php echo esc_attr( $slot_key ); ?>" name="bmfr[cadence_days]" value="<?php echo esc_attr( $settings['cadence_days'] ); ?>" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-cooldown-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Cooldown (giorni)', 'featured-post-rotator' ); ?></label></th>
							<td>
								<input type="number" min="0" step="1" id="bmfr-cooldown-<?php echo esc_attr( $slot_key ); ?>" name="bmfr[cooldown_days]" value="<?php echo esc_attr( $settings['cooldown_days'] ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'Un post già entrato in questo slot non può ritornarci prima di N giorni.', 'featured-post-rotator' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-minage-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Età minima post (giorni)', 'featured-post-rotator' ); ?></label></th>
							<td><input type="number" min="0" step="1" id="bmfr-minage-<?php echo esc_attr( $slot_key ); ?>" name="bmfr[min_age_days]" value="<?php echo esc_attr( $settings['min_age_days'] ); ?>" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-halflife-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Half-life recency (giorni)', 'featured-post-rotator' ); ?></label></th>
							<td>
								<input type="number" min="1" step="1" id="bmfr-halflife-<?php echo esc_attr( $slot_key ); ?>" name="bmfr[recency_half_life_days]" value="<?php echo esc_attr( $settings['recency_half_life_days'] ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'Più basso = favorisce fortemente i post recenti; più alto = punteggio più piatto tra post vecchi e nuovi.', 'featured-post-rotator' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Età massima', 'featured-post-rotator' ); ?></th>
							<td>
								<select name="bmfr[max_age_mode]" data-age-mode-select>
									<option value="relative" <?php selected( $settings['max_age_mode'], 'relative' ); ?>><?php esc_html_e( 'Relativa: non più vecchio di N giorni da oggi', 'featured-post-rotator' ); ?></option>
									<option value="absolute" <?php selected( $settings['max_age_mode'], 'absolute' ); ?>><?php esc_html_e( 'Assoluta: non più vecchio di una data fissa', 'featured-post-rotator' ); ?></option>
									<option value="none" <?php selected( $settings['max_age_mode'], 'none' ); ?>><?php esc_html_e( 'Nessun limite', 'featured-post-rotator' ); ?></option>
								</select>
								<p data-age-field="relative">
									<label><?php esc_html_e( 'Giorni (usato se "Relativa")', 'featured-post-rotator' ); ?>
										<input type="number" min="0" step="1" name="bmfr[max_age_days]" value="<?php echo esc_attr( $settings['max_age_days'] ); ?>" class="small-text" />
									</label>
								</p>
								<p data-age-field="absolute">
									<label><?php esc_html_e( 'Data (usata se "Assoluta", formato AAAA-MM-GG)', 'featured-post-rotator' ); ?>
										<input type="date" name="bmfr[max_age_date]" value="<?php echo esc_attr( $settings['max_age_date'] ); ?>" />
									</label>
								</p>
								<p class="description"><?php esc_html_e( 'I post pubblicati prima di questa soglia non sono mai candidati per questo slot.', 'featured-post-rotator' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Pesi dei segnali', 'featured-post-rotator' ); ?></th>
							<td>
								<div class="bmfr-weights-grid" data-weights-group>
									<?php foreach ( $weight_labels as $weight_key => $weight_label ) : ?>
										<label class="bmfr-weight-field">
											<span class="bmfr-weight-field-label"><?php echo esc_html( $weight_label ); ?></span>
											<input type="number" min="0" max="1" step="0.05" name="bmfr[weights][<?php echo esc_attr( $weight_key ); ?>]" value="<?php echo esc_attr( $settings['weights'][ $weight_key ] ); ?>" class="small-text" data-weight="<?php echo esc_attr( $weight_key ); ?>" data-weight-label="<?php echo esc_attr( $weight_label ); ?>" />
										</label>
									<?php endforeach; ?>
								</div>
								<p class="bmfr-weights-total" data-weights-total aria-live="polite">
									<?php
									$readout_parts = array();
									foreach ( $weight_labels as $weight_key => $weight_label ) {
										$readout_parts[] = $weight_label . ' ' . round( $normalized_weights[ $weight_key ] * 100 ) . '%';
									}
									echo esc_html( implode( ' · ', $readout_parts ) );
									?>
								</p>
								<p class="description">
									<?php esc_html_e( 'I pesi vengono normalizzati automaticamente (non serve che sommino a 1). Se li metti tutti a 0 il plugin usa pesi uguali.', 'featured-post-rotator' ); ?>
									<?php
									printf(
										/* translators: %s: link to the Calendario stagionale admin page */
										esc_html__( 'L\'"Adattamento stagionale" premia i post in tema con la ricorrenza del momento, definita nel %s.', 'featured-post-rotator' ),
										'<a href="' . esc_url( admin_url( 'admin.php?page=bmfr-calendar' ) ) . '">' . esc_html__( 'Calendario stagionale', 'featured-post-rotator' ) . '</a>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-excluded-cats-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Categorie escluse', 'featured-post-rotator' ); ?></label></th>
							<td>
								<input type="text" class="bmfr-category-filter" data-filter-target="bmfr-excluded-cats-<?php echo esc_attr( $slot_key ); ?>" placeholder="<?php esc_attr_e( 'Filtra categorie…', 'featured-post-rotator' ); ?>" />
								<select multiple size="8" id="bmfr-excluded-cats-<?php echo esc_attr( $slot_key ); ?>" name="bmfr[excluded_categories][]" class="bmfr-category-select">
									<?php foreach ( $all_categories as $category ) : ?>
										<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( in_array( (int) $category->term_id, $excluded_cats, true ) ); ?>>
											<?php echo esc_html( $category->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php
									printf(
										/* translators: %s: link to the Esclusioni admin page */
										esc_html__( 'I post di queste categorie non saranno mai candidati per questo slot (si sommano alle %s).', 'featured-post-rotator' ),
										'<a href="' . esc_url( admin_url( 'admin.php?page=bmfr-exclusions' ) ) . '">' . esc_html__( 'esclusioni globali', 'featured-post-rotator' ) . '</a>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-excluded-posts-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Post esclusi (ID)', 'featured-post-rotator' ); ?></label></th>
							<td>
								<textarea id="bmfr-excluded-posts-<?php echo esc_attr( $slot_key ); ?>" name="bmfr[excluded_post_ids]" rows="2" class="large-text" placeholder="123, 456, 789"><?php echo esc_textarea( implode( ', ', $settings['excluded_post_ids'] ) ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-recipients-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Destinatari notifiche email', 'featured-post-rotator' ); ?></label></th>
							<td>
								<textarea id="bmfr-recipients-<?php echo esc_attr( $slot_key ); ?>" name="bmfr_recipients" rows="3" class="large-text" placeholder="nome@esempio.it"><?php echo esc_textarea( implode( "\n", $notification_settings['recipients'] ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Un indirizzo per riga. Condiviso tra tutti gli slot: salvando da uno qualsiasi dei form aggiorni questa lista per tutti.', 'featured-post-rotator' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-subject-prefix-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Nome nell\'oggetto email', 'featured-post-rotator' ); ?></label></th>
							<td>
								<input type="text" id="bmfr-subject-prefix-<?php echo esc_attr( $slot_key ); ?>" name="bmfr_subject_prefix" value="<?php echo esc_attr( $notification_settings['subject_prefix'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Compare tra parentesi quadre all\'inizio dell\'oggetto, per esempio "[Nome] Rotazione …". Vuoto = titolo del sito. Condiviso tra tutti gli slot.', 'featured-post-rotator' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( sprintf( /* translators: %s: slot label */ __( 'Salva impostazioni "%s"', 'featured-post-rotator' ), $slot['label'] ) ); ?>
				</form>
			</div>
		</details>
	<?php endforeach; ?>
</div>
