<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page-cache purge after the slot tags change, so the blog home doesn't
 * keep showing the previous featured posts until a cache expires.
 *
 * Every tag-changing path (run, top-up, swap, exclusion enforcement) fires
 * the public action `bmfr_rotation_applied`. This class listens to it and
 * purges once per request, at shutdown, however many slots changed.
 *
 * Only purges caches whose plugin is actually active (function_exists
 * checks), so on a site with no page cache plugin (e.g. only a CDN that
 * doesn't cache HTML) it does nothing. Disable entirely with
 * add_filter( 'bmfr_purge_page_cache', '__return_false' ), or hook
 * `bmfr_rotation_applied` yourself for a cache not listed here.
 */
class BMFR_Cache {

	private static $pending = false;

	public static function init() {
		add_action( 'bmfr_rotation_applied', array( __CLASS__, 'schedule_purge' ) );
	}

	public static function schedule_purge() {
		if ( self::$pending ) {
			return;
		}

		self::$pending = true;
		add_action( 'shutdown', array( __CLASS__, 'purge' ) );
	}

	public static function purge() {
		if ( ! apply_filters( 'bmfr_purge_page_cache', true ) ) {
			return;
		}

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		if ( has_action( 'breeze_clear_all_cache' ) ) {
			do_action( 'breeze_clear_all_cache' );
		}

		do_action( 'bmfr_page_cache_purged' );
	}
}
