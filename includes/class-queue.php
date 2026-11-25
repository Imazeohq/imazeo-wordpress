<?php
/**
 * Bounded site queue and credit reviews.
 *
 * @package Imazeo
 */

namespace Imazeo\WordPress;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Persists a small queue with atomic compare-and-swap updates. */
final class Queue {
	public const OPTION = 'imazeo_queue';
	public const MAX_BATCH = 10;
	public const LEASE = 240;
	private const WAITING = array( 'queued', 'ready', 'recover', 'remote' );
	private const RUNNING = array( 'uploading', 'quoting', 'processing', 'importing', 'recovering', 'polling' );

	/** Initialize the non-autoloaded state row. */
	public static function install(): void {
		add_option( self::OPTION, '{"jobs":[],"reviews":[]}', '', false );
	}

	/** Load the bounded state. A direct read avoids an obsolete persistent-cache value during a worker race. */
	public static function state(): array {
		return self::snapshot()[1];
	}

	/** Save a single expiring review per user. */
	public static function store_review( int $user, array $review ): bool {
		return true === self::change(
			static function ( array &$state ) use ( $user, $review ): bool {
				if ( ! isset( $state['reviews'][ $user ] ) && count( $state['reviews'] ) >= 25 ) {
					return false;
				}
				$state['reviews'][ $user ] = $review;
				return true;
			}
		);
	}

	/** Read an owner-scoped review before displaying the confirmation form. */
	public static function review( int $user, string $token ): ?array {
		$review = self::state()['reviews'][ $user ] ?? null;
		return $review && $review['token'] === $token && $review['expires'] > time() ? $review : null;
	}

	/** Consume a review exactly once and enqueue all eligible copies or none. */
	public static function enqueue( int $user, string $token, string $generation ): int|WP_Error {
		$result = self::change(
			static function ( array &$state ) use ( $user, $token, $generation ): int|WP_Error {
				$review = $state['reviews'][ $user ] ?? null;
				if ( ! $review || $token !== $review['token'] || $generation !== $review['generation'] || $review['expires'] <= time() ) {
					return new WP_Error( 'review', __( 'This review expired or was already submitted. Start a new review.', 'imazeo' ) );
				}
				$pending = count( array_filter( $state['jobs'], static fn( array $job ): bool => self::active( $job ) ) );
				$items = $review['tasks'] ?? $review['files'];
				if ( $pending + count( $items ) > 25 ) {
					return new WP_Error( 'full', __( 'The queue is full. Wait for some copies to finish and request a new review.', 'imazeo' ) );
				}
				if ( isset( $review['tasks'] ) ) {
					foreach ( $items as $task ) {
						foreach ( $state['jobs'] as $existing ) {
							if ( self::active( $existing ) && $task['fingerprint'] === ( $existing['fingerprint'] ?? '' ) ) {
								return new WP_Error( 'duplicate', __( 'This media operation is already queued.', 'imazeo' ) );
							}
						}
					}
					unset( $state['reviews'][ $user ] );
					foreach ( $items as $task ) {
						$id = wp_generate_uuid4();
						$state['jobs'][ $id ] = $task + array(
							'id' => $id, 'user' => $user, 'project' => $review['project'], 'generation' => $generation,
							'state' => 'queued', 'updated' => time(), 'due' => time(), 'created' => time(), 'attempts' => 0,
						);
					}
					return count( $items );
				}
				foreach ( $review['files'] as $id => $file ) {
					foreach ( $state['jobs'] as $existing ) {
						if ( self::active( $existing ) && (int) $id === $existing['source'] ) {
							return new WP_Error( 'duplicate', __( 'An image in this selection is already queued. Wait for it to finish or remove it from the selection.', 'imazeo' ) );
						}
					}
				}
				unset( $state['reviews'][ $user ] );
				foreach ( $review['files'] as $id => $file ) {
					$job = wp_generate_uuid4();
					$state['jobs'][ $job ] = array(
						'id'         => $job,
						'source'     => (int) $id,
						'user'       => $user,
						'project'    => $review['project'],
						'generation' => $generation,
						'width'      => min( $review['width'], $file['edge'] ),
						'quality'    => $review['quality'],
						'ceiling'    => $review['credits'],
						'sha'        => $file['hash'],
						'original'   => $file['length'],
						'state'      => 'queued',
						'updated'    => time(),
						'due'        => time(),
						'attempts'   => 0,
					);
				}
				return count( $review['files'] );
			}
		);
		return is_int( $result ) || is_wp_error( $result ) ? $result : self::busy();
	}

