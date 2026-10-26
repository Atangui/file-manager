<?php
/**
 * Activity log, settings, tools, sudo and user preferences.
 *
 * @package Filora
 */

namespace Filora\Rest;

use Filora\Access;
use Filora\Error;
use Filora\Integrity;
use Filora\Log;
use Filora\Paths;
use Filora\RateLimit;
use Filora\Settings;
use Filora\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Misc endpoints.
 */
final class AdminApi extends Base {

	const PREFS_META = 'filora_prefs';

	/**
	 * Register routes.
	 */
	public function register() {
		$this->route( '/activity', 'GET', array( $this, 'activity' ) );
		$this->route( '/activity/export', 'GET', array( $this, 'export' ) );
		$this->route( '/settings', 'GET', array( $this, 'get_settings' ), 'settings' );
		$this->route( '/settings', 'POST', array( $this, 'save_settings' ), 'settings' );
		$this->route( '/tools/server', 'GET', array( $this, 'server' ) );
		$this->route( '/tools/selftest', 'POST', array( $this, 'selftest' ) );
		$this->route( '/tools/integrity', 'GET', array( $this, 'integrity' ) );
		$this->route( '/tools/recent', 'GET', array( $this, 'recent' ) );
		$this->route( '/tools/suspicious', 'GET', array( $this, 'suspicious' ) );
		$this->route( '/tools/usage', 'GET', array( $this, 'usage' ) );
		$this->route( '/sudo', 'POST', array( $this, 'sudo_confirm' ) );
		$this->route( '/prefs', 'GET', array( $this, 'get_prefs' ) );
		$this->route( '/prefs', 'POST', array( $this, 'save_prefs' ) );
	}

	/**
	 * Throw unless the user is a full admin.
	 *
	 * @throws Error When not admin.
	 */
	private function admin_only() {
		if ( ! Access::profile() || ! Access::profile()->is_admin ) {
			throw Error::forbidden();
		}
	}

	/**
	 * Query args for the log (non-admins only see their own actions).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	private function log_args( \WP_REST_Request $r ) {
		$p    = Access::profile();
		$args = array(
			'action'   => $this->str( $r, 'action' ),
			'search'   => $this->str( $r, 'search' ),
			'share'    => (int) $r->get_param( 'share' ),
			'from'     => (int) $r->get_param( 'from' ),
			'to'       => (int) $r->get_param( 'to' ),
			'page'     => (int) $r->get_param( 'page' ),
			'per_page' => (int) $r->get_param( 'per_page' ),
			'user'     => (int) $r->get_param( 'user' ),
		);
		if ( ! $p->is_admin ) {
			$args['user'] = $p->user_id;
			$args['root'] = $p->root;
		}
		return $args;
	}

	/**
	 * GET /activity
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function activity( \WP_REST_Request $r ) {
		$log = Log::query( $this->log_args( $r ) );
		$p   = Access::profile();
		foreach ( $log['items'] as &$item ) {
			$abs          = Paths::from_storage( $item['path'] );
			$item['path'] = '' === $item['path'] ? '' : ( Paths::is_within( $abs, $p->root ) ? '/' . Paths::relative( $abs, $p->root ) : ( $p->is_admin ? $abs : basename( $abs ) ) );
			if ( ! $p->is_admin ) {
				unset( $item['ip'], $item['ua'] );
			}
		}
		$users = array();
		if ( $p->is_admin ) {
			global $wpdb;
			$table = \Filora\Installer::table( 'log' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table} WHERE user_id > 0 LIMIT 200" );
			foreach ( (array) $ids as $id ) {
				$u = get_userdata( (int) $id );
				if ( $u ) {
					$users[] = array(
						'id'   => (int) $id,
						'name' => $u->display_name,
					);
				}
			}
		}
		$log['users'] = $users;
		return $log;
	}

	/**
	 * GET /activity/export — CSV.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function export( \WP_REST_Request $r ) {
		$args             = $this->log_args( $r );
		$args['per_page'] = 500;
		$p                = Access::profile();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="filora-activity-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, array( 'date_utc', 'user', 'action', 'path', 'share_id', 'ip', 'details' ) );
		for ( $page = 1; $page <= 200; $page++ ) {
			$args['page'] = $page;
			$log          = Log::query( $args );
			foreach ( $log['items'] as $item ) {
				$row = array( gmdate( 'Y-m-d H:i:s', $item['time'] ), $item['user'], $item['action'], $item['path'], $item['shareId'], $p->is_admin ? $item['ip'] : '', $item['details'] ? wp_json_encode( $item['details'] ) : '' );
				// Neutralize spreadsheet formulas (CSV injection).
				foreach ( $row as &$cell ) {
					$cell = (string) $cell;
					if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
						$cell = "'" . $cell;
					}
				}
				fputcsv( $out, $row );
			}
			if ( count( $log['items'] ) < $args['per_page'] ) {
				break;
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * GET /settings
	 *
	 * @return array
	 */
	public function get_settings() {
		$roles = array();
		foreach ( wp_roles()->get_names() as $key => $name ) {
			if ( 'administrator' === $key && ! is_multisite() ) {
				continue;
			}
			$roles[] = array(
				'key'  => $key,
				'name' => translate_user_role( $name ),
			);
		}
		$admins = array();
		foreach ( get_users(
			array(
				'role__in' => array( 'administrator' ),
				'number'   => 200,
				'fields'   => array( 'ID', 'display_name', 'user_email' ),
			)
		) as $u ) {
			$admins[] = array(
				'id'    => (int) $u->ID,
				'name'  => $u->display_name,
				'email' => $u->user_email,
			);
		}
		return array(
			'settings'     => Settings::get(),
			'roles'        => $roles,
			'admins'       => $admins,
			'ops'          => Settings::OPS,
			'roleDefaults' => Settings::role_defaults(),
			'sensitive'    => Settings::default_sensitive_patterns(),
			'paths'        => array(
				'abspath' => Paths::abspath(),
				'uploads' => wp_upload_dir( null, false )['basedir'],
				'content' => WP_CONTENT_DIR,
				'vault'   => Storage::vault(),
			),
			'multisite'    => is_multisite(),
			'permalinks'   => (bool) get_option( 'permalink_structure' ),
			'home'         => home_url( '/' ),
		);
	}

