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
	'category_balance' => __( 'Category balance', 'featured-post-rotator' ),
	'engagement'       => __( 'Engagement (comments)', 'featured-post-rotator' ),
	'seasonal_fit'     => __( 'Seasonal fit', 'featured-post-rotator' ),
);
?>
<div class="wrap bmfr-wrap">
	<h1><?php esc_html_e( 'Post Rotator — Settings', 'featured-post-rotator' ); ?></h1>
	<p class="bmfr-lede"><?php esc_html_e( 'Slots start turned off. Use the Preview tab to calculate a preview (dry run) and to run a supervised real rotation before turning on the automatic cron below.', 'featured-post-rotator' ); ?></p>

	<nav class="bmfr-quicknav" aria-label="<?php esc_attr_e( 'Jump to section', 'featured-post-rotator' ); ?>">
		<ul>
			<li><a href="#bmfr-status"><?php esc_html_e( 'Schedule status', 'featured-post-rotator' ); ?></a></li>
			<li><a href="#bmfr-slots"><?php esc_html_e( 'Slot', 'featured-post-rotator' ); ?></a></li>
			<li><a href="#bmfr-slack"><?php esc_html_e( 'Slack notice', 'featured-post-rotator' ); ?></a></li>
			<?php foreach ( $slots as $slot_key => $slot ) : ?>
				<li><a href="#bmfr-slot-<?php echo esc_attr( $slot_key ); ?>"><?php echo esc_html( $slot['label'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>

	<div class="bmfr-card" id="bmfr-status">
		<h2><?php esc_html_e( 'Schedule status', 'featured-post-rotator' ); ?></h2>

		<p class="bmfr-health">
			<span class="bmfr-health-dot bmfr-health-dot-<?php echo esc_attr( $cron_health['status'] ); ?>" aria-hidden="true"></span>
			<span class="bmfr-health-text"><?php echo esc_html( $cron_health['message'] ); ?></span>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Slot', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Active', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Cadence', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Last run', 'featured-post-rotator' ); ?></th>
					<th><?php esc_html_e( 'Next expected run', 'featured-post-rotator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $slots as $slot_key => $slot ) : ?>
					<?php $status = $slot_status[ $slot_key ]; ?>
					<tr>
						<td><?php echo esc_html( $slot['label'] ); ?></td>
						<td>
							<span class="bmfr-pill <?php echo $status['enabled'] ? 'bmfr-pill-on' : 'bmfr-pill-off'; ?>">
								<?php echo $status['enabled'] ? esc_html__( 'Yes', 'featured-post-rotator' ) : esc_html__( 'No', 'featured-post-rotator' ); ?>
							</span>
						</td>
						<td>
							<?php
							printf(
								/* translators: %d: number of days */
								esc_html( _n( 'every %d day', 'every %d days', (int) $status['cadence_days'], 'featured-post-rotator' ) ),
								(int) $status['cadence_days']
							);
							?>
						</td>
						<td><?php echo $status['last_run'] ? esc_html( wp_date( 'd/m/Y H:i', $status['last_run'] ) ) : esc_html__( 'Never', 'featured-post-rotator' ); ?></td>
						<td>
							<?php
							if ( ! $status['enabled'] ) {
								esc_html_e( 'Off', 'featured-post-rotator' );
							} elseif ( 0 === $status['next_run'] ) {
								esc_html_e( 'Due: it will run at the next hourly cron check', 'featured-post-rotator' );
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
					esc_html__( 'The hourly cron check is scheduled for %s. The actual run depends on site traffic (or on a real server-side cron calling wp-cron.php): on a low-traffic site the tick can slip past the scheduled time.', 'featured-post-rotator' ),
					esc_html( wp_date( 'd/m/Y H:i', $next_tick ) )
				);
			} else {
				esc_html_e( 'No hourly cron check is scheduled at the moment.', 'featured-post-rotator' );
			}
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bmfr-cron-time-form">
			<input type="hidden" name="action" value="bmfr_save_cron_time" />
			<?php wp_nonce_field( 'bmfr_save_cron_time' ); ?>
			<label for="bmfr-cron-time"><?php esc_html_e( 'Preferred run time', 'featured-post-rotator' ); ?></label>
			<input type="time" id="bmfr-cron-time" name="bmfr_cron_time" value="<?php echo esc_attr( $cron_time ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Save time', 'featured-post-rotator' ); ?></button>
			<p class="description">
				<?php esc_html_e( 'If set, the cadence is counted in calendar days from the last run and the rotation always runs at this time (the hourly cron check still runs every hour). Leave the field empty to run as soon as it is due, at any time — the previous behavior.', 'featured-post-rotator' ); ?>
			</p>
		</form>
	</div>

	<div class="bmfr-card" id="bmfr-slots">
		<h2><?php esc_html_e( 'Slot', 'featured-post-rotator' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Each slot is a block of featured posts: the plugin adds its tag to the chosen posts and removes it from those that leave. The theme shows the posts with that tag in the block. A slot without a tag does not rotate.', 'featured-post-rotator' ); ?>
		</p>

		<?php
		$bmfr_tag_options = static function ( $selected ) use ( $all_tags ) {
			$html  = '<option value="">' . esc_html__( '— No tag —', 'featured-post-rotator' ) . '</option>';
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
						<th><?php esc_html_e( 'Name', 'featured-post-rotator' ); ?></th>
						<th><?php esc_html_e( 'Tag', 'featured-post-rotator' ); ?></th>
						<th><?php esc_html_e( 'Posts', 'featured-post-rotator' ); ?></th>
						<th><?php esc_html_e( 'Remove', 'featured-post-rotator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $slots as $slot_key => $slot ) : ?>
						<tr>
							<td>
								<input type="text" name="bmfr_slots[<?php echo esc_attr( $slot_key ); ?>][label]" value="<?php echo esc_attr( $slot['label'] ); ?>" class="regular-text" required aria-label="<?php esc_attr_e( 'Slot name', 'featured-post-rotator' ); ?>" />
								<br /><code class="bmfr-slot-key"><?php echo esc_html( $slot_key ); ?></code>
							</td>
							<td>
								<select name="bmfr_slots[<?php echo esc_attr( $slot_key ); ?>][tag_slug]" aria-label="<?php esc_attr_e( 'Slot tag', 'featured-post-rotator' ); ?>">
									<?php echo $bmfr_tag_options( $slot['tag_slug'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure. ?>
								</select>
							</td>
							<td>
								<input type="number" min="1" max="<?php echo (int) BMFR_Slots::MAX_COUNT; ?>" step="1" name="bmfr_slots[<?php echo esc_attr( $slot_key ); ?>][count]" value="<?php echo (int) $slot['count']; ?>" class="small-text" aria-label="<?php esc_attr_e( 'Number of posts in the slot', 'featured-post-rotator' ); ?>" />
							</td>
							<td>
								<label>
									<input type="checkbox" name="bmfr_slots[<?php echo esc_attr( $slot_key ); ?>][remove]" value="1" />
									<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: slot label */ __( 'Remove %s', 'featured-post-rotator' ), $slot['label'] ) ); ?></span>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( count( $slots ) < BMFR_Slots::MAX_SLOTS ) : ?>
						<tr class="bmfr-slot-new">
							<td>
								<input type="text" name="bmfr_new_slot[label]" value="" class="regular-text" placeholder="<?php esc_attr_e( 'New slot (leave empty to add none)', 'featured-post-rotator' ); ?>" aria-label="<?php esc_attr_e( 'New slot name', 'featured-post-rotator' ); ?>" />
							</td>
							<td>
								<select name="bmfr_new_slot[tag_slug]" aria-label="<?php esc_attr_e( 'New slot tag', 'featured-post-rotator' ); ?>">
									<?php echo $bmfr_tag_options( '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure. ?>
								</select>
							</td>
							<td>
								<input type="number" min="1" max="<?php echo (int) BMFR_Slots::MAX_COUNT; ?>" step="1" name="bmfr_new_slot[count]" value="3" class="small-text" aria-label="<?php esc_attr_e( 'Number of posts in the new slot', 'featured-post-rotator' ); ?>" />
							</td>
							<td></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'The code under the name identifies the slot and never changes: you can rename the slot and change its tag and number of posts without losing settings, priorities and history.', 'featured-post-rotator' ); ?>
				<?php esc_html_e( 'Removing a slot takes it out of the rotation and the pages, but keeps its data: if you create a slot with the same name again, it gets them back. The tag stays on the posts that already have it.', 'featured-post-rotator' ); ?>
				<?php esc_html_e( 'If you change a slot\'s tag, posts with the previous tag keep it: remove it by hand if needed.', 'featured-post-rotator' ); ?>
			</p>

			<?php submit_button( __( 'Save slots', 'featured-post-rotator' ) ); ?>
		</form>
	</div>

	<div class="bmfr-card" id="bmfr-slack">
		<h2><?php esc_html_e( 'Slack pre-rotation notice', 'featured-post-rotator' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'A purely informational message, sent once per cycle when the given notice time is left before the automatic rotation of an active slot: "this will happen unless you step in". There are no action buttons in the message — to exclude a candidate or review who will come in, go to the Preview page, linked in the message itself.', 'featured-post-rotator' ); ?>
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
							<?php esc_html_e( 'URL of the webhook created in Slack (Slack app → Incoming Webhooks → turn on → choose the dedicated channel → copy the URL). The channel is chosen in Slack when you create the webhook, not here. Leave empty to turn off the notice.', 'featured-post-rotator' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bmfr-slack-lead"><?php esc_html_e( 'Notice time (hours before the rotation)', 'featured-post-rotator' ); ?></label></th>
					<td>
						<input type="number" min="1" max="168" step="1" id="bmfr-slack-lead" name="bmfr_slack_lead_hours" value="<?php echo esc_attr( $slack_settings['lead_hours'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'How long before the due rotation the notice arrives: it should leave enough time to act on the Preview page.', 'featured-post-rotator' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Slack settings', 'featured-post-rotator' ) ); ?>
		</form>

		<?php if ( ! empty( $slack_settings['webhook_url'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bmfr_send_slack_test" />
				<?php wp_nonce_field( 'bmfr_send_slack_test' ); ?>
				<?php submit_button( __( 'Send test notification', 'featured-post-rotator' ), 'secondary', 'submit', false ); ?>
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
							esc_html__( '%d posts, no tag: choose one in the Slots section', 'featured-post-rotator' ),
							(int) $slot['count']
						);
					} else {
						printf(
							/* translators: 1: number of posts shown in the slot, 2: WordPress tag slug */
							esc_html__( '%1$d posts, tag %2$s', 'featured-post-rotator' ),
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
							<th scope="row"><?php esc_html_e( 'Active', 'featured-post-rotator' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="bmfr[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
									<?php esc_html_e( 'Turn on automatic cron rotation for this slot', 'featured-post-rotator' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-cadence-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Cadence (days)', 'featured-post-rotator' ); ?></label></th>
							<td><input type="number" min="1" step="1" id="bmfr-cadence-<?php echo esc_attr( $slot_key ); ?>" name="bmfr[cadence_days]" value="<?php echo esc_attr( $settings['cadence_days'] ); ?>" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-cooldown-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Cooldown (days)', 'featured-post-rotator' ); ?></label></th>
							<td>
								<input type="number" min="0" step="1" id="bmfr-cooldown-<?php echo esc_attr( $slot_key ); ?>" name="bmfr[cooldown_days]" value="<?php echo esc_attr( $settings['cooldown_days'] ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'A post that has already been in this slot cannot come back before N days.', 'featured-post-rotator' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-minage-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Minimum post age (days)', 'featured-post-rotator' ); ?></label></th>
							<td><input type="number" min="0" step="1" id="bmfr-minage-<?php echo esc_attr( $slot_key ); ?>" name="bmfr[min_age_days]" value="<?php echo esc_attr( $settings['min_age_days'] ); ?>" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-halflife-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Recency half-life (days)', 'featured-post-rotator' ); ?></label></th>
							<td>
								<input type="number" min="1" step="1" id="bmfr-halflife-<?php echo esc_attr( $slot_key ); ?>" name="bmfr[recency_half_life_days]" value="<?php echo esc_attr( $settings['recency_half_life_days'] ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'Lower = strongly favors recent posts; higher = flatter score between old and new posts.', 'featured-post-rotator' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Maximum age', 'featured-post-rotator' ); ?></th>
							<td>
								<select name="bmfr[max_age_mode]" data-age-mode-select>
									<option value="relative" <?php selected( $settings['max_age_mode'], 'relative' ); ?>><?php esc_html_e( 'Relative: no older than N days from today', 'featured-post-rotator' ); ?></option>
									<option value="absolute" <?php selected( $settings['max_age_mode'], 'absolute' ); ?>><?php esc_html_e( 'Absolute: no older than a fixed date', 'featured-post-rotator' ); ?></option>
									<option value="none" <?php selected( $settings['max_age_mode'], 'none' ); ?>><?php esc_html_e( 'No limit', 'featured-post-rotator' ); ?></option>
								</select>
								<p data-age-field="relative">
									<label><?php esc_html_e( 'Days (used if "Relative")', 'featured-post-rotator' ); ?>
										<input type="number" min="0" step="1" name="bmfr[max_age_days]" value="<?php echo esc_attr( $settings['max_age_days'] ); ?>" class="small-text" />
									</label>
								</p>
								<p data-age-field="absolute">
									<label><?php esc_html_e( 'Date (used if "Absolute", format YYYY-MM-DD)', 'featured-post-rotator' ); ?>
										<input type="date" name="bmfr[max_age_date]" value="<?php echo esc_attr( $settings['max_age_date'] ); ?>" />
									</label>
								</p>
								<p class="description"><?php esc_html_e( 'Posts published before this threshold are never candidates for this slot.', 'featured-post-rotator' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Signal weights', 'featured-post-rotator' ); ?></th>
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
									<?php esc_html_e( 'Weights are normalized automatically (they do not need to add up to 1). If you set them all to 0, the plugin uses equal weights.', 'featured-post-rotator' ); ?>
									<?php
									printf(
										/* translators: %s: link to the Calendario stagionale admin page */
										esc_html__( '"Seasonal fit" rewards posts on topic with the current occasion, defined in the %s.', 'featured-post-rotator' ),
										'<a href="' . esc_url( admin_url( 'admin.php?page=bmfr-calendar' ) ) . '">' . esc_html__( 'Seasonal calendar', 'featured-post-rotator' ) . '</a>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-excluded-cats-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Excluded categories', 'featured-post-rotator' ); ?></label></th>
							<td>
								<input type="text" class="bmfr-category-filter" data-filter-target="bmfr-excluded-cats-<?php echo esc_attr( $slot_key ); ?>" placeholder="<?php esc_attr_e( 'Filter categories…', 'featured-post-rotator' ); ?>" />
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
										esc_html__( 'Posts in these categories will never be candidates for this slot (in addition to the %s).', 'featured-post-rotator' ),
										'<a href="' . esc_url( admin_url( 'admin.php?page=bmfr-exclusions' ) ) . '">' . esc_html__( 'global exclusions', 'featured-post-rotator' ) . '</a>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-excluded-posts-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Excluded posts (IDs)', 'featured-post-rotator' ); ?></label></th>
							<td>
								<textarea id="bmfr-excluded-posts-<?php echo esc_attr( $slot_key ); ?>" name="bmfr[excluded_post_ids]" rows="2" class="large-text" placeholder="123, 456, 789"><?php echo esc_textarea( implode( ', ', $settings['excluded_post_ids'] ) ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-recipients-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Email notification recipients', 'featured-post-rotator' ); ?></label></th>
							<td>
								<textarea id="bmfr-recipients-<?php echo esc_attr( $slot_key ); ?>" name="bmfr_recipients" rows="3" class="large-text" placeholder="nome@esempio.it"><?php echo esc_textarea( implode( "\n", $notification_settings['recipients'] ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One address per line. Shared by all slots: saving from any of the forms updates this list for all of them.', 'featured-post-rotator' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmfr-subject-prefix-<?php echo esc_attr( $slot_key ); ?>"><?php esc_html_e( 'Name in the email subject', 'featured-post-rotator' ); ?></label></th>
							<td>
								<input type="text" id="bmfr-subject-prefix-<?php echo esc_attr( $slot_key ); ?>" name="bmfr_subject_prefix" value="<?php echo esc_attr( $notification_settings['subject_prefix'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Shown in square brackets at the start of the subject, for example "[Name] Rotation …". Empty = site title. Shared by all slots.', 'featured-post-rotator' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( sprintf( /* translators: %s: slot label */ __( 'Save "%s" settings', 'featured-post-rotator' ), $slot['label'] ) ); ?>
				</form>
			</div>
		</details>
	<?php endforeach; ?>
</div>
