<?php
/**
 * Domain exception carrying a machine code and an HTTP status.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by filesystem / policy code, converted to WP_Error at the REST boundary.
 */
class Error extends \Exception {

	/**
	 * Machine readable code.
	 *
	 * @var string
	 */
	public $error_code;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Extra data for the client.
	 *
	 * @var array
	 */
	public $data;

	/**
	 * Constructor.
	 *
	 * @param string $code    Code.
	 * @param string $message Human message (translated).
	 * @param int    $status  HTTP status.
	 * @param array  $data    Extra data.
	 */
	public function __construct( $code, $message, $status = 400, array $data = array() ) {
		parent::__construct( $message );
		$this->error_code = $code;
		$this->status     = $status;
		$this->data       = $data;
	}

	/**
	 * Convert to WP_Error.
	 *
	 * @return \WP_Error
	 */
	public function to_wp_error() {
		return new \WP_Error( 'filora_' . $this->error_code, $this->getMessage(), array_merge( array( 'status' => $this->status ), $this->data ) );
	}

	/**
	 * Shorthand: not found (also used for hidden items, to not leak existence).
	 *
	 * @return Error
	 */
	public static function not_found() {
		return new self( 'not_found', __( 'This item does not exist or is not accessible.', 'filora' ), 404 );
	}

	/**
	 * Shorthand: forbidden.
	 *
	 * @param string $message Message.
	 * @return Error
	 */
	public static function forbidden( $message = '' ) {
		return new self( 'forbidden', $message ? $message : __( 'You are not allowed to do this.', 'filora' ), 403 );
	}
}
