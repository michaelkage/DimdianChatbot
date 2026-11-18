# Product Scope

## Purpose

Provide a small website chat widget that lets prospective and existing customers send quick enquiries and receive replies from the business's support team.

## Customer experience

- Chat is embedded on the business website.
- A visitor can start as a guest without creating an account.
- No OTP, password, or registration step.
- A visitor can send a short enquiry immediately.
- A phone number may be captured as a contact detail; it is not used as an identity-verification gate.
- Conversation history remains available in the same browser session and can be restored when the widget is reopened or the same tab is reloaded.
- The widget shows customer and support messages in chronological order.
- No full-page customer portal.

## Support experience

- The intended support workflow uses the existing WhatsApp Business app.
- No separate staff dashboard or inbox.
- Website messages are stored by the backend. When WhatsApp continuation is enabled and the visitor explicitly opts in, an approved template is sent to that visitor's number to establish the WhatsApp thread.
- A support reply sent from the WhatsApp Business app is mirrored into the originating website conversation through supported `smb_message_echoes` webhook events.
- This depends on Meta Business App Coexistence being available for the actual business number and the required echo events being delivered.
- The current product supports text synchronization only.

## Backend responsibilities

- Create and maintain anonymous guest sessions.
- Store conversations and messages.
- Send opted-in website messages through the supported WhatsApp Business Platform API using an approved template.
- Receive and verify WhatsApp webhooks.
- Associate supported app-originated support replies with website conversations.
- Expose a small polling API for the widget.
- Apply request validation, rate limits, exact-origin CORS protections, and webhook idempotency.
- Keep credentials outside the public web root and never commit secrets.

## WhatsApp integration gate

The intended experience depends on the business continuing to use the WhatsApp Business app while the same number is connected to the Cloud API. Confirm that Meta's supported coexistence onboarding is available for the actual +234 business number and that app-originated messages are delivered through the required webhook event. Validate this with a test number/account before migrating or changing the live business number.

If coexistence is unavailable, stop and present the operational alternatives before changing the product architecture. Do not silently substitute a separate staff dashboard.

## Explicitly out of scope

- AI chat, LLM providers, prompts, AI triage or AI settings
- Staff/admin inbox, agent accounts, assignment or ticket dashboards
- WooCommerce or other catalogue integrations
- Product search, MPN lookup, fitment checks, VIN capture or vehicle records
- Inventory, order management, payments or ERP workflows
- Customer accounts, passwords or OTP verification
- Native mobile applications
- Persistent WebSocket servers or mandatory paid SaaS infrastructure
- Media downloads, attachments, voice notes, group chats, message edits or deletions

## Technical constraints

- PHP 8.2+ with PDO
- MySQL / compatible MariaDB
- Vanilla browser JavaScript, HTML and CSS
- Shared-hosting compatible HTTP endpoints and short polling
- Keep the active codebase small and dependency-light
- No Composer or frontend runtime dependencies

## Current status

- PHP guest-session and message APIs are implemented.
- The embeddable widget, database schema, outbox worker and Meta webhook processor are implemented.
- A public mock website with a browser-only widget demo is deployed on GitHub Pages.
- The PHP backend has not yet been installed on the production Hostinger account.
- The WordPress embed and production database/configuration have not yet been set up.
- WhatsApp Coexistence for the actual business number, template approval and live round-trip behavior remain unverified.

The old marketplace-support source is retained under `archive/legacy-marketplace-support/` for reference only.
