<?php
/**
 * Private storage (trash, versions, temp, cache) and the protected "Vault" folder.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Locations that must never be reachable over HTTP.
 */
final class Storage {

	const SUFFIX_OPTION = 'filora_private_suffix';
	const SELFTEST_OPTION = 'filora_protection_test';

	/**
	 * Base private directory.
	 *
	 * Uses FILORA_PRIVATE_DIR when defined (ideally outside the web root), otherwise a
	 * folder in uploads with an unguessable name plus deny rules for Apache and IIS.
	 *
	 * @return string
	 */
	public static function base() {
		static $base = null;
		if ( null !== $base ) {
			return $base;
		}
		if ( defined( 'FILORA_PRIVATE_DIR' ) && FILORA_PRIVATE_DIR ) {
			$base = rtrim( str_replace( '\\', '/', FILORA_PRIVATE_DIR ), '/' );
			if ( is_multisite() && get_current_blog_id() > 1 ) {
				$base .= '/site-' . get_current_blog_id();
			}
			return $base;
		}
		$suffix = get_option( self::SUFFIX_OPTION );
		if ( ! is_string( $suffix ) || ! preg_match( '/^[a-f0-9]{16,64}$/', $suffix ) ) {
			$suffix = bin2hex( random_bytes( 12 ) );
			update_option( self::SUFFIX_OPTION, $suffix, false );
		}
		$uploads = wp_upload_dir( null, false );
		$base    = rtrim( str_replace( '\\', '/', $uploads['basedir'] ), '/' ) . '/filora-private-' . $suffix;
		return $base;
	}

	/**
	 * Sub directory of the private storage (created on demand).
	 *
	 * @param string $sub trash|versions|tmp|cache/thumbs...
	 * @return string
	 */
	public static function dir( $sub ) {
		self::ensure();
		$dir = self::base() . '/' . trim( $sub, '/' );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * The Vault: a visible folder whose files are only reachable through Filora.
	 *
	 * @return string
	 */
	public static function vault() {
		$uploads = wp_upload_dir( null, false );
		$dir     = rtrim( str_replace( '\\', '/', $uploads['basedir'] ), '/' ) . '/filora-vault';
		return apply_filters( 'filora_vault_dir', $dir );
	}

	/**
	 * Is the path inside the vault?
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function is_vault_path( $path ) {
		static $vault = null;
		if ( null === $vault ) {
			$real  = Paths::real( self::vault() );
			$vault = $real ? $real : self::vault();
		}
		return Paths::is_within( str_replace( '\\', '/', $path ), $vault );
	}

	/**
	 * Can this path be downloaded by anyone who guesses its direct URL?
	 *
	 * @param string $abs Absolute path.
	 * @return bool
	 */
	public static function is_web_exposed( $abs ) {
		if ( null === Fs::public_url( $abs ) ) {
			return false;
		}
		if ( self::is_vault_path( $abs ) ) {
			$test = get_option( self::SELFTEST_OPTION );
			return is_array( $test ) && isset( $test['status'] ) && 'exposed' === $test['status'];
		}
		return true;
	}

	/**
	 * Create the private base and the vault, with deny rules.
	 */
	public static function ensure() {
		$base = self::base();
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		if ( ! file_exists( $base . '/.htaccess' ) ) {
			self::protect( $base );
		}
		$vault = self::vault();
		if ( ! is_dir( $vault ) ) {
			wp_mkdir_p( $vault );
		}
		if ( ! file_exists( $vault . '/.htaccess' ) ) {
			self::protect( $vault );
		}
	}

	/**
	 * Write deny-all rules (Apache 2.2/2.4, LiteSpeed, IIS) and a silent index.
	 *
	 * @param string $dir Directory.
	 */
	public static function protect( $dir ) {
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return;
		}
		$htaccess = "# Filora: protected folder. Files are only served through share links / the file manager.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
			. "Options -Indexes -ExecCGI\n"
			. "<IfModule mod_php.c>\n\tphp_flag engine off\n</IfModule>\n"
			. "<IfModule mod_php7.c>\n\tphp_flag engine off\n</IfModule>\n";
		$webconfig = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization>"
			. "<handlers><clear /></handlers></system.webServer></configuration>\n";
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $dir . '/.htaccess', $htaccess );
		@file_put_contents( $dir . '/web.config', $webconfig );
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! file_exists( $dir . '/index.html' ) ) {
			@file_put_contents( $dir . '/index.html', '' );
		}
		// phpcs:enable
	}

	/**
	 * Check over HTTP whether the vault is really blocked (Nginx ignores .htaccess).
	 *
	 * @param bool $force Ignore cached result.
	 * @return array{status:string,checked:int,url:string}
	 */
	public static function self_test( $force = false ) {
		$cached = get_option( self::SELFTEST_OPTION );
		if ( ! $force && is_array( $cached ) && ! empty( $cached['checked'] ) && $cached['checked'] > time() - DAY_IN_SECONDS ) {
			return $cached;
		}
		self::ensure();
		$vault   = self::vault();
		$canary  = 'filora-canary.txt';
		$content = 'filora-' . wp_generate_password( 24, false );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $vault . '/' . $canary, $content );
		$url    = Fs::public_url( $vault . '/' . $canary );
		$result = array(
			'status'  => 'unknown',
			'checked' => time(),
			'url'     => (string) $url,
		);
		if ( $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 6,
					'redirection' => 0,
					'sslverify'   => false,
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			if ( ! is_wp_error( $response ) ) {
				$code = (int) wp_remote_retrieve_response_code( $response );
				$body = (string) wp_remote_retrieve_body( $response );
				if ( 200 === $code && false !== strpos( $body, $content ) ) {
					$result['status'] = 'exposed';
				} elseif ( $code >= 400 ) {
					$result['status'] = 'protected';
				}
			}
		}
		@unlink( $vault . '/' . $canary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		update_option( self::SELFTEST_OPTION, $result, false );
		return $result;
	}

	/**
	 * Nginx snippet to protect private folders when .htaccess is ignored.
	 *
	 * @return string
	 */
	public static function nginx_snippet() {
		$uploads = wp_upload_dir( null, false );
		$path    = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		$path    = rtrim( (string) $path, '/' );
		return "location ~* ^{$path}/filora-(vault|private-[a-f0-9]+)(/|$) {\n    deny all;\n    return 404;\n}";
	}
}
