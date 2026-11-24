# ChatBridge (DimdianChatbot)

ChatBridge is being developed as a platform-independent customer communication product: an embeddable website chat widget, a backend API, interchangeable storage and deployment implementations, and optional WordPress, WooCommerce, WhatsApp and AI integrations.

The current repository is the first Dimdian pilot. Its PHP + PDO backend now supports MySQL/MariaDB and PostgreSQL through dialect-aware repository adapters and separate schemas; its separate Supabase Edge Functions support the hosted demonstration and Meta diagnostics. These are current implementations, not mandatory infrastructure choices. Shared-database tenant isolation and export/restore remain future engineering milestones.

This README retains the operational handover for the current Dimdian PHP/MySQL pilot. For the product architecture and compatibility boundaries, read [Portable Architecture](docs/PORTABLE-ARCHITECTURE.md), [Product Scope](docs/PRODUCT-SCOPE.md), [Versioned API](docs/API-V1.md) and the [standalone embed guide](docs/EMBED-INTEGRATION.md).

> **Product architecture:** [Portable Architecture](docs/PORTABLE-ARCHITECTURE.md) · [Product Scope](docs/PRODUCT-SCOPE.md) · [Portability Acceptance Criteria](tests/PORTABILITY-ACCEPTANCE.md). **Current pilot operations:** start with the [Owner's Quick Fix Guide](docs/OWNER-GUIDE.md); see the [Production Hardening Guide](docs/PRODUCTION-HARDENING.md) and [WordPress Integration Notes](docs/WORDPRESS-INTEGRATION.md).

## WordPress plugin (early connector)

An installable plugin is available under [`wordpress-plugin/chatbridge-widget/`](wordpress-plugin/chatbridge-widget/). It adds a settings screen under **Settings → ChatBridge Widget**, a remote service health check, local WooCommerce compatibility diagnostics, optional read-only product search, and opt-in order lookup for signed-in customers. Product search returns published catalogue-visible products and supports SKU matching as well as partial product-name search; order lookup returns only a bounded summary of the current customer's own order. See [the plugin setup guide](docs/WORDPRESS-PLUGIN.md).

This is an early connector release, not yet a WordPress.org directory release. It requires the separately hosted ChatBridge PHP service; the PHP backend is not currently deployed to Hostinger. The plugin does not itself create a WhatsApp chatbot, connect Meta credentials, or provide AI-generated replies.

## 1. What this system does

- Adds a small floating **Message us** button to the Dimdian website.
- Opens a chat panel without sending the visitor to another page.
- Lets a visitor start chatting as a guest. No account, password, OTP or registration.
- Saves website messages and replies in a MySQL database.
- Shows replies in the same website chat.
- Can optionally connect an opted-in visitor's conversation to the WhatsApp Business app, using Meta's WhatsApp Cloud API and supported Business App Coexistence.
- Keeps Dimdian's support team on the existing WhatsApp Business app. Other businesses may enable the optional WordPress staff inbox; it is disabled by default.

### What it does not do

This is a focused enquiry channel, not a full customer-management system. It does not provide AI replies, guest-checkout order lookup, cart/checkout actions, inventory management, payments, CRM, attachments, voice notes or group chats. An opt-in WooCommerce order lookup is available only to signed-in customers and only for orders owned by their WordPress account. An optional staff inbox provides staff accounts through a trusted WordPress identity bridge, assignment, private notes, website-only replies, conversation search and owner-managed staff roles; it is not enabled in Dimdian's WhatsApp-first deployment. The WordPress plugin also has an optional read-only WooCommerce product catalogue adapter. The chat conversation itself currently synchronizes text messages only.

## 2. How a conversation works

### Website-only mode (the safe initial launch)

1. A visitor opens the widget and writes a message.
2. The browser asks the PHP API to create a guest session.
3. The API creates a guest, conversation and secure session record in MySQL.
4. The visitor's message is saved in MySQL.
5. The widget polls the API while open and displays new messages.
6. The widget polls for new messages and displays them.

**Operational limitation:** in Dimdian's default configuration, with WhatsApp enabled and the optional staff inbox disabled, staff reply through WhatsApp Business. If WhatsApp is disabled too, the widget is only a visitor message-capture channel unless the operator deliberately enables and provisions the optional staff inbox.

WhatsApp transport is disabled by default. With the staff inbox also disabled, the widget can collect and store enquiries but does not notify the team or provide a way for staff to answer them from this application. Do not launch it as the sole customer-support channel until the team has an agreed way to receive and answer those messages.

### Intended WhatsApp-connected mode

1. The visitor enters an international WhatsApp number and explicitly ticks the WhatsApp consent box. Both are optional for ordinary website chat.
2. The visitor sends a website message. The backend saves it and queues a WhatsApp template message addressed to that visitor.
3. A CLI worker sends the approved Meta template. This creates/continues the relevant business-to-customer WhatsApp thread, so the team can see it in the Business app when the number is correctly connected through supported Coexistence.
4. The team replies from the WhatsApp Business app.
5. Meta sends webhook events to this application. The application verifies the webhook signature, matches supported text echoes to the visitor's number, saves the reply and the website widget picks it up on its next poll.

**Important:** This flow depends on Meta supporting WhatsApp Business App Coexistence for the actual Dimdian number and delivering the `smb_message_echoes` webhook events. Do not migrate the live number or enable outbound sending until eligibility and a real app-originated reply have been tested. Country code +234 alone is not proof of eligibility. If Coexistence/echoes do not work, the current design cannot mirror replies from the Business app into the website chat.

**Fallback if Coexistence is unavailable:** enable the optional WordPress staff inbox instead of creating a separate password-only reply page. The existing inbox uses WordPress-authenticated staff identities, a server-side HMAC assertion bridge, locally provisioned roles and website-only replies; it works without WhatsApp. Import its opt-in schema, provision the initial owner and configure the WordPress connector as described in [Optional Staff Inbox Architecture](docs/STAFF-INBOX-ARCHITECTURE.md). This is not enabled in Dimdian's current deployment.

## 3. Live demo

The public demo is a mock Dimdian website with the actual widget code running in browser-only demo mode:

**https://michaelkage.github.io/DimdianChatbot/**

The demo does not connect to the PHP API, database or WhatsApp. It hides the visitor name/phone and WhatsApp consent fields, simulates a support reply, and does not persist messages after the page is reloaded. It is for trying the look and feel only; it is not the production service.

An owner-only diagnostic page is available at [the WhatsApp integration test console](https://michaelkage.github.io/DimdianChatbot/whatsapp-test.html). It will display verified Meta webhook events after the separate Supabase test project is configured. It is not a customer inbox and does not initiate WhatsApp onboarding or send messages.

## 4. Reference PHP/MySQL deployment

This section documents one self-hosted deployment shape for the current PHP reference backend. It is not a requirement for ChatBridge, and no Hostinger account access or production deployment is assumed or claimed.

- Example Chat/API host: `chat.dimdian.com` (not confirmed as provisioned)
- Main website: the Dimdian WordPress/WooCommerce website
- Runtime for this implementation: PHP 8.2+, PDO MySQL, MySQL/MariaDB, HTTPS and a scheduled CLI cron task

The chat application should be deployed separately from WordPress. It uses its own database and does not need access to the WooCommerce database. Other supported implementations may use different runtimes and storage once their adapters and compatibility tests exist.

### Recommended directory layout

Configure the `chat.dimdian.com` document root to point to the repository's `public/` directory. Keep application code, configuration, database scripts and tests outside the public document root.

For example:

```text
/home/ACCOUNT/dimdian-chat/
├── config.php                 # private; never inside public web root
├── app/
├── database/
├── docs/
├── tests/
└── public/                    # document root for chat.dimdian.com
    ├── api/
    ├── cron/
    ├── widget.js
    └── widget.css
```

The exact absolute home-directory path is assigned by the hosting provider and must be taken from its control panel or SSH; do not copy the example path literally. The PHP bootstrap currently looks for `config.php` in the repository root (one directory above `app/`). If the hosting layout is changed, update that path deliberately and keep the config outside the public directory.

### Self-hosted setup checklist

Do these steps in order. Do not change the main WordPress site or its database.

1. **Create the subdomain.** In your hosting control panel, create the desired chat subdomain, enable its SSL certificate, and set its document root to the repository's `public/` directory. Confirm that HTTPS works before embedding the widget.
2. **Check PHP.** Select PHP 8.2 or newer and confirm the PDO and PDO MySQL extensions are enabled. The application uses PHP streams and PDO; it does not require Composer, Node.js or a continuously running server process.
3. **Create a separate database.** Create a new MySQL database and database user for the chat application. Grant that user access only to this database. Do not reuse the WordPress/WooCommerce database credentials.
4. **Deploy the repository.** Use your hosting provider's supported Git deployment or upload the repository. Ensure the full project is present, while only `public/` is exposed as the web document root.
5. **Create the private configuration file.** Copy `config.example.php` to `config.php` in the repository root (not in `public/`). Fill in the database host/name/user/password, a strong random app key, timezone, public URL and exact website origins. Keep all WhatsApp fields empty/disabled for the first launch.
6. **Import the schema.** Using your hosting provider's database tool (for example phpMyAdmin), select the new empty chat database and import `database/schema.sql` once. This is the complete current schema. Do not run migrations 002, 003 or 004 on top of this fresh schema. For an existing database, apply migrations 002, 003 and 004 in order after taking a backup; see the [Production Hardening Guide](docs/PRODUCTION-HARDENING.md).
7. **Test the API before WordPress integration.** Open `https://chat.dimdian.com/api/session.php` in a browser. A method error is expected for a GET request; it confirms PHP is responding. A 503 `not_configured` response means the application cannot find its private `config.php`. A 500 response should be investigated in the hosting PHP error log. Do not expose database credentials in screenshots or support tickets.
8. **Add the widget to WordPress** using the snippet below, after the API host is working.
9. **Test from the real website** on desktop and mobile. Send a test message and verify the browser's network requests reach `chat.dimdian.com`. Website-only mode will save the message, but it will not notify the team through WhatsApp.
10. **Set up backups and monitoring.** Back up the chat database regularly and keep a copy of the private configuration file in a secure password manager or other restricted backup location. Never commit it to Git.

### Private configuration

Start from `config.example.php`. The important application values are:

| Setting | What to enter |
|---|---|
| `app.app_key` | A cryptographically random secret of at least 32 characters. Generate one on a trusted machine, for example with `openssl rand -hex 32`. |
| `app.timezone` | `Africa/Lagos` |
| `app.public_base_url` | `https://chat.dimdian.com` |
| `app.allowed_origins` | Exact origins of the live website, e.g. `https://dimdian.com` and `https://www.dimdian.com`. Add only hostnames that actually serve the site. No paths or trailing slash. |
| `database.dsn` | Hostinger database DSN in the form `mysql:host=DB_HOST;dbname=DB_NAME;charset=utf8mb4`. Use the exact database host/name shown in your hosting control panel. |
| `database.username` / `database.password` | Dedicated chat database credentials. |
| `whatsapp.outbound_enabled` | Keep `false` until the WhatsApp launch gate below is passed. |

The application stores timestamps in UTC. The configured timezone is used for PHP runtime behavior; it does not change the database's UTC timestamp convention.

### PostgreSQL 15+ alternative

The same PHP API and repository contracts also support PostgreSQL 15+.

- Enable PHP 8.2+ with `pdo_pgsql` (and OpenSSL).
- Create a dedicated PostgreSQL database and restricted application role.
- Import `database/postgresql/schema.sql` into an empty database.
- If the optional staff inbox is enabled, also import `database/postgresql/optional/staff-inbox.sql`.
- Set `database.dsn` to `pgsql:host=DB_HOST;port=5432;dbname=DB_NAME` and configure the database username/password.
- The application sets the PostgreSQL connection timezone to UTC. The PostgreSQL schema stores UTC timestamps without a timezone, matching the existing MySQL `DATETIME` convention.
- Do not run MySQL migrations 002–004 against PostgreSQL. The PostgreSQL schema is a clean-install schema; upgrades need dialect-specific migrations.

CI runs the same backend-neutral public and staff API acceptance suites against PostgreSQL 16, plus PostgreSQL-specific tests for webhook leases, encrypted payload/token storage, Meta token refresh, outbox lease completion, early delivery-status reconciliation and retention SQL.

## 5. Add the widget to the real Dimdian WordPress website

Once the backend is deployed and tested, add this script tag to the site's global footer or an approved site-wide custom HTML/code injection area:

```html
<script
  src="https://chat.dimdian.com/widget.js"
  data-api-base="https://chat.dimdian.com/api"
  data-title="Chat with Dimdian"
  data-subtitle="Send us a message. Our team will reply as soon as we can."
  defer
></script>
```

Use the WordPress theme's footer/custom-code facility or a trusted header/footer code plugin. Add it once only; do not paste it into every page if the chosen method already injects site-wide. Avoid editing WordPress core files or the theme's generated files directly because updates can overwrite changes.

The API's `app.allowed_origins` list must contain the exact origin(s) used by the public WordPress site. For example, `https://dimdian.com` and `https://www.dimdian.com` are different origins. If the site redirects one to the other, allow the final canonical origin and any actual alternate origin from which pages are served.

Widget options:

| Attribute | Purpose |
|---|---|
| `src` | Location of `widget.js`; the widget loads `widget.css` from the same directory. |
| `data-api-base` | PHP API base URL. |
| `data-title` | Chat panel heading. |
| `data-subtitle` | Supporting line below the heading. |
| `data-open="true"` | Optional: open the panel automatically on page load. Normally leave this unset. |

## 6. WhatsApp launch gate (do not skip)

WhatsApp integration is not ready for production merely because the code exists. Before enabling it, the owner and technical maintainer must complete all of the following:

- [ ] Confirm with Meta/WhatsApp that the actual Dimdian business number is eligible for Business App Coexistence.
- [ ] Confirm the intended onboarding does not require abandoning the existing WhatsApp Business app workflow or unexpectedly migrating the live number.
- [ ] Use a dedicated test number/account first where possible.
- [ ] Connect the test number through the supported Coexistence onboarding flow.
- [ ] Configure the Meta app webhook and verify that `smb_message_echoes` events arrive when a staff member sends a text reply from the Business app.
- [ ] Confirm a visitor's opted-in number receives the approved template and that the message/thread is visible to the support team in the Business app.
- [ ] Confirm the staff reply is saved and appears in the correct website conversation.
- [ ] Create and get approval for a WhatsApp message template with exactly one BODY text parameter (`{{1}}`). The template name and language must match the private config.
- [ ] Configure the Meta phone-number ID, access token, app secret, webhook verify token, Graph API version, approved template name and language in private `config.php`.
- [ ] Configure Meta to call `https://chat.dimdian.com/api/webhook.php` and subscribe to the required `messages` and `smb_message_echoes` fields.
- [ ] Test consent, opt-out/no-consent behavior, retries, delivery states, duplicate webhooks and failure handling.
- [ ] Only after the above tests pass, deliberately set `whatsapp.outbound_enabled` to `true`.

The visitor's phone number and WhatsApp consent are optional. A visitor who does not provide both a valid international number and explicit consent can still use website chat; the message remains website-only. No OTP is sent and the phone number is not identity verification.

### WhatsApp configuration keys

| Key | Purpose |
|---|---|
| `whatsapp.outbound_enabled` | Master switch. Keep false until all tests pass. |
| `whatsapp.graph_api_version` | Current supported Meta Graph API version used by the account. |
| `whatsapp.phone_number_id` | Meta phone-number ID for the connected business sender. |
| `whatsapp.access_token` | Server-side token with the required WhatsApp permissions. |
| `whatsapp.app_secret` | Meta App Secret used to verify webhook signatures. |
| `whatsapp.verify_token` | Private random string for the webhook GET verification handshake. |
| `whatsapp.template_name` | Exact approved template name. |
| `whatsapp.template_language` | Exact approved template language code. |

Never put any of these secrets in WordPress, the widget JavaScript, GitHub Pages or a public repository file.

## 7. Scheduled worker

The WhatsApp outbox worker, durable webhook inbox worker and retention job are command-line PHP scripts, not public URLs. It must be run by your hosting provider's cron scheduler using the actual absolute path to the project and the PHP CLI binary provided by the hosting account.

Script:

```text
/path/to/project/public/cron/process-outbox.php
```

Suggested schedule after WhatsApp has been enabled: once per minute. In your hosting provider's cron-job interface, use its documented PHP command format, with the absolute script path. The exact PHP executable path and project path must be copied from the hosting environment; do not guess them.

While WhatsApp is disabled, the outbox worker exits without processing sends. The webhook inbox worker should still run every minute so verified webhook deliveries are processed reliably; the retention job should run daily. Do not try to run this script by visiting it in a browser; it is intentionally CLI-only.

## 8. Privacy, security and day-to-day ownership

For the business owner:

- Tell visitors what the chat is used for and avoid asking them to send passwords, card details or sensitive personal information.
- Only use a visitor's phone number for the WhatsApp continuation they explicitly selected.
- Do not assume a visitor has consented to WhatsApp just because they started a website chat.
- Keep access to the hosting account, Meta Business Manager and database backups restricted to trusted people.
- If a visitor asks about a conversation, the current product has no staff dashboard or customer search screen; the technical maintainer may need to investigate the database securely.
- Agree an internal process for who monitors and replies to enquiries in the WhatsApp Business app and during what hours.
- Decide whether the default retention periods match the privacy notice and who handles individual privacy requests. A scheduled retention job now removes old closed conversations and operational records; it does not replace a documented privacy-request process.

Technical safeguards already in the code include hashed guest session tokens, prepared SQL statements, exact-origin CORS allowlisting, input limits, rate limiting, idempotent message submission, webhook HMAC signature verification, webhook deduplication and a CLI-only worker. These safeguards do not replace secure hosting, backups, access control or a clear privacy notice.

## 9. Troubleshooting

| Symptom | What to check |
|---|---|
| Widget does not appear | Confirm the script tag is present once, `widget.js` loads over HTTPS, and the browser console has no JavaScript errors. |
| Widget appears but cannot start a chat | Check that `chat.dimdian.com` is live, PHP 8.2+ and PDO MySQL are enabled, `config.php` is in the expected private location, and the database/schema are correct. |
| Browser reports a CORS error | Add the exact website origin to `app.allowed_origins`; scheme and hostname must match. |
| API responds with 503 `not_configured` | The bootstrap cannot find the private root `config.php`, or the file does not return a valid configuration array. |
| API responds with 500 | Check your hosting provider's PHP/server error logs and database credentials/schema. Do not display raw server errors to visitors. |
| Visitor message says “Website only” | This is expected while WhatsApp is disabled, or when the visitor did not provide a valid number and explicit opt-in. |
| WhatsApp template is not sent | Check the master switch, cron execution, approved template name/language, token permissions, phone-number ID and Meta error logs. |
| Staff reply does not appear on the website | Check Coexistence support, webhook subscription and delivery of `smb_message_echoes`; confirm the visitor number matches the website conversation. |
| Duplicate WhatsApp send is suspected | Check the outbox and provider message ID. Delivery is at-least-once, not mathematically exactly-once, if a worker dies after Meta accepts a send but before the response is saved. |

## 10. Repository and maintenance

```text
app/                       PHP bootstrap and WhatsApp services
config.example.php         Safe configuration template (no secrets)
database/schema.sql        Complete schema for a fresh install
database/migrations/       Incremental upgrades for older installations
public/api/                Public PHP API endpoints
public/cron/               CLI-only scheduled worker
public/widget.js           Embeddable browser widget
public/widget.css          Isolated widget styles
docs/index.html            Public mock website / demo
docs/API.md                HTTP API contract
docs/WIDGET.md             Widget integration notes
docs/WHATSAPP.md           Meta integration details
tests/                     PHP, Node and browser tests
archive/                   Retired marketplace-support implementation
```

### Fresh database vs existing database

- **Fresh installation:** import `database/schema.sql` once.
- **Older installation:** apply only the migrations that are missing, in order. Migration 002 adds the stable message sequence cursor; migration 003 adds the WhatsApp outbox/status-event tables.
- Do not import the fresh schema and then replay those migrations over it.

### Run checks locally

```sh
php tests/run.php
node --test tests/widget.test.mjs
php tests/integration.php
node --test tests/browser.mjs
find app public tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

The database-backed integration suite requires a configured MySQL/MariaDB test database. The browser suite requires its Playwright/Chromium test dependencies. GitHub Actions runs the automated checks and deploys the public demo from `docs/`; it does not deploy the PHP backend to a hosting provider.

## 11. Current deployment status

| Component | Status |
|---|---|
| Public mock website and demo widget | Deployed on GitHub Pages |
| PHP API and MySQL backend | Implemented in the repository; no production host deployment is confirmed |
| Production WordPress embed | Not yet configured |
| Production database and private config | Not yet created |
| WhatsApp credentials/template | Not configured |
| Supabase webhook event table | Deployed; RLS enabled and browser roles have no access |
| Supabase Edge Functions (`meta-webhook`, `meta-events`) | Deployed and active; required application secrets still need configuring |
| Coexistence for the actual +234 Dimdian number | Not verified |
| Live WhatsApp message/reply round trip | Not tested |

**Recommended rollout:** deploy the PHP backend and test the website chat first with WhatsApp disabled. Embed the widget on the real website only after the API is healthy. Treat WhatsApp as a separate second phase, and do not switch it on until the Coexistence test checklist has passed.

---

For the implementation details, see [docs/API.md](docs/API.md), [docs/WIDGET.md](docs/WIDGET.md), [docs/WHATSAPP.md](docs/WHATSAPP.md) and [docs/PRODUCT-SCOPE.md](docs/PRODUCT-SCOPE.md).


## Production hardening additions

The production hardening pass adds an auditable WhatsApp consent ledger and opt-out endpoint, deterministic phone-to-conversation links, an encrypted durable Meta webhook inbox, a recoverable webhook worker, server-side Embedded Signup code exchange with encrypted token storage, environment-variable configuration overrides, and a CLI retention job. The [file-change manifest](docs/FILE-CHANGE-MANIFEST.md), [manual setup checklist](docs/MANUAL-SETUP-CHECKLIST.md) and [16-milestone acceptance status](docs/MILESTONE-STATUS.md) list the repository changes, deployment steps and remaining production gates. The optional staff inbox is a separate opt-in WordPress module; it is not enabled in Dimdian's own WhatsApp-first deployment. Media synchronization remains out of scope. The WordPress plugin includes the administrator-only Embedded Signup v4 launcher; Meta app review, partner eligibility and live onboarding remain external gates.


## Optional staff inbox

Businesses that want website-based support can enable the optional staff inbox in the WordPress connector. It provides assignment, private internal notes, staff-authored website replies and audit history. It is a separate module with an opt-in schema; it does not alter Dimdian's own WhatsApp-first workflow. See [Optional Staff Inbox Architecture](docs/STAFF-INBOX-ARCHITECTURE.md) and [WordPress Plugin Setup](docs/WORDPRESS-PLUGIN.md).
