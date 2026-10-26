<?php
/**
 * Sandboxed filesystem operations for one profile.
 *
 * Every method takes paths relative to the profile root, resolves them with realpath()
 * (so symlinks pointing outside the root are rejected), applies the hidden / locked /
 * sensitive policy, and never shells out.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Filesystem facade.
 */
final class Fs {

	const EDIT_MAX_BYTES = 5242880;
	const LIST_MAX       = 20000;

	/**
	 * Profile.
	 *
	 * @var Profile
	 */
	private $p;

	/**
	 * Share paths cache for "shared" badges.
	 *
	 * @var array|null
	 */
	private $shared = null;

	/**
	 * Constructor.
	 *
	 * @param Profile $profile Profile.
	 */
	public function __construct( Profile $profile ) {
		$this->p = $profile;
	}

	/**
	 * Profile accessor.
	 *
	 * @return Profile
	 */
	public function profile() {
		return $this->p;
	}

	/* ---------------------------------------------------------------------
	 * Resolution & policy
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve an existing path.
	 *
	 * @param string $rel Relative path.
	 * @return string Absolute path.
	 * @throws Error When missing, hidden or outside the root.
	 */
	public function abs( $rel ) {
		$clean = Paths::clean_rel( $rel );
		$path  = Paths::join( $this->p->root, $clean );
		$real  = Paths::real( $path );
		if ( false === $real || ! Paths::is_within( $real, $this->p->root ) ) {
			throw Error::not_found();
		}
		if ( 'hidden' === $this->visibility( $real ) ) {
			throw Error::not_found();
		}
		return $real;
	}

	/**
	 * Resolve a directory that must exist.
	 *
	 * @param string $rel Relative path.
	 * @return string
	 * @throws Error When not a directory.
	 */
	public function dir( $rel ) {
		$abs = $this->abs( $rel );
		if ( ! is_dir( $abs ) ) {
			throw new Error( 'not_dir', __( 'This is not a folder.', 'filora' ) );
		}
		return $abs;
	}

	/**
	 * Path relative to the root, with a leading slash.
	 *
	 * @param string $abs Absolute path.
	 * @return string
	 */
	public function rel( $abs ) {
		return '/' . Paths::relative( $abs, $this->p->root );
	}

	/**
	 * Visibility of a path for this profile: ok | locked | hidden.
	 *
	 * @param string $abs Absolute (real) path.
	 * @return string
	 */
	public function visibility( $abs ) {
		if ( Paths::is_within( $abs, Storage::base() ) || ! Paths::is_within( $abs, $this->p->root ) ) {
			return 'hidden';
		}
		$rel_root = Paths::relative( $abs, $this->p->root );
		$site     = Paths::abspath();
		$rel_site = Paths::is_within( $abs, $site ) ? Paths::relative( $abs, $site ) : null;

		if ( '' === $rel_root ) {
			return 'ok';
		}
		if ( Policy::matches( $this->p->hidden, $rel_root, $rel_site ) ) {
			return 'hidden';
		}
		if ( $this->p->sensitive && 'full' !== $this->p->sensitive_mode && Policy::matches( $this->p->sensitive, $rel_root, $rel_site ) ) {
			return 'hidden' === $this->p->sensitive_mode ? 'hidden' : 'locked';
		}
		if ( Policy::matches( $this->p->locked, $rel_root, $rel_site ) ) {
			return 'locked';
		}
		return 'ok';
	}

	/**
	 * Is the item sensitive (even if visible)?
	 *
	 * @param string $abs Absolute path.
	 * @return bool
	 */
	public function is_sensitive( $abs ) {
		$patterns = $this->p->sensitive ? $this->p->sensitive : Settings::default_sensitive_patterns();
		$site     = Paths::abspath();
		return Policy::matches( $patterns, Paths::relative( $abs, $this->p->root ), Paths::is_within( $abs, $site ) ? Paths::relative( $abs, $site ) : null );
	}

	/**
	 * Throw unless the item can be modified.
	 *
	 * @param string $abs Absolute path.
	 * @throws Error When locked or not writable.
	 */
	public function assert_writable( $abs ) {
		if ( 'ok' !== $this->visibility( $abs ) ) {
			throw new Error( 'locked', __( 'This item is locked.', 'filora' ), 403 );
		}
		if ( ! is_writable( $abs ) ) {
			throw new Error( 'not_writable', __( 'The server does not allow writing here (file permissions).', 'filora' ), 403 );
		}
	}

