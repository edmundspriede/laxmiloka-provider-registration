/**
 * Laxmiloka Provider Registration — Interactivity API store.
 * Initial state is provided server-side via wp_interactivity_state().
 */
import { store, getElement, getContext } from '@wordpress/interactivity';

// Shared popup/toast service (laxmiloka-popup-notifications). error() popups
// are non-destructable: they show a blocking close button and stay until clicked.
const popups = store( 'laxmiloka/popups' );

const PAGES = 2;

/** Password rules: min 8, one upper, one lower, one digit. */
const pwLen   = ( v ) => ( v || '' ).length >= 8;
const pwUpper = ( v ) => /[A-Z]/.test( v || '' );
const pwLower = ( v ) => /[a-z]/.test( v || '' );
const pwDigit = ( v ) => /\d/.test( v || '' );

/** Generate a random password that satisfies every rule. */
function makePassword() {
	const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
	const lower = 'abcdefghijkmnpqrstuvwxyz';
	const digit = '23456789';
	const all   = upper + lower + digit;

	const pick = ( set ) => set[ Math.floor( Math.random() * set.length ) ];

	let out = [ pick( upper ), pick( lower ), pick( digit ) ];
	for ( let i = 0; i < 9; i++ ) {
		out.push( pick( all ) );
	}
	// Fisher–Yates shuffle so the guaranteed chars aren't always first.
	for ( let i = out.length - 1; i > 0; i-- ) {
		const j = Math.floor( Math.random() * ( i + 1 ) );
		[ out[ i ], out[ j ] ] = [ out[ j ], out[ i ] ];
	}
	return out.join( '' );
}

/**
 * Validate one page using the native HTML constraints of its required fields,
 * plus the page-specific rules below. Returns '' when valid, else a message.
 */
function validatePage( page, root ) {
	const section = root && root.querySelector( `.lpr-page[data-page="${ page }"]` );
	if ( ! section ) {
		return '';
	}

	const missing = [];
	section.querySelectorAll( '[required]' ).forEach( ( el ) => {
		const emptyText = typeof el.value === 'string' && el.value.trim() === '';
		const invalid = ! el.checkValidity() || emptyText;
		el.classList.toggle( 'is-invalid', invalid );
		if ( invalid ) {
			const label = el.closest( '.lpr-field' )?.querySelector( '.lpr-field__label' );
			missing.push( label ? label.textContent.trim() : el.dataset.field || 'field' );
		}
	} );

	if ( missing.length ) {
		section.querySelector( '.is-invalid' )?.focus();
		return (
			'Please fill in the required field' +
			( missing.length > 1 ? 's' : '' ) +
			': ' +
			missing.join( ', ' ) +
			'.'
		);
	}

	if ( page === 1 ) {
		if ( ! state.passwordValid ) {
			return 'Password must be at least 8 characters with a capital letter, a lowercase letter and a digit.';
		}
		if ( state.otpEnabled && ! state.otpVerified ) {
			return 'Please verify your e-mail address before continuing.';
		}
	}

	if ( page === 2 ) {
		if ( ! state.form.country ) {
			return 'Please select a country.';
		}
		if ( ! state.form.languages.length ) {
			return 'Please select at least one language.';
		}
		if ( ! state.form.timezone ) {
			return 'Please select a timezone.';
		}
	}

	return '';
}

