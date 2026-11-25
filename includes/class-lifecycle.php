<?php
/**
 * Plugin lifecycle and WP-Cron integration.
 *
 * @package Imazeo
 */

namespace Imazeo\WordPress;

use WP_Site;

defined( 'ABSPATH' ) || exit;

/** Handles per-site queue initialization and cleanup. */
final class Lifecycle {
	public const HOOK = 'imazeo_process_media_queue';

	/** Activate a site or each site on a network install. */
	public static function activate( bool $network = false ): void {
		self::for_sites(
			$network,
			static function (): void {
				Queue::install();
				if ( array_filter( Queue::state()['jobs'], array( Queue::class, 'active' ) ) ) {
					self::schedule();
				}
			}
		);
	}

	/** Pause work and preserve the queue, connection and media. */
	public static function deactivate( bool $network = false ): void {
		self::for_sites( $network, static fn() => wp_clear_scheduled_hook( self::HOOK ) );
	}

	/** Prepare a newly added site when the plugin is network active. */
	public static function initialize_site( WP_Site $site ): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! is_plugin_active_for_network( plugin_basename( dirname( __DIR__ ) . '/imazeo.php' ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			Queue::install();
		} finally {
			restore_current_blog();
		}
	}

	/** Schedule a single recurring worker per site. */
	public static function schedule(): bool {
		return (bool) wp_next_scheduled( self::HOOK ) || wp_schedule_event( time() + 5, 'imazeo_minute', self::HOOK ) || (bool) wp_next_scheduled( self::HOOK );
	}

	/** Register a one-minute worker interval. */
	public static function schedules( array $schedules ): array {
		$schedules['imazeo_minute'] = array( 'interval' => MINUTE_IN_SECONDS, 'display' => __( 'Every minute for Imazeo', 'imazeo' ) );
		return $schedules;
	}

	/** Remove plugin-owned state while retaining all Media Library files. */
	public static function uninstall(): void {
		self::for_sites(
			is_multisite(),
			static function (): void {
				wp_clear_scheduled_hook( self::HOOK );
				foreach ( Queue::state()['jobs'] as $job ) {
					if ( ! empty( $job['private'] ) ) {
						delete_transient( Processing::PRIVATE_PREFIX . $job['id'] );
					}
				}
				delete_option( Connection::OPTION );
				delete_option( Queue::OPTION );
				delete_post_meta_by_key( Media::JOB_META );
				delete_post_meta_by_key( Media::SOURCE_META );
				delete_post_meta_by_key( '_imazeo_featured_restore' );
			}
		);
	}

	private static function for_sites( bool $network, callable $callback ): void {
		if ( ! $network || ! is_multisite() ) {
			$callback();
			return;
		}
		$offset = 0;
		do {
			$ids = get_sites( array( 'number' => 100, 'offset' => $offset, 'fields' => 'ids', 'orderby' => 'id' ) );
			foreach ( $ids as $id ) {
				switch_to_blog( (int) $id );
				try {
					$callback();
				} finally {
					restore_current_blog();
				}
			}
			$offset += count( $ids );
		} while ( count( $ids ) === 100 );
	}
}
