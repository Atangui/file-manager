<?php
/**
 * Streams files to the browser (Range requests, conditional GET, safe headers).
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Never redirects to a file's direct URL: content is always piped through PHP with
 * Content-Disposition and a restrictive Content-Security-Policy, so HTML/SVG/PHP files
 * can't execute on the site origin.
 */
final class Stream {

	/**
	 * Send a file and exit.
	 *
	 * @param string $abs  Absolute path.
	 * @param array  $opts name, inline, cache (seconds, private), mime, delete_after.
	 */
	public static function send( $abs, array $opts = array() ) {
		$opts = $opts + array(
			'name'         => basename( $abs ),
			'inline'       => false,
			'cache'        => 0,
			'mime'         => null,
			'delete_after' => false,
		);
		if ( ! is_file( $abs ) || ! is_readable( $abs ) ) {
			status_header( 404 );
			exit;
		}

		$size  = (int) filesize( $abs );
		$mtime = (int) filemtime( $abs );
		$mime  = $opts['mime'] ? $opts['mime'] : Mime::type( $opts['name'] );
		$etag  = '"' . md5( $abs . '|' . $size . '|' . $mtime ) . '"';

		$inline = (bool) $opts['inline'];
		$svg    = 'image/svg+xml' === $mime;
		$text   = 0 === strpos( $mime, 'text/' ) || in_array( $mime, array( 'application/json', 'application/xml' ), true );
		if ( $inline && ! Mime::inline_safe( $mime ) && ! $svg && ! $text ) {
			$inline = false;
		}
		if ( $inline && $text ) {
			$mime = 'text/plain; charset=utf-8'; // Never render HTML/XML from storage.
		}

		self::clean_buffers();
		if ( function_exists( 'session_write_close' ) ) {
			@session_write_close(); // phpcs:ignore
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore
		}
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore
		}
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore

		header_remove( 'Pragma' );
		header_remove( 'Expires' );
		if ( $opts['cache'] > 0 ) {
			header( 'Cache-Control: private, max-age=' . (int) $opts['cache'] );
		} else {
			header( 'Cache-Control: private, no-store, max-age=0' );
		}
		header( 'ETag: ' . $etag );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( 'application/pdf' !== $mime ) {
			// Sandboxed, script-less document even if a browser decides to render it.
			header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox" );
		}
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: ' . self::disposition( $inline ? 'inline' : 'attachment', $opts['name'] ) );
		header( 'Accept-Ranges: bytes' );

		if ( ! $opts['delete_after'] && self::not_modified( $etag, $mtime ) ) {
			status_header( 304 );
			exit;
		}

		$start = 0;
		$end   = $size - 1;
		if ( $size > 0 && isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/^bytes=(\d*)-(\d*)$/', trim( (string) $_SERVER['HTTP_RANGE'] ), $m ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( '' === $m[1] && '' !== $m[2] ) {
				$start = max( 0, $size - (int) $m[2] );
			} else {
				$start = (int) $m[1];
				$end   = '' !== $m[2] ? min( (int) $m[2], $size - 1 ) : $size - 1;
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		} else {
			status_header( 200 );
		}
		$length = $size > 0 ? $end - $start + 1 : 0;
		header( 'Content-Length: ' . $length );

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
			exit;
		}

		$xsend = apply_filters( 'filora_xsendfile_header', '', $abs );
		if ( $xsend && 0 === $start && $length === $size ) {
			header( $xsend . ': ' . $abs );
			exit;
		}

		$fh = fopen( $abs, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $fh ) {
			fseek( $fh, $start );
			$left = $length;
			while ( $left > 0 && ! feof( $fh ) && ! connection_aborted() ) {
				$chunk = fread( $fh, (int) min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$left -= strlen( $chunk );
				flush();
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $opts['delete_after'] ) {
			@unlink( $abs ); // phpcs:ignore
		}
		exit;
	}

	/**
	 * Build a safe Content-Disposition (RFC 6266 / 5987), immune to header injection.
	 *
	 * @param string $type inline|attachment.
	 * @param string $name File name.
	 * @return string
	 */
	public static function disposition( $type, $name ) {
		$name     = str_replace( array( "\r", "\n", '"', '\\' ), '', (string) $name );
		$fallback = preg_replace( '/[^\x20-\x7E]/', '_', $name );
		return $type . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode( $name );
	}

	/**
	 * Conditional GET.
	 *
	 * @param string $etag  ETag.
	 * @param int    $mtime Modification time.
	 * @return bool
	 */
	private static function not_modified( $etag, $mtime ) {
		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) {
			return trim( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) === $etag; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			$since = strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return $since && $since >= $mtime;
		}
		return false;
	}

	/**
	 * Drop any buffered output (themes / plugins sometimes echo whitespace).
	 */
	public static function clean_buffers() {
		while ( ob_get_level() > 0 ) {
			@ob_end_clean(); // phpcs:ignore
		}
	}
}
