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

## Intended implementation constraints

- PHP 8.2+ and PDO
- MySQL / MariaDB
- Vanilla JavaScript, HTML and CSS
- Stateless HTTP APIs and short polling; no WebSocket daemon
- Shared-hosting / Hostinger-friendly deployment
- Minimal runtime dependencies
- Rate limiting, secure guest-session tokens, webhook verification and message deduplication

## Repository layout

The previous marketplace-support implementation has been moved intact to `archive/legacy-marketplace-support/`. It is retained for reference and is not part of the active application.

The active application is being rebuilt around the scope above. Until the new widget, API, schema and WhatsApp webhook are implemented and configured, this repository should be treated as a development scaffold, not a deployable support service.

## Development status

1. [x] Retire the previous marketplace-support direction from the active repository.
2. [ ] Implement guest sessions and the embeddable chat widget.
3. [ ] Implement conversation persistence and message polling.
4. [ ] Verify WhatsApp Business App Coexistence for the actual business number.
5. [ ] Implement Cloud API outbound delivery and verified inbound app-reply webhooks.
6. [ ] Add automated tests and Hostinger deployment instructions.
7. [ ] Run an end-to-end test with the business's real WhatsApp Business account.

See [the product scope](docs/PRODUCT-SCOPE.md) for the active requirements.
