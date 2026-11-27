<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Priorità e pesi": one page to tune the four scoring signals per slot and
 * to set each article's priority while seeing the whole candidate pool of
 * both slots at once.
 *
 * Priority model (v1.19.0, see BMFR_Settings::get_manual_score_boost()):
 * a post's correction in a slot = shared priority (one value for every slot)
 * + that slot's own small offset. The ranking table shows every post inside
 * a slot's age window, ranks the ones that are eligible right now, and lists
 * the others with the reason they're out.
 *
 * Re-ranking happens in the browser: the cached payload holds each post's
 * four raw signals per slot, never a final score, so the page can rebuild
 * the exact final score (weights from the sliders + current priority) on
 * every slider move, +/- click or drag without asking the server. Only
 * saving goes through AJAX (BMFR_Admin::ajax_priority_set()).
 *
 * The order is still a chance, not a guarantee: the real pick,
 * BMFR_Selector::weighted_sample(), draws weighted by that same score.
 */
class BMFR_Priority_Page {

	// Signals are normalized across the whole eligible pool (hundreds of
	// posts for Spotlight), too expensive to rebuild on every page view.
	// Priorities are NOT part of this cache: they're read fresh on every
	// render, so saving one never needs a recompute.
	const TRANSIENT_TTL = 5 * MINUTE_IN_SECONDS;

	// One color per BMFR_Scoring signal, shared by the weight sliders and
	// every row's composition bar. Order here is also each bar's stacking
	// order and the order of the 'sig' arrays in the payload.
	const SIGNAL_COLORS = array(
		'recency'          => '#2271b1',
		'category_balance' => '#7341b0',
		'engagement'       => '#0a7d6c',
		'seasonal_fit'     => '#a23e82',
	);

	public static function render() {
		if ( ! current_user_can( BMFR_Admin::VIEW_CAP ) ) {
			wp_die( esc_html__( 'Non hai i permessi per accedere a questa pagina.', 'featured-post-rotator' ) );
		}

		$weight_labels = self::get_weight_labels();
		$slots         = BMFR_Slots::get_slots();
		$slot_data     = array();

		foreach ( $slots as $slot_key => $slot ) {
			$slot_data[ $slot_key ] = self::get_ranking( $slot_key );
		}

		$client_data = self::build_client_data( $slots, $slot_data );

		include BMFR_PLUGIN_DIR . 'admin/views/priority-page.php';
	}

	public static function get_weight_labels() {
		return array(
			'recency'          => __( 'Recency', 'featured-post-rotator' ),
			'category_balance' => __( 'Bilanciamento categoria', 'featured-post-rotator' ),
			'engagement'       => __( 'Engagement', 'featured-post-rotator' ),
			'seasonal_fit'     => __( 'Adattamento stagionale', 'featured-post-rotator' ),
		);
	}

	/**
	 * Cached candidate data for one slot, computed on first view and kept
	 * for TRANSIENT_TTL after that.
	 */
	public static function get_ranking( $slot_key ) {
		$cached = get_transient( self::cache_key( $slot_key ) );

		if ( is_array( $cached ) && isset( $cached['posts'] ) ) {
			return $cached;
		}

		return self::recompute( $slot_key );
	}

	/**
	 * Every post inside the slot's age window: the eligible ones with their
	 * four raw signals, the others with the reason they can't be picked now.
	 */
	public static function recompute( $slot_key ) {
		$slot = BMFR_Slots::get_slot( $slot_key );

		if ( ! $slot ) {
			return null;
		}

		$settings   = BMFR_Settings::get_slot_settings( $slot_key );
		$warnings   = array();
		$candidates = BMFR_Selector::get_eligible_candidates( $slot_key, $settings, $warnings );
		$scored     = BMFR_Selector::score_candidates( $slot_key, $settings, $candidates );
		$window     = BMFR_Selector::get_slot_candidate_pool( $slot_key, $settings );
		$out_ids    = array_values( array_diff( $window, $candidates ) );
		$reasons    = self::out_reasons( $slot_key, $settings, $out_ids );

		$posts = array();

		foreach ( $scored as $post_id => $data ) {
			$b                 = $data['breakdown'];
			$posts[ $post_id ] = array(
				'sig' => array(
					round( (float) $b['recency'], 4 ),
					round( (float) $b['category_balance'], 4 ),
					round( (float) $b['engagement'], 4 ),
					round( (float) $b['seasonal_fit'], 4 ),
				),
			);
		}

		foreach ( $out_ids as $post_id ) {
			$posts[ $post_id ] = array(
				'out' => isset( $reasons[ $post_id ] ) ? $reasons[ $post_id ] : 'other',
			);
		}

		$result = array(
			'slot_key'        => $slot_key,
			'computed_at'     => time(),
			'candidate_count' => count( $candidates ),
			'window_count'    => count( $posts ),
			'posts'           => $posts,
		);

		set_transient( self::cache_key( $slot_key ), $result, self::TRANSIENT_TTL );

		return $result;
	}

