<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of the featured slots. Since v1.21.0 the list is admin-editable
 * (Impostazioni → Slot) and stored in the OPTION row as
 * slot_key => array( label, tag_slug, count ).
 *
 * The slot key is generated once from the label when a slot is created and
 * never changes afterwards: every per-slot piece of data hangs off it
 * (bmfr_slot_settings_<key>, history.slot, lock names, priority offsets),
 * so label, tag and count can be edited freely while the data stays put.
 * Removing a slot only drops it from this list; its settings option and
 * history rows are kept, so re-creating a slot with the same key brings
 * them back.
 *
 * Until the option is saved (new installs, and installs that predate it),
 * the list is two slots with no tag: the admin picks one of the site's
 * existing tags for each in Impostazioni → Slot before they rotate.
 */
class BMFR_Slots {

	const OPTION = 'bmfr_slots';

	// Historical keys, kept as constants: BMFR_Settings still gives the
	// "spotlight" key its own default settings.
	const TREND_POSTS = 'trend_posts';
	const SPOTLIGHT    = 'spotlight';

	const MAX_SLOTS = 10;
	const MAX_COUNT = 20;
	// history.slot is VARCHAR(50); leave room for nothing else.
	const MAX_KEY_LENGTH = 40;

	private static $cache = null;

	/**
	 * Default list: two slots, no tag yet (chosen in Impostazioni among the
	 * site's existing tags). A slot without a tag never rotates.
	 */
	public static function get_default_definitions() {
		return array(
			self::TREND_POSTS => array(
				'label'    => 'Trend Posts',
				'tag_slug' => '',
				'count'    => 3,
			),
			self::SPOTLIGHT   => array(
				'label'    => 'Spotlight',
				'tag_slug' => '',
				'count'    => 4,
			),
		);
	}

	/**
	 * Raw stored definitions, or the default list when none is saved yet.
	 */
	public static function get_definitions() {
		$saved = get_option( self::OPTION, null );

		if ( is_array( $saved ) && ! empty( $saved ) ) {
			return $saved;
		}

		return self::get_default_definitions();
	}

	public static function get_slots() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$slots = array();

		foreach ( self::get_definitions() as $key => $definition ) {
			$key = sanitize_key( $key );

			if ( '' === $key || ! is_array( $definition ) ) {
				continue;
			}

			$label = isset( $definition['label'] ) ? (string) $definition['label'] : '';

			$slots[ $key ] = array(
				'key'      => $key,
				'label'    => '' !== $label ? $label : $key,
				'tag_slug' => isset( $definition['tag_slug'] ) ? sanitize_title( (string) $definition['tag_slug'] ) : '',
				'count'    => isset( $definition['count'] ) ? max( 1, min( self::MAX_COUNT, absint( $definition['count'] ) ) ) : 1,
			);
		}

		self::$cache = $slots;

