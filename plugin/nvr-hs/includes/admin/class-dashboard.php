<?php
/**
 * Sales and funnel dashboard.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs\Admin;

use NvrHs\Logger;
use NvrHs\Queue;
use NvrHs\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the reporting screen.
 *
 * Plain HTML and a little inline CSS, no chart library. The bars are divs
 * sized by percentage, which is honest about the data and means the page has
 * no external dependency and nothing to load.
 */
final class Dashboard {

	public const SLUG = 'nvr-hs-dashboard';

	private Settings $settings;
	private Queue $queue;
	private Logger $logger;
	private Funnel $funnel;

	public function __construct( Settings $settings, Queue $queue, Logger $logger ) {
		$this->settings = $settings;
		$this->queue    = $queue;
		$this->logger   = $logger;
		$this->funnel   = new Funnel();
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
	}

	public function add_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Navira HubSpot report', 'nvr-hs' ),
			__( 'Navira report', 'nvr-hs' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this.', 'nvr-hs' ) );
		}

		$days = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30;
		$days = in_array( $days, array( 7, 30, 90, 365 ), true ) ? $days : 30;

		$report = $this->funnel->report( $days );
		$carts  = $report['carts'];
		$orders = $report['orders'];

		echo '<div class="wrap"><h1>' . esc_html__( 'Navira HubSpot report', 'nvr-hs' ) . '</h1>';

		$this->styles();
		$this->range_nav( $days );

		// Headline numbers.
		echo '<div class="nvr-cards">';
		$this->card( __( 'Revenue', 'nvr-hs' ), wp_strip_all_tags( wc_price( (float) $orders['revenue'] ) ) );
		$this->card( __( 'Paid orders', 'nvr-hs' ), (string) $orders['paid'] );
		$this->card( __( 'Average order', 'nvr-hs' ), wp_strip_all_tags( wc_price( (float) $orders['aov'] ) ) );
		$this->card( __( 'Deals created', 'nvr-hs' ), (string) $orders['with_deals'] );
		echo '</div>';

		// Funnel.
		echo '<h2>' . esc_html__( 'Conversion funnel', 'nvr-hs' ) . '</h2>';

