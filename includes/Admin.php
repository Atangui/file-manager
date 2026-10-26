<?php
/**
 * Admin screens: menu, assets, app container.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the single-page app in wp-admin.
 */
final class Admin {

	/**
	 * Screen hook suffixes.
	 *
	 * @var string[]
	 */
	private static $hooks = array();

	/**
	 * Route for each submenu slug.
	 */
	const ROUTES = array(
		'filora'          => 'files',
		'filora-shares'   => 'shares',
		'filora-trash'    => 'trash',
		'filora-activity' => 'activity',
		'filora-tools'    => 'tools',
		'filora-settings' => 'settings',
	);

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FILORA_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'module_tag' ), 10, 2 );
	}

	/**
	 * Admin menu.
	 */
	public static function menu() {
		if ( ! Access::can_use() ) {
			return;
		}
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#a7aaad" d="M3 7.5A2.5 2.5 0 0 1 5.5 5h3.6a2 2 0 0 1 1.4.6l1.3 1.3a2 2 0 0 0 1.4.6h5.3A2.5 2.5 0 0 1 21 10v7.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z"/><path fill="#1d2327" d="M12.6 11.2a1.9 1.9 0 0 1 3.3 1.3v.5h.4a.7.7 0 0 1 .7.7v2.6a.7.7 0 0 1-.7.7h-3.6a.7.7 0 0 1-.7-.7v-2.6a.7.7 0 0 1 .7-.7h.4v-.5c0-.5.2-1 .5-1.3zm1.3.5a.8.8 0 0 0-.8.8v.5h1.6v-.5a.8.8 0 0 0-.8-.8z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$cb   = array( __CLASS__, 'render' );

		self::$hooks[] = add_menu_page( __( 'Filora — Files', 'filora' ), __( 'Files', 'filora' ), 'read', 'filora', $cb, $icon, 58 );
		self::$hooks[] = add_submenu_page( 'filora', __( 'Files', 'filora' ), __( 'All files', 'filora' ), 'read', 'filora', $cb );
		if ( Access::profile()->can( 'share' ) ) {
			self::$hooks[] = add_submenu_page( 'filora', __( 'Shared links', 'filora' ), __( 'Shared links', 'filora' ), 'read', 'filora-shares', $cb );
		}
		if ( Settings::value( 'general', 'trash', true ) && Access::profile()->can( 'delete' ) ) {
			self::$hooks[] = add_submenu_page( 'filora', __( 'Trash', 'filora' ), __( 'Trash', 'filora' ), 'read', 'filora-trash', $cb );
		}
		self::$hooks[] = add_submenu_page( 'filora', __( 'Activity', 'filora' ), __( 'Activity', 'filora' ), 'read', 'filora-activity', $cb );
		if ( Access::profile()->is_admin ) {
			self::$hooks[] = add_submenu_page( 'filora', __( 'Security tools', 'filora' ), __( 'Security tools', 'filora' ), 'read', 'filora-tools', $cb );
		}
		if ( Access::can_manage_settings() ) {
			self::$hooks[] = add_submenu_page( 'filora', __( 'Settings', 'filora' ), __( 'Settings', 'filora' ), 'read', 'filora-settings', $cb );
		}
	}

	/**
	 * Is the current screen ours?
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	private static function is_ours( $hook ) {
		return in_array( $hook, self::$hooks, true );
	}

	/**
	 * Enqueue the app on our screens only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue( $hook ) {
		if ( ! self::is_ours( $hook ) ) {
			return;
		}
		// The app replaces the screen: keep foreign notices out of the way.
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		add_filter(
			'admin_body_class',
			static function ( $classes ) {
				return $classes . ' filora-screen';
			}
		);
		add_filter( 'admin_footer_text', '__return_empty_string', 99 );
		add_filter( 'update_footer', '__return_empty_string', 99 );

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'filora'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::enqueue_app( isset( self::ROUTES[ $page ] ) ? self::ROUTES[ $page ] : 'files', false );
	}

	/**
	 * Enqueue app assets and configuration (admin or front-end).
	 *
	 * @param string $route    Initial route.
	 * @param bool   $frontend Embedded on the front-end.
	 */
	public static function enqueue_app( $route, $frontend ) {
		$css = FILORA_DIR . 'assets/dist/app.css';
		$js  = FILORA_DIR . 'assets/dist/app.js';
		wp_enqueue_style( 'filora-app', FILORA_URL . 'assets/dist/app.css', array(), is_readable( $css ) ? (string) filemtime( $css ) : FILORA_VERSION );
		wp_enqueue_script( 'filora-app', FILORA_URL . 'assets/dist/app.js', array(), is_readable( $js ) ? (string) filemtime( $js ) : FILORA_VERSION, true );
		wp_add_inline_script( 'filora-app', 'window.FiloraConfig = ' . wp_json_encode( self::config( $route, $frontend ) ) . ';', 'before' );
	}

	/**
	 * Load our bundle as an ES module (enables code-splitting).
	 *
	 * @param string $tag    Tag.
	 * @param string $handle Handle.
	 * @return string
	 */
	public static function module_tag( $tag, $handle ) {
		if ( 'filora-app' !== $handle || false !== strpos( $tag, 'type="module"' ) ) {
			return $tag;
		}
		return (string) preg_replace_callback(
			'/<script\b([^>]*?)\ssrc=/',
			static function ( $m ) {
				$attrs = preg_replace( '/\stype=([\'"])[^\'"]*\1/', '', $m[1] );
				return '<script type="module"' . $attrs . ' src=';
			},
			$tag,
			1
		);
	}

	/**
	 * Configuration passed to the app.
	 *
	 * @param string $route    Initial route.
	 * @param bool   $frontend Front-end embed.
	 * @return array
	 */
	public static function config( $route, $frontend ) {
		$profile = Access::profile();
		$user    = wp_get_current_user();
		$sharing = Settings::get( 'sharing' );
		$general = Settings::get( 'general' );
		return array(
			'rest'       => esc_url_raw( rest_url( 'filora/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'version'    => FILORA_VERSION,
			'route'      => $route,
			'frontend'   => (bool) $frontend,
			'adminUrl'   => admin_url( 'admin.php?page=filora' ),
			'user'       => array(
				'id'          => (int) $user->ID,
				'name'        => $user->display_name,
				'avatar'      => get_avatar_url( $user->ID, array( 'size' => 64 ) ),
				'canSettings' => Access::can_manage_settings(),
			),
			'profile'    => $profile ? $profile->to_client() : null,
			'error'      => $profile ? '' : Access::$last_error,
			'prefs'      => Rest\AdminApi::prefs(),
			'sharing'    => array(
				'enabled'       => (bool) $sharing['enabled'],
				'defaultDays'   => (int) $sharing['default_days'],
				'maxDays'       => (int) $sharing['max_days'],
				'forcePassword' => (bool) $sharing['force_password'],
				'allowUpload'   => (bool) $sharing['allow_upload'],
				'uploadExts'    => (string) $sharing['upload_exts'],
				'uploadMax'     => (int) $sharing['upload_max'],
				'notify'        => (string) $sharing['notify'],
			),
			'trash'      => array(
				'enabled' => (bool) $general['trash'],
				'days'    => (int) $general['trash_days'],
			),
			'versions'   => (bool) $general['versions'],
			'sudo'       => (bool) Settings::value( 'security', 'sudo', false ),
			'chunk'      => Uploads::chunk_size(),
			'zip'        => Zipper::available(),
			'codemirror' => array(
				'js'  => includes_url( 'js/codemirror/codemirror.min.js' ),
				'css' => includes_url( 'js/codemirror/codemirror.min.css' ),
			),
			'locale'     => str_replace( '_', '-', get_user_locale() ),
			'rtl'        => is_rtl(),
			'site'       => array(
				'name' => get_bloginfo( 'name' ),
				'url'  => home_url( '/' ),
			),
			'i18n'       => I18n::strings(),
		);
	}

	/**
	 * App container.
	 */
	public static function render() {
		echo '<div id="filora-app" class="flr-host"><div class="flr-boot" aria-busy="true"><span></span></div></div>';
		echo '<noscript><p>' . esc_html__( 'Filora needs JavaScript to run.', 'filora' ) . '</p></noscript>';
	}

	/**
	 * Links on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=filora' ) ) . '">' . esc_html__( 'Open', 'filora' ) . '</a>' );
		if ( Access::can_manage_settings() ) {
			$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=filora-settings' ) ) . '">' . esc_html__( 'Settings', 'filora' ) . '</a>';
		}
		return $links;
	}
}
