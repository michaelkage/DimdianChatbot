# Dimdian Website Chat

A lightweight customer chat widget for the Dimdian website. Visitors can send an enquiry without creating an account. The intended support workflow lets the team continue the conversation from the existing WhatsApp Business app, subject to Meta's WhatsApp Business App Coexistence requirements being confirmed for the business number.

This README is also the handover guide for the Dimdian business owner and the person maintaining the website.

> **For the business owner:** start with the [Owner's Quick Fix Guide](docs/OWNER-GUIDE.md). For the existing WordPress store architecture and integration boundaries, see [WordPress Integration Notes](docs/WORDPRESS-INTEGRATION.md).

## WordPress plugin (early connector)

An installable plugin is available under [`wordpress-plugin/chatbridge-widget/`](wordpress-plugin/chatbridge-widget/). It adds a settings screen under **Settings → ChatBridge Widget**, a remote service health check, local WooCommerce compatibility diagnostics, and optional read-only product search. When WCFM Marketplace is active, product search and customer order summaries include vendor attribution using WCFM's in-process vendor lookup functions. This does not grant vendor users access to customer orders or expose WCFM credentials. See [the plugin setup guide](docs/WORDPRESS-PLUGIN.md).

This is an early connector release, not yet a WordPress.org directory release. It requires the separately hosted ChatBridge PHP service; the PHP backend is not currently deployed to Hostinger. The plugin includes a gated Meta Embedded Signup v5 launcher when the service has been configured with an eligible Meta app and tenant API key. It never exposes Meta app secrets or business access tokens to the browser. The 0.3.0 connector adds an ownership-checked WooCommerce order summary for signed-in customers; it does not support guest-checkout order verification. The tenant AI runtime is a separate backend feature and is disabled until configured.

## 1. What this system does

- Adds a small floating **Message us** button to the Dimdian website.
- Opens a chat panel without sending the visitor to another page.
- Lets a visitor start chatting as a guest. No account, password, OTP or registration.
- Saves website messages and replies in a MySQL database.
- Shows replies in the same website chat.
- Can optionally connect an opted-in visitor's conversation to the WhatsApp Business app, using Meta's WhatsApp Cloud API and supported Business App Coexistence.
- Keeps the support team on the existing WhatsApp Business app. There is no separate staff dashboard.

### What it does not do

This is a focused enquiry channel, not a full customer-management system. It does not provide a staffed shared inbox, staff accounts, agent assignment/resolution UI, cart/checkout actions, inventory management, payments, CRM, attachments, voice notes or group chats. An optional tenant-scoped AI queue and knowledge retrieval runtime exists on the feature branch; it is disabled by default and has no staff inbox UI. The WordPress plugin now has an optional read-only WooCommerce product catalogue adapter; this is separate from the current PHP chat backend. The chat conversation itself currently synchronizes text messages only.

## 2. How a conversation works

### Website-only mode (the safe initial launch)

1. A visitor opens the widget and writes a message.
2. The browser asks the PHP API to create a guest session.
3. The API creates a guest, conversation and secure session record in MySQL.
4. The visitor's message is saved in MySQL.
5. The widget polls the API while open and displays new messages.
6. The widget polls for new messages and displays them.

**Operational limitation:** with WhatsApp disabled, this is currently a visitor message-capture channel, not a complete two-way support inbox. There is no staff dashboard or manual reply screen, so the team cannot reply to website-only messages from inside this product yet. A support reply reaches the widget only through the WhatsApp-connected workflow described below (or through a future staff reply interface, which is not currently in scope).

WhatsApp transport is disabled by default. In this state, the widget can collect and store enquiries, but it does not notify the team or provide a way for staff to answer them from this application. Do not launch it as the sole customer-support channel until the team has an agreed way to receive and answer those messages.

### Intended WhatsApp-connected mode

1. The visitor enters an international WhatsApp number and explicitly ticks the WhatsApp consent box. Both are optional for ordinary website chat.
2. The visitor sends a website message. The backend saves it and queues a WhatsApp template message addressed to that visitor.
3. A CLI worker sends the approved Meta template. This creates/continues the relevant business-to-customer WhatsApp thread, so the team can see it in the Business app when the number is correctly connected through supported Coexistence.
4. The team replies from the WhatsApp Business app.
5. Meta sends webhook events to this application. The application verifies the webhook signature, matches supported text echoes to the visitor's number, saves the reply and the website widget picks it up on its next poll.

**Important:** This flow depends on Meta supporting WhatsApp Business App Coexistence for the actual Dimdian number and delivering the `smb_message_echoes` webhook events. Do not migrate the live number or enable outbound sending until eligibility and a real app-originated reply have been tested. Country code +234 alone is not proof of eligibility. If Coexistence/echoes do not work, the current design cannot mirror replies from the Business app into the website chat.

## 3. Live demo

The public demo is a mock Dimdian website with the actual widget code running in browser-only demo mode:

**https://michaelkage.github.io/DimdianChatbot/**

The demo does not connect to the PHP API, database or WhatsApp. It hides the visitor name/phone and WhatsApp consent fields, simulates a support reply, and does not persist messages after the page is reloaded. It is for trying the look and feel only; it is not the production service.

An owner-only diagnostic page is available at [the WhatsApp integration test console](https://michaelkage.github.io/DimdianChatbot/whatsapp-test.html). It will display verified Meta webhook events after the separate Supabase test project is configured. It is not a customer inbox and does not initiate WhatsApp onboarding or send messages.

## 4. Production hosting plan

The intended hosting is the existing **Hostinger Business Web Hosting** account, using the subdomain:

- Chat/API host: `chat.dimdian.com`
- Main website: the existing Dimdian WordPress/WooCommerce website
- Runtime: PHP 8.2+, PDO MySQL, MySQL/MariaDB, HTTPS and a scheduled CLI cron task

The chat application should be deployed separately from WordPress. It uses its own database and does not need access to the WooCommerce database.

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

The exact absolute home-directory path is assigned by Hostinger and must be taken from hPanel/SSH; do not copy the example path literally. The PHP bootstrap currently looks for `config.php` in the repository root (one directory above `app/`). If the hosting layout is changed, update that path deliberately and keep the config outside the public directory.

### Hostinger setup checklist

Do these steps in order. Do not change the main WordPress site or its database.

1. **Create the subdomain.** In Hostinger hPanel, create `chat.dimdian.com`, enable its SSL certificate, and set its document root to the repository's `public/` directory. Confirm that HTTPS works before embedding the widget.
2. **Check PHP.** Select PHP 8.2 or newer and confirm the PDO and PDO MySQL extensions are enabled. The application uses PHP streams and PDO; it does not require Composer, Node.js or a continuously running server process.
3. **Create a separate database.** Create a new MySQL database and database user for the chat application. Grant that user access only to this database. Do not reuse the WordPress/WooCommerce database credentials.
4. **Deploy the repository.** Use Hostinger's supported Git deployment or upload the repository. Ensure the full project is present, while only `public/` is exposed as the web document root.
5. **Create the private configuration file.** Copy `config.example.php` to `config.php` in the repository root (not in `public/`). Fill in the database host/name/user/password, a strong random app key, timezone, public URL and exact website origins. Keep all WhatsApp fields empty/disabled for the first launch.
6. **Import the schema.** Using Hostinger's database tool (for example phpMyAdmin), select the new empty chat database and import `database/schema.sql` once. Then apply `database/migrations/004_multi_tenant_foundation.sql` followed by `database/migrations/005_tenant_ai_knowledge_handover.sql` and `database/migrations/006_tenant_api_keys_and_channel_events.sql`. Do not run migrations 002 or 003 on top of the fresh schema; those are only for databases created from older schema versions. The new public API requires the tenant tables and verified origin mapping created by migration 004.
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
| `app.tenant_id` | Tenant UUID used by the single-tenant CLI workers. Public API requests resolve the tenant from the verified `tenant_sites` registry, not from a browser-supplied tenant ID. |
| `app.encryption_key` | Persistent 32-byte key (64 hex characters) used to encrypt tenant provider credentials. Back it up securely; losing it makes stored credentials unreadable. |
| `database.dsn` | Hostinger database DSN in the form `mysql:host=DB_HOST;dbname=DB_NAME;charset=utf8mb4`. Use the exact database host/name shown in hPanel. |
| `database.username` / `database.password` | Dedicated chat database credentials. |
| `whatsapp.outbound_enabled` | Keep `false` until the WhatsApp launch gate below is passed. |

The application stores timestamps in UTC. The configured timezone is used for PHP runtime behavior; it does not change the database's UTC timestamp convention. See [Tenant AI setup](docs/AI.md) for the optional provider, knowledge import and worker flow.

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

The public API resolves each request's Origin against a verified `tenant_sites` row. For an existing single-tenant deployment, register the exact canonical origin against the legacy tenant UUID `00000000-0000-4000-8000-000000000001`. For a new tenant, use `bin/provision-tenant.php`; it creates a pending mapping. Verify domain ownership before setting the mapping status to `verified`. Do not accept tenant IDs from browser requests.

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

The WhatsApp outbox worker is a command-line PHP script, not a public URL. It must be run by Hostinger's cron scheduler using the actual absolute path to the project and the PHP CLI binary provided by the hosting account.

Scripts:

```text
/path/to/project/public/cron/process-outbox.php
/path/to/project/public/cron/process-ai.php
```

The AI worker is optional and only needed after tenant AI has been configured and published knowledge has been imported. Each worker requires an explicit `app.tenant_id`; run one worker per tenant at a time.

Suggested schedule after WhatsApp has been enabled: once per minute. In hPanel's Cron Jobs section, use the PHP command format Hostinger provides, with the absolute script path. The exact PHP executable path and project path must be copied from the hosting environment; do not guess them.

While WhatsApp is disabled, the worker exits without processing jobs, so creating the cron job can wait until the WhatsApp integration test phase. Do not try to run this script by visiting it in a browser; it is intentionally CLI-only.

## 8. Privacy, security and day-to-day ownership

For the business owner:

- Tell visitors what the chat is used for and avoid asking them to send passwords, card details or sensitive personal information.
- Only use a visitor's phone number for the WhatsApp continuation they explicitly selected.
- Do not assume a visitor has consented to WhatsApp just because they started a website chat.
- Keep access to Hostinger, Meta Business Manager and database backups restricted to trusted people.
- If a visitor asks about a conversation, the current product has no staff dashboard or customer search screen; the technical maintainer may need to investigate the database securely.
- Agree an internal process for who monitors and replies to enquiries in the WhatsApp Business app and during what hours.
- Decide how long chat records should be retained and who is responsible for handling deletion/privacy requests. Automated retention/deletion tooling is not currently included.

Technical safeguards already in the code include hashed guest session tokens, prepared SQL statements, verified tenant-origin CORS allowlisting, input limits, rate limiting, idempotent message submission, webhook HMAC signature verification, webhook deduplication and a CLI-only worker. These safeguards do not replace secure hosting, backups, access control or a clear privacy notice.

## 9. Troubleshooting

| Symptom | What to check |
|---|---|
| Widget does not appear | Confirm the script tag is present once, `widget.js` loads over HTTPS, and the browser console has no JavaScript errors. |
| Widget appears but cannot start a chat | Check that `chat.dimdian.com` is live, PHP 8.2+ and PDO MySQL are enabled, `config.php` is in the expected private location, and the database/schema are correct. |
| Browser reports a CORS error | Verify the exact website origin has a `tenant_sites` row with status `verified`; scheme, hostname and port must match. |
| API responds with 503 `not_configured` | The bootstrap cannot find the private root `config.php`, or the file does not return a valid configuration array. |
| API responds with 500 | Check Hostinger PHP/server error logs and database credentials/schema. Do not display raw server errors to visitors. |
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

- **Fresh installation:** import `database/schema.sql`, then apply migrations 004 and 005 in order.
- **Older installation:** apply only missing migrations in order. Migrations 002 and 003 upgrade older message sequencing and WhatsApp outbox/status tables; migrations 004 and 005 add tenant isolation, AI knowledge and handover tables.
- Do not import the fresh schema and then replay those migrations over it.

### Run checks locally

```sh
php tests/run.php
node --test tests/widget.test.mjs
php tests/integration.php
node --test tests/browser.mjs
find app bin public tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

The database-backed integration suite requires a configured MySQL/MariaDB test database. The browser suite requires its Playwright/Chromium test dependencies. GitHub Actions runs the automated checks and deploys the public demo from `docs/`; it does not deploy the PHP backend to Hostinger.

## 11. Current deployment status

| Component | Status |
|---|---|
| Public mock website and demo widget | Deployed on GitHub Pages |
| PHP API and MySQL backend | Implemented in repository; not yet installed on Hostinger |
| Production WordPress embed | Not yet configured |
| Production database and private config | Not yet created |
| Multi-tenant runtime | Initial tenant-scoped API/webhook increment is in open PR #2; migration has not been applied to production |
| Meta Embedded Signup v5 onboarding | Gated WordPress admin launcher and backend exchange/provisioning flow implemented on open PR #2; not yet configured or tested with a real Meta app |
| Tenant AI provider/knowledge/handover | Provider abstraction, encrypted key storage, tenant-scoped retrieval, async queue and handover runtime in open PR #2; disabled by default; no staffed inbox UI |
| WCFM vendor-aware catalogue/order summaries | Read-only vendor attribution in open PR #2; no vendor-authorized order access |
| WordPress.org readiness | Not ready; plugin distribution/release gates remain open |
| WhatsApp credentials/template | Not configured |
| Supabase webhook event table | Deployed; RLS enabled and browser roles have no access |
| Supabase Edge Functions (`meta-webhook`, `meta-events`) | Deployed and active; required application secrets still need configuring |
| Coexistence for the actual +234 Dimdian number | Not verified |
| Live WhatsApp message/reply round trip | Not tested |

**Recommended rollout:** deploy the PHP backend and test the website chat first with WhatsApp disabled. Embed the widget on the real website only after the API is healthy. Treat WhatsApp as a separate second phase, and do not switch it on until the Coexistence test checklist has passed.

---

For the implementation details, see [docs/API.md](docs/API.md), [docs/WIDGET.md](docs/WIDGET.md), [docs/WHATSAPP.md](docs/WHATSAPP.md) and [docs/PRODUCT-SCOPE.md](docs/PRODUCT-SCOPE.md).
