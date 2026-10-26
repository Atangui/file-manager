<?php
/**
 * Share links management REST endpoints.
 *
 * @package Filora
 */

namespace Filora\Rest;

use Filora\Log;
use Filora\Paths;
use Filora\Shares;

defined( 'ABSPATH' ) || exit;

/**
 * /filora/v1/shares
 */
final class SharesApi extends Base {

	/**
	 * Register routes.
	 */
	public function register() {
		$this->route( '/shares', 'GET', array( $this, 'index' ) );
		$this->route( '/shares', 'POST', array( $this, 'create' ) );
		$this->route( '/shares/(?P<id>\d+)', 'POST, PUT, PATCH', array( $this, 'update' ) );
		$this->route( '/shares/(?P<id>\d+)', 'DELETE', array( $this, 'delete' ) );
		$this->route( '/shares/(?P<id>\d+)/regenerate', 'POST', array( $this, 'regenerate' ) );
		$this->route( '/shares/(?P<id>\d+)/activity', 'GET', array( $this, 'activity' ) );
	}

	/**
	 * GET /shares[?path=]
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function index( \WP_REST_Request $r ) {
		$fs   = $this->fs();
		$args = array();
		$path = $this->str( $r, 'path' );
		if ( '' !== $path ) {
			$args['path'] = $fs->abs( $path );
		}
		return Shares::all( $fs->profile(), $args );
	}

	/**
	 * POST /shares
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function create( \WP_REST_Request $r ) {
		$this->sudo();
		return Shares::create( $this->fs(), $this->input( $r ) );
	}

	/**
	 * POST /shares/{id}
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function update( \WP_REST_Request $r ) {
		$this->sudo();
		return Shares::update( (int) $r['id'], $this->input( $r ), $this->fs()->profile() );
	}

	/**
	 * DELETE /shares/{id}
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function delete( \WP_REST_Request $r ) {
		$this->sudo();
		Shares::delete( (int) $r['id'], $this->fs()->profile() );
		return array( 'deleted' => true );
	}

	/**
	 * POST /shares/{id}/regenerate
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function regenerate( \WP_REST_Request $r ) {
		$this->sudo();
		return Shares::regenerate( (int) $r['id'], $this->fs()->profile() );
	}

	/**
	 * GET /shares/{id}/activity
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public function activity( \WP_REST_Request $r ) {
		$row = Shares::editable( (int) $r['id'], $this->fs()->profile() );
		$log = Log::query(
			array(
				'share'    => (int) $row['id'],
				'page'     => (int) $r->get_param( 'page' ),
				'per_page' => 100,
			)
		);
		foreach ( $log['items'] as &$item ) {
			$item['path'] = basename( Paths::from_storage( $item['path'] ) );
		}
		return $log;
	}

	/**
	 * Whitelisted input fields.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	private function input( \WP_REST_Request $r ) {
		$params = $r->get_json_params();
		if ( ! is_array( $params ) || ! $params ) {
			$params = $r->get_body_params();
		}
		$keys = array( 'path', 'label', 'mode', 'expires', 'password', 'emails', 'ips', 'max_downloads', 'options', 'status', 'revoke_sessions', 'reset_counters' );
		return array_intersect_key( (array) $params, array_flip( $keys ) );
	}
}
