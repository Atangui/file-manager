<?php
/**
 * Trash stored in the private (non web-reachable) storage.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Each item lives in trash/{id}/item/{name} with a meta.json next to it.
 */
final class Trash {

	/**
	 * Trash root.
	 *
	 * @return string
	 */
	private static function root() {
		return Storage::dir( 'trash' );
	}

	/**
	 * Move an item to the trash.
	 *
	 * @param string $abs     Absolute path.
	 * @param int    $user_id User.
	 * @return string Trash ID.
	 * @throws Error On failure.
	 */
	public static function put( $abs, $user_id ) {
		$id   = gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 4 ) );
		$base = self::root() . '/' . $id;
		if ( ! wp_mkdir_p( $base . '/item' ) ) {
			throw new Error( 'trash_failed', __( 'The trash folder is not writable.', 'filora' ), 500 );
		}
		$is_dir = is_dir( $abs );
		$size   = $is_dir ? null : (int) @filesize( $abs ); // phpcs:ignore
		Fs::move_path( $abs, $base . '/item/' . basename( $abs ) );
		$meta = array(
			'id'         => $id,
			'name'       => basename( $abs ),
			'original'   => Paths::to_storage( $abs ),
			'type'       => $is_dir ? 'dir' : 'file',
			'size'       => $size,
			'deleted_at' => time(),
			'deleted_by' => (int) $user_id,
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $base . '/meta.json', wp_json_encode( $meta ) );
		return $id;
	}

	/**
	 * Read one item's metadata.
	 *
	 * @param string $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		if ( ! preg_match( '/^\d{14}-[a-f0-9]{8}$/', (string) $id ) ) {
			return null;
		}
		$file = self::root() . '/' . $id . '/meta.json';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$meta = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore
		return is_array( $meta ) ? $meta : null;
	}

	/**
	 * Items visible to a profile (original location inside its root).
	 *
	 * @param Profile $p Profile.
	 * @return array
	 */
	public static function items( Profile $p ) {
		$root  = self::root();
		$names = @scandir( $root ); // phpcs:ignore
		$out   = array();
		foreach ( (array) $names as $name ) {
			$meta = self::get( $name );
			if ( ! $meta ) {
				continue;
			}
			$original = Paths::from_storage( $meta['original'] );
			if ( ! Paths::is_within( $original, $p->root ) ) {
				continue;
			}
			$user              = get_userdata( (int) $meta['deleted_by'] );
			$meta['path']      = '/' . Paths::relative( $original, $p->root );
			$meta['deletedBy'] = $user ? $user->display_name : '';
			unset( $meta['original'] );
			$out[] = $meta;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['deleted_at'] - $a['deleted_at'];
			}
		);
		return $out;
	}

	/**
	 * Restore an item to its original place (renamed on conflict).
	 *
	 * @param string $id ID.
	 * @param Fs     $fs Filesystem of the requesting profile.
	 * @return string Restored relative path.
	 * @throws Error On failure.
	 */
	public static function restore( $id, Fs $fs ) {
		$meta = self::get( $id );
		if ( ! $meta ) {
			throw Error::not_found();
		}
		$p        = $fs->profile();
		$original = Paths::from_storage( $meta['original'] );
		if ( ! Paths::is_within( $original, $p->root ) ) {
			throw Error::not_found();
		}
		$parent = dirname( $original );
		if ( ! is_dir( $parent ) ) {
			wp_mkdir_p( $parent );
		}
		$real_parent = Paths::real( $parent );
		if ( ! $real_parent || ! Paths::is_within( $real_parent, $p->root ) || 'ok' !== $fs->visibility( $real_parent ) ) {
			throw Error::forbidden();
		}
		Policy::assert_writable_name( $p, $meta['name'], 'dir' === $meta['type'] );
		$name   = Paths::unique_name( $real_parent, $meta['name'] );
		$target = $real_parent . '/' . $name;
		Fs::move_path( self::root() . '/' . $id . '/item/' . $meta['name'], $target );
		Fs::delete_path( self::root() . '/' . $id );
		return '/' . Paths::relative( $target, $p->root );
	}

	/**
	 * Permanently delete one item.
	 *
	 * @param string $id ID.
	 * @return bool
	 */
	public static function purge( $id ) {
		if ( ! self::get( $id ) ) {
			return false;
		}
		return Fs::delete_path( self::root() . '/' . $id );
	}

	/**
	 * Purge items older than N days (cron).
	 *
	 * @param int $days Days (0 = keep forever).
	 */
	public static function purge_older( $days ) {
		if ( $days <= 0 ) {
			return;
		}
		$limit = time() - $days * DAY_IN_SECONDS;
		foreach ( (array) @scandir( self::root() ) as $name ) { // phpcs:ignore
			$meta = self::get( $name );
			if ( $meta && (int) $meta['deleted_at'] < $limit ) {
				Fs::delete_path( self::root() . '/' . $name );
			}
		}
	}
}
