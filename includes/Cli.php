<?php
/**
 * WP-CLI commands: `wp filora ...`
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Filora share links, trash and checks from the command line.
 */
final class Cli {

	/**
	 * Register commands.
	 */
	public static function register() {
		\WP_CLI::add_command( 'filora', __CLASS__ );
	}

	/**
	 * Run as the first administrator (or --user) so profiles resolve.
	 *
	 * @param array $assoc Assoc args.
	 * @return Fs
	 */
	private static function fs( array $assoc ) {
		if ( ! get_current_user_id() ) {
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			);
			wp_set_current_user( isset( $assoc['user'] ) ? (int) $assoc['user'] : (int) reset( $admins ) );
		}
		$profile = Access::profile();
		if ( ! $profile ) {
			\WP_CLI::error( 'This user has no Filora access. Use --user=<id> or `wp --user=<login>`.' );
		}
		return new Fs( $profile );
	}

	/**
	 * Create a share link.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Path relative to the user's root (e.g. /wp-content/uploads/filora-vault/contract.pdf).
	 *
	 * [--mode=<mode>]
	 * : view, download, upload or dropbox.
	 * ---
	 * default: download
	 * ---
	 *
	 * [--expires=<when>]
	 * : e.g. 7d, 24h, 2w, never, or a date.
	 *
	 * [--password=<password>]
	 * : Protect with a password.
	 *
	 * [--emails=<emails>]
	 * : Comma separated allowed emails (or *@domain.com). Visitors receive a one-time code.
	 *
	 * [--max-downloads=<n>]
	 * : Download limit.
	 *
	 * [--label=<label>]
	 * : Name shown to visitors.
	 *
	 * [--user=<id>]
	 * : Create as this user.
	 *
	 * ## EXAMPLES
	 *
	 *     wp filora share /wp-content/uploads/filora-vault/brief --expires=7d --password=secret
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function share( $args, $assoc ) {
		$fs   = self::fs( $assoc );
		$data = array(
			'path'          => $args[0],
			'mode'          => isset( $assoc['mode'] ) ? $assoc['mode'] : 'download',
			'label'         => isset( $assoc['label'] ) ? $assoc['label'] : '',
			'max_downloads' => isset( $assoc['max-downloads'] ) ? (int) $assoc['max-downloads'] : 0,
			'emails'        => isset( $assoc['emails'] ) ? $assoc['emails'] : '',
		);
		if ( isset( $assoc['expires'] ) ) {
			$data['expires'] = $assoc['expires'];
		}
		if ( isset( $assoc['password'] ) ) {
			$data['password'] = $assoc['password'];
		}
		try {
			$share = Shares::create( $fs, $data );
		} catch ( Error $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( $share['url'] );
	}

	/**
	 * List share links.
	 *
	 * [--format=<format>]
	 * : table, json, csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand shares
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function shares( $args, $assoc ) {
		$fs   = self::fs( $assoc );
		$rows = array();
		foreach ( Shares::all( $fs->profile() ) as $share ) {
			$rows[] = array(
				'id'        => $share['id'],
				'name'      => $share['name'],
				'mode'      => $share['mode'],
				'status'    => $share['status'],
				'expires'   => $share['expiresAt'] ? gmdate( 'Y-m-d H:i', $share['expiresAt'] ) : 'never',
				'downloads' => $share['downloads'],
				'protected' => $share['hasPassword'] || $share['emails'] ? 'yes' : 'no',
				'url'       => $share['url'],
			);
		}
		\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'id', 'name', 'mode', 'status', 'expires', 'downloads', 'protected', 'url' ) );
	}

	/**
	 * Revoke (delete) a share link.
	 *
	 * <id>
	 * : Share ID.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function revoke( $args, $assoc ) {
		$fs = self::fs( $assoc );
		try {
			Shares::delete( (int) $args[0], $fs->profile() );
		} catch ( Error $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( 'Share link revoked.' );
	}

	/**
	 * Empty the trash.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * @subcommand empty-trash
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function empty_trash( $args, $assoc ) {
		\WP_CLI::confirm( 'Permanently delete every item in the Filora trash?', $assoc );
		$fs    = self::fs( $assoc );
		$count = 0;
		foreach ( Trash::items( $fs->profile() ) as $item ) {
			$count += Trash::purge( $item['id'] ) ? 1 : 0;
		}
		\WP_CLI::success( "{$count} item(s) deleted." );
	}

	/**
	 * Verify WordPress core files against official checksums and list PHP files in uploads.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function scan( $args, $assoc ) {
		$fs = self::fs( $assoc );
		try {
			$core = Integrity::core();
			\WP_CLI::log( sprintf( 'Core %s: %d files checked, %d modified, %d unknown.', $core['version'], $core['checked'], count( $core['modified'] ), count( $core['unknown'] ) ) );
			foreach ( $core['modified'] as $file ) {
				\WP_CLI::warning( 'Modified: ' . $file );
			}
			foreach ( $core['unknown'] as $file ) {
				\WP_CLI::warning( 'Unknown: ' . $file );
			}
		} catch ( Error $e ) {
			\WP_CLI::warning( $e->getMessage() );
		}
		foreach ( Integrity::suspicious( $fs ) as $file ) {
			\WP_CLI::warning( 'PHP in uploads: ' . $file['path'] );
		}
		\WP_CLI::success( 'Scan finished.' );
	}
}
