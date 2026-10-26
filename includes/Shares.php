<?php
/**
 * Share links model.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD + validation for the {prefix}filora_shares table.
 */
final class Shares {

	const MODES = array( 'view', 'download', 'upload', 'dropbox' );

	/**
	 * Owner profiles cache.
	 *
	 * @var array
	 */
	private static $owners = array();

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() {
		return Installer::table( 'shares' );
	}

	/**
	 * Public URL for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function url( $token ) {
		$slug = Settings::value( 'sharing', 'slug', 'share' );
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( user_trailingslashit( $slug . '/' . $token, 'single' ) );
		}
		return add_query_arg( ShareRouter::QUERY_VAR, $token, home_url( '/' ) );
	}

	/**
	 * Paths that may never be shared, whatever the user's root.
	 *
	 * @param string $abs Absolute path.
	 * @return string|null Reason, or null when allowed.
	 */
	public static function forbidden_reason( $abs ) {
		$abs  = rtrim( str_replace( '\\', '/', $abs ), '/' );
		$site = Paths::abspath();
		if ( Paths::is_within( $site, $abs ) ) {
			return __( 'The WordPress root folder (or one of its parents) cannot be shared.', 'filora' );
		}
		if ( Paths::is_within( $abs, Storage::base() ) ) {
			return __( 'This folder is private.', 'filora' );
		}
		if ( Paths::is_within( $abs, $site . '/wp-admin' ) || Paths::is_within( $abs, $site . '/wp-includes' ) ) {
			return __( 'WordPress system folders cannot be shared.', 'filora' );
		}
		$blocked = array( WP_CONTENT_DIR, WP_PLUGIN_DIR, get_theme_root() );
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$blocked[] = WPMU_PLUGIN_DIR;
		}
		foreach ( $blocked as $dir ) {
			$real = Paths::real( $dir );
			$real = $real ? $real : rtrim( str_replace( '\\', '/', $dir ), '/' );
			if ( $abs === $real ) {
				return __( 'WordPress system folders cannot be shared.', 'filora' );
			}
		}
		if ( is_dir( $abs ) && file_exists( $abs . '/wp-config.php' ) ) {
			return __( 'Folders containing wp-config.php cannot be shared.', 'filora' );
		}
		return apply_filters( 'filora_share_forbidden_reason', null, $abs );
	}

	/**
	 * Create a share.
	 *
	 * @param Fs    $fs   Filesystem of the creator.
	 * @param array $data Input.
	 * @return array Client representation (includes the URL).
	 * @throws Error On validation errors.
	 */
	public static function create( Fs $fs, array $data ) {
		$p = $fs->profile();
		$p->require_op( 'share' );
		$abs = $fs->abs( isset( $data['path'] ) ? $data['path'] : '' );
		if ( $fs->is_sensitive( $abs ) ) {
			throw Error::forbidden( __( 'Sensitive files (configuration, keys, database dumps…) can never be shared.', 'filora' ) );
		}
		$reason = self::forbidden_reason( $abs );
		if ( $reason ) {
			throw Error::forbidden( $reason );
		}
		if ( 'hidden' === $fs->visibility( $abs ) ) {
			throw Error::not_found();
		}
		$type  = is_dir( $abs ) ? 'dir' : 'file';
		$clean = self::validate( $data, $p, $type, true );
		$token = Crypto::token();
		$now   = current_time( 'mysql', true );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			self::table(),
			array_merge(
				$clean,
				array(
					'token_hash' => Crypto::token_hash( $token ),
					'token_enc'  => Crypto::encrypt( $token ),
					'path'       => Paths::to_storage( $abs ),
					'type'       => $type,
					'created_by' => $p->user_id,
					'created_at' => $now,
					'updated_at' => $now,
					'status'     => 'active',
				)
			)
		);
		if ( ! $ok ) {
			throw new Error( 'db_error', __( 'The share link could not be saved.', 'filora' ), 500 );
		}
		$row = self::get( (int) $wpdb->insert_id );
		Log::add( 'share.create', $abs, array( 'mode' => $row['mode'] ), (int) $row['id'] );
		do_action( 'filora_share_created', $row );
		$out          = self::to_client( $row, $p );
		$out['url']   = self::url( $token );
		$out['fresh'] = true;
		return $out;
	}

	/**
	 * Validate input for create / update.
	 *
	 * @param array   $data     Input.
	 * @param Profile $p        Profile of the editor.
	 * @param string  $type     file|dir.
	 * @param bool    $creating Creating (vs updating).
	 * @param array   $current  Current row (update).
	 * @return array Columns.
	 * @throws Error On validation errors.
	 */
	private static function validate( array $data, Profile $p, $type, $creating, array $current = array() ) {
		$sharing = Settings::get( 'sharing' );
		$out     = array();

		if ( $creating || isset( $data['mode'] ) ) {
			$mode = isset( $data['mode'] ) ? (string) $data['mode'] : 'download';
			if ( ! in_array( $mode, self::MODES, true ) ) {
				$mode = 'download';
			}
			if ( in_array( $mode, array( 'upload', 'dropbox' ), true ) ) {
				if ( 'dir' !== $type ) {
					throw new Error( 'invalid_mode', __( 'Uploads are only possible on shared folders.', 'filora' ) );
				}
				if ( empty( $sharing['allow_upload'] ) || ! $p->can( 'upload' ) ) {
					throw Error::forbidden( __( 'You are not allowed to create upload links.', 'filora' ) );
				}
			}
			if ( in_array( $mode, array( 'download', 'upload' ), true ) && ! $p->can( 'download' ) ) {
				$mode = 'view';
			}
			$out['mode'] = $mode;
		}

		if ( $creating || array_key_exists( 'label', $data ) ) {
			$out['label'] = substr( sanitize_text_field( (string) ( isset( $data['label'] ) ? $data['label'] : '' ) ), 0, 190 );
		}

		if ( $creating || array_key_exists( 'expires', $data ) ) {
			$expires = isset( $data['expires'] ) ? $data['expires'] : ( $creating ? (int) $sharing['default_days'] . 'd' : null );
			$ts      = self::parse_expiry( $expires );
			$max     = (int) $sharing['max_days'];
			if ( $max > 0 ) {
				$limit = time() + $max * DAY_IN_SECONDS;
				if ( null === $ts || $ts > $limit ) {
					$ts = $limit;
				}
			}
			if ( null !== $ts && $ts <= time() ) {
				throw new Error( 'invalid_expiry', __( 'The expiration date must be in the future.', 'filora' ) );
			}
			$out['expires_at'] = null === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
		}

		if ( array_key_exists( 'password', $data ) ) {
			$password = (string) $data['password'];
			if ( '' === $password ) {
				$out['password_hash'] = null;
			} else {
				if ( strlen( $password ) < 4 ) {
					throw new Error( 'weak_password', __( 'The password must contain at least 4 characters.', 'filora' ) );
				}
				$out['password_hash'] = wp_hash_password( $password );
			}
		}
		$has_password = array_key_exists( 'password_hash', $out ) ? null !== $out['password_hash'] : ! empty( $current['password_hash'] );
		if ( ! empty( $sharing['force_password'] ) && ! $has_password ) {
			throw new Error( 'password_required', __( 'A password is required for share links on this site.', 'filora' ) );
		}

		if ( $creating || array_key_exists( 'emails', $data ) ) {
			$emails = array();
			$list   = isset( $data['emails'] ) ? $data['emails'] : array();
			$list   = is_array( $list ) ? $list : preg_split( '/[\s,;]+/', (string) $list );
			foreach ( $list as $email ) {
				$email = strtolower( trim( (string) $email ) );
				if ( '' === $email ) {
					continue;
				}
				if ( is_email( $email ) || preg_match( '/^\*@[a-z0-9.-]+\.[a-z]{2,}$/', $email ) ) {
					$emails[] = $email;
				} else {
					/* translators: %s: invalid email */
					throw new Error( 'invalid_email', sprintf( __( 'Invalid email address: %s', 'filora' ), $email ) );
				}
			}
			$out['emails'] = $emails ? implode( "\n", array_unique( $emails ) ) : null;
		}

		if ( $creating || array_key_exists( 'ips', $data ) ) {
			$ips  = array();
			$list = isset( $data['ips'] ) ? $data['ips'] : array();
			$list = is_array( $list ) ? $list : preg_split( '/[\s,;]+/', (string) $list );
			foreach ( $list as $ip ) {
				$ip = trim( (string) $ip );
				if ( '' === $ip ) {
					continue;
				}
				$base = explode( '/', $ip )[0];
				if ( ! filter_var( $base, FILTER_VALIDATE_IP ) ) {
					/* translators: %s: invalid IP */
					throw new Error( 'invalid_ip', sprintf( __( 'Invalid IP address or range: %s', 'filora' ), $ip ) );
				}
				$ips[] = $ip;
			}
			$out['ips'] = $ips ? implode( "\n", array_unique( $ips ) ) : null;
		}

		if ( $creating || array_key_exists( 'max_downloads', $data ) ) {
			$max                  = isset( $data['max_downloads'] ) ? (int) $data['max_downloads'] : 0;
			$out['max_downloads'] = $max > 0 ? $max : null;
		}

		if ( $creating || isset( $data['options'] ) ) {
			$o       = isset( $data['options'] ) && is_array( $data['options'] ) ? $data['options'] : array();
			$current_o = isset( $current['options'] ) ? (array) json_decode( (string) $current['options'], true ) : array();
			$o       = $o + $current_o;
			$global  = (string) $sharing['upload_exts'];
			$exts    = isset( $o['upload_exts'] ) && '' !== $o['upload_exts'] ? Settings::exts( $o['upload_exts'] ) : $global;
			if ( '*' !== $global && '*' !== $exts ) {
				$exts = implode( ',', array_intersect( explode( ',', $exts ), explode( ',', $global ) ) );
			} elseif ( '*' !== $global ) {
				$exts = $global;
			}
			$upload_max     = isset( $o['upload_max'] ) ? (int) $o['upload_max'] : (int) $sharing['upload_max'];
			$out['options'] = wp_json_encode(
				array(
					'message'     => substr( sanitize_textarea_field( (string) ( isset( $o['message'] ) ? $o['message'] : '' ) ), 0, 2000 ),
					'notify'      => isset( $o['notify'] ) && in_array( $o['notify'], array( 'none', 'uploads', 'all' ), true ) ? $o['notify'] : $sharing['notify'],
					'allow_zip'   => ! isset( $o['allow_zip'] ) || ! empty( $o['allow_zip'] ),
					'upload_exts' => $exts,
					'upload_max'  => max( 1, min( (int) $sharing['upload_max'], $upload_max ) ),
					'show_owner'  => isset( $o['show_owner'] ) ? ! empty( $o['show_owner'] ) : ! empty( $sharing['show_owner'] ),
					'ask_name'    => ! empty( $o['ask_name'] ),
				)
			);
		}

		return $out;
	}

	/**
	 * Parse "7d", "24h", a timestamp or an ISO date into a UTC timestamp (null = never).
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	public static function parse_expiry( $value ) {
		if ( null === $value || '' === $value || 'never' === $value || '0d' === $value || 0 === $value || '0' === $value ) {
			return null;
		}
		if ( is_numeric( $value ) ) {
			$value = (int) $value;
			return $value > 1000000000 ? $value : time() + $value * DAY_IN_SECONDS;
		}
		if ( preg_match( '/^(\d+)\s*([hdwm])$/i', (string) $value, $m ) ) {
			$units = array(
				'h' => HOUR_IN_SECONDS,
				'd' => DAY_IN_SECONDS,
				'w' => WEEK_IN_SECONDS,
				'm' => MONTH_IN_SECONDS,
			);
			$n     = (int) $m[1];
			return $n > 0 ? time() + $n * $units[ strtolower( $m[2] ) ] : null;
		}
		$ts = strtotime( (string) $value );
		if ( false === $ts ) {
			throw new Error( 'invalid_expiry', __( 'Invalid expiration date.', 'filora' ) );
		}
		return $ts;
	}

	/**
	 * Update a share.
	 *
	 * @param int     $id   ID.
	 * @param array   $data Input.
	 * @param Profile $p    Editor.
	 * @return array
	 * @throws Error On errors.
	 */
	public static function update( $id, array $data, Profile $p ) {
		$row = self::editable( $id, $p );
		$set = self::validate( $data, $p, $row['type'], false, $row );
		if ( isset( $data['status'] ) && in_array( $data['status'], array( 'active', 'disabled' ), true ) ) {
			$set['status'] = $data['status'];
		}
		// Changing who can get in invalidates every visitor session.
		if ( array_key_exists( 'password_hash', $set ) || array_key_exists( 'emails', $set ) || ! empty( $data['revoke_sessions'] ) ) {
			$set['auth_version'] = (int) $row['auth_version'] + 1;
		}
		if ( ! empty( $data['reset_counters'] ) ) {
			$set['download_count'] = 0;
		}
		$set['updated_at'] = current_time( 'mysql', true );
		global $wpdb;
		$wpdb->update( self::table(), $set, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = self::get( (int) $id );
		Log::add( 'share.update', Paths::from_storage( $row['path'] ), array_keys( $set ), (int) $id );
		return self::to_client( $row, $p );
	}

	/**
	 * Replace the token (old link stops working immediately).
	 *
	 * @param int     $id ID.
	 * @param Profile $p  Editor.
	 * @return array
	 */
	public static function regenerate( $id, Profile $p ) {
		$row   = self::editable( $id, $p );
		$token = Crypto::token();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array(
				'token_hash'   => Crypto::token_hash( $token ),
				'token_enc'    => Crypto::encrypt( $token ),
				'auth_version' => (int) $row['auth_version'] + 1,
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
		Log::add( 'share.regenerate', Paths::from_storage( $row['path'] ), array(), (int) $id );
		$out        = self::to_client( self::get( (int) $id ), $p );
		$out['url'] = self::url( $token );
		return $out;
	}

	/**
	 * Delete a share.
	 *
	 * @param int     $id ID.
	 * @param Profile $p  Editor.
	 */
	public static function delete( $id, Profile $p ) {
		$row = self::editable( $id, $p );
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Log::add( 'share.delete', Paths::from_storage( $row['path'] ), array(), (int) $id );
	}

	/**
	 * Fetch a row the profile may edit.
	 *
	 * @param int     $id ID.
	 * @param Profile $p  Profile.
	 * @return array
	 * @throws Error When missing / not allowed.
	 */
	public static function editable( $id, Profile $p ) {
		$row = self::get( $id );
		if ( ! $row ) {
			throw Error::not_found();
		}
		if ( ! $p->is_admin && (int) $row['created_by'] !== $p->user_id ) {
			throw Error::not_found();
		}
		return $row;
	}

	/**
	 * Raw row by ID.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Raw row by token (constant-time lookup through the hash index).
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function find_by_token( $token ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,128}$/', (string) $token ) ) {
			return null;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", Crypto::token_hash( $token ) ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * List shares visible to a profile.
	 *
	 * @param Profile $p    Profile.
	 * @param array   $args path (abs) to filter on one item.
	 * @return array
	 */
	public static function all( Profile $p, array $args = array() ) {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();
		if ( ! $p->is_admin ) {
			$where[]  = 'created_by = %d';
			$params[] = $p->user_id;
		}
		if ( ! empty( $args['path'] ) ) {
			$where[]  = 'path = %s';
			$params[] = Paths::to_storage( $args['path'] );
		}
		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 2000';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::to_client( $row, $p );
		}
		return $out;
	}

	/**
	 * Absolute paths of active shares directly inside (or equal to) a folder.
	 *
	 * @param string $dir Folder.
	 * @return string[]
	 */
	public static function paths_in( $dir ) {
		global $wpdb;
		$table  = self::table();
		$stored = Paths::to_storage( $dir );
		$like   = '' === $stored ? '%' : $wpdb->esc_like( $stored . '/' ) . '%';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT path FROM {$table} WHERE status = 'active' AND path LIKE %s LIMIT 5000", $like ) );
		$out  = array();
		foreach ( (array) $rows as $path ) {
			$out[] = Paths::from_storage( $path );
		}
		return $out;
	}

	/**
	 * Keep links working after a rename / move inside the file manager.
	 *
	 * @param string $old Old absolute path.
	 * @param string $new New absolute path.
	 */
	public static function path_moved( $old, $new ) {
		global $wpdb;
		$table = self::table();
		$old_s = Paths::to_storage( $old );
		$new_s = Paths::to_storage( $new );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, path FROM {$table} WHERE path = %s OR path LIKE %s", $old_s, $wpdb->esc_like( $old_s . '/' ) . '%' ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$wpdb->update( $table, array( 'path' => $new_s . substr( $row['path'], strlen( $old_s ) ) ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Atomically increment a counter.
	 *
	 * @param int    $id    ID.
	 * @param string $field download_count|view_count|upload_count.
	 */
	public static function increment( $id, $field ) {
		if ( ! in_array( $field, array( 'download_count', 'view_count', 'upload_count' ), true ) ) {
			return;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$field} = {$field} + 1, last_access_at = %s WHERE id = %d", current_time( 'mysql', true ), (int) $id ) );
	}

	/**
	 * Decoded options with defaults.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function options( array $row ) {
		$sharing = Settings::get( 'sharing' );
		$o       = json_decode( (string) $row['options'], true );
		$o       = is_array( $o ) ? $o : array();
		return $o + array(
			'message'     => '',
			'notify'      => $sharing['notify'],
			'allow_zip'   => true,
			'upload_exts' => $sharing['upload_exts'],
			'upload_max'  => (int) $sharing['upload_max'],
			'show_owner'  => ! empty( $sharing['show_owner'] ),
			'ask_name'    => false,
		);
	}

	/**
	 * Owner profile (cached), null if the owner lost access.
	 *
	 * @param array $row Row.
	 * @return Profile|null
	 */
	public static function owner_profile( array $row ) {
		$uid = (int) $row['created_by'];
		if ( ! array_key_exists( $uid, self::$owners ) ) {
			$user                 = get_userdata( $uid );
			self::$owners[ $uid ] = $user ? Access::profile( $user ) : null;
		}
		return self::$owners[ $uid ];
	}

	/**
	 * Computed status: active | disabled | expired | exhausted | missing | orphaned.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	public static function status( array $row ) {
		if ( 'active' !== $row['status'] ) {
			return 'disabled';
		}
		if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] . ' UTC' ) <= time() ) {
			return 'expired';
		}
		if ( ! empty( $row['max_downloads'] ) && (int) $row['download_count'] >= (int) $row['max_downloads'] ) {
			return 'exhausted';
		}
		$abs = Paths::from_storage( $row['path'] );
		if ( ! file_exists( $abs ) ) {
			return 'missing';
		}
		$owner = self::owner_profile( $row );
		if ( ! $owner || ! $owner->can( 'share' ) || ! Paths::is_within( $abs, $owner->root ) ) {
			return 'orphaned';
		}
		return 'active';
	}

	/**
	 * Client representation.
	 *
	 * @param array        $row Row.
	 * @param Profile|null $p   Viewer.
	 * @return array
	 */
	public static function to_client( array $row, $p = null ) {
		$abs   = Paths::from_storage( $row['path'] );
		$owner = get_userdata( (int) $row['created_by'] );
		$token = Crypto::decrypt( $row['token_enc'] );
		$rel   = null;
		if ( $p && Paths::is_within( $abs, $p->root ) ) {
			$rel = '/' . Paths::relative( $abs, $p->root );
		}
		$date = static function ( $value ) {
			return $value ? strtotime( $value . ' UTC' ) : null;
		};
		return array(
			'id'           => (int) $row['id'],
			'url'          => $token ? self::url( $token ) : null,
			'label'        => (string) $row['label'],
			'name'         => basename( $abs ),
			'path'         => $rel,
			'location'     => $rel ? $rel : ( $p && $p->is_admin ? $abs : '' ),
			'type'         => $row['type'],
			'mode'         => $row['mode'],
			'owner'        => $owner ? $owner->display_name : '',
			'ownerId'      => (int) $row['created_by'],
			'createdAt'    => $date( $row['created_at'] ),
			'updatedAt'    => $date( $row['updated_at'] ),
			'expiresAt'    => $date( $row['expires_at'] ),
			'lastAccess'   => $date( $row['last_access_at'] ),
			'hasPassword'  => ! empty( $row['password_hash'] ),
			'emails'       => $row['emails'] ? explode( "\n", $row['emails'] ) : array(),
			'ips'          => $row['ips'] ? explode( "\n", $row['ips'] ) : array(),
			'maxDownloads' => $row['max_downloads'] ? (int) $row['max_downloads'] : null,
			'downloads'    => (int) $row['download_count'],
			'views'        => (int) $row['view_count'],
			'uploads'      => (int) $row['upload_count'],
			'status'       => self::status( $row ),
			'options'      => self::options( $row ),
			'exposed'      => Storage::is_web_exposed( $abs ),
		);
	}
}
