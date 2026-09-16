<?php
/**
 * Plugin Name:       PlumberSlot
 * Plugin URI:        https://plumberslot.com
 * Description:       Scheduling for plumbing businesses with technician-owned availability, service addresses, recurring appointments, Service Plans, optional payments and virtual estimates.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            PlumberSlot
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       plumberslot
 * Domain Path:       /languages
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.0';
const DB_VERSION  = 11;
const PLUGIN_FILE = __FILE__;
const MIN_PHP     = '8.1';
const MIN_WP      = '6.4';

define( 'PLUMBERSLOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLUMBERSLOT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bail out politely rather than fatally on an unsupported stack.
 */
if ( version_compare( PHP_VERSION, MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: required PHP version. */
						__( 'PlumberSlot needs PHP %s or newer. Ask your host to upgrade, then activate the plugin again.', 'plumberslot' ),
						MIN_PHP
					)
				)
			);
		}
	);
	return;
}

/**
 * Autoloading.
 *
 * The bundled loader always runs, so the plugin activates from a plain ZIP.
 * Composer's autoloader is layered on top when the site was installed from the
 * repository, which is what brings in Action Scheduler.
 */
require_once __DIR__ . '/src/Autoloader.php';
Autoloader::register();

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

$plumberslot_scheduler = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

if ( is_readable( $plumberslot_scheduler ) ) {
	require_once $plumberslot_scheduler;
}

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

/**
 * Single entry point. Everything else is wired from Plugin::boot().
 *
 * WordPress 6.7+ expects translated plugin strings to be created at init or
 * later. Priority 5 still registers every REST, admin and frontend hook before
 * its corresponding lifecycle action fires.
 */
add_action(
	'init',
	static function (): void {
		Plugin::instance()->boot();
	},
	5
);
