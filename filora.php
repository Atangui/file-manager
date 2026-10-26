<?php
/**
 * Plugin Name:       Filora — File Manager & Secure Sharing
 * Plugin URI:        https://github.com/atangui/file-manager
 * Description:       A modern, fast and secure file manager for WordPress, with Drive-like private share links for clients and partners.
 * Version:           1.0.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Filora contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       filora
 * Domain Path:       /languages
 *
 * @package Filora
 */

defined( 'ABSPATH' ) || exit;

define( 'FILORA_VERSION', '1.0.2' );
define( 'FILORA_DB_VERSION', 1 );
define( 'FILORA_FILE', __FILE__ );
define( 'FILORA_DIR', plugin_dir_path( __FILE__ ) );
define( 'FILORA_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Filora requires PHP 7.4 or newer. Please ask your host to upgrade PHP.', 'filora' );
			echo '</p></div>';
		}
	);
	return;
}

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'Filora\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'Filora\\' ) );
		$file     = FILORA_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'Filora\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Filora\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Filora\\Plugin', 'instance' ) );