	/**
	 * Throw unless the item can be deleted / renamed / moved away.
	 *
	 * @param string $abs Absolute path.
	 * @throws Error When protected.
	 */
	public function assert_removable( $abs ) {
		if ( $abs === $this->p->root ) {
			throw new Error( 'protected', __( 'The root folder cannot be removed.', 'filora' ), 403 );
		}
		if ( Paths::is_system( $abs ) ) {
			throw new Error( 'protected', __( 'This is a WordPress system item and is protected.', 'filora' ), 403 );
		}
		if ( 'ok' !== $this->visibility( $abs ) ) {
			throw new Error( 'locked', __( 'This item is locked.', 'filora' ), 403 );
		}
		if ( ! is_writable( dirname( $abs ) ) ) {
			throw new Error( 'not_writable', __( 'The server does not allow changes in this folder (file permissions).', 'filora' ), 403 );
		}
		if ( ! $this->p->is_admin && is_dir( $abs ) && $this->contains_hidden( $abs ) ) {
			throw new Error( 'contains_protected', __( 'This folder contains protected items and cannot be changed.', 'filora' ), 403 );
		}
	}

	/**
	 * Does a directory contain items hidden from this profile? (budgeted scan)
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	private function contains_hidden( $dir ) {
		$count = 0;
		$stack = array( $dir );
		while ( $stack ) {
			$current = array_pop( $stack );
			$items   = @scandir( $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $items ) {
				return true;
			}
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				if ( ++$count > 50000 ) {
					return true;
				}
				$path = $current . '/' . $item;
				if ( 'hidden' === $this->visibility( $path ) ) {
					return true;
				}
				if ( is_dir( $path ) && ! is_link( $path ) ) {
					$stack[] = $path;
				}
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Read operations
	 * ------------------------------------------------------------------- */

