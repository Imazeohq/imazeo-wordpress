<?php
/**
 * Settings and Media Library integration.
 *
 * @package Imazeo
 */

namespace Imazeo\WordPress;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/** Native admin screens and explicitly authorized POST actions. */
final class Admin {
	private const SETTINGS = 'imazeo';
	private const MEDIA = 'imazeo-media';

	/** Register admin-only screens, entry points and actions. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menus' ) );
		add_action( 'admin_init', array( self::class, 'privacy' ) );
		add_filter( 'media_row_actions', array( self::class, 'media_action' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( self::class, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( self::class, 'bulk_redirect' ), 10, 3 );
		add_action( 'add_meta_boxes_attachment', array( self::class, 'attachment_box' ) );
		foreach ( array( 'connection', 'disconnect', 'enqueue', 'cancel' ) as $action ) {
			add_action( 'admin_post_imazeo_' . $action, array( self::class, $action ) );
		}
	}

	/** Register Settings and Media submenus. */
	public static function menus(): void {
		add_options_page( __( 'Imazeo', 'imazeo' ), __( 'Imazeo', 'imazeo' ), 'manage_options', self::SETTINGS, array( self::class, 'settings' ) );
		add_media_page( __( 'Imazeo Processing Queue', 'imazeo' ), __( 'Imazeo Processing Queue', 'imazeo' ), 'upload_files', self::MEDIA, array( self::class, 'media' ) );
	}

	/** Register privacy policy text on the WordPress privacy administration screen. */
	public static function privacy(): void {
		wp_add_privacy_policy_content(
			'Imazeo',
			'<p>' . esc_html__( 'When an administrator connects Imazeo, this site sends the configured project API key to Imazeo. Approving a job can send files, URLs, text and processing choices. Uploaded originals and saved outputs may be retained in the Imazeo project. WordPress stores the imported media and limited job history. Dynamic QR links use Imazeo redirects and can collect scan analytics when enabled.', 'imazeo' )
			. ' <a href="https://imazeo.com/legal/privacy/">' . esc_html__( 'Imazeo privacy notice', 'imazeo' ) . '</a></p>'
		);
	}

