<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * First-run (and always-reachable) one-time sweep of every post that could
 * ever be candidable in either slot, split into three tabs so an operator
 * can work the whole list down to zero instead of re-checking it on every
 * visit:
 *
 * - "Da controllare": at least one cycle still undecided.
 * - "Candidati": no cycle left undecided, and at least one was "Mantieni".
 * - "Esclusi": globally excluded, or no cycle left undecided and none was
 *   "Mantieni" (i.e. "Escludi" everywhere it could run).
 *
 * Mantieni/Escludi are decided per cycle (BMFR_Slots slot), not for the
 * whole post: a post can be fine for Spotlight and wrong for Trend Posts at
 * the same time, so each slot pill carries its own decision and its own
 * score. See BMFR_Settings::add_slot_kept_post()/add_slot_excluded_post().
 *
 * The universe itself (BMFR_Selector::get_slot_candidate_pool()) ignores
 * cooldown/season/cross-slot on purpose, so a post doesn't bounce in and
 * out of "Da controllare" as those rotation states shift day to day — see
 * that method's docblock.
 */
class BMFR_Tour_Page {

	const PER_PAGE = 20;

	const TAB_REVIEW   = 'review';
	const TAB_KEPT     = 'kept';
	const TAB_EXCLUDED = 'excluded';

	public static function render() {
		if ( ! current_user_can( BMFR_Admin::VIEW_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'featured-post-rotator' ) );
		}

		$buckets = self::get_candidate_buckets();

		$valid_tabs  = array( self::TAB_REVIEW, self::TAB_KEPT, self::TAB_EXCLUDED );
		$current_tab = isset( $_GET['tour_tab'] ) ? sanitize_key( wp_unslash( $_GET['tour_tab'] ) ) : self::TAB_REVIEW; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$current_tab = in_array( $current_tab, $valid_tabs, true ) ? $current_tab : self::TAB_REVIEW;

		$tab_counts = array(
			self::TAB_REVIEW   => count( $buckets[ self::TAB_REVIEW ] ),
			self::TAB_KEPT     => count( $buckets[ self::TAB_KEPT ] ),
			self::TAB_EXCLUDED => count( $buckets[ self::TAB_EXCLUDED ] ),
		);

		$candidates  = $buckets[ $current_tab ];
		$total_count = count( $candidates );

