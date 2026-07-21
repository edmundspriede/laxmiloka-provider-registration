<?php
/**
 * Admin-ajax endpoints for Laxmiloka Provider Registration.
 *
 * Actions (all nonce-protected with "lpr_nonce"):
 *  - lpr_send_otp       : generate a code, store it, e-mail it to the address.
 *  - lpr_verify_otp     : check the entered code, mark the address verified.
 *  - lpr_get_countries  : terms of the Countries taxonomy (+ base64 flag).
 *  - lpr_get_languages  : terms of the Languages taxonomy (+ base64 flag).
 *  - lpr_register       : create the user + Astrologer CPT + relation + webhook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LPR_Ajax {

	/** Transient TTLs. */
	const OTP_TTL      = 600;   // 10 min for the code itself.
	const VERIFIED_TTL = 1800;  // 30 min for the "verified" flag.

	public function __construct() {
		add_action( 'wp_ajax_nopriv_lpr_send_otp', array( $this, 'send_otp' ) );
		add_action( 'wp_ajax_lpr_send_otp', array( $this, 'send_otp' ) );

		add_action( 'wp_ajax_nopriv_lpr_verify_otp', array( $this, 'verify_otp' ) );
		add_action( 'wp_ajax_lpr_verify_otp', array( $this, 'verify_otp' ) );

		add_action( 'wp_ajax_nopriv_lpr_get_countries', array( $this, 'get_countries' ) );
		add_action( 'wp_ajax_lpr_get_countries', array( $this, 'get_countries' ) );

		add_action( 'wp_ajax_nopriv_lpr_get_languages', array( $this, 'get_languages' ) );
		add_action( 'wp_ajax_lpr_get_languages', array( $this, 'get_languages' ) );

		add_action( 'wp_ajax_nopriv_lpr_get_timezones', array( $this, 'get_timezones' ) );
		add_action( 'wp_ajax_lpr_get_timezones', array( $this, 'get_timezones' ) );

		add_action( 'wp_ajax_nopriv_lpr_register', array( $this, 'register' ) );
		add_action( 'wp_ajax_lpr_register', array( $this, 'register' ) );
	}

	private function check_nonce() {
		check_ajax_referer( 'lpr_nonce', 'nonce' );
	}

	/** Storage key for an e-mail's OTP code. */
	private function otp_key( $email ) {
		return 'lpr_otp_' . md5( strtolower( $email ) );
	}

	/** Storage key for an e-mail's "verified" flag. */
	private function verified_key( $email ) {
		return 'lpr_otp_ok_' . md5( strtolower( $email ) );
	}

	/* ------------------------------------------------------------------ *
	 * OTP — send.
	 * ------------------------------------------------------------------ */

	public function send_otp() {
		$this->check_nonce();

		if ( ! LPR_Plugin::otp_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'E-mail verification is disabled.', 'laxmiloka-provider-registration' ) ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid e-mail address first.', 'laxmiloka-provider-registration' ) ) );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'An account with this e-mail already exists.', 'laxmiloka-provider-registration' ) ) );
		}

		$code = (string) wp_rand( 100000, 999999 );
		set_transient( $this->otp_key( $email ), $code, self::OTP_TTL );

		$subject = apply_filters(
			'lpr/otp_subject',
			sprintf( __( 'Your %s verification code', 'laxmiloka-provider-registration' ), get_bloginfo( 'name' ) ),
			$email
		);

		$message = apply_filters(
			'lpr/otp_message',
			sprintf(
				/* translators: 1: site name, 2: the 6-digit code */
				__( "Hello,\n\nYour verification code for %1\$s is: %2\$s\n\nThe code expires in 10 minutes.", 'laxmiloka-provider-registration' ),
				get_bloginfo( 'name' ),
				$code
			),
			$email,
			$code
		);

		$sent = wp_mail( $email, $subject, $message );

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'Could not send the verification e-mail. Please try again.', 'laxmiloka-provider-registration' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'A verification code has been sent to your e-mail.', 'laxmiloka-provider-registration' ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * OTP — verify.
	 * ------------------------------------------------------------------ */

	public function verify_otp() {
		$this->check_nonce();

		if ( ! LPR_Plugin::otp_enabled() ) {
			wp_send_json_success( array( 'verified' => true ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$code  = isset( $_POST['code'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['code'] ) ) : '';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid e-mail address.', 'laxmiloka-provider-registration' ) ) );
		}

		$stored = get_transient( $this->otp_key( $email ) );

		if ( ! $stored ) {
			wp_send_json_error( array( 'message' => __( 'The code has expired. Please request a new one.', 'laxmiloka-provider-registration' ) ) );
		}

		if ( ! hash_equals( (string) $stored, (string) $code ) ) {
			wp_send_json_error( array( 'message' => __( 'Incorrect code. Please try again.', 'laxmiloka-provider-registration' ) ) );
		}

		delete_transient( $this->otp_key( $email ) );
		set_transient( $this->verified_key( $email ), 1, self::VERIFIED_TTL );

		wp_send_json_success( array( 'verified' => true ) );
	}

	/* ------------------------------------------------------------------ *
	 * Countries taxonomy (with base64 flag).
	 * ------------------------------------------------------------------ */

	public function get_countries() {
		$this->check_nonce();

		$taxonomy = LPR_Plugin::country_taxonomy();

		if ( ! taxonomy_exists( $taxonomy ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s taxonomy slug */
						__( 'Taxonomy "%s" is not registered. Use the lpr/country_taxonomy filter to point to the correct one.', 'laxmiloka-provider-registration' ),
						$taxonomy
					),
				)
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( is_wp_error( $terms ) ) {
			wp_send_json_error( array( 'message' => $terms->get_error_message() ) );
		}

		$meta_key = LPR_Plugin::flag_meta_key();
		$items    = array();

		foreach ( $terms as $term ) {
			$items[] = array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
				'flag' => $this->flag_src( get_term_meta( $term->term_id, $meta_key, true ) ),
			);
		}

		wp_send_json_success( array( 'terms' => $items ) );
	}

	/**
	 * Normalise a stored flag value into something usable as an <img src>.
	 * Accepts a full data URI, a raw <svg> string, or a bare base64 blob.
	 */
	private function flag_src( $raw ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';

		if ( '' === $raw ) {
			$src = '';
		} elseif ( 0 === strpos( $raw, 'data:' ) ) {
			$src = $raw;
		} elseif ( 0 === strpos( $raw, '<svg' ) ) {
			$src = 'data:image/svg+xml;base64,' . base64_encode( $raw );
		} else {
			// Bare base64 — default to PNG (override via the filter if needed).
			$src = 'data:image/png;base64,' . $raw;
		}

		return apply_filters( 'lpr/flag_data_uri', $src, $raw );
	}

	/* ------------------------------------------------------------------ *
	 * Languages taxonomy (with base64 flag).
	 * ------------------------------------------------------------------ */

	public function get_languages() {
		$this->check_nonce();

		$taxonomy = LPR_Plugin::language_taxonomy();

		if ( ! taxonomy_exists( $taxonomy ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s taxonomy slug */
						__( 'Taxonomy "%s" is not registered. Use the lpr/language_taxonomy filter to point to the correct one.', 'laxmiloka-provider-registration' ),
						$taxonomy
					),
				)
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( is_wp_error( $terms ) ) {
			wp_send_json_error( array( 'message' => $terms->get_error_message() ) );
		}

		$meta_key = LPR_Plugin::language_flag_meta_key();
		$items    = array();

		foreach ( $terms as $term ) {
			$items[] = array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
				'flag' => $this->flag_src( get_term_meta( $term->term_id, $meta_key, true ) ),
			);
		}

		wp_send_json_success( array( 'terms' => $items ) );
	}

	/* ------------------------------------------------------------------ *
	 * Timezones taxonomy.
	 * ------------------------------------------------------------------ */

	public function get_timezones() {
		$this->check_nonce();

		$taxonomy = LPR_Plugin::timezone_taxonomy();

		if ( ! taxonomy_exists( $taxonomy ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s taxonomy slug */
						__( 'Taxonomy "%s" is not registered. Use the lpr/timezone_taxonomy filter to point to the correct one.', 'laxmiloka-provider-registration' ),
						$taxonomy
					),
				)
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( is_wp_error( $terms ) ) {
			wp_send_json_error( array( 'message' => $terms->get_error_message() ) );
		}

		$items = array();
		foreach ( $terms as $term ) {
			$items[] = array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
			);
		}

		wp_send_json_success( array( 'terms' => $items ) );
	}

	/* ------------------------------------------------------------------ *
	 * Registration.
	 * ------------------------------------------------------------------ */

	public function register() {
		$this->check_nonce();

		$payload = isset( $_POST['payload'] ) ? json_decode( wp_unslash( $_POST['payload'] ), true ) : null;

		if ( ! is_array( $payload ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payload.', 'laxmiloka-provider-registration' ) ) );
		}

		// ---- Sanitize -----------------------------------------------------.
		$first    = sanitize_text_field( $payload['firstName'] ?? '' );
		$last     = sanitize_text_field( $payload['lastName'] ?? '' );
		$email    = sanitize_email( $payload['email'] ?? '' );
		$phone    = sanitize_text_field( $payload['phone'] ?? '' );
		$password = (string) ( $payload['password'] ?? '' );
		$country  = absint( $payload['country'] ?? 0 );
		$langs    = array_map( 'absint', (array) ( $payload['languages'] ?? array() ) );
		$timezone = absint( $payload['timezone'] ?? 0 );
		$about    = sanitize_textarea_field( $payload['about'] ?? '' );

		// ---- Mandatory fields ---------------------------------------------.
		$required = array(
			'firstName' => array( $first, __( 'First Name', 'laxmiloka-provider-registration' ) ),
			'lastName'  => array( $last, __( 'Last Name', 'laxmiloka-provider-registration' ) ),
			'email'     => array( $email, __( 'Email', 'laxmiloka-provider-registration' ) ),
			'phone'     => array( $phone, __( 'Phone', 'laxmiloka-provider-registration' ) ),
			'password'  => array( $password, __( 'Password', 'laxmiloka-provider-registration' ) ),
		);

		foreach ( $required as $pair ) {
			if ( '' === trim( (string) $pair[0] ) ) {
				wp_send_json_error(
					array(
						/* translators: %s field label */
						'message' => sprintf( __( '%s is required.', 'laxmiloka-provider-registration' ), $pair[1] ),
					)
				);
			}
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid e-mail address.', 'laxmiloka-provider-registration' ) ) );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'An account with this e-mail already exists.', 'laxmiloka-provider-registration' ) ) );
		}

		if ( ! $this->is_strong_password( $password ) ) {
			wp_send_json_error( array( 'message' => __( 'Password must be at least 8 characters and contain a capital letter, a lowercase letter and a digit.', 'laxmiloka-provider-registration' ) ) );
		}

		if ( empty( $langs ) ) {
			wp_send_json_error( array( 'message' => __( 'Please select at least one language.', 'laxmiloka-provider-registration' ) ) );
		}

		if ( ! $timezone ) {
			wp_send_json_error( array( 'message' => __( 'Please select a timezone.', 'laxmiloka-provider-registration' ) ) );
		}

		if ( '' === trim( $about ) ) {
			wp_send_json_error( array( 'message' => __( 'Please tell us about yourself.', 'laxmiloka-provider-registration' ) ) );
		}

		// ---- E-mail OTP gate ----------------------------------------------.
		if ( LPR_Plugin::otp_enabled() && ! get_transient( $this->verified_key( $email ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Please verify your e-mail address before submitting.', 'laxmiloka-provider-registration' ) ) );
		}

		// ---- Create the user ----------------------------------------------.
		$username = $this->unique_username( $email, $first, $last );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $password,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ),
				'role'         => LPR_Plugin::ROLE,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
		}

		update_user_meta( $user_id, 'phone', $phone );

		// ---- Create the Astrologer CPT entry ------------------------------.
		$cpt = apply_filters( 'lpr/cpt_slug', LPR_Plugin::CPT );

		$post_id = wp_insert_post(
			array(
				'post_type'    => $cpt,
				'post_status'  => apply_filters( 'lpr/cpt_status', 'publish' ),
				'post_title'   => trim( $first . ' ' . $last ),
				'post_content' => $about, // long description.
				'post_author'  => $user_id,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			// Roll back the user so we don't leave an orphan account.
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Phone + about as post meta too, for convenience.
		update_post_meta( $post_id, 'phone', $phone );
		update_post_meta( $post_id, 'email', $email );

		// ---- Taxonomy terms (Countries / Languages) -----------------------.
		$country_tax = LPR_Plugin::country_taxonomy();
		if ( $country && taxonomy_exists( $country_tax ) ) {
			wp_set_object_terms( $post_id, array( $country ), $country_tax );
		}

		$lang_tax = LPR_Plugin::language_taxonomy();
		if ( $langs && taxonomy_exists( $lang_tax ) ) {
			wp_set_object_terms( $post_id, $langs, $lang_tax );
		}

		$tz_tax = LPR_Plugin::timezone_taxonomy();
		if ( $timezone && taxonomy_exists( $tz_tax ) ) {
			wp_set_object_terms( $post_id, array( $timezone ), $tz_tax );
		}

		// ---- JetEngine relation (parent: user, child: CPT) ----------------.
		$relation_ok = $this->link_relation( $user_id, $post_id );

		// ---- Clean up the OTP "verified" flag -----------------------------.
		if ( $email ) {
			delete_transient( $this->verified_key( $email ) );
		}

		// ---- Fire the configurable webhook --------------------------------.
		$this->fire_webhook( $user_id, $post_id, $email );

		do_action( 'lpr/registered', $user_id, $post_id, $payload );

		wp_send_json_success(
			array(
				'userId'      => $user_id,
				'postId'      => $post_id,
				'relationSet' => $relation_ok,
				'message'     => __( 'Your astrologer account has been created.', 'laxmiloka-provider-registration' ),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Helpers.
	 * ------------------------------------------------------------------ */

	/** Min 8 chars, one uppercase, one lowercase, one digit. */
	private function is_strong_password( $password ) {
		return (bool) preg_match( '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{8,}$/', (string) $password );
	}

	/** Build a unique login from the e-mail (falls back to first/last name). */
	private function unique_username( $email, $first, $last ) {
		$base = sanitize_user( current( explode( '@', $email ) ), true );

		if ( '' === $base ) {
			$base = sanitize_user( strtolower( $first . $last ), true );
		}
		if ( '' === $base ) {
			$base = 'astrologer';
		}

		$username = $base;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i;
			$i++;
		}

		return $username;
	}

	/**
	 * Link the user (parent) to the CPT entry (child) via the JetEngine
	 * relation. Returns true on success, false if JetEngine / the relation
	 * isn't available.
	 */
	private function link_relation( $user_id, $post_id ) {
		$relation_id = LPR_Plugin::relation_id();

		if ( ! function_exists( 'jet_engine' ) || ! jet_engine()->relations ) {
			return false;
		}

		$relation = jet_engine()->relations->get_active_relations( $relation_id );

		if ( ! $relation ) {
			return false;
		}

		// Parent: user id, Child: post id.
		$relation->update( $user_id, $post_id );

		return true;
	}

	/** POST the new user id to the configured webhook (non-blocking). */
	private function fire_webhook( $user_id, $post_id, $email ) {
		$url = LPR_Plugin::webhook_url();

		if ( empty( $url ) ) {
			return;
		}

		$body = apply_filters(
			'lpr/webhook_body',
			array(
				'user_id' => $user_id,
				'post_id' => $post_id,
				'email'   => $email,
			),
			$user_id,
			$post_id
		);

		wp_remote_post(
			$url,
			array(
				'timeout'  => 5,
				'blocking' => false,
				'body'     => $body,
			)
		);
	}
}