	/** Display credential settings without returning a stored key to the browser. */
	public static function settings(): void {
		self::require_cap( 'manage_options' );
		$connection = Connection::current();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Imazeo', 'imazeo' ); ?></h1>
			<?php self::notice(); ?>
			<p><?php esc_html_e( 'Connect an Imazeo project to process and manage media from WordPress.', 'imazeo' ); ?></p>
			<?php if ( $connection ) : ?>
				<p><?php
				/* translators: %s: verified Imazeo project ID. */
				printf( esc_html__( 'Connected to project %s.', 'imazeo' ), esc_html( $connection['project'] ) );
				?></p>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Project connection', 'imazeo' ); ?></h2>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="imazeo_connection">
				<?php wp_nonce_field( 'imazeo_connection' ); ?>
				<?php if ( defined( 'IMAZEO_API_KEY' ) ) : ?>
					<p><?php esc_html_e( 'WordPress configuration supplies the key. Connect to verify it and authorize use on this site.', 'imazeo' ); ?></p>
				<?php else : ?>
					<p><label for="imazeo-key"><?php esc_html_e( 'Imazeo project API key', 'imazeo' ); ?></label><br>
					<input id="imazeo-key" class="regular-text" type="password" name="imazeo_key" autocomplete="new-password" maxlength="84" required></p>
					<p><a href="https://app.imazeo.com/"><?php esc_html_e( 'Manage project keys in Imazeo', 'imazeo' ); ?></a></p>
				<?php endif; ?>
				<p><label><input type="checkbox" name="imazeo_consent" value="1" required>
					<?php esc_html_e( 'I authorize this site to contact Imazeo. Users with media upload permission can send processing requests and spend project credits after review. They must also be able to edit any attachments they send.', 'imazeo' ); ?>
				</label></p>
				<p><?php esc_html_e( 'Imazeo may retain uploaded originals in the project. Review its service terms and privacy notice before connecting.', 'imazeo' ); ?>
					<a href="https://imazeo.com/legal/terms/"><?php esc_html_e( 'Service terms', 'imazeo' ); ?></a> ·
					<a href="https://imazeo.com/legal/privacy/"><?php esc_html_e( 'Privacy notice', 'imazeo' ); ?></a>
				</p>
				<?php submit_button( $connection ? __( 'Verify and update connection', 'imazeo' ) : __( 'Connect project', 'imazeo' ) ); ?>
			</form>
			<?php if ( get_option( Connection::OPTION ) ) : ?>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="imazeo_disconnect">
					<?php wp_nonce_field( 'imazeo_disconnect' ); ?>
					<?php submit_button( __( 'Disconnect Imazeo', 'imazeo' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Add a row action to eligible images in the Media Library list. */
	public static function media_action( array $actions, WP_Post $post ): array {
		if ( Media::eligible( $post->ID, get_current_user_id(), 'any' ) ) {
			$kind = self::attachment_task( $post );
			$actions['imazeo'] = '<a href="' . esc_url( Workspace::url( array( 'task' => $kind, 'ids' => $post->ID ) ) ) . '">' . esc_html__( 'Process with Imazeo', 'imazeo' ) . '</a>';
		}
		return $actions;
	}

	/** Add the bulk review entry point. */
	public static function bulk_action( array $actions ): array {
		$actions['imazeo_media'] = __( 'Process with Imazeo', 'imazeo' );
		return $actions;
	}

	/** Go to review after the core Media Library nonce check. */
	public static function bulk_redirect( string $redirect, string $action, array $ids ): string {
		$first = $ids ? get_post( (int) $ids[0] ) : null;
		return 'imazeo_media' === $action
			? Workspace::url( array( 'task' => $first instanceof WP_Post ? self::attachment_task( $first, count( $ids ) > 1 ) : 'image', 'ids' => implode( ',', array_map( 'absint', array_slice( $ids, 0, Queue::MAX_BATCH + 1 ) ) ) ) )
			: $redirect;
	}

	/** Add the same workflow to the attachment editor. */
	public static function attachment_box( WP_Post $post ): void {
		if ( ! Media::eligible( $post->ID, get_current_user_id(), 'any' ) ) {
			return;
		}
		add_meta_box(
			'imazeo-media',
			__( 'Imazeo', 'imazeo' ),
			static function ( WP_Post $post ): void {
				printf(
					'<a class="button" href="%s">%s</a>',
					esc_url( Workspace::url( array( 'task' => self::attachment_task( $post ), 'ids' => $post->ID ) ) ),
					esc_html__( 'Process this attachment', 'imazeo' )
				);
			},
			'attachment',
			'side'
		);
	}

	private static function attachment_task( WP_Post $post, bool $bulk = false ): string {
		return match ( Media::TYPES[ $post->post_mime_type ] ?? '' ) {
			'pdf' => $bulk ? 'pdf_merge' : 'pdf_render',
			'docx' => 'docx',
			'xlsx' => 'xlsx',
			'pptx' => 'pptx',
			default => 'image',
		};
	}

	/** Display the queue and allow existing WebP reviews to finish after an update. */
	public static function media(): void {
		self::require_cap( 'upload_files' );
		$user = get_current_user_id();
		// Navigation parameters only select views. All remote and state-changing actions require POST and nonces.
		$token = isset( $_GET['review'] ) && is_string( $_GET['review'] ) ? sanitize_text_field( wp_unslash( $_GET['review'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$connection = Connection::current();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Imazeo Processing Queue', 'imazeo' ); ?></h1>
			<?php self::notice(); ?>
			<?php if ( ! $connection ) : ?>
				<p><?php esc_html_e( 'A site administrator can connect an Imazeo project to process media.', 'imazeo' ); ?>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<a href="<?php echo esc_url( self::settings_url() ); ?>"><?php esc_html_e( 'Open Imazeo settings', 'imazeo' ); ?></a>
				<?php endif; ?></p>
			<?php elseif ( $token ) : ?>
				<?php self::confirmation( $user, $token, $connection['generation'] ); ?>
			<?php else : ?>
				<p><a class="button" href="<?php echo esc_url( Workspace::url() ); ?>"><?php esc_html_e( 'Create with Imazeo', 'imazeo' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'upload.php?mode=list' ) ); ?>"><?php esc_html_e( 'Open Media Library', 'imazeo' ); ?></a></p>
			<?php endif; ?>
			<?php self::history( $user ); ?>
		</div>
		<?php
	}

	private static function confirmation( int $user, string $token, string $generation ): void {
		$review = Queue::review( $user, $token );
		if ( ! $review || $review['generation'] !== $generation || array_filter( array_keys( $review['files'] ), static fn( int $id ): bool => ! Media::eligible( $id, $user ) ) ) {
			echo '<p>' . esc_html__( 'This review expired or its permissions changed. Select the images again.', 'imazeo' ) . '</p>';
			return;
		}
		$count = count( $review['files'] );
		?>
		<h2><?php esc_html_e( 'Approve WebP copies', 'imazeo' ); ?></h2>
		<?php self::image_list( array_keys( $review['files'] ) ); ?>
		<p><?php
		/* translators: 1: total credit ceiling. 2: number of copies. */
		printf( esc_html__( 'Up to %1$s processing credits for %2$s selected images.', 'imazeo' ), esc_html( number_format_i18n( ceil( $review['credits'] * $count * 100 ) / 100, 2 ) ), esc_html( number_format_i18n( $count ) ) );
		?></p>
		<p><?php
		/* translators: 1: maximum edge in pixels. 2: WebP quality value. */
		printf( esc_html__( 'Maximum edge %1$s px, WebP quality %2$s.', 'imazeo' ), esc_html( (string) $review['width'] ), esc_html( (string) $review['quality'] ) );
		?></p>
		<p><?php esc_html_e( 'Approving sends the selected files to Imazeo. The service may retain uploaded originals. Copies are added to WordPress in the background.', 'imazeo' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="imazeo_enqueue">
			<input type="hidden" name="imazeo_review" value="<?php echo esc_attr( $token ); ?>">
			<?php wp_nonce_field( 'imazeo_enqueue' ); ?>
			<?php submit_button( __( 'Approve and queue copies', 'imazeo' ) ); ?>
		</form>
		<?php
	}

	private static function image_list( array $ids ): void {
		echo '<ul>';
		foreach ( $ids as $id ) {
			echo '<li>' . wp_kses_post( wp_get_attachment_image( $id, array( 48, 48 ), false, array( 'alt' => '' ) ) ) . ' ' . esc_html( get_the_title( $id ) ) . '</li>';
		}
		echo '</ul>';
	}

	private static function history( int $user ): void {
		$jobs = array_filter( Queue::state()['jobs'], static fn( array $job ): bool => Queue::visible( $job, $user ) );
		uasort( $jobs, static fn( array $a, array $b ): int => $b['updated'] <=> $a['updated'] );
		?>
		<h2><?php esc_html_e( 'Processing and recent results', 'imazeo' ); ?></h2>
		<?php if ( ! $jobs ) : ?>
			<p><?php esc_html_e( 'No media jobs have been queued.', 'imazeo' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<p><a class="button" href="<?php echo esc_url( self::media_url() ); ?>"><?php esc_html_e( 'Refresh status', 'imazeo' ); ?></a></p>
		<table class="widefat striped">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Media job', 'imazeo' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'imazeo' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Result', 'imazeo' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $jobs as $job ) : ?>
					<tr>
						<th scope="row">
							<?php echo esc_html( isset( $job['task'] ) ? ( Operations::tasks()[ $job['task'] ][0] ?? '' ) : __( 'Create WebP copy', 'imazeo' ) ); ?>
							<?php if ( ! empty( $job['source'] ) ) : ?>
								<br><a href="<?php echo esc_url( get_edit_post_link( $job['source'] ) ); ?>"><?php echo esc_html( get_the_title( $job['source'] ) ); ?></a>
							<?php endif; ?>
						</th>
						<td>
							<?php echo esc_html( self::status( $job['state'] ) ); ?>
							<?php if ( 'attention' === $job['state'] ) : ?>
								<br><?php echo esc_html( $job['message'] ?? __( 'A worker was interrupted. Check project usage before submitting again.', 'imazeo' ) ); ?>
								<?php if ( ! empty( $job['request'] ) ) : ?>
									<br><code><?php echo esc_html( $job['request'] ); ?></code>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( in_array( $job['state'], array( 'queued', 'ready', 'recover' ), true ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="imazeo_cancel">
									<input type="hidden" name="imazeo_job" value="<?php echo esc_attr( $job['id'] ); ?>">
									<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'imazeo_cancel_' . $job['id'] ) ); ?>">
									<?php submit_button( __( 'Cancel remaining work', 'imazeo' ), 'small', 'submit', false ); ?>
								</form>
							<?php endif; ?>
						</td>
						<td>
							<?php self::output( $job, $user ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function output( array $job, int $user ): void {
		foreach ( $job['outputs'] ?? array_filter( array( $job['output'] ?? 0 ) ) as $id ) {
			if ( ! user_can( $user, 'edit_post', $id ) ) {
				continue;
			}
			echo '<p><a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
			if ( wp_attachment_is_image( $id ) ) {
				echo '<br>' . wp_kses_post( wp_get_attachment_image( $id, array( 96, 96 ) ) );
				echo '<br><a href="' . esc_url( Featured_Media::url( $id, $job['target'] ?? 0 ) ) . '">' . esc_html__( 'Use as featured image', 'imazeo' ) . '</a>';
			}
			echo '</p>';
		}
		if ( ! empty( $job['private'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="imazeo_private_result"><input type="hidden" name="imazeo_job" value="' . esc_attr( $job['id'] ) . '">';
			echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'imazeo_private_result' ) ) . '">';
			submit_button( __( 'Download SVG', 'imazeo' ), 'small', 'submit', false );
			echo '</form>';
		}
		if ( isset( $job['data'] ) ) {
			echo '<details><summary>' . esc_html__( 'Result details', 'imazeo' ) . '</summary>';
			Workspace::render_values( $job['data'] );
			echo '</details>';
		}
		if ( ! empty( $job['conversion'] ) ) {
			echo '<p>' . esc_html__( 'Conversion ID', 'imazeo' ) . ' <code>' . esc_html( $job['conversion'] ) . '</code></p>';
		} elseif ( isset( $job['task'] ) && 'attention' === $job['state'] ) {
			echo '<p>' . esc_html__( 'Request key', 'imazeo' ) . ' <code>' . esc_html( $job['id'] ) . '</code></p>';
		}
	}

	/** POST handler for credential verification and consent. */
	public static function connection(): void {
		self::require_post( 'manage_options' );
		check_admin_referer( 'imazeo_connection' );
		$key = isset( $_POST['imazeo_key'] ) && is_string( $_POST['imazeo_key'] ) ? sanitize_text_field( wp_unslash( $_POST['imazeo_key'] ) ) : '';
		if ( ! isset( $_POST['imazeo_consent'] ) || ! is_string( $_POST['imazeo_consent'] ) || '1' !== sanitize_text_field( wp_unslash( $_POST['imazeo_consent'] ) ) ) {
			self::redirect( self::settings_url(), 'consent' );
		}
		$result = Connection::connect( $key );
		self::redirect( self::settings_url(), is_wp_error( $result ) ? self::error_code( $result ) : 'connected' );
	}

	/** POST handler to disconnect the WordPress site. */
	public static function disconnect(): void {
		self::require_post( 'manage_options' );
		check_admin_referer( 'imazeo_disconnect' );
		delete_option( Connection::OPTION );
		self::redirect( self::settings_url(), 'disconnected' );
	}

	/** Authorize the pending review and queue it once. */
	public static function enqueue(): void {
		self::require_post( 'upload_files' );
		check_admin_referer( 'imazeo_enqueue' );
		$user = get_current_user_id();
		$token = isset( $_POST['imazeo_review'] ) && is_string( $_POST['imazeo_review'] ) ? sanitize_text_field( wp_unslash( $_POST['imazeo_review'] ) ) : '';
		$connection = Connection::current();
		$review = Queue::review( $user, $token );
		if ( ! Api::is_uuid( $token ) || ! $connection || empty( $review['files'] )
			|| array_filter( array_keys( $review['files'] ), static fn( int $id ): bool => ! Media::eligible( $id, $user ) ) ) {
			self::redirect( self::media_url(), 'review' );
		}
		if ( ! Lifecycle::schedule() ) {
			self::redirect( self::media_url(), 'cron' );
		}
		$result = Queue::enqueue( $user, $token, $connection['generation'] );
		self::redirect( self::media_url(), is_wp_error( $result ) ? self::error_code( $result ) : 'queued' );
	}

	/** Cancel unclaimed work belonging to the current user or site. */
	public static function cancel(): void {
		self::require_post( 'upload_files' );
		$job = isset( $_POST['imazeo_job'] ) && is_string( $_POST['imazeo_job'] ) ? sanitize_text_field( wp_unslash( $_POST['imazeo_job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The posted ID is used to build the nonce action; no state changes until verified.
		check_admin_referer( 'imazeo_cancel_' . $job );
		self::redirect( self::media_url(), Api::is_uuid( $job ) && Queue::cancel( $job, get_current_user_id() ) ? 'cancelled' : 'cancel_failed' );
	}

	private static function require_post( string $capability ): void {
		self::require_cap( $capability );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'This action requires a form submission.', 'imazeo' ), '', array( 'response' => 405 ) );
		}
	}

	private static function require_cap( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'imazeo' ), '', array( 'response' => 403 ) );
		}
	}

	private static function settings_url(): string {
		return add_query_arg( 'page', self::SETTINGS, admin_url( 'options-general.php' ) );
	}

	private static function media_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::MEDIA ), $args ), admin_url( 'upload.php' ) );
	}

	private static function redirect( string $url, string $notice ): never {
		wp_safe_redirect( $notice ? add_query_arg( 'imazeo_notice', $notice, $url ) : $url, 303 );
		exit;
	}

	private static function error_code( WP_Error $error ): string {
		return 'remote' === $error->get_error_code()
			? 'remote_' . (int) ( $error->get_error_data()['status'] ?? 0 )
			: (string) $error->get_error_code();
	}

	private static function notice(): void {
		$code = isset( $_GET['imazeo_notice'] ) && is_string( $_GET['imazeo_notice'] ) ? sanitize_key( wp_unslash( $_GET['imazeo_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a local message.
		if ( '' === $code ) {
			return;
		}
		$message = match ( $code ) {
			'connected' => __( 'The Imazeo project connection was verified.', 'imazeo' ),
			'disconnected' => __( 'Imazeo was disconnected. Copies already in the Media Library remain available.', 'imazeo' ),
			'queued' => __( 'Media processing was queued. Refresh the queue to check progress.', 'imazeo' ),
			'cancelled' => __( 'The remaining work was cancelled. Requests already sent may still count toward usage.', 'imazeo' ),
			'cancel_failed' => __( 'This job is currently running, already finished or unavailable to your account.', 'imazeo' ),
			'consent' => __( 'Authorize external processing to connect this site.', 'imazeo' ),
			'key' => __( 'Enter a valid Imazeo project API key.', 'imazeo' ),
			'encryption' => __( 'Encrypted key storage requires OpenSSL and a unique AUTH_KEY. A site administrator can configure IMAZEO_API_KEY in wp-config.php instead.', 'imazeo' ),
			'remote_401', 'remote_403' => __( 'Imazeo denied access. Check the project key, plan and permissions.', 'imazeo' ),
			'credit_insufficient' => __( 'The Imazeo project has insufficient processing credits.', 'imazeo' ),
			'remote_429' => __( 'Imazeo is limiting requests. Wait a moment and try again.', 'imazeo' ),
			'webp' => __( 'WordPress needs WebP upload permission and WebP support in GD or Imagick.', 'imazeo' ),
			'attachment', 'selection', 'file', 'size', 'type' => __( 'Select up to 10 editable local JPEG or PNG images, each at most 5 MiB and 16 megapixels.', 'imazeo' ),
			'review' => __( 'The review expired or was already submitted. Select the images again.', 'imazeo' ),
			'full' => __( 'The queue is full. Wait for copies to finish before starting another batch.', 'imazeo' ),
			'duplicate' => __( 'An image in this selection is already being processed.', 'imazeo' ),
			'cron' => __( 'WordPress could not schedule the background worker. Check the site’s cron configuration.', 'imazeo' ),
			default => __( 'Imazeo could not complete this action. Check the connection and try again.', 'imazeo' ),
		};
		$type = in_array( $code, array( 'connected', 'disconnected', 'queued', 'cancelled' ), true ) ? 'notice-success' : 'notice-error';
		printf( '<div class="notice %s"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	private static function status( string $state ): string {
		return match ( $state ) {
			'queued', 'ready' => __( 'Queued', 'imazeo' ),
			'uploading' => __( 'Uploading the image', 'imazeo' ),
			'quoting', 'processing' => __( 'Processing media', 'imazeo' ),
			'remote', 'polling' => __( 'Waiting for the Imazeo conversion', 'imazeo' ),
			'importing' => __( 'Saving to the Media Library', 'imazeo' ),
			'recover', 'recovering' => __( 'Checking for the previous result', 'imazeo' ),
			'completed' => __( 'Complete', 'imazeo' ),
			'cancelled' => __( 'Cancelled', 'imazeo' ),
			default => __( 'Needs attention', 'imazeo' ),
		};
	}
}
