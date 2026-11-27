<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu registration, asset enqueue, and the admin-post.php handlers for the
 * three state-changing actions (save settings, dry-run, rotate now). Every
 * handler re-checks capability + nonce itself, since admin-post.php hooks
 * are reachable independent of whether the menu item is visible.
 */
class BMFR_Admin {

	const VIEW_CAP  = 'edit_others_posts';
	const WRITE_CAP = 'manage_options';

	/**
	 * Option flag set once by BMFR_Activator::activate() to send the admin to
	 * the Tour page right after the plugin is turned on — a one-shot nudge,
	 * not a gate. The page itself stays in the menu permanently, so nothing
	 * about returning here later depends on this flag.
	 */
	const TOUR_REDIRECT_OPTION = 'bmfr_tour_redirect_pending';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_to_tour' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_bmfr_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_bmfr_save_slots', array( __CLASS__, 'handle_save_slots' ) );
		add_action( 'admin_post_bmfr_save_cron_time', array( __CLASS__, 'handle_save_cron_time' ) );
		add_action( 'admin_post_bmfr_save_slack_settings', array( __CLASS__, 'handle_save_slack_settings' ) );
		add_action( 'admin_post_bmfr_send_slack_test', array( __CLASS__, 'handle_send_slack_test' ) );
		add_action( 'admin_post_bmfr_rotate_now', array( __CLASS__, 'handle_rotate_now' ) );
		add_action( 'admin_post_bmfr_dry_run', array( __CLASS__, 'handle_dry_run' ) );
		add_action( 'admin_post_bmfr_save_calendar', array( __CLASS__, 'handle_save_calendar' ) );
		add_action( 'admin_post_bmfr_add_global_exclusion', array( __CLASS__, 'handle_add_global_exclusion' ) );
		add_action( 'admin_post_bmfr_remove_global_exclusion', array( __CLASS__, 'handle_remove_global_exclusion' ) );
		add_action( 'admin_post_bmfr_exclude_post', array( __CLASS__, 'handle_exclude_post' ) );
		add_action( 'admin_post_bmfr_swap_post', array( __CLASS__, 'handle_swap_post' ) );
		add_action( 'admin_post_bmfr_tour_keep_post', array( __CLASS__, 'handle_tour_keep_post' ) );
		add_action( 'admin_post_bmfr_tour_exclude_all_open_cycles', array( __CLASS__, 'handle_tour_exclude_all_open_cycles' ) );
		add_action( 'admin_post_bmfr_tour_keep_cycle', array( __CLASS__, 'handle_tour_keep_cycle' ) );
		add_action( 'admin_post_bmfr_tour_exclude_cycle', array( __CLASS__, 'handle_tour_exclude_cycle' ) );
		add_action( 'admin_post_bmfr_tour_reset_cycle', array( __CLASS__, 'handle_tour_reset_cycle' ) );
		add_action( 'admin_post_bmfr_tour_adjust_score', array( __CLASS__, 'handle_tour_adjust_score' ) );
		add_action( 'admin_post_bmfr_tour_reset_score', array( __CLASS__, 'handle_tour_reset_score' ) );
		add_action( 'admin_post_bmfr_save_weights', array( __CLASS__, 'handle_save_weights' ) );
		add_action( 'admin_post_bmfr_priority_recalculate', array( __CLASS__, 'handle_priority_recalculate' ) );
		add_action( 'wp_ajax_bmfr_search_posts', array( __CLASS__, 'ajax_search_posts' ) );
		add_action( 'wp_ajax_bmfr_tour_exclude_post', array( __CLASS__, 'ajax_tour_exclude_post' ) );
		add_action( 'wp_ajax_bmfr_tour_unexclude_post', array( __CLASS__, 'ajax_tour_unexclude_post' ) );
		add_action( 'wp_ajax_bmfr_tour_keep_post', array( __CLASS__, 'ajax_tour_keep_post' ) );
		add_action( 'wp_ajax_bmfr_tour_exclude_all_open_cycles', array( __CLASS__, 'ajax_tour_exclude_all_open_cycles' ) );
		add_action( 'wp_ajax_bmfr_tour_keep_cycle', array( __CLASS__, 'ajax_tour_keep_cycle' ) );
		add_action( 'wp_ajax_bmfr_tour_exclude_cycle', array( __CLASS__, 'ajax_tour_exclude_cycle' ) );
		add_action( 'wp_ajax_bmfr_tour_reset_cycle', array( __CLASS__, 'ajax_tour_reset_cycle' ) );
		add_action( 'wp_ajax_bmfr_tour_adjust_score', array( __CLASS__, 'ajax_tour_adjust_score' ) );
		add_action( 'wp_ajax_bmfr_tour_reset_score', array( __CLASS__, 'ajax_tour_reset_score' ) );
		add_action( 'wp_ajax_bmfr_priority_set', array( __CLASS__, 'ajax_priority_set' ) );
		add_action( 'wp_ajax_bmfr_priority_exclusion', array( __CLASS__, 'ajax_priority_exclusion' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Post Rotator', 'featured-post-rotator' ),
			__( 'Post Rotator', 'featured-post-rotator' ),
			self::VIEW_CAP,
			'bmfr-settings',
			array( 'BMFR_Settings_Page', 'render' ),
			'dashicons-update'
		);

		add_submenu_page( 'bmfr-settings', __( 'Settings', 'featured-post-rotator' ), __( 'Settings', 'featured-post-rotator' ), self::VIEW_CAP, 'bmfr-settings', array( 'BMFR_Settings_Page', 'render' ) );
		add_submenu_page( 'bmfr-settings', __( 'Candidate tour', 'featured-post-rotator' ), __( 'Candidate tour', 'featured-post-rotator' ), self::VIEW_CAP, 'bmfr-tour', array( 'BMFR_Tour_Page', 'render' ) );
		add_submenu_page( 'bmfr-settings', __( 'Preview', 'featured-post-rotator' ), __( 'Preview', 'featured-post-rotator' ), self::VIEW_CAP, 'bmfr-preview', array( 'BMFR_Preview_Page', 'render' ) );
		add_submenu_page( 'bmfr-settings', __( 'Priority and weights', 'featured-post-rotator' ), __( 'Priority and weights', 'featured-post-rotator' ), self::VIEW_CAP, 'bmfr-priority', array( 'BMFR_Priority_Page', 'render' ) );
		add_submenu_page( 'bmfr-settings', __( 'History', 'featured-post-rotator' ), __( 'History', 'featured-post-rotator' ), self::VIEW_CAP, 'bmfr-history', array( __CLASS__, 'render_history_page' ) );
		add_submenu_page( 'bmfr-settings', __( 'Seasonal calendar', 'featured-post-rotator' ), __( 'Seasonal calendar', 'featured-post-rotator' ), self::VIEW_CAP, 'bmfr-calendar', array( 'BMFR_Calendar_Page', 'render' ) );
		add_submenu_page( 'bmfr-settings', __( 'Exclusions', 'featured-post-rotator' ), __( 'Exclusions', 'featured-post-rotator' ), self::VIEW_CAP, 'bmfr-exclusions', array( 'BMFR_Exclusions_Page', 'render' ) );
	}

	/**
	 * One-shot redirect to the Tour page right after activation, mirroring
	 * the common "setup wizard" pattern: fires once, never again, and skips
	 * bulk-activation (where landing on one plugin's screen for every plugin
	 * activated at once would be disruptive). The flag is deleted before the
	 * redirect, not after, so a redirect that never completes (browser back,
	 * closed tab) can't leave the nudge permanently stuck firing on every
	 * admin page.
	 */
	public static function maybe_redirect_to_tour() {
		if ( ! get_option( self::TOUR_REDIRECT_OPTION ) ) {
			return;
		}

		delete_option( self::TOUR_REDIRECT_OPTION );

		if ( wp_doing_ajax() || ! current_user_can( self::VIEW_CAP ) ) {
			return;
		}

		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, core's own bulk-activation marker.
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'bmfr-tour',
					'bmfr_notice' => 'welcome',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render_history_page() {
		if ( ! current_user_can( self::VIEW_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'featured-post-rotator' ) );
		}

		$list_table = new BMFR_History_List_Table();
		$list_table->prepare_items();

		include BMFR_PLUGIN_DIR . 'admin/views/history-page.php';
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'bmfr' ) ) {
			return;
		}

		wp_enqueue_style( 'bmfr-admin', BMFR_PLUGIN_URL . 'assets/admin.css', array(), BMFR_VERSION );
		wp_enqueue_script( 'bmfr-admin', BMFR_PLUGIN_URL . 'assets/admin.js', array(), BMFR_VERSION, true );
		wp_localize_script(
			'bmfr-admin',
			'bmfrAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'searchNonce' => wp_create_nonce( 'bmfr_search_posts' ),
				'strings'     => array(
					'confirm' => __( 'Confirm', 'featured-post-rotator' ),
					'cancel'  => __( 'Cancel', 'featured-post-rotator' ),
				),
			)
		);
	}

	public static function handle_save_settings() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		$slot_key = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';
		check_admin_referer( 'bmfr_save_settings_' . $slot_key );

		if ( ! BMFR_Slots::get_slot( $slot_key ) ) {
			wp_die( esc_html__( 'Invalid slot.', 'featured-post-rotator' ) );
		}

		$raw   = isset( $_POST['bmfr'] ) && is_array( $_POST['bmfr'] ) ? wp_unslash( $_POST['bmfr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$clean = BMFR_Settings::sanitize_slot_settings( $raw, $slot_key );
		BMFR_Settings::update_slot_settings( $slot_key, $clean );

		if ( isset( $_POST['bmfr_recipients'] ) ) {
			$notification_settings = BMFR_Settings::sanitize_notification_settings(
				wp_unslash( $_POST['bmfr_recipients'] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				isset( $_POST['bmfr_subject_prefix'] ) ? wp_unslash( $_POST['bmfr_subject_prefix'] ) : null // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			);
			BMFR_Settings::update_notification_settings( $notification_settings );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'bmfr-settings',
					'bmfr_notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Impostazioni → Slot: edits the slot list (label, tag, post count),
	 * removes slots and adds one. Validation lives in
	 * BMFR_Slots::sanitize_submission(); on any error nothing is saved.
	 * Removing a slot keeps its settings and history (see BMFR_Slots).
	 */
	public static function handle_save_slots() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_save_slots' );

		$rows    = isset( $_POST['bmfr_slots'] ) && is_array( $_POST['bmfr_slots'] ) ? wp_unslash( $_POST['bmfr_slots'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized field by field in sanitize_submission().
		$new_row = isset( $_POST['bmfr_new_slot'] ) && is_array( $_POST['bmfr_new_slot'] ) ? wp_unslash( $_POST['bmfr_new_slot'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- same.

		$tag_slugs = get_tags(
			array(
				'hide_empty' => false,
				'fields'     => 'id=>slug',
			)
		);
		$tag_slugs = is_array( $tag_slugs ) ? array_values( $tag_slugs ) : array();

		$result = BMFR_Slots::sanitize_submission( $rows, $new_row, $tag_slugs );

		if ( '' === $result['error'] ) {
			BMFR_Slots::update_definitions( $result['definitions'] );
			self::invalidate_preview_cache();
			$notice = empty( $result['retagged'] ) ? 'slots_saved' : 'slots_saved_retagged';
		} else {
			$notice = $result['error'];
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'bmfr-settings',
					'bmfr_notice' => $notice,
				),
				admin_url( 'admin.php' )
			) . '#bmfr-slots'
		);
		exit;
	}

	public static function handle_save_cron_time() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_save_cron_time' );

		$raw = isset( $_POST['bmfr_cron_time'] ) ? sanitize_text_field( wp_unslash( $_POST['bmfr_cron_time'] ) ) : '';
		BMFR_Settings::update_cron_time( $raw );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'bmfr-settings',
					'bmfr_notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_save_slack_settings() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_save_slack_settings' );

		$raw_url        = isset( $_POST['bmfr_slack_webhook_url'] ) ? wp_unslash( $_POST['bmfr_slack_webhook_url'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$raw_lead_hours = isset( $_POST['bmfr_slack_lead_hours'] ) ? wp_unslash( $_POST['bmfr_slack_lead_hours'] ) : 24;

		BMFR_Settings::update_slack_settings( BMFR_Settings::sanitize_slack_settings( $raw_url, $raw_lead_hours ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'bmfr-settings',
					'bmfr_notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_send_slack_test() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_send_slack_test' );

		$sent   = BMFR_Slack_Notifier::send_test_message();
		$notice = $sent ? 'slack_test_sent' : 'slack_test_failed';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'bmfr-settings',
					'bmfr_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Runs a real rotation for every slot the admin checked on the Anteprima
	 * page's single "Esegui ora" button — one or both of Trend/Spotlight —
	 * instead of always acting on whichever slot tab happens to be open.
	 */
	public static function handle_rotate_now() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_rotate_now' );

		$requested_slots = isset( $_POST['slots'] ) && is_array( $_POST['slots'] ) ? wp_unslash( $_POST['slots'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized via sanitize_key() below.
		$slot_keys       = array();

		foreach ( $requested_slots as $requested_slot ) {
			$slot_key = sanitize_key( $requested_slot );
			if ( BMFR_Slots::get_slot( $slot_key ) ) {
				$slot_keys[] = $slot_key;
			}
		}

		$current_slot = isset( $_POST['current_slot'] ) ? sanitize_key( wp_unslash( $_POST['current_slot'] ) ) : '';
		$current_slot = BMFR_Slots::get_slot( $current_slot ) ? $current_slot : BMFR_Slots::get_default_slot_key();

		if ( empty( $slot_keys ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'        => 'bmfr-preview',
						'slot'        => $current_slot,
						'bmfr_notice' => 'no_slot_selected',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$succeeded = 0;
		$failed    = 0;
		$locked    = 0;

		foreach ( $slot_keys as $slot_key ) {
			$result = BMFR_Rotator::run( $slot_key, 'manual', false );
			if ( is_wp_error( $result ) ) {
				++$failed;
				if ( 'bmfr_locked' === $result->get_error_code() ) {
					++$locked;
				}
			} else {
				++$succeeded;
			}
		}

		if ( 0 === $failed ) {
			$notice = 'rotated';
		} elseif ( 0 === $succeeded ) {
			$notice = ( $locked === $failed ) ? 'locked' : 'error';
		} else {
			$notice = 'rotated_partial';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'bmfr-preview',
					'slot'        => $current_slot,
					'bmfr_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_dry_run() {
		if ( ! current_user_can( self::VIEW_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		$slot_key = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';
		check_admin_referer( 'bmfr_dry_run_' . $slot_key );

		if ( ! BMFR_Slots::get_slot( $slot_key ) ) {
			wp_die( esc_html__( 'Invalid slot.', 'featured-post-rotator' ) );
		}

		$result = BMFR_Rotator::run( $slot_key, 'manual', true );

		if ( ! is_wp_error( $result ) ) {
			set_transient( 'bmfr_dry_run_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'bmfr-preview',
					'slot'        => $slot_key,
					'bmfr_notice' => 'preview',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_save_calendar() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_save_calendar' );

		$rows  = isset( $_POST['bmfr_calendar'] ) && is_array( $_POST['bmfr_calendar'] ) ? wp_unslash( $_POST['bmfr_calendar'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$clean = array();

		foreach ( $rows as $row ) {
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';

			// Blank label = an unused extra row; skip silently rather than
			// erroring the whole save for a row nobody filled in.
			if ( '' === $label ) {
				continue;
			}

			if ( ! empty( $row['delete'] ) ) {
				continue;
			}

			$start = isset( $row['start'] ) ? sanitize_text_field( $row['start'] ) : '';
			$end   = isset( $row['end'] ) ? sanitize_text_field( $row['end'] ) : '';

			// Invalid date window: skip just this row rather than failing the
			// whole save, so one typo doesn't discard every other entry.
			if ( ! preg_match( '/^\d{2}-\d{2}$/', $start ) || ! preg_match( '/^\d{2}-\d{2}$/', $end ) ) {
				continue;
			}

			$keywords  = array_map( 'strtolower', BMFR_Seasonal::parse_csv_list( isset( $row['keywords'] ) ? $row['keywords'] : '' ) );
			$tag_slugs = array_map( 'sanitize_title', BMFR_Seasonal::parse_csv_list( isset( $row['tag_slugs'] ) ? $row['tag_slugs'] : '' ) );

			$clean[] = array(
				'label'     => $label,
				'keywords'  => $keywords,
				'tag_slugs' => $tag_slugs,
				'start'     => $start,
				'end'       => $end,
				'enabled'   => ! empty( $row['enabled'] ),
			);
		}

		BMFR_Seasonal::update_calendar( $clean );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'bmfr-calendar',
					'bmfr_notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_add_global_exclusion() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_exclusion_action' );

		$type = isset( $_POST['exclusion_type'] ) ? sanitize_key( wp_unslash( $_POST['exclusion_type'] ) ) : '';
		$id   = isset( $_POST['exclusion_id'] ) ? absint( $_POST['exclusion_id'] ) : 0;

		if ( $id > 0 ) {
			if ( 'post' === $type ) {
				BMFR_Settings::add_global_excluded_post( $id );
			} elseif ( 'category' === $type ) {
				BMFR_Settings::add_global_excluded_category( $id );
			}

			BMFR_Rotator::enforce_exclusions();
			self::invalidate_preview_cache();
		}

		self::redirect_back( 'excluded' );
	}

	public static function handle_remove_global_exclusion() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_exclusion_action' );

		$type = isset( $_POST['exclusion_type'] ) ? sanitize_key( wp_unslash( $_POST['exclusion_type'] ) ) : '';
		$id   = isset( $_POST['exclusion_id'] ) ? absint( $_POST['exclusion_id'] ) : 0;

		if ( $id > 0 ) {
			if ( 'post' === $type ) {
				BMFR_Settings::remove_global_excluded_post( $id );
			} elseif ( 'category' === $type ) {
				BMFR_Settings::remove_global_excluded_category( $id );
			}

			self::invalidate_preview_cache();
		}

		self::redirect_back( 'unexcluded' );
	}

	/**
	 * The one-click "Escludi" action used from the Anteprima page, both on
	 * the "Attualmente in evidenza" list and on the dry-run IN/OUT/INVARIATI
	 * rows. Adds the post to the global exclusion list, then enforce_exclusions()
	 * strips it from any slot it is live in RIGHT NOW and immediately tops
	 * that slot back up — so excluding a post actually removes (and replaces)
	 * it on the site immediately rather than just blocking it starting next
	 * rotation.
	 */
	public static function handle_exclude_post() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_exclusion_action' );

		$post_id = isset( $_POST['exclusion_id'] ) ? absint( $_POST['exclusion_id'] ) : 0;

		if ( $post_id > 0 ) {
			BMFR_Settings::add_global_excluded_post( $post_id );
			BMFR_Rotator::enforce_exclusions();
			self::invalidate_preview_cache();
		}

		self::redirect_back( 'excluded' );
	}

	/**
	 * "Cambia" on the Preview page: replaces one live post in one slot with a
	 * fresh pick, without excluding it. See BMFR_Rotator::swap_out().
	 */
	public static function handle_swap_post() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_swap_post' );

		$post_id  = isset( $_POST['swap_id'] ) ? absint( $_POST['swap_id'] ) : 0;
		$slot_key = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';

		$swapped = BMFR_Rotator::swap_out( $slot_key, $post_id );
		self::invalidate_preview_cache();

		self::redirect_back( $swapped ? 'swapped' : 'error' );
	}

	/**
	 * Tour page fast path: "Mantieni" for every one of this post's cycles
	 * that is still undecided, leaving any cycle already decided (either
	 * way) untouched. Bookkeeping only — a kept cycle was already eligible,
	 * or it wouldn't be on the Tour at all.
	 */
	public static function handle_tour_keep_post() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_exclusion_action' );

		$post_id = isset( $_POST['exclusion_id'] ) ? absint( $_POST['exclusion_id'] ) : 0;

		if ( $post_id > 0 ) {
			self::mutate_keep_open_cycles( $post_id );
		}

		self::redirect_back( 'kept' );
	}

	/**
	 * Tour page fast path: "Escludi" for every one of this post's cycles
	 * that is still undecided — the counterpart of handle_tour_keep_post().
	 * Unlike the global "Escludi" above, this only touches the cycles that
	 * had no decision yet, never one already marked "Mantieni".
	 */
	public static function handle_tour_exclude_all_open_cycles() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_exclusion_action' );

		$post_id = isset( $_POST['exclusion_id'] ) ? absint( $_POST['exclusion_id'] ) : 0;

		if ( $post_id > 0 ) {
			self::mutate_exclude_open_cycles( $post_id );
		}

		self::redirect_back( 'excluded' );
	}

	/**
	 * Tour page, one cycle at a time: marks this post "Mantieni" for this
	 * one slot only, leaving every other cycle it belongs to untouched.
	 */
	public static function handle_tour_keep_cycle() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_exclusion_action' );

		$post_id = isset( $_POST['exclusion_id'] ) ? absint( $_POST['exclusion_id'] ) : 0;
		$slot    = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';

		if ( $post_id > 0 && BMFR_Slots::get_slot( $slot ) ) {
			self::mutate_keep_cycle( $post_id, $slot );
		}

		self::redirect_back( 'cycle_kept' );
	}

	/**
	 * Tour page, one cycle at a time: marks this post "Escludi" for this
	 * one slot only. Immediately strips the slot's live tag if the post is
	 * tagged there right now and tops that one slot back up — same
	 * production-safety guarantee as the global "Escludi", just scoped to
	 * a single cycle instead of everywhere.
	 */
	public static function handle_tour_exclude_cycle() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_exclusion_action' );

		$post_id = isset( $_POST['exclusion_id'] ) ? absint( $_POST['exclusion_id'] ) : 0;
		$slot    = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';

		if ( $post_id > 0 && BMFR_Slots::get_slot( $slot ) ) {
			self::mutate_exclude_cycle( $post_id, $slot );
		}

		self::redirect_back( 'cycle_excluded' );
	}

	/**
	 * Tour page "cambia": reopens one cycle's decision (Mantieni or
	 * Escludi) so it shows the two buttons again instead of the resolved
	 * label.
	 */
	public static function handle_tour_reset_cycle() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_exclusion_action' );

		$post_id = isset( $_POST['exclusion_id'] ) ? absint( $_POST['exclusion_id'] ) : 0;
		$slot    = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';

		if ( $post_id > 0 && BMFR_Slots::get_slot( $slot ) ) {
			self::mutate_reset_cycle( $post_id, $slot );
		}

		self::redirect_back( 'cycle_reset' );
	}

	/**
	 * Tour page score stepper: nudges one post's offset for one slot by
	 * +/-BMFR_Scoring::MANUAL_BOOST_STEP, clamped to +/-SLOT_OFFSET_MAX (the
	 * shared priority is set on the Priorità e pesi page). Affects the real rotation, not just this page's
	 * display — see BMFR_Selector::score_candidates().
	 */
	public static function handle_tour_adjust_score() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_exclusion_action' );

		$post_id = isset( $_POST['exclusion_id'] ) ? absint( $_POST['exclusion_id'] ) : 0;
		$slot    = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';
		$delta   = isset( $_POST['delta'] ) ? (float) wp_unslash( $_POST['delta'] ) : 0.0;

		if ( $post_id > 0 && BMFR_Slots::get_slot( $slot ) ) {
			BMFR_Settings::adjust_manual_score_boost( $slot, $post_id, $delta );
			self::invalidate_preview_cache();
		}

		self::redirect_back( 'score_adjusted' );
	}

	/**
	 * Tour page score "reset": drops the manual correction back to zero.
	 */
	public static function handle_tour_reset_score() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		check_admin_referer( 'bmfr_exclusion_action' );

		$post_id = isset( $_POST['exclusion_id'] ) ? absint( $_POST['exclusion_id'] ) : 0;
		$slot    = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';

		if ( $post_id > 0 && BMFR_Slots::get_slot( $slot ) ) {
			BMFR_Settings::reset_manual_score_boost( $slot, $post_id );
			self::invalidate_preview_cache();
		}

		self::redirect_back( 'score_reset' );
	}

	/**
	 * Priorità e pesi page: saves one slot's 4 signal weights. Deliberately
	 * its own tiny handler rather than reusing handle_save_settings()'s
	 * combined form — see BMFR_Settings::update_slot_weights()'s docblock
	 * for why posting weights alone through that handler would wipe every
	 * other field on this slot back to its default.
	 */
	public static function handle_save_weights() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		$slot_key = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';
		check_admin_referer( 'bmfr_save_weights_' . $slot_key );

		if ( ! BMFR_Slots::get_slot( $slot_key ) ) {
			wp_die( esc_html__( 'Invalid slot.', 'featured-post-rotator' ) );
		}

		$raw_weights = isset( $_POST['bmfr_weights'] ) && is_array( $_POST['bmfr_weights'] ) ? wp_unslash( $_POST['bmfr_weights'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast to float per key in update_slot_weights().

		BMFR_Settings::update_slot_weights( $slot_key, $raw_weights );
		self::invalidate_preview_cache();
		BMFR_Priority_Page::recompute( $slot_key );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'bmfr-priority',
					'bmfr_notice' => 'weights_saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Priorità e pesi page: forces a fresh ranking for one slot, bypassing
	 * the 5-minute cache (same "Ricalcola" idea as Anteprima's dry run).
	 */
	public static function handle_priority_recalculate() {
		if ( ! current_user_can( self::VIEW_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'featured-post-rotator' ) );
		}

		$slot_key = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';
		check_admin_referer( 'bmfr_priority_recalculate_' . $slot_key );

		if ( 'all' === $slot_key ) {
			foreach ( BMFR_Slots::get_slot_keys() as $key ) {
				BMFR_Priority_Page::recompute( $key );
			}
		} elseif ( BMFR_Slots::get_slot( $slot_key ) ) {
			BMFR_Priority_Page::recompute( $slot_key );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'bmfr-priority',
					'bmfr_notice' => 'priority_recalculated',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Priorità e pesi page: saves one post's priority as absolute values
	 * (shared priority and/or slot offsets), whether they came from a +/-
	 * click, a drag or an undo. The page has already re-ranked in the
	 * browser; this only persists and returns what was actually stored
	 * (clamped, rounded) so the page can correct itself if it differs.
	 *
	 * Only the dry-run preview cache is dropped: the Priorità cache holds
	 * raw signals, not priorities, so it stays valid.
	 */
	public static function ajax_priority_set() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'featured-post-rotator' ) ), 403 );
		}

		if ( ! check_ajax_referer( 'bmfr_priority_set', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired, please reload the page.', 'featured-post-rotator' ) ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( $post_id <= 0 || 'post' !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post.', 'featured-post-rotator' ) ) );
		}

		$shared  = isset( $_POST['shared'] ) && '' !== $_POST['shared'] ? (float) wp_unslash( $_POST['shared'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast to float, clamped in set_priority().
		$offsets = array();

		if ( isset( $_POST['offsets'] ) && is_array( $_POST['offsets'] ) ) {
			foreach ( wp_unslash( $_POST['offsets'] ) as $slot_key => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- keys checked against BMFR_Slots, values cast to float and clamped in set_priority().
				$slot_key = sanitize_key( $slot_key );
				if ( BMFR_Slots::get_slot( $slot_key ) ) {
					$offsets[ $slot_key ] = (float) $value;
				}
			}
		}

		$state = BMFR_Settings::set_priority( $post_id, $shared, $offsets );
		delete_transient( 'bmfr_dry_run_' . get_current_user_id() );

		wp_send_json_success(
			array(
				'post_id' => $post_id,
				'shared'  => $state['shared'],
				'offsets' => $state['offsets'],
			)
		);
	}

	/**
	 * Priorità e pesi page: exclude one post (from one slot or from every
	 * slot) or readmit it, then send back the whole table, recomputed.
	 * Unlike a priority change, an exclusion changes who is eligible, and
	 * the category balance and engagement signals are normalized across
	 * that pool, so the browser can't patch the table by itself.
	 *
	 * Same mutations as the Tour page: per-slot exclusion =
	 * mutate_exclude_cycle(), every slot = global excluded post. Both remove
	 * the post at once if it's featured now and top the slot up.
	 *
	 * ops: exclude_slot, exclude_all, readmit (lifts the global exclusion
	 * and this slot's own; a category exclusion is left alone).
	 */
	public static function ajax_priority_exclusion() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'featured-post-rotator' ) ), 403 );
		}

		if ( ! check_ajax_referer( 'bmfr_priority_set', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired, please reload the page.', 'featured-post-rotator' ) ), 403 );
		}

		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$slot_key = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';
		$op       = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		if ( $post_id <= 0 || 'post' !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post.', 'featured-post-rotator' ) ) );
		}

		if ( ! BMFR_Slots::get_slot( $slot_key ) || ! in_array( $op, array( 'exclude_slot', 'exclude_all', 'readmit' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid operation.', 'featured-post-rotator' ) ) );
		}

		$was_live = array();
		foreach ( BMFR_Slots::get_slot_keys() as $key ) {
			if ( in_array( $post_id, array_map( 'absint', BMFR_Rotator::get_currently_tagged_post_ids( $key ) ), true ) ) {
				$was_live[] = $key;
			}
		}

		if ( 'exclude_slot' === $op ) {
			self::mutate_exclude_cycle( $post_id, $slot_key );
		} elseif ( 'exclude_all' === $op ) {
			BMFR_Settings::add_global_excluded_post( $post_id );
			BMFR_Rotator::enforce_exclusions();
			self::invalidate_preview_cache();
		} else {
			BMFR_Settings::remove_global_excluded_post( $post_id );
			self::mutate_reset_cycle( $post_id, $slot_key );
		}

		$replaced = false;
		foreach ( $was_live as $key ) {
			if ( ! in_array( $post_id, array_map( 'absint', BMFR_Rotator::get_currently_tagged_post_ids( $key ) ), true ) ) {
				$replaced = true;
			}
		}

		wp_send_json_success(
			array(
				'post_id'  => $post_id,
				'op'       => $op,
				'replaced' => $replaced,
				'board'    => BMFR_Priority_Page::get_client_payload(),
			)
		);
	}

	/**
	 * Shared mutations behind the admin-post handlers above and their AJAX
	 * counterparts below, so the two never drift apart.
	 */
	private static function mutate_keep_open_cycles( $post_id ) {
		foreach ( BMFR_Tour_Page::get_post_slot_keys( $post_id ) as $slot_key ) {
			if ( 'open' === BMFR_Tour_Page::get_cycle_decision( $post_id, $slot_key ) ) {
				self::mutate_keep_cycle( $post_id, $slot_key );
			}
		}
	}

	private static function mutate_exclude_open_cycles( $post_id ) {
		foreach ( BMFR_Tour_Page::get_post_slot_keys( $post_id ) as $slot_key ) {
			if ( 'open' === BMFR_Tour_Page::get_cycle_decision( $post_id, $slot_key ) ) {
				self::mutate_exclude_cycle( $post_id, $slot_key );
			}
		}
	}

	private static function mutate_keep_cycle( $post_id, $slot_key ) {
		BMFR_Settings::add_slot_kept_post( $slot_key, $post_id );
		BMFR_Settings::remove_slot_excluded_post( $slot_key, $post_id );
		self::invalidate_preview_cache();
	}

	private static function mutate_exclude_cycle( $post_id, $slot_key ) {
		BMFR_Settings::add_slot_excluded_post( $slot_key, $post_id );
		BMFR_Settings::remove_slot_kept_post( $slot_key, $post_id );
		BMFR_Rotator::enforce_exclusions( $slot_key );
		self::invalidate_preview_cache();
	}

	private static function mutate_reset_cycle( $post_id, $slot_key ) {
		BMFR_Settings::remove_slot_kept_post( $slot_key, $post_id );
		BMFR_Settings::remove_slot_excluded_post( $slot_key, $post_id );
		self::invalidate_preview_cache();
	}

	/**
	 * Drops the current user's cached Anteprima dry-run, if any, so the next
	 * time they load that page BMFR_Preview_Page::render() recomputes it
	 * from scratch. Every write path that changes an exclusion list (this
	 * class's own handlers and BMFR_Post_Metabox::save()) must call this —
	 * otherwise a change made from the Esclusioni page, or from the post
	 * editor, stays invisible on Anteprima for as long as the cached
	 * forecast remains fresh, even though the exclusion itself is already
	 * in effect.
	 */
	public static function invalidate_preview_cache() {
		delete_transient( 'bmfr_dry_run_' . get_current_user_id() );
		BMFR_Priority_Page::clear_cache();
	}

	/**
	 * Shared nonce + post-id extraction for the Tour-page AJAX actions
	 * below. Reuses the exact same nonce ('bmfr_exclusion_action') already
	 * sitting in each form's hidden field — assets/admin.js reads it from
	 * the form itself rather than the page needing a second nonce wired
	 * through wp_localize_script. Dies with a JSON error (never returns)
	 * when the check fails.
	 */
	private static function require_tour_post_id() {
		if ( ! current_user_can( self::WRITE_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'featured-post-rotator' ) ), 403 );
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- verified by wp_verify_nonce() below.
		if ( ! wp_verify_nonce( $nonce, 'bmfr_exclusion_action' ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired, please reload the page.', 'featured-post-rotator' ) ), 403 );
		}

		$post_id = isset( $_POST['exclusion_id'] ) ? absint( $_POST['exclusion_id'] ) : 0;

		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post.', 'featured-post-rotator' ) ) );
		}

		return $post_id;
	}

	/**
	 * Same nonce/post-id guard as require_tour_post_id(), plus the slot_key
	 * every per-cycle action also needs. Dies with a JSON error (never
	 * returns) when the slot is missing or unknown.
	 */
	private static function require_tour_slot() {
		$slot_key = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';

		if ( ! BMFR_Slots::get_slot( $slot_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid cycle.', 'featured-post-rotator' ) ) );
		}

		return $slot_key;
	}

	/**
	 * AJAX counterpart of handle_exclude_post(), used only by the Tour page
	 * so excluding a post there removes its row in place instead of
	 * reloading the whole page.
	 */
	public static function ajax_tour_exclude_post() {
		$post_id = self::require_tour_post_id();

		BMFR_Settings::add_global_excluded_post( $post_id );
		BMFR_Rotator::enforce_exclusions();
		self::invalidate_preview_cache();

		wp_send_json_success( array( 'post_id' => $post_id ) );
	}

	/**
	 * AJAX counterpart of handle_remove_global_exclusion() (post type only),
	 * used by the "Riammetti" action on the Tour page's "Esclusi" tab.
	 */
	public static function ajax_tour_unexclude_post() {
		$post_id = self::require_tour_post_id();

		BMFR_Settings::remove_global_excluded_post( $post_id );
		self::invalidate_preview_cache();

		wp_send_json_success( array( 'post_id' => $post_id ) );
	}

	/**
	 * AJAX counterpart of handle_tour_keep_post(): row_resolved tells the
	 * browser whether to remove the row (true once no cycle is left open)
	 * or leave it in place.
	 */
	public static function ajax_tour_keep_post() {
		$post_id = self::require_tour_post_id();

		self::mutate_keep_open_cycles( $post_id );

		wp_send_json_success( self::build_row_ajax_response( $post_id ) );
	}

	/**
	 * AJAX counterpart of handle_tour_exclude_all_open_cycles().
	 */
	public static function ajax_tour_exclude_all_open_cycles() {
		$post_id = self::require_tour_post_id();

		self::mutate_exclude_open_cycles( $post_id );

		wp_send_json_success( self::build_row_ajax_response( $post_id ) );
	}

	/**
	 * AJAX counterpart of handle_tour_keep_cycle(): updates just this one
	 * cycle's card in place, or removes the whole row if this was the last
	 * open cycle (row_resolved).
	 */
	public static function ajax_tour_keep_cycle() {
		$post_id = self::require_tour_post_id();
		$slot    = self::require_tour_slot();

		self::mutate_keep_cycle( $post_id, $slot );

		wp_send_json_success( self::build_cycle_ajax_response( $post_id, $slot, 'kept' ) );
	}

	/**
	 * AJAX counterpart of handle_tour_exclude_cycle().
	 */
	public static function ajax_tour_exclude_cycle() {
		$post_id = self::require_tour_post_id();
		$slot    = self::require_tour_slot();

		self::mutate_exclude_cycle( $post_id, $slot );

		wp_send_json_success( self::build_cycle_ajax_response( $post_id, $slot, 'excluded' ) );
	}

	/**
	 * AJAX counterpart of handle_tour_reset_cycle().
	 */
	public static function ajax_tour_reset_cycle() {
		$post_id = self::require_tour_post_id();
		$slot    = self::require_tour_slot();

		self::mutate_reset_cycle( $post_id, $slot );

		wp_send_json_success( self::build_cycle_ajax_response( $post_id, $slot, 'open' ) );
	}

	/**
	 * AJAX counterpart of handle_tour_adjust_score(): the score really did
	 * change, so this rebuilds the score line with a fresh value.
	 */
	public static function ajax_tour_adjust_score() {
		$post_id = self::require_tour_post_id();
		$slot    = self::require_tour_slot();
		$delta   = isset( $_POST['delta'] ) ? (float) wp_unslash( $_POST['delta'] ) : 0.0;

		$manual_boost = BMFR_Settings::adjust_manual_score_boost( $slot, $post_id, $delta );
		self::invalidate_preview_cache();

		wp_send_json_success( self::build_score_ajax_response( $post_id, $slot, $manual_boost ) );
	}

	/**
	 * AJAX counterpart of handle_tour_reset_score().
	 */
	public static function ajax_tour_reset_score() {
		$post_id = self::require_tour_post_id();
		$slot    = self::require_tour_slot();

		BMFR_Settings::reset_manual_score_boost( $slot, $post_id );
		self::invalidate_preview_cache();

		wp_send_json_success( self::build_score_ajax_response( $post_id, $slot, 0.0 ) );
	}

	/**
	 * Response for the two row-level fast-path actions: no single cycle to
	 * patch, just whether the whole row leaves the tab currently being
	 * viewed.
	 */
	private static function build_row_ajax_response( $post_id ) {
		$current_tab = isset( $_POST['current_tab'] ) ? sanitize_key( wp_unslash( $_POST['current_tab'] ) ) : BMFR_Tour_Page::TAB_REVIEW;
		$slot_keys   = BMFR_Tour_Page::get_post_slot_keys( $post_id );

		return array(
			'post_id'      => $post_id,
			'row_resolved' => ! BMFR_Tour_Page::row_still_in_tab( $post_id, $slot_keys, $current_tab ),
		);
	}

	/**
	 * Response for the three per-cycle decision actions (keep/exclude/reset
	 * a single cycle). The score itself never changes here, so the score
	 * line is rebuilt from the client's own hidden fields — what was
	 * already on screen — instead of re-scoring the post.
	 */
	private static function build_cycle_ajax_response( $post_id, $slot_key, $decision ) {
		$current_tab  = isset( $_POST['current_tab'] ) ? sanitize_key( wp_unslash( $_POST['current_tab'] ) ) : BMFR_Tour_Page::TAB_REVIEW;
		$score        = isset( $_POST['score'] ) && '' !== $_POST['score'] ? (float) wp_unslash( $_POST['score'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast to float, display-only re-render.
		$manual_boost = isset( $_POST['manual_boost'] ) ? (float) wp_unslash( $_POST['manual_boost'] ) : 0.0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast to float, display-only re-render.
		$slot_keys    = BMFR_Tour_Page::get_post_slot_keys( $post_id );

		return array(
			'post_id'           => $post_id,
			'slot'              => $slot_key,
			'decision'          => $decision,
			'row_resolved'      => ! BMFR_Tour_Page::row_still_in_tab( $post_id, $slot_keys, $current_tab ),
			'cycle_score_html'  => BMFR_Tour_Page::render_cycle_score( $post_id, $slot_key, $score, $manual_boost, $decision, $current_tab ),
			'cycle_bottom_html' => BMFR_Tour_Page::render_cycle_bottom( $post_id, $slot_key, $decision, $current_tab, $score, $manual_boost ),
		);
	}

	/**
	 * Response for the score stepper/reset. Unlike build_cycle_ajax_response()
	 * the score really changed, so this re-scores the post via
	 * BMFR_Selector::score_candidates() — on just this one post, which means
	 * the category-balance/engagement signals (normally normalized across
	 * the whole candidate batch) fall back to their neutral midpoint here.
	 * The number can therefore drift very slightly from the one shown after
	 * the next full page load, which batches correctly. The real rotation
	 * never takes this shortcut — BMFR_Rotator always scores the full batch.
	 *
	 * Also rebuilds the decision line (cycle_bottom_html): its own forms
	 * carry the score/manual_boost as hidden fields (see
	 * render_cycle_bottom()), so after a nudge those need refreshing too —
	 * otherwise a later Mantieni/Escludi/cambia click on the same card would
	 * report the pre-nudge score back to build_cycle_ajax_response().
	 */
	private static function build_score_ajax_response( $post_id, $slot_key, $manual_boost ) {
		$settings    = BMFR_Settings::get_slot_settings( $slot_key );
		$scored      = BMFR_Selector::score_candidates( $slot_key, $settings, array( $post_id ) );
		$decision    = BMFR_Tour_Page::get_cycle_decision( $post_id, $slot_key );
		$score       = isset( $scored[ $post_id ] ) ? round( $scored[ $post_id ]['final'], 2 ) : null;
		$current_tab = isset( $_POST['current_tab'] ) ? sanitize_key( wp_unslash( $_POST['current_tab'] ) ) : BMFR_Tour_Page::TAB_REVIEW;

		return array(
			'post_id'           => $post_id,
			'slot'              => $slot_key,
			'cycle_score_html'  => BMFR_Tour_Page::render_cycle_score( $post_id, $slot_key, $score, $manual_boost, $decision, $current_tab ),
			'cycle_bottom_html' => BMFR_Tour_Page::render_cycle_bottom( $post_id, $slot_key, $decision, $current_tab, $score, $manual_boost ),
		);
	}

	public static function ajax_search_posts() {
		check_ajax_referer( 'bmfr_search_posts', 'nonce' );

		if ( ! current_user_can( self::VIEW_CAP ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';

		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array() );
		}

		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				's'              => $term,
				'posts_per_page' => 10,
				'no_found_rows'  => true,
			)
		);

		$results = array();
		foreach ( $posts as $post ) {
			$results[] = array(
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
			);
		}

		wp_send_json_success( $results );
	}

	/**
	 * Redirects back to whatever admin page the form was submitted from
	 * (exclusions, preview...) so these exclusion actions work the same way
	 * regardless of which screen triggers them.
	 */
	private static function redirect_back( $notice ) {
		$target = wp_get_referer();

		if ( ! $target ) {
			$target = admin_url( 'admin.php?page=bmfr-exclusions' );
		}

		$target = add_query_arg( 'bmfr_notice', $notice, remove_query_arg( 'bmfr_notice', $target ) );

		wp_safe_redirect( $target );
		exit;
	}

	public static function render_notices() {
		if ( empty( $_GET['bmfr_notice'] ) || empty( $_GET['page'] ) || 0 !== strpos( sanitize_key( wp_unslash( $_GET['page'] ) ), 'bmfr' ) ) {
			return;
		}

		$notice   = sanitize_key( wp_unslash( $_GET['bmfr_notice'] ) );
		$messages = array(
			'saved'   => array( 'success', __( 'Settings saved.', 'featured-post-rotator' ) ),
			'rotated' => array( 'success', __( 'Rotation completed.', 'featured-post-rotator' ) ),
			'rotated_partial' => array( 'error', __( 'Rotation completed for only some of the selected slots: check the history for details.', 'featured-post-rotator' ) ),
			'no_slot_selected' => array( 'error', __( 'Select at least one slot before running the rotation.', 'featured-post-rotator' ) ),
			'slots_saved'          => array( 'success', __( 'Slots saved.', 'featured-post-rotator' ) ),
			'slots_saved_retagged' => array( 'warning', __( 'Slots saved. You changed a slot\'s tag: posts that had the previous tag keep it, so remove it by hand if they should no longer appear in the block. The next rotation will use the new tag.', 'featured-post-rotator' ) ),
			'slots_unknown_tag'    => array( 'error', __( 'Tag not found: choose one of the existing tags. No changes saved.', 'featured-post-rotator' ) ),
			'slots_duplicate_tag'  => array( 'error', __( 'Two slots cannot use the same tag. No changes saved.', 'featured-post-rotator' ) ),
			'slots_none_left'      => array( 'error', __( 'At least one slot must remain. No changes saved.', 'featured-post-rotator' ) ),
			'slots_too_many'       => array( 'error', __( 'You have reached the maximum number of slots. No changes saved.', 'featured-post-rotator' ) ),
			'preview' => array( 'info', __( 'Preview calculated.', 'featured-post-rotator' ) ),
			'error'   => array( 'error', __( 'An error occurred during the rotation.', 'featured-post-rotator' ) ),
			'locked'  => array( 'error', __( 'A rotation of this slot is already running (probably the cron): nothing was changed. Try again in a few minutes.', 'featured-post-rotator' ) ),
			'excluded'   => array( 'success', __( 'Exclusion added. If something was already featured and has been removed, the slot was immediately topped up with a replacement.', 'featured-post-rotator' ) ),
			'swapped'    => array( 'success', __( 'Post replaced in the slot with a new candidate. It was not excluded: it can still be picked in future rotations.', 'featured-post-rotator' ) ),
			'unexcluded' => array( 'success', __( 'Exclusion removed.', 'featured-post-rotator' ) ),
			'kept'             => array( 'success', __( '"Keep" applied to every cycle of this post still to be decided.', 'featured-post-rotator' ) ),
			'cycle_kept'       => array( 'success', __( 'Cycle kept.', 'featured-post-rotator' ) ),
			'cycle_excluded'   => array( 'success', __( 'Cycle excluded. If the post was already featured there, the slot was immediately topped up with a replacement.', 'featured-post-rotator' ) ),
			'cycle_reset'      => array( 'success', __( 'Cycle decision undone: it is back among those to review.', 'featured-post-rotator' ) ),
			'score_adjusted'   => array( 'success', __( 'Score adjusted manually.', 'featured-post-rotator' ) ),
			'score_reset'      => array( 'success', __( 'Manual score adjustment removed.', 'featured-post-rotator' ) ),
			'weights_saved'          => array( 'success', __( 'Weights saved.', 'featured-post-rotator' ) ),
			'priority_recalculated'  => array( 'success', __( 'Ranking recalculated.', 'featured-post-rotator' ) ),
			'slack_test_sent'   => array( 'success', __( 'Test message sent to Slack: check the channel connected to the webhook.', 'featured-post-rotator' ) ),
			'slack_test_failed' => array( 'error', __( 'Sending failed. Check that the webhook URL is correct and saved.', 'featured-post-rotator' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			list( $type, $text ) = $messages[ $notice ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}
}
