<?php
/**
 * File manager REST endpoints.
 *
 * @package Filora
 */

namespace Filora\Rest;

use Filora\Error;
use Filora\Fs;
use Filora\Images;
use Filora\Log;
use Filora\Mime;
use Filora\Paths;
use Filora\Policy;
use Filora\Settings;
use Filora\Storage;
use Filora\Stream;
use Filora\Trash;
use Filora\Uploads;
use Filora\Versions;
use Filora\Zipper;

defined( 'ABSPATH' ) || exit;

/**
 * /filora/v1/fs/* and /filora/v1/trash/*
 */
final class Files extends Base {

	/**
	 * Register routes.
	 */
	public function register() {
		$this->route( '/fs/list', 'GET', array( $this, 'listing' ) );
		$this->route( '/fs/tree', 'GET', array( $this, 'tree' ) );
		$this->route( '/fs/info', 'GET', array( $this, 'info' ) );
		$this->route( '/fs/read', 'GET', array( $this, 'read' ) );
		$this->route( '/fs/write', 'POST', array( $this, 'write' ) );
		$this->route( '/fs/mkdir', 'POST', array( $this, 'mkdir' ) );
		$this->route( '/fs/mkfile', 'POST', array( $this, 'mkfile' ) );
		$this->route( '/fs/rename', 'POST', array( $this, 'rename' ) );
		$this->route( '/fs/transfer', 'POST', array( $this, 'transfer' ) );
		$this->route( '/fs/duplicate', 'POST', array( $this, 'duplicate' ) );
		$this->route( '/fs/delete', 'POST', array( $this, 'delete' ) );
		$this->route( '/fs/chmod', 'POST', array( $this, 'chmod' ) );
		$this->route( '/fs/upload', 'POST', array( $this, 'upload' ) );
		$this->route( '/fs/remote', 'POST', array( $this, 'remote' ) );
		$this->route( '/fs/download', 'GET', array( $this, 'download' ) );
		$this->route( '/fs/raw', 'GET', array( $this, 'raw' ) );
		$this->route( '/fs/thumb', 'GET', array( $this, 'thumb' ) );
		$this->route( '/fs/archive', 'POST', array( $this, 'archive' ) );
		$this->route( '/fs/extract', 'POST', array( $this, 'extract' ) );
		$this->route( '/fs/zip-entries', 'GET', array( $this, 'zip_entries' ) );
		$this->route( '/fs/search', 'GET', array( $this, 'search' ) );
		$this->route( '/fs/image', 'POST', array( $this, 'image' ) );
		$this->route( '/fs/versions', 'GET', array( $this, 'versions' ) );
		$this->route( '/fs/version', 'GET', array( $this, 'version' ) );
		$this->route( '/fs/versions/restore', 'POST', array( $this, 'version_restore' ) );
		$this->route( '/fs/vault', 'POST', array( $this, 'to_vault' ) );
		$this->route( '/trash', 'GET', array( $this, 'trash' ) );
		$this->route( '/trash/restore', 'POST', array( $this, 'trash_restore' ) );
		$this->route( '/trash/purge', 'POST', array( $this, 'trash_purge' ) );
	}

	/* Read ---------------------------------------------------------------- */

	/**
	 * GET /fs/list
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function listing( \WP_REST_Request $r ) {
		return $this->fs()->listing( $this->str( $r, 'path', '/' ) );
	}

	/**
	 * GET /fs/tree
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function tree( \WP_REST_Request $r ) {
		return $this->fs()->tree( $this->str( $r, 'path', '/' ) );
	}

	/**
	 * GET /fs/info
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function info( \WP_REST_Request $r ) {
		$fs   = $this->fs();
		$info = $fs->info( $this->str( $r, 'path' ), (bool) $r->get_param( 'size' ) );
		if ( 'file' === $info['type'] && $fs->profile()->can( 'edit' ) ) {
			$info['versions'] = count( Versions::all( $fs->abs( $info['path'] ) ) );
		}
		return $info;
	}

	/**
	 * GET /fs/read
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function read( \WP_REST_Request $r ) {
		$fs = $this->fs();
		if ( ! $fs->profile()->can( 'edit' ) && ! $fs->profile()->can( 'download' ) ) {
			throw Error::forbidden();
		}
		$data             = $fs->read( $this->str( $r, 'path' ) );
		$data['readonly'] = ! $fs->profile()->can( 'edit' ) || ! $data['entry']['writable'] || ( ! $fs->profile()->is_admin && Policy::is_php( $data['entry']['name'] ) ) || ( $fs->profile()->php_locked && Policy::is_php( $data['entry']['name'] ) );
		return $data;
	}

	/* Write --------------------------------------------------------------- */

