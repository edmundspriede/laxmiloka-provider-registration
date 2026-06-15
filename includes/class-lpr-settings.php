<?php
/**
 * Settings page (Settings → Astrologer Registration).
 *
 * Lets an admin toggle the e-mail OTP step and set the webhook URL without
 * touching code. Both can still be hard-overridden from wp-config.php via the
 * LPR_OTP_ENABLED / LPR_WEBHOOK_URL constants, in which case the matching field
 * is shown read-only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LPR_Settings {

	const GROUP = 'lpr_settings';
	const PAGE  = 'lpr-settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	public function menu() {
		add_options_page(
			__( 'Astrologer Registration', 'laxmiloka-provider-registration' ),
			__( 'Astrologer Registration', 'laxmiloka-provider-registration' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	public function register() {
		register_setting(
			self::GROUP,
			'lpr_otp_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'default'           => true,
			)
		);

		register_setting(
			self::GROUP,
			'lpr_webhook_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
	}

	public function sanitize_bool( $value ) {
		return ! empty( $value ) ? 1 : 0;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$otp_const     = defined( 'LPR_OTP_ENABLED' );
		$webhook_const = defined( 'LPR_WEBHOOK_URL' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Astrologer Registration', 'laxmiloka-provider-registration' ); ?></h1>

			<p><?php esc_html_e( 'Place the [astrologer_registration] shortcode on any page to show the form.', 'laxmiloka-provider-registration' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'E-mail OTP verification', 'laxmiloka-provider-registration' ); ?></th>
						<td>
							<?php if ( $otp_const ) : ?>
								<em><?php echo LPR_OTP_ENABLED ? esc_html__( 'Enabled (set in wp-config.php)', 'laxmiloka-provider-registration' ) : esc_html__( 'Disabled (set in wp-config.php)', 'laxmiloka-provider-registration' ); ?></em>
							<?php else : ?>
								<label>
									<input type="checkbox" name="lpr_otp_enabled" value="1" <?php checked( get_option( 'lpr_otp_enabled', true ) ); ?>>
									<?php esc_html_e( 'Require users to verify their e-mail with a code before registering.', 'laxmiloka-provider-registration' ); ?>
								</label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'laxmiloka-provider-registration' ); ?></th>
						<td>
							<?php if ( $webhook_const ) : ?>
								<em><?php echo esc_html( LPR_WEBHOOK_URL ? LPR_WEBHOOK_URL : __( '(empty, set in wp-config.php)', 'laxmiloka-provider-registration' ) ); ?></em>
							<?php else : ?>
								<input type="url" class="regular-text" name="lpr_webhook_url" value="<?php echo esc_attr( get_option( 'lpr_webhook_url', '' ) ); ?>" placeholder="https://example.com/webhook">
								<p class="description"><?php esc_html_e( 'POSTed with the new user id after each successful registration. Leave empty to disable.', 'laxmiloka-provider-registration' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
