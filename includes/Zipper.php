<?php
/**
 * Zip creation and safe extraction (no shell commands).
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * ZipArchive wrapper with Zip-Slip, symlink and zip-bomb protection.
 */
final class Zipper {

	const STORE_EXTS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'mov', 'm4v', 'webm', 'mkv', 'mp3', 'm4a', 'aac', 'ogg', 'zip', 'gz', 'tgz', 'rar', '7z', 'xz', 'bz2', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'woff', 'woff2', 'pdf' );

	/**
	 * Is zip support available?
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Build a zip archive.
	 *
	 * @param string[] $items    Absolute paths (files or folders).
	 * @param string   $target   Zip file to create.
	 * @param callable $accept   fn( string $abs ): bool — whether to include an item.
	 * @param int      $max_size Max total uncompressed bytes (0 = unlimited).
	 * @return int Number of files added.
	 * @throws Error On failure / limits.
	 */
	public static function create( array $items, $target, $accept, $max_size = 0 ) {
		if ( ! self::available() ) {
			throw new Error( 'no_zip', __( 'The ZipArchive PHP extension is not available on this server.', 'filora' ), 501 );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new Error( 'zip_failed', __( 'The archive could not be created.', 'filora' ), 500 );
		}
		$total = 0;
		$count = 0;
		foreach ( $items as $abs ) {
			self::add( $zip, $abs, basename( $abs ), $accept, $max_size, $total, $count );
		}
		if ( 0 === $count ) {
			$zip->addEmptyDir( '.' === basename( $target ) ? 'empty' : pathinfo( $target, PATHINFO_FILENAME ) );
		}
		if ( ! $zip->close() ) {
			throw new Error( 'zip_failed', __( 'The archive could not be created.', 'filora' ), 500 );
		}
		return $count;
	}

	/**
	 * Add an item recursively.
	 *
	 * @param \ZipArchive $zip      Archive.
	 * @param string      $abs      Path.
	 * @param string      $local    Name inside the archive.
	 * @param callable    $accept   Filter.
	 * @param int         $max_size Limit.
	 * @param int         $total    Running size.
	 * @param int         $count    Running count.
	 * @throws Error On limits.
	 */
	private static function add( \ZipArchive $zip, $abs, $local, $accept, $max_size, &$total, &$count ) {
		if ( is_link( $abs ) || ! call_user_func( $accept, $abs ) ) {
			return;
		}
		if ( is_dir( $abs ) ) {
			$zip->addEmptyDir( $local );
			foreach ( (array) @scandir( $abs ) as $name ) { // phpcs:ignore
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				self::add( $zip, $abs . '/' . $name, $local . '/' . $name, $accept, $max_size, $total, $count );
			}
			return;
		}
		$total += (int) @filesize( $abs ); // phpcs:ignore
		if ( $max_size && $total > $max_size ) {
			throw new Error( 'zip_too_large', __( 'The selection is too large to be zipped. Download the files individually.', 'filora' ), 413 );
		}
		if ( ++$count > 50000 ) {
			throw new Error( 'zip_too_many', __( 'Too many files to create an archive.', 'filora' ), 413 );
		}
		$zip->addFile( $abs, $local );
		if ( method_exists( $zip, 'setCompressionName' ) && in_array( Paths::ext( $abs ), self::STORE_EXTS, true ) ) {
			$zip->setCompressionName( $local, \ZipArchive::CM_STORE );
		}
	}

	/**
	 * Extract an archive into a folder, validating every entry.
	 *
	 * @param string   $archive  Zip file.
	 * @param string   $dest     Existing destination folder.
	 * @param callable $check    fn( string $name, bool $is_dir, string $relative ) throws Error when not allowed.
	 * @param int      $max_size Max uncompressed bytes.
	 * @return array{files:int,skipped:array}
	 * @throws Error On failure.
	 */
	public static function extract( $archive, $dest, $check, $max_size, $after = null ) {
		if ( ! self::available() ) {
			throw new Error( 'no_zip', __( 'The ZipArchive PHP extension is not available on this server.', 'filora' ), 501 );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archive ) ) {
			throw new Error( 'zip_invalid', __( 'This archive is invalid or corrupted.', 'filora' ), 422 );
		}
		$declared = 0;
		$entries  = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! $stat ) {
				continue;
			}
			$declared += (int) $stat['size'];
			$entries[] = $stat;
		}
		if ( count( $entries ) > 50000 ) {
			$zip->close();
			throw new Error( 'zip_too_many', __( 'This archive contains too many files.', 'filora' ), 413 );
		}
		if ( $max_size && $declared > $max_size ) {
			$zip->close();
			throw new Error( 'zip_too_large', __( 'This archive is too large to be extracted here.', 'filora' ), 413 );
		}

		$written = 0;
		$files   = 0;
		$skipped = array();
		foreach ( $entries as $stat ) {
			$raw  = (string) $stat['name'];
			$name = str_replace( '\\', '/', $raw );
			// Zip-Slip: absolute paths, drive letters, "..", control characters.
			if ( '' === $name || '/' === $name[0] || preg_match( '#^[A-Za-z]:#', $name ) || preg_match( '/[\x00-\x1F]/', $name ) ) {
				$skipped[] = $raw;
				continue;
			}
			$clean = Paths::normalize( $name );
			if ( null === $clean || '' === $clean || false !== strpos( '/' . $clean . '/', '/../' ) ) {
				$skipped[] = $raw;
				continue;
			}
			if ( 0 === strpos( $clean, '__MACOSX/' ) || '.DS_Store' === basename( $clean ) ) {
				continue;
			}
			// Symlink entries (unix mode in the external attributes).
			if ( method_exists( $zip, 'getExternalAttributesIndex' ) ) {
				$opsys = 0;
				$attr  = 0;
				if ( $zip->getExternalAttributesIndex( $stat['index'], $opsys, $attr ) && \ZipArchive::OPSYS_UNIX === $opsys && 0120000 === ( ( $attr >> 16 ) & 0170000 ) ) {
					$skipped[] = $raw;
					continue;
				}
			}
			$is_dir = '/' === substr( $name, -1 );
			$parts  = explode( '/', $clean );
			try {
				$sofar = '';
				foreach ( $parts as $index => $part ) {
					$sofar = '' === $sofar ? $part : $sofar . '/' . $part;
					call_user_func( $check, $part, $is_dir || $index < count( $parts ) - 1, $sofar );
				}
			} catch ( Error $e ) {
				$skipped[] = $raw;
				continue;
			}
			$target = $dest . '/' . $clean;
			if ( $is_dir ) {
				wp_mkdir_p( $target );
				continue;
			}
			wp_mkdir_p( dirname( $target ) );
			$in  = $zip->getStream( $raw );
			$out = @fopen( $target, 'wb' ); // phpcs:ignore
			if ( ! $in || ! $out ) {
				$skipped[] = $raw;
				continue;
			}
			while ( ! feof( $in ) ) {
				$chunk    = fread( $in, 1048576 ); // phpcs:ignore
				$written += strlen( (string) $chunk );
				if ( $max_size && $written > $max_size ) {
					fclose( $in ); // phpcs:ignore
					fclose( $out ); // phpcs:ignore
					@unlink( $target ); // phpcs:ignore
					$zip->close();
					throw new Error( 'zip_too_large', __( 'This archive expands beyond the allowed size and was stopped.', 'filora' ), 413 );
				}
				fwrite( $out, (string) $chunk ); // phpcs:ignore
			}
			fclose( $in ); // phpcs:ignore
			fclose( $out ); // phpcs:ignore
			@chmod( $target, 0644 ); // phpcs:ignore
			if ( $after ) {
				try {
					call_user_func( $after, $target, basename( $clean ) );
				} catch ( Error $e ) {
					@unlink( $target ); // phpcs:ignore
					$skipped[] = $raw;
					continue;
				}
			}
			++$files;
		}
		$zip->close();
		return array(
			'files'   => $files,
			'skipped' => $skipped,
		);
	}

	/**
	 * List entries (preview before extracting).
	 *
	 * @param string $archive Zip file.
	 * @return array
	 */
	public static function entries( $archive ) {
		if ( ! self::available() ) {
			throw new Error( 'no_zip', __( 'The ZipArchive PHP extension is not available on this server.', 'filora' ), 501 );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archive ) ) {
			throw new Error( 'zip_invalid', __( 'This archive is invalid or corrupted.', 'filora' ), 422 );
		}
		$out = array();
		for ( $i = 0; $i < min( $zip->numFiles, 2000 ); $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( $stat ) {
				$out[] = array(
					'name'  => $stat['name'],
					'size'  => (int) $stat['size'],
					'csize' => (int) $stat['comp_size'],
					'mtime' => (int) $stat['mtime'],
				);
			}
		}
		$total = $zip->numFiles;
		$zip->close();
		return array(
			'entries' => $out,
			'total'   => $total,
		);
	}
}
