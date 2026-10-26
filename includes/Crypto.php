<?php
/**
 * Tokens, signatures and reversible encryption of share links.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Small crypto toolbox built on random_bytes, HMAC and libsodium (polyfilled by WordPress).
 */
final class Crypto {

	/**
	 * URL-safe random token (192 bits by default).
	 *
	 * @param int $bytes Entropy bytes.
	 * @return string
	 */
	public static function token( $bytes = 24 ) {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Lookup hash of a share token. Deliberately not salted with WordPress keys, so that
	 * rotating the salts after an incident does not break every partner link.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function token_hash( $token ) {
		return hash( 'sha256', 'filora-share|' . (string) $token );
	}

	/**
	 * HMAC signature with a site secret.
	 *
	 * @param string $data    Data.
	 * @param string $context Context separator.
	 * @return string
	 */
	public static function sign( $data, $context = 'default' ) {
		return hash_hmac( 'sha256', $context . '|' . $data, wp_salt( 'auth' ) . 'filora' );
	}

	/**
	 * Constant-time signature check.
	 *
	 * @param string $data      Data.
	 * @param string $signature Signature.
	 * @param string $context   Context.
	 * @return bool
	 */
	public static function verify( $data, $signature, $context = 'default' ) {
		return hash_equals( self::sign( $data, $context ), (string) $signature );
	}

	/**
	 * Encryption key derived from wp-config.php secrets (never stored in the database).
	 *
	 * @return string 32 bytes.
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'secure_auth' ) . '|filora-share-key', true );
	}

	/**
	 * Encrypt a token so that admins can copy a link again later.
	 *
	 * @param string $plain Plain text.
	 * @return string|null
	 */
	public static function encrypt( $plain ) {
		try {
			if ( function_exists( 'sodium_crypto_secretbox' ) ) {
				$nonce = random_bytes( 24 );
				return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore
			}
			if ( function_exists( 'openssl_encrypt' ) ) {
				$iv     = random_bytes( 12 );
				$tag    = '';
				$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
				if ( false !== $cipher ) {
					return 'o1:' . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore
				}
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
	}

	/**
	 * Decrypt (returns null when keys changed or data is corrupted).
	 *
	 * @param string|null $data Cipher text.
	 * @return string|null
	 */
	public static function decrypt( $data ) {
		if ( ! is_string( $data ) || strlen( $data ) < 4 ) {
			return null;
		}
		$raw = base64_decode( substr( $data, 3 ), true ); // phpcs:ignore
		if ( false === $raw ) {
			return null;
		}
		try {
			if ( 0 === strpos( $data, 's1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) && strlen( $raw ) > 24 ) {
				$plain = sodium_crypto_secretbox_open( substr( $raw, 24 ), substr( $raw, 0, 24 ), self::key() );
				return false === $plain ? null : $plain;
			}
			if ( 0 === strpos( $data, 'o1:' ) && function_exists( 'openssl_decrypt' ) && strlen( $raw ) > 28 ) {
				$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
				return false === $plain ? null : $plain;
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
	}

	/**
	 * Encode a signed, expiring payload (for cookies and download tickets).
	 *
	 * @param array  $payload Data.
	 * @param string $context Context.
	 * @return string
	 */
	public static function pack( array $payload, $context ) {
		$json = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		return $json . '.' . self::sign( $json, $context );
	}

	/**
	 * Decode and verify a payload produced by pack().
	 *
	 * @param string $value   Value.
	 * @param string $context Context.
	 * @return array|null
	 */
	public static function unpack( $value, $context ) {
		$parts = explode( '.', (string) $value );
		if ( 2 !== count( $parts ) || ! self::verify( $parts[0], $parts[1], $context ) ) {
			return null;
		}
		$json = base64_decode( strtr( $parts[0], '-_', '+/' ), true ); // phpcs:ignore
		$data = $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( isset( $data['exp'] ) && (int) $data['exp'] < time() ) {
			return null;
		}
		return $data;
	}
}
