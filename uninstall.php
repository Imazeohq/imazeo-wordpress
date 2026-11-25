<?php
/**
 * Clean up site settings and processing history.
 *
 * @package Imazeo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-connection.php';
require_once __DIR__ . '/includes/class-media.php';
require_once __DIR__ . '/includes/class-processing.php';
require_once __DIR__ . '/includes/class-queue.php';
require_once __DIR__ . '/includes/class-lifecycle.php';

\Imazeo\WordPress\Lifecycle::uninstall();
