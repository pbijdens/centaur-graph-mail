<?php
namespace Centaur\GraphMail;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts secrets (client secret, tokens) at rest in wp_options, keyed on the site's wp-config salts.
 */
final class Crypto {
	private const PREFIX = 'v1:';
	private const CIPHER = 'aes-256-gcm';

	private static function key(): string {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		return hash_hmac( 'sha256', 'centaur-graph-mail', $material, true );
	}

	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return self::PREFIX . base64_encode( $iv . $tag . $cipher );
	}

	/** Returns '' when the value is empty, malformed, or was encrypted with different salts. */
	public static function decrypt( string $stored ): string {
		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? '' : $plain;
	}
}
