<?php
/**
 * Plugin Name:       Laxmiloka Provider Registration
 * Description:       Two-step frontend registration form (Interactivity API) that creates an Astrologer WordPress user, a matching "Astrologer" CPT entry, links them through a JetEngine relation and fires a configurable webhook.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Ed
 * Text Domain:       laxmiloka-provider-registration
 *
 * Usage: place the [astrologer_registration] shortcode on any page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LPR_VERSION', '1.0.0' );
define( 'LPR_FILE', __FILE__ );
define( 'LPR_PATH', plugin_dir_path( __FILE__ ) );
define( 'LPR_URL', plugin_dir_url( __FILE__ ) );

/*
 * JetEngine relation linking the new user (parent) to the Astrologer CPT (child).
 * Override in wp-config.php with: define( 'LPR_RELATION_ID', 120 );
 */
if ( ! defined( 'LPR_RELATION_ID' ) ) {
	define( 'LPR_RELATION_ID', 120 );
}

/*
 * Enable / disable the e-mail OTP verification step.
 * Hard-override in wp-config.php with: define( 'LPR_OTP_ENABLED', false );
 * Otherwise it falls back to the "lpr_otp_enabled" option (default: on).
 */
// define( 'LPR_OTP_ENABLED', true );

require_once LPR_PATH . 'includes/class-lpr-ajax.php';
require_once LPR_PATH . 'includes/class-lpr-settings.php';

final class LPR_Plugin {