	/**
	 * Why each in-window post isn't eligible right now, checked in the same
	 * order BMFR_Selector::get_eligible_candidates() filters them.
	 */
	private static function out_reasons( $slot_key, array $settings, array $post_ids ) {
		if ( empty( $post_ids ) ) {
			return array();
		}

		update_object_term_cache( $post_ids, 'post' );

		$global       = BMFR_Settings::get_global_exclusions();
		$ex_slot      = array_map( 'absint', (array) $settings['excluded_post_ids'] );
		$ex_global    = array_map( 'absint', (array) $global['excluded_post_ids'] );
		$ex_cats      = array_map( 'absint', array_merge( (array) $settings['excluded_categories'], (array) $global['excluded_categories'] ) );
		$live_here    = array_map( 'absint', BMFR_Rotator::get_currently_tagged_post_ids( $slot_key ) );
		$latest       = BMFR_Selector::get_latest_post_id();
		$out_season   = BMFR_Seasonal::get_out_of_season( $post_ids );
		$cooldown     = BMFR_History::get_posts_in_cooldown( $slot_key, (int) $settings['cooldown_days'] );
		$live_other   = array();

		foreach ( BMFR_Slots::get_slot_keys() as $other_key ) {
			if ( $other_key !== $slot_key ) {
				$live_other = array_merge( $live_other, array_map( 'absint', BMFR_Rotator::get_currently_tagged_post_ids( $other_key ) ) );
			}
		}

		$reasons = array();

		foreach ( $post_ids as $post_id ) {
			if ( in_array( $post_id, $live_here, true ) ) {
				$reason = 'live';
			} elseif ( $post_id === $latest ) {
				$reason = 'latest';
			} elseif ( in_array( $post_id, $ex_global, true ) ) {
				// Before the slot's own list: readmitting must lift the
				// global exclusion first, or the post would stay out.
				$reason = 'excluded_global';
			} elseif ( in_array( $post_id, $ex_slot, true ) ) {
				$reason = 'excluded';
			} elseif ( array_intersect( $ex_cats, wp_get_post_categories( $post_id ) ) ) {
				$reason = 'excluded_cat';
			} elseif ( isset( $out_season[ $post_id ] ) ) {
				$reason = 'season';
			} elseif ( in_array( $post_id, $live_other, true ) ) {
				$reason = 'other_slot';
			} elseif ( isset( $cooldown[ $post_id ] ) ) {
				$reason = 'cooldown';
			} else {
				$reason = 'other';
			}

			$reasons[ $post_id ] = $reason;
		}

		return $reasons;
	}

	/**
	 * Fresh table data for both slots, used after an exclusion or a
	 * readmission from the board: eligibility changed, and the category
	 * balance and engagement signals are normalized across the whole pool,
	 * so every post's signals have to come from a new computation.
	 */
	public static function get_client_payload() {
		$slots     = BMFR_Slots::get_slots();
		$slot_data = array();

		foreach ( array_keys( $slots ) as $slot_key ) {
			$slot_data[ $slot_key ] = self::recompute( $slot_key );
		}

		return self::build_client_data( $slots, $slot_data );
	}

