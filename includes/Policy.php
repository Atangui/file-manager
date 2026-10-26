<?php
/**
 * File name and pattern policy.
 *
 * Central place for "can this name exist / be written / be seen?" decisions, used by
 * every write path (upload, create, rename, copy, extract, save, public uploads).
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless policy helpers.
 */
final class Policy {

	/**
	 * Extensions a web server may execute. Checked against every dot-segment of a name,
	 * so "shell.php.jpg" is caught too (Apache multi-extension handling).
	 */
	const SERVER_EXECUTABLE = array( 'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'pgif', 'inc', 'shtml', 'shtm', 'stm', 'cgi', 'pl', 'py', 'pyc', 'rb', 'asp', 'aspx', 'ascx', 'ashx', 'asmx', 'axd', 'cshtml', 'vbhtml', 'jsp', 'jspx', 'jsf', 'cfm', 'cfml', 'sh', 'bash', 'ksh', 'zsh' );

	/**
	 * Names with a special meaning for web servers / PHP.
	 */
	const SERVER_CONFIG_NAMES = array( '.htaccess', '.htpasswd', '.user.ini', 'php.ini', 'web.config', '.htgroups', '.htdigest' );

	/**
	 * Types that run scripts in the browser when opened from the site origin (stored XSS).
	 */
	const BROWSER_ACTIVE = array( 'html', 'htm', 'xhtml', 'xht', 'shtml', 'svg', 'svgz', 'xml', 'xsl', 'xslt', 'js', 'mjs', 'swf', 'hta', 'mht', 'mhtml' );

	/**
	 * Validate the syntax of a single file / folder name.
	 *
	 * @param string $name Name.
	 * @throws Error On invalid names.
	 */
	public static function assert_valid_name( $name ) {
		$name = (string) $name;
		if ( '' === trim( $name ) || '.' === $name || '..' === $name ) {
			throw new Error( 'invalid_name', __( 'Please enter a valid name.', 'filora' ) );
		}
		if ( strlen( $name ) > 255 ) {
			throw new Error( 'invalid_name', __( 'This name is too long.', 'filora' ) );
		}
		if ( preg_match( '#[/\\\\\x00-\x1F\x7F]#', $name ) ) {
			throw new Error( 'invalid_name', __( 'Names cannot contain slashes or control characters.', 'filora' ) );
		}
		if ( Paths::is_windows() && ( preg_match( '/[<>:"|?*]/', $name ) || preg_match( '/^(con|prn|aux|nul|com\d|lpt\d)(\..*)?$/i', $name ) || preg_match( '/[. ]$/', $name ) ) ) {
			throw new Error( 'invalid_name', __( 'This name is not allowed on this server.', 'filora' ) );
		}
	}

	/**
	 * All lower-cased dot segments after the first one ("a.php.jpg" => [php, jpg]).
	 *
	 * @param string $name Name.
	 * @return string[]
	 */
	public static function segments( $name ) {
		$parts = explode( '.', strtolower( trim( (string) $name ) ) );
		array_shift( $parts );
		return array_map( 'trim', $parts );
	}

	/**
	 * Is the name something a web server could execute or treat as configuration?
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function is_server_dangerous( $name ) {
		$lower = strtolower( trim( (string) $name ) );
		if ( in_array( $lower, self::SERVER_CONFIG_NAMES, true ) || 0 === strpos( $lower, '.ht' ) ) {
			return true;
		}
		$never = apply_filters( 'filora_server_executable_extensions', self::SERVER_EXECUTABLE );
		foreach ( self::segments( $lower ) as $seg ) {
			if ( in_array( $seg, $never, true ) ) {
				return true;
			}
			// Variants like ".php5x", ".phtml~", ".php_".
			if ( preg_match( '/^(php\d*|phtml|phar)[^a-z]*$/', $seg ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is the final extension able to run script in a browser?
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function is_browser_active( $name ) {
		return in_array( Paths::ext( $name ), self::BROWSER_ACTIVE, true );
	}

	/**
	 * Check whether a profile may create a file/folder with this name (any write path).
	 *
	 * @param Profile $profile Profile.
	 * @param string  $name    Name.
	 * @param bool    $is_dir  Directory?
	 * @throws Error When forbidden.
	 */
	public static function assert_writable_name( Profile $profile, $name, $is_dir = false ) {
		self::assert_valid_name( $name );
		if ( $is_dir ) {
			return;
		}
		if ( $profile->is_admin ) {
			if ( $profile->php_locked && self::is_php( $name ) ) {
				throw new Error( 'php_disabled', __( 'PHP file editing is disabled on this site (DISALLOW_FILE_EDIT).', 'filora' ), 403 );
			}
			return;
		}
		if ( self::is_server_dangerous( $name ) ) {
			throw new Error( 'forbidden_type', __( 'For security reasons, this file type cannot be created here.', 'filora' ), 403 );
		}
		if ( self::is_browser_active( $name ) && ! in_array( Paths::ext( $name ), $profile->upload_exts_list(), true ) ) {
			throw new Error( 'forbidden_type', __( 'For security reasons, this file type cannot be created here.', 'filora' ), 403 );
		}
	}

	/**
	 * Check an upload name against the profile allow-list.
	 *
	 * @param Profile $profile Profile.
	 * @param string  $name    Name.
	 * @throws Error When forbidden.
	 */
	public static function assert_uploadable( Profile $profile, $name ) {
		self::assert_writable_name( $profile, $name );
		if ( $profile->is_admin ) {
			return;
		}
		$allowed = $profile->upload_exts;
		if ( '*' === $allowed ) {
			return;
		}
		if ( ! in_array( Paths::ext( $name ), $profile->upload_exts_list(), true ) ) {
			/* translators: %s: comma separated list of extensions */
			throw new Error( 'forbidden_type', sprintf( __( 'This file type is not allowed. Allowed types: %s', 'filora' ), str_replace( ',', ', ', (string) $allowed ) ), 403 );
		}
	}

	/**
	 * Check a public (share) upload name.
	 *
	 * @param string $name    Name.
	 * @param string $allowed Comma list or "*".
	 * @throws Error When forbidden.
	 */
	public static function assert_public_uploadable( $name, $allowed ) {
		self::assert_valid_name( $name );
		if ( '.' === substr( $name, 0, 1 ) || self::is_server_dangerous( $name ) || self::is_browser_active( $name ) ) {
			throw new Error( 'forbidden_type', __( 'This file type is not allowed.', 'filora' ), 403 );
		}
		if ( '*' !== $allowed && ! in_array( Paths::ext( $name ), array_filter( explode( ',', (string) $allowed ) ), true ) ) {
			/* translators: %s: comma separated list of extensions */
			throw new Error( 'forbidden_type', sprintf( __( 'This file type is not allowed. Allowed types: %s', 'filora' ), str_replace( ',', ', ', (string) $allowed ) ), 403 );
		}
	}

	/**
	 * Does the name look like PHP?
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function is_php( $name ) {
		foreach ( self::segments( $name ) as $seg ) {
			if ( preg_match( '/^(php\d*|phtml|pht|phar|inc)$/', $seg ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Match a path against glob-like patterns.
	 *
	 * - "node_modules" (no slash) matches any path segment with that name, and everything below it.
	 * - "wp-content/plugins" (with a slash) matches that path relative to the root or to ABSPATH,
	 *   and everything below it.
	 *
	 * @param string[]    $patterns Patterns.
	 * @param string      $rel_root Path relative to the profile root ("a/b").
	 * @param string|null $rel_site Path relative to ABSPATH, or null when outside.
	 * @return bool
	 */
	public static function matches( array $patterns, $rel_root, $rel_site ) {
		if ( ! $patterns ) {
			return false;
		}
		$rel_root = strtolower( trim( (string) $rel_root, '/' ) );
		$rel_site = null === $rel_site ? null : strtolower( trim( (string) $rel_site, '/' ) );
		// Name patterns only look at segments below the root, so a pattern such as
		// "uploads" never hides an entire root that happens to live in /uploads/.
		$segments = '' === $rel_root ? array() : explode( '/', $rel_root );
		foreach ( $patterns as $pattern ) {
			$pattern = strtolower( trim( str_replace( '\\', '/', (string) $pattern ), " \t/" ) );
			if ( '' === $pattern ) {
				continue;
			}
			if ( false === strpos( $pattern, '/' ) ) {
				foreach ( $segments as $seg ) {
					if ( fnmatch( $pattern, $seg ) ) {
						return true;
					}
				}
				continue;
			}
			foreach ( array( $rel_root, $rel_site ) as $candidate ) {
				if ( null === $candidate || '' === $candidate ) {
					continue;
				}
				if ( fnmatch( $pattern, $candidate ) || fnmatch( $pattern . '/*', $candidate ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
