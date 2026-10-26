<?php
/**
 * Main plugin orchestrator.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component to WordPress.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boot the plugin once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		Installer::maybe_upgrade();

		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( ShareRouter::class, 'register_rewrite' ) );
		add_action( 'init', array( Installer::class, 'maybe_flush_rewrites' ), 99 );
		add_filter( 'query_vars', array( ShareRouter::class, 'query_vars' ) );
		// Handled before the main query runs: faster, and no theme code is involved.
		add_action( 'parse_request', array( ShareRouter::class, 'maybe_handle' ), 0 );

		add_action( 'rest_api_init', array( Rest\Routes::class, 'register' ) );

		add_action( Installer::CRON_HOOK, array( Maintenance::class, 'daily' ) );

		if ( is_admin() ) {
			Admin::init();
		}
		Frontend::init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli::register();
		}
	}

	/**
	 * Load translations (bundled fr_FR and any wp.org language pack).
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'filora', false, dirname( plugin_basename( FILORA_FILE ) ) . '/languages' );
	}
}
