<?php
/**
 * Settings screen.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs;

use NvrHs\Api\Client;
use NvrHs\Api\Properties;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's configuration screen.
 *
 * Hand-rolled rather than via the Settings API because there are three
 * actions on this page (save, test connection, create properties) and the
 * Settings API only really wants one.
 */
final class Settings_Page {

	public const SLUG  = 'nvr-hs-settings';
	private const NONCE = 'nvr_hs_settings';

	private Settings $settings;
	private Client $client;
	private Properties $properties;
	private Queue $queue;

	/** @var array<int,array{type:string,text:string}> */
	private array $notices = array();

	public function __construct( Settings $settings, Client $client, Properties $properties, Queue $queue ) {
		$this->settings   = $settings;
		$this->client     = $client;
		$this->properties = $properties;
		$this->queue      = $queue;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
	}

	public function add_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Navira HubSpot', 'nvr-hs' ),
			__( 'Navira HubSpot', 'nvr-hs' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this.', 'nvr-hs' ) );
		}

		$this->handle_post();

		$s = $this->settings->all();

		echo '<div class="wrap"><h1>' . esc_html__( 'Navira HubSpot', 'nvr-hs' ) . '</h1>';

		foreach ( $this->notices as $notice ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['text'] )
			);
		}

		$source = $this->settings->token_source();
		$hint   = $this->settings->token_hint();

		echo '<h2>' . esc_html__( 'Connection', 'nvr-hs' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%s</th><td><code>%s</code><p class="description">%s</p></td></tr>',
			esc_html__( 'Token in use', 'nvr-hs' ),
			esc_html( '' === $hint ? __( 'not set', 'nvr-hs' ) : $hint ),
			esc_html( $this->describe_source( $source ) )
		);

		echo '</tbody></table>';

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );

		if ( 'constant' !== $source && 'environment' !== $source ) {
			echo '<table class="form-table" role="presentation"><tbody>';
			printf(
				'<tr><th scope="row"><label for="nvr_token">%s</label></th><td>
					<input type="password" id="nvr_token" name="nvr_token" class="regular-text" autocomplete="off" placeholder="pat-na2-...">
					<p class="description">%s</p></td></tr>',
				esc_html__( 'Private app token', 'nvr-hs' ),
				esc_html__( 'Stored encrypted. Leave blank to keep the current token.', 'nvr-hs' )
			);
			echo '</tbody></table>';
		}

		submit_button( __( 'Save settings', 'nvr-hs' ), 'primary', 'nvr_save', false );
		echo ' ';
		submit_button( __( 'Test connection', 'nvr-hs' ), 'secondary', 'nvr_test', false );
		echo ' ';
		submit_button( __( 'Create HubSpot properties', 'nvr-hs' ), 'secondary', 'nvr_props', false );

		echo '<h2>' . esc_html__( 'Deal pipeline', 'nvr-hs' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->number_row(
			'high_value_threshold',
			__( 'High-value threshold', 'nvr-hs' ),
			(string) $s['high_value_threshold'],
			sprintf(
				/* translators: %s: store currency code */
				__( 'Orders at or above this %s value create a deal. Smaller orders are skipped so the pipeline stays worth looking at.', 'nvr-hs' ),
				get_woocommerce_currency()
			),
			'0.01'
		);

		$this->text_row( 'deal_pipeline', __( 'Pipeline ID', 'nvr-hs' ), (string) $s['deal_pipeline'] );
		$this->text_row( 'deal_stage_new', __( 'Stage for a new deal', 'nvr-hs' ), (string) $s['deal_stage_new'], 'Prospecting = 4224208578' );
		$this->text_row( 'deal_stage_won', __( 'Stage when completed', 'nvr-hs' ), (string) $s['deal_stage_won'] );
		$this->text_row( 'deal_stage_lost', __( 'Stage when cancelled or refunded', 'nvr-hs' ), (string) $s['deal_stage_lost'] );
		$this->text_row( 'deal_owner_id', __( 'Deal owner ID', 'nvr-hs' ), (string) $s['deal_owner_id'], __( 'Optional. Leave blank to let HubSpot assign.', 'nvr-hs' ) );

		$this->text_row(
			'deal_currency_code',
			__( 'Deal currency code', 'nvr-hs' ),
			(string) $s['deal_currency_code'],
			sprintf(
				/* translators: %s: store currency code */
				__( 'Leave blank unless your portal has more than one currency enabled. This store trades in %s, so the cleanest setup is to make that the portal company currency and leave this empty. A code the portal does not have enabled is rejected and fails the whole deal.', 'nvr-hs' ),
				get_woocommerce_currency()
			)
		);

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Cart recovery and follow-up', 'nvr-hs' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->number_row(
			'cart_abandon_minutes',
			__( 'Minutes before a cart is abandoned', 'nvr-hs' ),
			(string) $s['cart_abandon_minutes'],
			__( 'Counted from the last cart change. Minimum 5.', 'nvr-hs' ),
			'1'
		);

		printf(
			'<tr><th scope="row">%s</th><td>
				<label><input type="radio" name="email_mode" value="workflow" %s> %s</label><br>
				<label><input type="radio" name="email_mode" value="woocommerce" %s> %s</label>
				<p class="description">%s</p></td></tr>',
			esc_html__( 'Who sends the email', 'nvr-hs' ),
			checked( 'workflow', $s['email_mode'], false ),
			esc_html__( 'HubSpot workflows', 'nvr-hs' ),
			checked( 'woocommerce', $s['email_mode'], false ),
			esc_html__( 'This plugin, via WordPress mail', 'nvr-hs' ),
			esc_html__( 'Workflow mode only writes contact properties and lets HubSpot send, which needs Marketing Hub Professional. Pick WordPress mail on a free or Starter portal.', 'nvr-hs' )
		);

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Segmentation', 'nvr-hs' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->number_row( 'tier_vip_ltv', __( 'VIP lifetime value', 'nvr-hs' ), (string) $s['tier_vip_ltv'], '', '0.01' );
		$this->number_row( 'tier_repeat_orders', __( 'Orders to count as repeat', 'nvr-hs' ), (string) $s['tier_repeat_orders'], '', '1' );
		$this->number_row(
			'churn_risk_days',
			__( 'Days idle before at risk', 'nvr-hs' ),
			(string) $s['churn_risk_days'],
			__( 'Twice this many days marks the customer churned. Recency is checked before value, so a big spender who went quiet shows as at risk rather than VIP.', 'nvr-hs' ),
			'1'
		);

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Diagnostics', 'nvr-hs' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%s</th><td>
				<select name="log_level"><option value="error" %s>%s</option><option value="debug" %s>%s</option></select></td></tr>',
			esc_html__( 'Log level', 'nvr-hs' ),
			selected( 'error', $s['log_level'], false ),
			esc_html__( 'Errors only', 'nvr-hs' ),
			selected( 'debug', $s['log_level'], false ),
			esc_html__( 'Everything (noisy)', 'nvr-hs' )
		);

		$stats = $this->queue->stats();
		printf(
			'<tr><th scope="row">%s</th><td><code>%s</code></td></tr>',
			esc_html__( 'Queue', 'nvr-hs' ),
			esc_html(
				sprintf(
					'pending %d, running %d, done %d, failed %d',
					$stats['pending'],
					$stats['running'],
					$stats['done'],
					$stats['failed']
				)
			)
		);

		echo '</tbody></table>';

		submit_button( __( 'Save settings', 'nvr-hs' ) );
		echo '</form></div>';
	}

	private function handle_post(): void {
		if ( ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_POST['nvr_test'] ) ) {
			$result          = $this->client->connection_test();
			$this->notices[] = array(
				'type' => $result['ok'] ? 'success' : 'error',
				'text' => $result['message'],
			);
			return;
		}

		if ( isset( $_POST['nvr_props'] ) ) {
			$result = $this->properties->ensure();

			$this->notices[] = array(
				'type' => $result['errors'] ? 'warning' : 'success',
				'text' => sprintf(
					/* translators: 1: created count, 2: existing count, 3: error count */
					__( '%1$d properties created, %2$d already present, %3$d failed.', 'nvr-hs' ),
					count( $result['created'] ),
					count( $result['existing'] ),
					count( $result['errors'] )
				),
			);

			foreach ( array_slice( $result['errors'], 0, 3 ) as $error ) {
				$this->notices[] = array(
					'type' => 'error',
					'text' => $error,
				);
			}
			return;
		}

		// Plain save.
		$token = isset( $_POST['nvr_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['nvr_token'] ) ) : '';

		if ( '' !== $token ) {
			$this->settings->set_token( $token );
		}

		$this->settings->update(
			array(
				'high_value_threshold' => (float) ( $_POST['high_value_threshold'] ?? 0 ),
				'deal_pipeline'        => sanitize_text_field( wp_unslash( (string) ( $_POST['deal_pipeline'] ?? '' ) ) ),
				'deal_stage_new'       => sanitize_text_field( wp_unslash( (string) ( $_POST['deal_stage_new'] ?? '' ) ) ),
				'deal_stage_won'       => sanitize_text_field( wp_unslash( (string) ( $_POST['deal_stage_won'] ?? '' ) ) ),
				'deal_stage_lost'      => sanitize_text_field( wp_unslash( (string) ( $_POST['deal_stage_lost'] ?? '' ) ) ),
				'deal_owner_id'        => sanitize_text_field( wp_unslash( (string) ( $_POST['deal_owner_id'] ?? '' ) ) ),
				'deal_currency_code'   => strtoupper( sanitize_text_field( wp_unslash( (string) ( $_POST['deal_currency_code'] ?? '' ) ) ) ),
				'cart_abandon_minutes' => max( 5, (int) ( $_POST['cart_abandon_minutes'] ?? 60 ) ),
				'email_mode'           => 'woocommerce' === ( $_POST['email_mode'] ?? '' ) ? 'woocommerce' : 'workflow',
				'tier_vip_ltv'         => (float) ( $_POST['tier_vip_ltv'] ?? 0 ),
				'tier_repeat_orders'   => max( 2, (int) ( $_POST['tier_repeat_orders'] ?? 2 ) ),
				'churn_risk_days'      => max( 1, (int) ( $_POST['churn_risk_days'] ?? 90 ) ),
				'log_level'            => 'debug' === ( $_POST['log_level'] ?? '' ) ? 'debug' : 'error',
			)
		);

		$this->notices[] = array(
			'type' => 'success',
			'text' => __( 'Settings saved.', 'nvr-hs' ),
		);
	}

	private function describe_source( string $source ): string {
		return match ( $source ) {
			'constant'    => __( 'From the NVR_HS_TOKEN constant in wp-config.php. The field below is hidden because the constant wins.', 'nvr-hs' ),
			'environment' => __( 'From the NVR_HS_TOKEN environment variable, so it never touches the database. The field below is hidden because the environment wins.', 'nvr-hs' ),
			'database'    => __( 'Stored encrypted in the database.', 'nvr-hs' ),
			default       => __( 'No token yet. Create a private app in HubSpot and paste its token below.', 'nvr-hs' ),
		};
	}

	private function text_row( string $name, string $label, string $value, string $description = '' ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>
				<input type="text" id="%1$s" name="%1$s" value="%3$s" class="regular-text">%4$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			'' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>'
		);
	}

	private function number_row( string $name, string $label, string $value, string $description = '', string $step = '1' ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>
				<input type="number" step="%5$s" min="0" id="%1$s" name="%1$s" value="%3$s" class="small-text">%4$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			'' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>',
			esc_attr( $step )
		);
	}
}
