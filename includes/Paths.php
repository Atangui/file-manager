<?php
/**
 * Path normalization and containment helpers.
 *
 * Every path that comes from a browser goes through here before touching the disk.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Pure path helpers (no policy).
 */
final class Paths {

	/**
	 * Normalize separators and collapse "." segments; ".." pops a segment.
	 *
	 * @param string $path Path.
	 * @return string|null Null when ".." escapes the start of the path.
	 */
	public static function normalize( $path ) {
		$path   = str_replace( '\\', '/', (string) $path );
		$prefix = '';
		if ( preg_match( '#^[A-Za-z]:/#', $path ) ) {
			$prefix = substr( $path, 0, 3 );
			$path   = substr( $path, 3 );
		} elseif ( '' !== $path && '/' === $path[0] ) {
			$prefix = '/';
		}
		$parts = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( ! $parts ) {
					return null;
				}
				array_pop( $parts );
				continue;
			}
			$parts[] = $segment;
		}
		return $prefix . implode( '/', $parts );
	}

	/**
	 * Clean a client-supplied path relative to a root: "" for the root itself, "a/b" otherwise.
	 *
	 * @param string $rel Relative path.
	 * @return string
	 * @throws Error When the path is malformed or escapes the root.
	 */
	public static function clean_rel( $rel ) {
		$rel = (string) $rel;
		if ( preg_match( '/[\x00-\x1F\x7F]/', $rel ) ) {
			throw new Error( 'invalid_path', __( 'Invalid path.', 'filora' ) );
		}
		$normalized = self::normalize( ltrim( str_replace( '\\', '/', $rel ), '/' ) );
		if ( null === $normalized ) {
			throw new Error( 'invalid_path', __( 'Invalid path.', 'filora' ) );
		}
		return ltrim( $normalized, '/' );
	}

	/**
	 * Resolve symlinks and normalize separators.
	 *
	 * @param string $path Absolute path.
	 * @return string|false
	 */
	public static function real( $path ) {
		$real = realpath( $path );
		if ( false === $real ) {
			return false;
		}
		$real = str_replace( '\\', '/', $real );
		return '/' === $real ? $real : rtrim( $real, '/' );
	}

	/**
	 * Whether $child is $parent or inside it. Both must be normalized absolute paths.
	 *
	 * @param string $child  Path.
	 * @param string $parent Directory.
	 * @return bool
	 */
	public static function is_within( $child, $parent ) {
		$child  = rtrim( str_replace( '\\', '/', $child ), '/' );
		$parent = rtrim( str_replace( '\\', '/', $parent ), '/' );
		if ( self::is_windows() ) {
			$child  = strtolower( $child );
			$parent = strtolower( $parent );
		}
		if ( '' === $parent ) {
			return true; // Filesystem root "/".
		}
		return $child === $parent || 0 === strpos( $child, $parent . '/' );
	}

	/**
	 * Path of $abs relative to $root ("" when equal). Assumes is_within().
	 *
	 * @param string $abs  Absolute path.
	 * @param string $root Root.
	 * @return string
	 */
	public static function relative( $abs, $root ) {
		$abs  = rtrim( str_replace( '\\', '/', $abs ), '/' );
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		if ( strlen( $abs ) <= strlen( $root ) ) {
			return '';
		}
		return ltrim( substr( $abs, strlen( $root ) ), '/' );
	}

	/**
	 * Join a directory and a relative path.
	 *
	 * @param string $dir Directory.
	 * @param string $rel Relative.
	 * @return string
	 */
	public static function join( $dir, $rel ) {
		$dir = rtrim( str_replace( '\\', '/', $dir ), '/' );
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
		if ( '' === $rel ) {
			return '' === $dir ? '/' : $dir;
		}
		return $dir . '/' . $rel;
	}

	/**
	 * Normalized ABSPATH without trailing slash.
	 *
	 * @return string
	 */
	public static function abspath() {
		$real = self::real( ABSPATH );
		return $real ? $real : rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
	}

	/**
	 * Store a path in a migration-friendly way (relative to ABSPATH when possible).
	 *
	 * @param string $abs Absolute path.
	 * @return string
	 */
	public static function to_storage( $abs ) {
		$abs  = str_replace( '\\', '/', $abs );
		$base = self::abspath();
		if ( self::is_within( $abs, $base ) ) {
			return self::relative( $abs, $base );
		}
		return $abs;
	}

	/**
	 * Inverse of to_storage().
	 *
	 * @param string $stored Stored path.
	 * @return string
	 */
	public static function from_storage( $stored ) {
		$stored = str_replace( '\\', '/', (string) $stored );
		if ( '' !== $stored && ( '/' === $stored[0] || preg_match( '#^[A-Za-z]:/#', $stored ) ) ) {
			return $stored;
		}
		return self::join( self::abspath(), $stored );
	}

	/**
	 * Running on Windows?
	 *
	 * @return bool
	 */
	public static function is_windows() {
		return '\\' === DIRECTORY_SEPARATOR;
	}

	/**
	 * Lower-cased extension of a file name ("" when none).
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function ext( $name ) {
		$name = basename( (string) $name );
		$pos  = strrpos( $name, '.' );
		if ( false === $pos || 0 === $pos ) {
			return '';
		}
		return strtolower( substr( $name, $pos + 1 ) );
	}

	/**
	 * Build a non-colliding name in $dir: "name (1).ext", "name (2).ext"...
	 *
	 * @param string $dir  Directory.
	 * @param string $name Desired name.
	 * @param string $tag  Optional tag, e.g. "copy".
	 * @return string
	 */
	public static function unique_name( $dir, $name, $tag = '' ) {
		if ( ! file_exists( self::join( $dir, $name ) ) && '' === $tag ) {
			return $name;
		}
		$ext  = self::ext( $name );
		$base = '' !== $ext && ! is_dir( self::join( $dir, $name ) ) ? substr( $name, 0, -strlen( $ext ) - 1 ) : $name;
		$ext  = '' !== $ext && $base !== $name ? '.' . $ext : '';
		for ( $i = 1; $i < 10000; $i++ ) {
			if ( '' !== $tag ) {
				$suffix = 1 === $i ? " ({$tag})" : " ({$tag} {$i})";
			} else {
				$suffix = " ({$i})";
			}
			$candidate = $base . $suffix . $ext;
			if ( ! file_exists( self::join( $dir, $candidate ) ) ) {
				return $candidate;
			}
		}
		return $base . ' (' . wp_generate_password( 6, false ) . ')' . $ext;
	}

	/**
	 * Core locations that must never be deleted, renamed or moved from the UI.
	 *
	 * @return string[] Normalized absolute paths.
	 */
	public static function system_paths() {
		static $paths = null;
		if ( null !== $paths ) {
			return $paths;
		}
		$abs     = self::abspath();
		$uploads = wp_upload_dir( null, false );
		$list    = array(
			$abs,
			$abs . '/wp-admin',
			$abs . '/wp-includes',
			$abs . '/wp-config.php',
			$abs . '/index.php',
			$abs . '/wp-load.php',
			$abs . '/wp-settings.php',
			$abs . '/wp-blog-header.php',
			dirname( $abs ) . '/wp-config.php',
			WP_CONTENT_DIR,
			WP_PLUGIN_DIR,
			defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
			get_theme_root(),
			get_stylesheet_directory(),
			get_template_directory(),
			isset( $uploads['basedir'] ) ? $uploads['basedir'] : '',
			FILORA_DIR,
			Storage::base(),
			Storage::vault(),
			Storage::vault() . '/.htaccess',
			Storage::vault() . '/web.config',
			Storage::vault() . '/index.php',
		);
		$paths = array();
		foreach ( $list as $p ) {
			if ( ! $p ) {
				continue;
			}
			$real    = self::real( $p );
			$paths[] = $real ? $real : rtrim( str_replace( '\\', '/', $p ), '/' );
		}
		$paths = array_values( array_unique( apply_filters( 'filora_system_paths', $paths ) ) );
		return $paths;
	}

	/**
	 * Is this a protected system path?
	 *
	 * @param string $abs Absolute normalized path.
	 * @return bool
	 */
	public static function is_system( $abs ) {
		$abs = rtrim( str_replace( '\\', '/', $abs ), '/' );
		foreach ( self::system_paths() as $p ) {
			if ( self::is_windows() ? strtolower( $p ) === strtolower( $abs ) : $p === $abs ) {
				return true;
			}
		}
		return false;
	}
}
