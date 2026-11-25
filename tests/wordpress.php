<?php
/**
 * Integration checks for a disposable WordPress installation.
 *
 * Run with wp eval-file after installing and activating the packaged plugin.
 */

use Imazeo\WordPress\Api;
use Imazeo\WordPress\Connection;
use Imazeo\WordPress\Lifecycle;
use Imazeo\WordPress\Media;
use Imazeo\WordPress\Queue;
use Imazeo\WordPress\Worker;

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

function imazeo_test_expect( bool $truth, string $message ): void {
	if ( ! $truth ) {
		throw new RuntimeException( $message );
	}
}

function imazeo_test_response( int $status, array|string $body, string $type = 'application/json' ): array {
	return array(
		'headers'  => array( 'content-type' => $type ),
		'body'     => is_array( $body ) ? wp_json_encode( array( 'success' => true, 'data' => $body ) ) : $body,
		'response' => array( 'code' => $status, 'message' => 'Fixture' ),
		'cookies'  => array(),
	);
}

function imazeo_test_fixture( string $filename, int $parent = 0 ): int {
	$temp = wp_tempnam( $filename );
	copy( __DIR__ . '/fixtures/source.jpg', $temp );
	$id = media_handle_sideload( array( 'name' => $filename, 'tmp_name' => $temp ), $parent, 'Synthetic fixture' );
	imazeo_test_expect( is_int( $id ) && $id > 0, 'Could not create the synthetic WordPress attachment.' );
	return $id;
}

function imazeo_test_review( int $user, int $attachment, array $connection ): string {
	$source = Media::read( $attachment, $user );
	imazeo_test_expect( is_array( $source ), 'Fixture source must be eligible.' );
	$token = wp_generate_uuid4();
	imazeo_test_expect(
		Queue::store_review(
			$user,
			array(
				'token' => $token,
				'generation' => $connection['generation'],
				'project' => $connection['project'],
				'files' => array( $attachment => array( 'hash' => $source['hash'], 'length' => strlen( $source['bytes'] ), 'edge' => $source['edge'] ) ),
				'credits' => 2,
				'width' => 320,
				'quality' => 82,
				'expires' => time() + 120,
			)
		),
		'Expected a stored credit review.'
	);
	return $token;
}

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
imazeo_test_expect( (bool) $admin && class_exists( Worker::class ), 'Activate the packaged plugin on a disposable site first.' );
$user = (int) $admin[0];
wp_set_current_user( $user );
Lifecycle::activate();
$key = 'imz_01111111-1111-4111-8111-111111111111.' . str_repeat( 'z', 43 );
$project = wp_generate_uuid4();
$image = wp_generate_uuid4();
$key_id = wp_generate_uuid4();
$quote_id = wp_generate_uuid4();
$operation_id = wp_generate_uuid4();
$conversion_id = wp_generate_uuid4();
$webp = file_get_contents( __DIR__ . '/fixtures/result.webp' );
$original_bytes = file_get_contents( __DIR__ . '/fixtures/source.jpg' );
$requests = array();
$recover = false;
$charge_count = 0;

add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) use ( &$requests, &$recover, &$charge_count, $key, $key_id, $project, $image, $quote_id, $operation_id, $conversion_id, $webp, $original_bytes ) {
		imazeo_test_expect( 0 === $args['redirection'] && $args['sslverify'], 'Transport must verify HTTPS and disable redirects.' );
		imazeo_test_expect( $key === $args['headers']['X-API-Key'], 'Incorrect fixture key header.' );
		$requests[] = $url;
		$base = 'https://api.imazeo.com/v1';
		$prefix = $base . '/projects/' . $project;
		switch ( $url ) {
			case $base . '/api-key/context':
				return imazeo_test_response( 200, array( 'projectId' => $project, 'keyId' => $key_id ) );
			case $prefix . '/images':
				imazeo_test_expect( (string) strlen( $original_bytes ) === $args['headers']['X-Upload-Length'] && str_contains( $args['body'], $original_bytes ), 'Wrong multipart bytes.' );
				return imazeo_test_response( 201, array( '_id' => $image ) );
			case $prefix . '/credits/quote':
				$payload = json_decode( $args['body'], true, 8, JSON_THROW_ON_ERROR );
				return imazeo_test_response( 200, array( 'id' => $quote_id, 'kind' => $payload['kind'], 'units' => 1, 'credits' => 2, 'expiresAt' => gmdate( DATE_ATOM, time() + 120 ), 'replayEnabled' => true ) );
			case $prefix . '/transforms':
				imazeo_test_expect( $quote_id === $args['headers']['X-Quote-ID'] && wp_is_uuid( $args['headers']['Idempotency-Key'], 4 ), 'Charge headers are required.' );
				++$charge_count;
				return $recover ? new WP_Error( 'http_request_failed', 'Simulated timeout' ) : imazeo_test_response( 200, $webp, 'image/webp' );
			case $prefix . '/operations/lookup':
				return imazeo_test_response( 200, array( 'id' => $operation_id, 'kind' => Api::KIND, 'state' => 'completed', 'hasResult' => true ) );
			case $prefix . '/operations/' . $operation_id . '/result':
				return imazeo_test_response( 200, $webp, 'image/webp' );
			case $prefix . '/conversions/website?format=webp':
				imazeo_test_expect( $quote_id === $args['headers']['X-Quote-ID'] && wp_is_uuid( $args['headers']['Idempotency-Key'], 4 ), 'Website capture uses both billing headers.' );
				return imazeo_test_response( 202, array( 'id' => $conversion_id, 'status' => 'queued' ) );
			case $prefix . '/conversions/' . $conversion_id:
				return imazeo_test_response( 200, array( 'id' => $conversion_id, 'status' => 'completed' ) );
			case $prefix . '/conversions/' . $conversion_id . '/download':
				return imazeo_test_response( 200, $webp, 'image/webp' );
			default:
				throw new RuntimeException( 'Unexpected HTTP call during the WordPress integration test.' );
		}
	},
	10,
	3
);

