<?php
/**
 * Local attachment inspection and native media import.
 *
 * @package Imazeo
 */

namespace Imazeo\WordPress;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/** Operates on eligible files in the local WordPress uploads directory. */
final class Media {
	public const JOB_META = '_imazeo_output_job';
	public const SOURCE_META = '_imazeo_source_attachment';
	public const TYPES = array(
		'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
		'image/gif' => 'gif', 'image/avif' => 'avif', 'application/pdf' => 'pdf',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
	);

	/** Check the user's rights, attachment type and the parent’s visibility. */
	public static function eligible( int $id, int $user, string $family = 'legacy' ): bool {
		if ( $id < 1 ) {
			return false;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type
			|| ! self::matches( $post->post_mime_type, $family )
			|| ! in_array( $post->post_status, array( 'inherit', 'publish' ), true )
			|| '' !== $post->post_password || ! user_can( $user, 'upload_files' ) || ! user_can( $user, 'edit_post', $id ) ) {
			return false;
		}
		if ( ! $post->post_parent ) {
			return true;
		}
		$parent = get_post( $post->post_parent );
		return $parent instanceof WP_Post && is_post_publicly_viewable( $parent ) && '' === $parent->post_password;
	}

	/** Respect the site's upload policy and image editor configuration. */
	public static function can_import( int $user, string $mime = 'image/webp' ): bool {
		if ( ! isset( self::TYPES[ $mime ] ) ) {
			return false;
		}
		$type = wp_check_filetype( 'imazeo.' . self::TYPES[ $mime ], get_allowed_mime_types( $user ) );
		return $mime === $type['type'] && ( ! str_starts_with( $mime, 'image/' ) || wp_image_editor_supports( array( 'mime_type' => $mime ) ) );
	}