	/**
	 * POST /settings
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function save_settings( \WP_REST_Request $r ) {
		$this->sudo();
		$input = $r->get_json_params();
		if ( ! is_array( $input ) ) {
			throw new Error( 'invalid', __( 'Invalid settings.', 'filora' ) );
		}
		// Never lock yourself out with the admin allow-list.
		if ( isset( $input['general']['admin_users'] ) && is_array( $input['general']['admin_users'] ) && $input['general']['admin_users'] && ! is_multisite() ) {
			$ids = array_map( 'intval', $input['general']['admin_users'] );
			if ( ! in_array( get_current_user_id(), $ids, true ) ) {
				$input['general']['admin_users'][] = get_current_user_id();
			}
		}
		Settings::update( $input );
		Access::flush();
		Log::add( 'settings.update', '', array( 'sections' => array_keys( $input ) ) );
		return $this->get_settings();
	}

	/**
	 * GET /tools/server
	 *
	 * @return array
	 */
	public function server() {
		$this->admin_only();
		return Integrity::server();
	}

	/**
	 * POST /tools/selftest
	 *
	 * @return array
	 */
	public function selftest() {
		$this->admin_only();
		return Storage::self_test( true );
	}

	/**
	 * GET /tools/integrity
	 *
	 * @return array
	 */
	public function integrity() {
		$this->admin_only();
		return Integrity::core();
	}

	/**
	 * GET /tools/recent
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function recent( \WP_REST_Request $r ) {
		$this->admin_only();
		return Integrity::recent( $this->fs(), (int) $r->get_param( 'days' ) );
	}

	/**
	 * GET /tools/suspicious
	 *
	 * @return array
	 */
	public function suspicious() {
		$this->admin_only();
		return Integrity::suspicious( $this->fs() );
	}

