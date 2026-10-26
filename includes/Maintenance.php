<?php
/**
 * Daily housekeeping.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Cron tasks.
 */
final class Maintenance {

	/**
	 * Run daily maintenance.
	 */
	public static function daily() {
		Trash::purge_older( (int) Settings::value( 'general', 'trash_days', 30 ) );
		Log::purge_older( (int) Settings::value( 'security', 'log_days', 90 ) );
		Uploads::cleanup();
		self::cleanup_thumbs();
		Storage::ensure();
	}

	/**
	 * Remove thumbnails untouched for 60 days.
	 */
	private static function cleanup_thumbs() {
		$root = Storage::base() . '/cache/thumbs';
		if ( ! is_dir( $root ) ) {
			return;
		}
		$limit = time() - 60 * DAY_IN_SECONDS;
		foreach ( (array) @scandir( $root ) as $sub ) { // phpcs:ignore
			if ( '.' === $sub || '..' === $sub || ! is_dir( $root . '/' . $sub ) ) {
				continue;
			}
			foreach ( (array) @scandir( $root . '/' . $sub ) as $file ) { // phpcs:ignore
				$path = $root . '/' . $sub . '/' . $file;
				if ( is_file( $path ) && @fileatime( $path ) < $limit && @filemtime( $path ) < $limit ) { // phpcs:ignore
					@unlink( $path ); // phpcs:ignore
				}
			}
		}
	}
}