		if ( 0 === (int) $carts['created'] && 0 === (int) $orders['placed'] ) {
			printf(
				'<p class="nvr-empty">%s</p>',
				esc_html__( 'No carts or orders in this window yet, so there is no funnel to draw. The numbers appear once the store sees traffic.', 'nvr-hs' )
			);
		} else {
			echo '<table class="widefat striped nvr-funnel"><thead><tr>';
			printf( '<th>%s</th>', esc_html__( 'Step', 'nvr-hs' ) );
			printf( '<th>%s</th>', esc_html__( 'Count', 'nvr-hs' ) );
			printf( '<th>%s</th>', esc_html__( 'Of all carts', 'nvr-hs' ) );
			printf( '<th>%s</th>', esc_html__( 'From previous step', 'nvr-hs' ) );
			echo '</tr></thead><tbody>';

			foreach ( $report['funnel'] as $step ) {
				$rate = null === $step['rate'] ? 0.0 : (float) $step['rate'];

				echo '<tr>';
				printf( '<td>%s</td>', esc_html( $step['label'] ) );
				printf( '<td><strong>%d</strong></td>', (int) $step['count'] );
				printf(
					'<td><div class="nvr-bar"><span style="width:%s%%"></span></div>%s</td>',
					esc_attr( (string) min( 100, round( $rate, 1 ) ) ),
					esc_html( sprintf( '%.1f%%', $rate ) )
				);
				printf(
					'<td>%s</td>',
					esc_html( null === $step['step_rate'] ? '-' : sprintf( '%.1f%%', (float) $step['step_rate'] ) )
				);
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		// Cart recovery.
		echo '<h2>' . esc_html__( 'Cart recovery', 'nvr-hs' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		$this->row( __( 'Carts started', 'nvr-hs' ), (string) $carts['created'] );
		$this->row( __( 'Marked abandoned', 'nvr-hs' ), (string) $carts['abandoned'] );
		$this->row( __( 'Recovery triggered', 'nvr-hs' ), (string) $carts['notified'] );
		$this->row(
			__( 'Recovered after a nudge', 'nvr-hs' ),
			sprintf(
				'%d%s',
				(int) $carts['recovered'],
				(int) $carts['notified'] > 0
					? sprintf( ' (%.1f%%)', ( (int) $carts['recovered'] / (int) $carts['notified'] ) * 100 )
					: ''
			)
		);
		$this->row( __( 'Average cart value', 'nvr-hs' ), wp_strip_all_tags( wc_price( (float) $carts['avg_value'] ) ) );
		$this->row(
			__( 'Value still sitting in abandoned carts', 'nvr-hs' ),
			wp_strip_all_tags( wc_price( (float) $carts['lost_value'] ) )
		);
		echo '</tbody></table>';

		// Revenue by day.
		if ( $orders['by_day'] ) {
			echo '<h2>' . esc_html__( 'Revenue by day', 'nvr-hs' ) . '</h2>';
			$peak = max( $orders['by_day'] );

			echo '<table class="widefat striped nvr-days"><tbody>';
			foreach ( $orders['by_day'] as $day => $amount ) {
				printf(
					'<tr><td class="nvr-day">%s</td><td><div class="nvr-bar"><span style="width:%s%%"></span></div></td><td>%s</td></tr>',
					esc_html( $day ),
					esc_attr( (string) ( $peak > 0 ? round( ( $amount / $peak ) * 100, 1 ) : 0 ) ),
					esc_html( wp_strip_all_tags( wc_price( (float) $amount ) ) )
				);
			}
			echo '</tbody></table>';
		}

		// Top products.
		if ( $orders['top_products'] ) {
			echo '<h2>' . esc_html__( 'Top products by revenue', 'nvr-hs' ) . '</h2>';
			echo '<table class="widefat striped"><tbody>';
			foreach ( $orders['top_products'] as $name => $amount ) {
				$this->row( (string) $name, wp_strip_all_tags( wc_price( (float) $amount ) ) );
			}
			echo '</tbody></table>';
		}

		// Sync health.
		$stats  = $this->queue->stats();
		$errors = $this->logger->recent( 10, 'error' );

		echo '<h2>' . esc_html__( 'Sync health', 'nvr-hs' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		$this->row( __( 'Queue pending', 'nvr-hs' ), (string) $stats['pending'] );
		$this->row( __( 'Queue failed', 'nvr-hs' ), (string) $stats['failed'] );
		$this->row(
			__( 'Email sent by', 'nvr-hs' ),
			$this->settings->workflow_email_mode()
				? __( 'HubSpot workflows', 'nvr-hs' )
				: __( 'WordPress mail', 'nvr-hs' )
		);
		echo '</tbody></table>';

		if ( $errors ) {
			echo '<h3>' . esc_html__( 'Recent errors', 'nvr-hs' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr>';
			printf( '<th>%s</th><th>%s</th><th>%s</th>', esc_html__( 'When', 'nvr-hs' ), esc_html__( 'Code', 'nvr-hs' ), esc_html__( 'Message', 'nvr-hs' ) );
			echo '</tr></thead><tbody>';

			foreach ( $errors as $error ) {
				printf(
					'<tr><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
					esc_html( (string) $error->created ),
					esc_html( (string) $error->code ),
					esc_html( (string) $error->message )
				);
			}

			echo '</tbody></table>';
		}

		echo '</div>';
	}

	private function range_nav( int $current ): void {
		echo '<p class="nvr-range">';
		foreach ( array( 7, 30, 90, 365 ) as $days ) {
			$url = add_query_arg(
				array(
					'page' => self::SLUG,
					'days' => $days,
				),
				admin_url( 'admin.php' )
			);

			printf(
				'<a href="%s" class="button %s">%s</a> ',
				esc_url( $url ),
				$days === $current ? 'button-primary' : '',
				esc_html(
					sprintf(
						/* translators: %d: number of days */
						_n( 'Last %d day', 'Last %d days', $days, 'nvr-hs' ),
						$days
					)
				)
			);
		}
		echo '</p>';
	}

	private function card( string $label, string $value ): void {
		printf(
			'<div class="nvr-card"><span class="nvr-card-label">%s</span><span class="nvr-card-value">%s</span></div>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	private function row( string $label, string $value ): void {
		printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}

	/**
	 * Inline because it is a few rules on one screen; a separate stylesheet
	 * would be another request for no benefit.
	 */
	private function styles(): void {
		echo '<style>
			.nvr-cards{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0 24px}
			.nvr-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 16px;min-width:160px}
			.nvr-card-label{display:block;font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.04em}
			.nvr-card-value{display:block;font-size:22px;font-weight:600;margin-top:4px}
			.nvr-bar{display:inline-block;width:180px;height:10px;background:#f0f0f1;border-radius:5px;overflow:hidden;vertical-align:middle;margin-right:8px}
			.nvr-bar span{display:block;height:100%;background:#2271b1}
			.nvr-funnel td,.nvr-days td{vertical-align:middle}
			.nvr-day{width:110px;font-variant-numeric:tabular-nums}
			.nvr-empty{padding:12px;background:#fff;border-left:4px solid #72aee6}
			.nvr-range{margin:12px 0}
		</style>';
	}
}
