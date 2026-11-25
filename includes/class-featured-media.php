<?php
/**
 * Explicit featured-image replacement and restoration.
 *
 * @package Imazeo
 */

namespace Imazeo\WordPress;

defined( 'ABSPATH' ) || exit;

final class Featured_Media {
	public const META = '_imazeo_featured_restore';
	private const SLUG = 'imazeo-featured';

	public static function register(): void {
		add_action( 'admin_menu', static function (): void {
			add_media_page( __( 'Imazeo Featured Image', 'imazeo' ), __( 'Imazeo Featured Image', 'imazeo' ), 'upload_files', self::SLUG, array( self::class, 'page' ) );
		} );
		add_action( 'admin_post_imazeo_featured', array( self::class, 'change' ) );
		add_filter( 'admin_post_thumbnail_html', array( self::class, 'editor_actions' ), 10, 3 );
	}

	/** Offer contextual actions from the WordPress featured-image panel where the hook is available. */
	public static function editor_actions( string $html, int $post, int|string|null $media ): string {
		$media = (int) $media;
		if ( ! $media || ! current_user_can( 'upload_files' ) || ! self::target( $post ) ) {
			return $html;
		}
		$actions = array();
		if ( Connection::current() && Media::eligible( $media, get_current_user_id(), 'image' ) ) {
			$actions[] = '<a href="' . esc_url( Workspace::url( array( 'ids' => $media, 'target' => $post ) ) ) . '">' . esc_html__( 'Transform with Imazeo', 'imazeo' ) . '</a>';
		}
		if ( self::restorable( $post, $media ) ) {
			$actions[] = '<a href="' . esc_url( self::url( 0, $post ) ) . '">' . esc_html__( 'Review featured image restoration', 'imazeo' ) . '</a>';
		}
		return $html . ( $actions ? '<p>' . implode( '<br>', $actions ) . '</p>' : '' );
	}

	public static function url( int $media = 0, int $post = 0 ): string {
		return add_query_arg( array( 'page' => self::SLUG, 'media' => $media, 'target' => $post ), admin_url( 'upload.php' ) );
	}