	/**
	 * Everything the ranking table needs, merged across slots into one list
	 * of posts, plus every post's current priority (read fresh, not cached).
	 */
	private static function build_client_data( array $slots, array $slot_data ) {
		$posts     = array();
		$slot_meta = array();

		foreach ( $slots as $slot_key => $slot ) {
			$ranking = $slot_data[ $slot_key ];

			if ( ! $ranking ) {
				continue;
			}

			$settings = BMFR_Settings::get_slot_settings( $slot_key );
			$weights  = array();
			foreach ( array_keys( self::SIGNAL_COLORS ) as $signal ) {
				$weights[] = isset( $settings['weights'][ $signal ] ) ? (float) $settings['weights'][ $signal ] : 0.0;
			}

			$slot_meta[ $slot_key ] = array(
				'label'   => $slot['label'],
				'count'   => (int) $slot['count'],
				'weights' => $weights,
				'live'    => array_map( 'absint', BMFR_Rotator::get_currently_tagged_post_ids( $slot_key ) ),
			);

			foreach ( $ranking['posts'] as $post_id => $entry ) {
				$posts[ $post_id ]['s'][ $slot_key ] = $entry;
			}
		}

		$ids = array_map( 'absint', array_keys( $posts ) );
		_prime_post_caches( $ids, false, false );

		$shared  = BMFR_Settings::get_shared_priorities();
		$offsets = array();
		foreach ( array_keys( $slot_meta ) as $slot_key ) {
			$offsets[ $slot_key ] = BMFR_Settings::get_slot_offsets( $slot_key );
		}

		$list = array();

		foreach ( $posts as $post_id => $entry ) {
			$row = array(
				'id'  => (int) $post_id,
				't'   => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
				'url' => get_edit_post_link( $post_id, 'raw' ),
				'd'   => get_the_date( 'Y-m-d', $post_id ),
				's'   => $entry['s'],
				'p'   => isset( $shared[ $post_id ] ) ? (float) $shared[ $post_id ] : 0.0,
				'o'   => array(),
			);

			foreach ( array_keys( $slot_meta ) as $slot_key ) {
				$row['o'][ $slot_key ] = isset( $offsets[ $slot_key ][ $post_id ] ) ? (float) $offsets[ $slot_key ][ $post_id ] : 0.0;
			}

			$list[] = $row;
		}

		return array(
			'slots'   => $slot_meta,
			'posts'   => $list,
			'limits'  => array(
				'shared' => BMFR_Scoring::MANUAL_BOOST_MAX,
				'offset' => BMFR_Scoring::SLOT_OFFSET_MAX,
				'step'   => BMFR_Scoring::MANUAL_BOOST_STEP,
			),
			'colors'  => array_values( self::SIGNAL_COLORS ),
			'labels'  => array_values( self::get_weight_labels() ),
			'strings' => array(
				'title'        => __( 'Articolo', 'featured-post-rotator' ),
				'signals'      => __( 'Segnali', 'featured-post-rotator' ),
				'shared'       => __( 'Comune', 'featured-post-rotator' ),
				'onlySlot'     => __( 'Solo %s', 'featured-post-rotator' ),
				'score'        => __( 'Punt.', 'featured-post-rotator' ),
				'inWindow'     => __( '%d nella finestra', 'featured-post-rotator' ),
				'liveIn'       => __( 'in evidenza in %s', 'featured-post-rotator' ),
				'decrease'     => __( '%s: diminuisci', 'featured-post-rotator' ),
				'increase'     => __( '%s: aumenta', 'featured-post-rotator' ),
				'resetAll'     => __( 'Azzera la priorità di questo articolo', 'featured-post-rotator' ),
				'drag'         => __( 'Trascina per spostare', 'featured-post-rotator' ),
				'stays'        => __( '%1$s resta %2$s', 'featured-post-rotator' ),
				'moves'        => __( '%1$s %2$s → %3$s', 'featured-post-rotator' ),
				'out'          => __( 'fuori gara', 'featured-post-rotator' ),
				'saved'        => __( 'Salvato.', 'featured-post-rotator' ),
				'saving'       => __( 'Salvataggio…', 'featured-post-rotator' ),
				'saveFailed'   => __( 'Salvataggio non riuscito: la modifica è stata annullata. %s', 'featured-post-rotator' ),
				'undo'         => __( 'Annulla', 'featured-post-rotator' ),
				'undone'       => __( 'Modifica annullata.', 'featured-post-rotator' ),
				'offsetOnly'   => __( 'Usato solo il ritocco di %s: gli altri slot non cambiano.', 'featured-post-rotator' ),
				'offsetSpill'  => __( 'Il ritocco di slot non bastava: corretta anche la priorità comune, quindi cambiano anche gli altri slot.', 'featured-post-rotator' ),
				'maxReached'   => __( 'Posizione più vicina raggiungibile con i limiti attuali: %d.', 'featured-post-rotator' ),
				'limitShared'  => __( 'Priorità comune già al limite (±0,30).', 'featured-post-rotator' ),
				'limitOffset'  => __( 'Ritocco di slot già al limite (±0,15).', 'featured-post-rotator' ),
				'noResults'    => __( 'Nessun articolo corrisponde ai filtri.', 'featured-post-rotator' ),
				'empty'        => __( 'Nessun articolo nella finestra di questo slot.', 'featured-post-rotator' ),
				'priorityNote' => __( 'priorità %s', 'featured-post-rotator' ),
				'exclude'      => __( 'Escludi', 'featured-post-rotator' ),
				'excludeAsk'   => __( 'Escludere “%s”?', 'featured-post-rotator' ),
				'excludeSlot'  => __( 'Solo da %s', 'featured-post-rotator' ),
				'excludeAll'   => __( 'Da tutti gli slot', 'featured-post-rotator' ),
				'excludeLive'  => __( 'È in evidenza ora: viene tolto subito e sostituito con un altro articolo. Annullando l\'esclusione non torna in evidenza fino alla prossima rotazione.', 'featured-post-rotator' ),
				'cancel'       => __( 'Annulla', 'featured-post-rotator' ),
				'readmit'      => __( 'Riammetti', 'featured-post-rotator' ),
				'readmitHint'  => __( 'Togli l\'esclusione: l\'articolo torna sorteggiabile', 'featured-post-rotator' ),
				'catHint'      => __( 'Esclusa l\'intera categoria: si riammette dalla pagina Esclusioni o da Impostazioni', 'featured-post-rotator' ),
				'working'      => __( 'Aggiornamento della classifica…', 'featured-post-rotator' ),
				'excludedSlot' => __( '“%1$s” escluso da %2$s.', 'featured-post-rotator' ),
				'excludedAll'  => __( '“%s” escluso da tutti gli slot.', 'featured-post-rotator' ),
				'replaced'     => __( 'Era in evidenza: tolto e sostituito.', 'featured-post-rotator' ),
				'readmitted'   => __( '“%s” riammesso: torna sorteggiabile.', 'featured-post-rotator' ),
				'actionFailed' => __( 'Operazione non riuscita. %s', 'featured-post-rotator' ),
				'reasons'      => array(
					'live'       => __( 'in evidenza ora', 'featured-post-rotator' ),
					'latest'     => __( 'ultimo post pubblicato', 'featured-post-rotator' ),
					'excluded'        => __( 'escluso da questo slot', 'featured-post-rotator' ),
					'excluded_global' => __( 'escluso da tutti gli slot', 'featured-post-rotator' ),
					'excluded_cat'    => __( 'categoria esclusa', 'featured-post-rotator' ),
					'season'     => __( 'fuori stagione', 'featured-post-rotator' ),
					'other_slot' => __( 'in evidenza in un altro slot', 'featured-post-rotator' ),
					'cooldown'   => __( 'cooldown', 'featured-post-rotator' ),
					'other'      => __( 'non eleggibile ora', 'featured-post-rotator' ),
				),
			),
			'canEdit' => current_user_can( BMFR_Admin::WRITE_CAP ),
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'bmfr_priority_set' ),
		);
	}

	/**
	 * Called whenever anything the cached signals or eligibility depend on
	 * changes (weights, exclusions, rotations...) — see
	 * BMFR_Admin::invalidate_preview_cache(). Priority changes don't need it.
	 */
	public static function clear_cache( $slot_key = null ) {
		$keys = $slot_key ? array( $slot_key ) : BMFR_Slots::get_slot_keys();

		foreach ( $keys as $key ) {
			delete_transient( self::cache_key( $key ) );
		}
	}

	private static function cache_key( $slot_key ) {
		return 'bmfr_priority_pool_' . $slot_key . '_' . get_current_user_id();
	}

	/**
	 * Normalizes one slot's raw weights to shares that sum to 1 (or equal
	 * quarters if every weight is 0), the same rule BMFR_Scoring::combine()
	 * applies — used to pre-render the weight editor's percent readouts.
	 */
	public static function normalize_weights( array $weights ) {
		$sum   = array_sum( $weights );
		$share = array();

		foreach ( array_keys( self::SIGNAL_COLORS ) as $signal ) {
			$value            = isset( $weights[ $signal ] ) ? (float) $weights[ $signal ] : 0.0;
			$share[ $signal ] = $sum > 0 ? $value / $sum : 1 / count( self::SIGNAL_COLORS );
		}

		return $share;
	}
}
