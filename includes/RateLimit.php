<?php
/**
 * Tiny fixed-window rate limiter backed by transients (works with or without object cache).
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Rate limiting for brute-force sensitive endpoints.
 */
final class RateLimit {

	/**
	 * Transient key.
	 *
	 * @param string $bucket Bucket.
	 * @param string $id     Identity (IP, email...).
	 * @return string
	 */
	private static function key( $bucket, $id ) {
		return 'filora_rl_' . substr( md5( $bucket . '|' . $id ), 0, 24 );
	}

	/**
	 * Is the identity over the limit?
	 *
	 * @param string $bucket Bucket.
	 * @param string $id     Identity.
	 * @param int    $max    Max hits per window.
	 * @return bool
	 */
	public static function exceeded( $bucket, $id, $max ) {
		$data = get_transient( self::key( $bucket, $id ) );
		return is_array( $data ) && (int) $data['n'] >= $max;
	}

	/**
	 * Record a hit.
	 *
	 * @param string $bucket Bucket.
	 * @param string $id     Identity.
	 * @param int    $window Window in seconds.
	 * @return int Hits in the window.
	 */
	public static function hit( $bucket, $id, $window ) {
		$key  = self::key( $bucket, $id );
		$data = get_transient( $key );
		if ( ! is_array( $data ) || (int) $data['t'] < time() ) {
			$data = array(
				'n' => 0,
				't' => time() + $window,
			);
		}
		++$data['n'];
		set_transient( $key, $data, max( 1, (int) $data['t'] - time() ) );
		return (int) $data['n'];
	}

	/**
	 * Reset an identity (after a success).
	 *
	 * @param string $bucket Bucket.
	 * @param string $id     Identity.
	 */
	public static function reset( $bucket, $id ) {
		delete_transient( self::key( $bucket, $id ) );
	}

	/**
	 * Hit and check in one call.
	 *
	 * @param string $bucket Bucket.
	 * @param string $id     Identity.
	 * @param int    $max    Max.
	 * @param int    $window Window.
	 * @return bool True when allowed.
	 */
	public static function allow( $bucket, $id, $max, $window ) {
		if ( self::exceeded( $bucket, $id, $max ) ) {
			return false;
		}
		self::hit( $bucket, $id, $window );
		return true;
	}
}