	/** Take one lease or return immediately if another worker is active. */
	public static function claim(): ?array {
		$result = self::change(
			static function ( array &$state ): ?array {
				$now = time();
				foreach ( $state['jobs'] as &$job ) {
					if ( ! in_array( $job['state'], self::RUNNING, true ) ) {
						continue;
					}
					if ( $job['lease'] > $now ) {
						return null;
					}
					$job['state'] = match ( $job['state'] ) {
						'quoting' => 'ready',
						'polling' => 'remote',
						'processing', 'importing', 'recovering' => 'recover',
						default => 'attention',
					};
					$job['problem'] = 'interrupted';
					$job['updated'] = $now;
					unset( $job['token'], $job['lease'] );
				}
				unset( $job );
				foreach ( $state['jobs'] as &$job ) {
					if ( ! in_array( $job['state'], self::WAITING, true ) || $job['due'] > $now ) {
						continue;
					}
					$job['state'] = match ( $job['state'] ) {
						'queued' => 'uploading',
						'ready'  => 'quoting',
						'remote' => 'polling',
						default  => 'recovering',
					};
					if ( 'recovering' === $job['state'] ) {
						++$job['attempts'];
					}
					$job['token'] = wp_generate_uuid4();
					$job['lease'] = $now + self::LEASE;
					$job['updated'] = $now;
					return $job;
				}
				return null;
			}
		);
		return is_array( $result ) ? $result : null;
	}

	/** Advance only the job still owned by this worker. */
	public static function save( array $job, array $fields, bool $release = true ): bool {
		return true === self::change(
			static function ( array &$state ) use ( $job, $fields, $release ): bool {
				$current = $state['jobs'][ $job['id'] ] ?? null;
				if ( ! $current || ( $current['token'] ?? '' ) !== $job['token'] || $current['lease'] <= time() ) {
					return false;
				}
				$current = array_replace( $current, $fields, array( 'updated' => time() ) );
				if ( $release ) {
					unset( $current['token'], $current['lease'] );
				}
				$state['jobs'][ $job['id'] ] = $current;
				return true;
			}
		);
	}

	/** Cancel work that has no active worker. */
	public static function cancel( string $id, int $user ): bool {
		return true === self::change(
			static function ( array &$state ) use ( $id, $user ): bool {
				$job = $state['jobs'][ $id ] ?? null;
				if ( ! $job || ! self::visible( $job, $user ) || ! in_array( $job['state'], array( 'queued', 'ready', 'recover' ), true ) ) {
					return false;
				}
				$state['jobs'][ $id ]['state'] = 'cancelled';
				$state['jobs'][ $id ]['updated'] = time();
				return true;
			}
		);
	}

	/** Limit history to its submitter or an administrator who can edit the source. */
	public static function visible( array $job, int $user ): bool {
		return ( $user === $job['user'] || user_can( $user, 'manage_options' ) )
			&& ! array_filter( $job['ids'] ?? array_filter( array( $job['source'] ?? 0 ) ), static fn( int $id ): bool => ! user_can( $user, 'edit_post', $id ) );
	}

	/** Reserve a confirmed project action once across repeat submissions and concurrent requests. */
	public static function reserve_command( string $key ): bool {
		return true === self::change( static function ( array &$state ) use ( $key ): bool {
			$state['commands'] = array_filter( $state['commands'] ?? array(), static fn( int $expiry ): bool => $expiry > time() );
			if ( isset( $state['commands'][ $key ] ) || count( $state['commands'] ) >= 50 ) {
				return false;
			}
			$state['commands'][ $key ] = time() + 600;
			return true;
		} );
	}

	/** Whether a job can still perform work. */
	public static function active( array $job ): bool {
		return in_array( $job['state'], self::WAITING, true ) || in_array( $job['state'], self::RUNNING, true );
	}

	private static function snapshot(): array {
		global $wpdb;
		// Direct reads and CAS writes intentionally bypass the options cache.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::OPTION ) );
		$state = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return array( $raw, is_array( $state ) ? $state : array( 'jobs' => array(), 'reviews' => array() ) );
	}

	private static function change( callable $change ): mixed {
		global $wpdb;
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			list( $raw, $state ) = self::snapshot();
			if ( null === $raw ) {
				return self::busy();
			}
			$original = $state;
			$now = time();
			$state['reviews'] = array_filter( $state['reviews'], static fn( array $review ): bool => $review['expires'] > $now );
			if ( isset( $state['commands'] ) ) {
				$state['commands'] = array_filter( $state['commands'], static fn( int $expiry ): bool => $expiry > $now );
			}
			$terminal = array_filter( $state['jobs'], static fn( array $job ): bool => ! self::active( $job ) );
			uasort( $terminal, static fn( array $a, array $b ): int => $b['updated'] <=> $a['updated'] );
			$old = array_filter( $terminal, static fn( array $job ): bool => $job['updated'] < $now - 30 * DAY_IN_SECONDS );
			$state['jobs'] = array_diff_key( $state['jobs'], $old, array_slice( $terminal, 75, null, true ) );
			$result = $change( $state );
			if ( $state === $original ) {
				return $result;
			}
			$next = wp_json_encode( $state );
			if ( false === $next ) {
				return self::busy();
			}
			// WordPress's options API cannot conditionally replace a row. BINARY makes the comparison byte-exact.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$changed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s', $wpdb->options, $next, self::OPTION, $raw ) );
			if ( 1 === $changed ) {
				wp_cache_delete( self::OPTION, 'options' );
				return $result;
			}
		}
		return self::busy();
	}

	private static function busy(): WP_Error {
		return new WP_Error( 'queue', __( 'WordPress could not update the queue. Try again in a moment.', 'imazeo' ) );
	}
}
