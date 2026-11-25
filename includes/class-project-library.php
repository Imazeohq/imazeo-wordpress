<?php
/**
 * Project-owned outputs, conversions and dynamic QR links.
 *
 * @package Imazeo
 */

namespace Imazeo\WordPress;

defined( 'ABSPATH' ) || exit;

final class Project_Library {
	public const SLUG = 'imazeo-project';
	private static array $result = array();

	public static function register(): void {
		add_action( 'admin_menu', static function (): void {
			$screen = add_media_page( __( 'Imazeo Project Library', 'imazeo' ), __( 'Imazeo Project Library', 'imazeo' ), 'manage_options', self::SLUG, array( self::class, 'page' ) );
			if ( $screen ) {
				add_action( 'load-' . $screen, array( self::class, 'load' ) );
			}
		} );
	}

	/** Project access is reserved for the site role that approved the connection. */
	public static function load(): void {
		if ( ! Workspace::is_post() ) {
			return;
		}
		Workspace::check_post( 'manage_options' );
		check_admin_referer( 'imazeo_project_library' );
		$op = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_text_field( wp_unslash( $_POST['operation'] ) ) : '';
		$id = isset( $_POST['object'] ) && is_string( $_POST['object'] ) ? sanitize_text_field( wp_unslash( $_POST['object'] ) ) : '';
		$upload = isset( $_POST['attachment'] ) && is_scalar( $_POST['attachment'] ) ? absint( $_POST['attachment'] ) : 0;
		$mode = isset( $_POST['destination'] ) && is_string( $_POST['destination'] ) ? sanitize_key( wp_unslash( $_POST['destination'] ) ) : 'download';
		$key = isset( $_POST['request_key'] ) && is_string( $_POST['request_key'] ) ? sanitize_text_field( wp_unslash( $_POST['request_key'] ) ) : '';
		$connection = Connection::current();
		$label = self::commands()[ $op ] ?? '';
		$routes = Operations::all();
		if ( ! $connection || ! $label || ! isset( $routes[ $op ] ) ) {
			self::error( __( 'Verify the Imazeo project connection first.', 'imazeo' ) );
			return;
		}
		$route = $routes[ $op ];
		$form = Operations::form( $op );
		$query = Operations::fields( $form['query'], wp_unslash( $_POST['query'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated against the production parameter schema.
		$raw_body = wp_unslash( $_POST['body'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below.
		$files = array();
		if ( $route['multipart'] ) {
			$files = Processing::reviewed_files( array( $upload ), get_current_user_id(), 'ImagesController_upload' === $op ? 'image' : 'any' );
			if ( is_wp_error( $files ) ) {
				self::error( $files->get_error_message() );
				return;
			}
			if ( 'ToolArtifactsController_save' === $op && is_array( $raw_body ) ) {
				$raw_body['contentType'] = reset( $files )['mime'];
			}
		}
		$body = Operations::fields( $form['body'] ?: array( 'type' => 'object' ), $raw_body );
		if ( is_wp_error( $query ) || is_wp_error( $body ) ) {
			self::error( __( 'Check the project fields and try again.', 'imazeo' ) );
			return;
		}
		$mutates = ! in_array( $route['method'], array( 'GET' ), true ) && ! in_array( $op, array( 'ProcessingController_lookup', 'ProcessingController_quote' ), true );
		$confirm = isset( $_POST['confirm'] ) && '1' === $_POST['confirm']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact comparison.
		if ( ( $mutates || 'import' === $mode ) && ! $confirm ) {
			self::error( __( 'Confirm the transfer or project change before continuing.', 'imazeo' ) );
			return;
		}
		if ( isset( $body['destination'] ) && ! Operations::url( $body['destination'] ) ) {
			self::error( __( 'Enter a valid public HTTP or HTTPS destination.', 'imazeo' ) );
			return;
		}
		$headers = array();
		if ( in_array( $op, array( 'DynamicCodesController_create', 'DynamicCodesController_edit' ), true ) ) {
			if ( ! Api::is_uuid( $key ) ) {
				self::error( __( 'Reload the form before modifying the dynamic QR link.', 'imazeo' ) );
				return;
			}
			$headers['Idempotency-Key'] = $key;
		}
		if ( 'TransformsController_content' === $op && isset( $_POST['etag'] ) && is_string( $_POST['etag'] ) ) {
			$tag = sanitize_text_field( wp_unslash( $_POST['etag'] ) );
			if ( preg_match( '/\A"[a-f0-9]{64}"\z/i', $tag ) ) {
				$headers['If-None-Match'] = $tag;
			}
		}
		if ( ( $mutates || 'import' === $mode ) && ( ! Api::is_uuid( $key ) || ! Queue::reserve_command( $key ) ) ) {
			self::error( __( 'This action was already submitted or the queue is busy. Check the project before repeating it.', 'imazeo' ) );
			return;
		}
		$result = ( new Api( $connection['key'] ) )->call( $op, $connection['project'], $id, $query, $body, $files, $headers );
		if ( is_wp_error( $result ) ) {
			self::error( $result->get_error_message() );
			return;
		}
		if ( isset( $result['bytes'] ) ) {
			$mime = Processing::result_mime( $result );
			if ( 'import' === $mode ) {
				$media = Media::import_result( array( 'id' => wp_generate_uuid4(), 'user' => get_current_user_id() ), $result['bytes'], $mime, $label );
				self::$result = is_wp_error( $media ) ? array( 'error' => $media->get_error_message() ) : array( 'media' => $media );
				return;
			}
			$ext = 'DynamicCodesController_csv' === $op ? 'csv' : ( Media::TYPES[ $mime ] ?? ( 'image/svg+xml' === $mime ? 'svg' : 'bin' ) );
			Workspace::attachment( $result['bytes'], 'imazeo-output.' . $ext );
		}
		self::$result = array( 'op' => $op, 'id' => $id, 'data' => $result['data'] ?? array(), 'meta' => $result['meta'] ?? array(), 'unchanged' => $result['unchanged'] ?? false );
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			Workspace::denied();
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Imazeo Project Library', 'imazeo' ) . '</h1>';
		if ( ! Connection::current() ) {
			echo '<p>' . esc_html__( 'Connect a project in Settings → Imazeo.', 'imazeo' ) . '</p></div>';
			return;
		}
		if ( isset( self::$result['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( self::$result['error'] ) . '</p></div>';
		} elseif ( isset( self::$result['media'] ) ) {
			echo '<div class="notice notice-success"><p><a href="' . esc_url( get_edit_post_link( self::$result['media'] ) ) . '">' . esc_html__( 'Open imported media', 'imazeo' ) . '</a></p></div>';
		} elseif ( ! empty( self::$result['unchanged'] ) ) {
			echo '<p>' . esc_html__( 'The project file is unchanged.', 'imazeo' ) . '</p>';
		} elseif ( isset( self::$result['op'] ) ) {
			self::render_result();
		}
		$groups = array(
			__( 'Project outputs', 'imazeo' ) => array( 'OutputsController_summary', 'ImagesController_list', 'ImagesController_upload', 'TransformsController_list', 'ToolArtifactsController_list', 'ToolArtifactsController_save' ),
			__( 'Conversion jobs', 'imazeo' ) => array( 'ConversionsController_capabilities', 'ConversionsController_list', 'ConversionsController_lookup', 'ProcessingController_lookup', 'ProcessingController_status' ),
			__( 'Dynamic QR links', 'imazeo' ) => array( 'DynamicCodesController_capabilities', 'DynamicCodesController_list', 'DynamicCodesController_create' ),
			__( 'Processing credits', 'imazeo' ) => array( 'ProcessingController_quote' ),
		);
		foreach ( $groups as $title => $ops ) {
			echo '<h2>' . esc_html( $title ) . '</h2>';
			foreach ( $ops as $op ) {
				self::form( $op );
			}
		}
		echo '</div>';
	}

	private static function render_result(): void {
		$op = self::$result['op'];
		$data = self::$result['data'];
		$id = self::$result['id'];
		echo '<h2>' . esc_html( self::commands()[ $op ] ) . '</h2>';
		if ( array_is_list( $data ) ) {
			if ( ! $data ) {
				echo '<p>' . esc_html__( 'There are no matching project records.', 'imazeo' ) . '</p>';
				return;
			}
			echo '<ul>';
			foreach ( $data as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$row_id = $row['id'] ?? $row['_id'] ?? '';
				if ( ! Api::is_uuid( $row_id ) ) {
					continue;
				}
				echo '<li><strong>' . esc_html( $row['name'] ?? $row['displayName'] ?? $row['originalName'] ?? $row_id ) . '</strong> ' . esc_html( $row['status'] ?? '' );
				foreach ( self::related( $op ) as $related ) {
					self::form( $related, $row_id, $row );
				}
				echo '</li>';
			}
			echo '</ul>';
			if ( isset( self::$result['meta']['page'], self::$result['meta']['pages'] ) ) {
				/* translators: 1: current page, 2: total pages. */
				echo '<p>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'imazeo' ), (int) self::$result['meta']['page'], (int) self::$result['meta']['pages'] ) ) . '</p>';
			}
			return;
		}
		unset( $data['requestSnapshot'], $data['recent'] );
		Workspace::render_values( $data );
		$object_id = $id ?: ( $data['id'] ?? $data['_id'] ?? '' );
		if ( Api::is_uuid( $object_id ) ) {
			foreach ( self::related( $op ) as $related ) {
				self::form( $related, $object_id, $data );
			}
			if ( in_array( $op, array( 'DynamicCodesController_detail', 'DynamicCodesController_create', 'DynamicCodesController_edit' ), true ) && is_string( $data['redirectUrl'] ?? null ) ) {
				echo '<p><a class="button" href="' . esc_url( Workspace::url( array( 'task' => 'qr', 'content' => $data['redirectUrl'] ) ) ) . '">' . esc_html__( 'Create a QR image for this link', 'imazeo' ) . '</a></p>';
			}
			if ( 'ImagesController_get' === $op ) {
				echo '<p><a class="button" href="' . esc_url( Workspace::url( array( 'task' => 'image', 'image' => $object_id ) ) ) . '">' . esc_html__( 'Transform this original', 'imazeo' ) . '</a></p>';
			}
		}
	}

	private static function form( string $op, string $id = '', array $data = array() ): void {
		$label = self::commands()[ $op ];
		$route = Operations::all()[ $op ];
		$form = Operations::form( $op );
		$mutates = 'GET' !== $route['method'] && ! in_array( $op, array( 'ProcessingController_lookup', 'ProcessingController_quote' ), true );
		$binary = in_array( $op, array( 'ImagesController_content', 'TransformsController_content', 'ToolArtifactsController_content', 'ConversionsController_download', 'ProcessingController_result' ), true );
		$can_import = $binary && 'image/svg+xml' !== ( $data['contentType'] ?? '' );
		$suffix = strtolower( $op ) . ( $id ? '-' . $id : '' );
		?>
		<details><summary><?php echo esc_html( $label ); ?></summary>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'page', self::SLUG, admin_url( 'upload.php' ) ) ); ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'imazeo_project_library' ) ); ?>">
			<input type="hidden" name="operation" value="<?php echo esc_attr( $op ); ?>">
			<input type="hidden" name="request_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
			<?php if ( str_contains( $route['path'], '{id}' ) ) : ?>
				<?php if ( $id ) : ?>
					<input type="hidden" name="object" value="<?php echo esc_attr( $id ); ?>">
				<?php else : ?>
					<p><label for="<?php echo esc_attr( $suffix ); ?>"><?php esc_html_e( 'Project record ID', 'imazeo' ); ?></label><br>
					<input name="object" class="regular-text" id="<?php echo esc_attr( $suffix ); ?>" required></p>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $route['multipart'] ) : ?>
				<p><label for="<?php echo esc_attr( $suffix . '-media' ); ?>"><?php esc_html_e( 'Media Library attachment ID', 'imazeo' ); ?></label><br>
				<input type="number" min="1" name="attachment" class="regular-text" id="<?php echo esc_attr( $suffix . '-media' ); ?>" required>
				<button type="button" class="button" data-imazeo-picker="<?php echo esc_attr( $suffix . '-media' ); ?>" data-imazeo-single="1"><?php esc_html_e( 'Choose media', 'imazeo' ); ?></button></p>
				<?php unset( $form['body']['properties']['contentType'] ); ?>
			<?php endif; ?>
			<?php if ( 'TransformsController_content' === $op ) : ?>
				<p><label for="<?php echo esc_attr( $suffix . '-etag' ); ?>"><?php esc_html_e( 'Previous ETag', 'imazeo' ); ?></label><br><input name="etag" id="<?php echo esc_attr( $suffix . '-etag' ); ?>"></p>
			<?php endif; ?>
			<?php if ( $can_import ) : ?>
				<p><label><input type="radio" name="destination" value="download" checked> <?php esc_html_e( 'Download privately', 'imazeo' ); ?></label><br>
				<label><input type="radio" name="destination" value="import"> <?php esc_html_e( 'Import into the Media Library', 'imazeo' ); ?></label></p>
			<?php endif; ?>
			<?php
			Workspace::render_fields( $form['query'], 'query', array(), $suffix );
			$defaults = isset( $data['revision'] ) ? array( 'expectedRevision' => $data['revision'] ) : array();
			if ( 'DynamicCodesController_edit' === $op && isset( $data['redirectUrl'] ) ) {
				$form['body']['properties']['qrOptions']['properties']['content']['description'] = __( 'Use this link as the content when saving QR styles. Leave the entire QR options section blank to keep the saved design.', 'imazeo' ) . ' ' . $data['redirectUrl'];
			}
			Workspace::render_fields( $form['body'], 'body', $defaults, $suffix );
			?>
			<?php if ( $mutates || $can_import ) : ?>
				<p><label><input type="checkbox" name="confirm" value="1">
					<?php echo esc_html( $mutates ? __( 'I authorize this project change or upload.', 'imazeo' ) : __( 'I authorize saving the result as a WordPress attachment if I selected import.', 'imazeo' ) ); ?>
				</label></p>
			<?php endif; ?>
			<?php submit_button( $label, 'secondary' ); ?>
		</form></details>
		<?php
	}

	private static function related( string $op ): array {
		return match ( $op ) {
			'ImagesController_list' => array( 'ImagesController_get', 'ImagesController_content' ),
			'ImagesController_get' => array( 'ImagesController_content', 'ImagesController_remove' ),
			'TransformsController_list' => array( 'TransformsController_get', 'TransformsController_content' ),
			'TransformsController_get' => array( 'TransformsController_content', 'TransformsController_remove' ),
			'ToolArtifactsController_list' => array( 'ToolArtifactsController_get', 'ToolArtifactsController_content' ),
			'ToolArtifactsController_get' => array( 'ToolArtifactsController_content', 'ToolArtifactsController_remove' ),
			'ConversionsController_list' => array( 'ConversionsController_get' ),
			'ConversionsController_get', 'ConversionsController_lookup' => array( 'ConversionsController_download', 'ConversionsController_save', 'ConversionsController_cancel' ),
			'ProcessingController_lookup', 'ProcessingController_status' => array( 'ProcessingController_result' ),
			'DynamicCodesController_list' => array( 'DynamicCodesController_detail' ),
			'DynamicCodesController_detail', 'DynamicCodesController_edit', 'DynamicCodesController_create' => array( 'DynamicCodesController_edit', 'DynamicCodesController_analytics', 'DynamicCodesController_csv' ),
			default => array(),
		};
	}

	/** Fixed list of project controls exposed to site administrators. */
	public static function commands(): array {
		return array(
			'OutputsController_summary' => __( 'View output totals', 'imazeo' ),
			'ImagesController_list' => __( 'Find uploaded originals', 'imazeo' ),
			'ImagesController_upload' => __( 'Upload an original to the Imazeo project', 'imazeo' ),
			'ImagesController_get' => __( 'View original details', 'imazeo' ),
			'ImagesController_content' => __( 'Download or import the original', 'imazeo' ),
			'ImagesController_remove' => __( 'Delete the project original', 'imazeo' ),
			'TransformsController_list' => __( 'Browse image transformations', 'imazeo' ),
			'TransformsController_get' => __( 'View transformation details', 'imazeo' ),
			'TransformsController_content' => __( 'Download or import the transformation', 'imazeo' ),
			'TransformsController_remove' => __( 'Delete the project transformation', 'imazeo' ),
			'ToolArtifactsController_list' => __( 'Find saved outputs', 'imazeo' ),
			'ToolArtifactsController_save' => __( 'Save an attachment in the Imazeo project', 'imazeo' ),
			'ToolArtifactsController_get' => __( 'View saved output details', 'imazeo' ),
			'ToolArtifactsController_content' => __( 'Download or import the saved output', 'imazeo' ),
			'ToolArtifactsController_remove' => __( 'Delete the saved project output', 'imazeo' ),
			'ConversionsController_capabilities' => __( 'View available conversion engines', 'imazeo' ),
			'ConversionsController_list' => __( 'Browse conversion jobs', 'imazeo' ),
			'ConversionsController_lookup' => __( 'Find a conversion by request key', 'imazeo' ),
			'ConversionsController_get' => __( 'View conversion status', 'imazeo' ),
			'ConversionsController_save' => __( 'Save the conversion in the Imazeo project', 'imazeo' ),
			'ConversionsController_cancel' => __( 'Cancel the conversion', 'imazeo' ),
			'ConversionsController_download' => __( 'Download or import the conversion', 'imazeo' ),
			'ProcessingController_quote' => __( 'Request a processing quote', 'imazeo' ),
			'ProcessingController_lookup' => __( 'Find a processing operation by request key', 'imazeo' ),
			'ProcessingController_status' => __( 'View processing status by operation ID', 'imazeo' ),
			'ProcessingController_result' => __( 'Retrieve the retained result', 'imazeo' ),
			'DynamicCodesController_capabilities' => __( 'View dynamic QR availability', 'imazeo' ),
			'DynamicCodesController_list' => __( 'Find dynamic QR links', 'imazeo' ),
			'DynamicCodesController_create' => __( 'Create a dynamic QR link', 'imazeo' ),
			'DynamicCodesController_detail' => __( 'View dynamic QR details', 'imazeo' ),
			'DynamicCodesController_edit' => __( 'Edit the dynamic QR link', 'imazeo' ),
			'DynamicCodesController_analytics' => __( 'View scan analytics', 'imazeo' ),
			'DynamicCodesController_csv' => __( 'Download scan analytics CSV', 'imazeo' ),
		);
	}

	private static function error( string $message ): void {
		self::$result = array( 'error' => $message );
	}
}
