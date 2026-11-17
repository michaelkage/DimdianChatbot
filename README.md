# DimdianChatbot

A lightweight website chat widget ↔ WhatsApp Business app bridge.

## Product direction

- Website visitors start conversations as guests; no account or OTP.
- The chat lives in an embeddable website widget, not a customer portal.
- Phone is optional contact information. WhatsApp continuation requires an explicit opt-in and an international phone number.
- Support staff should continue using their existing WhatsApp Business app; no separate staff dashboard is in scope.
- No AI assistant, WooCommerce integration, product catalogue, fitment/VIN workflow or inventory system.

## Target flow

```text
Website visitor ↔ Widget ↔ PHP API + MySQL
                         ↕
                  WhatsApp Cloud API
                         ↕
               WhatsApp Business app
```

The intended bridge uses an approved WhatsApp template to start an opted-in visitor's WhatsApp thread, inbound message webhooks to mirror visitor replies, and the `smb_message_echoes` webhook to mirror supported Business-app replies back into the website. This depends on actual WhatsApp Business App Coexistence support for the business number. Do not enable production transport or migrate the live number until +234-number eligibility and app-originated echo delivery have been tested.

## Stack

- PHP 8.2+ / PDO
- MySQL or compatible MariaDB
- Vanilla JavaScript, HTML and CSS
- Stateless HTTP and short polling; no WebSocket daemon
- CLI cron worker for outbound queue
- No Composer or frontend runtime dependencies

## Repository layout

```text
app/                       PHP bootstrap and WhatsApp services
config.example.php         Private config template
database/schema.sql        Fresh-install schema
database/migrations/       Incremental upgrades
docs/API.md                HTTP API contract
docs/WIDGET.md             Widget integration
docs/WHATSAPP.md           Meta setup, outbox and webhook design
public/api/                Public API endpoints
public/cron/               CLI-only scheduled workers
public/widget.js           Embeddable widget
public/widget.css          Isolated widget styles
tests/                     PHP and Node tests
archive/                   Retired marketplace-support implementation
```

## Database setup

For a fresh database, import `database/schema.sql` once.

For an existing database created before the WhatsApp outbox work, apply migrations in order:
1. `database/migrations/002_message_sequence.sql` if the database predates the stable message cursor.
2. `database/migrations/003_whatsapp_outbox.sql`.

Do not run migrations on a fresh database after importing the current schema; the current schema already includes their changes.

## Configuration

Copy `config.example.php` to a private `config.php`. Configure database credentials, app key and exact allowed website origins. WhatsApp remains disabled by default. See [docs/WHATSAPP.md](docs/WHATSAPP.md) before adding Meta credentials or enabling outbound transport.

## Tests

Run locally:

```sh
php tests/run.php
node --test tests/widget.test.mjs
find app public tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

GitHub Actions runs these checks on pushes and pull requests.

## Development status

- [x] Retire old marketplace-support implementation from active paths.
- [x] PHP foundation, guest sessions, message submission and polling.
- [x] Embeddable guest widget with tab-scoped session restoration.
- [x] Durable outbound outbox, retry/backoff and Meta Cloud API template adapter.
- [x] Meta webhook GET verification, POST signature verification and event deduplication.
- [x] Inbound text and Business-app text echo correlation by visitor phone.
- [x] Automated PHP helper and widget contract tests.
- [ ] Execute database-backed integration tests against the target MySQL/MariaDB version.
- [ ] Verify Coexistence and `smb_message_echoes` for the actual +234 business number.
- [ ] Configure and approve the WhatsApp template; test with a dedicated number.
- [ ] Complete real-account end-to-end testing before production enablement.

WhatsApp outbound is intentionally disabled by default. Without visitor opt-in and a configured/approved template, messages remain website-only. Text is the only synchronized message type in this implementation.
