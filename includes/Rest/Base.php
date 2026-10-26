<?php
/**
 * Shared REST plumbing.
 *
 * @package Filora
 */

namespace Filora\Rest;

use Filora\Access;
use Filora\Error;
use Filora\Fs;

defined( 'ABSPATH' ) || exit;

/**
 * Base controller: permission checks, error conversion, sudo mode.
 */
abstract class Base {

	const NS = 'filora/v1';

	/**
	 * Register one route.
	 *
	 * @param string   $path    Route.
	 * @param string   $methods Methods.
	 * @param callable $handler Handler( WP_REST_Request ).
	 * @param string   $perm    use|settings.
	 */
	protected function route( $path, $methods, $handler, $perm = 'use' ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => function ( \WP_REST_Request $request ) use ( $handler ) {
					try {
						$result = call_user_func( $handler, $request );
						return rest_ensure_response( $result );
					} catch ( Error $e ) {
						return $e->to_wp_error();
					} catch ( \Throwable $t ) {
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( 'Filora: ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
						return new \WP_Error( 'filora_internal', __( 'Unexpected server error.', 'filora' ), array( 'status' => 500 ) );
					}
				},
				'permission_callback' => function () use ( $perm ) {
					if ( 'settings' === $perm ) {
						return Access::can_manage_settings();
					}
					if ( ! Access::can_use() ) {
						return new \WP_Error( 'filora_no_access', Access::$last_error ? Access::$last_error : __( 'You do not have access to the file manager.', 'filora' ), array( 'status' => is_user_logged_in() ? 403 : 401 ) );
					}
					return true;
				},
			)
		);
	}

	/**
	 * Filesystem for the current user.
	 *
	 * @return Fs
	 */
	protected function fs() {
		return new Fs( Access::profile() );
	}

	/**
	 * Require recent password confirmation for sensitive operations.
	 *
	 * @throws Error When sudo is required.
	 */
	protected function sudo() {
		if ( Access::sudo_required() ) {
			throw new Error( 'sudo_required', __( 'Please confirm your password to continue.', 'filora' ), 403 );
		}
	}

	/**
	 * Read a list of paths.
	 *
	 * @param \WP_REST_Request $r   Request.
	 * @param string           $key Key.
	 * @return string[]
	 */
	protected function paths( \WP_REST_Request $r, $key = 'paths' ) {
		$paths = $r->get_param( $key );
		if ( ! is_array( $paths ) ) {
			$paths = null === $paths || '' === $paths ? array() : array( $paths );
		}
		$paths = array_values( array_filter( array_map( 'strval', $paths ), 'strlen' ) );
		if ( count( $paths ) > 5000 ) {
			throw new Error( 'too_many', __( 'Too many items selected.', 'filora' ) );
		}
		return $paths;
	}

	/**
	 * String param.
	 *
	 * @param \WP_REST_Request $r       Request.
	 * @param string           $key     Key.
	 * @param string           $default Default.
	 * @return string
	 */
	protected function str( \WP_REST_Request $r, $key, $default = '' ) {
		$value = $r->get_param( $key );
		return is_scalar( $value ) ? (string) $value : $default;
	}
}
