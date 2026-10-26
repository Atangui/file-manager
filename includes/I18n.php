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
}
