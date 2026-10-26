<?php
/**
 * Who can use the file manager, with which profile, plus "sudo mode".
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves profiles from settings and the current user.
 */
final class Access {

	const SUDO_META = 'filora_sudo';

	/**
	 * Cached profiles by user ID.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Last resolution error (for the UI).
	 *
	 * @var string
	 */
	public static $last_error = '';

	/**
	 * Is the user a full administrator for Filora?
	 *
	 * Single site: manage_options (optionally restricted to an allow-list of users).
	 * Multisite: super admins only — site admins must be granted a role profile.
	 *
	 * @param \WP_User|null $user User.
	 * @return bool
	 */
	public static function is_full_admin( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		if ( is_multisite() ) {
			$is = is_super_admin( $user->ID );
		} else {
			$is      = user_can( $user, 'manage_options' );
			$allowed = (array) Settings::value( 'general', 'admin_users', array() );
			if ( $is && $allowed && ! in_array( (int) $user->ID, array_map( 'intval', $allowed ), true ) ) {
				$is = false;
			}
		}
		return (bool) apply_filters( 'filora_is_full_admin', $is, $user );
	}

	/**
	 * Can the user manage plugin settings?
	 *
	 * @return bool
	 */
	public static function can_manage_settings() {
		return self::is_full_admin() && ( is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' ) );
	}

	/**
	 * Can the current user open the file manager at all?
	 *
	 * @return bool
	 */
	public static function can_use() {
		return null !== self::profile();
	}

	/**
	 * Role key whose profile applies to the user, or "admin".
	 *
	 * @param \WP_User $user User.
	 * @return string|null
	 */
	public static function profile_key( $user ) {
		if ( self::is_full_admin( $user ) ) {
			return 'admin';
		}
		$roles = Settings::get( 'roles' );
		foreach ( (array) $user->roles as $role ) {
			if ( isset( $roles[ $role ] ) && ! empty( $roles[ $role ]['enabled'] ) ) {
				return $role;
			}
		}
		return null;
	}

	/**
	 * Build (and cache) the effective profile for a user.
	 *
	 * @param \WP_User|null $user User (current user by default).
	 * @return Profile|null Null when the user has no access or the root is unavailable (fail closed).
	 */
	public static function profile( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return null;
		}
		if ( array_key_exists( $user->ID, self::$cache ) ) {
			return self::$cache[ $user->ID ];
		}
		self::$cache[ $user->ID ] = self::build( $user );
		return self::$cache[ $user->ID ];
	}

	/**
	 * Clear cache (after settings change).
	 */
	public static function flush() {
		self::$cache = array();
	}