		$total_pages  = max( 1, (int) ceil( $total_count / self::PER_PAGE ) );
		$current_page = isset( $_GET['tour_page'] ) ? max( 1, absint( $_GET['tour_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$current_page = min( $current_page, $total_pages );

		$offset          = ( $current_page - 1 ) * self::PER_PAGE;
		$page_candidates = array_slice( $candidates, $offset, self::PER_PAGE );
		$page_candidates = self::attach_cycle_view_data( $page_candidates );
		$is_welcome      = isset( $_GET['bmfr_notice'] ) && 'welcome' === $_GET['bmfr_notice']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.

		include BMFR_PLUGIN_DIR . 'admin/views/tour-page.php';
	}

	/**
	 * Union of the per-slot candidate pools, deduplicated, each post
	 * carrying the keys of every slot it falls into (a post can be
	 * in-window for both at once) — then split into the three tabs by
	 * comparing against the global exclusion list and each slot's own
	 * per-cycle decisions. Sorted newest-first within each tab since that's
	 * the most useful order to work through.
	 */
	private static function get_candidate_buckets() {
		$merged = array();

		foreach ( BMFR_Slots::get_slot_keys() as $slot_key ) {
			$settings = BMFR_Settings::get_slot_settings( $slot_key );
			$ids      = BMFR_Selector::get_slot_candidate_pool( $slot_key, $settings );

			foreach ( $ids as $post_id ) {
				if ( ! isset( $merged[ $post_id ] ) ) {
					$merged[ $post_id ] = array();
				}
				$merged[ $post_id ][] = $slot_key;
			}
		}

		$global_exclusions   = BMFR_Settings::get_global_exclusions();
		$excluded_post_ids   = array_map( 'absint', (array) $global_exclusions['excluded_post_ids'] );
		$excluded_categories = array_map( 'absint', (array) $global_exclusions['excluded_categories'] );

		$post_ids = array_keys( $merged );

		usort(
			$post_ids,
			static function ( $a, $b ) {
				return strcmp( get_post_field( 'post_date', $b ), get_post_field( 'post_date', $a ) );
			}
		);

		$buckets = array(
			self::TAB_REVIEW   => array(),
			self::TAB_KEPT     => array(),
			self::TAB_EXCLUDED => array(),
		);

		foreach ( $post_ids as $post_id ) {
			$slot_keys = $merged[ $post_id ];

			// The blunt, pre-existing global exclusion (Esclusioni page, post
			// editor metabox, Anteprima's "Escludi") always wins: it means
			// "never show this anywhere", independent of and prior to any
			// per-cycle decision made on this page.
			$is_globally_excluded = in_array( $post_id, $excluded_post_ids, true )
				|| ! empty( array_intersect( wp_get_post_categories( $post_id ), $excluded_categories ) );

			if ( $is_globally_excluded ) {
				$buckets[ self::TAB_EXCLUDED ][] = array(
					'post_id'          => $post_id,
					'slot_keys'        => $slot_keys,
					'global_exclusion' => true,
				);
				continue;
			}

			$row = array(
				'post_id'          => $post_id,
				'slot_keys'        => $slot_keys,
				'global_exclusion' => false,
			);

			if ( self::row_still_in_tab( $post_id, $slot_keys, self::TAB_REVIEW ) ) {
				$buckets[ self::TAB_REVIEW ][] = $row;
			} elseif ( self::row_still_in_tab( $post_id, $slot_keys, self::TAB_KEPT ) ) {
				$buckets[ self::TAB_KEPT ][] = $row;
			} else {
				$buckets[ self::TAB_EXCLUDED ][] = $row;
			}
		}

		return $buckets;
	}

	/**
	 * Single source of truth for "does this row still belong in this tab",
	 * shared by the initial render (get_candidate_buckets()) and every
	 * per-cycle AJAX action (so BMFR_Admin can tell the browser whether to
	 * remove the row in place after a decision changes).
	 */
	public static function row_still_in_tab( $post_id, array $slot_keys, $tab ) {
		$has_open = false;
		$has_kept = false;

		foreach ( $slot_keys as $slot_key ) {
			$decision = self::get_cycle_decision( $post_id, $slot_key );

			if ( 'open' === $decision ) {
				$has_open = true;
			} elseif ( 'kept' === $decision ) {
				$has_kept = true;
			}
		}

		if ( self::TAB_KEPT === $tab ) {
			return ! $has_open && $has_kept;
		}

		if ( self::TAB_EXCLUDED === $tab ) {
			return ! $has_open && ! $has_kept;
		}

		return $has_open;
	}

	/**
	 * This one post's decision for this one slot: 'excluded' if it's in
	 * that slot's own excluded_post_ids (the field that actually removes it
	 * from rotation), 'kept' if explicitly marked "Mantieni" for this slot
	 * (bookkeeping only), otherwise 'open' (not yet decided).
	 */
	public static function get_cycle_decision( $post_id, $slot_key ) {
		$settings = BMFR_Settings::get_slot_settings( $slot_key );
		$post_id  = absint( $post_id );

		if ( in_array( $post_id, array_map( 'absint', (array) $settings['excluded_post_ids'] ), true ) ) {
			return 'excluded';
		}

		if ( in_array( $post_id, array_map( 'absint', (array) $settings['kept_post_ids'] ), true ) ) {
			return 'kept';
		}

		return 'open';
	}

	/**
	 * Which slots (if any) a single post currently falls into, re-derived
	 * from each slot's own candidate pool rather than trusting anything the
	 * browser sends — used by the "apply to every open cycle" fast-path
	 * actions and by the per-cycle AJAX responses to know the row's full
	 * cycle set.
	 */
	public static function get_post_slot_keys( $post_id ) {
		$post_id = absint( $post_id );
		$keys    = array();

		foreach ( BMFR_Slots::get_slot_keys() as $slot_key ) {
			$settings = BMFR_Settings::get_slot_settings( $slot_key );
			$pool     = BMFR_Selector::get_slot_candidate_pool( $slot_key, $settings );

			if ( in_array( $post_id, $pool, true ) ) {
				$keys[] = $slot_key;
			}
		}

		return $keys;
	}

	/**
	 * Batch-scores this page's rows per slot (bounded by self::PER_PAGE, not
	 * the whole candidate pool) and attaches each row's per-cycle view data
	 * — label, score, manual boost, decision — so the view never has to
	 * touch BMFR_Settings/BMFR_Selector directly.
	 */
	private static function attach_cycle_view_data( array $rows ) {
		$ids_by_slot = array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row['global_exclusion'] ) ) {
				continue;
			}
			foreach ( $row['slot_keys'] as $slot_key ) {
				$ids_by_slot[ $slot_key ][] = $row['post_id'];
			}
		}

