<?php
/**
 * Bounded processing jobs and retained output import.
 *
 * @package Imazeo
 */

namespace Imazeo\WordPress;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Processing {
	public const PRIVATE_PREFIX = 'imazeo_result_';

	/** Only upload bytes from reviewed attachments. */
	public static function reviewed_files( array $ids, int $user, string $family, array $hashes = array() ): array|WP_Error {
		$files = array();
		$total = 0;
		foreach ( $ids as $id ) {
			$file = Media::read( (int) $id, $user, $family );
			if ( is_wp_error( $file ) || ( $hashes && ! hash_equals( $hashes[ $id ] ?? '', $file['hash'] ) ) ) {
				return is_wp_error( $file ) ? $file : new WP_Error( 'file', __( 'An attachment changed after review. Select it again.', 'imazeo' ) );
			}
			$total += strlen( $file['bytes'] );
			if ( $total > Api::MAX_BYTES ) {
				return new WP_Error( 'size', __( 'The batch transfer limit is 5 MiB per request.', 'imazeo' ) );
			}
			$file['name'] = 'source-' . ( count( $files ) + 1 ) . '.' . Media::TYPES[ $file['mime'] ];
			$files[ $id ] = $file;
		}
		return $files;
	}

	/** Process one step using the same queue fence as existing image jobs. */
	public static function work( array $job, Api $api ): void {
		$spec = Operations::tasks()[ $job['task'] ] ?? null;
		if ( ! $spec || ! user_can( $job['user'], 'upload_files' ) || ( ! empty( $job['project_source'] ) && ! user_can( $job['user'], 'manage_options' ) ) ) {
			self::fail( $job, __( 'Your permission to process this media changed.', 'imazeo' ) );
			return;
		}
		foreach ( $job['ids'] as $id ) {
			if ( ! Media::eligible( $id, $job['user'], $spec[3] ) ) {
				self::fail( $job, __( 'An attachment’s permissions changed after approval.', 'imazeo' ) );
				return;
			}
		}
		if ( 'polling' === $job['state'] ) {
			self::poll( $job, $api );
			return;
		}
		if ( 'recovering' === $job['state'] ) {
			self::recover( $job, $api, $spec );
			return;
		}
		$files = self::reviewed_files( $job['ids'], $job['user'], $spec[3], $job['hashes'] );
		if ( is_wp_error( $files ) ) {
			self::fail( $job, $files->get_error_message() );
			return;
		}
		if ( 'image' === $job['task'] && $files && empty( $job['image'] ) ) {
			if ( ! Connection::still_connected( $job['generation'] ) ) {
				self::fail( $job, __( 'The project connection changed. Review the request again.', 'imazeo' ) );
				return;
			}
			$result = $api->call( 'ImagesController_upload', $job['project'], '', array(), array(), $files );
			$id = is_wp_error( $result ) ? '' : ( $result['data']['_id'] ?? '' );
			if ( is_wp_error( $result ) || ! Api::is_uuid( $id ) ) {
				self::fail( $job, is_wp_error( $result ) ? $result->get_error_message() : __( 'Imazeo did not return an image identifier.', 'imazeo' ) );
				return;
			}
			Queue::save( $job, array( 'state' => 'ready', 'image' => $id ) );
			return;
		}
		$body = $job['body'];
		if ( 'image' === $job['task'] && $files ) {
			$body['imageId'] = $job['image'];
			$files = array();
		}
		$quote = $api->quote( $job['project'], $spec[2], $job['units'], ! empty( $job['remote_source'] ) );
		if ( is_wp_error( $quote ) || $quote['credits'] > $job['ceiling'] ) {
			self::fail( $job, is_wp_error( $quote ) ? $quote->get_error_message() : __( 'The price increased. Review a new quote.', 'imazeo' ) );
			return;
		}
		if ( ! Connection::still_connected( $job['generation'] ) ) {
			self::fail( $job, __( 'The project connection changed. Review the request again.', 'imazeo' ) );
			return;
		}
		if ( ! Queue::save( $job, array( 'state' => 'processing', 'replay' => $quote['replayEnabled'] ), false ) ) {
			return;
		}
		$job['replay'] = $quote['replayEnabled'];
		$result = $api->call( $spec[1], $job['project'], '', $job['query'], $body, $files, array( 'Idempotency-Key' => $job['id'], 'X-Quote-ID' => $quote['id'] ) );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );
			if ( ( ! $status || 409 === $status || $status >= 500 ) && ( 'conversion' === $spec[4] || $job['replay'] ) ) {
				self::wait( $job, 'recover', 60 );
				return;
			}
			self::fail( $job, $result->get_error_message() );
			return;
		}
		if ( 'conversion' === $spec[4] ) {
			self::track( $job, $result );
			return;
		}
		self::store( $job, $result, $spec[0] );
	}

	private static function track( array $job, array $result ): void {
		$id = $result['data']['id'] ?? '';
		if ( ! Api::is_uuid( $id ) ) {
			self::wait( $job, 'recover', 60 );
			return;
		}
		Queue::save( $job, array( 'state' => 'remote', 'conversion' => $id, 'due' => time() + 30 ) );
	}

	private static function poll( array $job, Api $api ): void {
		$id = $job['conversion'];
		$status = $api->call( 'ConversionsController_get', $job['project'], $id );
		if ( is_wp_error( $status ) ) {
			$http_status = (int) ( $status->get_error_data()['status'] ?? 0 );
			if ( ( ! $http_status || 429 === $http_status || $http_status >= 500 ) && time() - $job['created'] < 3600 ) {
				self::wait( $job, 'remote', max( 90, (int) ( $status->get_error_data()['retry'] ?? 0 ) ) );
			} else {
				self::fail( $job, $status->get_error_message() );
			}
			return;
		}
		$state = $status['data']['status'] ?? '';
		if ( 'completed' === $state ) {
			$result = $api->call( 'ConversionsController_download', $job['project'], $id );
			if ( is_wp_error( $result ) ) {
				$http_status = (int) ( $result->get_error_data()['status'] ?? 0 );
				if ( ( ! $http_status || 429 === $http_status || $http_status >= 500 ) && time() - $job['created'] < 3600 ) {
					self::wait( $job, 'remote', max( 90, (int) ( $result->get_error_data()['retry'] ?? 0 ) ) );
				} else {
					self::fail( $job, $result->get_error_message() );
				}
			} else {
				self::store( $job, $result, Operations::tasks()[ $job['task'] ][0] );
			}
		} elseif ( in_array( $state, array( 'queued', 'running', 'cancelling' ), true ) && time() - $job['created'] < 3600 ) {
			self::wait( $job, 'remote', 60 );
		} else {
			self::fail( $job, __( 'Check the conversion in Project library for its latest status or available result.', 'imazeo' ) );
		}
	}

	private static function recover( array $job, Api $api, array $spec ): void {
		if ( $job['attempts'] > 4 ) {
			self::fail( $job, __( 'No result could be recovered. Check the project before approving another request.', 'imazeo' ) );
			return;
		}
		if ( 'conversion' === $spec[4] ) {
			if ( ! empty( $job['conversion'] ) ) {
				self::poll( $job, $api );
				return;
			}
			$result = $api->call( 'ConversionsController_lookup', $job['project'], '', array( 'key' => $job['id'] ) );
			if ( ! is_wp_error( $result ) ) {
				self::track( $job, $result );
				return;
			}
		} elseif ( ! empty( $job['replay'] ) ) {
			$lookup = $api->call( 'ProcessingController_lookup', $job['project'], '', array(), array( 'kind' => $spec[2], 'key' => $job['id'] ) );
			$data = ! is_wp_error( $lookup ) ? ( $lookup['data'] ?? array() ) : array();
			if ( 'completed' === ( $data['state'] ?? '' ) && ! empty( $data['hasResult'] ) && Api::is_uuid( $data['id'] ?? null ) ) {
				$result = $api->call( 'ProcessingController_result', $job['project'], $data['id'] );
				if ( ! is_wp_error( $result ) ) {
					self::store( $job, $result, $spec[0] );
					return;
				}
			}
		} else {
			self::fail( $job, __( 'This project has no retained result for the interrupted operation. Check usage before trying again.', 'imazeo' ) );
			return;
		}
		self::wait( $job, 'recover', 60 * $job['attempts'] );
	}

	/** Decode trusted-format API media and store results as native attachments. */
	private static function store( array $job, array $result, string $label ): void {
		if ( ! Connection::still_connected( $job['generation'] ) ) {
			self::fail( $job, __( 'The project connection changed. Retrieve the result from the original project.', 'imazeo' ) );
			return;
		}
		if ( ! Queue::save( $job, array( 'state' => 'importing' ), false ) ) {
			return;
		}
		$pages = $result['data']['pages'] ?? null;
		if ( is_array( $pages ) && 'pdf_render' === $job['task'] ) {
			$items = array();
			foreach ( array_slice( $pages, 0, Operations::RENDER_LIMIT ) as $page ) {
				$number = (int) ( $page['pageNumber'] ?? 0 );
				$encoded = $page['data'] ?? null;
				$bytes = is_string( $encoded ) ? base64_decode( $encoded, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode the documented PDF page result.
				if ( $number < 1 || ! is_string( $bytes ) || ! is_string( $page['contentType'] ?? null ) ) {
					self::fail( $job, __( 'Imazeo returned invalid PDF page data.', 'imazeo' ) );
					return;
				}
				$items[ $number ] = array( 'bytes' => $bytes, 'mime' => $page['contentType'] );
			}
		} elseif ( isset( $result['bytes'] ) ) {
			$items = array( 1 => $result );
		} else {
			$metadata = $result['data'] ?? array();
			$metadata = strlen( (string) wp_json_encode( $metadata ) ) <= 32768 ? $metadata : array_intersect_key( $metadata, array( 'pageCount' => true ) );
			Queue::save( $job, array( 'state' => 'completed', 'data' => $metadata ) );
			return;
		}
		$outputs = array();
		$previous = get_current_user_id();
		wp_set_current_user( $job['user'] );
		try {
			foreach ( $items as $page => $item ) {
				$mime = self::result_mime( $item, $job['body']['format'] ?? '' );
				if ( 'image/svg+xml' === $mime ) {
					$key = self::PRIVATE_PREFIX . $job['id'];
					if ( strlen( $item['bytes'] ) > 524288 || ( ! set_transient( $key, $item['bytes'], HOUR_IN_SECONDS ) && get_transient( $key ) !== $item['bytes'] ) ) {
						self::fail( $job, __( 'The SVG could not be stored for private download.', 'imazeo' ) );
						return;
					}
					Queue::save( $job, array( 'state' => 'completed', 'private' => 'svg' ) );
					return;
				}
				$item_job = array_replace( $job, array( 'id' => $job['id'] . ':' . $page ) );
				$existing = Media::existing( $item_job['id'] );
				$id = $existing ?: Media::import_result( $item_job, $item['bytes'], $mime, $label . ( count( $items ) > 1 ? ' ' . $page : '' ) );
				if ( is_wp_error( $id ) ) {
					Queue::save( $job, array( 'state' => 'attention', 'message' => $id->get_error_message(), 'outputs' => $outputs ) );
					return;
				}
				$outputs[] = $id;
			}
		} finally {
			wp_set_current_user( $previous );
		}
		Queue::save( $job, array( 'state' => 'completed', 'outputs' => $outputs, 'output' => $outputs[0] ?? 0 ) );
	}

	/** Infer a binary result from its expected format or raster signature when documented as octet-stream. */
	public static function result_mime( array $item, string $format = '' ): string {
		if ( in_array( $item['mime'], array( '', 'application/octet-stream' ), true ) ) {
			if ( 'svg' === $format ) {
				return 'image/svg+xml';
			}
			if ( str_starts_with( $item['bytes'], '%PDF-' ) ) {
				return 'application/pdf';
			}
			$image = @getimagesizefromstring( $item['bytes'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Inspect binary metadata safely.
			return $image['mime'] ?? '';
		}
		return $item['mime'];
	}

	private static function wait( array $job, string $state, int $delay ): void {
		Queue::save( $job, array( 'state' => $state, 'due' => time() + $delay ) );
	}

	private static function fail( array $job, string $message ): void {
		Queue::save( $job, array( 'state' => 'attention', 'message' => $message ) );
	}
}