	/**
	 * Build a profile.
	 *
	 * @param \WP_User $user User.
	 * @return Profile|null
	 */
	private static function build( \WP_User $user ) {
		$key = self::profile_key( $user );
		if ( null === $key ) {
			return null;
		}
		$general  = Settings::get( 'general' );
		$security = Settings::get( 'security' );
		$profile  = new Profile();

		$profile->key     = $key;
		$profile->user_id = (int) $user->ID;

		if ( 'admin' === $key ) {
			$profile->is_admin       = true;
			$template                = $general['root'];
			$create                  = false;
			$profile->ops            = Settings::OPS;
			$profile->upload_exts    = '*';
			$profile->max_upload     = (int) $general['max_upload'] * MB_IN_BYTES;
			$profile->sensitive_mode = $security['sensitive'] ? $security['admin_sensitive'] : 'full';
		} else {
			$conf                    = Settings::get( 'roles' )[ $key ];
			$template                = $conf['root'];
			$create                  = ! empty( $conf['create_root'] );
			$profile->ops            = array_values( array_intersect( Settings::OPS, (array) $conf['ops'] ) );
			$profile->hidden         = (array) $conf['hidden'];
			$profile->locked         = (array) $conf['locked'];
			$profile->upload_exts    = (string) $conf['upload_exts'];
			$max                     = (int) $conf['max_upload'] ? (int) $conf['max_upload'] : (int) $general['max_upload'];
			$profile->max_upload     = $max * MB_IN_BYTES;
			$profile->quota          = (int) $conf['quota'] * MB_IN_BYTES;
			$profile->sensitive_mode = 'hidden'; // Non-admins never see secrets, whatever the settings.
			if ( '' === $profile->upload_exts ) {
				$profile->ops = array_values( array_diff( $profile->ops, array( 'upload' ) ) );
			}
		}

		$profile->sensitive = $security['sensitive'] || ! $profile->is_admin ? (array) $security['sensitive_patterns'] : array();
		if ( ! $profile->is_admin && ! $profile->sensitive ) {
			$profile->sensitive = Settings::default_sensitive_patterns();
		}

		if ( ! empty( $general['read_only'] ) ) {
			$profile->read_only        = true;
			$profile->read_only_reason = __( 'The file manager is in read-only mode (Settings → General).', 'filora' );
		}
		if ( ! empty( $security['respect_constants'] ) ) {
			if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
				$profile->read_only        = true;
				$profile->read_only_reason = __( 'File modifications are disabled on this site (DISALLOW_FILE_MODS in wp-config.php).', 'filora' );
			}
			if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
				$profile->php_locked = true;
			}
		}

		$root = self::resolve_root( $template, $user, $create );
		if ( null === $root ) {
			return null;
		}
		$profile->root = $root;

		return apply_filters( 'filora_profile', $profile, $user );
	}

	/**
	 * Expand placeholders and validate a root path. Fails closed.
	 *
	 * @param string   $template Template.
	 * @param \WP_User $user     User.
	 * @param bool     $create   Create when missing.
	 * @return string|null
	 */
	public static function resolve_root( $template, \WP_User $user, $create ) {
		$uploads = wp_upload_dir( null, false );
		$login   = sanitize_file_name( $user->user_login );
		$login   = ltrim( $login, '.' );
		if ( '' === $login ) {
			$login = 'user-' . $user->ID;
		}
		$roles = (array) $user->roles;
		$map   = array(
			'{abspath}'       => Paths::abspath(),
			'{wp_content}'    => WP_CONTENT_DIR,
			'{wp_content_dir}' => WP_CONTENT_DIR,
			'{uploads}'       => $uploads['basedir'],
			'{vault}'         => Storage::vault(),
			'{user_login}'    => $login,
			'{user_id}'       => (string) $user->ID,
			'{role}'          => $roles ? sanitize_key( reset( $roles ) ) : 'none',
			'{site_id}'       => (string) get_current_blog_id(),
		);

		$path = str_ireplace( array_keys( $map ), array_values( $map ), (string) $template );
		$path = Paths::normalize( $path );
		if ( null === $path || '' === $path ) {
			self::$last_error = __( 'The configured root folder is invalid.', 'filora' );
			return null;
		}
		if ( ! is_dir( $path ) && $create ) {
			// Folders created inside the Vault inherit its deny rules (Apache, IIS and the Nginx snippet).
			wp_mkdir_p( $path );
		}
		$real = Paths::real( $path );
		if ( false === $real || ! is_dir( $real ) || ! is_readable( $real ) ) {
			self::$last_error = __( 'The configured root folder does not exist or is not readable. Access denied.', 'filora' );
			return null;
		}
		if ( Paths::is_within( $real, Storage::base() ) ) {
			self::$last_error = __( 'The configured root folder is not allowed.', 'filora' );
			return null;
		}
		return $real;
	}

	/**
	 * Is sudo (password re-confirmation) required right now?
	 *
	 * @return bool
	 */
	public static function sudo_required() {
		if ( ! Settings::value( 'security', 'sudo', false ) ) {
			return false;
		}
		$meta = get_user_meta( get_current_user_id(), self::SUDO_META, true );
		if ( ! is_array( $meta ) || empty( $meta['until'] ) || empty( $meta['session'] ) ) {
			return true;
		}
		return (int) $meta['until'] < time() || ! hash_equals( (string) $meta['session'], self::session_fingerprint() );
	}

	/**
	 * Verify the password and open a sudo window bound to the login session.
	 *
	 * @param string $password Password.
	 * @return bool
	 */
	public static function grant_sudo( $password ) {
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! wp_check_password( (string) $password, $user->user_pass, $user->ID ) ) {
			return false;
		}
		$minutes = (int) Settings::value( 'security', 'sudo_minutes', 15 );
		update_user_meta(
			$user->ID,
			self::SUDO_META,
			array(
				'until'   => time() + $minutes * MINUTE_IN_SECONDS,
				'session' => self::session_fingerprint(),
			)
		);
		return true;
	}

	/**
	 * Fingerprint of the current login session.
	 *
	 * @return string
	 */
	private static function session_fingerprint() {
		$token = function_exists( 'wp_get_session_token' ) ? wp_get_session_token() : '';
		return hash( 'sha256', (string) $token . '|' . get_current_user_id() );
	}
}
