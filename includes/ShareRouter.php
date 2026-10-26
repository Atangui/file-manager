<?php
/**
 * Public share links: /{slug}/{token}/
 *
 * Request flow: no-cache/noindex headers → rate limit → token lookup → status checks →
 * IP allow-list → gates (password and/or email one-time code) → action.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Handles every public request for a share token.
 */
final class ShareRouter {

	const QUERY_VAR      = 'filora_share';
	const VISITOR_COOKIE = 'filora_v';

	/**
	 * Share row.
	 *
	 * @var array
	 */
	private $share;

	/**
	 * Token from the URL.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Root of the share (file or folder), real path.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Decoded options.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Visitor session.
	 *
	 * @var array
	 */
	private $session = array();

	/**
	 * Owner filesystem view (to apply the owner's hidden rules).
	 *
	 * @var Fs|null
	 */
	private $owner_fs = null;

	/**
	 * CSP nonce for inline script.
	 *
	 * @var string
	 */
	private $nonce;

	/**
	 * Register the pretty URL.
	 */
	public static function register_rewrite() {
		$slug = preg_quote( (string) Settings::value( 'sharing', 'slug', 'share' ), '#' );
		add_rewrite_rule( '^' . $slug . '/([A-Za-z0-9_-]{20,128})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Allow our query var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Entry point on parse_request.
	 *
	 * @param \WP $wp WP object.
	 */
	public static function maybe_handle( $wp ) {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		$router = new self( (string) $wp->query_vars[ self::QUERY_VAR ] );
		$router->dispatch();
		exit;
	}

	/**
	 * Constructor.
	 *
	 * @param string $token Token.
	 */
	private function __construct( $token ) {
		$this->token = $token;
		$this->nonce = Crypto::token( 12 );
	}

	/* ---------------------------------------------------------------------
	 * Dispatch
	 * ------------------------------------------------------------------- */

	/**
	 * Main flow.
	 */
	private function dispatch() {
		Http::private_headers();
		I18n::use_visitor_locale();
		$ip = Http::ip();

		if ( ! Settings::value( 'sharing', 'enabled', true ) ) {
			$this->message( 'unavailable', 404 );
		}
		if ( RateLimit::exceeded( 'share-bad-token', $ip, 40 ) ) {
			$this->message( 'rate_limited', 429 );
		}
		$row = Shares::find_by_token( $this->token );
		if ( ! $row ) {
			RateLimit::hit( 'share-bad-token', $ip, 10 * MINUTE_IN_SECONDS );
			$this->message( 'not_found', 404 );
		}
		$this->share   = $row;
		$this->options = Shares::options( $row );

		$status = Shares::status( $row );
		if ( 'active' !== $status ) {
			$codes = array(
				'expired'   => 410,
				'disabled'  => 410,
				'exhausted' => 410,
				'missing'   => 404,
				'orphaned'  => 404,
			);
			$this->message( $status, isset( $codes[ $status ] ) ? $codes[ $status ] : 404 );
		}

		if ( $row['ips'] && ! Http::ip_matches( $ip, explode( "\n", $row['ips'] ) ) ) {
			Log::add( 'share.ip_denied', Paths::from_storage( $row['path'] ), array( 'ip' => Http::ip_for_log() ), (int) $row['id'], 0 );
			$this->message( 'ip_denied', 403 );
		}

		$this->root = Paths::real( Paths::from_storage( $row['path'] ) );
		$owner      = Shares::owner_profile( $row );
		if ( ! $this->root || ! $owner ) {
			$this->message( 'missing', 404 );
		}
		$this->owner_fs = new Fs( $owner );
		$this->session  = $this->read_session();

		$action = isset( $_GET['a'] ) ? sanitize_key( wp_unslash( $_GET['a'] ) ) : 'page'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post   = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];

		if ( $post && 'unlock' === $action ) {
			$this->handle_unlock();
		}
		if ( $post && 'otp_send' === $action ) {
			$this->handle_otp_send();
		}
		if ( $post && 'otp_verify' === $action ) {
			$this->handle_otp_verify();
		}
		if ( 'logout' === $action ) {
			$this->clear_session();
			$this->redirect();
		}

		$gate = $this->pending_gate();
		if ( $gate ) {
			if ( 'page' !== $action ) {
				Http::json( array( 'error' => __( 'Please unlock this share first.', 'filora' ) ), 403 );
			}
			$this->render_gate( $gate );
		}

		switch ( $action ) {
			case 'dl':
				$this->handle_download();
				break;
			case 'raw':
				$this->handle_raw();
				break;
			case 'thumb':
				$this->handle_thumb();
				break;
			case 'zip':
				$this->handle_zip();
				break;
			case 'upload':
				$this->handle_upload();
				break;
			case 'uploaded':
				$this->handle_upload_done();
				break;
			default:
				$this->handle_page();
		}
	}

	/* ---------------------------------------------------------------------
	 * Sessions & gates
	 * ------------------------------------------------------------------- */

	/**
	 * Cookie name for this share.
	 *
	 * @return string
	 */
	private function cookie_name() {
		return 'filora_s' . (int) $this->share['id'];
	}

	/**
	 * Read and verify the signed session cookie.
	 *
	 * @return array
	 */
	private function read_session() {
		$name = $this->cookie_name();
		if ( empty( $_COOKIE[ $name ] ) ) {
			return array();
		}
		$data = Crypto::unpack( wp_unslash( (string) $_COOKIE[ $name ] ), 'share-session' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $data || (int) $data['s'] !== (int) $this->share['id'] || (int) $data['v'] !== (int) $this->share['auth_version'] ) {
			return array();
		}
		return $data;
	}

	/**
	 * Persist the session cookie.
	 *
	 * @param array $data Data (pw, em).
	 */
	private function write_session( array $data ) {
		$hours                    = (int) Settings::value( 'sharing', 'session_hours', 12 );
		$data['s']                = (int) $this->share['id'];
		$data['v']                = (int) $this->share['auth_version'];
		$data['exp']              = time() + $hours * HOUR_IN_SECONDS;
		$this->session            = $data;
		$value                    = Crypto::pack( $data, 'share-session' );
		$_COOKIE[ $this->cookie_name() ] = $value;
		self::set_cookie( $this->cookie_name(), $value, $data['exp'] );
	}

	/**
	 * Remove the session cookie.
	 */
	private function clear_session() {
		self::set_cookie( $this->cookie_name(), '', time() - YEAR_IN_SECONDS );
		$this->session = array();
	}

	/**
	 * Set a hardened cookie.
	 *
	 * @param string $name    Name.
	 * @param string $value   Value.
	 * @param int    $expires Expiry.
	 */
	private static function set_cookie( $name, $value, $expires ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			$name,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Which gate is still closed: "password", "email" or null.
	 *
	 * @return string|null
	 */
	private function pending_gate() {
		if ( ! empty( $this->share['password_hash'] ) && empty( $this->session['pw'] ) ) {
			return 'password';
		}
		if ( ! empty( $this->share['emails'] ) && empty( $this->session['em'] ) ) {
			return 'email';
		}
		return null;
	}

	/**
	 * Verify the anti-CSRF form ticket.
	 *
	 * @return bool
	 */
	private function valid_form_ticket() {
		$ticket = isset( $_POST['ft'] ) ? (string) wp_unslash( $_POST['ft'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		if ( '' === $ticket && isset( $_SERVER['HTTP_X_FILORA_TICKET'] ) ) {
			$ticket = (string) wp_unslash( $_SERVER['HTTP_X_FILORA_TICKET'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$data = Crypto::unpack( $ticket, 'share-form' );
		return $data && (int) $data['s'] === (int) $this->share['id'];
	}

	/**
	 * Form ticket for this share (valid 6 hours).
	 *
	 * @return string
	 */
	private function form_ticket() {
		return Crypto::pack(
			array(
				's'   => (int) $this->share['id'],
				'exp' => time() + 6 * HOUR_IN_SECONDS,
			),
			'share-form'
		);
	}

	/**
	 * Password form submission.
	 */
	private function handle_unlock() {
		$ip     = Http::ip();
		$bucket = 'share-pw-' . $this->share['id'];
		if ( ! $this->valid_form_ticket() ) {
			$this->render_gate( 'password', __( 'Your session expired. Please try again.', 'filora' ) );
		}
		if ( RateLimit::exceeded( $bucket, $ip, 8 ) ) {
			$this->render_gate( 'password', __( 'Too many attempts. Please wait 15 minutes and try again.', 'filora' ), 429 );
		}
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		if ( '' === $password || empty( $this->share['password_hash'] ) || ! wp_check_password( $password, $this->share['password_hash'] ) ) {
			RateLimit::hit( $bucket, $ip, 15 * MINUTE_IN_SECONDS );
			Log::add( 'share.unlock_failed', Paths::from_storage( $this->share['path'] ), array(), (int) $this->share['id'], 0 );
			$this->render_gate( 'password', __( 'Wrong password.', 'filora' ), 403 );
		}
		RateLimit::reset( $bucket, $ip );
		$this->write_session( array_merge( $this->session, array( 'pw' => 1 ) ) );
		Log::add( 'share.unlock', Paths::from_storage( $this->share['path'] ), array(), (int) $this->share['id'], 0 );
		$this->redirect();
	}

	/**
	 * Is the email on the allow-list (exact address or *@domain)?
	 *
	 * @param string $email Email.
	 * @return bool
	 */
	private function email_allowed( $email ) {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return false;
		}
		$domain = substr( strrchr( $email, '@' ), 1 );
		foreach ( explode( "\n", (string) $this->share['emails'] ) as $rule ) {
			$rule = strtolower( trim( $rule ) );
			if ( $rule === $email || ( '*@' === substr( $rule, 0, 2 ) && substr( $rule, 2 ) === $domain ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Transient key for a one-time code.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private function otp_key( $email ) {
		return 'filora_otp_' . md5( $this->share['id'] . '|' . strtolower( $email ) );
	}

	/**
	 * Send a one-time code (same answer whether or not the email is allowed: no enumeration).
	 */
	private function handle_otp_send() {
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $this->valid_form_ticket() ) {
			$this->render_gate( 'email', __( 'Your session expired. Please try again.', 'filora' ) );
		}
		if ( ! is_email( $email ) ) {
			$this->render_gate( 'email', __( 'Please enter a valid email address.', 'filora' ), 400 );
		}
		$ip = Http::ip();
		if ( ! RateLimit::allow( 'share-otp-ip-' . $this->share['id'], $ip, 6, 15 * MINUTE_IN_SECONDS ) || ! RateLimit::allow( 'share-otp-mail', strtolower( $email ), 4, 15 * MINUTE_IN_SECONDS ) ) {
			$this->render_gate( 'email', __( 'Too many attempts. Please wait 15 minutes and try again.', 'filora' ), 429 );
		}
		if ( $this->email_allowed( $email ) ) {
			$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
			set_transient(
				$this->otp_key( $email ),
				array(
					'h' => Crypto::sign( $code, 'otp' . $this->share['id'] ),
					'n' => 0,
				),
				10 * MINUTE_IN_SECONDS
			);
			Mailer::otp( $email, $code, $this->display_name() );
			Log::add( 'share.otp_sent', Paths::from_storage( $this->share['path'] ), array( 'email' => $email ), (int) $this->share['id'], 0 );
		} else {
			Log::add( 'share.otp_refused', Paths::from_storage( $this->share['path'] ), array( 'email' => $email ), (int) $this->share['id'], 0 );
		}
		$this->render_gate( 'code', '', 200, $email );
	}

	/**
	 * Verify a one-time code.
	 */
	private function handle_otp_verify() {
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$code  = isset( $_POST['code'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		if ( ! $this->valid_form_ticket() ) {
			$this->render_gate( 'email', __( 'Your session expired. Please try again.', 'filora' ) );
		}
		$key   = $this->otp_key( $email );
		$state = get_transient( $key );
		if ( ! is_array( $state ) || ! $this->email_allowed( $email ) ) {
			$this->render_gate( 'code', __( 'This code is invalid or expired. Request a new one.', 'filora' ), 403, $email );
		}
		if ( (int) $state['n'] >= 5 ) {
			delete_transient( $key );
			$this->render_gate( 'email', __( 'Too many attempts. Please request a new code.', 'filora' ), 429 );
		}
		if ( ! Crypto::verify( $code, $state['h'], 'otp' . $this->share['id'] ) ) {
			++$state['n'];
			set_transient( $key, $state, 10 * MINUTE_IN_SECONDS );
			Log::add( 'share.otp_failed', Paths::from_storage( $this->share['path'] ), array( 'email' => $email ), (int) $this->share['id'], 0 );
			$this->render_gate( 'code', __( 'This code is invalid or expired. Request a new one.', 'filora' ), 403, $email );
		}
		delete_transient( $key );
		$this->write_session( array_merge( $this->session, array( 'em' => strtolower( $email ) ) ) );
		Log::add( 'share.otp_verified', Paths::from_storage( $this->share['path'] ), array( 'email' => $email ), (int) $this->share['id'], 0 );
		$this->redirect();
	}

	/**
	 * Post/Redirect/Get back to the share page.
	 */
	private function redirect() {
		wp_safe_redirect( Shares::url( $this->token ), 303 );
		exit;
	}

	/**
	 * Persistent anonymous visitor ID (for "my uploads" and view de-duplication).
	 *
	 * @return string
	 */
	private function visitor() {
		$id = isset( $_COOKIE[ self::VISITOR_COOKIE ] ) ? (string) $_COOKIE[ self::VISITOR_COOKIE ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			$id = bin2hex( random_bytes( 16 ) );
			self::set_cookie( self::VISITOR_COOKIE, $id, time() + YEAR_IN_SECONDS );
			$_COOKIE[ self::VISITOR_COOKIE ] = $id;
		}
		return $id;
	}

	/**
	 * Details stored with each log entry.
	 *
	 * @param array $extra Extra.
	 * @return array
	 */
	private function log_details( array $extra = array() ) {
		if ( ! empty( $this->session['em'] ) ) {
			$extra['email'] = $this->session['em'];
		}
		$extra['visitor'] = substr( $this->visitor(), 0, 12 );
		return $extra;
	}

	/* ---------------------------------------------------------------------
	 * Path resolution inside the share
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve ?p= inside the share root. Dotfiles, sensitive files, the owner's hidden
	 * items and symlinks escaping the share are never reachable.
	 *
	 * @param bool $must_be_dir Expect a folder.
	 * @return string
	 */
	private function target( $must_be_dir = false ) {
		if ( 'file' === $this->share['type'] ) {
			if ( $must_be_dir ) {
				Http::json( array( 'error' => 'not_dir' ), 400 );
			}
			return $this->root;
		}
		$p = isset( $_GET['p'] ) ? (string) wp_unslash( $_GET['p'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		try {
			$rel = Paths::clean_rel( $p );
		} catch ( Error $e ) {
			$this->not_found();
		}
		$abs = Paths::real( Paths::join( $this->root, $rel ) );
		if ( ! $abs || ! Paths::is_within( $abs, $this->root ) || ! $this->visible( $abs ) ) {
			$this->not_found();
		}
		if ( $must_be_dir && ! is_dir( $abs ) ) {
			$this->not_found();
		}
		return $abs;
	}

	/**
	 * Is an item visible through this share?
	 *
	 * @param string $abs Absolute path.
	 * @return bool
	 */
	private function visible( $abs ) {
		$rel = Paths::relative( $abs, $this->root );
		if ( '' !== $rel ) {
			foreach ( explode( '/', $rel ) as $segment ) {
				if ( '' !== $segment && '.' === $segment[0] ) {
					return false;
				}
			}
		}
		if ( 'hidden' === $this->owner_fs->visibility( $abs ) || $this->owner_fs->is_sensitive( $abs ) ) {
			return false;
		}
		if ( Policy::matches( Settings::default_sensitive_patterns(), $rel, null ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Abort with a 404 (JSON for API calls, page otherwise).
	 */
	private function not_found() {
		$action = isset( $_GET['a'] ) ? sanitize_key( wp_unslash( $_GET['a'] ) ) : 'page'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'page' === $action ) {
			$this->message( 'item_missing', 404 );
		}
		status_header( 404 );
		exit;
	}

	/**
	 * Visible children of a folder, folders first, natural order.
	 *
	 * @param string $dir       Folder.
	 * @param bool   $summaries Add a content summary to sub-folders (page view).
	 * @return array
	 */
	private function children( $dir, $summaries = false ) {
		$out = array();
		foreach ( (array) @scandir( $dir ) as $name ) { // phpcs:ignore
			if ( '.' === $name || '..' === $name || '' === $name || '.' === $name[0] ) {
				continue;
			}
			$abs = $dir . '/' . $name;
			if ( is_link( $abs ) ) {
				$real = Paths::real( $abs );
				if ( ! $real || ! Paths::is_within( $real, $this->root ) ) {
					continue;
				}
			}
			if ( ! $this->visible( $abs ) ) {
				continue;
			}
			$is_dir = is_dir( $abs );
			$out[]  = array(
				'name'  => $name,
				'abs'   => $abs,
				'rel'   => Paths::relative( $abs, $this->root ),
				'dir'   => $is_dir,
				'size'  => $is_dir ? 0 : (int) @filesize( $abs ), // phpcs:ignore
				'mtime' => (int) @filemtime( $abs ), // phpcs:ignore
				'cat'   => $is_dir ? 'dir' : Mime::category( $name ),
				'ext'   => $is_dir ? '' : Paths::ext( $name ),
				'thumb' => ! $is_dir && Mime::is_thumbable( $name ),
			);
			if ( count( $out ) >= 3000 ) {
				break;
			}
		}
		if ( $summaries ) {
			$budget = 48; // Folder cards with a summary (each one scans its folder).
			foreach ( $out as &$item ) {
				if ( $item['dir'] && $budget-- > 0 ) {
					$item['summary'] = $this->summary( $item['abs'] );
				}
			}
			unset( $item );
		}
		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['dir'] !== $b['dir'] ) {
					return $a['dir'] ? -1 : 1;
				}
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);
		return $out;
	}

	/**
	 * What a sub-folder contains: counts and a few photos for its card.
	 *
	 * @param string $dir Folder.
	 * @return array{dirs:int,files:int,images:int,thumbs:string[]}
	 */
	private function summary( $dir ) {
		$out  = array(
			'dirs'   => 0,
			'files'  => 0,
			'images' => 0,
			'thumbs' => array(),
		);
		$seen = 0;
		foreach ( (array) @scandir( $dir ) as $name ) { // phpcs:ignore
			if ( '' === $name || '.' === $name[0] ) {
				continue;
			}
			if ( ++$seen > 200 ) {
				break;
			}
			$abs = $dir . '/' . $name;
			if ( is_link( $abs ) ) {
				$real = Paths::real( $abs );
				if ( ! $real || ! Paths::is_within( $real, $this->root ) ) {
					continue;
				}
			}
			if ( ! $this->visible( $abs ) ) {
				continue;
			}
			if ( is_dir( $abs ) ) {
				++$out['dirs'];
				continue;
			}
			++$out['files'];
			if ( 'image' === Mime::category( $name ) ) {
				++$out['images'];
				if ( count( $out['thumbs'] ) < 3 && Mime::is_thumbable( $name ) ) {
					$out['thumbs'][] = Paths::relative( $abs, $this->root );
				}
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------- */

	/**
	 * Can visitors download?
	 *
	 * @return bool
	 */
	private function can_download() {
		return in_array( $this->share['mode'], array( 'download', 'upload' ), true );
	}

	/**
	 * Can visitors upload?
	 *
	 * @return bool
	 */
	private function can_upload() {
		return 'dir' === $this->share['type']
			&& in_array( $this->share['mode'], array( 'upload', 'dropbox' ), true )
			&& Settings::value( 'sharing', 'allow_upload', true )
			&& ! Settings::value( 'general', 'read_only', false );
	}

	/**
	 * Can visitors browse / preview?
	 *
	 * @return bool
	 */
	private function can_browse() {
		return 'dropbox' !== $this->share['mode'];
	}

	/**
	 * Inline previews are disabled when a download limit is set (otherwise the limit
	 * could be bypassed by "previewing" the file).
	 *
	 * @return bool
	 */
	private function can_preview() {
		return $this->can_browse() && empty( $this->share['max_downloads'] );
	}

	/**
	 * Should this request count (not a bot, not HEAD, not a resumed range)?
	 *
	 * @return bool
	 */
	private function countable() {
		if ( Http::is_bot() ) {
			return false;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
			return false;
		}
		if ( isset( $_SERVER['HTTP_RANGE'] ) && ! preg_match( '/^bytes=0-/', (string) $_SERVER['HTTP_RANGE'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return false;
		}
		return true;
	}

	/**
	 * Main page (folder listing or single file).
	 */
	private function handle_page() {
		$dir  = 'dir' === $this->share['type'] ? $this->target( false ) : $this->root;
		$open = '';
		if ( 'dir' === $this->share['type'] && ! is_dir( $dir ) ) {
			// ?p= points to a file: show the parent folder with the preview open.
			$open = $this->rel( $dir );
			$dir  = dirname( $dir );
		}
		if ( $this->countable() ) {
			$key = 'filora_seen_' . md5( $this->share['id'] . '|' . $this->visitor() );
			if ( ! get_transient( $key ) ) {
				set_transient( $key, 1, 12 * HOUR_IN_SECONDS );
				Shares::increment( (int) $this->share['id'], 'view_count' );
				Log::add( 'share.view', $this->root, $this->log_details(), (int) $this->share['id'], 0 );
				Mailer::notify_owner( $this->share, 'view', $this->log_details() );
			}
		}
		$items = array();
		if ( 'dir' === $this->share['type'] && $this->can_browse() ) {
			$items = $this->children( $dir, true );
		}
		$this->render(
			'page',
			array(
				'dir'      => $dir,
				'open'     => $open,
				'items'    => $items,
				'my_files' => 'dropbox' === $this->share['mode'] ? $this->my_uploads() : array(),
			)
		);
	}

	/**
	 * Download one file.
	 */
	private function handle_download() {
		if ( ! $this->can_download() ) {
			status_header( 403 );
			exit;
		}
		$abs = $this->target( false );
		if ( is_dir( $abs ) ) {
			$this->handle_zip();
		}
		if ( $this->countable() ) {
			Shares::increment( (int) $this->share['id'], 'download_count' );
			Log::add( 'share.download', $abs, $this->log_details(), (int) $this->share['id'], 0 );
			Mailer::notify_owner( $this->share, 'download', $this->log_details( array( 'files' => array( basename( $abs ) ) ) ) );
			do_action( 'filora_share_download', $this->share, $abs );
		}
		Stream::send( $abs, array( 'inline' => false ) );
	}

	/**
	 * Inline preview.
	 */
	private function handle_raw() {
		if ( ! $this->can_preview() ) {
			status_header( 403 );
			exit;
		}
		$abs = $this->target( false );
		if ( is_dir( $abs ) ) {
			$this->not_found();
		}
		$mime = Mime::type( $abs );
		$text = Mime::is_text( $abs ) && filesize( $abs ) < 2 * MB_IN_BYTES;
		if ( ! Mime::inline_safe( $mime ) && ! $text ) {
			status_header( 415 );
			exit;
		}
		Stream::send(
			$abs,
			array(
				'inline' => true,
				'mime'   => $text ? 'text/plain' : $mime,
			)
		);
	}

	/**
	 * Image thumbnail.
	 */
	private function handle_thumb() {
		// Thumbnails can be the original file (small images): same rule as previews,
		// so a download limit cannot be bypassed.
		if ( ! $this->can_preview() ) {
			status_header( 403 );
			exit;
		}
		$abs   = $this->target( false );
		$size  = isset( $_GET['s'] ) ? (int) $_GET['s'] : 320; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$thumb = Images::thumb( $abs, $size );
		if ( ! $thumb ) {
			status_header( 404 );
			exit;
		}
		Stream::send(
			$thumb,
			array(
				'inline' => true,
				'cache'  => DAY_IN_SECONDS,
				'name'   => pathinfo( basename( $abs ), PATHINFO_FILENAME ) . '.' . Paths::ext( $thumb ),
			)
		);
	}

	/**
	 * Zip a folder of the share.
	 */
	private function handle_zip() {
		if ( ! $this->can_download() || 'dir' !== $this->share['type'] || empty( $this->options['allow_zip'] ) ) {
			status_header( 403 );
			exit;
		}
		$dir = $this->target( true );
		if ( ! RateLimit::allow( 'share-zip', Http::ip(), 8, 10 * MINUTE_IN_SECONDS ) ) {
			$this->message( 'rate_limited', 429 );
		}
		$name = ( $dir === $this->root && '' !== $this->share['label'] ? sanitize_file_name( $this->share['label'] ) : basename( $dir ) ) . '.zip';
		$tmp  = Storage::dir( 'tmp' ) . '/zip-' . bin2hex( random_bytes( 8 ) ) . '.zip';
		$max  = (int) Settings::value( 'sharing', 'zip_max', 2048 ) * MB_IN_BYTES;
		try {
			$items = array();
			foreach ( $this->children( $dir ) as $child ) {
				$items[] = $child['abs'];
			}
			$self = $this;
			Zipper::create(
				$items,
				$tmp,
				static function ( $abs ) use ( $self ) {
					return $self->visible_for_zip( $abs );
				},
				$max
			);
		} catch ( Error $e ) {
			@unlink( $tmp ); // phpcs:ignore
			$this->message( 'zip_error', $e->status, $e->getMessage() );
		}
		if ( $this->countable() ) {
			Shares::increment( (int) $this->share['id'], 'download_count' );
			Log::add( 'share.download_zip', $dir, $this->log_details(), (int) $this->share['id'], 0 );
			Mailer::notify_owner( $this->share, 'download', $this->log_details( array( 'files' => array( $name ) ) ) );
		}
		Stream::send(
			$tmp,
			array(
				'name'         => $name,
				'delete_after' => true,
			)
		);
	}

	/**
	 * Zip filter (public for the closure).
	 *
	 * @param string $abs Path.
	 * @return bool
	 */
	public function visible_for_zip( $abs ) {
		return Paths::is_within( $abs, $this->root ) && $this->visible( $abs );
	}

	/**
	 * Receive an upload chunk.
	 */
	private function handle_upload() {
		$post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		if ( ! $post || ! $this->can_upload() || empty( $_SERVER['HTTP_X_FILORA'] ) || ! $this->valid_form_ticket() ) {
			Http::json( array( 'error' => __( 'Uploads are not allowed here.', 'filora' ) ), 403 );
		}
		$dest    = 'dropbox' === $this->share['mode'] ? $this->root : $this->target( true );
		$exts    = (string) $this->options['upload_exts'];
		$max     = (int) $this->options['upload_max'] * MB_IN_BYTES;
		$visitor = $this->visitor();
		$self    = $this;
		$params  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$file    = isset( $_FILES['chunk'] ) ? $_FILES['chunk'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! RateLimit::allow( 'share-upload', Http::ip(), 3000, HOUR_IN_SECONDS ) ) {
			Http::json( array( 'error' => __( 'Too many uploads. Please wait a moment.', 'filora' ) ), 429 );
		}
		try {
			$result = Uploads::chunk(
				$params,
				$file,
				's' . $this->share['id'] . ':' . $visitor,
				static function ( $name, $size ) use ( $exts, $max ) {
					Policy::assert_public_uploadable( $name, $exts );
					if ( $max && $size > $max ) {
						/* translators: %s: max size */
						throw new Error( 'too_large', sprintf( __( 'This file is too large (max %s).', 'filora' ), size_format( $max ) ), 413 );
					}
				},
				static function ( $tmp, $name ) use ( $self, $dest ) {
					return $self->store_upload( $tmp, $name, $dest );
				}
			);
		} catch ( Error $e ) {
			Http::json( array( 'error' => $e->getMessage() ), $e->status );
		}
		Http::json( $result );
	}

	/**
	 * Move a finished public upload into the shared folder (never overwrites).
	 *
	 * @param string $tmp  Assembled file.
	 * @param string $name Original name.
	 * @param string $dest Destination folder.
	 * @return array
	 */
	public function store_upload( $tmp, $name, $dest ) {
		$name = sanitize_file_name( $name );
		Policy::assert_public_uploadable( $name, (string) $this->options['upload_exts'] );
		Uploads::assert_content_matches( $tmp, $name );
		$name   = Paths::unique_name( $dest, $name );
		$target = $dest . '/' . $name;
		if ( ! @rename( $tmp, $target ) && ! ( @copy( $tmp, $target ) && @unlink( $tmp ) ) ) { // phpcs:ignore
			throw new Error( 'write_failed', __( 'The file could not be saved.', 'filora' ), 500 );
		}
		@chmod( $target, 0644 ); // phpcs:ignore
		$visitor_name = isset( $_POST['visitor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Shares::increment( (int) $this->share['id'], 'upload_count' );
		Log::add( 'share.upload', $target, $this->log_details( array_filter( array( 'name' => substr( $visitor_name, 0, 100 ) ) ) ), (int) $this->share['id'], 0 );
		do_action( 'filora_share_uploaded', $this->share, $target );
		return array(
			'name' => $name,
			'size' => (int) filesize( $target ),
		);
	}

	/**
	 * Batch notification after an upload queue finished.
	 */
	private function handle_upload_done() {
		if ( ! $this->can_upload() || ! $this->valid_form_ticket() ) {
			Http::json( array( 'ok' => false ), 403 );
		}
		$files = $this->my_uploads( HOUR_IN_SECONDS );
		if ( $files ) {
			$name = isset( $_POST['visitor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			Mailer::notify_owner(
				$this->share,
				'upload',
				$this->log_details(
					array(
						'files' => wp_list_pluck( $files, 'name' ),
						'name'  => $name,
					)
				)
			);
		}
		Http::json( array( 'ok' => true ) );
	}

	/**
	 * Files uploaded by this visitor (dropbox mode shows only these).
	 *
	 * @param int $within Seconds back (0 = 30 days).
	 * @return array
	 */
	private function my_uploads( $within = 0 ) {
		$log   = Log::query(
			array(
				'share'    => (int) $this->share['id'],
				'action'   => 'share.upload',
				'from'     => time() - ( $within ? $within : 30 * DAY_IN_SECONDS ),
				'per_page' => 200,
			)
		);
		$mine  = substr( $this->visitor(), 0, 12 );
		$files = array();
		foreach ( $log['items'] as $item ) {
			if ( empty( $item['details']['visitor'] ) || $item['details']['visitor'] !== $mine ) {
				continue;
			}
			$abs = Paths::from_storage( $item['path'] );
			if ( is_file( $abs ) ) {
				$files[] = array(
					'name' => basename( $abs ),
					'size' => (int) filesize( $abs ),
					'time' => $item['time'],
				);
			}
		}
		return $files;
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	/**
	 * Name shown to visitors.
	 *
	 * @return string
	 */
	public function display_name() {
		if ( $this->share && '' !== $this->share['label'] ) {
			return $this->share['label'];
		}
		return $this->root ? basename( $this->root ) : '';
	}

	/**
	 * URL for an action inside this share.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public function link( array $args = array() ) {
		$args = array_filter(
			$args,
			static function ( $v ) {
				return null !== $v && '' !== $v;
			}
		);
		return add_query_arg( array_map( 'rawurlencode', $args ), Shares::url( $this->token ) );
	}

	/**
	 * Render a gate page and exit.
	 *
	 * @param string $gate   password|email|code.
	 * @param string $error  Error message.
	 * @param int    $status HTTP status.
	 * @param string $email  Email (code step).
	 */
	private function render_gate( $gate, $error = '', $status = 200, $email = '' ) {
		status_header( $status );
		$this->render(
			'gate',
			array(
				'gate'  => $gate,
				'error' => $error,
				'email' => $email,
			)
		);
	}

	/**
	 * Render a message page (expired, not found...) and exit.
	 *
	 * @param string $kind   Kind.
	 * @param int    $status HTTP status.
	 * @param string $text   Custom text.
	 */
	private function message( $kind, $status, $text = '' ) {
		status_header( $status );
		$this->render(
			'message',
			array(
				'kind' => $kind,
				'text' => $text,
			)
		);
	}

	/**
	 * Render a template inside the share layout and exit.
	 *
	 * @param string $view View name.
	 * @param array  $vars Variables.
	 */
	private function render( $view, array $vars ) {
		Stream::clean_buffers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header(
				"Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https:; media-src 'self' blob:; style-src 'self' 'unsafe-inline'; script-src 'nonce-" . $this->nonce . "'; "
				. "frame-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'"
			);
		}
		$router  = $this;
		$share   = $this->share;
		$options = $this->options;
		$nonce   = $this->nonce;
		$ctx     = array(
			'can_download' => $this->share ? $this->can_download() : false,
			'can_upload'   => $this->share ? $this->can_upload() : false,
			'can_browse'   => $this->share ? $this->can_browse() : false,
			'can_preview'  => $this->share ? $this->can_preview() : false,
			'gated'        => $this->share && ( ! empty( $this->share['password_hash'] ) || ! empty( $this->share['emails'] ) ),
			'ticket'       => $this->share ? $this->form_ticket() : '',
			'session'      => $this->session,
			'root'         => $this->root,
		);
		extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include FILORA_DIR . 'templates/share.php';
		exit;
	}

	/**
	 * Relative path of a folder inside the share ("" for the root).
	 *
	 * @param string $abs Absolute path.
	 * @return string
	 */
	public function rel( $abs ) {
		return Paths::relative( $abs, $this->root );
	}
}
