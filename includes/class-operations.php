<?php
/**
 * Reviewed production operations and their WordPress workflow settings.
 *
 * @package Imazeo
 */

namespace Imazeo\WordPress;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Operations {
	public const RENDER_LIMIT = 10;

	/** Read the bundled, API-key-only contract. */
	public static function all(): array {
		static $operations;
		if ( null === $operations ) {
			$catalog = file_get_contents( __DIR__ . '/operations.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the fixed file bundled with the plugin.
			$operations = json_decode( $catalog, true, 64, JSON_THROW_ON_ERROR );
		}
		return $operations;
	}

	/** Job definitions shared by Media Library, the editor and the worker. */
	public static function tasks(): array {
		return array(
			'image'     => array( __( 'Transform images', 'imazeo' ), 'TransformsController_create', 'image.transform', 'image', 'image' ),
			'pdf_info'  => array( __( 'Inspect a PDF', 'imazeo' ), 'PdfController_pdfInfo', 'pdf.info', 'pdf', 'metadata' ),
			'pdf_render' => array( __( 'Render PDF pages', 'imazeo' ), 'PdfController_pdfRender', 'pdf.render', 'pdf', 'pages' ),
			'pdf_merge' => array( __( 'Merge PDFs', 'imazeo' ), 'PdfController_pdfMerge', 'pdf.merge', 'pdf', 'pdf' ),
			'pdf_extract' => array( __( 'Extract PDF pages', 'imazeo' ), 'PdfController_pdfExtract', 'pdf.extract', 'pdf', 'pdf' ),
			'docx'      => array( __( 'Render a Word document', 'imazeo' ), 'ConversionsController_create', 'docx.render', 'docx', 'conversion' ),
			'xlsx'      => array( __( 'Render an Excel sheet', 'imazeo' ), 'ConversionsController_xlsx', 'xlsx.render', 'xlsx', 'conversion' ),
			'pptx'      => array( __( 'Render a PowerPoint slide', 'imazeo' ), 'ConversionsController_pptx', 'pptx.render', 'pptx', 'conversion' ),
			'website'   => array( __( 'Capture a website', 'imazeo' ), 'ConversionsController_website', 'website.capture', '', 'conversion' ),
			'qr'        => array( __( 'Generate a QR code', 'imazeo' ), 'CodesController_qr', 'code.qr', '', 'code' ),
			'barcode'   => array( __( 'Generate a barcode', 'imazeo' ), 'CodesController_barcode', 'code.barcode', '', 'code' ),
		);
	}

	/** Form options applicable to the chosen workflow; file data comes from WordPress. */
	public static function form( string $operation, bool $image_source = false ): array {
		$route = self::all()[ $operation ];
		$body = $route['body'];
		if ( $route['multipart'] ) {
			unset( $body['properties']['file'] );
			$body['required'] = array_values( array_diff( $body['required'] ?? array(), array( 'file' ) ) );
		}
		if ( $image_source ) {
			unset( $body['properties']['imageId'], $body['properties']['url'], $body['oneOf'] );
		}
		return array( 'query' => $route['query'], 'body' => $body );
	}

	/** Parse a request field set without trusting arbitrary parameter names or coercing false into true. */
	public static function fields( array $schema, mixed $raw ): mixed {
		$type = $schema['type'] ?? '';
		if ( isset( $schema['oneOf'] ) && '' === $type ) {
			foreach ( $schema['oneOf'] as $option ) {
				$value = self::fields( $option, $raw );
				if ( ! is_wp_error( $value ) ) {
					return $value;
				}
			}
			return self::invalid_field();
		}
		if ( 'object' === $type || isset( $schema['properties'] ) ) {
			if ( ! is_array( $raw ) ) {
				return self::invalid_field();
			}
			$value = array();
			$props = $schema['properties'] ?? array();
			foreach ( $raw as $key => $input ) {
				if ( ! isset( $props[ $key ] ) || '' === $input || self::blank( $input ) ) {
					continue;
				}
				$part = self::fields( $props[ $key ], $input );
				if ( is_wp_error( $part ) ) {
					return $part;
				}
				$value[ $key ] = $part;
			}
			return array_diff_key( array_fill_keys( $schema['required'] ?? array(), true ), $value ) ? self::invalid_field() : $value;
		}
		if ( 'boolean' === $type ) {
			$value = match ( $raw ) {
				true, 'true', '1' => true,
				false, 'false', '0' => false,
				default => null,
			};
			if ( null === $value ) {
				return self::invalid_field();
			}
		} elseif ( in_array( $type, array( 'integer', 'number' ), true ) ) {
			$value = filter_var( $raw, 'integer' === $type ? FILTER_VALIDATE_INT : FILTER_VALIDATE_FLOAT );
			if ( false === $value || ! is_finite( (float) $value ) || $value < ( $schema['minimum'] ?? -PHP_INT_MAX ) || $value > ( $schema['maximum'] ?? PHP_INT_MAX ) ) {
				return self::invalid_field();
			}
		} else {
			if ( ! is_string( $raw ) || ! preg_match( '//u', $raw ) || str_contains( $raw, "\0" )
				|| strlen( $raw ) > ( $schema['maxLength'] ?? 2048 ) || strlen( $raw ) < ( $schema['minLength'] ?? 0 )
				|| ( isset( $schema['pattern'] ) && 1 !== preg_match( '~' . $schema['pattern'] . '~D', $raw ) ) ) {
				return self::invalid_field();
			}
			$value = $raw;
		}
		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true )
			&& ! ( 'number' === $type && in_array( (int) $value, $schema['enum'], true ) && floor( $value ) === (float) $value ) ) {
			return self::invalid_field();
		}
		return $value;
	}

	/** Determine the credit ceiling for page selection before publishing a job. */
	public static function units( string $task, array $query ): int|WP_Error {
		if ( ! in_array( $task, array( 'pdf_render', 'pdf_extract' ), true ) ) {
			return 1;
		}
		$pages = $query['pages'] ?? '';
		$start = $query['rangeStart'] ?? null;
		$end   = $query['rangeEnd'] ?? null;
		$all   = $query['all'] ?? false;
		if ( ( '' !== $pages && ( $start || $end || $all ) ) || ( $all && ( $start || $end ) ) || ( null === $start ) !== ( null === $end ) || ( $start && $end < $start )
			|| ( 'pdf_extract' === $task && '' === $pages && ! $start ) ) {
			return self::invalid_field();
		}
		$count = 1;
		if ( '' !== $pages ) {
			$chosen = explode( ',', $pages );
			foreach ( $chosen as $page ) {
				if ( ! preg_match( '/\A[1-9]\d{0,2}\z/', $page ) || (int) $page > 100 ) {
					return self::invalid_field();
				}
			}
			$count = count( array_unique( $chosen ) );
			if ( $count !== count( $chosen ) ) {
				return self::invalid_field();
			}
		} elseif ( $start ) {
			$count = $end - $start + 1;
		} elseif ( $all ) {
			$count = self::RENDER_LIMIT;
		}
		return 'pdf_extract' === $task ? 1 : ( $count <= self::RENDER_LIMIT ? $count : self::invalid_field() );
	}

	/** Validate text without fetching the site from the WordPress host. */
	public static function url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] )
			|| ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$host = strtolower( trim( $parts['host'], '[].' ) );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE );
		}
		return str_contains( $host, '.' ) && 1 !== preg_match( '/\A[0-9.]+\z/', $host ) && ! str_ends_with( $host, '.local' ) && ! str_ends_with( $host, '.localhost' );
	}

	public static function invalid_field(): WP_Error {
		return new WP_Error( 'fields', __( 'Check the selected files, field values and page selection.', 'imazeo' ) );
	}

	private static function blank( mixed $input ): bool {
		return is_array( $input ) && ! array_filter( $input, static fn( $value ): bool => '' !== $value && ! self::blank( $value ) );
	}
}
