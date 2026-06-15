# Laxmiloka Provider Registration

A two-step frontend registration wizard built on the **WordPress Interactivity API**. It registers a new **Astrologer** user, creates a matching **Astrologer** CPT entry, links the two with a **JetEngine relation**, and fires a configurable **webhook**. Modeled on the structure and styling of *Jet Service Creator*; all data exchange goes through `admin-ajax.php`.

## Requirements

- WordPress 6.5+ (Interactivity API / script modules)
- JetEngine (for the relation and, typically, the `astrologer` CPT + `countries` / `languages` taxonomies)

## Usage

1. Activate the plugin (this registers the **Astrologer** user role).
2. Put `[astrologer_registration]` on any page.
3. Configure under **Settings → Astrologer Registration**.

## Pages

1. **Personal Details** (two columns) — First Name, Last Name, Email, Phone, Password (all required).
   - **Password generator** + show/hide; a live checklist enforces min 8 chars, one capital, one lowercase, one digit (also re-checked server-side).
   - **Email OTP** — a 6-digit code is e-mailed and must be verified before continuing. Toggle in PHP or on the settings page.
2. **Settings** — searchable **Country** selector (terms from the `countries` taxonomy, with the base64 `flag` term-meta shown as an icon), multi-select **Languages** (`languages` taxonomy), searchable **Timezone** selector (`ttimezones` taxonomy), a **Tell about yourself** text area, and **Submit**.

## On submit

- Creates the user with the **astrologer** role (phone stored as user meta).
- Creates an **Astrologer** CPT entry — title = *First + Last*, long description (post content) = *Tell about yourself*; country + languages assigned as taxonomy terms.
- Links **JetEngine relation 120** — parent: user, child: Astrologer CPT.
- Fires the configured **webhook** (POST) with the new `user_id`.

## Configuration

E-mail OTP and the webhook URL can be set on the settings page, or hard-overridden in `wp-config.php`:

```php
define( 'LPR_OTP_ENABLED', false );                 // turn OTP off
define( 'LPR_WEBHOOK_URL', 'https://…/webhook' );   // webhook endpoint
define( 'LPR_RELATION_ID', 120 );                   // JetEngine relation id
```

## Admin-ajax actions

| Action | Purpose |
|---|---|
| `lpr_send_otp` | Generate + e-mail a 6-digit code (stored in a transient) |
| `lpr_verify_otp` | Verify the code, mark the address verified |
| `lpr_get_countries` | `{ terms: [{id, name, flag}] }` from the Countries taxonomy |
| `lpr_get_languages` | `{ terms: [{id, name}] }` from the Languages taxonomy |
| `lpr_get_timezones` | `{ terms: [{id, name}] }` from the Timezones taxonomy |
| `lpr_register` | Create user + CPT + relation, fire webhook |

All actions are nonce-protected (`lpr_nonce`).

## Filters

| Filter | Default | Purpose |
|---|---|---|
| `lpr/otp_enabled` | option/`true` | Enable the e-mail OTP step |
| `lpr/webhook_url` | option/`''` | Webhook endpoint |
| `lpr/relation_id` | `120` | JetEngine relation id |
| `lpr/cpt_slug` | `astrologer` | CPT used for the entry |
| `lpr/country_taxonomy` | `countries` | Country taxonomy slug |
| `lpr/language_taxonomy` | `languages` | Language taxonomy slug |
| `lpr/timezone_taxonomy` | `ttimezones` | Timezone taxonomy slug |
| `lpr/flag_meta_key` | `flag` | Term-meta holding the base64 flag |
| `lpr/flag_data_uri` | — | Adjust how a stored flag becomes an `<img src>` |
| `lpr/otp_subject`, `lpr/otp_message` | — | OTP e-mail content |
| `lpr/webhook_body` | `{user_id, post_id, email}` | Webhook payload |
| `lpr/registered` (action) | — | Fires after a successful registration |

## Notes on flags

The `flag` term-meta may be a full `data:` URI, a raw `<svg>` string, or a bare base64 blob (assumed PNG). Use the `lpr/flag_data_uri` filter to change how a bare blob is interpreted.

## Files

```
laxmiloka-provider-registration/
├── laxmiloka-provider-registration.php   # bootstrap, CPT/role, shortcode, markup, state
├── includes/
│   ├── class-lpr-ajax.php                 # admin-ajax handlers
│   └── class-lpr-settings.php             # settings page
└── assets/
    ├── js/frontend.js                     # Interactivity API store (module)
    └── css/frontend.css                   # wizard styles
```
