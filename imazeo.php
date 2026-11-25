<?php
/**
 * Plugin Name: Imazeo
 * Plugin URI: https://imazeo.com/
 * Description: Transform images, work with PDFs, render documents and websites, and manage QR codes and barcodes from WordPress.
 * Version: 1.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Author: Imazeo
 * Author URI: https://imazeo.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: imazeo
 * Domain Path: /languages
 *
 * @package Imazeo
 */

namespace Imazeo\WordPress;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-api.php';
require_once __DIR__ . '/includes/class-operations.php';
require_once __DIR__ . '/includes/class-processing.php';
require_once __DIR__ . '/includes/class-connection.php';
require_once __DIR__ . '/includes/class-media.php';
require_once __DIR__ . '/includes/class-queue.php';
require_once __DIR__ . '/includes/class-worker.php';
require_once __DIR__ . '/includes/class-lifecycle.php';

add_filter( 'cron_schedules', array( Lifecycle::class, 'schedules' ) );
add_action( Lifecycle::HOOK, array( Worker::class, 'run' ) );
add_action( 'wp_initialize_site', array( Lifecycle::class, 'initialize_site' ), 20 );
register_activation_hook( __FILE__, array( Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Lifecycle::class, 'deactivate' ) );

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-admin.php';
	require_once __DIR__ . '/includes/class-workspace.php';
	require_once __DIR__ . '/includes/class-project-library.php';
	require_once __DIR__ . '/includes/class-featured-media.php';
	Admin::register();
	Workspace::register();
	Project_Library::register();
	Featured_Media::register();
}
