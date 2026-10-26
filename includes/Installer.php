<?php
/**
 * Activation, upgrades and database schema.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Installs tables, defaults, cron and rewrite rules.
 */
final class Installer {

	const CRON_HOOK   = 'filora_daily_maintenance';
	const FLUSH_FLAG  = 'filora_flush_rewrites';
	const DB_OPTION   = 'filora_db_version';

	/**
	 * Activation hook (handles network activation too).
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites( array( 'fields' => 'ids', 'number' => 500 ) );
			foreach ( $sites as $site_id ) {
				switch_to_blog( $site_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}
		self::install_site();
	}

	/**
	 * Deactivation: stop cron, drop our rewrite rules. Data is kept.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		flush_rewrite_rules( false );
	}

	/**
	 * Run upgrades when the stored schema is older than the code.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::DB_OPTION, 0 ) < FILORA_DB_VERSION ) {
			self::install_site();
		}
	}

	/**
	 * Flush rewrites once after our rules are registered (activation or slug change).
	 */
	public static function maybe_flush_rewrites() {
		if ( get_option( self::FLUSH_FLAG ) ) {
			delete_option( self::FLUSH_FLAG );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Install for the current site.
	 */
	public static function install_site() {
		self::create_tables();
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		Storage::ensure();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		update_option( self::FLUSH_FLAG, 1, false );
		update_option( self::DB_OPTION, FILORA_DB_VERSION, false );
	}

	/**
	 * Table names.
	 *
	 * @param string $name shares|log.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'filora_' . $name;
	}

	/**
	 * Create or update tables with dbDelta.
	 */
	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$shares  = self::table( 'shares' );
		$log     = self::table( 'log' );

		dbDelta(
			"CREATE TABLE {$shares} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL,
  token_enc text NULL,
  path text NOT NULL,
  type varchar(8) NOT NULL DEFAULT 'file',
  label varchar(191) NOT NULL DEFAULT '',
  mode varchar(16) NOT NULL DEFAULT 'download',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  expires_at datetime NULL,
  password_hash varchar(255) NULL,
  emails text NULL,
  ips text NULL,
  max_downloads int(10) unsigned NULL,
  download_count int(10) unsigned NOT NULL DEFAULT 0,
  view_count int(10) unsigned NOT NULL DEFAULT 0,
  upload_count int(10) unsigned NOT NULL DEFAULT 0,
  last_access_at datetime NULL,
  auth_version int(10) unsigned NOT NULL DEFAULT 1,
  status varchar(16) NOT NULL DEFAULT 'active',
  options longtext NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY created_by (created_by),
  KEY status (status)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  share_id bigint(20) unsigned NULL,
  action varchar(40) NOT NULL DEFAULT '',
  path text NULL,
  details longtext NULL,
  ip varchar(64) NOT NULL DEFAULT '',
  ua varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY user_id (user_id),
  KEY share_id (share_id),
  KEY action (action)
) {$charset};"
		);
	}
}