	/**
	 * POST /fs/write
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function write( \WP_REST_Request $r ) {
		$this->sudo();
		$fs     = $this->fs();
		$path   = $this->str( $r, 'path' );
		$result = $fs->write( $path, (string) $r->get_param( 'content' ), $r->get_param( 'hash' ), $this->str( $r, 'encoding', 'utf-8' ) );
		Log::add( 'edit', $fs->abs( $path ), array( 'size' => $result['entry']['size'] ) );
		return $result;
	}

	/**
	 * POST /fs/mkdir
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function mkdir( \WP_REST_Request $r ) {
		$this->sudo();
		$fs    = $this->fs();
		$entry = $fs->mkdir( $this->str( $r, 'path', '/' ), $this->str( $r, 'name' ) );
		Log::add( 'mkdir', $fs->abs( $entry['path'] ) );
		return $entry;
	}

	/**
	 * POST /fs/mkfile
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function mkfile( \WP_REST_Request $r ) {
		$this->sudo();
		$fs    = $this->fs();
		$entry = $fs->mkfile( $this->str( $r, 'path', '/' ), $this->str( $r, 'name' ), (string) $r->get_param( 'content' ) );
		Log::add( 'mkfile', $fs->abs( $entry['path'] ) );
		return $entry;
	}

	/**
	 * POST /fs/rename
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function rename( \WP_REST_Request $r ) {
		$this->sudo();
		$fs    = $this->fs();
		$old   = $fs->abs( $this->str( $r, 'path' ) );
		$entry = $fs->rename( $this->str( $r, 'path' ), $this->str( $r, 'name' ) );
		Log::add( 'rename', $fs->abs( $entry['path'] ), array( 'from' => basename( $old ) ) );
		return $entry;
	}

	/**
	 * POST /fs/transfer (copy / move)
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function transfer( \WP_REST_Request $r ) {
		$this->sudo();
		$fs       = $this->fs();
		$mode     = 'move' === $this->str( $r, 'mode' ) ? 'move' : 'copy';
		$conflict = in_array( $this->str( $r, 'conflict' ), array( 'rename', 'overwrite', 'skip' ), true ) ? $this->str( $r, 'conflict' ) : 'rename';
		$result   = $fs->transfer( $this->paths( $r ), $this->str( $r, 'dest', '/' ), $mode, $conflict );
		foreach ( $result['done'] as $done ) {
			Log::add( $mode, Paths::join( $fs->profile()->root, $done['to'] ), array( 'from' => $done['from'] ) );
		}
		$fs->touch_usage();
		return $result;
	}

	/**
	 * POST /fs/duplicate
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function duplicate( \WP_REST_Request $r ) {
		$this->sudo();
		$fs     = $this->fs();
		$result = $fs->duplicate( $this->paths( $r ) );
		foreach ( $result['done'] as $done ) {
			Log::add( 'copy', Paths::join( $fs->profile()->root, $done['to'] ), array( 'from' => $done['from'] ) );
		}
		$fs->touch_usage();
		return $result;
	}

	/**
	 * POST /fs/delete
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function delete( \WP_REST_Request $r ) {
		$this->sudo();
		$fs        = $this->fs();
		$permanent = (bool) $r->get_param( 'permanent' );
		$result    = $fs->delete( $this->paths( $r ), $permanent );
		foreach ( $result['done'] as $path ) {
			Log::add( $result['trash'] ? 'trash' : 'delete', Paths::join( $fs->profile()->root, $path ) );
		}
		$fs->touch_usage();
		return $result;
	}

	/**
	 * POST /fs/chmod
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function chmod( \WP_REST_Request $r ) {
		$this->sudo();
		$fs     = $this->fs();
		$result = $fs->chmod( $this->paths( $r ), $this->str( $r, 'mode' ), (bool) $r->get_param( 'recursive' ), $this->str( $r, 'fileMode', '0644' ), $this->str( $r, 'dirMode', '0755' ) );
		foreach ( $result['done'] as $entry ) {
			Log::add( 'chmod', Paths::join( $fs->profile()->root, $entry['path'] ), array( 'mode' => $this->str( $r, 'mode' ) ) );
		}
		return $result;
	}

	/**
	 * POST /fs/upload — one chunk.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function upload( \WP_REST_Request $r ) {
		$this->sudo();
		$fs = $this->fs();
		$p  = $fs->profile();
		$p->require_op( 'upload' );
		$dest     = $this->str( $r, 'path', '/' );
		$relpath  = $this->str( $r, 'relpath' );
		$conflict = in_array( $this->str( $r, 'conflict' ), array( 'rename', 'overwrite', 'skip' ), true ) ? $this->str( $r, 'conflict' ) : 'rename';
		$files    = $r->get_file_params();
		$params   = array(
			'upload_id' => $this->str( $r, 'upload_id' ),
			'index'     => (int) $r->get_param( 'index' ),
			'total'     => (int) $r->get_param( 'total' ),
			'size'      => (int) $r->get_param( 'size' ),
			'name'      => '' !== $relpath ? $relpath : $this->str( $r, 'name' ),
		);
		$fs->dir( $dest ); // Fail early if the destination is invalid.

		$result = Uploads::chunk(
			$params,
			isset( $files['chunk'] ) ? $files['chunk'] : null,
			'u' . $p->user_id,
			static function ( $name, $size ) use ( $p, $fs ) {
				Policy::assert_uploadable( $p, basename( str_replace( '\\', '/', $name ) ) );
				if ( $p->max_upload && $size > $p->max_upload ) {
					/* translators: %s: size */
					throw new Error( 'too_large', sprintf( __( 'This file is too large (max %s).', 'filora' ), size_format( $p->max_upload ) ), 413 );
				}
				$fs->assert_quota( null, $size );
			},
			static function ( $tmp, $name ) use ( $fs, $dest, $conflict ) {
				$entry = Uploads::place( $fs, $tmp, $dest, $name, $conflict );
				Log::add( 'upload', $fs->abs( $entry['path'] ), array( 'size' => $entry['size'] ) );
				$fs->touch_usage();
				return $entry;
			}
		);
		return $result;
	}

	/**
	 * POST /fs/remote — fetch a URL into a folder (admins only; SSRF-safe via wp_safe_remote_get).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function remote( \WP_REST_Request $r ) {
		$this->sudo();
		$fs = $this->fs();
		$p  = $fs->profile();
		$p->require_op( 'upload' );
		if ( ! $p->is_admin ) {
			throw Error::forbidden();
		}
		$url = esc_url_raw( $this->str( $r, 'url' ) );
		if ( ! wp_http_validate_url( $url ) ) {
			throw new Error( 'invalid_url', __( 'Please enter a valid public URL.', 'filora' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $url, 300 );
		if ( is_wp_error( $tmp ) ) {
			throw new Error( 'download_failed', $tmp->get_error_message(), 502 );
		}
		$name = $this->str( $r, 'name' );
		if ( '' === $name ) {
			$name = sanitize_file_name( rawurldecode( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		}
		if ( '' === $name || '.' === $name[0] ) {
			$name = 'download-' . gmdate( 'Ymd-His' );
		}
		try {
			$entry = Uploads::place( $fs, $tmp, $this->str( $r, 'path', '/' ), $name, 'rename' );
		} finally {
			if ( file_exists( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore
			}
		}
		Log::add( 'upload', $fs->abs( $entry['path'] ), array( 'url' => $url ) );
		return $entry;
	}

	/* Streams ------------------------------------------------------------- */

	/**
	 * GET /fs/download — one file, or a zip of several items / a folder.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function download( \WP_REST_Request $r ) {
		$fs = $this->fs();
		$fs->profile()->require_op( 'download' );
		$paths = $this->paths( $r );
		if ( ! $paths ) {
			$paths = array( $this->str( $r, 'path' ) );
		}
		$abs = array();
		foreach ( $paths as $path ) {
			$abs[] = $fs->abs( $path );
		}
		if ( 1 === count( $abs ) && is_file( $abs[0] ) ) {
			if ( ! $fs->profile()->is_admin && 'locked' === $fs->visibility( $abs[0] ) && $fs->is_sensitive( $abs[0] ) ) {
				throw Error::forbidden();
			}
			Log::add( 'download', $abs[0] );
			Stream::send( $abs[0] );
		}
		$name = 1 === count( $abs ) ? basename( $abs[0] ) . '.zip' : 'filora-' . gmdate( 'Ymd-His' ) . '.zip';
		$tmp  = Storage::dir( 'tmp' ) . '/dl-' . bin2hex( random_bytes( 8 ) ) . '.zip';
		$p    = $fs->profile();
		Zipper::create(
			$abs,
			$tmp,
			static function ( $path ) use ( $fs, $p ) {
				return Paths::is_within( $path, $p->root ) && 'hidden' !== $fs->visibility( $path ) && ( $p->is_admin || ! $fs->is_sensitive( $path ) );
			},
			(int) apply_filters( 'filora_zip_max_bytes', 4 * GB_IN_BYTES )
		);
		Log::add( 'download', count( $abs ) === 1 ? $abs[0] : dirname( $abs[0] ), array( 'zip' => count( $abs ) ) );
		Stream::send(
			$tmp,
			array(
				'name'         => $name,
				'delete_after' => true,
			)
		);
	}

	/**
	 * GET /fs/raw — inline preview.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function raw( \WP_REST_Request $r ) {
		$fs = $this->fs();
		$fs->profile()->require_op( 'download' );
		$abs = $fs->abs( $this->str( $r, 'path' ) );
		if ( is_dir( $abs ) ) {
			throw new Error( 'not_file', __( 'This is a folder.', 'filora' ) );
		}
		$mime = Mime::type( $abs );
		if ( Mime::is_text( $abs ) && 'image/svg+xml' !== $mime ) {
			$mime = 'text/plain';
		}
		Stream::send(
			$abs,
			array(
				'inline' => true,
				'mime'   => $mime,
				'cache'  => (int) $r->get_param( 'v' ) ? HOUR_IN_SECONDS : 0,
			)
		);
	}

	/**
	 * GET /fs/thumb
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function thumb( \WP_REST_Request $r ) {
		$fs    = $this->fs();
		$abs   = $fs->abs( $this->str( $r, 'path' ) );
		$thumb = Images::thumb( $abs, (int) $r->get_param( 's' ) );
		if ( ! $thumb ) {
			throw Error::not_found();
		}
		Stream::send(
			$thumb,
			array(
				'inline' => true,
				'cache'  => 30 * DAY_IN_SECONDS,
				'name'   => 'thumb.' . Paths::ext( $thumb ),
			)
		);
	}

	/* Archives ------------------------------------------------------------ */

	/**
	 * POST /fs/archive
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function archive( \WP_REST_Request $r ) {
		$this->sudo();
		$fs = $this->fs();
		$p  = $fs->profile();
		$p->require_op( 'archive' );
		$abs = array();
		foreach ( $this->paths( $r ) as $path ) {
			$abs[] = $fs->abs( $path );
		}
		if ( ! $abs ) {
			throw new Error( 'empty', __( 'Nothing selected.', 'filora' ) );
		}
		$dest = $fs->dir( $this->str( $r, 'dest', $fs->rel( dirname( $abs[0] ) ) ) );
		$fs->assert_writable( $dest );
		$name = Fs::clean_name( $this->str( $r, 'name', 1 === count( $abs ) ? basename( $abs[0] ) : 'archive' ) );
		if ( 'zip' !== Paths::ext( $name ) ) {
			$name .= '.zip';
		}
		Policy::assert_writable_name( $p, $name );
		$name   = Paths::unique_name( $dest, $name );
		$target = $dest . '/' . $name;
		$fs->assert_quota( null, 0 );
		$tmp = Storage::dir( 'tmp' ) . '/ar-' . bin2hex( random_bytes( 8 ) ) . '.zip';
		Zipper::create(
			$abs,
			$tmp,
			static function ( $path ) use ( $fs, $p ) {
				return Paths::is_within( $path, $p->root ) && 'hidden' !== $fs->visibility( $path ) && ( $p->is_admin || ! $fs->is_sensitive( $path ) );
			},
			(int) apply_filters( 'filora_zip_max_bytes', 4 * GB_IN_BYTES )
		);
		Fs::move_path( $tmp, $target );
		@chmod( $target, 0644 ); // phpcs:ignore
		Log::add( 'archive', $target, array( 'items' => count( $abs ) ) );
		$fs->touch_usage();
		return $fs->entry( $target );
	}

	/**
	 * POST /fs/extract
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function extract( \WP_REST_Request $r ) {
		$this->sudo();
		$fs = $this->fs();
		$p  = $fs->profile();
		$p->require_op( 'extract' );
		$archive = $fs->abs( $this->str( $r, 'path' ) );
		if ( 'zip' !== Paths::ext( $archive ) ) {
			throw new Error( 'not_zip', __( 'Only .zip archives can be extracted.', 'filora' ) );
		}
		$dir = dirname( $archive );
		$fs->assert_writable( $dir );
		if ( $r->get_param( 'folder' ) ) {
			$folder = Paths::unique_name( $dir, pathinfo( $archive, PATHINFO_FILENAME ) );
			$fs->mkdir( $fs->rel( $dir ), $folder );
			$dir = $dir . '/' . $folder;
		}
		$max = $p->quota ? max( 0, $p->quota - $fs->used_space( true ) ) : (int) apply_filters( 'filora_extract_max_bytes', 4 * GB_IN_BYTES );
		if ( $p->quota && $max <= 0 ) {
			throw new Error( 'quota', __( 'Your storage quota is full.', 'filora' ), 507 );
		}
		$result = Zipper::extract(
			$archive,
			$dir,
			static function ( $part, $is_dir, $relative ) use ( $fs, $p, $dir ) {
				Policy::assert_writable_name( $p, $part, $is_dir );
				if ( 'ok' !== $fs->visibility( $dir . '/' . $relative ) ) {
					throw Error::forbidden();
				}
			},
			$max
		);
		Log::add( 'extract', $archive, array( 'files' => $result['files'], 'skipped' => count( $result['skipped'] ) ) );
		$fs->touch_usage();
		$result['path'] = $fs->rel( $dir );
		return $result;
	}

	/**
	 * GET /fs/zip-entries
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function zip_entries( \WP_REST_Request $r ) {
		return Zipper::entries( $this->fs()->abs( $this->str( $r, 'path' ) ) );
	}

	/* Misc ---------------------------------------------------------------- */

	/**
	 * GET /fs/search
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function search( \WP_REST_Request $r ) {
		return $this->fs()->search(
			$this->str( $r, 'path', '/' ),
			array(
				'q'       => $this->str( $r, 'q' ),
				'content' => (bool) $r->get_param( 'content' ),
				'ext'     => $this->str( $r, 'ext' ),
				'days'    => (int) $r->get_param( 'days' ),
			)
		);
	}

	/**
	 * POST /fs/image
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function image( \WP_REST_Request $r ) {
		$this->sudo();
		$fs    = $this->fs();
		$args  = $r->get_param( 'args' );
		$entry = Images::edit( $fs, $this->str( $r, 'path' ), $this->str( $r, 'op' ), is_array( $args ) ? $args : array() );
		Log::add( 'image', $fs->abs( $entry['path'] ), array( 'op' => $this->str( $r, 'op' ) ) );
		$fs->touch_usage();
		return $entry;
	}

	/**
	 * GET /fs/versions
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function versions( \WP_REST_Request $r ) {
		$fs = $this->fs();
		$fs->profile()->require_op( 'edit' );
		return Versions::all( $fs->abs( $this->str( $r, 'path' ) ) );
	}

	/**
	 * GET /fs/version — content of one version (text).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function version( \WP_REST_Request $r ) {
		$fs = $this->fs();
		$fs->profile()->require_op( 'edit' );
		$file    = Versions::file( $fs->abs( $this->str( $r, 'path' ) ), $this->str( $r, 'id' ) );
		$content = (string) file_get_contents( $file ); // phpcs:ignore
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$content = mb_convert_encoding( $content, 'UTF-8', 'Windows-1252' );
		}
		return array( 'content' => $content );
	}

	/**
	 * POST /fs/versions/restore
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function version_restore( \WP_REST_Request $r ) {
		$this->sudo();
		$fs = $this->fs();
		$fs->profile()->require_op( 'edit' );
		$abs = $fs->abs( $this->str( $r, 'path' ) );
		$fs->assert_writable( $abs );
		Versions::restore( $abs, $this->str( $r, 'id' ), $fs->profile()->user_id );
		Log::add( 'restore_version', $abs, array( 'version' => $this->str( $r, 'id' ) ) );
		return $fs->entry( $abs );
	}

	/**
	 * POST /fs/vault — move items into the protected Vault.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function to_vault( \WP_REST_Request $r ) {
		$this->sudo();
		$fs    = $this->fs();
		$vault = Paths::real( Storage::vault() );
		if ( ! $vault || ! Paths::is_within( $vault, $fs->profile()->root ) ) {
			throw Error::forbidden( __( 'The Vault is outside of your root folder.', 'filora' ) );
		}
		Storage::ensure();
		return $fs->transfer( $this->paths( $r ), $fs->rel( $vault ), 'move', 'rename' );
	}

	/* Trash --------------------------------------------------------------- */

	/**
	 * GET /trash
	 *
	 * @return array
	 */
	public function trash() {
		return array(
			'items'   => Trash::items( $this->fs()->profile() ),
			'enabled' => (bool) Settings::value( 'general', 'trash', true ),
			'days'    => (int) Settings::value( 'general', 'trash_days', 30 ),
		);
	}

	/**
	 * POST /trash/restore
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function trash_restore( \WP_REST_Request $r ) {
		$this->sudo();
		$fs = $this->fs();
		$fs->profile()->require_op( 'delete' );
		$out = array(
			'done'   => array(),
			'errors' => array(),
		);
		foreach ( $this->paths( $r, 'ids' ) as $id ) {
			try {
				$path          = Trash::restore( $id, $fs );
				$out['done'][] = $path;
				Log::add( 'restore', Paths::join( $fs->profile()->root, $path ) );
			} catch ( Error $e ) {
				$out['errors'][] = array(
					'path'    => $id,
					'message' => $e->getMessage(),
				);
			}
		}
		return $out;
	}

	/**
	 * POST /trash/purge
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function trash_purge( \WP_REST_Request $r ) {
		$this->sudo();
		$fs = $this->fs();
		$fs->profile()->require_op( 'delete' );
		$visible = array();
		foreach ( Trash::items( $fs->profile() ) as $item ) {
			$visible[ $item['id'] ] = $item;
		}
		$ids   = $r->get_param( 'all' ) ? array_keys( $visible ) : $this->paths( $r, 'ids' );
		$count = 0;
		foreach ( $ids as $id ) {
			if ( isset( $visible[ $id ] ) && Trash::purge( $id ) ) {
				++$count;
				Log::add( 'purge', Paths::join( $fs->profile()->root, $visible[ $id ]['path'] ) );
			}
		}
		return array( 'purged' => $count );
	}
}