imazeo_test_expect( true === Connection::connect( $key ), 'Encrypted connection should verify and save.' );
$connection = Connection::current();
imazeo_test_expect( $key === $connection['key'] && ! str_contains( wp_json_encode( get_option( Connection::OPTION ) ), $key ), 'The database should hold no plaintext fixture key.' );
$source = imazeo_test_fixture( 'source.jpg' );
$hash = hash_file( 'sha256', get_attached_file( $source ) );
update_post_meta( $source, '_wp_attachment_image_alt', 'Synthetic image alt' );
$subscriber = wp_insert_user( array( 'user_login' => 'imazeo-fixture-subscriber', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$private_parent = wp_insert_post( array( 'post_title' => 'Private fixture', 'post_status' => 'private' ) );
imazeo_test_expect( is_int( $subscriber ) && $subscriber > 0 && $private_parent > 0, 'Could not create permission fixtures.' );
$private = imazeo_test_fixture( 'private.jpg', $private_parent );
imazeo_test_expect( ! Media::eligible( $source, $subscriber ) && ! Media::eligible( $private, $user ), 'Restrict source permissions and parent visibility.' );

$token = imazeo_test_review( $user, $source, $connection );
imazeo_test_expect( 1 === Queue::enqueue( $user, $token, $connection['generation'] ), 'Queue one verified job.' );
imazeo_test_expect( is_wp_error( Queue::enqueue( $user, $token, $connection['generation'] ) ), 'Review consumption must be atomic.' );
imazeo_test_expect( Lifecycle::schedule() && wp_next_scheduled( Lifecycle::HOOK ), 'A recurring cron worker is required.' );
do_action( Lifecycle::HOOK );
do_action( Lifecycle::HOOK );
$jobs = Queue::state()['jobs'];
$first = reset( $jobs );
imazeo_test_expect( 'completed' === $first['state'] && $first['output'] !== $source, 'A separate WebP attachment should be imported.' );
$metadata = wp_get_attachment_metadata( $first['output'] );
imazeo_test_expect( 320 === $metadata['width'] && 213 === $metadata['height'] && ! empty( $metadata['sizes'] ), 'WordPress must generate image metadata and sizes.' );
imazeo_test_expect( 'Synthetic image alt' === get_post_meta( $first['output'], '_wp_attachment_image_alt', true ), 'Copy the attachment’s alternative text.' );
imazeo_test_expect( $hash === hash_file( 'sha256', get_attached_file( $source ) ), 'Keep the source file bytes unchanged.' );

$recover = true;
$token = imazeo_test_review( $user, $source, $connection );
Queue::enqueue( $user, $token, $connection['generation'] );
do_action( Lifecycle::HOOK );
do_action( Lifecycle::HOOK );
$state = Queue::state();
$last_id = array_key_last( $state['jobs'] );
imazeo_test_expect( 'recover' === $state['jobs'][ $last_id ]['state'], 'A timed-out transform should enter recovery.' );
$state['jobs'][ $last_id ]['due'] = time();
update_option( Queue::OPTION, wp_json_encode( $state ), false );
do_action( Lifecycle::HOOK );
$last = Queue::state()['jobs'][ $last_id ];
imazeo_test_expect( 'completed' === $last['state'] && 2 === $charge_count, 'Recovered output without another chargeable transform.' );

$token = wp_generate_uuid4();
imazeo_test_expect(
	Queue::store_review( $user, array(
		'token' => $token, 'generation' => $connection['generation'], 'project' => $project, 'expires' => time() + 120,
		'tasks' => array( array(
			'task' => 'website', 'ids' => array(), 'hashes' => array(), 'body' => array( 'url' => 'https://example.com/' ),
			'query' => array( 'format' => 'webp' ), 'ceiling' => 2, 'units' => 1, 'fingerprint' => str_repeat( 'c', 64 ),
		) ),
	) ) && 1 === Queue::enqueue( $user, $token, $connection['generation'] ),
	'An authorized public website capture should queue without a local attachment.'
);
do_action( Lifecycle::HOOK );
$state = Queue::state();
$capture_id = array_key_last( $state['jobs'] );
imazeo_test_expect( 'remote' === $state['jobs'][ $capture_id ]['state'] && $conversion_id === $state['jobs'][ $capture_id ]['conversion'], 'The queued operation tracks the remote conversion ID.' );
$state['jobs'][ $capture_id ]['due'] = time();
update_option( Queue::OPTION, wp_json_encode( $state ), false );
do_action( Lifecycle::HOOK );
$capture = Queue::state()['jobs'][ $capture_id ];
imazeo_test_expect( 'completed' === $capture['state'] && count( $capture['outputs'] ) === 1 && 'image/webp' === get_post_mime_type( $capture['output'] ), 'A completed capture imports a native image.' );

Lifecycle::deactivate();
imazeo_test_expect( ! wp_next_scheduled( Lifecycle::HOOK ) && false !== get_option( Connection::OPTION ), 'Deactivation pauses work and keeps the connection.' );
Lifecycle::uninstall();
imazeo_test_expect( false === get_option( Connection::OPTION ) && false === get_option( Queue::OPTION ), 'Uninstall should remove settings and history.' );
imazeo_test_expect( is_file( get_attached_file( $source ) ) && is_file( get_attached_file( $first['output'] ) ) && is_file( get_attached_file( $last['output'] ) ), 'Uninstall must keep original and generated attachments.' );
echo "Passed WordPress connection, permissions, cron, media metadata, synchronous recovery, async imports and uninstall checks.\n";