const { state, actions, callbacks } = store( 'laxmiloka-provider-registration', {
	state: {
		/* ---- page flags ---- */
		get isPage1() { return state.page === 1; },
		get isPage2() { return state.page === 2; },
		get isDone1() { return state.page > 1; },
		get isDone2() { return state.created; },

		/* ---- password ---- */
		get pwLenOk() { return pwLen( state.form.password ); },
		get pwUpperOk() { return pwUpper( state.form.password ); },
		get pwLowerOk() { return pwLower( state.form.password ); },
		get pwDigitOk() { return pwDigit( state.form.password ); },
		get passwordValid() {
			return state.pwLenOk && state.pwUpperOk && state.pwLowerOk && state.pwDigitOk;
		},
		get passwordInputType() { return state.showPassword ? 'text' : 'password'; },

		/* ---- OTP ---- */
		get sendOtpLabel() { return state.otpSent ? 'Resend code' : 'Send code'; },
		get otpShowEntry() { return state.otpEnabled && state.otpSent && ! state.otpVerified; },

		/* ---- countries ---- */
		get filteredCountries() {
			const q = state.countrySearch.trim().toLowerCase();
			const list = q
				? state.countries.filter( ( c ) => c.name.toLowerCase().includes( q ) )
				: state.countries;
			return list.map( ( c ) => ( {
				...c,
				selected: c.id === state.form.country,
			} ) );
		},
		get countriesEmpty() { return state.filteredCountries.length === 0; },
		get countriesEmptyText() {
			if ( ! state.countriesLoaded ) {
				return 'Loading…';
			}
			if ( state.countrySearch && state.countries.length ) {
				return 'No matches for "' + state.countrySearch + '".';
			}
			return 'No countries available.';
		},

		/* ---- languages ---- */
		get filteredLanguages() {
			const q = state.languageSearch.trim().toLowerCase();
			const list = q
				? state.languages.filter( ( l ) => l.name.toLowerCase().includes( q ) )
				: state.languages;
			return list.map( ( l ) => ( {
				...l,
				selected: state.form.languages.includes( l.id ),
			} ) );
		},
		get selectedLanguages() {
			return state.languages.filter( ( l ) => state.form.languages.includes( l.id ) );
		},
		get hasLanguages() { return state.form.languages.length > 0; },
		get languagesEmpty() { return state.filteredLanguages.length === 0; },
		get languagesEmptyText() {
			if ( ! state.languagesLoaded ) {
				return 'Loading…';
			}
			if ( state.languageSearch && state.languages.length ) {
				return 'No matches for "' + state.languageSearch + '".';
			}
			return 'No languages available.';
		},

		/* ---- timezones ---- */
		get filteredTimezones() {
			const q = state.timezoneSearch.trim().toLowerCase();
			const list = q
				? state.timezones.filter( ( t ) => t.name.toLowerCase().includes( q ) )
				: state.timezones;
			return list.map( ( t ) => ( {
				...t,
				selected: t.id === state.form.timezone,
			} ) );
		},
		get timezonesEmpty() { return state.filteredTimezones.length === 0; },
		get timezonesEmptyText() {
			if ( ! state.timezonesLoaded ) {
				return 'Loading…';
			}
			if ( state.timezoneSearch && state.timezones.length ) {
				return 'No matches for "' + state.timezoneSearch + '".';
			}
			return 'No timezones available.';
		},
	},

	actions: {
		/* ---- navigation ---- */
		next() {
			const { ref } = getElement();
			const err = validatePage( state.page, ref.closest( '.lpr-form' ) );
			if ( err ) {
				popups.actions.error( err );
				return;
			}
			state.page = Math.min( PAGES, state.page + 1 );
		},

		prev() {
			state.page = Math.max( 1, state.page - 1 );
		},

		reset() {
			state.created = false;
			state.createdMessage = '';
			state.page = 1;
			state.otpSent = false;
			state.otpVerified = false;
			state.otpError = '';
			state.otpNotice = '';
			state.otpCode = '';
			state.countrySearch = '';
			state.languageSearch = '';
			state.timezoneSearch = '';
			state.form.firstName = '';
			state.form.lastName = '';
			state.form.email = '';
			state.form.phone = '';
			state.form.password = '';
			state.form.country = 0;
			state.form.languages = [];
			state.form.timezone = 0;
			state.form.about = '';
		},

		/* ---- generic field handler ---- */
		updateField() {
			const { ref } = getElement();
			state.form[ ref.dataset.field ] = ref.value;
			ref.classList.remove( 'is-invalid' );
		},

		/* ---- email + OTP ---- */
		updateEmail() {
			const { ref } = getElement();
			state.form.email = ref.value;
			ref.classList.remove( 'is-invalid' );
			// Any change invalidates a previous verification.
			state.otpVerified = false;
			state.otpSent = false;
			state.otpError = '';
			state.otpNotice = '';
			state.otpCode = '';
		},

		updateOtpCode() {
			const { ref } = getElement();
			state.otpCode = ref.value.replace( /\D/g, '' );
		},

		*sendOtp() {
			state.otpError = '';
			state.otpNotice = '';

			const email = state.form.email.trim();
			if ( ! email || ! /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test( email ) ) {
				state.otpError = 'Please enter a valid e-mail address first.';
				return;
			}

			state.otpSending = true;
			try {
				const body = new FormData();
				body.append( 'action', 'lpr_send_otp' );
				body.append( 'nonce', state.nonce );
				body.append( 'email', email );

				const res = yield fetch( state.ajaxUrl, { method: 'POST', body } );
				const json = yield res.json();

				if ( json.success ) {
					state.otpSent = true;
					state.otpNotice = ( json.data && json.data.message ) || 'Code sent.';
				} else {
					state.otpError = ( json.data && json.data.message ) || 'Could not send the code.';
				}
			} catch ( e ) {
				state.otpError = 'Request failed. Please try again.';
			} finally {
				state.otpSending = false;
			}
		},

		*verifyOtp() {
			state.otpError = '';

			if ( ! state.otpCode || state.otpCode.length < 4 ) {
				state.otpError = 'Please enter the code from your e-mail.';
				return;
			}

			state.otpVerifying = true;
			try {
				const body = new FormData();
				body.append( 'action', 'lpr_verify_otp' );
				body.append( 'nonce', state.nonce );
				body.append( 'email', state.form.email.trim() );
				body.append( 'code', state.otpCode );

				const res = yield fetch( state.ajaxUrl, { method: 'POST', body } );
				const json = yield res.json();

				if ( json.success ) {
					state.otpVerified = true;
					state.otpNotice = '';
				} else {
					state.otpError = ( json.data && json.data.message ) || 'Verification failed.';
				}
			} catch ( e ) {
				state.otpError = 'Request failed. Please try again.';
			} finally {
				state.otpVerifying = false;
			}
		},

		/* ---- password ---- */
		togglePassword() {
			state.showPassword = ! state.showPassword;
		},

		generatePassword() {
			state.form.password = makePassword();
			state.showPassword = true;
		},

		/* ---- country ---- */
		updateCountrySearch() {
			const { ref } = getElement();
			state.countrySearch = ref.value;
		},
		clearCountrySearch() {
			state.countrySearch = '';
		},
		selectCountry() {
			const { country } = getContext();
			state.form.country = state.form.country === country.id ? 0 : country.id;
		},

		/* ---- languages ---- */
		updateLanguageSearch() {
			const { ref } = getElement();
			state.languageSearch = ref.value;
		},
		clearLanguageSearch() {
			state.languageSearch = '';
		},
		toggleLanguage() {
			const { lang } = getContext();
			if ( state.form.languages.includes( lang.id ) ) {
				state.form.languages = state.form.languages.filter( ( id ) => id !== lang.id );
			} else {
				state.form.languages = [ ...state.form.languages, lang.id ];
			}
		},

		/* ---- timezone ---- */
		updateTimezoneSearch() {
			const { ref } = getElement();
			state.timezoneSearch = ref.value;
		},
		clearTimezoneSearch() {
			state.timezoneSearch = '';
		},
		selectTimezone() {
			const { tz } = getContext();
			state.form.timezone = state.form.timezone === tz.id ? 0 : tz.id;
		},

		/* ---- submit ---- */
		*submit() {
			const root = getElement().ref.closest( '.lpr-form' );
			for ( let p = 1; p <= PAGES; p++ ) {
				const err = validatePage( p, root );
				if ( err ) {
					state.page = p;
					popups.actions.error( err );
					return;
				}
			}

			state.loading = true;

			const payload = {
				firstName: state.form.firstName,
				lastName: state.form.lastName,
				email: state.form.email,
				phone: state.form.phone,
				password: state.form.password,
				country: state.form.country,
				languages: state.form.languages,
				timezone: state.form.timezone,
				about: state.form.about,
			};

			const body = new FormData();
			body.append( 'action', 'lpr_register' );
			body.append( 'nonce', state.nonce );
			body.append( 'payload', JSON.stringify( payload ) );

			try {
				const res = yield fetch( state.ajaxUrl, { method: 'POST', body } );
				const json = yield res.json();

				if ( json.success ) {
					state.created = true;
					state.createdMessage = ( json.data && json.data.message ) || 'Registration complete.';
					popups.actions.add( {
						message: state.createdMessage,
						type: 'success',
						destructable: false,
						linkUrl: '/login',
						linkText: 'Please login here or check email for instructions. Thank you.',
					} );
				} else {
					popups.actions.error( ( json.data && json.data.message ) || 'Something went wrong.' );
				}
			} catch ( e ) {
				popups.actions.error( 'Request failed. Please try again.' );
			} finally {
				state.loading = false;
			}
		},
	},

	callbacks: {
		init() {
			const { ref } = getElement();
			state.ready = true;

			// Fallback reveal if frontend.css is blocked/404 after 3s.
			setTimeout( () => {
				const cssLoaded =
					getComputedStyle( ref ).getPropertyValue( '--lpr-accent' ).trim() !== '';
				if ( ! cssLoaded ) {
					const ui = ref.querySelector( '.lpr-ui' );
					const sk = ref.querySelector( '.lpr-skeleton' );
					if ( ui ) {
						ui.style.display = 'block';
					}
					if ( sk ) {
						sk.style.display = 'none';
					}
				}
			}, 3000 );

			callbacks.loadCountries();
			callbacks.loadLanguages();
			callbacks.loadTimezones();
		},

		*loadCountries() {
			const url = new URL( state.ajaxUrl );
			url.searchParams.set( 'action', 'lpr_get_countries' );
			url.searchParams.set( 'nonce', state.nonce );
			try {
				const res = yield fetch( url );
				const json = yield res.json();
				if ( json.success ) {
					state.countries = json.data.terms;
				} else if ( json.data && json.data.message ) {
					popups.actions.error( json.data.message );
				}
			} catch ( e ) {
				popups.actions.error( 'Could not load countries.' );
			} finally {
				state.countriesLoaded = true;
			}
		},

		*loadLanguages() {
			const url = new URL( state.ajaxUrl );
			url.searchParams.set( 'action', 'lpr_get_languages' );
			url.searchParams.set( 'nonce', state.nonce );
			try {
				const res = yield fetch( url );
				const json = yield res.json();
				if ( json.success ) {
					state.languages = json.data.terms;
				} else if ( json.data && json.data.message ) {
					popups.actions.error( json.data.message );
				}
			} catch ( e ) {
				popups.actions.error( 'Could not load languages.' );
			} finally {
				state.languagesLoaded = true;
			}
		},

		*loadTimezones() {
			const url = new URL( state.ajaxUrl );
			url.searchParams.set( 'action', 'lpr_get_timezones' );
			url.searchParams.set( 'nonce', state.nonce );
			try {
				const res = yield fetch( url );
				const json = yield res.json();
				if ( json.success ) {
					state.timezones = json.data.terms;
				} else if ( json.data && json.data.message ) {
					popups.actions.error( json.data.message );
				}
			} catch ( e ) {
				popups.actions.error( 'Could not load timezones.' );
			} finally {
				state.timezonesLoaded = true;
			}
		},
	},
} );
