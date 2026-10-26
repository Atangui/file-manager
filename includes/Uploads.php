<?php
/**
 * Chunked uploads: large files go through in small pieces, so PHP's
 * upload_max_filesize / post_max_size limits no longer cap file sizes.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the file manager and public upload links.
 */
final class Uploads {

	/**
	 * Recommended chunk size for this server (bytes).
	 *
	 * @return int
	 */
	public static function chunk_size() {
		$server = (int) wp_max_upload_size();
		$size   = $server > 0 ? min( $server - 65536, 8 * MB_IN_BYTES ) : 2 * MB_IN_BYTES;
		return (int) apply_filters( 'filora_chunk_size', max( 256 * KB_IN_BYTES, $size ) );
	}

	/**
	 * Handle one chunk.
	 *
	 * @param array      $params   upload_id, index, total, size, name.
	 * @param array|null $file     $_FILES entry of the chunk.
	 * @param string     $owner    Owner key (user or share visitor) — an upload can only be continued by its owner.
	 * @param callable   $validate fn( string $name, int $size ) — throws Error to refuse before receiving data.
	 * @param callable   $finalize fn( string $tmp, string $name ): array — moves the assembled file into place.
	 * @return array
	 * @throws Error On invalid chunks.
	 */
	public static function chunk( array $params, $file, $owner, $validate, $finalize ) {
		$id    = isset( $params['upload_id'] ) ? (string) $params['upload_id'] : '';
		$index = isset( $params['index'] ) ? (int) $params['index'] : 0;
		$total = isset( $params['total'] ) ? (int) $params['total'] : 1;
		$size  = isset( $params['size'] ) ? (int) $params['size'] : 0;
		$name  = isset( $params['name'] ) ? trim( (string) $params['name'] ) : '';

		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $id ) || $index < 0 || $total < 1 || $index >= $total || $total > 200000 || $size < 0 ) {
			throw new Error( 'invalid_chunk', __( 'Invalid upload request.', 'filora' ) );
		}

		$tmp  = Storage::dir( 'tmp' );
		$key  = sha1( $owner . '|' . $id );
		$meta = $tmp . '/' . $key . '.json';
		$part = $tmp . '/' . $key . '.part';

		$has_file = is_array( $file ) && isset( $file['tmp_name'] ) && '' !== $file['tmp_name'];
		if ( is_array( $file ) && isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] && UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
			throw new Error( 'upload_error', self::php_error( (int) $file['error'] ), 413 );
		}
		if ( $has_file && ! is_uploaded_file( $file['tmp_name'] ) && ! apply_filters( 'filora_allow_non_uploaded_chunk', false ) ) {
			throw new Error( 'invalid_chunk', __( 'Invalid upload request.', 'filora' ) );
		}
		if ( ! $has_file && $size > 0 ) {
			throw new Error( 'upload_error', __( 'The upload was interrupted. Please retry.', 'filora' ) );
		}

		if ( 0 === $index ) {
			call_user_func( $validate, $name, $size );
			$state = array(
				'owner'    => $owner,
				'name'     => $name,
				'size'     => $size,
				'total'    => $total,
				'next'     => 0,
				'received' => 0,
				'created'  => time(),
			);
			@unlink( $part ); // phpcs:ignore
		} else {
			$state = is_file( $meta ) ? json_decode( (string) file_get_contents( $meta ), true ) : null; // phpcs:ignore
			if ( ! is_array( $state ) || $state['owner'] !== $owner || $state['name'] !== $name || (int) $state['size'] !== $size || (int) $state['total'] !== $total ) {
				throw new Error( 'invalid_chunk', __( 'This upload expired. Please retry.', 'filora' ), 409 );
			}
			if ( $index < (int) $state['next'] ) {
				return array(
					'done'     => false,
					'received' => (int) $state['received'],
				);
			}
			if ( $index !== (int) $state['next'] ) {
				throw new Error( 'invalid_chunk', __( 'Upload chunks arrived out of order. Please retry.', 'filora' ), 409 );
			}
		}

		if ( $has_file ) {
			$in  = fopen( $file['tmp_name'], 'rb' ); // phpcs:ignore
			$out = fopen( $part, 'ab' ); // phpcs:ignore
			if ( ! $in || ! $out ) {
				throw new Error( 'upload_error', __( 'The server could not store the upload (temporary folder not writable).', 'filora' ), 500 );
			}
			$copied = stream_copy_to_stream( $in, $out );
			fclose( $in ); // phpcs:ignore
			fclose( $out ); // phpcs:ignore
			@unlink( $file['tmp_name'] ); // phpcs:ignore
			$state['received'] += (int) $copied;
		} elseif ( ! file_exists( $part ) ) {
			touch( $part );
		}

		if ( (int) $state['received'] > $size ) {
			@unlink( $part ); // phpcs:ignore
			@unlink( $meta ); // phpcs:ignore
			throw new Error( 'invalid_chunk', __( 'The uploaded data is larger than announced.', 'filora' ) );
		}
		$state['next'] = $index + 1;

		if ( $state['next'] < $total ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $meta, wp_json_encode( $state ) );
			return array(
				'done'     => false,
				'received' => (int) $state['received'],
			);
		}

		@unlink( $meta ); // phpcs:ignore
		if ( (int) $state['received'] !== $size ) {
			@unlink( $part ); // phpcs:ignore
			throw new Error( 'upload_error', __( 'The upload is incomplete. Please retry.', 'filora' ) );
		}
		try {
			$result = call_user_func( $finalize, $part, $name );
		} finally {
			if ( file_exists( $part ) ) {
				@unlink( $part ); // phpcs:ignore
			}
		}
		return array(
			'done'   => true,
			'result' => $result,
		);
	}

	/**
	 * Translate PHP upload error codes.
	 *
	 * @param int $code Code.
	 * @return string
	 */
	private static function php_error( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The server refused the upload: the chunk exceeds the PHP size limit.', 'filora' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The upload was interrupted. Please retry.', 'filora' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not store the upload (temporary folder not writable).', 'filora' );
			default:
				return __( 'The upload failed.', 'filora' );
		}
	}

	/**
	 * Remove abandoned uploads (cron).
	 */
	public static function cleanup() {
		$dir = Storage::base() . '/tmp';
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) @scandir( $dir ) as $name ) { // phpcs:ignore
			$path = $dir . '/' . $name;
			if ( is_file( $path ) && filemtime( $path ) < time() - DAY_IN_SECONDS ) {
				@unlink( $path ); // phpcs:ignore
			}
		}
	}

	/**
	 * Move an assembled upload into a folder, creating sub-folders for folder uploads.
	 *
	 * @param Fs     $fs       Filesystem.
	 * @param string $tmp      Assembled file.
	 * @param string $dest     Destination folder (relative).
	 * @param string $relpath  Relative path inside the destination ("photos/2024/a.jpg") or just a name.
	 * @param string $conflict rename|overwrite|skip.
	 * @return array Entry.
	 * @throws Error On policy errors.
	 */
	public static function place( Fs $fs, $tmp, $dest, $relpath, $conflict = 'rename' ) {
		$p        = $fs->profile();
		$dir      = $fs->dir( $dest );
		$segments = array_values( array_filter( explode( '/', str_replace( '\\', '/', (string) $relpath ) ), 'strlen' ) );
		$name     = array_pop( $segments );
		Policy::assert_uploadable( $p, (string) $name );
		foreach ( $segments as $segment ) {
			$segment = Fs::clean_name( $segment );
			if ( '..' === $segment ) {
				throw new Error( 'invalid_path', __( 'Invalid path.', 'filora' ) );
			}
			$next = $dir . '/' . $segment;
			if ( ! is_dir( $next ) ) {
				if ( ! $p->can( 'mkdir' ) && ! $p->can( 'upload' ) ) {
					throw Error::forbidden();
				}
				if ( 'ok' !== $fs->visibility( $next ) || ! @mkdir( $next, 0755 ) ) { // phpcs:ignore
					throw new Error( 'mkdir_failed', __( 'The folder could not be created.', 'filora' ), 500 );
				}
			}
			$dir = Paths::real( $next );
			if ( ! $dir || ! Paths::is_within( $dir, $p->root ) ) {
				throw Error::forbidden();
			}
		}
		$fs->assert_writable( $dir );
		$target = $dir . '/' . $name;
		if ( file_exists( $target ) ) {
			if ( 'skip' === $conflict ) {
				throw new Error( 'exists', __( 'An item with this name already exists.', 'filora' ), 409 );
			}
			if ( 'overwrite' === $conflict && is_file( $target ) ) {
				$fs->assert_writable( $target );
				Versions::snapshot( $target, $p->user_id, 'upload' );
			} else {
				$target = $dir . '/' . Paths::unique_name( $dir, $name );
			}
		}
		if ( 'ok' !== $fs->visibility( $target ) ) {
			throw Error::forbidden( __( 'This name is reserved.', 'filora' ) );
		}
		if ( ! $p->is_admin ) {
			self::assert_content_matches( $tmp, basename( $target ) );
		}
		if ( ! @rename( $tmp, $target ) && ! ( @copy( $tmp, $target ) && @unlink( $tmp ) ) ) { // phpcs:ignore
			throw new Error( 'write_failed', __( 'The file could not be saved.', 'filora' ), 500 );
		}
		@chmod( $target, 0644 ); // phpcs:ignore
		return $fs->entry( $target );
	}

	/**
	 * Refuse files whose content contradicts their extension (WordPress' own check), when
	 * WordPress knows the type. Prevents e.g. a PHP payload disguised as "photo.jpg".
	 *
	 * @param string $tmp  File.
	 * @param string $name Name.
	 * @throws Error On mismatch.
	 */
	public static function assert_content_matches( $tmp, $name ) {
		$known = wp_check_filetype( $name );
		if ( empty( $known['type'] ) ) {
			return;
		}
		$check = wp_check_filetype_and_ext( $tmp, $name );
		if ( empty( $check['ext'] ) && 0 === strpos( (string) $known['type'], 'image/' ) ) {
			throw new Error( 'type_mismatch', __( 'The file content does not match its extension.', 'filora' ), 415 );
		}
		// Look for PHP open tags in files that should never contain code.
		$head = (string) @file_get_contents( $tmp, false, null, 0, 65536 ); // phpcs:ignore
		if ( preg_match( '/<\?php|<\?=|<script\b/i', $head ) && in_array( Mime::category( $name ), array( 'image', 'pdf', 'video', 'audio' ), true ) ) {
			throw new Error( 'type_mismatch', __( 'The file content does not match its extension.', 'filora' ), 415 );
		}
	}
}