		$scores_by_slot = array();
		foreach ( $ids_by_slot as $slot_key => $ids ) {
			$settings                     = BMFR_Settings::get_slot_settings( $slot_key );
			$scores_by_slot[ $slot_key ] = BMFR_Selector::score_candidates( $slot_key, $settings, array_values( array_unique( $ids ) ) );
		}

		foreach ( $rows as &$row ) {
			$row['cycles'] = array();

			foreach ( $row['slot_keys'] as $slot_key ) {
				$slot = BMFR_Slots::get_slot( $slot_key );

				if ( ! empty( $row['global_exclusion'] ) ) {
					$row['cycles'][] = array(
						'key'   => $slot_key,
						'label' => $slot ? $slot['label'] : $slot_key,
					);
					continue;
				}

				$data = isset( $scores_by_slot[ $slot_key ][ $row['post_id'] ] ) ? $scores_by_slot[ $slot_key ][ $row['post_id'] ] : null;

				$row['cycles'][] = array(
					'key'          => $slot_key,
					'label'        => $slot ? $slot['label'] : $slot_key,
					'score'        => $data ? round( $data['final'], 2 ) : null,
					'manual_boost' => $data ? round( $data['manual_boost'], 2 ) : 0.0,
					'decision'     => self::get_cycle_decision( $row['post_id'], $slot_key ),
				);
			}
		}
		unset( $row );