	const STORE     = 'laxmiloka-provider-registration';
	const ROLE      = 'astrologer';
	const CPT       = 'astrologer';

	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'init', array( $this, 'register_assets' ) );
		add_shortcode( 'astrologer_registration', array( $this, 'render_shortcode' ) );

		new LPR_Ajax();
		new LPR_Settings();
	}

	/* ------------------------------------------------------------------ *
	 * Configuration helpers (PHP-level, all filterable).
	 * ------------------------------------------------------------------ */

	/** Is the e-mail OTP step active? */
	public static function otp_enabled() {
		$enabled = defined( 'LPR_OTP_ENABLED' )
			? (bool) LPR_OTP_ENABLED
			: (bool) get_option( 'lpr_otp_enabled', false );

		return (bool) apply_filters( 'lpr/otp_enabled', $enabled );
	}

	/** Webhook fired after a successful registration (receives the user id). */
	public static function webhook_url() {
		$url = defined( 'LPR_WEBHOOK_URL' )
			? LPR_WEBHOOK_URL
			: get_option( 'lpr_webhook_url', '' );

		return esc_url_raw( apply_filters( 'lpr/webhook_url', $url ) );
	}

	/** JetEngine relation id (parent: user, child: astrologer CPT). */
	public static function relation_id() {
		return (int) apply_filters( 'lpr/relation_id', LPR_RELATION_ID );
	}

	/** Taxonomy slugs / meta keys used on page 2. */
	public static function country_taxonomy() {
		return apply_filters( 'lpr/country_taxonomy', 'tcountries' );
	}

	public static function language_taxonomy() {
		return apply_filters( 'lpr/language_taxonomy', 'languages' );
	}

	public static function timezone_taxonomy() {
		return apply_filters( 'lpr/timezone_taxonomy', 'ttimezones' );
	}

	public static function flag_meta_key() {
		return apply_filters( 'lpr/flag_meta_key', 'flag' );
	}

	/* ------------------------------------------------------------------ *
	 * Activation — register the Astrologer role.
	 * ------------------------------------------------------------------ */

	public static function activate() {
		if ( ! get_role( self::ROLE ) ) {
			add_role(
				self::ROLE,
				__( 'Astrologer', 'laxmiloka-provider-registration' ),
				array(
					'read'         => true,
					'upload_files' => true,
				)
			);
		}
	}

	/* ------------------------------------------------------------------ *
	 * CPT — only register if something else (e.g. JetEngine) hasn't.
	 * ------------------------------------------------------------------ */

	public function register_cpt() {
		$slug = apply_filters( 'lpr/cpt_slug', self::CPT );

		if ( post_type_exists( $slug ) ) {
			return;
		}

		register_post_type(
			$slug,
			array(
				'labels'       => array(
					'name'          => __( 'Astrologers', 'laxmiloka-provider-registration' ),
					'singular_name' => __( 'Astrologer', 'laxmiloka-provider-registration' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-star-filled',
				'supports'     => array( 'title', 'editor', 'author', 'thumbnail', 'custom-fields' ),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Assets — Interactivity API script module + stylesheet.
	 * ------------------------------------------------------------------ */

	public function register_assets() {
		wp_register_script_module(
			'lpr-frontend',
			LPR_URL . 'assets/js/frontend.js',
			array( '@wordpress/interactivity', 'lpn-popups' ),
			LPR_VERSION
		);

		wp_register_style(
			'lpr-frontend',
			LPR_URL . 'assets/css/frontend.css',
			array(),
			LPR_VERSION
		);
	}

	/* ------------------------------------------------------------------ *
	 * Shortcode.
	 * ------------------------------------------------------------------ */

	public function render_shortcode() {

		wp_enqueue_script_module( 'lpr-frontend' );
		wp_enqueue_style( 'lpr-frontend' );

		// Global state consumed by assets/js/frontend.js.
		wp_interactivity_state(
			self::STORE,
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'lpr_nonce' ),
				'page'            => 1,
				'ready'           => false,
				'loading'         => false,
				'created'         => false,
				'createdMessage'  => '',

				// E-mail OTP.
				'otpEnabled'      => self::otp_enabled(),
				'otpSent'         => false,
				'otpVerified'     => false,
				'otpSending'      => false,
				'otpVerifying'    => false,
				'otpError'        => '',
				'otpNotice'       => '',
				'otpCode'         => '',

				// Misc UI.
				'showPassword'    => false,
				'countrySearch'   => '',
				'languageSearch'  => '',
				'timezoneSearch'  => '',

				// Taxonomy data (loaded lazily via admin-ajax).
				'countries'       => array(),
				'countriesLoaded' => false,
				'languages'       => array(),
				'languagesLoaded' => false,
				'timezones'       => array(),
				'timezonesLoaded' => false,

				'form'            => array(
					'firstName' => '',
					'lastName'  => '',
					'email'     => '',
					'phone'     => '',
					'password'  => '',
					'country'   => 0,
					'languages' => array(),
					'timezone'  => 0,
					'about'     => '',
				),
			)
		);

		ob_start();
		$this->form_markup();
		$html = ob_get_clean();

		return wp_interactivity_process_directives( $html );
	}

	/* ------------------------------------------------------------------ *
	 * Markup — all behaviour lives in assets/js/frontend.js.
	 * ------------------------------------------------------------------ */

	private function form_markup() {
		$steps = array(
			1 => __( 'Personal Details', 'laxmiloka-provider-registration' ),
			2 => __( 'Settings', 'laxmiloka-provider-registration' ),
		);
		?>
		<?php $this->skeleton_css(); ?>
		<div class="lpr-form"
			data-wp-interactive="<?php echo esc_attr( self::STORE ); ?>"
			data-wp-class--is-ready="state.ready"
			data-wp-init="callbacks.init">

			<noscript>
				<p class="lpr-no-access"><?php esc_html_e( 'This form requires JavaScript.', 'laxmiloka-provider-registration' ); ?></p>
			</noscript>

			<!-- Skeleton: hidden by frontend.css once .is-ready is set by JS -->
			<div class="lpr-skeleton" aria-hidden="true">
				<div class="lpr-skeleton__steps"><span></span><span></span></div>
				<div class="lpr-skeleton__bar"></div>
				<div class="lpr-skeleton__card">
					<span class="lpr-skeleton__line lpr-skeleton__line--title"></span>
					<span class="lpr-skeleton__line lpr-skeleton__line--label"></span>
					<span class="lpr-skeleton__line lpr-skeleton__line--input"></span>
					<span class="lpr-skeleton__line lpr-skeleton__line--label"></span>
					<span class="lpr-skeleton__line lpr-skeleton__line--input"></span>
				</div>
				<div class="lpr-skeleton__nav"><span></span><span></span></div>
			</div>

			<div class="lpr-ui">

			<!-- Success panel -->
			<div class="lpr-success" data-wp-bind--hidden="!state.created">
				<h3><?php esc_html_e( 'Registration complete', 'laxmiloka-provider-registration' ); ?></h3>
				<p data-wp-text="state.createdMessage"></p>
				<p class="lpr-success__links">
					<button type="button" class="lpr-btn" data-wp-on--click="actions.reset"><?php esc_html_e( 'Register another', 'laxmiloka-provider-registration' ); ?></button>
				</p>
			</div>

			<div class="lpr-wizard" data-wp-bind--hidden="state.created">

				<!-- Step header -->
				<ol class="lpr-steps">
					<?php foreach ( $steps as $n => $label ) : ?>
						<li class="lpr-steps__item"
							data-wp-class--is-active="state.isPage<?php echo (int) $n; ?>"
							data-wp-class--is-done="state.isDone<?php echo (int) $n; ?>">
							<span class="lpr-steps__num"><?php echo (int) $n; ?></span>
							<span class="lpr-steps__label"><?php echo esc_html( $label ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>

				<!-- ============ PAGE 1: Personal Details ============ -->
				<section class="lpr-page" data-page="1" data-wp-bind--hidden="!state.isPage1">
					<h3 class="lpr-page__title"><?php esc_html_e( 'Personal Details', 'laxmiloka-provider-registration' ); ?></h3>

					<div class="lpr-grid-2">
						<label class="lpr-field lpr-field--required">
							<span class="lpr-field__label"><?php esc_html_e( 'First Name', 'laxmiloka-provider-registration' ); ?></span>
							<input type="text" data-field="firstName" required aria-required="true"
								data-wp-bind--value="state.form.firstName"
								data-wp-on--input="actions.updateField">
						</label>

						<label class="lpr-field lpr-field--required">
							<span class="lpr-field__label"><?php esc_html_e( 'Last Name', 'laxmiloka-provider-registration' ); ?></span>
							<input type="text" data-field="lastName" required aria-required="true"
								data-wp-bind--value="state.form.lastName"
								data-wp-on--input="actions.updateField">
						</label>

						<!-- E-mail + OTP -->
						<div class="lpr-field lpr-field--required">
							<span class="lpr-field__label"><?php esc_html_e( 'Email', 'laxmiloka-provider-registration' ); ?></span>
							<div class="lpr-inline">
								<input type="email" data-field="email" required aria-required="true"
									data-wp-bind--value="state.form.email"
									data-wp-bind--disabled="state.otpVerified"
									data-wp-on--input="actions.updateEmail">
								<button type="button" class="lpr-btn"
									data-wp-bind--hidden="!state.otpEnabled"
									data-wp-bind--disabled="state.otpVerified"
									data-wp-on--click="actions.sendOtp">
									<span data-wp-bind--hidden="state.otpSending"><span data-wp-text="state.sendOtpLabel"></span></span>
									<span data-wp-bind--hidden="!state.otpSending"><?php esc_html_e( 'Sending…', 'laxmiloka-provider-registration' ); ?></span>
								</button>
							</div>

							<!-- OTP entry row, shown after a code is sent -->
							<div class="lpr-otp" data-wp-bind--hidden="!state.otpShowEntry">
								<input type="text" inputmode="numeric" maxlength="6"
									placeholder="<?php esc_attr_e( 'Enter 6-digit code', 'laxmiloka-provider-registration' ); ?>"
									data-wp-bind--value="state.otpCode"
									data-wp-on--input="actions.updateOtpCode">
								<button type="button" class="lpr-btn lpr-btn--primary"
									data-wp-bind--disabled="state.otpVerifying"
									data-wp-on--click="actions.verifyOtp">
									<span data-wp-bind--hidden="state.otpVerifying"><?php esc_html_e( 'Verify', 'laxmiloka-provider-registration' ); ?></span>
									<span data-wp-bind--hidden="!state.otpVerifying"><?php esc_html_e( 'Verifying…', 'laxmiloka-provider-registration' ); ?></span>
								</button>
							</div>

							<span class="lpr-otp__notice" data-wp-bind--hidden="!state.otpNotice" data-wp-text="state.otpNotice"></span>
							<span class="lpr-otp__error" data-wp-bind--hidden="!state.otpError" data-wp-text="state.otpError"></span>
							<span class="lpr-otp__verified" data-wp-bind--hidden="!state.otpVerified"><?php esc_html_e( '✓ Email verified', 'laxmiloka-provider-registration' ); ?></span>
						</div>

						<label class="lpr-field lpr-field--required">
							<span class="lpr-field__label"><?php esc_html_e( 'Phone', 'laxmiloka-provider-registration' ); ?></span>
							<input type="tel" data-field="phone" required aria-required="true"
								data-wp-bind--value="state.form.phone"
								data-wp-on--input="actions.updateField"
								placeholder="+1 555 123 4567">
						</label>

						<!-- Password (spans both columns) -->
						<div class="lpr-field lpr-field--required lpr-col-span">
							<span class="lpr-field__label"><?php esc_html_e( 'Password', 'laxmiloka-provider-registration' ); ?></span>
							<div class="lpr-inline">
								<input data-field="password" required aria-required="true"
									data-wp-bind--type="state.passwordInputType"
									data-wp-bind--value="state.form.password"
									data-wp-on--input="actions.updateField">
								<button type="button" class="lpr-btn lpr-btn--icon" data-wp-on--click="actions.togglePassword"
									aria-label="<?php esc_attr_e( 'Show or hide password', 'laxmiloka-provider-registration' ); ?>">
									<!-- Eye (open): shown while the password is masked -->
									<svg class="lpr-eye" data-wp-bind--hidden="state.showPassword" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
										<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
										<circle cx="12" cy="12" r="3"/>
									</svg>
									<!-- Eye (closed): shown while the password is visible -->
									<svg class="lpr-eye" data-wp-bind--hidden="!state.showPassword" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
										<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
										<line x1="1" y1="1" x2="23" y2="23"/>
									</svg>
								</button>
								<button type="button" class="lpr-btn lpr-btn--link" data-wp-on--click="actions.generatePassword"><?php esc_html_e( 'Generate', 'laxmiloka-provider-registration' ); ?></button>
							</div>

							<ul class="lpr-pwreq">
								<li data-wp-class--is-ok="state.pwLenOk"><?php esc_html_e( 'At least 8 characters', 'laxmiloka-provider-registration' ); ?></li>
								<li data-wp-class--is-ok="state.pwUpperOk"><?php esc_html_e( 'One capital letter', 'laxmiloka-provider-registration' ); ?></li>
								<li data-wp-class--is-ok="state.pwLowerOk"><?php esc_html_e( 'One lowercase letter', 'laxmiloka-provider-registration' ); ?></li>
								<li data-wp-class--is-ok="state.pwDigitOk"><?php esc_html_e( 'One digit', 'laxmiloka-provider-registration' ); ?></li>
							</ul>
						</div>
					</div>
				</section>

				<!-- ============ PAGE 2: Settings ============ -->
				<section class="lpr-page" data-page="2" data-wp-bind--hidden="!state.isPage2">
					<h3 class="lpr-page__title"><?php esc_html_e( 'Settings', 'laxmiloka-provider-registration' ); ?></h3>

					<div class="lpr-grid-2">

					<!-- Country selector -->
					<div class="lpr-field lpr-field--required">
						<span class="lpr-field__label"><?php esc_html_e( 'Country', 'laxmiloka-provider-registration' ); ?></span>

						<div class="lpr-search">
							<input type="search"
								placeholder="<?php esc_attr_e( 'Search countries…', 'laxmiloka-provider-registration' ); ?>"
								data-wp-bind--value="state.countrySearch"
								data-wp-on--input="actions.updateCountrySearch">
							<button type="button" class="lpr-search__clear"
								data-wp-bind--hidden="!state.countrySearch"
								data-wp-on--click="actions.clearCountrySearch"
								aria-label="<?php esc_attr_e( 'Clear search', 'laxmiloka-provider-registration' ); ?>">&times;</button>
						</div>

						<ul class="lpr-list">
							<template data-wp-each--country="state.filteredCountries">
								<li class="lpr-list__item lpr-list__item--country"
									data-wp-class--is-selected="context.country.selected"
									data-wp-on--click="actions.selectCountry">
									<img class="lpr-flag" data-wp-bind--src="context.country.flag" data-wp-bind--hidden="!context.country.flag" alt="">
									<span data-wp-text="context.country.name"></span>
									<span class="lpr-list__check" data-wp-bind--hidden="!context.country.selected">✓</span>
								</li>
							</template>
						</ul>
						<p class="lpr-list__empty" data-wp-bind--hidden="!state.countriesEmpty" data-wp-text="state.countriesEmptyText"></p>
					</div>

					<!-- Languages selector -->
					<div class="lpr-field">
						<span class="lpr-field__label"><?php esc_html_e( 'Languages', 'laxmiloka-provider-registration' ); ?></span>

						<div class="lpr-chips" data-wp-bind--hidden="!state.hasLanguages">
							<template data-wp-each--lang="state.selectedLanguages">
								<span class="lpr-chip">
									<span data-wp-text="context.lang.name"></span>
									<button type="button" class="lpr-chip__remove icon-editor-close" data-wp-on--click="actions.toggleLanguage" aria-label="<?php esc_attr_e( 'Remove', 'laxmiloka-provider-registration' ); ?>"></button>
								</span>
							</template>
						</div>

						<div class="lpr-search">
							<input type="search"
								placeholder="<?php esc_attr_e( 'Search languages…', 'laxmiloka-provider-registration' ); ?>"
								data-wp-bind--value="state.languageSearch"
								data-wp-on--input="actions.updateLanguageSearch">
							<button type="button" class="lpr-search__clear"
								data-wp-bind--hidden="!state.languageSearch"
								data-wp-on--click="actions.clearLanguageSearch"
								aria-label="<?php esc_attr_e( 'Clear search', 'laxmiloka-provider-registration' ); ?>">&times;</button>
						</div>

						<ul class="lpr-list">
							<template data-wp-each--lang="state.filteredLanguages">
								<li class="lpr-list__item"
									data-wp-class--is-selected="context.lang.selected"
									data-wp-on--click="actions.toggleLanguage">
									<span data-wp-text="context.lang.name"></span>
									<span class="lpr-list__check" data-wp-bind--hidden="!context.lang.selected">✓</span>
								</li>
							</template>
						</ul>
						<p class="lpr-list__empty" data-wp-bind--hidden="!state.languagesEmpty" data-wp-text="state.languagesEmptyText"></p>
					</div>

					</div><!-- /.lpr-grid-2 -->

					<!-- Timezone selector -->
					<div class="lpr-field">
						<span class="lpr-field__label"><?php esc_html_e( 'Timezone', 'laxmiloka-provider-registration' ); ?></span>

						<div class="lpr-search">
							<input type="search"
								placeholder="<?php esc_attr_e( 'Search timezones…', 'laxmiloka-provider-registration' ); ?>"
								data-wp-bind--value="state.timezoneSearch"
								data-wp-on--input="actions.updateTimezoneSearch">
							<button type="button" class="lpr-search__clear"
								data-wp-bind--hidden="!state.timezoneSearch"
								data-wp-on--click="actions.clearTimezoneSearch"
								aria-label="<?php esc_attr_e( 'Clear search', 'laxmiloka-provider-registration' ); ?>">&times;</button>
						</div>

						<ul class="lpr-list">
							<template data-wp-each--tz="state.filteredTimezones">
								<li class="lpr-list__item"
									data-wp-class--is-selected="context.tz.selected"
									data-wp-on--click="actions.selectTimezone">
									<span data-wp-text="context.tz.name"></span>
									<span class="lpr-list__check" data-wp-bind--hidden="!context.tz.selected">✓</span>
								</li>
							</template>
						</ul>
						<p class="lpr-list__empty" data-wp-bind--hidden="!state.timezonesEmpty" data-wp-text="state.timezonesEmptyText"></p>
					</div>

					<!-- About -->
					<label class="lpr-field">
						<span class="lpr-field__label"><?php esc_html_e( 'Tell about yourself', 'laxmiloka-provider-registration' ); ?></span>
						<textarea rows="5" data-field="about"
							data-wp-bind--value="state.form.about"
							data-wp-on--input="actions.updateField"></textarea>
					</label>
				</section>

				<!-- Footer nav -->
				<div class="lpr-nav">
					<button type="button" class="lpr-btn" data-wp-on--click="actions.prev" data-wp-bind--disabled="state.isPage1"><?php esc_html_e( 'Back', 'laxmiloka-provider-registration' ); ?></button>
					<button type="button" class="lpr-btn lpr-btn--primary" data-wp-on--click="actions.next" data-wp-bind--hidden="state.isPage2"><?php esc_html_e( 'Next', 'laxmiloka-provider-registration' ); ?></button>
					<button type="button" class="lpr-btn lpr-btn--primary" data-wp-on--click="actions.submit" data-wp-bind--hidden="!state.isPage2" data-wp-bind--disabled="state.loading">
						<span data-wp-bind--hidden="state.loading"><?php esc_html_e( 'Submit', 'laxmiloka-provider-registration' ); ?></span>
						<span data-wp-bind--hidden="!state.loading"><?php esc_html_e( 'Submitting…', 'laxmiloka-provider-registration' ); ?></span>
					</button>
				</div>
			</div>

			</div><!-- /.lpr-ui -->
		</div>
		<?php
	}

	/**
	 * Inline skeleton styles, printed once so the placeholder renders before
	 * frontend.css arrives.
	 */
	private function skeleton_css() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style id="lpr-skeleton-css">
			.lpr-form{width:100%;margin:0 auto}
			.lpr-form [hidden]{display:none!important}
			.lpr-ui{display:none}
			.lpr-skeleton{--jsc-sk:#e7eee7}
			.lpr-skeleton__steps{display:flex;gap:4px;margin-bottom:8px}
			.lpr-skeleton__steps span{flex:1;height:38px;border-radius:8px;background:var(--lpr-sk)}
			.lpr-skeleton__bar{height:4px;border-radius:2px;background:var(--lpr-sk);margin-bottom:24px}
			.lpr-skeleton__card{border:1px solid var(--lpr-sk);border-radius:8px;padding:24px}
			.lpr-skeleton__line{display:block;border-radius:6px;background:var(--lpr-sk)}
			.lpr-skeleton__line--title{height:22px;width:40%;margin-bottom:20px}
			.lpr-skeleton__line--label{height:12px;width:25%;margin-bottom:8px}
			.lpr-skeleton__line--input{height:38px;margin-bottom:18px}
			.lpr-skeleton__nav{display:flex;justify-content:space-between;margin-top:16px}
			.lpr-skeleton__nav span{width:96px;height:40px;border-radius:6px;background:var(--lpr-sk)}
			.lpr-skeleton__steps span,
			.lpr-skeleton__bar,
			.lpr-skeleton__line,
			.lpr-skeleton__nav span{
                            
                                
				background-image:linear-gradient(90deg,var(--lpr-sk) 0%,#f4f6f8 40%,var(--lpr-sk) 80%);
				background-size:200% 100%;
				animation:lpr-shimmer 3s ease-in-out infinite;
			}
			@keyframes lpr-shimmer{from{background-position:200% 0}to{background-position:-200% 0}}
			@media (prefers-reduced-motion:reduce){
				.lpr-skeleton__steps span,.lpr-skeleton__bar,.lpr-skeleton__line,.lpr-skeleton__nav span{animation:none}
			}
		</style>
		<?php
	}
}

register_activation_hook( __FILE__, array( 'LPR_Plugin', 'activate' ) );

new LPR_Plugin();
