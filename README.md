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
- Shared-hosting friendly deployment
- Minimal runtime dependencies

## Repository layout

```text
app/                       Shared PHP bootstrap and application services
config.example.php         Safe configuration template (copy to config.php)
database/schema.sql        Initial MySQL schema
database/migrations/       Incremental database changes
docs/API.md                Public API contract
docs/WIDGET.md             Widget integration and behavior
public/api/                Public HTTP API endpoints
public/widget.js           Embeddable browser widget
public/widget.css          Isolated widget styling
tests/                     API and widget acceptance criteria
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
8. See [docs/WIDGET.md](docs/WIDGET.md) for the browser widget embed contract. Do not embed it on the live website until the API is deployed and tested.

The endpoint returns a one-time plaintext guest token. The database stores only its SHA-256 hash. The widget keeps the token in tab-scoped `sessionStorage` and sends it as `X-DIMDIAN-GUEST-TOKEN` on subsequent API calls. It is never placed in a URL.

## Development status

1. [x] Retire the previous marketplace-support direction from the active repository.
2. [x] Establish PHP bootstrap, database schema, and guest-session API.
3. [x] Implement message submission and polling APIs.
4. [x] Build the first dependency-free embeddable guest chat widget.
5. [ ] Add automated browser and PHP/MySQL runtime tests.
6. [ ] Verify WhatsApp Business App Coexistence for the actual business number.
7. [ ] Implement Cloud API outbound delivery and verified inbound app-reply webhooks.
8. [ ] Complete deployment verification and end-to-end testing with the real WhatsApp Business account.

Visitor messages are persisted and can be polled by the originating widget session. Until the WhatsApp transport is implemented, newly submitted messages remain queued in the database; the API and widget do not claim they have reached the business.
