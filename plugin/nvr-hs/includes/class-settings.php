<?php
/**
 * Settings storage and access.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes plugin configuration.
 *
 * The token is kept apart from the rest of the settings and encrypted at rest,
 * because everything else here is harmless config that we happily dump into
 * logs and the dashboard, and the token is not.
 */
final class Settings {

	public const OPTION       = 'nvr_hs_settings';
	public const OPTION_TOKEN = 'nvr_hs_token';

	/** @var array<string,mixed>|null */
	private ?array $cache = null;

	/**
	 * Rough units-per-USD, used only to scale the money defaults.
	 *
	 * Not an FX rate and never used for conversion: it exists so a store
	 * trading in rupiah does not inherit a "high value" threshold of 1000 IDR,
	 * which is about six cents and would push every single order into the deal
	 * pipeline. Order of magnitude is all that matters here, and the admin is
	 * expected to tune the actual numbers on the settings screen.
	 */
	private const CURRENCY_SCALE = array(
		'IDR' => 16000.0,
		'VND' => 25000.0,
		'KRW' => 1300.0,
		'JPY' => 150.0,
		'INR' => 84.0,
		'PHP' => 58.0,
		'THB' => 36.0,
		'MYR' => 4.7,
		'CNY' => 7.2,
		'BRL' => 5.4,
		'ZAR' => 18.0,
		'AUD' => 1.5,
		'SGD' => 1.35,
		'USD' => 1.0,
		'EUR' => 1.0,
		'GBP' => 1.0,
	);

	/**
	 * Scale a USD-shaped default into the store's currency, rounded to
	 * something a human would have typed.
	 */
	private static function money( float $usd ): float {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		$scale     = self::CURRENCY_SCALE[ $currency ] ?? 1.0;
		$value     = $usd * $scale;

		// Round to two significant figures so we get 1,600,000 rather than
		// 1,600,000.00000001, and 1000 stays 1000.
		if ( $value >= 100 ) {
			$magnitude = 10 ** ( floor( log10( $value ) ) - 1 );
			return round( $value / $magnitude ) * $magnitude;
		}

		return round( $value, 2 );
	}

	/**
	 * Defaults reflect the real Navira portal (247211579) so a fresh install
	 * is already pointed at the right pipeline instead of an empty select.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// A lead worth at least this much (store currency) gets a deal.
			'high_value_threshold' => self::money( 200.0 ),

			// Sales Pipeline in portal 247211579.
			'deal_pipeline'        => 'default',
			'deal_stage_new'       => '4224208578', // Prospecting
			'deal_stage_won'       => 'closedwon',
			'deal_stage_lost'      => 'closedlost',
			'deal_owner_id'        => '',

			// Minutes of inactivity before a cart counts as abandoned.
			'cart_abandon_minutes' => 60,

			// 'workflow' hands off to HubSpot workflows by writing contact
			// properties. 'woocommerce' sends from WordPress instead, for
			// portals without Marketing Hub Professional.
			'email_mode'           => 'workflow',

			// Customer tiers, evaluated highest first on lifetime value.
			'tier_vip_ltv'         => self::money( 1000.0 ),
			'tier_repeat_orders'   => 2,
			'churn_risk_days'      => 90,

			'log_level'            => 'error', // error | debug
			'log_retention_days'   => 30,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return $this->cache;
	}

	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->all()[ $key ] ?? $fallback;
	}

	public function float( string $key ): float {
		return (float) $this->get( $key, 0 );
	}

	public function int( string $key ): int {
		return (int) $this->get( $key, 0 );
	}

	public function str( string $key ): string {
		return (string) $this->get( $key, '' );
	}

	/**
	 * @param array<string,mixed> $values Sanitised values to merge in.
	 */
	public function update( array $values ): void {
		$merged = wp_parse_args( $values, $this->all() );
		update_option( self::OPTION, $merged, false );
		$this->cache = null;
	}

	/**
	 * The HubSpot private app token.
	 *
	 * Resolution order matters. A constant or environment variable wins over
	 * the database so the token can live in fnox and reach PHP through the
	 * container environment, never touching wp_options. The encrypted option
	 * is the fallback for people who would rather paste it into the admin.
	 */
	public function token(): string {
		if ( defined( 'NVR_HS_TOKEN' ) && is_string( \NVR_HS_TOKEN ) && '' !== \NVR_HS_TOKEN ) {
			return \NVR_HS_TOKEN;
		}

		$env = getenv( 'NVR_HS_TOKEN' );
		if ( is_string( $env ) && '' !== $env ) {
			return $env;
		}

		$stored = get_option( self::OPTION_TOKEN, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}

		return self::decrypt( $stored );
	}

	/**
	 * Where the active token came from, for the settings screen to show.
	 */
	public function token_source(): string {
		if ( defined( 'NVR_HS_TOKEN' ) && '' !== (string) \NVR_HS_TOKEN ) {
			return 'constant';
		}
		if ( '' !== (string) getenv( 'NVR_HS_TOKEN' ) ) {
			return 'environment';
		}
		if ( '' !== (string) get_option( self::OPTION_TOKEN, '' ) ) {
			return 'database';
		}
		return 'unset';
	}

	public function set_token( string $token ): void {
		$token = trim( $token );

		if ( '' === $token ) {
			delete_option( self::OPTION_TOKEN );
			return;
		}

		update_option( self::OPTION_TOKEN, self::encrypt( $token ), false );
	}

	/**
	 * Never show the token back to the browser in full.
	 */
	public function token_hint(): string {
		$token = $this->token();
		if ( '' === $token ) {
			return '';
		}
		return substr( $token, 0, 8 ) . str_repeat( '*', 8 ) . substr( $token, -4 );
	}

	/**
	 * Encryption key derived from the site's own salts, so a database dump on
	 * its own is not enough to read the token back.
	 */
	private static function key(): string {
		$material = ( defined( 'AUTH_KEY' ) ? \AUTH_KEY : '' )
			. ( defined( 'AUTH_SALT' ) ? \AUTH_SALT : '' );

		if ( '' === $material ) {
			// No salts defined is abnormal, but fall back to something
			// site-specific rather than a constant key.
			$material = (string) get_option( 'siteurl' ) . ABSPATH;
		}

		return hash( 'sha256', 'nvr-hs|' . $material, true );
	}

	private static function encrypt( string $plain ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// Better stored readable than not stored at all, but say so.
			return 'plain:' . base64_encode( $plain );
		}

		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return 'plain:' . base64_encode( $plain );
		}

		return 'v1:' . base64_encode( $iv . $cipher );
	}

	private static function decrypt( string $stored ): string {
		if ( str_starts_with( $stored, 'plain:' ) ) {
			return (string) base64_decode( substr( $stored, 6 ), true );
		}

		if ( ! str_starts_with( $stored, 'v1:' ) ) {
			return '';
		}

		$blob = base64_decode( substr( $stored, 3 ), true );
		if ( false === $blob || strlen( $blob ) <= 16 ) {
			return '';
		}

		$plain = openssl_decrypt(
			substr( $blob, 16 ),
			'aes-256-cbc',
			self::key(),
			OPENSSL_RAW_DATA,
			substr( $blob, 0, 16 )
		);

		return false === $plain ? '' : $plain;
	}

	/**
	 * True when HubSpot workflows are expected to send the recovery and
	 * post-purchase email, false when this plugin sends it via WooCommerce.
	 */
	public function workflow_email_mode(): bool {
		return 'workflow' === $this->str( 'email_mode' );
	}
}
