<?php
/**
 * Run with the PHP CLI using a small WordPress stub and synthetic fixtures.
 */

use Imazeo\WordPress\Api;
use Imazeo\WordPress\Connection;
use Imazeo\WordPress\Media;
use Imazeo\WordPress\Operations;
use Imazeo\WordPress\Processing;
use Imazeo\WordPress\Project_Library;
use Imazeo\WordPress\Queue;
use Imazeo\WordPress\Worker;

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'IMAZEO_API_KEY', 'imz_01111111-1111-4111-8111-111111111111.' . str_repeat( 'z', 43 ) );

final class WP_Error {
	public function __construct( private string $code, private string $message, private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

final class WP_Post {
	public function __construct(
		public string $post_type = 'attachment',
		public string $post_mime_type = 'image/jpeg',
		public string $post_status = 'inherit',
		public string $post_password = '',
		public int $post_parent = 0
	) {}
}

final class TestDatabase {
	public string $options = 'test_options';
	public ?Closure $before_cas = null;
	public int $conflicts = 0;
	public function prepare( string $sql, mixed ...$values ): array { return array_slice( $values, 1 ); }
	public function get_var( array $params ): mixed { return get_option( $params[0], null ); }
	public function query( array $params ): int {
		if ( $this->before_cas ) {
			$callback = $this->before_cas;
			$this->before_cas = null;
			$callback();
		}
		if ( get_option( $params[1] ) !== $params[2] ) {
			++$this->conflicts;
			return 0;
		}
		update_option( $params[1], $params[0] );
		return 1;
	}
}

$options = array();
$http = array();
$requests = array();
$wpdb = new TestDatabase();
$posts = array( 1 => new WP_Post(), 2 => new WP_Post( post_parent: 3 ), 3 => new WP_Post( 'post', '', 'private' ), 4 => new WP_Post() );
$allowed = true;

function __( string $text, string $domain ): string { return $text; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value, mixed $autoload = null ): bool { $GLOBALS['options'][ $name ] = $value; return true; }
function add_option( string $name, mixed $value, string $deprecated, bool $autoload ): bool {
	return isset( $GLOBALS['options'][ $name ] ) ? false : update_option( $name, $value );
}
function wp_cache_delete( string $key, string $group ): bool { return true; }
function wp_is_uuid( string $value, int $version ): bool { return 1 === preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/i', $value ); }
function wp_generate_uuid4(): string {
	static $id = 0;
	return sprintf( '00000000-0000-4000-8000-%012x', ++$id );
}
function wp_safe_remote_request( string $url, array $args ): array|WP_Error {
	$GLOBALS['requests'][] = array( $url, $args );
	expect( (bool) $GLOBALS['http'], 'Unexpected API request.' );
	return array_shift( $GLOBALS['http'] );
}
function wp_remote_retrieve_response_code( array $response ): int { return $response['status']; }
function wp_remote_retrieve_body( array $response ): string { return $response['body']; }
function wp_remote_retrieve_header( array $response, string $name ): mixed { return $response['headers'][ $name ] ?? ''; }
function get_post( int $id ): ?WP_Post { return $GLOBALS['posts'][ $id ] ?? null; }
function user_can( int $user, string $cap, mixed ...$args ): bool { return 7 === $user && $GLOBALS['allowed']; }
function is_post_publicly_viewable( WP_Post $post ): bool { return 'publish' === $post->post_status; }
function get_attached_file( int $id, bool $unfiltered ): string { return 4 === $id ? __FILE__ : __DIR__ . '/fixtures/source.jpg'; }
function wp_get_upload_dir(): array { return array( 'basedir' => __DIR__ . '/fixtures' ); }
function wp_is_stream( string $file ): bool { return str_contains( $file, '://' ); }
function trailingslashit( string $path ): string { return rtrim( $path, '/\\' ) . '/'; }
function wp_normalize_path( string $path ): string { return str_replace( '\\', '/', $path ); }
function get_post_mime_type( int $id ): string { return get_post( $id )->post_mime_type; }
function get_posts( array $args ): array { return array(); }
function get_allowed_mime_types( int $user ): array { return array( 'webp' => 'image/webp' ); }
function wp_check_filetype( string $file, array $mimes ): array { return array( 'type' => $mimes['webp'] ); }
function wp_image_editor_supports( array $args ): bool { return true; }
function wp_parse_url( string $url ): array|false { return parse_url( $url ); }

foreach ( array( 'api', 'connection', 'media', 'operations', 'processing', 'project-library', 'queue', 'worker' ) as $module ) {
	require __DIR__ . '/../includes/class-' . $module . '.php';
}

function expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function response( array|string $data, int $status = 200, array $headers = array() ): array {
	return array(
		'status' => $status,
		'body' => is_array( $data ) ? json_encode( array( 'success' => true, 'data' => $data ), JSON_THROW_ON_ERROR ) : $data,
		'headers' => $headers,
	);
}

function cost( int $credits = 2, string $kind = Api::KIND ): array {
	return response(
		array(
			'id' => '11111111-1111-4111-8111-111111111111',
			'kind' => $kind,
			'units' => 1,
			'credits' => $credits,
			'expiresAt' => gmdate( DATE_ATOM, time() + 120 ),
			'replayEnabled' => true,
		)
	);
}

function review_for( array $connection, string $hash, int $length, int $id = 1 ): string {
	$token = wp_generate_uuid4();
	expect(
		Queue::store_review(
			7,
			array(
				'token' => $token,
				'generation' => $connection['generation'],
				'project' => $connection['project'],
				'files' => array( $id => array( 'hash' => $hash, 'length' => $length, 'edge' => 480 ) ),
				'credits' => 2,
				'width' => 320,
				'quality' => 82,
				'expires' => time() + 120,
			)
		),
		'Review must save.'
	);
	return $token;
}

Queue::install();
$project = wp_generate_uuid4();
$image = wp_generate_uuid4();
$http[] = response( array( 'projectId' => $project, 'keyId' => wp_generate_uuid4() ) );
expect( true === Connection::connect( '' ), 'Verified configuration key must connect.' );
$connection = Connection::current();
expect( $project === $connection['project'] && '' === get_option( Connection::OPTION )['ciphertext'], 'Configured keys must remain outside the database.' );
expect( 'https://api.imazeo.com/v1/api-key/context' === $requests[0][0] && 0 === $requests[0][1]['redirection'], 'Connection must use pinned origin and reject redirects.' );
expect( is_wp_error( Media::read( 1, 8 ) ) && is_wp_error( Media::read( 2, 7 ) ) && is_wp_error( Media::read( 4, 7 ) ), 'Reject foreign, private and out-of-root attachments.' );
$source = Media::read( 1, 7 );
expect( is_array( $source ), 'Read a local editable JPEG.' );
$size = strlen( $source['bytes'] );
$token = review_for( $connection, $source['hash'], $size );
expect( null === Queue::review( 8, $token ), 'Review must be owner scoped.' );
expect( 1 === Queue::enqueue( 7, $token, $connection['generation'] ), 'Valid review must enqueue.' );
expect( is_wp_error( Queue::enqueue( 7, $token, $connection['generation'] ) ), 'Duplicate review submission must be rejected.' );
$other = review_for( $connection, $source['hash'], $size );
expect( is_wp_error( Queue::enqueue( 7, $other, $connection['generation'] ) ), 'The same active attachment must not enqueue twice.' );

$competing = null;
$wpdb->before_cas = static function () use ( &$competing ): void { $competing = Queue::claim(); };
expect( null === Queue::claim() && is_array( $competing ) && 1 === $wpdb->conflicts, 'Only one concurrent worker can claim the job.' );
expect( ! Queue::cancel( $competing['id'], 7 ), 'An active worker cannot be cancelled.' );
Queue::save( $competing, array( 'state' => 'queued' ) );
$http[] = response( array( '_id' => $image ), 201 );
Worker::run();
$request = end( $requests );
expect( str_ends_with( $request[0], '/images' ) && (string) $size === $request[1]['headers']['X-Upload-Length'], 'Upload sends exact byte count.' );
expect( str_contains( $request[1]['body'], $source['bytes'] ) && str_contains( $request[1]['body'], 'filename="source.jpg"' ), 'Multipart bytes and generic filename must match.' );

$http[] = cost( 3 );
Worker::run();
$job = Queue::state()['jobs'][ $competing['id'] ];
expect( 'attention' === $job['state'] && str_contains( $job['message'], 'price increased' ) && ! $http, 'A higher quote must never send a transform.' );

$token = review_for( $connection, $source['hash'], $size );
expect( 1 === Queue::enqueue( 7, $token, $connection['generation'] ), 'Queue a new explicitly reviewed attempt.' );
$http[] = response( array( '_id' => $image ), 201 );
Worker::run();
$http[] = cost();
$http[] = new WP_Error( 'http_request_failed', 'Untrusted transport details' );
Worker::run();
$request = end( $requests );
$payload = json_decode( $request[1]['body'], true );
expect( str_ends_with( $request[0], '/transforms' ) && 'EPHEMERAL' === $payload['mode'] && 'webp' === $payload['transform']['format'], 'Transform uses the reviewed binary contract.' );
$job_id = $request[1]['headers']['Idempotency-Key'];
expect( Api::is_uuid( $job_id ) && Api::is_uuid( $request[1]['headers']['X-Quote-ID'] ), 'The request needs stable idempotency and quote headers.' );
$state = Queue::state();
expect( 'recover' === $state['jobs'][ $job_id ]['state'], 'An uncertain transform should switch to recovery.' );
$state['jobs'][ $job_id ]['due'] = time();
update_option( Queue::OPTION, json_encode( $state ) );
$http[] = response( array( 'id' => wp_generate_uuid4(), 'kind' => Api::KIND, 'state' => 'completed', 'hasResult' => false ) );
Worker::run();
expect( 'attention' === Queue::state()['jobs'][ $job_id ]['state'] && str_ends_with( end( $requests )[0], '/operations/lookup' ), 'Recovery queries the original operation and never repeats the transform.' );

$token = review_for( $connection, $source['hash'], $size );
Queue::enqueue( 7, $token, $connection['generation'] );
$allowed = false;
$before = count( $requests );
Worker::run();
expect( count( $requests ) === $before, 'Removing permissions must stop previously authorized work.' );
$allowed = true;
$token = review_for( $connection, $source['hash'], $size );
Queue::enqueue( 7, $token, $connection['generation'] );
$http[] = new WP_Error( 'http_request_failed', 'Simulated upload timeout' );
Worker::run();
$jobs = Queue::state()['jobs'];
$last = $jobs[ array_key_last( $jobs ) ];
expect( 'attention' === $last['state'] && str_ends_with( end( $requests )[0], '/images' ), 'An uncertain upload must require attention.' );
$before = count( $requests );
Worker::run();
expect( count( $requests ) === $before, 'An uncertain upload must never be retried automatically.' );
$http[] = response( '{"success":false,"error":{"code":"CREDIT_INSUFFICIENT","message":"Untrusted service details"}}', 403 );
$e = ( new Api( IMAZEO_API_KEY ) )->quote( $project );
expect( is_wp_error( $e ) && 'credit_insufficient' === $e->get_error_code() && ! str_contains( $e->get_error_message(), 'Untrusted' ), 'Recognize insufficient credits without echoing service error text.' );

$catalog = Operations::all();
$workflow_operations = array_unique( array_merge( array( 'ApiKeyContextController_context' ), array_column( Operations::tasks(), 1 ), array_keys( Project_Library::commands() ) ) );
expect( 45 === count( $catalog ) && ! array_diff( array_keys( $catalog ), $workflow_operations ) && ! array_diff( $workflow_operations, array_keys( $catalog ) ), 'Every deployed project-key operation must map to a WordPress workflow.' );
expect( '/projects/{projectId}/conversions' === $catalog['ConversionsController_create']['path'], 'Use the deployed DOCX route.' );
expect( 3 === Operations::units( 'pdf_render', array( 'rangeStart' => 4, 'rangeEnd' => 6 ) ) && 10 === Operations::units( 'pdf_render', array( 'all' => true ) ), 'Review the correct upper bound for rendered pages.' );
expect( is_wp_error( Operations::units( 'pdf_render', array( 'pages' => '1,1' ) ) ) && is_wp_error( Operations::units( 'pdf_render', array( 'all' => true, 'pages' => '1,2' ) ) ), 'Reject ambiguous PDF selections.' );
expect( Operations::url( 'https://example.com/page' ) && ! Operations::url( 'http://127.0.0.1/' ) && ! Operations::url( 'http://127.000.0.1/' ) && ! Operations::url( 'https://name:secret@example.com/' ), 'A public URL field should reject private literals and credentials before quoting.' );
$schema = Operations::form( 'TransformsController_create', true );
$crop = array( 'left' => '0', 'top' => '1', 'width' => '20', 'height' => '15' );
$fields = Operations::fields( $schema['body'], array( 'transform' => array( 'crop' => $crop, 'flip' => 'false', 'format' => 'avif' ), 'url' => 'https://example.com/' ) );
expect( is_array( $fields ) && 0 === $fields['transform']['crop']['left'] && false === $fields['transform']['flip'] && ! isset( $fields['url'] ), 'Preserve typed nested options and keep the image source outside generic controls.' );
expect( is_wp_error( Operations::fields( $schema['body'], array( 'transform' => array( 'crop' => array_diff_key( $crop, array( 'top' => true ) ) ) ) ) ), 'Reject incomplete pixel crop.' );
$api = new Api( IMAZEO_API_KEY );
$conditional_tag = '"' . str_repeat( 'a', 64 ) . '"';
$http[] = response( '', 304 );
$result = $api->call( 'TransformsController_content', $project, wp_generate_uuid4(), array(), array(), array(), array( 'If-None-Match' => $conditional_tag, 'X-API-Key' => 'must-not-override' ) );
$request = end( $requests );
expect( ! empty( $result['unchanged'] ) && $conditional_tag === $request[1]['headers']['If-None-Match'] && IMAZEO_API_KEY === $request[1]['headers']['X-API-Key'], 'Support authenticated transform revalidation without forwarding credential overrides.' );
expect( is_wp_error( $api->call( 'ImagesController_get', $project, str_repeat( 'a', 24 ) ) ), 'Reject database-shaped IDs before routing project requests.' );
$http[] = response( '%PDF-test', 200, array( 'content-type' => 'application/pdf' ) );
$result = $api->call( 'PdfController_pdfMerge', $project, '', array(), array(), array(
	array( 'name' => 'source-1.pdf', 'bytes' => 'first', 'mime' => 'application/pdf' ),
	array( 'name' => 'source-2.pdf', 'bytes' => 'second', 'mime' => 'application/pdf' ),
) );
$request = end( $requests );
expect( '11' === $request[1]['headers']['X-Upload-Length'] && 2 === substr_count( $request[1]['body'], 'name="file"' )
	&& strpos( $request[1]['body'], 'first' ) < strpos( $request[1]['body'], 'second' ) && 'application/pdf' === Processing::result_mime( $result ), 'Merge sends repeated multipart fields in the reviewed order.' );
$command = wp_generate_uuid4();
expect( Queue::reserve_command( $command ) && ! Queue::reserve_command( $command ), 'A project mutation token can only be consumed once.' );

$token = wp_generate_uuid4();
$review = array(
	'token' => $token, 'generation' => $connection['generation'], 'project' => $project, 'expires' => time() + 120,
	'tasks' => array( array(
		'task' => 'website', 'ids' => array(), 'hashes' => array(), 'body' => array( 'url' => 'https://example.com/' ),
		'query' => array( 'mode' => 'fullPage', 'javascriptEnabled' => false ), 'ceiling' => 2, 'units' => 1, 'fingerprint' => str_repeat( 'c', 64 ),
	) ),
);
expect( Queue::store_review( 7, $review ) && 1 === Queue::enqueue( 7, $token, $connection['generation'] ), 'Queue the reviewed website capture.' );
$http[] = cost( 2, 'website.capture' );
$http[] = new WP_Error( 'transport', 'Simulated submission timeout.' );
Worker::run();
$request = end( $requests );
$job_id = $request[1]['headers']['Idempotency-Key'];
$state = Queue::state();
expect( str_ends_with( $request[0], '/conversions/website?mode=fullPage&javascriptEnabled=false' ) && 'recover' === $state['jobs'][ $job_id ]['state'], 'Submit the reviewed query with stable idempotency and recover an ambiguous response.' );
$state['jobs'][ $job_id ]['due'] = time();
update_option( Queue::OPTION, json_encode( $state ) );
$conversion_id = wp_generate_uuid4();
$http[] = response( array( 'id' => $conversion_id ), 200, array( 'content-type' => 'application/json; charset=utf-8' ) );
Worker::run();
$state = Queue::state();
expect( str_ends_with( end( $requests )[0], '/conversions/lookup?key=' . $job_id ) && 'remote' === $state['jobs'][ $job_id ]['state'] && $conversion_id === $state['jobs'][ $job_id ]['conversion'], 'Recover the original conversion without a second paid request.' );
$state['jobs'][ $job_id ]['due'] = time();
update_option( Queue::OPTION, json_encode( $state ) );
$http[] = response( array( 'id' => $conversion_id, 'status' => 'running' ), 200, array( 'content-type' => 'application/json' ) );
Worker::run();
$state = Queue::state();
expect( 'remote' === $state['jobs'][ $job_id ]['state'] && $state['jobs'][ $job_id ]['due'] > time(), 'Running conversions release the worker and schedule a later poll.' );
expect( ! $http, 'All mock HTTP responses must be consumed.' );
echo "Passed coverage, request, permission, byte validation, review, CAS, credit, queue and recovery checks.\n";
