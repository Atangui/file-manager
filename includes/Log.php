<?php
/**
 * Activity log (audit trail) for file operations and share access.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Writes and queries the {prefix}filora_log table.
 */
final class Log {

	/**
	 * Record an event.
	 *
	 * @param string   $action   Action key, e.g. "upload", "share.download".
	 * @param string   $path     Path (relative to ABSPATH when possible).
	 * @param array    $details  Extra details.
	 * @param int|null $share_id Share ID.
	 * @param int|null $user_id  User (current by default).
	 */
	public static function add( $action, $path = '', array $details = array(), $share_id = null, $user_id = null ) {
		if ( ! Settings::value( 'security', 'log', true ) ) {
			return;
		}
		global $wpdb;
		$path = '' !== $path && null !== $path ? Paths::to_storage( $path ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			Installer::table( 'log' ),
			array(
				'created_at' => current_time( 'mysql', true ),
				'user_id'    => null === $user_id ? get_current_user_id() : (int) $user_id,
				'share_id'   => $share_id ? (int) $share_id : null,
				'action'     => substr( (string) $action, 0, 40 ),
				'path'       => $path,
				'details'    => $details ? wp_json_encode( $details ) : null,
				'ip'         => Http::ip_for_log(),
				'ua'         => Http::ua(),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		do_action( 'filora_logged', $action, $path, $details, $share_id );
	}

	/**
	 * Query entries.
	 *
	 * @param array $args user, action, share, search, from, to, page, per_page, root (limit to paths inside).
	 * @return array{items:array,total:int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table  = Installer::table( 'log' );
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['user'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user'];
		}
		if ( ! empty( $args['share'] ) ) {
			$where[]  = 'share_id = %d';
			$params[] = (int) $args['share'];
		}
		if ( ! empty( $args['action'] ) ) {
			$action   = (string) $args['action'];
			$where[]  = 'action LIKE %s';
			$params[] = '.' === substr( $action, -1 ) ? $wpdb->esc_like( $action ) . '%' : $action;
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'path LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', (int) $args['from'] );
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', (int) $args['to'] );
		}
		if ( isset( $args['root'] ) && null !== $args['root'] ) {
			$root = Paths::to_storage( $args['root'] );
			if ( '' !== $root ) {
				$where[]  = '(path = %s OR path LIKE %s)';
				$params[] = $root;
				$params[] = $wpdb->esc_like( $root . '/' ) . '%';
			}
		}
		$per_page = min( 500, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 50 ) ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$sql_w    = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_w}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$list_sql  = "SELECT * FROM {$table} WHERE {$sql_w} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		$users = array();
		$items = array();
		foreach ( (array) $rows as $row ) {
			$uid = (int) $row['user_id'];
			if ( $uid && ! isset( $users[ $uid ] ) ) {
				$u             = get_userdata( $uid );
				$users[ $uid ] = $u ? $u->display_name : '#' . $uid;
			}
			$items[] = array(
				'id'      => (int) $row['id'],
				'time'    => strtotime( $row['created_at'] . ' UTC' ),
				'user'    => $uid ? $users[ $uid ] : '',
				'userId'  => $uid,
				'shareId' => $row['share_id'] ? (int) $row['share_id'] : null,
				'action'  => $row['action'],
				'path'    => (string) $row['path'],
				'details' => $row['details'] ? json_decode( $row['details'], true ) : null,
				'ip'      => $row['ip'],
				'ua'      => $row['ua'],
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Delete entries older than N days.
	 *
	 * @param int $days Days (0 = keep forever).
	 */
	public static function purge_older( $days ) {
		if ( $days <= 0 ) {
			return;
		}
		global $wpdb;
		$table = Installer::table( 'log' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}
}
