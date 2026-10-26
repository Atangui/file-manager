<?php
/**
 * Thumbnails (cached privately) and simple image edits through WP_Image_Editor.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Image helpers.
 */
final class Images {

	const MAX_PIXELS = 40000000;

	/**
	 * Path of a cached thumbnail, generated on demand.
	 *
	 * @param string $abs  Image.
	 * @param int    $size Box size.
	 * @return string|null
	 */
	public static function thumb( $abs, $size = 320 ) {
		$size = in_array( (int) $size, array( 64, 160, 320, 640, 1280 ), true ) ? (int) $size : 320;
		if ( ! Mime::is_thumbable( $abs ) || ! is_file( $abs ) ) {
			return null;
		}
		$bytes = (int) filesize( $abs );
		if ( $bytes > 30 * MB_IN_BYTES ) {
			return null;
		}
		$key  = md5( $abs . '|' . filemtime( $abs ) . '|' . $bytes . '|' . $size );
		$dir  = Storage::dir( 'cache/thumbs/' . substr( $key, 0, 2 ) );
		$webp = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		$file = $dir . '/' . $key . ( $webp ? '.webp' : '.jpg' );
		if ( is_file( $file ) ) {
			return $file;
		}
		if ( is_file( $file . '.fail' ) ) {
			return null;
		}
		$dim = @getimagesize( $abs ); // phpcs:ignore
		if ( ! $dim || $dim[0] * $dim[1] > self::MAX_PIXELS ) {
			touch( $file . '.fail' );
			return null;
		}
		if ( $dim[0] <= $size && $dim[1] <= $size && $bytes < 200 * KB_IN_BYTES ) {
			return $abs; // Already small: serve the original.
		}
		$editor = wp_get_image_editor( $abs );
		if ( is_wp_error( $editor ) ) {
			touch( $file . '.fail' );
			return null;
		}
		$editor->resize( $size, $size, false );
		$editor->set_quality( 78 );
		$saved = $editor->save( $file, $webp ? 'image/webp' : 'image/jpeg' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			touch( $file . '.fail' );
			return null;
		}
		return $saved['path'];
	}

	/**
	 * Apply an operation and save (in place or as a copy).
	 *
	 * @param Fs     $fs   Filesystem.
	 * @param string $rel  Image.
	 * @param string $op   rotate|flip|resize|crop|convert.
	 * @param array  $args Parameters.
	 * @return array New entry.
	 * @throws Error On failure.
	 */
	public static function edit( Fs $fs, $rel, $op, array $args ) {
		$p = $fs->profile();
		$p->require_op( 'image' );
		$abs = $fs->abs( $rel );
		if ( ! Mime::is_thumbable( $abs ) ) {
			throw new Error( 'not_image', __( 'This file is not an editable image.', 'filora' ) );
		}
		$dim = @getimagesize( $abs ); // phpcs:ignore
		if ( ! $dim || $dim[0] * $dim[1] > self::MAX_PIXELS ) {
			throw new Error( 'too_large', __( 'This image is too large to be edited on this server.', 'filora' ), 413 );
		}
		$editor = wp_get_image_editor( $abs );
		if ( is_wp_error( $editor ) ) {
			throw new Error( 'no_editor', __( 'No image library (GD or Imagick) is available.', 'filora' ), 501 );
		}
		$mime = null;
		switch ( $op ) {
			case 'rotate':
				$angle = (int) ( isset( $args['angle'] ) ? $args['angle'] : 90 );
				if ( ! in_array( $angle, array( 90, 180, 270, -90 ), true ) ) {
					throw new Error( 'invalid', __( 'Invalid angle.', 'filora' ) );
				}
				$res = $editor->rotate( -$angle ); // WP rotates counter-clockwise.
				break;
			case 'flip':
				$res = $editor->flip( ! empty( $args['vertical'] ), empty( $args['vertical'] ) );
				break;
			case 'resize':
				$w   = max( 1, min( 10000, (int) ( isset( $args['width'] ) ? $args['width'] : 0 ) ) );
				$h   = max( 0, min( 10000, (int) ( isset( $args['height'] ) ? $args['height'] : 0 ) ) );
				$res = $editor->resize( $w, $h ? $h : null, false );
				break;
			case 'crop':
				$res = $editor->crop( (int) $args['x'], (int) $args['y'], max( 1, (int) $args['width'] ), max( 1, (int) $args['height'] ) );
				break;
			case 'convert':
				$formats = array(
					'webp' => 'image/webp',
					'jpg'  => 'image/jpeg',
					'png'  => 'image/png',
					'avif' => 'image/avif',
				);
				$format  = isset( $args['format'] ) ? (string) $args['format'] : 'webp';
				if ( ! isset( $formats[ $format ] ) || ! wp_image_editor_supports( array( 'mime_type' => $formats[ $format ] ) ) ) {
					throw new Error( 'unsupported', __( 'This format is not supported by the server.', 'filora' ), 501 );
				}
				$mime = $formats[ $format ];
				$res  = true;
				break;
			default:
				throw new Error( 'invalid', __( 'Unknown image operation.', 'filora' ) );
		}
		if ( is_wp_error( $res ) ) {
			throw new Error( 'image_failed', $res->get_error_message(), 500 );
		}
		if ( isset( $args['quality'] ) ) {
			$editor->set_quality( max( 10, min( 100, (int) $args['quality'] ) ) );
		}

		$dir     = dirname( $abs );
		$as_copy = ! empty( $args['copy'] ) || null !== $mime;
		if ( $as_copy ) {
			$fs->assert_writable( $dir );
			$base   = pathinfo( $abs, PATHINFO_FILENAME );
			$ext    = $mime ? array_search( $mime, array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'avif' => 'image/avif' ), true ) : Paths::ext( $abs );
			$name   = Paths::unique_name( $dir, $base . '.' . $ext, null === $mime ? __( 'edited', 'filora' ) : '' );
			Policy::assert_writable_name( $p, $name );
			$target = $dir . '/' . $name;
		} else {
			$fs->assert_writable( $abs );
			Versions::snapshot( $abs, $p->user_id, 'image' );
			$target = $abs;
		}
		$saved = $editor->save( $target, $mime );
		if ( is_wp_error( $saved ) ) {
			throw new Error( 'image_failed', $saved->get_error_message(), 500 );
		}
		clearstatcache( true, $target );
		return $fs->entry( Paths::real( $saved['path'] ) );
	}
}
