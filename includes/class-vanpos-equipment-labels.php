<?php
/**
 * Canonical additional_equipment labels for WPML languages (key => label).
 *
 * Stored meta may use canonical keys (e.g. kitchen) or any localized label;
 * resolution uses these maps for the active WPML language (nl / en / de).
 * Values are always normalized to canonical snake_case keys on save.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Equipment label maps and WPML-aware resolution.
 */
class VanPOS_Equipment_Labels {

	/**
	 * English labels.
	 *
	 * @var array<string, string>
	 */
	private static $map_en = array(
		'sleep_roof'             => 'Sleep Roof',
		'roof_hatch'             => 'Roof Hatch',
		'roof_fan'               => 'Roof Fan',
		'solar_panels'           => 'Solar Panels',
		'bike_rack'              => 'Bike Rack',
		'awning'                 => 'Awning',
		'diesel_heater'          => 'Diesel Heater',
		'electric_floor_heating' => 'Electric Floor Heating',
		'outdoor_shower'         => 'Outdoor Shower',
		'bathroom'               => 'Bathroom',
		'kitchen'                => 'Kitchen',
	);

	/**
	 * Dutch labels.
	 *
	 * @var array<string, string>
	 */
	private static $map_nl = array(
		'sleep_roof'             => 'Slaaphefdak',
		'roof_hatch'             => 'Dakluik(en)',
		'roof_fan'               => 'Dakventilator',
		'solar_panels'           => 'Zonnepanelen',
		'bike_rack'              => 'Fietsendrager',
		'awning'                 => 'Luifel',
		'diesel_heater'          => 'Dieselverwarming',
		'electric_floor_heating' => 'Elektrische vloerverwarming',
		'outdoor_shower'         => 'Buitendouche',
		'bathroom'               => 'Badkamer',
		'kitchen'                => 'Keuken',
	);

	/**
	 * German labels.
	 *
	 * @var array<string, string>
	 */
	private static $map_de = array(
		'sleep_roof'             => 'Schlafdach',
		'roof_hatch'             => 'Dachluke(n)',
		'roof_fan'               => 'Dachlüfter',
		'solar_panels'           => 'Solarpanele',
		'bike_rack'              => 'Fahrradträger',
		'awning'                 => 'Markise',
		'diesel_heater'          => 'Dieselheizung',
		'electric_floor_heating' => 'Elektrische Fußbodenheizung',
		'outdoor_shower'         => 'Außendusche',
		'bathroom'               => 'Badezimmer',
		'kitchen'                => 'Küche',
	);

	/**
	 * Genuine free-text aliases NOT already covered by label normalization.
	 *
	 * Most former aliases (e.g. "solar panels", "roof hatches", "diesel heater")
	 * now resolve automatically: normalize_lookup() reduces both the input and
	 * the localized labels to the same canonical form, so they match without an
	 * explicit entry. Only variants that do NOT reduce to a known label need to
	 * live here (e.g. "sleeping roof" vs. the label "Sleep Roof").
	 *
	 * @var array<string, string>
	 */
	private static $aliases = array(
		'sleeping roof' => 'sleep_roof',
	);

	/**
	 * Cached reverse lookup: normalized label/alias => canonical key.
	 *
	 * @var array<string, string>|null
	 */
	private static $reverse_cache = null;

	/**
	 * Register ACF/SCF hooks for additional_equipment key/label handling.
	 *
	 * @return void
	 */
	public static function init() {
		// Global hooks with field detection: robust when the field name differs across exports/translations.
		add_filter( 'acf/load_field', array( __CLASS__, 'filter_acf_field_choices' ), 20 );
		add_filter( 'acf/load_value', array( __CLASS__, 'filter_acf_load_value_to_keys' ), 20, 3 );
		add_filter( 'acf/update_value', array( __CLASS__, 'filter_acf_update_value_to_keys' ), 20, 3 );
	}

	/**
	 * Active WPML language code: nl, en, or de (fallback en).
	 *
	 * @return string
	 */
	public static function get_language_code() {
		// WPML post-edit screens provide explicit language in query params.
		if ( isset( $_GET['lang'] ) ) {
			$requested = strtolower( substr( sanitize_text_field( wp_unslash( $_GET['lang'] ) ), 0, 2 ) );
			if ( in_array( $requested, array( 'nl', 'de', 'en' ), true ) ) {
				return $requested;
			}
		}
		$code = apply_filters( 'wpml_current_language', null );
		if ( empty( $code ) && defined( 'ICL_LANGUAGE_CODE' ) ) {
			$code = ICL_LANGUAGE_CODE;
		}
		if ( empty( $code ) ) {
			return self::language_from_locale();
		}
		$code = strtolower( substr( (string) $code, 0, 2 ) );
		if ( in_array( $code, array( 'nl', 'de', 'en' ), true ) ) {
			return $code;
		}
		return 'en';
	}

	/**
	 * Infer nl/de/en from WordPress locale when WPML is not active.
	 *
	 * @return string
	 */
	private static function language_from_locale() {
		$locale = get_locale();
		if ( 0 === strpos( $locale, 'nl' ) ) {
			return 'nl';
		}
		if ( 0 === strpos( $locale, 'de' ) ) {
			return 'de';
		}
		return 'en';
	}

	/**
	 * Label map for the current language.
	 *
	 * @return array<string, string>
	 */
	public static function get_map() {
		return self::get_map_by_lang( self::get_language_code() );
	}

