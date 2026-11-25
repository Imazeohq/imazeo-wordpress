<?php
/**
 * Site connection and credential storage.
 *
 * @package Imazeo
 */

namespace Imazeo\WordPress;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Manages the consent and credential for one WordPress site. */
final class Connection {
	public const OPTION = 'imazeo_connection';

	/** Get the current verified connection and decrypt its credential as needed. */
	public static function current(): ?array {
		$connection = get_option( self::OPTION );
		if ( ! is_array( $connection ) || ! Api::is_uuid( $connection['project'] ?? null ) ) {
			return null;
		}
		$key = defined( 'IMAZEO_API_KEY' ) ? self::configured_key() : self::decrypt( $connection['ciphertext'] ?? '' );
		if ( ! self::valid_key( $key ) || ! hash_equals( (string) ( $connection['fingerprint'] ?? '' ), hash( 'sha256', $key ) ) ) {
			return null;
		}
		$connection['key'] = $key;
		return $connection;
	}

	/** Recheck a job after waiting for another request, bypassing the previous option cache. */
	public static function still_connected( string $generation ): bool {
		wp_cache_delete( self::OPTION, 'options' );
		$connection = self::current();
		return $connection && $generation === $connection['generation'];
	}

	/** Validate the supplied key before replacing the current connection. */
	public static function connect( string $entered_key ): true|WP_Error {
		$key = defined( 'IMAZEO_API_KEY' ) ? self::configured_key() : trim( $entered_key );
		if ( ! self::valid_key( $key ) ) {
			return new WP_Error( 'key', __( 'Enter an Imazeo project API key.', 'imazeo' ) );
		}
		$ciphertext = '';
		if ( ! defined( 'IMAZEO_API_KEY' ) ) {
			$ciphertext = self::encrypt( $key );
			if ( '' === $ciphertext ) {
				return new WP_Error( 'encryption', __( 'Encrypted key storage requires OpenSSL and a unique AUTH_KEY in wp-config.php. Ask your administrator to configure IMAZEO_API_KEY in wp-config.php instead.', 'imazeo' ) );
			}
		}
		$data = ( new Api( $key ) )->context();
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$previous = self::current();
		$connection = array(
			'project'     => $data['projectId'],
			'fingerprint' => hash( 'sha256', $key ),
			'generation'  => $previous && $previous['project'] === $data['projectId'] && hash_equals( $previous['key'], $key )
				? $previous['generation'] : wp_generate_uuid4(),
			'ciphertext'  => $ciphertext,
		);
		if ( ! update_option( self::OPTION, $connection, false ) && get_option( self::OPTION ) !== $connection ) {
			return new WP_Error( 'storage', __( 'WordPress could not save the connection. Try again.', 'imazeo' ) );
		}
		return true;
	}

	private static function configured_key(): string {
		return is_string( IMAZEO_API_KEY ) ? trim( IMAZEO_API_KEY ) : '';
	}

	private static function valid_key( string $key ): bool {
		return 1 === preg_match( '/\Aimz_[a-f0-9-]{36}\.[A-Za-z0-9_-]{43}\z/', $key );
	}

	private static function secret(): string {
		if ( ! function_exists( 'openssl_encrypt' ) || ! defined( 'AUTH_KEY' ) || ! is_string( AUTH_KEY )
			|| strlen( AUTH_KEY ) < 32 || 'put your unique phrase here' === AUTH_KEY ) {
			return '';
		}
		return hash_hkdf( 'sha256', AUTH_KEY, 32, 'imazeo/wordpress/' . get_current_blog_id() );
	}

	private static function encrypt( string $key ): string {
		$secret = self::secret();
		if ( '' === $secret ) {
			return '';
		}
		$iv = random_bytes( 12 );
		$ciphertext = openssl_encrypt( $key, 'aes-256-gcm', $secret, OPENSSL_RAW_DATA, $iv, $tag );
		// The GCM tag authenticates the encrypted value before it is used.
		return false === $ciphertext ? '' : base64_encode( $iv . $tag . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function decrypt( string $encoded ): string {
		$secret = self::secret();
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode the encrypted option.
		if ( '' === $secret || false === $raw || strlen( $raw ) <= 28 ) {
			return '';
		}
		$key = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $secret, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return is_string( $key ) ? $key : '';
	}
}