	/** Snapshot only the bounded, canonical local attachment file. */
	public static function read( int $id, int $user, string $family = 'legacy' ): array|WP_Error {
		if ( ! self::eligible( $id, $user, $family ) ) {
			return new WP_Error( 'attachment', __( 'This attachment is private, unsupported or outside your editing permissions.', 'imazeo' ) );
		}
		$file = get_attached_file( $id, true );
		$uploads = wp_get_upload_dir();
		$root = realpath( $uploads['basedir'] );
		$path = $file && wp_is_stream( $file ) === false ? realpath( $file ) : false;
		if ( ! $root || ! $path || is_link( $file ) || ! is_file( $path ) || ! str_starts_with( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $root ) ) ) ) {
			return new WP_Error( 'file', __( 'The original is unavailable in this site’s local uploads directory.', 'imazeo' ) );
		}
		// Read the same bytes that will be hashed, validated and sent. The extra byte detects oversized files.
		$bytes = @file_get_contents( $path, false, null, 0, Api::MAX_BYTES + 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded local file read, without logging paths.
		if ( ! is_string( $bytes ) || '' === $bytes || strlen( $bytes ) > Api::MAX_BYTES ) {
			return new WP_Error( 'size', __( 'Choose a readable local file up to 5 MiB.', 'imazeo' ) );
		}
		$mime = get_post_mime_type( $id );
		if ( ! str_starts_with( $mime, 'image/' ) ) {
			$signature = 'application/pdf' === $mime ? '%PDF-' : "PK\x03\x04";
			return str_starts_with( $bytes, $signature )
				? array( 'bytes' => $bytes, 'mime' => $mime, 'hash' => hash( 'sha256', $bytes ) )
				: new WP_Error( 'type', __( 'The attachment bytes do not match the selected document type.', 'imazeo' ) );
		}
		$size = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Reject malformed images without emitting a warning.
		if ( ! is_array( $size ) || get_post_mime_type( $id ) !== $size['mime'] || $size[0] < 1 || $size[1] < 1
			|| $size[0] * $size[1] > 16000000 || ( 'legacy' === $family && 'image/png' === $size['mime'] && self::animated_png( $bytes ) ) ) {
			return new WP_Error( 'type', __( 'Choose a supported raster image up to 16 megapixels.', 'imazeo' ) );
		}
		return array(
			'bytes' => $bytes,
			'mime'  => $size['mime'],
			'hash'  => hash( 'sha256', $bytes ),
			'edge'  => max( $size[0], $size[1] ),
		);
	}

	private static function matches( string $mime, string $family ): bool {
		return match ( $family ) {
			'legacy' => in_array( $mime, array( 'image/jpeg', 'image/png' ), true ),
			'image' => isset( self::TYPES[ $mime ] ) && str_starts_with( $mime, 'image/' ),
			'any' => isset( self::TYPES[ $mime ] ),
			default => $family === ( self::TYPES[ $mime ] ?? '' ),
		};
	}

	/** Look up a previous local import when recovering an interrupted worker. */
	public static function existing( string $job ): int {
		$matches = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => array( 'inherit', 'publish' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
				'meta_key'               => self::JOB_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Used only for recovery after an interrupted import.
				'meta_value'             => $job, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return (int) ( $matches[0] ?? 0 );
	}

	/** Store a validated result using WordPress attachment and sideload APIs. */
	public static function import( array $job, string $bytes ): int|WP_Error {
		return self::import_result( $job, $bytes, 'image/webp', 'WebP', $job['width'] );
	}

	/** Import raster images and PDFs through the normal WordPress upload policy. */
	public static function import_result( array $job, string $bytes, string $mime, string $label, int $max_edge = 10000 ): int|WP_Error {
		$type = self::TYPES[ $mime ] ?? '';
		$valid = false;
		if ( 'application/pdf' === $mime ) {
			$valid = str_starts_with( $bytes, '%PDF-' );
		} elseif ( isset( self::TYPES[ $mime ] ) && str_starts_with( $mime, 'image/' ) ) {
			$size = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Validate untrusted binary output.
			$valid = is_array( $size ) && $mime === $size['mime'] && min( $size[0], $size[1] ) > 0
				&& max( $size[0], $size[1] ) <= $max_edge && $size[0] * $size[1] <= 25000000;
		}
		if ( ! $valid || strlen( $bytes ) > Api::MAX_RESULT || ! self::can_import( $job['user'], $mime ) ) {
			return new WP_Error( 'invalid_output', __( 'WordPress could not accept the result format or size.', 'imazeo' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temp = wp_tempnam( 'imazeo.' . $type );
		if ( ! $temp ) {
			return self::save_error();
		}
		try {
			if ( strlen( $bytes ) !== @file_put_contents( $temp, $bytes, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Use a local WordPress temporary file without logging paths.
				return self::save_error();
			}
			$source = (int) ( $job['source'] ?? 0 );
			$title = $source ? sanitize_text_field( get_the_title( $source ) ) : sanitize_text_field( $label );
			$basename = substr( sanitize_title( $title ), 0, 120 ) ?: 'image';
			$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 16 );
			$name = sanitize_file_name( $basename . '-' . $suffix . '.' . $type );
			$upload = array( 'name' => $name, 'tmp_name' => $temp, 'error' => UPLOAD_ERR_OK, 'size' => strlen( $bytes ) );
			$file = wp_handle_sideload( $upload, array( 'test_form' => false ) );
			if ( ! empty( $file['error'] ) ) {
				return self::save_error();
			}
			$id = wp_insert_attachment(
				array(
					'guid'           => $file['url'],
					'post_mime_type' => $mime,
					/* translators: 1: image title, 2: result label. */
					'post_title'     => wp_slash( $source ? sprintf( __( '%1$s (%2$s)', 'imazeo' ), $title, $label ) : $title ),
					'post_author'    => $job['user'],
					'post_status'    => 'inherit',
					'meta_input'     => array(
						self::JOB_META => $job['id'],
						self::SOURCE_META => $source,
						'_wp_attachment_image_alt' => $source ? wp_slash( sanitize_text_field( get_post_meta( $source, '_wp_attachment_image_alt', true ) ) ) : '',
					),
				),
				$file['file'],
				0,
				true
			);
			if ( is_wp_error( $id ) || ! $id ) {
				wp_delete_file( $file['file'] );
				return self::save_error();
			}
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file['file'] ) );
			/** Fires after an Imazeo WebP attachment is imported.
			 *
			 * @param int $id     New attachment ID.
			 * @param int $source Source attachment ID.
			 */
			if ( 'image/webp' === $mime ) {
				do_action( 'imazeo_webp_imported', $id, $source );
			}
			do_action( 'imazeo_media_imported', $id, $source, $mime );
			return $id;
		} finally {
			if ( is_file( $temp ) ) {
				wp_delete_file( $temp );
			}
		}
	}

	private static function animated_png( string $bytes ): bool {
		$offset = 8;
		$size = strlen( $bytes );
		while ( $offset + 12 <= $size ) {
			$length = unpack( 'N', substr( $bytes, $offset, 4 ) )[1];
			$type = substr( $bytes, $offset + 4, 4 );
			if ( 'acTL' === $type ) {
				return true;
			}
			if ( 'IDAT' === $type || $length > $size - $offset - 12 ) {
				break;
			}
			$offset += 12 + $length;
		}
		return false;
	}

	private static function save_error(): WP_Error {
		return new WP_Error( 'local_save', __( 'WordPress could not save the media. Check upload permissions and disk space.', 'imazeo' ) );
	}
}
