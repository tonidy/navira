<?php
/**
 * Plugin Name:       Navira HubSpot
 * Plugin URI:        https://navira.run.biz.id
 * Description:       Deal pipeline automation, abandoned cart recovery, transaction segmentation and a sales funnel dashboard for the Navira reseller store, on top of HubSpot.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * WC requires at least: 9.0
 * WC tested up to:   11.0
 * Author:            tonidy
 * Text Domain:       nvr-hs
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 *
 * Companion to the CRM Perks "Integration for HubSpot and WooCommerce" plugin,
 * which owns baseline contact and order sync. This plugin covers what that one
 * does not: deal stage automation for high-value leads, abandoned cart and
 * post-purchase triggers, transaction-based segmentation, and reporting.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;
const MIN_WC      = '9.0';

define( 'NVR_HS_DIR', plugin_dir_path( __FILE__ ) );
define( 'NVR_HS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Map class names to files. Kept explicit rather than derived from the class
 * name so a typo fails loudly at review time instead of silently not loading.
 */
spl_autoload_register(
	static function ( string $class ): void {
		static $map = array(
			'NvrHs\\Plugin'              => 'includes/class-plugin.php',
			'NvrHs\\Settings'            => 'includes/class-settings.php',
			'NvrHs\\Settings_Page'       => 'includes/class-settings-page.php',
			'NvrHs\\Logger'              => 'includes/class-logger.php',
			'NvrHs\\Queue'               => 'includes/class-queue.php',
			'NvrHs\\Installer'           => 'includes/class-installer.php',
			'NvrHs\\Api\\Client'         => 'includes/api/class-client.php',
			'NvrHs\\Api\\Contacts'       => 'includes/api/class-contacts.php',
			'NvrHs\\Api\\Deals'          => 'includes/api/class-deals.php',
			'NvrHs\\Api\\Properties'     => 'includes/api/class-properties.php',
			'NvrHs\\Sync\\Contact_Sync'  => 'includes/sync/class-contact-sync.php',
			'NvrHs\\Sync\\Deal_Pipeline' => 'includes/sync/class-deal-pipeline.php',
			'NvrHs\\Sync\\Cart_Recovery' => 'includes/sync/class-cart-recovery.php',
			'NvrHs\\Sync\\Post_Purchase' => 'includes/sync/class-post-purchase.php',
			'NvrHs\\Sync\\Segmentation'  => 'includes/sync/class-segmentation.php',
			'NvrHs\\Admin\\Dashboard'    => 'includes/admin/class-dashboard.php',
			'NvrHs\\Admin\\Funnel'       => 'includes/admin/class-funnel.php',
		);

		if ( isset( $map[ $class ] ) ) {
			require_once NVR_HS_DIR . $map[ $class ];
		}
	}
);

/**
 * WooCommerce is a hard dependency: every feature here keys off orders, carts
 * or products. Rather than fatal on a missing class, bail with a notice so the
 * site stays usable if Woo is deactivated.
 */
function woocommerce_ready(): bool {
	return class_exists( 'WooCommerce' )
		&& defined( 'WC_VERSION' )
		&& version_compare( WC_VERSION, MIN_WC, '>=' );
}

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! woocommerce_ready() ) {
			add_action(
				'admin_notices',
				static function (): void {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: %s: minimum WooCommerce version */
								__( 'Navira HubSpot needs WooCommerce %s or newer and is idle until it is active.', 'nvr-hs' ),
								MIN_WC
							)
						)
					);
				}
			);
			return;
		}

		Plugin::instance()->boot();
	},
	20
);

// Declare HPOS compatibility. Woo 11 defaults to custom order tables, and
// without this the store shows an incompatibility warning.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				PLUGIN_FILE,
				true
			);
		}
	}
);

// Registered at file scope, not in Plugin::boot(). Activation runs after
// plugins_loaded has already fired, so a filter added in boot() does not exist
// yet when the activation hook schedules events, and wp_schedule_event()
// silently fails for any recurrence it does not recognise.
add_filter( 'cron_schedules', array( Installer::class, 'cron_schedules' ) );

register_activation_hook( PLUGIN_FILE, array( Installer::class, 'activate' ) );
register_deactivation_hook( PLUGIN_FILE, array( Installer::class, 'deactivate' ) );
