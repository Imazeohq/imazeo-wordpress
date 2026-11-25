<?php
/**
 * Imazeo API transport.
 *
 * @package Imazeo
 */

namespace Imazeo\WordPress;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** WordPress HTTP transport for the project-key endpoints used by the plugin. */
final class Api {
	private const BASE = 'https://api.imazeo.com/v1';
	public const KIND = 'image.transform';
	public const MAX_BYTES = 5242880;
	public const MAX_RESULT = 16777216;

	/** @param string $key Project key. */
	public function __construct( private readonly string $key ) {}

	/** Resolve the project associated with a key. */
	public function context(): array|WP_Error {
		$data = $this->json( 'GET', '/api-key/context' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::is_uuid( $data['projectId'] ?? null ) ? $data : self::invalid();
	}

	/** Quote the processing cost of one uploaded image. */
	public function quote( string $project, string $kind = self::KIND, int $units = 1, bool $remote = false ): array|WP_Error {
		$input = array( 'kind' => $kind, 'units' => $units );
		if ( $remote ) {
			$input['remote'] = true;
		}
		$data = $this->json( 'POST', "/projects/$project/credits/quote", $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$credits = $data['credits'] ?? null;
		$expires = is_string( $data['expiresAt'] ?? null ) ? strtotime( $data['expiresAt'] ) : false;
		if ( ! self::is_uuid( $data['id'] ?? null ) || $kind !== ( $data['kind'] ?? null )
			|| $units !== ( $data['units'] ?? null ) || ( ! is_int( $credits ) && ! is_float( $credits ) )
			|| ! is_finite( (float) $credits ) || $credits < 0 || ! $expires || $expires <= time()
			|| ! is_bool( $data['replayEnabled'] ?? null ) ) {
			return self::invalid();
		}
		return $data;
	}

	/** Execute an operation from the bundled production catalog at the fixed HTTPS origin. */
	public function call( string $operation, string $project, string $id = '', array $query = array(), array $body = array(), array $files = array(), array $headers = array() ): array|WP_Error {
		$route = Operations::all()[ $operation ] ?? null;
		if ( ! $route || ( str_contains( $route['path'], '{projectId}' ) && ! self::is_uuid( $project ) )
			|| ( str_contains( $route['path'], '{id}' ) && ! self::is_uuid( $id ) ) ) {
			return self::invalid();
		}
		$path = strtr( $route['path'], array( '{projectId}' => $project, '{id}' => $id ) );
		if ( $query ) {
			$query = array_map( static fn( $value ) => is_bool( $value ) ? ( $value ? 'true' : 'false' ) : $value, $query );
			$path .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		$args = array(
			'headers' => array_intersect_key( $headers, array_fill_keys( array( 'Idempotency-Key', 'X-Quote-ID', 'If-None-Match' ), true ) ),
			'limit_response_size' => self::MAX_RESULT + 1,
			'timeout' => 'GET' === $route['method'] ? 15 : 23,
			'allow_not_modified' => 'TransformsController_content' === $operation,
		);
		if ( $route['multipart'] ) {
			$boundary = 'imazeo-' . str_replace( '-', '', wp_generate_uuid4() );
			$multipart = '';
			$size = 0;
			foreach ( $files as $file ) {
				if ( ! preg_match( '/\A[a-z0-9.-]+\z/', $file['name'] ) || ! preg_match( '~\A[a-z0-9.+-]+/[a-z0-9.+-]+\z~', $file['mime'] ) ) {
					return self::invalid();
				}
				$size += strlen( $file['bytes'] );
				if ( $size > self::MAX_BYTES ) {
					return new WP_Error( 'size', __( 'The selected files exceed the WordPress transfer limit.', 'imazeo' ) );
				}
				$multipart .= "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . $file['name'] . "\"\r\nContent-Type: " . $file['mime'] . "\r\n\r\n" . $file['bytes'] . "\r\n";
			}
			foreach ( $body as $name => $value ) {
				if ( ! isset( $route['body']['properties'][ $name ] ) || 'file' === $name || ! is_scalar( $value ) ) {
					return self::invalid();
				}
				$multipart .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
			}
			$args['body'] = $multipart . "--$boundary--\r\n";
			$args['headers']['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
			$args['headers']['X-Upload-Length'] = (string) $size;
		} elseif ( $body ) {
			$args['body'] = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}
		$response = $this->request( $route['method'], $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$bytes = wp_remote_retrieve_body( $response );
		$mime = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) );
		if ( 304 === $status ) {
			return array( 'unchanged' => true );
		}
		if ( strlen( $bytes ) > self::MAX_RESULT ) {
			return new WP_Error( 'size', __( 'Imazeo returned a result that exceeds the WordPress transfer limit.', 'imazeo' ) );
		}
		if ( 'application/json' === $mime ) {
			$data = $this->data( $response );
			$decoded = json_decode( $bytes, true );
			return is_wp_error( $data ) ? $data : array( 'data' => $data, 'meta' => $decoded['meta'] ?? array() );
		}
		return '' !== $bytes ? array( 'bytes' => $bytes, 'mime' => $mime ) : self::invalid();
	}

	/** Upload exactly the reviewed local bytes. */
	public function upload( string $project, string $bytes, string $mime ): string|WP_Error {
		$boundary = 'imazeo-' . str_replace( '-', '', wp_generate_uuid4() );
		$filename = 'image/png' === $mime ? 'source.png' : 'source.jpg';
		$body = "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"$filename\"\r\n"
			. "Content-Type: $mime\r\n\r\n" . $bytes . "\r\n--$boundary--\r\n";
		$response = $this->request(
			'POST',
			"/projects/$project/images",
			array(
				'body'    => $body,
				'headers' => array(
					'Content-Type'    => 'multipart/form-data; boundary=' . $boundary,
					'X-Upload-Length' => (string) strlen( $bytes ),
				),
				'timeout' => 15,
			)
		);
		$data = $this->data( $response );
		return is_wp_error( $data ) ? $data : ( self::is_uuid( $data['_id'] ?? null ) ? $data['_id'] : self::invalid() );
	}

	/** Create an ephemeral WebP derivative with its approved quote and stable request key. */
	public function transform( array $job, array $quote ): string|WP_Error {
		return $this->binary(
			$this->request(
				'POST',
				'/projects/' . $job['project'] . '/transforms',
				array(
					'headers' => array(
						'Content-Type'    => 'application/json',
						'Accept'          => 'image/webp',
						'Idempotency-Key' => $job['id'],
						'X-Quote-ID'      => $quote['id'],
					),
					'body'    => wp_json_encode(
						array(
							'imageId'        => $job['image'],
							'mode'           => 'EPHEMERAL',
							'maxOutputBytes' => self::MAX_BYTES,
							'transform'      => array(
								'width'         => $job['width'],
								'height'        => $job['width'],
								'fit'           => 'inside',
								'format'        => 'webp',
								'quality'       => $job['quality'],
								'stripMetadata' => true,
							),
						)
					),
					'timeout'             => 20,
					'limit_response_size' => self::MAX_BYTES + 1,
				)
			)
		);
	}

	/** Look up an existing operation without repeating processing. */
	public function lookup( string $project, string $key ): array|WP_Error {
		$data = $this->json( 'POST', "/projects/$project/operations/lookup", array( 'kind' => self::KIND, 'key' => $key ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! self::is_uuid( $data['id'] ?? null ) || self::KIND !== ( $data['kind'] ?? null )
			|| ! in_array( $data['state'] ?? null, array( 'running', 'completed', 'failed' ), true )
			|| ! is_bool( $data['hasResult'] ?? null ) ) {
			return self::invalid();
		}
		return $data;
	}

	/** Read the retained binary result for an existing operation. */
	public function result( string $project, string $id ): string|WP_Error {
		return $this->binary(
			$this->request(
				'GET',
				"/projects/$project/operations/$id/result",
				array(
					'headers'             => array( 'Accept' => 'image/webp' ),
					'timeout'             => 12,
					'limit_response_size' => self::MAX_BYTES + 1,
				)
			)
		);
	}

	/** Validate public API resource IDs, quotes and request keys. */
	public static function is_uuid( mixed $value ): bool {
		return is_string( $value ) && wp_is_uuid( $value, 4 );
	}

	private function json( string $method, string $path, ?array $body = null ): array|WP_Error {
		$args = null === $body ? array() : array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		);
		return $this->data( $this->request( $method, $path, $args ) );
	}

	private function request( string $method, string $path, array $args ): array|WP_Error {
		$allow_not_modified = $args['allow_not_modified'] ?? false;
		unset( $args['allow_not_modified'] );
		$args['method'] = $method;
		$args['redirection'] = 0;
		$args['sslverify'] = true;
		$args['decompress'] = false;
		$args['timeout'] ??= 8;
		$args['limit_response_size'] ??= 65536;
		$args['headers'] = array_merge(
			array( 'X-API-Key' => $this->key, 'Accept' => 'application/json', 'Accept-Encoding' => 'identity' ),
			$args['headers'] ?? array()
		);
		$response = wp_safe_remote_request( self::BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'transport', __( 'Imazeo could not be reached. Check your site’s connection and try again if no work was submitted.', 'imazeo' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( ( $status < 200 || $status > 299 ) && ! ( 304 === $status && $allow_not_modified ) ) {
			$error = json_decode( wp_remote_retrieve_body( $response ), true );
			$code = $error['error']['code'] ?? '';
			$insufficient = 402 === $status || 'CREDIT_INSUFFICIENT' === $code;
			$retry = wp_remote_retrieve_header( $response, 'retry-after' );
			$request = wp_remote_retrieve_header( $response, 'x-request-id' );
			return new WP_Error(
				$insufficient ? 'credit_insufficient' : 'remote',
				match ( true ) {
					$insufficient => __( 'The Imazeo project has insufficient processing credits.', 'imazeo' ),
					401 === $status, 403 === $status => __( 'Imazeo denied access. Check the project key, plan and permissions.', 'imazeo' ),
					413 === $status => __( 'The media exceeds an Imazeo plan or request limit.', 'imazeo' ),
					429 === $status => __( 'Imazeo is limiting requests. Wait before submitting more work.', 'imazeo' ),
					'QUOTE_EXPIRED' === $code => __( 'The credit quote expired. Review a new quote before submitting.', 'imazeo' ),
					default => __( 'Imazeo could not complete the request. Check the project’s usage before submitting again.', 'imazeo' ),
				},
				array(
					'status' => $status,
					'retry'  => is_string( $retry ) && ctype_digit( $retry ) ? min( 900, (int) $retry ) : 0,
					'request' => is_string( $request ) && preg_match( '/\Areq_[a-f0-9-]{36}\z/i', $request ) ? $request : '',
				)
			);
		}
		return $response;
	}

	private function data( array|WP_Error $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return true === ( $body['success'] ?? null ) && is_array( $body['data'] ?? null ) ? $body['data'] : self::invalid();
	}

	private function binary( array|WP_Error $response ): string|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = wp_remote_retrieve_body( $response );
		$type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( ! is_string( $type ) || 'image/webp' !== strtolower( trim( explode( ';', $type )[0] ) )
			|| '' === $body || strlen( $body ) > self::MAX_BYTES ) {
			return self::invalid();
		}
		return $body;
	}

	private static function invalid(): WP_Error {
		return new WP_Error( 'invalid_response', __( 'Imazeo returned an unexpected response. Review project usage before submitting again.', 'imazeo' ) );
	}
}
