<?php
/**
 * Media creation and review screens.
 *
 * @package Imazeo
 */

namespace Imazeo\WordPress;

defined( 'ABSPATH' ) || exit;

final class Workspace {
	public const SLUG = 'imazeo-create';

	public static function register(): void {
		add_action( 'admin_menu', static function (): void {
			add_media_page( __( 'Create with Imazeo', 'imazeo' ), __( 'Create with Imazeo', 'imazeo' ), 'upload_files', self::SLUG, array( self::class, 'page' ) );
		} );
		add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
			if ( 'media_page_' . self::SLUG === $hook || 'media_page_imazeo-project' === $hook ) {
				wp_enqueue_media();
				wp_enqueue_script( 'imazeo-media-picker', plugins_url( 'assets/media-picker.js', dirname( __DIR__ ) . '/imazeo.php' ), array( 'media-editor' ), '1.1.0', true );
			}
		} );
		add_action( 'admin_post_imazeo_review_media', array( self::class, 'review' ) );
		add_action( 'admin_post_imazeo_queue_media', array( self::class, 'enqueue' ) );
		add_action( 'admin_post_imazeo_private_result', array( self::class, 'download' ) );
	}

	public static function url( array $query = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + $query, admin_url( 'upload.php' ) );
	}

	public static function page(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			self::denied();
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Create with Imazeo', 'imazeo' ) . '</h1>';
		$connection = Connection::current();
		if ( ! $connection ) {
			echo '<p>' . esc_html__( 'A site administrator must connect an Imazeo project in Settings → Imazeo.', 'imazeo' ) . '</p></div>';
			return;
		}
		// These parameters only choose a read-only panel.
		$task = isset( $_GET['task'] ) && is_string( $_GET['task'] ) ? sanitize_key( wp_unslash( $_GET['task'] ) ) : 'image'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['review'] ) && is_string( $_GET['review'] ) ? sanitize_text_field( wp_unslash( $_GET['review'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$failure = isset( $_GET['message'] ) && is_string( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $failure ) {
			echo '<div class="notice notice-error"><p>' . esc_html( self::message( $failure ) ) . '</p></div>';
		}
		if ( $token ) {
			self::confirmation( $connection, $token );
			echo '</div>';
			return;
		}
		$tasks = Operations::tasks();
		$task = isset( $tasks[ $task ] ) ? $task : 'image';
		$spec = $tasks[ $task ];
		$selection = isset( $_GET['ids'] ) && is_string( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ids = self::ids( $selection );
		if ( $spec[3] && $selection && ( ! $ids || array_filter( $ids, static fn( int $id ): bool => ! Media::eligible( $id, get_current_user_id(), $spec[3] ) ) ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Choose up to 10 editable attachments of the required type. The previous selection was cleared.', 'imazeo' ) . '</p></div>';
			$ids = array();
		}
		$content = isset( $_GET['content'] ) && is_string( $_GET['content'] ) ? esc_url_raw( wp_unslash( $_GET['content'] ), array( 'http', 'https' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$image_id = isset( $_GET['image'] ) && is_string( $_GET['image'] ) ? sanitize_text_field( wp_unslash( $_GET['image'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$target = isset( $_GET['target'] ) && is_string( $_GET['target'] ) ? absint( $_GET['target'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Media tools', 'imazeo' ); ?>">
		<?php foreach ( $tasks as $key => $item ) : ?>
			<a class="nav-tab <?php echo $key === $task ? 'nav-tab-active' : ''; ?>" aria-current="<?php echo $key === $task ? 'page' : 'false'; ?>" href="<?php echo esc_url( self::url( array( 'task' => $key, 'ids' => implode( ',', $ids ) ) ) ); ?>"><?php echo esc_html( $item[0] ); ?></a>
		<?php endforeach; ?>
		</nav>
		<h2><?php echo esc_html( $spec[0] ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="imazeo_review_media">
			<input type="hidden" name="imazeo_task" value="<?php echo esc_attr( $task ); ?>">
			<?php if ( 'image' === $task && Featured_Media::target( $target ) ) : ?>
				<input type="hidden" name="imazeo_target" value="<?php echo esc_attr( (string) $target ); ?>">
			<?php endif; ?>
			<?php wp_nonce_field( 'imazeo_review_media' ); ?>
			<?php if ( $spec[3] ) : ?>
				<p><label for="imazeo-attachments"><?php esc_html_e( 'Media Library attachment IDs in order', 'imazeo' ); ?></label><br>
					<input class="regular-text" id="imazeo-attachments" name="imazeo_ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
					<button type="button" class="button" data-imazeo-picker="imazeo-attachments" data-imazeo-family="<?php echo esc_attr( $spec[3] ); ?>" <?php echo in_array( $task, array( 'image', 'pdf_merge' ), true ) ? '' : 'data-imazeo-single="1"'; ?>><?php esc_html_e( 'Choose media', 'imazeo' ); ?></button>
				</p>
				<?php if ( 'image' === $task ) : ?>
					<p><label for="imazeo-remote"><?php esc_html_e( 'Or use a public image URL', 'imazeo' ); ?></label><br><input class="regular-text" type="url" id="imazeo-remote" name="imazeo_remote" maxlength="2048"></p>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<p><label for="imazeo-project-source"><?php esc_html_e( 'Or reuse an original from this project', 'imazeo' ); ?></label><br><input class="regular-text" id="imazeo-project-source" name="imazeo_project_source" value="<?php echo esc_attr( Api::is_uuid( $image_id ) ? $image_id : '' ); ?>"></p>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>
			<?php
			$fields = Operations::form( $spec[1], 'image' === $task );
			self::render_fields( $fields['body'], 'body', array( 'content' => $content ) );
			if ( ! empty( $fields['query']['properties'] ) ) {
				echo '<details><summary>' . esc_html__( 'Processing options', 'imazeo' ) . '</summary>';
				self::render_fields( $fields['query'], 'query' );
				echo '</details>';
			}
			submit_button( __( 'Review processing credits', 'imazeo' ) );
			?>
		</form></div>
		<?php
	}

	public static function label( string $name ): string {
		return match ( $name ) {
			'url' => __( 'Public URL', 'imazeo' ),
			'id' => __( 'ID', 'imazeo' ),
			'key' => __( 'Request key', 'imazeo' ),
			'maxOutputBytes' => __( 'Maximum output size in bytes', 'imazeo' ),
			'mode' => __( 'Mode', 'imazeo' ),
			'all' => __( 'Render up to the first ten pages', 'imazeo' ),
			'pages' => __( 'Page numbers', 'imazeo' ),
			'qrOptions' => __( 'QR style options', 'imazeo' ),
			'stripMetadata' => __( 'Strip metadata', 'imazeo' ),
			'flip' => __( 'Mirror vertically', 'imazeo' ),
			'flop' => __( 'Mirror horizontally', 'imazeo' ),
			default => ucfirst( strtolower( (string) preg_replace( '/(?<!^)([A-Z])/', ' $1', $name ) ) ),
		};
	}

	/** Present structured request settings and project results in their original hierarchy. */
	public static function render_values( array $data ): void {
		echo '<dl>';
		foreach ( $data as $key => $value ) {
			if ( null === $value || array() === $value ) {
				continue;
			}
			echo '<dt><strong>' . esc_html( is_int( $key ) ? (string) ( $key + 1 ) : self::label( $key ) ) . '</strong></dt><dd>';
			if ( is_array( $value ) ) {
				self::render_values( $value );
			} else {
				echo esc_html( is_bool( $value ) ? ( $value ? __( 'Yes', 'imazeo' ) : __( 'No', 'imazeo' ) ) : (string) $value );
			}
			echo '</dd>';
		}
		echo '</dl>';
	}

	/** Render only known fields, with optional style groups collapsed. */
	public static function render_fields( array $schema, string $prefix, array $defaults = array(), string $scope = 'imazeo' ): void {
		foreach ( $schema['properties'] ?? array() as $name => $field ) {
			$key = $prefix . '[' . $name . ']';
			$id = sanitize_html_class( $scope . '-' . str_replace( array( '[', ']' ), array( '-', '' ), $key ) );
			$label = self::label( $name );
			$default = $defaults[ $name ] ?? '';
			$type = $field['type'] ?? '';
			if ( isset( $field['oneOf'] ) && '' === $type ) {
				// The numeric-trim form and the boolean shorthand are equivalent options on the API.
				$field = $field['oneOf'][1] ?? $field['oneOf'][0];
				$type = $field['type'] ?? '';
			}
			if ( 'object' === $type ) {
				echo '<details><summary>' . esc_html( $label ) . '</summary><fieldset>';
				if ( ! empty( $field['description'] ) ) {
					echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
				}
				self::render_fields( $field, $key, is_array( $default ) ? $default : array(), $scope );
				echo '</fieldset></details>';
				continue;
			}
			echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><br>';
			if ( isset( $field['enum'] ) || 'boolean' === $type ) {
				echo '<select name="' . esc_attr( $key ) . '" id="' . esc_attr( $id ) . '"><option value="">' . esc_html__( 'Default', 'imazeo' ) . '</option>';
				$options = 'boolean' === $type && ! isset( $field['enum'] )
					? array( 'true' => __( 'Yes', 'imazeo' ), 'false' => __( 'No', 'imazeo' ) )
					: array_combine( array_map( static fn( $v ) => true === $v ? 'true' : (string) $v, $field['enum'] ), $field['enum'] );
				foreach ( $options as $value => $text ) {
					echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( is_bool( $default ) ? ( $default ? 'true' : 'false' ) : $default, $value, false ) . '>' . esc_html( true === $text ? __( 'Yes', 'imazeo' ) : (string) $text ) . '</option>';
				}
				echo '</select>';
			} elseif ( in_array( $type, array( 'integer', 'number' ), true ) ) {
				printf( '<input id="%s" name="%s" type="number" step="%s" value="%s"', esc_attr( $id ), esc_attr( $key ), esc_attr( 'integer' === $type ? '1' : 'any' ), esc_attr( (string) $default ) );
				foreach ( array( 'minimum' => 'min', 'maximum' => 'max' ) as $prop => $attribute ) {
					if ( isset( $field[ $prop ] ) ) {
						echo ' ' . esc_attr( $attribute ) . '="' . esc_attr( (string) $field[ $prop ] ) . '"';
					}
				}
				echo '>';
			} elseif ( 'content' === $name ) {
				printf( '<textarea id="%s" name="%s" class="large-text" maxlength="%d" rows="2">%s</textarea>', esc_attr( $id ), esc_attr( $key ), (int) ( $field['maxLength'] ?? 2048 ), esc_textarea( (string) $default ) );
			} else {
				printf( '<input id="%s" name="%s" class="regular-text" maxlength="%d" value="%s">', esc_attr( $id ), esc_attr( $key ), (int) ( $field['maxLength'] ?? 2048 ), esc_attr( (string) $default ) );
			}
			if ( ! empty( $field['description'] ) ) {
				echo '<br><span class="description">' . esc_html( $field['description'] ) . '</span>';
			}
			echo '</p>';
		}
	}

	public static function review(): void {
		self::check_post( 'upload_files' );
		check_admin_referer( 'imazeo_review_media' );
		$user = get_current_user_id();
		$task = isset( $_POST['imazeo_task'] ) && is_string( $_POST['imazeo_task'] ) ? sanitize_key( wp_unslash( $_POST['imazeo_task'] ) ) : '';
		$spec = Operations::tasks()[ $task ] ?? null;
		$connection = Connection::current();
		$url = self::url( array( 'task' => $task ) );
		if ( ! $spec || ! $connection ) {
			self::back( $url, 'connection' );
		}
		$fields = Operations::form( $spec[1], 'image' === $task );
		// Fields are constrained and validated recursively against the fixed production schema.
		$body = Operations::fields( $fields['body'] ?: array( 'type' => 'object' ), wp_unslash( $_POST['body'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$query = Operations::fields( $fields['query'], wp_unslash( $_POST['query'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_wp_error( $body ) || is_wp_error( $query ) ) {
			self::back( $url, 'fields' );
		}
		$ids = isset( $_POST['imazeo_ids'] ) && is_string( $_POST['imazeo_ids'] ) ? self::ids( sanitize_text_field( wp_unslash( $_POST['imazeo_ids'] ) ) ) : array();
		$target = isset( $_POST['imazeo_target'] ) && is_string( $_POST['imazeo_target'] ) ? absint( $_POST['imazeo_target'] ) : 0;
		$remote = isset( $_POST['imazeo_remote'] ) && is_string( $_POST['imazeo_remote'] ) ? esc_url_raw( wp_unslash( $_POST['imazeo_remote'] ), array( 'http', 'https' ) ) : '';
		$project_source = isset( $_POST['imazeo_project_source'] ) && is_string( $_POST['imazeo_project_source'] ) ? sanitize_text_field( wp_unslash( $_POST['imazeo_project_source'] ) ) : '';
		if ( 'image' === $task ) {
			if ( (int) (bool) $ids + (int) (bool) $remote + (int) (bool) $project_source !== 1
				|| ( $remote && ! Operations::url( $remote ) ) || ( $project_source && ( ! current_user_can( 'manage_options' ) || ! Api::is_uuid( $project_source ) ) ) ) {
				self::back( $url, 'fields' );
			}
			if ( $remote ) {
				$body['url'] = $remote;
			} elseif ( $project_source ) {
				$body['imageId'] = $project_source;
			}
		} elseif ( $remote || $project_source || ( 'website' === $task && ! Operations::url( $body['url'] ?? '' ) )
			|| ( $spec[3] && ( 'pdf_merge' === $task ? ! $ids : 1 !== count( $ids ) ) ) || ( ! $spec[3] && $ids ) ) {
			self::back( $url, 'fields' );
		}
		$units = Operations::units( $task, $query );
		if ( is_wp_error( $units ) ) {
			self::back( $url, 'fields' );
		}
		$groups = 'image' === $task && $ids ? array_chunk( $ids, 1 ) : array( $ids );
		$group_hashes = array();
		foreach ( $groups as $group ) {
			$files = Processing::reviewed_files( $group, $user, $spec[3] );
			if ( is_wp_error( $files ) ) {
				self::back( $url, $files->get_error_code() );
			}
			$group_hashes[] = array_column( $files, 'hash', null );
			unset( $files );
		}
		$quote = ( new Api( $connection['key'] ) )->quote( $connection['project'], $spec[2], $units, (bool) $remote );
		if ( is_wp_error( $quote ) ) {
			self::back( $url, $quote->get_error_code() );
		}
		$jobs = array();
		foreach ( $groups as $i => $group ) {
			$jobs[] = array(
				'task' => $task, 'source' => $group[0] ?? 0, 'ids' => $group, 'target' => 'image' === $task && Featured_Media::target( $target ) ? $target : 0,
				'hashes' => array_combine( $group, $group_hashes[ $i ] ),
				'query' => $query, 'body' => $body, 'units' => $units, 'ceiling' => $quote['credits'],
				'remote_source' => (bool) $remote, 'project_source' => (bool) $project_source,
				'fingerprint' => hash( 'sha256', wp_json_encode( array( $connection['generation'], $task, $group, $group_hashes[ $i ], $query, $body ) ) ),
			);
		}
		$token = wp_generate_uuid4();
		if ( ! Queue::store_review( $user, array(
			'token' => $token, 'project' => $connection['project'], 'generation' => $connection['generation'],
			'expires' => strtotime( $quote['expiresAt'] ), 'tasks' => $jobs,
		) ) ) {
			self::back( $url, 'queue' );
		}
		self::back( self::url( array( 'review' => $token ) ) );
	}

	private static function confirmation( array $connection, string $token ): void {
		$review = Queue::review( get_current_user_id(), $token );
		if ( ! $review || ! isset( $review['tasks'] ) || $connection['generation'] !== $review['generation'] ) {
			echo '<p>' . esc_html__( 'That media review has expired.', 'imazeo' ) . '</p>';
			return;
		}
		$jobs = $review['tasks'];
		?>
		<h2><?php esc_html_e( 'Approve processing', 'imazeo' ); ?></h2>
		<p><?php
		/* translators: %s: maximum approved credit cost. */
		printf( esc_html__( 'Maximum cost: %s processing credits.', 'imazeo' ), esc_html( number_format_i18n( ceil( array_sum( array_column( $jobs, 'ceiling' ) ) * 100 ) / 100, 2 ) ) );
		?></p>
		<ul><?php foreach ( $jobs as $job ) : ?>
			<li><?php echo esc_html( Operations::tasks()[ $job['task'] ][0] ); ?>
				<?php foreach ( $job['ids'] as $id ) : ?>
					<?php if ( user_can( get_current_user_id(), 'edit_post', $id ) ) : ?>
						<span><?php echo esc_html( get_the_title( $id ) ); ?></span>
					<?php endif; ?>
				<?php endforeach; ?>
			</li>
		<?php endforeach; ?></ul>
		<?php if ( $jobs[0]['query'] || $jobs[0]['body'] ) : ?>
			<details open><summary><?php esc_html_e( 'Review request settings', 'imazeo' ); ?></summary>
				<?php self::render_values( $jobs[0]['body'] + $jobs[0]['query'] ); ?>
			</details>
		<?php endif; ?>
		<p><?php esc_html_e( 'Approval sends the selected files, URLs or text and processing options to Imazeo. Results that you import are stored in the WordPress Media Library.', 'imazeo' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="imazeo_queue_media">
			<input type="hidden" name="imazeo_review" value="<?php echo esc_attr( $token ); ?>">
			<?php wp_nonce_field( 'imazeo_queue_media' ); ?>
			<?php submit_button( __( 'Approve and queue', 'imazeo' ) ); ?>
		</form>
		<?php
	}

	public static function enqueue(): void {
		self::check_post( 'upload_files' );
		check_admin_referer( 'imazeo_queue_media' );
		$token = isset( $_POST['imazeo_review'] ) && is_string( $_POST['imazeo_review'] ) ? sanitize_text_field( wp_unslash( $_POST['imazeo_review'] ) ) : '';
		$user = get_current_user_id();
		$connection = Connection::current();
		$review = Queue::review( $user, $token );
		if ( ! $connection || empty( $review['tasks'] ) || ! Lifecycle::schedule() ) {
			self::back( self::url(), 'queue' );
		}
		foreach ( $review['tasks'] as $job ) {
			foreach ( $job['ids'] as $id ) {
				if ( ! Media::eligible( $id, $user, Operations::tasks()[ $job['task'] ][3] ) ) {
					self::back( self::url(), 'attachment' );
				}
			}
		}
		$result = Queue::enqueue( $user, $token, $connection['generation'] );
		if ( is_wp_error( $result ) ) {
			self::back( self::url(), $result->get_error_code() );
		}
		self::back( admin_url( 'upload.php?page=imazeo-media&imazeo_notice=queued' ) );
	}

	public static function download(): void {
		self::check_post( 'upload_files' );
		check_admin_referer( 'imazeo_private_result' );
		$id = isset( $_POST['imazeo_job'] ) && is_string( $_POST['imazeo_job'] ) ? sanitize_text_field( wp_unslash( $_POST['imazeo_job'] ) ) : '';
		$job = Queue::state()['jobs'][ $id ] ?? null;
		if ( ! Api::is_uuid( $id ) || ! $job || ! Queue::visible( $job, get_current_user_id() ) ) {
			self::denied();
		}
		$bytes = get_transient( Processing::PRIVATE_PREFIX . $id );
		if ( 'svg' !== ( $job['private'] ?? '' ) || ! is_string( $bytes ) ) {
			wp_die( esc_html__( 'This private download expired.', 'imazeo' ), '', array( 'response' => 404 ) );
		}
		self::attachment( $bytes, 'imazeo-result.svg' );
	}

	public static function attachment( string $bytes, string $filename ): never {
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Private attachment response, served as octet-stream with nosniff.
		exit;
	}

	public static function check_post( string $capability ): void {
		if ( ! current_user_can( $capability ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::denied();
		}
	}

	public static function denied(): never {
		wp_die( esc_html__( 'You do not have access to this operation.', 'imazeo' ), '', array( 'response' => 403 ) );
		exit;
	}

	public static function ids( string $input ): array {
		$ids = strlen( $input ) <= 240 && preg_match( '/\A[1-9]\d*(?:,[1-9]\d*)*\z/', $input ) ? wp_parse_id_list( $input ) : array();
		return count( $ids ) <= Queue::MAX_BATCH ? $ids : array();
	}

	public static function back( string $url, string $message = '' ): never {
		wp_safe_redirect( $message ? add_query_arg( 'message', $message, $url ) : $url, 303 );
		exit;
	}

	private static function message( string $issue ): string {
		return match ( $issue ) {
			'connection' => __( 'Connect an Imazeo project before continuing.', 'imazeo' ),
			'credit_insufficient' => __( 'The project has insufficient processing credits.', 'imazeo' ),
			'file', 'type', 'attachment', 'size' => __( 'Choose editable local files of the requested type within the transfer limit.', 'imazeo' ),
			'fields' => __( 'Check the selected files, field values and page selection.', 'imazeo' ),
			'duplicate' => __( 'This media operation is already queued.', 'imazeo' ),
			default => __( 'Imazeo could not prepare this job. Check your project and queue before retrying.', 'imazeo' ),
		};
	}
}
