<?php
/**
 * Settings schema, defaults and sanitization.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Stores everything in a single non-autoloaded option.
 */
final class Settings {

	const OPTION = 'filora_settings';

	/**
	 * Operations that can be granted per role.
	 */
	const OPS = array( 'download', 'upload', 'mkdir', 'mkfile', 'rename', 'move', 'copy', 'delete', 'edit', 'archive', 'extract', 'chmod', 'image', 'share' );

	/**
	 * Request-level cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default sensitive patterns (secrets, dumps, VCS metadata, keys).
	 *
	 * @return string[]
	 */
	public static function default_sensitive_patterns() {
		return array( 'wp-config.php', 'wp-config-*.php', '.env', '.env.*', '.htaccess', '.htpasswd', '.user.ini', 'php.ini', '*.sql', '*.sql.gz', '*.sqlite', '*.sqlite3', '*.db', '.git', '.svn', 'id_rsa*', 'id_ed25519*', '*.pem', '*.key', 'debug.log', 'error_log', '*.bak' );
	}

	/**
	 * Defaults for a non-admin role.
	 *
	 * @return array
	 */
	public static function role_defaults() {
		return array(
			'enabled'     => false,
			'root'        => '{UPLOADS}',
			'create_root' => true,
			'ops'         => array( 'download', 'upload', 'mkdir', 'rename', 'move', 'copy', 'delete' ),
			'hidden'      => array(),
			'locked'      => array(),
			'upload_exts' => 'jpg,jpeg,png,gif,webp,avif,pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,txt,csv,rtf,zip,mp4,mov,webm,mp3,wav,m4a',
			'max_upload'  => 0,
			'quota'       => 0,
		);
	}

