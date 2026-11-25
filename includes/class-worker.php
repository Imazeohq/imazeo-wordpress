<?php
/**
 * Background media processing and compatibility for existing WebP jobs.
 *
 * @package Imazeo
 */

namespace Imazeo\WordPress;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Performs one bounded, exclusively leased job step per cron run. */
final class Worker {
	/** Handle a scheduled worker event. */
	public static function run(): void {
		$job = Queue::claim();
		if ( ! $job ) {
			return;
		}
		$connection = Connection::current();
		if ( ! $connection || $connection['generation'] !== $job['generation'] ) {
			self::stop( $job, __( 'The Imazeo connection changed. Review the request again with the current connection.', 'imazeo' ) );
			return;
		}
		if ( isset( $job['task'] ) ) {
			Processing::work( $job, new Api( $connection['key'] ) );
			return;
		}
		if ( ! Media::can_import( $job['user'] ) ) {
			self::stop( $job, __( 'The site no longer allows WebP imports. Check its upload settings and image editor.', 'imazeo' ) );
			return;
		}
		$source = Media::read( $job['source'], $job['user'] );
		if ( is_wp_error( $source ) || ! hash_equals( $job['sha'], $source['hash'] ) ) {
			self::stop( $job, __( 'The source image or its permissions changed. Review the image again before processing.', 'imazeo' ) );
			return;
		}
		$api = new Api( $connection['key'] );
		switch ( $job['state'] ) {
			case 'uploading':
				self::upload( $api, $job, $source );
				break;
			case 'quoting':
				self::process( $api, $job );
				break;
			case 'recovering':
				self::recover( $api, $job );
				break;
		}
	}

	private static function upload( Api $api, array $job, array $source ): void {
		if ( ! self::authorized( $job ) || ! Queue::save( $job, array(), false ) ) {
			return;
		}
		$id = $api->upload( $job['project'], $source['bytes'], $source['mime'] );
		if ( is_wp_error( $id ) ) {
			// An ambiguous upload may already have created an original. Do not send it again.
			self::stop( $job, $id->get_error_message(), $id );
			return;
		}
		Queue::save( $job, array( 'image' => $id, 'state' => 'ready' ) );
	}

	private static function process( Api $api, array $job ): void {
		$quote = $api->quote( $job['project'] );
		if ( is_wp_error( $quote ) ) {
			self::stop( $job, $quote->get_error_message(), $quote );
			return;
		}
		if ( $quote['credits'] > $job['ceiling'] ) {
			self::stop( $job, __( 'The processing price increased. Review and approve a new quote.', 'imazeo' ) );
			return;
		}
		// Fence the chargeable call before sending it. Only recovery is allowed if the worker is interrupted here.
		$job['replay'] = $quote['replayEnabled'];
		if ( ! self::authorized( $job ) || ! Queue::save( $job, array( 'state' => 'processing', 'replay' => $job['replay'] ), false ) ) {
			return;
		}
		$bytes = $api->transform( $job, $quote );
		if ( ! is_wp_error( $bytes ) ) {
			self::store( $job, $bytes );
			return;
		}
		$status = (int) ( $bytes->get_error_data()['status'] ?? 0 );
		if ( $job['replay'] && ( 0 === $status || 409 === $status || $status >= 500 ) ) {
			self::retry_recovery( $job, $bytes );
			return;
		}
		self::stop( $job, $bytes->get_error_message(), $bytes );
	}

	private static function recover( Api $api, array $job ): void {
		$existing = Media::existing( $job['id'] );
		if ( $existing ) {
			self::complete( $job, $existing );
			return;
		}
		if ( empty( $job['replay'] ) || $job['attempts'] > 4 ) {
			self::stop( $job, __( 'The previous request was interrupted and its result is unavailable. Review Imazeo project usage before submitting again.', 'imazeo' ) );
			return;
		}
		$record = $api->lookup( $job['project'], $job['id'] );
		if ( is_wp_error( $record ) ) {
			self::retry_recovery( $job, $record );
			return;
		}
		if ( 'running' === $record['state'] ) {
			self::retry_recovery( $job );
			return;
		}
		if ( 'completed' !== $record['state'] || ! $record['hasResult'] ) {
			self::stop( $job, __( 'Imazeo has no retained result for this request. Review project usage before starting a new copy.', 'imazeo' ) );
			return;
		}
		$bytes = $api->result( $job['project'], $record['id'] );
		if ( is_wp_error( $bytes ) ) {
			self::retry_recovery( $job, $bytes );
			return;
		}
		self::store( $job, $bytes );
	}

	private static function retry_recovery( array $job, ?WP_Error $error = null ): void {
		$data = $error?->get_error_data();
		$status = (int) ( $data['status'] ?? 0 );
		if ( $job['attempts'] >= 4 || in_array( $status, array( 400, 401, 403, 413 ), true ) ) {
			self::stop( $job, __( 'The result could not be recovered. Review Imazeo project usage before starting a new copy.', 'imazeo' ), $error );
			return;
		}
		Queue::save(
			$job,
			array(
				'state' => 'recover',
				'due'   => time() + max( (int) ( $data['retry'] ?? 0 ), 60 * ( $job['attempts'] + 1 ) ),
			)
		);
	}

	private static function store( array $job, string $bytes ): void {
		if ( ! self::authorized( $job ) || ! Queue::save( $job, array( 'state' => 'importing' ), false ) ) {
			return;
		}
		$previous = get_current_user_id();
		try {
			wp_set_current_user( $job['user'] );
			$id = Media::import( $job, $bytes );
		} finally {
			wp_set_current_user( $previous );
		}
		if ( is_wp_error( $id ) ) {
			self::stop( $job, $id->get_error_message(), $id );
			return;
		}
		self::complete( $job, $id );
	}

	private static function authorized( array $job ): bool {
		if ( Connection::still_connected( $job['generation'] ) && Media::eligible( $job['source'], $job['user'] ) ) {
			return true;
		}
		self::stop( $job, __( 'The connection or attachment permissions changed. Review project usage before submitting again.', 'imazeo' ) );
		return false;
	}

	private static function complete( array $job, int $id ): void {
		$file = get_attached_file( $id, true );
		Queue::save(
			$job,
			array(
				'state'  => 'completed',
				'output' => $id,
				'bytes'  => $file && is_file( $file ) ? (int) wp_filesize( $file ) : 0,
			)
		);
	}

	private static function stop( array $job, string $message, ?WP_Error $error = null ): void {
		Queue::save(
			$job,
			array(
				'state'   => 'attention',
				'message' => $message,
				'request' => $error?->get_error_data()['request'] ?? '',
			)
		);
	}
}
