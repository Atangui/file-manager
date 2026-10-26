<?php
/**
 * Translations for the JavaScript app.
 *
 * The app's strings are extracted from assets/src by bin/extract-strings.mjs into
 * includes/i18n-strings.php, so they go through regular gettext (.po/.mo) like PHP strings
 * and work on every WordPress version without JSON translation files.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * JS string table.
 */
final class I18n {

	/**
	 * Translated strings keyed by their English source.
	 * Plurals are [ singular, plural ] pairs (the app picks with Intl.PluralRules).
	 *
	 * @return array
	 */
	public static function strings() {
		$file = FILORA_DIR . 'includes/i18n-strings.php';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$strings = include $file;
		return is_array( $strings ) ? $strings : array();
	}

	/**
	 * Locale the public share pages currently use (null: the site locale).
	 *
	 * @var string|null
	 */
	private static $visitor = null;

	/**
	 * Locales Filora has a translation for (plus English, the source language).
	 *
	 * @return string[]
	 */
	public static function available() {
		$out = array( 'en_US', get_locale() );
		foreach ( array( WP_LANG_DIR . '/plugins', FILORA_DIR . 'languages' ) as $dir ) {
			foreach ( (array) glob( $dir . '/filora-*.mo' ) as $mo ) {
				$out[] = substr( basename( (string) $mo, '.mo' ), 7 );
			}
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * Best locale for an Accept-Language header: exact match, then same language;
	 * the site locale when nothing matches.
	 *
	 * @param string $header Accept-Language value.
	 * @return string
	 */
	public static function negotiate( $header ) {
		$site      = get_locale();
		$available = self::available();
		$wanted    = array();
		foreach ( explode( ',', (string) $header ) as $i => $part ) {
			$bits = explode( ';', trim( $part ) );
			$tag  = strtolower( str_replace( '-', '_', trim( $bits[0] ) ) );
			if ( '' === $tag || '*' === $tag || ! preg_match( '/^[a-z]{2,3}(_[a-z0-9]{2,8})*$/', $tag ) ) {
				continue;
			}
			$q = 1.0;
			if ( isset( $bits[1] ) && preg_match( '/q=([0-9.]+)/', $bits[1], $m ) ) {
				$q = (float) $m[1];
			}
			$wanted[] = array( $tag, $q - $i / 1000 );
		}
		usort(
			$wanted,
			static function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);
		foreach ( $wanted as $w ) {
			$lang = strtok( $w[0], '_' );
			// Same language as the site: keep the site's regional variant.
			if ( strtok( strtolower( $site ), '_' ) === $lang ) {
				return $site;
			}
			foreach ( $available as $locale ) {
				if ( strtolower( $locale ) === $w[0] ) {
					return $locale;
				}
			}
			foreach ( $available as $locale ) {
				if ( strtok( strtolower( $locale ), '_' ) === $lang ) {
					return $locale;
				}
			}
		}
		return $site;
	}

	/**
	 * Translate the public share pages in the visitor's language (browser preference).
	 */
	public static function use_visitor_locale() {
		$header = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ), 0, 200 ) : '';
		/**
		 * Filters the locale of the public share pages.
		 *
		 * @param string $locale Negotiated locale.
		 * @param string $header Accept-Language header.
		 */
		$locale = (string) apply_filters( 'filora_share_locale', self::negotiate( $header ), $header );
		if ( $locale !== get_locale() && self::load( $locale ) ) {
			self::$visitor = $locale;
		}
	}

	/**
	 * Back to the site language (e-mails to the site owner).
	 *
	 * @return string|null Locale to restore afterwards with restore().
	 */
	public static function use_site_locale() {
		$previous = self::$visitor;
		if ( null !== $previous ) {
			self::load( get_locale() );
			self::$visitor = null;
		}
		return $previous;
	}

	/**
	 * Restore the visitor locale after use_site_locale().
	 *
	 * @param string|null $locale Locale returned by use_site_locale().
	 */
	public static function restore( $locale ) {
		if ( null !== $locale && self::load( $locale ) ) {
			self::$visitor = $locale;
		}
	}

	/**
	 * Current locale of the share pages.
	 *
	 * @return string
	 */
	public static function locale() {
		return null !== self::$visitor ? self::$visitor : get_locale();
	}

	/**
	 * Load Filora's translations for a locale (English: no file).
	 *
	 * @param string $locale Locale.
	 * @return bool
	 */
	private static function load( $locale ) {
		if ( ! preg_match( '/^[a-zA-Z]{2,3}(_[a-zA-Z0-9]{2,8})*$/', $locale ) ) {
			return false;
		}
		$files = array( WP_LANG_DIR . '/plugins/filora-' . $locale . '.mo', FILORA_DIR . 'languages/filora-' . $locale . '.mo' );
		$file  = '';
		foreach ( $files as $candidate ) {
			if ( is_readable( $candidate ) ) {
				$file = $candidate;
				break;
			}
		}
		if ( '' === $file && 'en_US' !== $locale ) {
			return false;
		}
		unload_textdomain( 'filora' );
		if ( '' !== $file ) {
			// No locale argument: WordPress 6.5+ only reads the current locale's bucket.
			load_textdomain( 'filora', $file );
		}
		return true;
	}

	/**
	 * Long date in the share page language.
	 *
	 * @param int $ts Timestamp.
	 * @return string
	 */
	public static function date( $ts ) {
		if ( null !== self::$visitor && class_exists( 'IntlDateFormatter' ) ) {
			try {
				$fmt = new \IntlDateFormatter( self::$visitor, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, wp_timezone() );
				$out = $fmt->format( (int) $ts );
				if ( is_string( $out ) && '' !== $out ) {
					return $out;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall back to WordPress formatting.
			}
		}
		return wp_date( get_option( 'date_format' ), $ts );
	}
}
