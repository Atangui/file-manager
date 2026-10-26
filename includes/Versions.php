<?php
/**
 * Automatic file revisions taken before every save from the editor or image tools.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * versions/{md5(path)}/index.json + {id}.bak
 */
final class Versions {

	const MAX_BYTES = 5242880;

	/**
	 * Folder for a file's versions.
	 *
	 * @param string $abs Absolute path.
	 * @return string
	 */
	private static function folder( $abs ) {
		return Storage::dir( 'versions' ) . '/' . md5( Paths::to_storage( $abs ) );
	}

	/**
	 * Read the index.
	 *
	 * @param string $folder Folder.
	 * @return array
	 */
	private static function index( $folder ) {
		$file = $folder . '/index.json';
		if ( ! is_file( $file ) ) {
			return array(
				'path'     => '',
				'versions' => array(),
			);
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore
		if ( ! is_array( $data ) || ! isset( $data['versions'] ) ) {
			return array(
				'path'     => '',
				'versions' => array(),
			);
		}
		return $data;
	}

	/**
	 * Save the index.
	 *
	 * @param string $folder Folder.
	 * @param array  $index  Index.
	 */
	private static function save_index( $folder, array $index ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $folder . '/index.json', wp_json_encode( $index ) );
	}

	/**
	 * Snapshot the current content of a file before it changes.
	 *
	 * @param string $abs     File.
	 * @param int    $user_id User.
	 * @param string $reason  edit|image|restore.
	 */
	public static function snapshot( $abs, $user_id, $reason = 'edit' ) {
		if ( ! Settings::value( 'general', 'versions', true ) || ! is_file( $abs ) || filesize( $abs ) > self::MAX_BYTES ) {
			return;
		}
		$folder = self::folder( $abs );
		if ( ! is_dir( $folder ) && ! wp_mkdir_p( $folder ) ) {
			return;
		}
		$id = gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 3 ) );
		if ( ! @copy( $abs, $folder . '/' . $id . '.bak' ) ) { // phpcs:ignore
			return;
		}
		$index               = self::index( $folder );
		$index['path']       = Paths::to_storage( $abs );
		$index['versions'][] = array(
			'id'     => $id,
			'time'   => time(),
			'user'   => (int) $user_id,
			'size'   => (int) filesize( $abs ),
			'reason' => $reason,
		);
		$keep = (int) Settings::value( 'general', 'versions_keep', 10 );
		while ( count( $index['versions'] ) > $keep ) {
			$old = array_shift( $index['versions'] );
			@unlink( $folder . '/' . $old['id'] . '.bak' ); // phpcs:ignore
		}
		self::save_index( $folder, $index );
	}

	/**
	 * List versions of a file (newest first).
	 *
	 * @param string $abs File.
	 * @return array
	 */
	public static function all( $abs ) {
		$index = self::index( self::folder( $abs ) );
		$out   = array();
		foreach ( array_reverse( $index['versions'] ) as $v ) {
			$user     = get_userdata( (int) $v['user'] );
			$v['by']  = $user ? $user->display_name : '';
			$out[]    = $v;
		}
		return $out;
	}

	/**
	 * Absolute path of a stored version.
	 *
	 * @param string $abs File.
	 * @param string $id  Version ID.
	 * @return string
	 * @throws Error When missing.
	 */
	public static function file( $abs, $id ) {
		if ( ! preg_match( '/^\d{14}-[a-f0-9]{6}$/', (string) $id ) ) {
			throw Error::not_found();
		}
		$file = self::folder( $abs ) . '/' . $id . '.bak';
		if ( ! is_file( $file ) ) {
			throw Error::not_found();
		}
		return $file;
	}

	/**
	 * Restore a version (the current content is snapshotted first, so it is undoable).
	 *
	 * @param string $abs     File.
	 * @param string $id      Version.
	 * @param int    $user_id User.
	 */
	public static function restore( $abs, $id, $user_id ) {
		$source  = self::file( $abs, $id );
		$content = (string) file_get_contents( $source ); // phpcs:ignore
		self::snapshot( $abs, $user_id, 'restore' );
		Fs::atomic_put( $abs, $content );
		clearstatcache( true, $abs );
	}

	/**
	 * Follow renames / moves (files and folders).
	 *
	 * @param string $old Old absolute path.
	 * @param string $new New absolute path.
	 */
	public static function path_moved( $old, $new ) {
		$root = Storage::base() . '/versions';
		if ( ! is_dir( $root ) ) {
			return;
		}
		$old_s = Paths::to_storage( $old );
		$new_s = Paths::to_storage( $new );
		foreach ( (array) @scandir( $root ) as $name ) { // phpcs:ignore
			if ( ! preg_match( '/^[a-f0-9]{32}$/', $name ) ) {
				continue;
			}
			$folder = $root . '/' . $name;
			$index  = self::index( $folder );
			$path   = (string) $index['path'];
			if ( $path !== $old_s && 0 !== strpos( $path, $old_s . '/' ) ) {
				continue;
			}
			$index['path'] = $new_s . substr( $path, strlen( $old_s ) );
			$target        = $root . '/' . md5( $index['path'] );
			self::save_index( $folder, $index );
			if ( ! is_dir( $target ) ) {
				@rename( $folder, $target ); // phpcs:ignore
			}
		}
	}

	/**
	 * Drop versions of a deleted file.
	 *
	 * @param string $abs File.
	 */
	public static function forget( $abs ) {
		$folder = Storage::base() . '/versions/' . md5( Paths::to_storage( $abs ) );
		if ( is_dir( $folder ) ) {
			Fs::delete_path( $folder );
		}
	}
}
