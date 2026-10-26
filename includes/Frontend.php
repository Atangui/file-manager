<?php
/**
 * [filora] shortcode: the same app on the front-end (client portals, intranets).
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end embed. The shortcode deliberately accepts no path attribute: authors and
 * contributors can write shortcodes, so the root always comes from the viewer's role profile.
 */
final class Frontend {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( 'filora', array( __CLASS__, 'shortcode' ) );
		add_filter( 'script_loader_tag', array( Admin::class, 'module_tag' ), 10, 2 );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'height' => '70vh',
			),
			is_array( $atts ) ? $atts : array(),
			'filora'
		);
		if ( ! Settings::value( 'general', 'frontend', false ) ) {
			return current_user_can( 'manage_options' ) ? '<p><em>' . esc_html__( 'Filora: enable the front-end file manager in Settings → General.', 'filora' ) . '</em></p>' : '';
		}
		if ( ! is_user_logged_in() ) {
			return '<p class="filora-login">' . sprintf(
				/* translators: %s: login link */
				esc_html__( 'Please %s to access your files.', 'filora' ),
				'<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'log in', 'filora' ) . '</a>'
			) . '</p>';
		}
		if ( ! Access::can_use() ) {
			return '<p class="filora-denied">' . esc_html__( 'You do not have access to the file manager.', 'filora' ) . '</p>';
		}
		$height = preg_match( '/^\d{2,4}(px|vh|rem|em)$/', (string) $atts['height'] ) ? $atts['height'] : '70vh';
		Admin::enqueue_app( 'files', true );
		return '<div id="filora-app" class="flr-host flr-host--embed" style="height:' . esc_attr( $height ) . '"><div class="flr-boot" aria-busy="true"><span></span></div></div>';
	}
}
