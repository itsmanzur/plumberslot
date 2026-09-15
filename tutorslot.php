<?php
/**
 * Plugin Name:       TutorSlot
 * Plugin URI:        https://tutorslot.com
 * Description:       Scheduling for tutors with weekly availability, recurring lessons, parent accounts, lesson packages, optional payments and online meetings.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            TutorSlot
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tutorslot
 * Domain Path:       /languages
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.0';
const DB_VERSION  = 6;
const PLUGIN_FILE = __FILE__;
const MIN_PHP     = '8.1';
const MIN_WP      = '6.4';

define( 'TUTORSLOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'TUTORSLOT_URL', plugin_dir_url( __FILE__ ) );

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
						__( 'TutorSlot needs PHP %s or newer. Ask your host to upgrade, then activate the plugin again.', 'tutorslot' ),
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

$tutorslot_scheduler = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

if ( is_readable( $tutorslot_scheduler ) ) {
	require_once $tutorslot_scheduler;
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