	/**
	 * Full defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'general'  => array(
				'root'          => '{ABSPATH}',
				'admin_users'   => array(),
				'read_only'     => false,
				'trash'         => true,
				'trash_days'    => 30,
				'versions'      => true,
				'versions_keep' => 10,
				'php_lint'      => true,
				'max_upload'    => 0,
				'frontend'      => false,
				'cleanup'       => false,
			),
			'security' => array(
				'sensitive'          => true,
				'sensitive_patterns' => self::default_sensitive_patterns(),
				'admin_sensitive'    => 'full',
				'sudo'               => false,
				'sudo_minutes'       => 15,
				'respect_constants'  => true,
				'log'                => true,
				'log_days'           => 90,
				'log_ip'             => 'full',
				'proxy_header'       => '',
			),
			'sharing'  => array(
				'enabled'        => true,
				'slug'           => 'share',
				'default_days'   => 7,
				'max_days'       => 0,
				'force_password' => false,
				'allow_upload'   => true,
				'upload_exts'    => 'jpg,jpeg,png,gif,webp,avif,heic,pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,txt,csv,rtf,zip,mp4,mov,m4v,webm,mp3,wav,m4a,psd,ai,eps,indd',
				'upload_max'     => 512,
				'zip_max'        => 2048,
				'session_hours'  => 12,
				'notify'         => 'uploads',
				'accent'         => '#4f46e5',
				'logo'           => '',
				'footer'         => '',
				'show_owner'     => false,
			),
			'roles'    => array(),
		);
	}

	/**
	 * Get all settings or one section, merged over defaults.
	 *
	 * @param string|null $section Section name.
	 * @return array
	 */
	public static function get( $section = null ) {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			$stored      = is_array( $stored ) ? $stored : array();
			self::$cache = self::merge( self::defaults(), $stored );
			foreach ( self::$cache['roles'] as $role => $conf ) {
				self::$cache['roles'][ $role ] = self::merge( self::role_defaults(), is_array( $conf ) ? $conf : array() );
			}
		}
		if ( null === $section ) {
			return self::$cache;
		}
		return isset( self::$cache[ $section ] ) ? self::$cache[ $section ] : array();
	}

	/**
	 * Read one value.
	 *
	 * @param string $section Section.
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function value( $section, $key, $default = null ) {
		$s = self::get( $section );
		return array_key_exists( $key, $s ) ? $s[ $key ] : $default;
	}

	/**
	 * Clear the request cache (after updates).
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Merge stored values over defaults (assoc arrays recurse, lists replace).
	 *
	 * @param array $defaults Defaults.
	 * @param array $stored   Stored.
	 * @return array
	 */
	private static function merge( array $defaults, array $stored ) {
		foreach ( $stored as $key => $value ) {
			if ( isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && is_array( $value ) && self::is_assoc( $defaults[ $key ] ) ) {
				$defaults[ $key ] = self::merge( $defaults[ $key ], $value );
			} else {
				$defaults[ $key ] = $value;
			}
		}
		return $defaults;
	}

	/**
	 * Whether an array is associative.
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	private static function is_assoc( array $arr ) {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Sanitize and persist a full settings payload.
	 *
	 * @param array $input Raw input.
	 * @return array Saved settings.
	 */
	public static function update( array $input ) {
		$current = self::get();
		$clean   = $current;

		if ( isset( $input['general'] ) && is_array( $input['general'] ) ) {
			$g                = $input['general'] + $current['general'];
			$clean['general'] = array(
				'root'          => self::clean_root( $g['root'], '{ABSPATH}' ),
				'admin_users'   => array_values( array_filter( array_map( 'absint', (array) $g['admin_users'] ) ) ),
				'read_only'     => self::bool( $g['read_only'] ),
				'trash'         => self::bool( $g['trash'] ),
				'trash_days'    => self::int( $g['trash_days'], 0, 3650 ),
				'versions'      => self::bool( $g['versions'] ),
				'versions_keep' => self::int( $g['versions_keep'], 1, 100 ),
				'php_lint'      => self::bool( $g['php_lint'] ),
				'max_upload'    => self::int( $g['max_upload'], 0, 1048576 ),
				'frontend'      => self::bool( $g['frontend'] ),
				'cleanup'       => self::bool( $g['cleanup'] ),
			);
		}

		if ( isset( $input['security'] ) && is_array( $input['security'] ) ) {
			$s                 = $input['security'] + $current['security'];
			$clean['security'] = array(
				'sensitive'          => self::bool( $s['sensitive'] ),
				'sensitive_patterns' => self::patterns( $s['sensitive_patterns'] ),
				'admin_sensitive'    => in_array( $s['admin_sensitive'], array( 'full', 'readonly', 'hidden' ), true ) ? $s['admin_sensitive'] : 'full',
				'sudo'               => self::bool( $s['sudo'] ),
				'sudo_minutes'       => self::int( $s['sudo_minutes'], 1, 720 ),
				'respect_constants'  => self::bool( $s['respect_constants'] ),
				'log'                => self::bool( $s['log'] ),
				'log_days'           => self::int( $s['log_days'], 0, 3650 ),
				'log_ip'             => in_array( $s['log_ip'], array( 'full', 'anonymized', 'none' ), true ) ? $s['log_ip'] : 'full',
				'proxy_header'       => in_array( $s['proxy_header'], array( '', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ), true ) ? $s['proxy_header'] : '',
			);
		}

		if ( isset( $input['sharing'] ) && is_array( $input['sharing'] ) ) {
			$sh    = $input['sharing'] + $current['sharing'];
			$slug  = sanitize_title( $sh['slug'] );
			$color = sanitize_hex_color( $sh['accent'] );

			$clean['sharing'] = array(
				'enabled'        => self::bool( $sh['enabled'] ),
				'slug'           => '' !== $slug ? $slug : 'share',
				'default_days'   => self::int( $sh['default_days'], 0, 3650 ),
				'max_days'       => self::int( $sh['max_days'], 0, 3650 ),
				'force_password' => self::bool( $sh['force_password'] ),
				'allow_upload'   => self::bool( $sh['allow_upload'] ),
				'upload_exts'    => self::exts( $sh['upload_exts'] ),
				'upload_max'     => self::int( $sh['upload_max'], 1, 1048576 ),
				'zip_max'        => self::int( $sh['zip_max'], 1, 1048576 ),
				'session_hours'  => self::int( $sh['session_hours'], 1, 720 ),
				'notify'         => in_array( $sh['notify'], array( 'none', 'uploads', 'all' ), true ) ? $sh['notify'] : 'uploads',
				'accent'         => $color ? $color : '#4f46e5',
				'logo'           => esc_url_raw( (string) $sh['logo'] ),
				'footer'         => sanitize_text_field( (string) $sh['footer'] ),
				'show_owner'     => self::bool( $sh['show_owner'] ),
			);
			if ( $clean['sharing']['slug'] !== $current['sharing']['slug'] ) {
				update_option( Installer::FLUSH_FLAG, 1, false );
			}
		}

		if ( isset( $input['roles'] ) && is_array( $input['roles'] ) ) {
			$roles          = wp_roles()->get_names();
			$clean['roles'] = array();
			foreach ( $input['roles'] as $role => $conf ) {
				$role = sanitize_key( $role );
				if ( ! isset( $roles[ $role ] ) || ! is_array( $conf ) ) {
					continue;
				}
				if ( 'administrator' === $role && ! is_multisite() ) {
					continue; // Single-site administrators use the general profile.
				}
				$conf                   = $conf + self::role_defaults();
				$clean['roles'][ $role ] = array(
					'enabled'     => self::bool( $conf['enabled'] ),
					'root'        => self::clean_root( $conf['root'], '{UPLOADS}' ),
					'create_root' => self::bool( $conf['create_root'] ),
					'ops'         => array_values( array_intersect( self::OPS, (array) $conf['ops'] ) ),
					'hidden'      => self::patterns( $conf['hidden'] ),
					'locked'      => self::patterns( $conf['locked'] ),
					'upload_exts' => self::exts( $conf['upload_exts'] ),
					'max_upload'  => self::int( $conf['max_upload'], 0, 1048576 ),
					'quota'       => self::int( $conf['quota'], 0, 104857600 ),
				);
			}
		}

		update_option( self::OPTION, $clean, false );
		self::flush_cache();
		return self::get();
	}

	/**
	 * Normalize a boolean-ish value.
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	private static function bool( $v ) {
		return in_array( $v, array( true, 1, '1', 'true', 'on', 'yes' ), true );
	}

	/**
	 * Clamp an integer.
	 *
	 * @param mixed $v   Value.
	 * @param int   $min Min.
	 * @param int   $max Max.
	 * @return int
	 */
	private static function int( $v, $min, $max ) {
		return max( $min, min( $max, (int) $v ) );
	}

	/**
	 * Clean a root path template (placeholders allowed).
	 *
	 * @param mixed  $v        Value.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function clean_root( $v, $fallback ) {
		$v = trim( str_replace( '\\', '/', wp_strip_all_tags( (string) $v ) ) );
		$v = preg_replace( '/[\x00-\x1F\x7F]/', '', $v );
		return '' === $v ? $fallback : $v;
	}

	/**
	 * Clean a pattern list (array or newline / | / comma separated string).
	 *
	 * @param mixed $v Value.
	 * @return string[]
	 */
	public static function patterns( $v ) {
		if ( ! is_array( $v ) ) {
			$v = preg_split( '/[\r\n|,]+/', (string) $v );
		}
		$out = array();
		foreach ( $v as $p ) {
			$p = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', str_replace( '\\', '/', (string) $p ) ) );
			if ( '' !== $p && strlen( $p ) < 256 ) {
				$out[] = $p;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Normalize an extension list ("*" means any non-dangerous type).
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	public static function exts( $v ) {
		$list = is_array( $v ) ? $v : preg_split( '/[\s,;|]+/', strtolower( (string) $v ) );
		$out  = array();
		foreach ( $list as $ext ) {
			$ext = ltrim( strtolower( trim( (string) $ext ) ), '.' );
			if ( '*' === $ext ) {
				return '*';
			}
			if ( preg_match( '/^[a-z0-9]{1,15}$/', $ext ) ) {
				$out[] = $ext;
			}
		}
		return implode( ',', array_unique( $out ) );
	}
}
