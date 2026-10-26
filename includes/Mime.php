<?php
/**
 * MIME types and file categories.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Extension based type detection (fast, no file reads).
 */
final class Mime {

	const MAP = array(
		'jpg'   => 'image/jpeg',
		'jpeg'  => 'image/jpeg',
		'jpe'   => 'image/jpeg',
		'png'   => 'image/png',
		'gif'   => 'image/gif',
		'webp'  => 'image/webp',
		'avif'  => 'image/avif',
		'bmp'   => 'image/bmp',
		'ico'   => 'image/x-icon',
		'svg'   => 'image/svg+xml',
		'svgz'  => 'image/svg+xml',
		'heic'  => 'image/heic',
		'tif'   => 'image/tiff',
		'tiff'  => 'image/tiff',
		'psd'   => 'image/vnd.adobe.photoshop',
		'mp4'   => 'video/mp4',
		'm4v'   => 'video/mp4',
		'mov'   => 'video/quicktime',
		'webm'  => 'video/webm',
		'ogv'   => 'video/ogg',
		'avi'   => 'video/x-msvideo',
		'mkv'   => 'video/x-matroska',
		'wmv'   => 'video/x-ms-wmv',
		'mp3'   => 'audio/mpeg',
		'm4a'   => 'audio/mp4',
		'aac'   => 'audio/aac',
		'wav'   => 'audio/wav',
		'ogg'   => 'audio/ogg',
		'oga'   => 'audio/ogg',
		'flac'  => 'audio/flac',
		'opus'  => 'audio/opus',
		'pdf'   => 'application/pdf',
		'zip'   => 'application/zip',
		'gz'    => 'application/gzip',
		'tgz'   => 'application/gzip',
		'tar'   => 'application/x-tar',
		'rar'   => 'application/vnd.rar',
		'7z'    => 'application/x-7z-compressed',
		'doc'   => 'application/msword',
		'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'   => 'application/vnd.ms-excel',
		'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ppt'   => 'application/vnd.ms-powerpoint',
		'pptx'  => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'odt'   => 'application/vnd.oasis.opendocument.text',
		'ods'   => 'application/vnd.oasis.opendocument.spreadsheet',
		'odp'   => 'application/vnd.oasis.opendocument.presentation',
		'rtf'   => 'application/rtf',
		'csv'   => 'text/csv',
		'txt'   => 'text/plain',
		'md'    => 'text/markdown',
		'json'  => 'application/json',
		'xml'   => 'application/xml',
		'html'  => 'text/html',
		'htm'   => 'text/html',
		'css'   => 'text/css',
		'js'    => 'text/javascript',
		'mjs'   => 'text/javascript',
		'woff'  => 'font/woff',
		'woff2' => 'font/woff2',
		'ttf'   => 'font/ttf',
		'otf'   => 'font/otf',
		'eot'   => 'application/vnd.ms-fontobject',
	);

	const TEXT_EXTS = array( 'txt', 'md', 'markdown', 'csv', 'tsv', 'log', 'json', 'xml', 'html', 'htm', 'xhtml', 'css', 'scss', 'sass', 'less', 'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'vue', 'svelte', 'php', 'phtml', 'inc', 'twig', 'blade', 'tpl', 'mustache', 'hbs', 'py', 'rb', 'pl', 'sh', 'bash', 'zsh', 'sql', 'yml', 'yaml', 'toml', 'ini', 'conf', 'cfg', 'env', 'htaccess', 'htpasswd', 'gitignore', 'gitattributes', 'editorconfig', 'svg', 'po', 'pot', 'lock', 'map', 'txt', 'rst', 'tex', 'bat', 'ps1', 'go', 'rs', 'java', 'kt', 'c', 'h', 'cpp', 'hpp', 'cs', 'swift', 'dart', 'lua', 'r', 'graphql', 'gql', 'dockerfile', 'nginx', 'vcf', 'ics', 'srt', 'vtt' );

	/**
	 * Inline-safe types: rendered by the browser without running script on our origin.
	 */
	const INLINE_SAFE = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/x-icon', 'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/wav', 'audio/ogg', 'audio/flac', 'audio/opus', 'application/pdf' );

	/**
	 * MIME type from a name.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function type( $name ) {
		$ext = Paths::ext( $name );
		if ( isset( self::MAP[ $ext ] ) ) {
			return self::MAP[ $ext ];
		}
		if ( self::is_text( $name ) ) {
			return 'text/plain';
		}
		$wp = wp_check_filetype( $name );
		return ! empty( $wp['type'] ) ? $wp['type'] : 'application/octet-stream';
	}

	/**
	 * Is this a text-like file (editable)?
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	public static function is_text( $name ) {
		$base = strtolower( basename( (string) $name ) );
		if ( in_array( $base, array( 'dockerfile', 'makefile', 'license', 'readme', 'changelog', '.env', '.htaccess', '.gitignore', '.user.ini', 'error_log' ), true ) ) {
			return true;
		}
		return in_array( Paths::ext( $name ), self::TEXT_EXTS, true );
	}

	/**
	 * Cheap binary sniffing for files without a known text extension.
	 *
	 * @param string $abs Absolute path.
	 * @return bool
	 */
	public static function looks_binary( $abs ) {
		$fh = @fopen( $abs, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return true;
		}
		$chunk = (string) fread( $fh, 8000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return false !== strpos( $chunk, "\0" );
	}

	/**
	 * Category for icons / previews.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function category( $name ) {
		$ext  = Paths::ext( $name );
		$mime = self::type( $name );
		if ( 0 === strpos( $mime, 'image/' ) ) {
			return 'image';
		}
		if ( 0 === strpos( $mime, 'video/' ) ) {
			return 'video';
		}
		if ( 0 === strpos( $mime, 'audio/' ) ) {
			return 'audio';
		}
		if ( 'pdf' === $ext ) {
			return 'pdf';
		}
		if ( in_array( $ext, array( 'zip', 'gz', 'tgz', 'tar', 'rar', '7z', 'bz2', 'xz' ), true ) ) {
			return 'archive';
		}
		if ( in_array( $ext, array( 'doc', 'docx', 'odt', 'rtf', 'pages' ), true ) ) {
			return 'doc';
		}
		if ( in_array( $ext, array( 'xls', 'xlsx', 'ods', 'csv', 'tsv', 'numbers' ), true ) ) {
			return 'sheet';
		}
		if ( in_array( $ext, array( 'ppt', 'pptx', 'odp', 'key' ), true ) ) {
			return 'slides';
		}
		if ( in_array( $ext, array( 'woff', 'woff2', 'ttf', 'otf', 'eot' ), true ) ) {
			return 'font';
		}
		if ( self::is_text( $name ) ) {
			return 'code';
		}
		return 'file';
	}

	/**
	 * Can this MIME type be displayed inline safely?
	 *
	 * @param string $mime MIME.
	 * @return bool
	 */
	public static function inline_safe( $mime ) {
		return in_array( $mime, self::INLINE_SAFE, true );
	}

	/**
	 * Raster image that GD/Imagick can thumbnail?
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	public static function is_thumbable( $name ) {
		return in_array( Paths::ext( $name ), array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'bmp', 'avif' ), true );
	}
}