	/**
	 * Map for a fixed language code (nl|en|de).
	 *
	 * @param string $lang Language code.
	 * @return array<string, string>
	 */
	private static function get_map_by_lang( $lang ) {
		switch ( $lang ) {
			case 'nl':
				return self::$map_nl;
			case 'de':
				return self::$map_de;
			default:
				return self::$map_en;
		}
	}

	/**
	 * Active-language label for a canonical key (falls back to the key itself).
	 *
	 * @param string $key Canonical snake_case key.
	 * @return string
	 */
	public static function get_label( $key ) {
		$map = self::get_map();
		return isset( $map[ $key ] ) ? $map[ $key ] : (string) $key;
	}

	/**
	 * ACF/SCF field filter: set choices to stable keys with active-language labels.
	 *
	 * @param array $field ACF/SCF field.
	 * @return array
	 */
	public static function filter_acf_field_choices( $field ) {
		if ( ! self::is_equipment_field( $field ) ) {
			return $field;
		}
		$field['choices'] = self::get_map();
		// For checkbox/select style fields, returning value keeps keys in data APIs.
		if ( isset( $field['return_format'] ) ) {
			$field['return_format'] = 'value';
		}
		return $field;
	}

	/**
	 * ACF/SCF load_value filter: map legacy labels/aliases into canonical keys.
	 *
	 * @param mixed $value   Loaded value.
	 * @param mixed $post_id Post ID.
	 * @param mixed $field   Field config.
	 * @return mixed
	 */
	public static function filter_acf_load_value_to_keys( $value, $post_id, $field ) {
		if ( ! self::is_equipment_field( $field ) ) {
			return $value;
		}
		return self::map_items_to_keys( $value );
	}

	/**
	 * ACF/SCF update_value filter: always store canonical keys.
	 *
	 * @param mixed $value   Submitted value.
	 * @param mixed $post_id Post ID.
	 * @param mixed $field   Field config.
	 * @return mixed
	 */
	public static function filter_acf_update_value_to_keys( $value, $post_id, $field ) {
		if ( ! self::is_equipment_field( $field ) ) {
			return $value;
		}
		return self::map_items_to_keys( $value );
	}

	/**
	 * Normalize a scalar or array of equipment values into unique canonical keys.
	 *
	 * Unrecognized items are preserved as-is so no data is silently dropped.
	 *
	 * @param mixed $value Raw value (scalar or array).
	 * @return mixed Original value when empty, otherwise an array of keys.
	 */
	private static function map_items_to_keys( $value ) {
		if ( empty( $value ) ) {
			return $value;
		}
		$items = is_array( $value ) ? $value : array( $value );
		$out   = array();
		foreach ( $items as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}
			$key   = self::find_canonical_key( $item );
			$out[] = $key ? $key : $item;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Detect whether an ACF/SCF field is additional_equipment.
	 *
	 * @param mixed $field Field config.
	 * @return bool
	 */
	private static function is_equipment_field( $field ) {
		if ( ! is_array( $field ) ) {
			return false;
		}
		$name = isset( $field['name'] ) ? (string) $field['name'] : '';
		$key  = isset( $field['key'] ) ? (string) $field['key'] : '';
		if ( 'additional_equipment' === $name ) {
			return true;
		}
		if ( ! empty( $key ) && isset( $field['choices'] ) && is_array( $field['choices'] ) ) {
			// Heuristic fallback for installations where the name differs: canonical keys present.
			$choices = array_keys( $field['choices'] );
			$hits    = array_intersect(
				$choices,
				array( 'sleep_roof', 'roof_hatch', 'roof_fan', 'solar_panels', 'bike_rack', 'kitchen' )
			);
			if ( count( $hits ) >= 3 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve a stored value (canonical key or any localized label/alias) to a key.
	 *
	 * @param string $value Raw meta / field value.
	 * @return string|null Canonical snake_case key, or null when unresolved.
	 */
	public static function find_canonical_key( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		// Already a canonical key.
		if ( isset( self::$map_en[ $value ] ) ) {
			return $value;
		}

		$reverse    = self::reverse_map();
		$normalized = self::normalize_lookup( $value );

		return isset( $reverse[ $normalized ] ) ? $reverse[ $normalized ] : null;
	}

	/**
	 * Build (and cache) the normalized label/alias => canonical key lookup.
	 *
	 * Labels are added first, then aliases, so an alias takes precedence on any
	 * normalized-form collision (matching the original resolution order).
	 *
	 * @return array<string, string>
	 */
	private static function reverse_map() {
		if ( null !== self::$reverse_cache ) {
			return self::$reverse_cache;
		}

		$reverse = array();
		foreach ( array( 'en', 'nl', 'de' ) as $lang ) {
			foreach ( self::get_map_by_lang( $lang ) as $key => $label ) {
				$reverse[ self::normalize_lookup( $label ) ] = $key;
			}
		}
		foreach ( self::$aliases as $alias => $key ) {
			$reverse[ self::normalize_lookup( $alias ) ] = $key;
		}

		self::$reverse_cache = $reverse;
		return self::$reverse_cache;
	}

	/**
	 * Normalize text for tolerant equipment matching.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function normalize_lookup( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = str_replace( array( '(s)', '(en)' ), '', $value );
		$value = str_replace( array( '_', '-', '/' ), ' ', $value );
		$value = preg_replace( '/[^a-z0-9 ]/i', '', $value );
		$value = preg_replace( '/\s+/', ' ', $value );
		$value = trim( (string) $value );
		// Strip a single trailing plural marker (-es or -s) rather than every "s".
		$value = preg_replace( '/(es|s)$/', '', $value );
		return $value;
	}
}
