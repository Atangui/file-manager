<?php
/**
 * REST routes registration.
 *
 * @package Filora
 */

namespace Filora\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every controller.
 */
final class Routes {

	/**
	 * Hooked on rest_api_init.
	 */
	public static function register() {
		( new Files() )->register();
		( new SharesApi() )->register();
		( new AdminApi() )->register();
	}
}