	public static function page(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			Workspace::denied();
		}
		$media = isset( $_GET['media'] ) ? absint( $_GET['media'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post = isset( $_GET['target'] ) ? absint( $_GET['target'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['message'] ) && is_string( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>' . esc_html__( 'Featured image', 'imazeo' ) . '</h1>';
		if ( $message ) {
			$good = in_array( $message, array( 'replaced', 'restored' ), true );
			$message = match ( $message ) {
				'replaced' => __( 'The featured image was updated.', 'imazeo' ),
				'restored' => __( 'The original featured image was restored.', 'imazeo' ),
				default => __( 'The featured image changed or the selected media is unavailable. Review it again.', 'imazeo' ),
			};
			echo '<div class="notice ' . esc_attr( $good ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $message ) . '</p></div>';
		}
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="hidden" name="media" value="<?php echo esc_attr( (string) $media ); ?>">
			<p><label for="imazeo-target"><?php esc_html_e( 'Post, page or product ID', 'imazeo' ); ?></label><br>
				<input id="imazeo-target" name="target" type="number" min="1" value="<?php echo esc_attr( $post ? (string) $post : '' ); ?>" required>
			</p>
			<?php submit_button( __( 'Review featured image', 'imazeo' ), 'secondary' ); ?>
		</form>
		<?php
		if ( $post && self::target( $post ) ) {
			$current = (int) get_post_thumbnail_id( $post );
			$restore = self::restorable( $post, $current );
			echo '<h2><a href="' . esc_url( get_edit_post_link( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></h2>';
			if ( $current ) {
				echo wp_kses_post( wp_get_attachment_image( $current, 'thumbnail' ) );
			}
			if ( $restore && self::image( $restore['previous'] ) ) {
				self::form( $post, $current, $restore['previous'], true );
			} elseif ( $current && $media !== $current && self::image( $media ) && get_post_meta( $media, Media::JOB_META, true ) ) {
				echo '<p>' . wp_kses_post( wp_get_attachment_image( $media, 'thumbnail' ) ) . '</p>';
				self::form( $post, $current, $media, false );
			} else {
				echo '<p>' . esc_html__( 'Select a generated image from the processing queue and a post with a featured image, or open a previously changed post to restore it.', 'imazeo' ) . '</p>';
			}
		} elseif ( $post ) {
			echo '<p>' . esc_html__( 'That post does not support featured images or is outside your editing permissions.', 'imazeo' ) . '</p>';
		}
		echo '</div>';
	}

	private static function form( int $post, int $expected, int $media, bool $restore ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="imazeo_featured">
			<input type="hidden" name="target" value="<?php echo esc_attr( (string) $post ); ?>">
			<input type="hidden" name="media" value="<?php echo esc_attr( (string) $media ); ?>">
			<input type="hidden" name="expected" value="<?php echo esc_attr( (string) $expected ); ?>">
			<input type="hidden" name="restore" value="<?php echo esc_attr( $restore ? '1' : '0' ); ?>">
			<?php wp_nonce_field( 'imazeo_featured' ); ?>
			<p><?php esc_html_e( 'This changes the featured image on this item only. Both images remain in the Media Library.', 'imazeo' ); ?></p>
			<?php submit_button( $restore ? __( 'Restore original featured image', 'imazeo' ) : __( 'Replace featured image', 'imazeo' ) ); ?>
		</form>
		<?php
	}

	public static function change(): void {
		Workspace::check_post( 'upload_files' );
		check_admin_referer( 'imazeo_featured' );
		$post = isset( $_POST['target'] ) ? absint( $_POST['target'] ) : 0;
		$media = isset( $_POST['media'] ) ? absint( $_POST['media'] ) : 0;
		$expected = isset( $_POST['expected'] ) ? absint( $_POST['expected'] ) : 0;
		$restore = isset( $_POST['restore'] ) && '1' === $_POST['restore']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact comparison.
		$url = self::url( $media, $post );
		if ( ! self::target( $post ) || ! $expected || $expected !== (int) get_post_thumbnail_id( $post ) || ! self::image( $media ) || $expected === $media ) {
			Workspace::back( $url, 'changed' );
		}
		$backup = self::restorable( $post, $expected );
		if ( $restore ) {
			if ( ! $backup || $media !== $backup['previous'] || ! update_post_meta( $post, '_thumbnail_id', $media, $expected ) ) {
				Workspace::back( $url, 'changed' );
			}
			delete_post_meta( $post, self::META, $backup );
			Workspace::back( self::url( 0, $post ), 'restored' );
		}
		if ( $backup || ! self::image( $expected ) || ! get_post_meta( $media, Media::JOB_META, true ) ) {
			Workspace::back( $url, 'changed' );
		}
		$record = array( 'previous' => $expected, 'replacement' => $media, 'key' => wp_generate_uuid4() );
		$reference = add_post_meta( $post, self::META, $record );
		if ( ! $reference ) {
			Workspace::back( $url, 'changed' );
		}
		if ( ! update_post_meta( $post, '_thumbnail_id', $media, $expected ) ) {
			delete_metadata_by_mid( 'post', $reference );
			Workspace::back( $url, 'changed' );
		}
		Workspace::back( self::url( $media, $post ), 'replaced' );
	}

	public static function target( int $id ): bool {
		$post = $id ? get_post( $id ) : null;
		return $post && 'attachment' !== $post->post_type && post_type_supports( $post->post_type, 'thumbnail' ) && current_user_can( 'edit_post', $id );
	}

	private static function image( int $id ): bool {
		return $id > 0 && wp_attachment_is_image( $id ) && current_user_can( 'edit_post', $id );
	}

	private static function restorable( int $post, int $current ): ?array {
		foreach ( array_reverse( get_post_meta( $post, self::META, false ) ) as $record ) {
			if ( is_array( $record ) && $current === ( $record['replacement'] ?? 0 ) && ! empty( $record['previous'] ) ) {
				return $record;
			}
		}
		return null;
	}
}