	/**
	 * GET /tools/usage — size of each child of a folder.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function usage( \WP_REST_Request $r ) {
		$fs    = $this->fs();
		$dir   = $fs->dir( $this->str( $r, 'path', '/' ) );
		$items = array();
		$start = microtime( true );
		$total = 0;
		foreach ( (array) @scandir( $dir ) as $name ) { // phpcs:ignore
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$abs = $dir . '/' . $name;
			if ( is_link( $abs ) || 'hidden' === $fs->visibility( $abs ) ) {
				continue;
			}
			$size    = is_dir( $abs ) ? $fs->dir_stats( $abs )['size'] : (int) @filesize( $abs ); // phpcs:ignore
			$total  += $size;
			$items[] = array(
				'name' => $name,
				'path' => $fs->rel( $abs ),
				'type' => is_dir( $abs ) ? 'dir' : 'file',
				'size' => $size,
			);
			if ( microtime( true ) - $start > 20 ) {
				break;
			}
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return $b['size'] <=> $a['size'];
			}
		);
		$p = $fs->profile();
		return array(
			'path'  => $fs->rel( $dir ),
			'total' => $total,
			'items' => array_slice( $items, 0, 200 ),
			'quota' => $p->quota,
			'used'  => $p->quota ? $fs->used_space() : null,
		);
	}

	/**
	 * POST /sudo — confirm password.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function sudo_confirm( \WP_REST_Request $r ) {
		$uid = (string) get_current_user_id();
		if ( RateLimit::exceeded( 'sudo', $uid, 6 ) ) {
			throw new Error( 'rate_limited', __( 'Too many attempts. Please wait 15 minutes and try again.', 'filora' ), 429 );
		}
		if ( ! Access::grant_sudo( $this->str( $r, 'password' ) ) ) {
			RateLimit::hit( 'sudo', $uid, 15 * MINUTE_IN_SECONDS );
			Log::add( 'sudo.failed' );
			throw new Error( 'wrong_password', __( 'Wrong password.', 'filora' ), 403 );
		}
		RateLimit::reset( 'sudo', $uid );
		Log::add( 'sudo' );
		return array( 'ok' => true );
	}

	/**
	 * Default preferences.
	 *
	 * @return array
	 */
	public static function default_prefs() {
		return array(
			'theme'      => 'auto',
			'view'       => 'list',
			'sort'       => 'name',
			'dir'        => 'asc',
			'density'    => 'comfortable',
			'showHidden' => true,
			'details'    => false,
			'favorites'  => array(),
			'recent'     => array(),
		);
	}

	/**
	 * Current user's preferences.
	 *
	 * @return array
	 */
	public static function prefs() {
		$stored = get_user_meta( get_current_user_id(), self::PREFS_META, true );
		return array_merge( self::default_prefs(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * GET /prefs
	 *
	 * @return array
	 */
	public function get_prefs() {
		return self::prefs();
	}

	/**
	 * POST /prefs (partial update).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function save_prefs( \WP_REST_Request $r ) {
		$in    = (array) $r->get_json_params();
		$prefs = self::prefs();
		$enums = array(
			'theme'   => array( 'auto', 'light', 'dark' ),
			'view'    => array( 'list', 'grid' ),
			'sort'    => array( 'name', 'size', 'mtime', 'type' ),
			'dir'     => array( 'asc', 'desc' ),
			'density' => array( 'comfortable', 'compact' ),
		);
		foreach ( $enums as $key => $allowed ) {
			if ( isset( $in[ $key ] ) && in_array( $in[ $key ], $allowed, true ) ) {
				$prefs[ $key ] = $in[ $key ];
			}
		}
		foreach ( array( 'showHidden', 'details' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$prefs[ $key ] = (bool) $in[ $key ];
			}
		}
		foreach ( array( 'favorites' => 50, 'recent' => 15 ) as $key => $max ) {
			if ( isset( $in[ $key ] ) && is_array( $in[ $key ] ) ) {
				$list = array();
				foreach ( $in[ $key ] as $path ) {
					$path = '/' . ltrim( wp_strip_all_tags( (string) $path ), '/' );
					if ( strlen( $path ) < 2048 && ! in_array( $path, $list, true ) ) {
						$list[] = $path;
					}
				}
				$prefs[ $key ] = array_slice( $list, 0, $max );
			}
		}
		update_user_meta( get_current_user_id(), self::PREFS_META, $prefs );
		return $prefs;
	}
}
