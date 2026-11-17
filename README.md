# DimdianChatbot

A lightweight, self-hosted website chat widget that connects customer enquiries to the business's WhatsApp support channel.

## Product direction

The current project is a focused website-to-WhatsApp support bridge. It is **not** the former auto-parts marketplace support platform.

- Visitors start conversations as guests; no account or OTP is required.
- The chat lives in an embeddable website widget, not a separate customer portal.
- Visitors can make quick enquiries without navigating away from the website.
- Conversations are stored by the backend so the widget can retrieve replies.
- Support staff should be able to use the WhatsApp Business app they already know.
- No separate staff inbox or support dashboard is part of the target product.
- No AI assistant, WooCommerce integration, product catalogue, fitment/VIN workflow, or inventory system is in scope.

A contact phone number may be collected in the widget to help support identify and continue an enquiry. It must not become an OTP or account-registration barrier.

## Target message flow

```text
Website visitor
    ↕
Embeddable chat widget
    ↕
DimdianChatbot API + MySQL
    ↕
WhatsApp Business Platform
    ↕
WhatsApp Business app used by support
```

The exact WhatsApp app/API coexistence and inbound-reply webhook route must be confirmed for the business's +234 number before production credentials or number migration are configured. Do not register or migrate the business number as a Cloud API-only number until coexistence eligibility and the app-reply echo/webhook behavior are verified.

## Technology and hosting

- PHP 8.2+ and PDO
- MySQL / MariaDB
- Vanilla JavaScript, HTML and CSS
- Stateless HTTP APIs and short polling; no WebSocket daemon
- Shared-hosting / Hostinger-friendly deployment
- Minimal runtime dependencies

## Repository layout

```text
app/                       Shared PHP bootstrap and application services
config.example.php         Safe configuration template (copy to config.php)
database/schema.sql        Initial MySQL schema
database/migrations/       Incremental database changes
docs/API.md                Public API contract
public/api/                Public HTTP endpoints; configure this as the web root
tests/                     API acceptance criteria
archive/                   Retired marketplace-support implementation
```

The previous marketplace-support implementation is preserved under `archive/legacy-marketplace-support/`. It is not loaded by the active application.

## Local / shared-hosting setup

1. Create an empty MySQL or MariaDB database and database user.
2. Import `database/schema.sql` into the empty database.
3. If you already imported the earlier schema from the previous commit, run `database/migrations/002_message_sequence.sql` instead of re-importing the schema.
4. Copy `config.example.php` to `config.php` in the repository root, outside the public web root.
5. Set the database DSN, username, password, a random `app.app_key` of at least 32 characters, and the exact website origins allowed to embed the widget.
6. Point the API subdomain's document root at `public/`. Do not expose the repository root or `archive/` over HTTP.
7. Test session creation, message submission and polling using [docs/API.md](docs/API.md).

The endpoint returns a one-time plaintext guest token. The database stores only its SHA-256 hash. Keep the token in the embedding browser's storage and send it as `X-DIMDIAN-GUEST-TOKEN` on subsequent API calls. Do not place it in a URL or log it.

## Development status

1. [x] Retire the previous marketplace-support direction from the active repository.
2. [x] Establish PHP bootstrap, database schema, and guest-session API.
3. [x] Implement message submission and polling APIs.
4. [ ] Build the embeddable website chat widget.
5. [ ] Verify WhatsApp Business App Coexistence for the actual business number.
6. [ ] Implement Cloud API outbound delivery and verified inbound app-reply webhooks.
7. [ ] Add automated runtime tests and complete Hostinger deployment verification.
8. [ ] Run an end-to-end test with the business's real WhatsApp Business account.

Visitor messages are persisted and can be polled by the originating widget session. Until the WhatsApp transport is implemented, newly submitted messages remain queued in the database; the API does not claim they have reached the business.
