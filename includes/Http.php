<?php
/**
 * HTTP helpers: client IP, cache / privacy headers, bot detection.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers.
 */
final class Http {

	/**
	 * Client IP. Proxy headers are only trusted when explicitly enabled in settings,
	 * otherwise anyone could spoof X-Forwarded-For to bypass rate limits and IP allow-lists.
	 *
	 * @return string
	 */
	public static function ip() {
		$header = (string) Settings::value( 'security', 'proxy_header', '' );
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $header && ! empty( $_SERVER[ $header ] ) ) {
			$candidates = explode( ',', (string) $_SERVER[ $header ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$candidate  = trim( (string) reset( $candidates ) );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
			}
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * IP for storage, according to the privacy setting.
	 *
	 * @return string
	 */
	public static function ip_for_log() {
		$mode = Settings::value( 'security', 'log_ip', 'full' );
		if ( 'none' === $mode ) {
			return '';
		}
		$ip = self::ip();
		if ( 'anonymized' === $mode ) {
			return function_exists( 'wp_privacy_anonymize_ip' ) ? wp_privacy_anonymize_ip( $ip ) : preg_replace( '/\.\d+$/', '.0', $ip );
		}
		return $ip;
	}

	/**
	 * User agent (truncated).
	 *
	 * @return string
	 */
	public static function ua() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return substr( wp_strip_all_tags( $ua ), 0, 255 );
	}

	/**
	 * Link previewers and scanners (Slack, Teams, Outlook Safe Links...) must not
	 * consume download limits or count as views.
	 *
	 * @return bool
	 */
	public static function is_bot() {
		$ua = strtolower( self::ua() );
		if ( '' === $ua ) {
			return true;
		}
		$needles = array( 'bot', 'crawl', 'spider', 'preview', 'slack', 'facebookexternalhit', 'whatsapp', 'telegram', 'discord', 'skypeuripreview', 'microsoft office', 'ms-office', 'outlook', 'safelinks', 'linkedin', 'embedly', 'quora link', 'pinterest', 'vkshare', 'w3c_validator', 'curl/', 'wget/', 'python-requests', 'headless', 'proofpoint', 'mimecast', 'barracuda' );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Headers for every share response: never cached, never indexed, never leaking
	 * the secret URL through the Referer header.
	 */
	public static function private_headers() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		do_action( 'litespeed_control_set_nocache', 'filora share' );
		nocache_headers();
		if ( headers_sent() ) {
			return;
		}
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()' );
		header( 'Cross-Origin-Opener-Policy: same-origin' );
	}

	/**
	 * Send a JSON response and stop.
	 *
	 * @param mixed $data   Data.
	 * @param int   $status Status.
	 */
	public static function json( $data, $status = 200 ) {
		if ( ! headers_sent() ) {
			status_header( $status );
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		echo wp_json_encode( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Does an IP match any rule (single IPs or CIDR ranges, v4 and v6)?
	 *
	 * @param string   $ip    IP.
	 * @param string[] $rules Rules.
	 * @return bool
	 */
	public static function ip_matches( $ip, array $rules ) {
		$bin = @inet_pton( $ip ); // phpcs:ignore
		if ( false === $bin ) {
			return false;
		}
		foreach ( $rules as $rule ) {
			$rule = trim( (string) $rule );
			if ( '' === $rule ) {
				continue;
			}
			if ( false === strpos( $rule, '/' ) ) {
				if ( @inet_pton( $rule ) === $bin ) { // phpcs:ignore
					return true;
				}
				continue;
			}
			list( $subnet, $bits ) = explode( '/', $rule, 2 );
			$sub                   = @inet_pton( trim( $subnet ) ); // phpcs:ignore
			$bits                  = (int) $bits;
			if ( false === $sub || strlen( $sub ) !== strlen( $bin ) || $bits < 0 || $bits > strlen( $bin ) * 8 ) {
				continue;
			}
			$bytes = intdiv( $bits, 8 );
			$rest  = $bits % 8;
			if ( substr( $bin, 0, $bytes ) !== substr( $sub, 0, $bytes ) ) {
				continue;
			}
			if ( 0 === $rest ) {
				return true;
			}
			$mask = chr( ( 0xFF << ( 8 - $rest ) ) & 0xFF );
			if ( ( $bin[ $bytes ] & $mask ) === ( $sub[ $bytes ] & $mask ) ) {
				return true;
			}
		}
		return false;
	}
}