		return $slots;
	}

	public static function get_slot( $slot_key ) {
		$slots = self::get_slots();
		return isset( $slots[ $slot_key ] ) ? $slots[ $slot_key ] : null;
	}

	public static function get_slot_keys() {
		return array_keys( self::get_slots() );
	}

	/**
	 * Fallback slot for pages opened without a valid ?slot=: the first one.
	 */
	public static function get_default_slot_key() {
		$keys = self::get_slot_keys();
		return $keys ? $keys[0] : '';
	}

	/**
	 * A slot can rotate only once it has a tag (new installs start without).
	 */
	public static function has_tag( $slot_key ) {
		$slot = self::get_slot( $slot_key );
		return $slot && '' !== $slot['tag_slug'];
	}

	public static function update_definitions( array $definitions ) {
		update_option( self::OPTION, $definitions, true );
		self::$cache = null;
	}

	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Validates the Impostazioni → Slot form against the current slots.
	 *
	 * @param array $rows     slot_key => array( label, tag_slug, count, remove ), existing slots only.
	 * @param array $new_row  array( label, tag_slug, count ) for an optional new slot.
	 * @param array $tag_slugs Slugs of the site's existing post tags.
	 * @return array array( 'error' => string|'', 'definitions' => array, 'retagged' => string[] slot keys whose tag changed )
	 */
	public static function sanitize_submission( array $rows, array $new_row, array $tag_slugs ) {
		$current     = self::get_slots();
		$definitions = array();
		$retagged    = array();

		foreach ( $current as $key => $slot ) {
			$row = isset( $rows[ $key ] ) && is_array( $rows[ $key ] ) ? $rows[ $key ] : array();

			if ( ! empty( $row['remove'] ) ) {
				continue;
			}

			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			$tag   = isset( $row['tag_slug'] ) ? sanitize_title( (string) $row['tag_slug'] ) : $slot['tag_slug'];

			// Only existing tags can be picked; the slot's current tag stays
			// valid even if it was deleted meanwhile (the rotation recreates it).
			if ( '' !== $tag && $tag !== $slot['tag_slug'] && ! in_array( $tag, $tag_slugs, true ) ) {
				return array( 'error' => 'slots_unknown_tag', 'definitions' => array(), 'retagged' => array() );
			}

			if ( $tag !== $slot['tag_slug'] && '' !== $slot['tag_slug'] ) {
				$retagged[] = $key;
			}

			$definitions[ $key ] = array(
				'label'    => '' !== $label ? $label : $slot['label'],
				'tag_slug' => $tag,
				'count'    => isset( $row['count'] ) ? max( 1, min( self::MAX_COUNT, absint( $row['count'] ) ) ) : $slot['count'],
			);
		}

		$new_label = isset( $new_row['label'] ) ? sanitize_text_field( (string) $new_row['label'] ) : '';

		if ( '' !== $new_label ) {
			if ( count( $definitions ) >= self::MAX_SLOTS ) {
				return array( 'error' => 'slots_too_many', 'definitions' => array(), 'retagged' => array() );
			}

			$new_tag = isset( $new_row['tag_slug'] ) ? sanitize_title( (string) $new_row['tag_slug'] ) : '';

			if ( '' !== $new_tag && ! in_array( $new_tag, $tag_slugs, true ) ) {
				return array( 'error' => 'slots_unknown_tag', 'definitions' => array(), 'retagged' => array() );
			}

			// Unique against every current key, removed-in-this-save included,
			// so a slot removed and added back in one go can't collide oddly.
			$new_key                 = self::generate_key( $new_label, array_keys( $current ) );
			$definitions[ $new_key ] = array(
				'label'    => $new_label,
				'tag_slug' => $new_tag,
				'count'    => isset( $new_row['count'] ) ? max( 1, min( self::MAX_COUNT, absint( $new_row['count'] ) ) ) : 3,
			);
		}

		if ( empty( $definitions ) ) {
			return array( 'error' => 'slots_none_left', 'definitions' => array(), 'retagged' => array() );
		}

		// Two slots on one tag would fight over the same posts.
		$used = array_filter( wp_list_pluck( $definitions, 'tag_slug' ), 'strlen' );

		if ( count( $used ) !== count( array_unique( $used ) ) ) {
			return array( 'error' => 'slots_duplicate_tag', 'definitions' => array(), 'retagged' => array() );
		}

		return array( 'error' => '', 'definitions' => $definitions, 'retagged' => $retagged );
	}

	/**
	 * Key for a new slot: from its label, made unique among the current
	 * slots. Deliberately NOT unique against orphaned settings options of
	 * removed slots: re-creating "Spotlight" brings its old data back.
	 */
	public static function generate_key( $label, array $taken ) {
		// sanitize_title() strips accents and turns spaces into hyphens
		// ("Novità e guide" -> novita-e-guide); keys use underscores.
		$base = sanitize_key( str_replace( '-', '_', sanitize_title( remove_accents( (string) $label ) ) ) );
		$base = trim( substr( $base, 0, self::MAX_KEY_LENGTH - 3 ), '_' );

		if ( '' === $base ) {
			$base = 'slot';
		}

		$key = $base;
		$n   = 2;

		while ( in_array( $key, $taken, true ) ) {
			$key = $base . '_' . $n;
			++$n;
		}

		return $key;
	}
}
