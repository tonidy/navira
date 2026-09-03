<?php
/**
 * Wiring.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs;

use NvrHs\Admin\Dashboard;
use NvrHs\Api\Client;
use NvrHs\Api\Contacts;
use NvrHs\Api\Deals;
use NvrHs\Api\Properties;
use NvrHs\Sync\Cart_Recovery;
use NvrHs\Sync\Contact_Sync;
use NvrHs\Sync\Deal_Pipeline;
use NvrHs\Sync\Post_Purchase;
use NvrHs\Sync\Segmentation;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the object graph and hooks it up.
 *
 * Constructor injection throughout, no service locator. It is a small plugin
 * and the dependencies are obvious at a glance from this one file, which is
 * worth more than the flexibility a container would buy.
 */
final class Plugin {

	private static ?self $instance = null;

	private Settings $settings;
	private Logger $logger;
	private Queue $queue;

	private Client $client;
	private Contacts $contacts;
	private Deals $deals;
	private Properties $properties;

	private Cart_Recovery $carts;
	private Segmentation $segmentation;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = new Settings();
		$this->logger   = new Logger( $this->settings );
		$this->queue    = new Queue( $this->logger );

		// Cron context gets the longer timeout and the retry behaviour; page
		// renders get the short one, so a slow HubSpot cannot hold a worker.
		$in_queue = wp_doing_cron() || ( defined( 'WP_CLI' ) && \WP_CLI );

		$this->client     = new Client( $this->settings, $this->logger, $in_queue );
		$this->contacts   = new Contacts( $this->client );
		$this->deals      = new Deals( $this->client );
		$this->properties = new Properties( $this->client, $this->logger );

		$this->segmentation = new Segmentation( $this->settings, $this->queue, $this->logger, $this->contacts );
		$this->carts        = new Cart_Recovery( $this->settings, $this->queue, $this->logger, $this->contacts );
	}

	public function boot(): void {
		// Picks up schema changes deployed by rsync or git, which never fire
		// the activation hook.
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ) );

		// Re-checked on every request rather than only on version change.
		// It is two wp_next_scheduled() calls against an autoloaded option,
		// and it means a schedule that failed to register once repairs itself
		// instead of staying missing until someone reactivates the plugin.
		add_action( 'init', array( Installer::class, 'ensure_schedule' ) );

		( new Contact_Sync( $this->queue, $this->logger, $this->contacts ) )->register_hooks();

		( new Deal_Pipeline(
			$this->settings,
			$this->queue,
			$this->logger,
			$this->deals,
			$this->contacts
		) )->register_hooks();

		$this->carts->register_hooks();
		$this->segmentation->register_hooks();

		( new Post_Purchase(
			$this->settings,
			$this->queue,
			$this->logger,
			$this->segmentation
		) )->register_hooks();

		add_action( Installer::CRON_QUEUE, array( $this->queue, 'run' ) );
		add_action( Installer::CRON_PRUNE, array( $this->logger, 'prune' ) );

		if ( is_admin() ) {
			( new Settings_Page( $this->settings, $this->client, $this->properties, $this->queue ) )->register_hooks();
			( new Dashboard( $this->settings, $this->queue, $this->logger ) )->register_hooks();
		}

		$this->register_cli();
	}

	/**
	 * WP-CLI commands. The container has wp-cli mounted at /usr/local/bin/wp,
	 * so these are the practical way to test a deploy without clicking through
	 * the admin.
	 */
	private function register_cli(): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		\WP_CLI::add_command(
			'nvr-hs test',
			function (): void {
				$result = $this->client->connection_test();
				if ( $result['ok'] ) {
					\WP_CLI::success( $result['message'] );
					return;
				}
				\WP_CLI::error( $result['message'] );
			},
			array( 'shortdesc' => 'Verify the HubSpot token and report the portal.' )
		);

		\WP_CLI::add_command(
			'nvr-hs properties',
			function (): void {
				$result = $this->properties->ensure();
				\WP_CLI::log( sprintf( 'created:  %d', count( $result['created'] ) ) );
				\WP_CLI::log( sprintf( 'existing: %d', count( $result['existing'] ) ) );

				foreach ( $result['created'] as $name ) {
					\WP_CLI::log( '  + ' . $name );
				}

				if ( $result['errors'] ) {
					foreach ( $result['errors'] as $error ) {
						\WP_CLI::warning( $error );
					}
					\WP_CLI::error( 'Some properties could not be created.' );
				}

				\WP_CLI::success( 'Properties are in place.' );
			},
			array( 'shortdesc' => 'Create the nvr_* custom properties in HubSpot.' )
		);

		\WP_CLI::add_command(
			'nvr-hs queue',
			function (): void {
				$stats = $this->queue->run();
				\WP_CLI::success(
					sprintf(
						'done %d, retried %d, failed %d',
						$stats['done'],
						$stats['retried'],
						$stats['failed']
					)
				);
			},
			array( 'shortdesc' => 'Drain the job queue once, in the foreground.' )
		);

		\WP_CLI::add_command(
			'nvr-hs sweep',
			function (): void {
				$this->carts->sweep();
				\WP_CLI::success( 'Cart sweep complete.' );
			},
			array( 'shortdesc' => 'Mark quiet carts as abandoned now.' )
		);

		\WP_CLI::add_command(
			'nvr-hs segment',
			function ( array $args ): void {
				$email = strtolower( (string) ( $args[0] ?? '' ) );

				if ( '' === $email ) {
					\WP_CLI::error( 'Pass a billing email.' );
				}

				$metrics = $this->segmentation->metrics_for( $email );

				foreach ( $metrics as $key => $value ) {
					\WP_CLI::log( sprintf( '%-12s %s', $key, is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) );
				}
			},
			array( 'shortdesc' => 'Show computed metrics for one customer, without writing.' )
		);
	}
}