	/**
	 * Describe one item.
	 *
	 * @param string $abs Absolute path.
	 * @return array
	 */
	public function entry( $abs ) {
		$name   = basename( $abs );
		$is_dir = is_dir( $abs );
		$stat   = @stat( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$vis    = $this->visibility( $abs );
		$entry  = array(
			'name'     => '' === $name ? '/' : $name,
			'path'     => $this->rel( $abs ),
			'type'     => $is_dir ? 'dir' : 'file',
			'size'     => $is_dir ? null : ( $stat ? (int) $stat['size'] : 0 ),
			'mtime'    => $stat ? (int) $stat['mtime'] : 0,
			'perms'    => $stat ? substr( sprintf( '%o', $stat['mode'] ), -4 ) : '',
			'ext'      => $is_dir ? '' : Paths::ext( $name ),
			'cat'      => $is_dir ? 'dir' : Mime::category( $name ),
			'link'     => is_link( $abs ),
			'locked'   => 'locked' === $vis,
			'writable' => 'ok' === $vis && ! $this->p->read_only && is_writable( $abs ),
			'system'   => Paths::is_system( $abs ),
		);
		$url = self::public_url( $abs );
		if ( $url && ! Storage::is_vault_path( $abs ) ) {
			$entry['url'] = $url; // Vault files have no usable direct URL (blocked on purpose).
		}
		if ( ! $is_dir && Mime::is_thumbable( $name ) && $entry['size'] > 0 && $entry['size'] < 30 * MB_IN_BYTES ) {
			$entry['thumb'] = true;
		}
		if ( $this->is_shared( $abs ) ) {
			$entry['shared'] = true;
		}
		if ( $this->p->is_admin && 'hidden' !== $vis && $this->p->sensitive && Policy::matches( $this->p->sensitive, Paths::relative( $abs, $this->p->root ), null ) ) {
			$entry['sensitive'] = true;
		}
		return $entry;
	}

	/**
	 * List a directory.
	 *
	 * @param string $rel Relative path.
	 * @return array
	 */
	public function listing( $rel ) {
		$dir   = $this->dir( $rel );
		$names = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $names ) {
			throw new Error( 'not_readable', __( 'The server does not allow reading this folder.', 'filora' ), 403 );
		}
		$this->load_shared( $dir );
		$entries   = array();
		$truncated = false;
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$abs = $dir . '/' . $name;
			if ( is_link( $abs ) ) {
				$target = Paths::real( $abs );
				if ( false === $target || ! Paths::is_within( $target, $this->p->root ) ) {
					continue; // Never expose symlinks that escape the root.
				}
			}
			if ( 'hidden' === $this->visibility( $abs ) ) {
				continue;
			}
			if ( count( $entries ) >= self::LIST_MAX ) {
				$truncated = true;
				break;
			}
			$entries[] = $this->entry( $abs );
		}
		$current = $this->entry( $dir );
		return array(
			'path'      => $this->rel( $dir ),
			'dir'       => $current,
			'entries'   => $entries,
			'truncated' => $truncated,
		);
	}

	/**
	 * Child folders (for the sidebar tree).
	 *
	 * @param string $rel Relative path.
	 * @return array
	 */
	public function tree( $rel ) {
		$dir   = $this->dir( $rel );
		$names = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$out   = array();
		if ( false === $names ) {
			return $out;
		}
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$abs = $dir . '/' . $name;
			if ( ! is_dir( $abs ) || 'hidden' === $this->visibility( $abs ) ) {
				continue;
			}
			if ( is_link( $abs ) ) {
				$target = Paths::real( $abs );
				if ( false === $target || ! Paths::is_within( $target, $this->p->root ) ) {
					continue;
				}
			}
			$out[] = array(
				'name'        => $name,
				'path'        => $this->rel( $abs ),
				'hasChildren' => $this->has_subdirs( $abs ),
			);
			if ( count( $out ) >= 2000 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Quick check for at least one visible sub-folder.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	private function has_subdirs( $dir ) {
		$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return false;
		}
		$seen  = 0;
		$found = false;
		while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			if ( ++$seen > 400 ) {
				$found = true; // Assume yes for huge folders.
				break;
			}
			if ( is_dir( $dir . '/' . $name ) && 'hidden' !== $this->visibility( $dir . '/' . $name ) ) {
				$found = true;
				break;
			}
		}
		closedir( $handle );
		return $found;
	}

	/**
	 * Detailed information.
	 *
	 * @param string $rel       Relative path.
	 * @param bool   $with_size Compute folder size.
	 * @return array
	 */
	public function info( $rel, $with_size = false ) {
		$abs   = $this->abs( $rel );
		$entry = $this->entry( $abs );
		if ( $this->p->is_admin ) {
			$entry['absolute'] = $abs;
			$owner             = @fileowner( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $owner && function_exists( 'posix_getpwuid' ) ) {
				$info           = @posix_getpwuid( $owner ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$entry['owner'] = $info && isset( $info['name'] ) ? $info['name'] : (string) $owner;
			}
		}
		if ( 'file' === $entry['type'] ) {
			$entry['mime'] = Mime::type( $abs );
			if ( 'image' === $entry['cat'] && $entry['size'] < 50 * MB_IN_BYTES ) {
				$dim = @getimagesize( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $dim ) {
					$entry['width']  = (int) $dim[0];
					$entry['height'] = (int) $dim[1];
				}
			}
			$entry['editable'] = $this->is_editable( $abs );
		} elseif ( $with_size ) {
			$entry['stats'] = $this->dir_stats( $abs );
		}
		$entry['ctime']     = (int) @filectime( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$entry['sensitive'] = $this->is_sensitive( $abs );
		$entry['vault']     = Storage::is_vault_path( $abs );
		return $entry;
	}

	/**
	 * Can this file be opened in the text editor?
	 *
	 * @param string $abs Absolute path.
	 * @return bool
	 */
	public function is_editable( $abs ) {
		if ( is_dir( $abs ) || filesize( $abs ) > self::EDIT_MAX_BYTES ) {
			return false;
		}
		if ( Mime::is_text( $abs ) ) {
			return true;
		}
		$cat = Mime::category( $abs );
		if ( in_array( $cat, array( 'image', 'video', 'audio', 'pdf', 'archive', 'font', 'doc', 'slides' ), true ) ) {
			return false;
		}
		return ! Mime::looks_binary( $abs );
	}

	/**
	 * Recursive size with a time / item budget.
	 *
	 * @param string $dir Directory.
	 * @return array{size:int,files:int,dirs:int,partial:bool}
	 */
	public function dir_stats( $dir ) {
		$start = microtime( true );
		$stats = array(
			'size'    => 0,
			'files'   => 0,
			'dirs'    => 0,
			'partial' => false,
		);
		$stack = array( $dir );
		while ( $stack ) {
			$current = array_pop( $stack );
			$handle  = @opendir( $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $handle ) {
				continue;
			}
			while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$path = $current . '/' . $name;
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					++$stats['dirs'];
					$stack[] = $path;
				} else {
					++$stats['files'];
					$stats['size'] += (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				if ( microtime( true ) - $start > 8 ) {
					$stats['partial'] = true;
					closedir( $handle );
					return $stats;
				}
			}
			closedir( $handle );
		}
		return $stats;
	}

	/**
	 * Read a text file for the editor.
	 *
	 * @param string $rel Relative path.
	 * @return array
	 */
	public function read( $rel ) {
		$abs = $this->abs( $rel );
		if ( is_dir( $abs ) ) {
			throw new Error( 'not_file', __( 'This is a folder.', 'filora' ) );
		}
		if ( filesize( $abs ) > self::EDIT_MAX_BYTES ) {
			throw new Error( 'too_large', __( 'This file is too large to be edited in the browser (5 MB max).', 'filora' ), 413 );
		}
		if ( ! is_readable( $abs ) ) {
			throw new Error( 'not_readable', __( 'The server does not allow reading this file.', 'filora' ), 403 );
		}
		$content  = (string) file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$hash     = md5( $content );
		$encoding = 'utf-8';
		if ( false !== strpos( substr( $content, 0, 8000 ), "\0" ) ) {
			throw new Error( 'binary', __( 'This looks like a binary file and cannot be edited as text.', 'filora' ), 415 );
		}
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$encoding = 'windows-1252';
			$content  = mb_convert_encoding( $content, 'UTF-8', 'Windows-1252' );
		}
		$entry = $this->entry( $abs );
		return array(
			'entry'    => $entry,
			'content'  => $content,
			'hash'     => $hash,
			'encoding' => $encoding,
		);
	}

	/**
	 * Search names (and optionally contents) below a folder.
	 *
	 * @param string $rel  Folder.
	 * @param array  $args q, content, ext, days, limit.
	 * @return array
	 */
	public function search( $rel, array $args ) {
		$dir     = $this->dir( $rel );
		$query   = trim( (string) ( isset( $args['q'] ) ? $args['q'] : '' ) );
		$content = ! empty( $args['content'] );
		$exts    = array_filter( explode( ',', strtolower( (string) ( isset( $args['ext'] ) ? $args['ext'] : '' ) ) ) );
		$since   = ! empty( $args['days'] ) ? time() - (int) $args['days'] * DAY_IN_SECONDS : 0;
		$limit   = min( 1000, max( 1, (int) ( isset( $args['limit'] ) ? $args['limit'] : 300 ) ) );
		$is_glob = false !== strpbrk( $query, '*?[' );
		$needle  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );

		if ( '' === $query && ! $since && ! $exts ) {
			throw new Error( 'empty_query', __( 'Type something to search.', 'filora' ) );
		}

		$start   = microtime( true );
		$visited = 0;
		$results = array();
		$partial = false;
		$stack   = array( $dir );

		while ( $stack ) {
			$current = array_shift( $stack );
			$names   = @scandir( $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $names ) {
				continue;
			}
			foreach ( $names as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$abs = $current . '/' . $name;
				if ( is_link( $abs ) || 'hidden' === $this->visibility( $abs ) ) {
					continue;
				}
				++$visited;
				$is_dir = is_dir( $abs );
				if ( $is_dir ) {
					$stack[] = $abs;
				}
				if ( $visited > 300000 || microtime( true ) - $start > 10 ) {
					$partial = true;
					break 2;
				}
				if ( $exts && ( $is_dir || ! in_array( Paths::ext( $name ), $exts, true ) ) ) {
					continue;
				}
				if ( $since && @filemtime( $abs ) < $since ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					continue;
				}
				$matches = array();
				if ( '' !== $query ) {
					if ( $content ) {
						if ( $is_dir || ! Mime::is_text( $name ) || @filesize( $abs ) > 2 * MB_IN_BYTES ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							continue;
						}
						$matches = $this->grep( $abs, $query );
						if ( ! $matches ) {
							continue;
						}
					} else {
						$hay = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
						if ( $is_glob ? ! fnmatch( $needle, $hay ) : false === strpos( $hay, $needle ) ) {
							continue;
						}
					}
				}
				$entry = $this->entry( $abs );
				if ( $matches ) {
					$entry['matches'] = $matches;
				}
				$results[] = $entry;
				if ( count( $results ) >= $limit ) {
					$partial = true;
					break 2;
				}
			}
		}
		return array(
			'results' => $results,
			'partial' => $partial,
			'visited' => $visited,
		);
	}

	/**
	 * Find lines containing a needle (case-insensitive), max 5.
	 *
	 * @param string $abs    File.
	 * @param string $needle Needle.
	 * @return array
	 */
	private function grep( $abs, $needle ) {
		$fh = @fopen( $abs, 'rb' ); // phpcs:ignore
		if ( ! $fh ) {
			return array();
		}
		$out  = array();
		$line = 0;
		while ( false !== ( $text = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$line;
			if ( false !== stripos( $text, $needle ) ) {
				$text  = trim( $text );
				$out[] = array(
					'line' => $line,
					'text' => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 ),
				);
				if ( count( $out ) >= 5 ) {
					break;
				}
			}
		}
		fclose( $fh ); // phpcs:ignore
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Write operations
	 * ------------------------------------------------------------------- */

	/**
	 * Save text content (editor).
	 *
	 * @param string      $rel      Relative path.
	 * @param string      $content  Content (UTF-8).
	 * @param string|null $expected Hash returned by read() (optimistic locking).
	 * @param string      $encoding Original encoding.
	 * @return array
	 */
	public function write( $rel, $content, $expected = null, $encoding = 'utf-8' ) {
		$this->p->require_op( 'edit' );
		$abs = $this->abs( $rel );
		if ( is_dir( $abs ) ) {
			throw new Error( 'not_file', __( 'This is a folder.', 'filora' ) );
		}
		$this->assert_writable( $abs );
		Policy::assert_writable_name( $this->p, basename( $abs ) );
		if ( ! $this->p->is_admin && ( Policy::is_server_dangerous( basename( $abs ) ) || Policy::is_php( basename( $abs ) ) ) ) {
			throw Error::forbidden( __( 'For security reasons, this file type cannot be edited.', 'filora' ) );
		}

		$content = (string) $content;
		if ( 'windows-1252' === $encoding && function_exists( 'mb_convert_encoding' ) ) {
			$content = mb_convert_encoding( $content, 'Windows-1252', 'UTF-8' );
		}

		$current = (string) file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( null !== $expected && '' !== $expected && ! hash_equals( md5( $current ), (string) $expected ) ) {
			throw new Error( 'conflict', __( 'This file was changed by someone else since you opened it.', 'filora' ), 409, array( 'hash' => md5( $current ) ) );
		}
		if ( $current === $content ) {
			return array(
				'entry' => $this->entry( $abs ),
				'hash'  => md5( $current ),
			);
		}

		if ( Settings::value( 'general', 'php_lint', true ) && Policy::is_php( basename( $abs ) ) ) {
			$error = self::lint_php( $content );
			if ( $error ) {
				throw new Error( 'syntax_error', $error['message'], 422, array( 'line' => $error['line'] ) );
			}
		}

		Versions::snapshot( $abs, $this->p->user_id );
		self::atomic_put( $abs, $content );
		clearstatcache( true, $abs );

		return array(
			'entry' => $this->entry( $abs ),
			'hash'  => md5( $content ),
		);
	}

	/**
	 * Check PHP syntax without exec() (token_get_all with TOKEN_PARSE throws on errors).
	 *
	 * @param string $code PHP code.
	 * @return array|null Error with message and line.
	 */
	public static function lint_php( $code ) {
		if ( ! defined( 'TOKEN_PARSE' ) ) {
			return null;
		}
		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return array(
				/* translators: 1: PHP error message, 2: line number */
				'message' => sprintf( __( 'PHP syntax error: %1$s on line %2$d. The file was not saved, so your site is safe.', 'filora' ), $e->getMessage(), $e->getLine() ),
				'line'    => $e->getLine(),
			);
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
	}

	/**
	 * Write a file atomically (temp file + rename), preserving permissions.
	 *
	 * @param string $abs     Target.
	 * @param string $content Content.
	 * @throws Error On failure.
	 */
	public static function atomic_put( $abs, $content ) {
		$dir   = dirname( $abs );
		$perms = file_exists( $abs ) ? ( fileperms( $abs ) & 0777 ) : 0644;
		if ( is_writable( $dir ) ) {
			$tmp = $dir . '/.' . basename( $abs ) . '.filora-' . wp_generate_password( 8, false, false ) . '.tmp';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false !== @file_put_contents( $tmp, $content, LOCK_EX ) ) {
				@chmod( $tmp, $perms ); // phpcs:ignore
				if ( @rename( $tmp, $abs ) ) { // phpcs:ignore
					return;
				}
				@unlink( $tmp ); // phpcs:ignore
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === @file_put_contents( $abs, $content, LOCK_EX ) ) {
			throw new Error( 'write_failed', __( 'The file could not be written (check file permissions).', 'filora' ), 500 );
		}
	}

	/**
	 * Create a folder.
	 *
	 * @param string $parent Parent folder.
	 * @param string $name   Name.
	 * @return array
	 */
	public function mkdir( $parent, $name ) {
		$this->p->require_op( 'mkdir' );
		$dir  = $this->dir( $parent );
		$name = self::clean_name( $name );
		Policy::assert_writable_name( $this->p, $name, true );
		$this->assert_writable( $dir );
		$target = $dir . '/' . $name;
		if ( file_exists( $target ) ) {
			throw new Error( 'exists', __( 'An item with this name already exists.', 'filora' ), 409 );
		}
		if ( 'hidden' === $this->visibility( $target ) ) {
			throw Error::forbidden( __( 'This name is reserved.', 'filora' ) );
		}
		if ( ! @mkdir( $target, 0755 ) ) { // phpcs:ignore
			throw new Error( 'mkdir_failed', __( 'The folder could not be created.', 'filora' ), 500 );
		}
		return $this->entry( $target );
	}

	/**
	 * Create a file.
	 *
	 * @param string $parent  Parent folder.
	 * @param string $name    Name.
	 * @param string $content Initial content.
	 * @return array
	 */
	public function mkfile( $parent, $name, $content = '' ) {
		$this->p->require_op( 'mkfile' );
		$dir  = $this->dir( $parent );
		$name = self::clean_name( $name );
		Policy::assert_writable_name( $this->p, $name );
		$this->assert_writable( $dir );
		$target = $dir . '/' . $name;
		if ( file_exists( $target ) ) {
			throw new Error( 'exists', __( 'An item with this name already exists.', 'filora' ), 409 );
		}
		if ( 'ok' !== $this->visibility( $target ) ) {
			throw Error::forbidden( __( 'This name is reserved.', 'filora' ) );
		}
		if ( '' === $content && Policy::is_php( $name ) ) {
			$content = "<?php\n\n";
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === @file_put_contents( $target, $content ) ) {
			throw new Error( 'write_failed', __( 'The file could not be created.', 'filora' ), 500 );
		}
		@chmod( $target, 0644 ); // phpcs:ignore
		return $this->entry( $target );
	}

	/**
	 * Rename in place.
	 *
	 * @param string $rel  Item.
	 * @param string $name New name.
	 * @return array
	 */
	public function rename( $rel, $name ) {
		$this->p->require_op( 'rename' );
		$abs  = $this->abs( $rel );
		$name = self::clean_name( $name );
		if ( basename( $abs ) === $name ) {
			return $this->entry( $abs );
		}
		$this->assert_removable( $abs );
		Policy::assert_writable_name( $this->p, $name, is_dir( $abs ) );
		$target = dirname( $abs ) . '/' . $name;
		// A case-only rename may target the same file (case-insensitive file systems),
		// but never another existing file.
		$same = strtolower( basename( $abs ) ) === strtolower( $name ) && Paths::real( $target ) === Paths::real( $abs );
		if ( file_exists( $target ) && ! $same ) {
			throw new Error( 'exists', __( 'An item with this name already exists.', 'filora' ), 409 );
		}
		if ( 'ok' !== $this->visibility( $target ) ) {
			throw Error::forbidden( __( 'This name is reserved.', 'filora' ) );
		}
		if ( ! @rename( $abs, $target ) ) { // phpcs:ignore
			throw new Error( 'rename_failed', __( 'The item could not be renamed.', 'filora' ), 500 );
		}
		Shares::path_moved( $abs, $target );
		Versions::path_moved( $abs, $target );
		return $this->entry( $target );
	}

	/**
	 * Copy or move items into a folder.
	 *
	 * @param string[] $rels     Items.
	 * @param string   $dest     Destination folder.
	 * @param string   $mode     copy|move.
	 * @param string   $conflict rename|overwrite|skip.
	 * @return array{done:array,skipped:array,errors:array}
	 */
	public function transfer( array $rels, $dest, $mode = 'copy', $conflict = 'rename' ) {
		$this->p->require_op( 'move' === $mode ? 'move' : 'copy' );
		$dest_abs = $this->dir( $dest );
		$this->assert_writable( $dest_abs );
		$result = array(
			'done'    => array(),
			'skipped' => array(),
			'errors'  => array(),
		);
		foreach ( $rels as $rel ) {
			try {
				$src  = $this->abs( $rel );
				$name = basename( $src );
				if ( 'move' === $mode ) {
					$this->assert_removable( $src );
				} elseif ( ! is_readable( $src ) ) {
					throw new Error( 'not_readable', __( 'The server does not allow reading this item.', 'filora' ), 403 );
				}
				if ( is_dir( $src ) && Paths::is_within( $dest_abs, $src ) ) {
					throw new Error( 'into_itself', __( 'A folder cannot be copied or moved into itself.', 'filora' ) );
				}
				Policy::assert_writable_name( $this->p, $name, is_dir( $src ) );
				$same_dir = dirname( $src ) === $dest_abs;
				if ( 'move' === $mode && $same_dir ) {
					$result['skipped'][] = $this->rel( $src );
					continue;
				}
				$target = $dest_abs . '/' . $name;
				if ( $same_dir ) {
					$target = $dest_abs . '/' . Paths::unique_name( $dest_abs, $name, __( 'copy', 'filora' ) );
				} elseif ( file_exists( $target ) ) {
					if ( 'skip' === $conflict ) {
						$result['skipped'][] = $this->rel( $src );
						continue;
					}
					if ( 'overwrite' === $conflict ) {
						// Overwriting destroys the existing item: it needs the delete permission.
						$this->p->require_op( 'delete' );
						$this->assert_removable( $target );
						$this->remove( $target, (bool) Settings::value( 'general', 'trash', true ) );
					} else {
						$target = $dest_abs . '/' . Paths::unique_name( $dest_abs, $name );
					}
				}
				if ( 'ok' !== $this->visibility( $target ) ) {
					throw Error::forbidden( __( 'This name is reserved.', 'filora' ) );
				}
				$this->assert_quota( 'copy' === $mode ? $src : null );
				if ( 'move' === $mode ) {
					self::move_path( $src, $target );
					Shares::path_moved( $src, $target );
					Versions::path_moved( $src, $target );
				} else {
					$this->copy_path( $src, $target );
				}
				$result['done'][] = array(
					'from' => '/' . Paths::relative( $src, $this->p->root ),
					'to'   => $this->rel( $target ),
				);
			} catch ( Error $e ) {
				$result['errors'][] = array(
					'path'    => (string) $rel,
					'message' => $e->getMessage(),
				);
			}
		}
		return $result;
	}

	/**
	 * Duplicate items next to themselves.
	 *
	 * @param string[] $rels Items.
	 * @return array
	 */
	public function duplicate( array $rels ) {
		$out = array(
			'done'    => array(),
			'skipped' => array(),
			'errors'  => array(),
		);
		foreach ( $rels as $rel ) {
			try {
				$abs = $this->abs( $rel );
				$res = $this->transfer( array( $rel ), $this->rel( dirname( $abs ) ), 'copy' );
				$out = array_merge_recursive( $out, $res );
			} catch ( Error $e ) {
				$out['errors'][] = array(
					'path'    => (string) $rel,
					'message' => $e->getMessage(),
				);
			}
		}
		return $out;
	}

	/**
	 * Delete items (to trash when enabled).
	 *
	 * @param string[] $rels      Items.
	 * @param bool     $permanent Skip trash.
	 * @return array
	 */
	public function delete( array $rels, $permanent = false ) {
		$this->p->require_op( 'delete' );
		$use_trash = Settings::value( 'general', 'trash', true ) && ! $permanent;
		$result    = array(
			'done'   => array(),
			'trash'  => array(),
			'errors' => array(),
		);
		foreach ( $rels as $rel ) {
			try {
				$abs = $this->abs( $rel );
				$this->assert_removable( $abs );
				$id               = $this->remove( $abs, $use_trash );
				$result['done'][] = $this->rel( $abs );
				if ( $id ) {
					$result['trash'][] = $id;
				}
			} catch ( Error $e ) {
				$result['errors'][] = array(
					'path'    => (string) $rel,
					'message' => $e->getMessage(),
				);
			}
		}
		return $result;
	}

	/**
	 * Remove an item (to trash or permanently).
	 *
	 * @param string $abs       Absolute path.
	 * @param bool   $use_trash Use trash.
	 * @return string|null Trash ID.
	 * @throws Error On failure.
	 */
	private function remove( $abs, $use_trash ) {
		if ( $use_trash ) {
			return Trash::put( $abs, $this->p->user_id );
		}
		if ( ! self::delete_path( $abs ) ) {
			throw new Error( 'delete_failed', __( 'The item could not be deleted completely (check file permissions).', 'filora' ), 500 );
		}
		Versions::forget( $abs );
		return null;
	}

	/**
	 * Change permissions.
	 *
	 * @param string[] $rels      Items.
	 * @param string   $mode      Octal string for the selected items.
	 * @param bool     $recursive Apply to folder contents.
	 * @param string   $file_mode Mode for nested files.
	 * @param string   $dir_mode  Mode for nested folders.
	 * @return array
	 */
	public function chmod( array $rels, $mode, $recursive = false, $file_mode = '0644', $dir_mode = '0755' ) {
		$this->p->require_op( 'chmod' );
		$mode      = self::parse_mode( $mode );
		$file_mode = self::parse_mode( $file_mode );
		$dir_mode  = self::parse_mode( $dir_mode );
		$result    = array(
			'done'   => array(),
			'errors' => array(),
		);
		foreach ( $rels as $rel ) {
			try {
				$abs = $this->abs( $rel );
				if ( 'ok' !== $this->visibility( $abs ) ) {
					throw new Error( 'locked', __( 'This item is locked.', 'filora' ), 403 );
				}
				if ( ! @chmod( $abs, $mode ) ) { // phpcs:ignore
					throw new Error( 'chmod_failed', __( 'Permissions could not be changed (the file probably belongs to another system user).', 'filora' ), 500 );
				}
				if ( $recursive && is_dir( $abs ) ) {
					$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $abs, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
					foreach ( $iterator as $item ) {
						$path = str_replace( '\\', '/', $item->getPathname() );
						if ( $item->isLink() || 'ok' !== $this->visibility( $path ) ) {
							continue;
						}
						@chmod( $path, $item->isDir() ? $dir_mode : $file_mode ); // phpcs:ignore
					}
				}
				clearstatcache();
				$result['done'][] = $this->entry( $abs );
			} catch ( Error $e ) {
				$result['errors'][] = array(
					'path'    => (string) $rel,
					'message' => $e->getMessage(),
				);
			}
		}
		return $result;
	}

	/**
	 * Parse an octal permission string.
	 *
	 * @param string $mode Mode.
	 * @return int
	 * @throws Error When invalid.
	 */
	private static function parse_mode( $mode ) {
		$mode = trim( (string) $mode );
		if ( ! preg_match( '/^0?[0-7]{3}$/', $mode ) ) {
			throw new Error( 'invalid_mode', __( 'Invalid permissions. Use an octal value such as 644 or 755.', 'filora' ) );
		}
		return octdec( $mode );
	}

	/**
	 * Enforce the role quota.
	 *
	 * @param string|null $adding Path being copied in (size added), or null.
	 * @param int         $bytes  Bytes being added.
	 * @throws Error When over quota.
	 */
	public function assert_quota( $adding = null, $bytes = 0 ) {
		if ( ! $this->p->quota ) {
			return;
		}
		if ( $adding ) {
			$bytes += is_dir( $adding ) ? $this->dir_stats( $adding )['size'] : (int) filesize( $adding );
		}
		$used = $this->used_space();
		if ( $used + $bytes > $this->p->quota ) {
			throw new Error( 'quota', __( 'Your storage quota is full.', 'filora' ), 507 );
		}
	}

	/**
	 * Used space in the root (cached 5 minutes).
	 *
	 * @param bool $fresh Ignore cache.
	 * @return int
	 */
	public function used_space( $fresh = false ) {
		$key  = 'filora_used_' . md5( $this->p->root );
		$used = $fresh ? false : get_transient( $key );
		if ( false === $used ) {
			$used = $this->dir_stats( $this->p->root )['size'];
			set_transient( $key, $used, 5 * MINUTE_IN_SECONDS );
		}
		return (int) $used;
	}

	/**
	 * Invalidate cached usage after writes.
	 */
	public function touch_usage() {
		if ( $this->p->quota ) {
			delete_transient( 'filora_used_' . md5( $this->p->root ) );
		}
	}

	/**
	 * Recursive copy that skips hidden / locked-sensitive items and dangerous names.
	 *
	 * @param string $src    Source.
	 * @param string $target Target.
	 * @throws Error On failure.
	 */
	private function copy_path( $src, $target ) {
		if ( is_dir( $src ) ) {
			if ( ! is_dir( $target ) && ! @mkdir( $target, 0755 ) ) { // phpcs:ignore
				throw new Error( 'copy_failed', __( 'The item could not be copied.', 'filora' ), 500 );
			}
			$names = @scandir( $src ); // phpcs:ignore
			foreach ( (array) $names as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$child = $src . '/' . $name;
				if ( is_link( $child ) || 'hidden' === $this->visibility( $child ) || ( ! $this->p->is_admin && $this->is_sensitive( $child ) ) ) {
					continue;
				}
				try {
					Policy::assert_writable_name( $this->p, $name, is_dir( $child ) );
				} catch ( Error $e ) {
					continue;
				}
				$this->copy_path( $child, $target . '/' . $name );
			}
			return;
		}
		if ( ! @copy( $src, $target ) ) { // phpcs:ignore
			throw new Error( 'copy_failed', __( 'The item could not be copied.', 'filora' ), 500 );
		}
	}

	/**
	 * Move a path, falling back to copy + delete across filesystems.
	 *
	 * @param string $src    Source.
	 * @param string $target Target.
	 * @throws Error On failure.
	 */
	public static function move_path( $src, $target ) {
		if ( @rename( $src, $target ) ) { // phpcs:ignore
			return;
		}
		if ( ! self::raw_copy( $src, $target ) ) {
			throw new Error( 'move_failed', __( 'The item could not be moved.', 'filora' ), 500 );
		}
		self::delete_path( $src );
	}

	/**
	 * Plain recursive copy (internal storage moves).
	 *
	 * @param string $src    Source.
	 * @param string $target Target.
	 * @return bool
	 */
	public static function raw_copy( $src, $target ) {
		if ( is_link( $src ) ) {
			return true;
		}
		if ( is_dir( $src ) ) {
			if ( ! is_dir( $target ) && ! @mkdir( $target, 0755, true ) ) { // phpcs:ignore
				return false;
			}
			foreach ( (array) @scandir( $src ) as $name ) { // phpcs:ignore
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				if ( ! self::raw_copy( $src . '/' . $name, $target . '/' . $name ) ) {
					return false;
				}
			}
			return true;
		}
		return @copy( $src, $target ); // phpcs:ignore
	}

	/**
	 * Recursive delete (never follows symlinks).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function delete_path( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			return @unlink( $path ); // phpcs:ignore
		}
		if ( ! is_dir( $path ) ) {
			return true;
		}
		$ok = true;
		foreach ( (array) @scandir( $path ) as $name ) { // phpcs:ignore
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$ok = self::delete_path( $path . '/' . $name ) && $ok;
		}
		return @rmdir( $path ) && $ok; // phpcs:ignore
	}

	/**
	 * Sanitize a user-typed name (trim, collapse whitespace) and validate it.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function clean_name( $name ) {
		$name = trim( preg_replace( '/\s+/u', ' ', (string) $name ) );
		Policy::assert_valid_name( $name );
		return $name;
	}

	/* ---------------------------------------------------------------------
	 * URLs & shares
	 * ------------------------------------------------------------------- */

	/**
	 * Public URL of a path, or null if not web-reachable (or protected).
	 *
	 * @param string $abs Absolute path.
	 * @return string|null
	 */
	public static function public_url( $abs ) {
		$abs = str_replace( '\\', '/', $abs );
		if ( Paths::is_within( $abs, Storage::base() ) ) {
			return null;
		}
		$uploads = wp_upload_dir( null, false );
		$map     = array(
			array( $uploads['basedir'], $uploads['baseurl'] ),
			array( WP_CONTENT_DIR, content_url() ),
			array( Paths::abspath(), site_url() ),
		);
		foreach ( $map as $pair ) {
			$base = Paths::real( $pair[0] );
			if ( $base && Paths::is_within( $abs, $base ) ) {
				$rel = Paths::relative( $abs, $base );
				$url = rtrim( $pair[1], '/' );
				if ( '' === $rel ) {
					return $url . '/';
				}
				return $url . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) );
			}
		}
		return null;
	}

	/**
	 * Load active share paths for badges in one query.
	 *
	 * @param string $dir Directory being listed.
	 */
	private function load_shared( $dir ) {
		$this->shared = array();
		foreach ( Shares::paths_in( $dir ) as $path ) {
			$this->shared[ $path ] = true;
		}
	}

	/**
	 * Is the path shared?
	 *
	 * @param string $abs Absolute path.
	 * @return bool
	 */
	private function is_shared( $abs ) {
		return null !== $this->shared && isset( $this->shared[ $abs ] );
	}
}
