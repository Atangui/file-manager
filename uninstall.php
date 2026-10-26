<?php
/**
 * Uninstall: data is only removed when "Delete all data on uninstall" is enabled
 * (or FILORA_REMOVE_ALL_DATA is true). Files in the Vault are never deleted.
 *
 * @package Filora
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove Filora data for the current site.
 */
function filora_uninstall_site() {
	global $wpdb;
	$settings = get_option( 'filora_settings' );
	$cleanup  = ( defined( 'FILORA_REMOVE_ALL_DATA' ) && FILORA_REMOVE_ALL_DATA ) || ( is_array( $settings ) && ! empty( $settings['general']['cleanup'] ) );
	wp_clear_scheduled_hook( 'filora_daily_maintenance' );
	if ( ! $cleanup ) {
		return;
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}filora_shares" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}filora_log" );
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('filora_prefs','filora_sudo')" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_filora\_%' OR option_name LIKE '\_transient\_timeout\_filora\_%'" );
	// phpcs:enable

	$suffix = get_option( 'filora_private_suffix' );
	if ( is_string( $suffix ) && preg_match( '/^[a-f0-9]{16,64}$/', $suffix ) ) {
		$uploads = wp_upload_dir( null, false );
		$dir     = rtrim( $uploads['basedir'], '/\\' ) . '/filora-private-' . $suffix;
		if ( is_dir( $dir ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $item ) {
				$item->isDir() && ! $item->isLink() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); // phpcs:ignore
			}
			@rmdir( $dir ); // phpcs:ignore
		}
	}
	foreach ( array( 'filora_settings', 'filora_db_version', 'filora_private_suffix', 'filora_protection_test', 'filora_flush_rewrites' ) as $option ) {
		delete_option( $option );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 1000 ) ) as $filora_site ) {
		switch_to_blog( $filora_site );
		filora_uninstall_site();
		restore_current_blog();
	}
} else {
	filora_uninstall_site();
}
