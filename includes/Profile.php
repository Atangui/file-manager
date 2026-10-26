<?php
/**
 * Effective permissions of one user.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Value object built by Access::profile().
 */
final class Profile {

	const WRITE_OPS = array( 'upload', 'replace', 'mkdir', 'mkfile', 'rename', 'move', 'copy', 'delete', 'edit', 'archive', 'extract', 'chmod', 'image' );

	/**
	 * Role key used ("admin" for full administrators).
	 *
	 * @var string
	 */
	public $key = '';

	/**
	 * User ID.
	 *
	 * @var int
	 */
	public $user_id = 0;

	/**
	 * Full administrator (no extension restrictions).
	 *
	 * @var bool
	 */
	public $is_admin = false;

	/**
	 * Real root path, normalized, without trailing slash.
	 *
	 * @var string
	 */
	public $root = '';

	/**
	 * Allowed operations.
	 *
	 * @var string[]
	 */
	public $ops = array();

	/**
	 * Hidden patterns (invisible and inaccessible).
	 *
	 * @var string[]
	 */
	public $hidden = array();

	/**
	 * Locked patterns (visible, read-only).
	 *
	 * @var string[]
	 */
	public $locked = array();

	/**
	 * Sensitive patterns.
	 *
	 * @var string[]
	 */
	public $sensitive = array();

	/**
	 * What happens with sensitive files: hidden | readonly | full.
	 *
	 * @var string
	 */
	public $sensitive_mode = 'hidden';

	/**
	 * Allowed upload extensions ("*" or comma list).
	 *
	 * @var string
	 */
	public $upload_exts = '';

	/**
	 * Max upload size in bytes (0 = unlimited).
	 *
	 * @var int
	 */
	public $max_upload = 0;

	/**
	 * Storage quota in bytes for the root (0 = unlimited).
	 *
	 * @var int
	 */
	public $quota = 0;

	/**
	 * Global read-only mode.
	 *
	 * @var bool
	 */
	public $read_only = false;

	/**
	 * Why the profile is read-only (for the UI).
	 *
	 * @var string
	 */
	public $read_only_reason = '';

	/**
	 * PHP files cannot be created/edited (DISALLOW_FILE_EDIT).
	 *
	 * @var bool
	 */
	public $php_locked = false;

	/**
	 * Can the profile perform this operation?
	 *
	 * @param string $op Operation.
	 * @return bool
	 */
	public function can( $op ) {
		if ( $this->read_only && in_array( $op, self::WRITE_OPS, true ) ) {
			return false;
		}
		if ( 'share' === $op && ! Settings::value( 'sharing', 'enabled', true ) ) {
			return false;
		}
		return in_array( $op, $this->ops, true );
	}

	/**
	 * Throw unless the operation is allowed.
	 *
	 * @param string $op Operation.
	 * @throws Error When not allowed.
	 */
	public function require_op( $op ) {
		if ( ! $this->can( $op ) ) {
			if ( $this->read_only && in_array( $op, self::WRITE_OPS, true ) ) {
				throw new Error( 'read_only', $this->read_only_reason ? $this->read_only_reason : __( 'The file manager is in read-only mode.', 'filora' ), 403 );
			}
			throw Error::forbidden();
		}
	}

	/**
	 * Upload extension list.
	 *
	 * @return string[]
	 */
	public function upload_exts_list() {
		if ( '*' === $this->upload_exts ) {
			return array();
		}
		return array_values( array_filter( explode( ',', (string) $this->upload_exts ) ) );
	}

	/**
	 * Data exposed to the browser.
	 *
	 * @return array
	 */
	public function to_client() {
		$ops = array();
		foreach ( Settings::OPS as $op ) {
			$ops[ $op ] = $this->can( $op );
		}
		return array(
			'key'          => $this->key,
			'isAdmin'      => $this->is_admin,
			'ops'          => $ops,
			'readOnly'     => $this->read_only,
			'readOnlyWhy'  => $this->read_only_reason,
			'phpLocked'    => $this->php_locked,
			'uploadExts'   => $this->is_admin ? '*' : $this->upload_exts,
			'maxUpload'    => $this->max_upload,
			'quota'        => $this->quota,
			'rootName'     => $this->root_name(),
			'rootAbsolute' => $this->is_admin ? $this->root : '',
			'vault'        => $this->vault_rel(),
		);
	}

	/**
	 * Path of the Vault relative to this profile's root ("" when it is out of reach).
	 *
	 * @return string
	 */
	private function vault_rel() {
		$vault = Paths::real( Storage::vault() );
		if ( ! $vault || $vault === $this->root || ! Paths::is_within( $vault, $this->root ) ) {
			return '';
		}
		return '/' . Paths::relative( $vault, $this->root );
	}

	/**
	 * Friendly root label.
	 *
	 * @return string
	 */
	public function root_name() {
		if ( Paths::abspath() === $this->root ) {
			return __( 'Site root', 'filora' );
		}
		$name = basename( $this->root );
		return '' !== $name ? $name : '/';
	}
}
