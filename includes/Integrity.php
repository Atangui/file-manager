<?php
/**
 * Security tools: core integrity check, suspicious files, recent changes, server health.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only diagnostics.
 */
final class Integrity {

	/**
	 * Compare WordPress core files with the official checksums from api.wordpress.org.
	 *
	 * @return array
	 * @throws Error When checksums are unavailable.
	 */
	public static function core() {
		global $wp_version, $wp_local_package;
		require_once ABSPATH . 'wp-admin/includes/update.php';
		$locale    = isset( $wp_local_package ) ? $wp_local_package : 'en_US';
		$checksums = get_core_checksums( $wp_version, $locale );
		if ( ! is_array( $checksums ) && 'en_US' !== $locale ) {
			$checksums = get_core_checksums( $wp_version, 'en_US' );
		}
		if ( ! is_array( $checksums ) || ! $checksums ) {
			throw new Error( 'no_checksums', __( 'Official checksums could not be downloaded from WordPress.org (network blocked or unknown version).', 'filora' ), 503 );
		}
		$base     = Paths::abspath();
		$modified = array();
		$missing  = array();
		$checked  = 0;
		foreach ( $checksums as $file => $md5 ) {
			if ( 0 === strpos( $file, 'wp-content/' ) ) {
				continue; // Bundled themes/plugins are often legitimately updated or removed.
			}
			$abs = $base . '/' . $file;
			if ( ! file_exists( $abs ) ) {
				$missing[] = $file;
				continue;
			}
			++$checked;
			if ( md5_file( $abs ) !== $md5 ) {
				$modified[] = $file;
			}
		}
		$unknown = array();
		foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $item ) {
				$rel = Paths::relative( str_replace( '\\', '/', $item->getPathname() ), $base );
				if ( ! isset( $checksums[ $rel ] ) ) {
					$unknown[] = $rel;
					if ( count( $unknown ) >= 500 ) {
						break 2;
					}
				}
			}
		}
		foreach ( (array) @scandir( $base ) as $name ) { // phpcs:ignore
			if ( is_file( $base . '/' . $name ) && 'php' === Paths::ext( $name ) && ! isset( $checksums[ $name ] ) && ! in_array( $name, array( 'wp-config.php' ), true ) ) {
				$unknown[] = $name;
			}
		}
		Log::add( 'tools.integrity', '', array( 'modified' => count( $modified ), 'unknown' => count( $unknown ) ) );
		return array(
			'version'  => $wp_version,
			'checked'  => $checked,
			'modified' => $modified,
			'missing'  => $missing,
			'unknown'  => $unknown,
		);
	}

	/**
	 * Files modified recently, with suspicious ones flagged.
	 *
	 * @param Fs  $fs   Filesystem.
	 * @param int $days Days.
	 * @return array
	 */
	public static function recent( Fs $fs, $days ) {
		$res     = $fs->search( '/', array( 'days' => max( 1, min( 365, (int) $days ) ), 'limit' => 1000 ) );
		$results = array_values(
			array_filter(
				$res['results'],
				static function ( $e ) {
					return 'file' === $e['type'];
				}
			)
		);
		usort(
			$results,
			static function ( $a, $b ) {
				return $b['mtime'] - $a['mtime'];
			}
		);
		foreach ( $results as &$entry ) {
			$entry['suspicious'] = self::suspicious_reason( $fs, $entry );
		}
		return array(
			'results' => array_slice( $results, 0, 500 ),
			'partial' => $res['partial'],
		);
	}

	/**
	 * Find PHP files where they should never be (uploads) — the #1 malware sign.
	 *
	 * @param Fs $fs Filesystem.
	 * @return array
	 */
	public static function suspicious( Fs $fs ) {
		$uploads = wp_upload_dir( null, false );
		$base    = Paths::real( $uploads['basedir'] );
		$out     = array();
		if ( ! $base || ! Paths::is_within( $base, $fs->profile()->root ) ) {
			return $out;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
		$count    = 0;
		foreach ( $iterator as $item ) {
			if ( ++$count > 200000 ) {
				break;
			}
			$path = str_replace( '\\', '/', $item->getPathname() );
			if ( Paths::is_within( $path, Storage::base() ) || Paths::is_within( $path, Storage::vault() ) ) {
				continue;
			}
			$name = $item->getFilename();
			if ( Policy::is_php( $name ) || in_array( strtolower( $name ), array( '.htaccess', '.user.ini', 'php.ini' ), true ) ) {
				if ( 'index.php' === $name && filesize( $path ) < 100 ) {
					continue; // "Silence is golden" files.
				}
				if ( '.htaccess' === $name && false !== strpos( (string) @file_get_contents( $path ), 'Filora' ) ) { // phpcs:ignore
					continue;
				}
				$out[] = array(
					'path'  => '/' . Paths::relative( $path, $fs->profile()->root ),
					'size'  => (int) $item->getSize(),
					'mtime' => (int) $item->getMTime(),
				);
			}
		}
		return $out;
	}

	/**
	 * Why a recently changed file looks suspicious (or null).
	 *
	 * @param Fs    $fs    Filesystem.
	 * @param array $entry Entry.
	 * @return string|null
	 */
	private static function suspicious_reason( Fs $fs, array $entry ) {
		$uploads = wp_upload_dir( null, false );
		$abs     = Paths::join( $fs->profile()->root, $entry['path'] );
		if ( Policy::is_php( $entry['name'] ) && Paths::is_within( $abs, str_replace( '\\', '/', $uploads['basedir'] ) ) ) {
			return __( 'PHP file inside the uploads folder', 'filora' );
		}
		if ( Policy::is_php( $entry['name'] ) && $entry['size'] < 300000 ) {
			$code = (string) @file_get_contents( $abs ); // phpcs:ignore
			if ( preg_match( '/eval\s*\(\s*(base64_decode|gzinflate|str_rot13|gzuncompress)|assert\s*\(\s*\$_(POST|GET|REQUEST)|\$_(POST|GET|REQUEST|COOKIE)\s*\[[^\]]+\]\s*\(/i', $code ) ) {
				return __( 'Contains code patterns typical of backdoors', 'filora' );
			}
		}
		return null;
	}

	/**
	 * Server capabilities relevant to the file manager.
	 *
	 * @return array
	 */
	public static function server() {
		$abs   = Paths::abspath();
		$free  = function_exists( 'disk_free_space' ) ? @disk_free_space( $abs ) : false; // phpcs:ignore
		$total = function_exists( 'disk_total_space' ) ? @disk_total_space( $abs ) : false; // phpcs:ignore
		return array(
			'php'          => PHP_VERSION,
			'wp'           => get_bloginfo( 'version' ),
			'server'       => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'zip'          => Zipper::available(),
			'imageEditor'  => (bool) _wp_image_editor_choose( array( 'mime_type' => 'image/jpeg' ) ),
			'webp'         => wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ),
			'sodium'       => function_exists( 'sodium_crypto_secretbox' ),
			'uploadMax'    => (int) wp_max_upload_size(),
			'chunkSize'    => Uploads::chunk_size(),
			'memory'       => (string) ini_get( 'memory_limit' ),
			'maxExecution' => (int) ini_get( 'max_execution_time' ),
			'diskFree'     => false === $free ? null : (float) $free,
			'diskTotal'    => false === $total ? null : (float) $total,
			'protection'   => Storage::self_test(),
			'nginx'        => Storage::nginx_snippet(),
			'fileMods'     => ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
			'fileEdit'     => ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ),
			'https'        => is_ssl() || 0 === strpos( home_url(), 'https://' ),
			'permalinks'   => (bool) get_option( 'permalink_structure' ),
			'privateDir'   => defined( 'FILORA_PRIVATE_DIR' ) && FILORA_PRIVATE_DIR,
		);
	}
}
