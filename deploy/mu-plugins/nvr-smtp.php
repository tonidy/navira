<?php
/**
 * Plugin Name: Navira SMTP
 * Description: Routes wp_mail through SMTP instead of the sendmail binary, which does not exist in the WordPress container.
 * Version:     1.0.0
 *
 * A must-use plugin rather than a normal one, so it cannot be deactivated by
 * accident and leave the site silently unable to send mail.
 *
 * Configured entirely from the environment, so the same file works against
 * Mailpit locally and a real provider later:
 *
 *   NVR_SMTP_HOST  hostname, e.g. "mailpit". Unset means do nothing.
 *   NVR_SMTP_PORT  port, default 1025
 *   NVR_SMTP_USER  optional username
 *   NVR_SMTP_PASS  optional password
 *   NVR_SMTP_TLS   "1" to require STARTTLS
 *   NVR_MAIL_FROM  envelope and header From address
 *   NVR_MAIL_NAME  From display name
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Read a setting from the environment, falling back to a constant so it can
 * also be set in wp-config.php.
 */
function nvr_smtp_config( string $name, string $default = '' ): string {
	$env = getenv( $name );

	if ( is_string( $env ) && '' !== $env ) {
		return $env;
	}

	if ( defined( $name ) ) {
		return (string) constant( $name );
	}

	return $default;
}

add_action(
	'phpmailer_init',
	static function ( $phpmailer ): void {
		$host = nvr_smtp_config( 'NVR_SMTP_HOST' );

		// No host configured: leave PHPMailer alone entirely rather than
		// half-configuring it.
		if ( '' === $host ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host    = $host;
		$phpmailer->Port    = (int) nvr_smtp_config( 'NVR_SMTP_PORT', '1025' );
		$phpmailer->Timeout = 10;

		$user = nvr_smtp_config( 'NVR_SMTP_USER' );
		$pass = nvr_smtp_config( 'NVR_SMTP_PASS' );

		if ( '' !== $user ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $user;
			$phpmailer->Password = $pass;
		} else {
			$phpmailer->SMTPAuth = false;
		}

		if ( '1' === nvr_smtp_config( 'NVR_SMTP_TLS' ) ) {
			$phpmailer->SMTPSecure = 'tls';
		} else {
			// Mailpit speaks plain SMTP on the compose network. Leaving
			// SMTPAutoTLS on makes PHPMailer try STARTTLS anyway and fail.
			$phpmailer->SMTPSecure = '';
			$phpmailer->SMTPAutoTLS = false;
		}
	},
	5
);

// WordPress defaults to wordpress@<host>, which real providers reject and
// which makes a caught inbox harder to read.
add_filter(
	'wp_mail_from',
	static function ( $from ) {
		$configured = nvr_smtp_config( 'NVR_MAIL_FROM' );
		return '' !== $configured ? $configured : $from;
	}
);

add_filter(
	'wp_mail_from_name',
	static function ( $name ) {
		$configured = nvr_smtp_config( 'NVR_MAIL_NAME' );
		return '' !== $configured ? $configured : $name;
	}
);

// Surface failures. Without this a rejected send is invisible: wp_mail just
// returns false and nothing says why.
add_action(
	'wp_mail_failed',
	static function ( $error ): void {
		if ( ! is_wp_error( $error ) ) {
			return;
		}

		if ( class_exists( 'NvrHs\\Logger' ) && class_exists( 'NvrHs\\Settings' ) ) {
			( new NvrHs\Logger( new NvrHs\Settings() ) )->error(
				'wp_mail_failed',
				$error->get_error_message(),
				array( 'code' => $error->get_error_code() )
			);
			return;
		}

		error_log( 'nvr-smtp: ' . $error->get_error_message() );
	}
);