		return $rows;
	}

	/**
	 * One no-JS-fallback form (real admin-post.php action + a
	 * data-bmfr-ajax-action JS hook), reused for every Tour button: row-level
	 * fast paths, per-cycle decisions, and the score stepper alike.
	 */
	public static function render_action_form( $action, $ajax_action, $button_label, $post_id, array $extra_fields = array(), $button_class = 'button button-small', $confirm_message = '' ) {
		ob_start();
		?>
		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			class="bmfr-tour-action-form"
			data-bmfr-ajax-action="<?php echo esc_attr( $ajax_action ); ?>"
			<?php echo $confirm_message ? 'data-bmfr-confirm="' . esc_attr( $confirm_message ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_attr() applied above when non-empty. ?>
		>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="exclusion_id" value="<?php echo esc_attr( $post_id ); ?>" />
			<?php foreach ( $extra_fields as $field_name => $field_value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $field_value ); ?>" />
			<?php endforeach; ?>
			<?php wp_nonce_field( 'bmfr_exclusion_action' ); ?>
			<button type="submit" class="<?php echo esc_attr( $button_class ); ?>"><?php echo esc_html( $button_label ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function format_signed( $value ) {
		$value = (float) $value;
		return ( $value > 0 ? '+' : '' ) . number_format_i18n( $value, 2 );
	}

	/**
	 * Small diverging bar on each Tour card (center = no correction, each
	 * half scaled to +/-BMFR_Scoring::TOTAL_BOOST_MAX, the largest shared +
	 * slot offset a post can carry), so the number reads as "how much room
	 * is in use" without dividing by the cap in your head.
	 */
	public static function render_boost_meter( $manual_boost ) {
		$max     = BMFR_Scoring::TOTAL_BOOST_MAX;
		$boost   = (float) $manual_boost;
		$percent = $max > 0 ? round( min( 100, ( abs( $boost ) / $max ) * 100 ) ) : 0;

		ob_start();
		?>
		<span class="bmfr-boost-meter" aria-hidden="true">
			<span class="bmfr-boost-meter-neg"><span class="bmfr-boost-meter-fill" style="width:<?php echo $boost < 0 ? esc_attr( $percent ) : 0; ?>%"></span></span>
			<span class="bmfr-boost-meter-center"></span>
			<span class="bmfr-boost-meter-pos"><span class="bmfr-boost-meter-fill" style="width:<?php echo $boost > 0 ? esc_attr( $percent ) : 0; ?>%"></span></span>
		</span>
		<?php
		return ob_get_clean();
	}

	/**
	 * The score line inside one cycle card: the number, the manual
	 * correction (value + meter) and the +/- stepper when the cycle is
	 * still live (open or kept — a kept post is still in the real rotation
	 * pool, only "excluded" makes the score moot). $current_tab rides along
	 * as a hidden field purely so BMFR_Admin::build_score_ajax_response()
	 * can pass the right tab back into the cycle_bottom fragment it
	 * rebuilds alongside this one.
	 */
	public static function render_cycle_score( $post_id, $slot_key, $score, $manual_boost, $decision, $current_tab = '' ) {
		$score_text = null !== $score ? number_format_i18n( $score, 2 ) : '—';
		$has_boost  = abs( (float) $manual_boost ) > 0.001;
		$score_live = 'excluded' !== $decision;

		ob_start();
		?>
		<span class="bmfr-cycle-score" data-bmfr-cycle-score>
			<?php echo esc_html( $score_text ); ?>
			<?php if ( $score_live ) : ?>
				<?php if ( $has_boost ) : ?>
					<?php
					$composition = sprintf(
						/* translators: 1: shared priority, 2: this slot's offset, both signed */
						__( 'Shared %1$s · this slot\'s adjustment %2$s. +/− here only changes the adjustment; the shared priority is set in Priority and weights.', 'featured-post-rotator' ),
						self::format_signed( BMFR_Settings::get_shared_priority( $post_id ) ),
						self::format_signed( BMFR_Settings::get_slot_offset( $slot_key, $post_id ) )
					);
					?>
					<span class="bmfr-cycle-manual <?php echo esc_attr( $manual_boost > 0 ? 'bmfr-cycle-manual-pos' : 'bmfr-cycle-manual-neg' ); ?>" title="<?php echo esc_attr( $composition ); ?>"><?php echo esc_html( self::format_signed( $manual_boost ) ); ?></span>
					<?php echo self::render_boost_meter( $manual_boost ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup from render_boost_meter(). ?>
				<?php endif; ?>
				<span class="bmfr-score-stepper">
					<?php echo self::render_action_form( 'bmfr_tour_adjust_score', 'bmfr_tour_adjust_score', '−', $post_id, array( 'slot' => $slot_key, 'delta' => (string) ( -1 * BMFR_Scoring::MANUAL_BOOST_STEP ), 'current_tab' => $current_tab ), 'bmfr-score-step' ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup from render_action_form(). ?>
					<?php echo self::render_action_form( 'bmfr_tour_adjust_score', 'bmfr_tour_adjust_score', '+', $post_id, array( 'slot' => $slot_key, 'delta' => (string) BMFR_Scoring::MANUAL_BOOST_STEP, 'current_tab' => $current_tab ), 'bmfr-score-step' ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup from render_action_form(). ?>
				</span>
				<?php if ( abs( BMFR_Settings::get_slot_offset( $slot_key, $post_id ) ) > 0.001 ) : ?>
					<?php echo self::render_action_form( 'bmfr_tour_reset_score', 'bmfr_tour_reset_score', __( 'reset', 'featured-post-rotator' ), $post_id, array( 'slot' => $slot_key, 'current_tab' => $current_tab ), 'bmfr-score-reset' ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup from render_action_form(). ?>
				<?php endif; ?>
			<?php endif; ?>
		</span>
		<?php
		return ob_get_clean();
	}

	/**
	 * The decision line inside one cycle card: Mantieni/Escludi while open,
	 * or the resolved label + a "cambia" link to reopen it once decided.
	 * $score/$manual_boost ride along as hidden fields purely so the AJAX
	 * response can rebuild the score line without re-scoring the post (see
	 * BMFR_Admin::build_cycle_ajax_response()) — they play no part in the
	 * decision itself.
	 */
	public static function render_cycle_bottom( $post_id, $slot_key, $decision, $current_tab, $score = null, $manual_boost = 0.0 ) {
		$score_fields = array(
			'score'        => null !== $score ? $score : '',
			'manual_boost' => $manual_boost,
		);

		ob_start();

		if ( 'open' === $decision ) {
			?>
			<div class="bmfr-cycle-mini-btns">
				<?php echo self::render_action_form( 'bmfr_tour_keep_cycle', 'bmfr_tour_keep_cycle', __( 'Keep', 'featured-post-rotator' ), $post_id, array_merge( array( 'slot' => $slot_key, 'current_tab' => $current_tab ), $score_fields ), 'bmfr-mini-keep' ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup from render_action_form(). ?>
				<?php echo self::render_action_form( 'bmfr_tour_exclude_cycle', 'bmfr_tour_exclude_cycle', __( 'Exclude', 'featured-post-rotator' ), $post_id, array_merge( array( 'slot' => $slot_key, 'current_tab' => $current_tab ), $score_fields ), 'bmfr-mini-exclude', __( 'Exclude this post from this cycle? If it is already featured here, it is removed immediately.', 'featured-post-rotator' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup from render_action_form(). ?>
			</div>
			<?php
		} else {
			?>
			<span class="bmfr-cycle-resolved-label">
				<?php echo 'kept' === $decision ? esc_html__( 'kept ✓', 'featured-post-rotator' ) : esc_html__( 'excluded ✕', 'featured-post-rotator' ); ?>
			</span>
			<?php echo self::render_action_form( 'bmfr_tour_reset_cycle', 'bmfr_tour_reset_cycle', __( 'swap', 'featured-post-rotator' ), $post_id, array_merge( array( 'slot' => $slot_key, 'current_tab' => $current_tab ), $score_fields ), 'bmfr-cycle-change' ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup from render_action_form(). ?>
			<?php
		}

		return ob_get_clean();
	}

	/**
	 * One full per-cycle card: name, score line, decision line. Used by the
	 * view for the initial render; the AJAX handlers re-render just the
	 * score/decision sub-parts via render_cycle_score()/render_cycle_bottom()
	 * instead of this whole wrapper, since only those two ever change in
	 * place.
	 */
	public static function render_cycle_card( $post_id, array $cycle, $current_tab ) {
		$decision   = $cycle['decision'];
		$card_class = 'bmfr-cycle-card';

		if ( 'kept' === $decision ) {
			$card_class .= ' bmfr-cycle-kept';
		} elseif ( 'excluded' === $decision ) {
			$card_class .= ' bmfr-cycle-excluded';
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( $card_class ); ?>" data-bmfr-cycle-card data-slot="<?php echo esc_attr( $cycle['key'] ); ?>">
			<div class="bmfr-cycle-top">
				<span class="bmfr-cycle-name"><?php echo esc_html( $cycle['label'] ); ?></span>
				<?php echo self::render_cycle_score( $post_id, $cycle['key'], $cycle['score'], $cycle['manual_boost'], $decision, $current_tab ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup from render_cycle_score(). ?>
			</div>
			<div class="bmfr-cycle-bottom" data-bmfr-cycle-bottom>
				<?php echo self::render_cycle_bottom( $post_id, $cycle['key'], $decision, $current_tab, $cycle['score'], $cycle['manual_boost'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup from render_cycle_bottom(). ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
