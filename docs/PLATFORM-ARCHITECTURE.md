# ChatBridge platform architecture and implementation gates

This document records the implementation boundary for turning the current single-site widget into a multi-business WordPress support product. It deliberately distinguishes shipped code from planned platform capabilities.

## Existing system: source of truth

The current live-chat design is a dependency-light PHP 8.2+ service backed by MySQL. Its guests, conversations, guest_sessions, messages, webhook_events and outbox tables are the operational source of truth for the PHP service.

The Supabase project currently connected as **Dimdian** is a separate Meta webhook test/diagnostic project. It currently contains the private meta_webhook_events table and the meta-webhook and meta-events Edge Functions. It is not the production conversation database, does not provision tenants, and must not be treated as the source of truth for PHP chat data.

Do not split a conversation across Supabase and MySQL. Before a production multi-tenant launch, choose and document one authoritative transactional store and a migration plan. The safest incremental path is to add tenant ownership to the existing MySQL service first, then evaluate whether a hosted control plane is justified.

## Tenant model (target)

A tenant represents one business/customer of the ChatBridge platform. A WordPress installation is a site belonging to exactly one tenant in the first release.

Target relationships:

- tenants: immutable tenant ID, display name, slug, lifecycle state, plan/limits and timestamps.
- tenant_sites: tenant ID, canonical site URL, verified domains, WordPress site identifier and connection status.
- tenant_memberships: tenant ID, user identity, role and status. Roles are owner, admin, support agent and read-only operator.
- channel_connections: tenant ID, provider, channel/account IDs, connection state, scopes, token expiry and encrypted credential reference. Never store raw tokens in public WordPress options or Git.
- conversations: tenant ID is mandatory. Every conversation belongs to exactly one tenant.
- messages: tenant ownership is inherited through the parent conversation and must be enforced by every query.
- webhook_events: provider + account + event ID is unique; resolve the account to exactly one tenant before processing.
- audit_events: record privileged configuration changes, onboarding transitions, handovers and access to customer data.

Tenant isolation rules:

1. Resolve the tenant from a verified site/domain mapping or a server-side connection credential. Never trust a tenant ID supplied by browser JSON alone.
2. Derive tenant scope once per request and pass it explicitly to every repository/query.
3. Every conversation lookup, message insert, guest-session authentication and webhook match must include tenant scope.
4. A guest token is valid only for the conversation and tenant that issued it.
5. Unique webhook deduplication keys include provider account identity, not just a global event ID.
6. Cross-tenant access tests must prove tenant A cannot enumerate, read, update or infer tenant B's conversations, messages, customers or channel metadata.
7. Tenant deletion and export require an audited lifecycle; do not cascade-delete production data until retention/export behavior is defined.

No tenant migration has been applied to the Dimdian MySQL database. The current single-tenant production schema remains unchanged.

## WhatsApp connection lifecycle

The target onboarding state machine is:

not_connected → signup_started → authorization_received → account_discovered → credentials_exchanged → webhook_subscribed → number_verified → connection_tested → connected

Failure/exit states include cancelled, needs_action, disconnected and revoked. Store timestamps and a sanitized failure reason for each transition. Never log authorization codes, access tokens, app secrets, QR payloads or verification codes.

The Meta implementation guide supplied for this project describes Embedded Signup v4 and says the integrating business must already be a Meta Solution Partner or Tech Provider. The accompanying default-flow guide says v5 is now the latest version. Therefore the implementation must target the current supported version (v5, subject to a fresh check of Meta's version/migration documentation), not blindly copy the v4 sample. It also requires an HTTPS origin, a Facebook Login for Business configuration, the account_update webhook subscription, and a server-side exchange of the short-lived authorization code. The authorization code expires quickly; exchange it server-side immediately.

Coexistence-specific requirements:

- Use the Coexistence feature in the current supported Embedded Signup flow, not a generic Cloud API number-add flow. Do not freeze v4-specific launch parameters into production code while v5 is current.
- The business owner must authenticate and approve the exact business assets being connected.
- The owner must scan/approve the in-app QR step when Meta presents it.
- Preserve the existing WhatsApp Business app workflow; do not tell the owner that this is guaranteed until the actual number completes onboarding.
- Verify the returned WABA/phone-number IDs against the selected tenant before storing the connection.
- Subscribe to and test smb_message_echoes before declaring Business App replies synchronized.
- Test incoming messages, app-originated replies, delivery statuses, disconnect and token revocation.
- Do not enable outbound sends until the approved template, recipient opt-in, number identity and webhook round-trip are verified.

Meta's partner overview distinguishes Tech Providers, Tech Partners and Solution Partners and identifies Embedded Signup as a Tech Provider capability. See [Meta's partner overview](https://whatsappbusiness.com/partners/become-a-partner/). For a provider-specific example of the onboarding prerequisites, see [Twilio's Tech Provider integration guide](https://www.twilio.com/docs/whatsapp/isv/tech-provider-program/integration-guide). A Meta App ID/configuration ID/Partner Solution ID are configuration identifiers, not credentials. App Secret, access tokens and exchanged credentials are server-side secrets. The browser may launch signup and relay the short-lived code to a protected backend endpoint; it must never exchange or persist the long-lived token itself.

The mock-site Supabase webhook remains a diagnostic receiver only. It currently accepts Meta's webhook handshake and signed event POSTs and writes normalized diagnostic events. It does not yet implement Embedded Signup, authorization-code exchange, tenant provisioning or message routing into the PHP chat service.

## AI responses and knowledge

AI is optional and disabled by default. The platform must remain fully usable as a human-operated support channel if no AI provider is configured.

Target design:

- Provider adapter interface with explicit timeouts, bounded output tokens, retry policy and provider-specific error mapping.
- Per-tenant provider/model selection and server-side encrypted credentials; never put provider keys in WordPress settings or browser JavaScript.
- Tenant-scoped knowledge sources: approved FAQs, policy pages, product/catalogue data and uploaded documents.
- Retrieval must be tenant-filtered before any context is sent to a model. No cross-tenant vector or document retrieval.
- Responses must identify the business knowledge used where practical and avoid inventing stock, price, delivery or payment claims.
- Human handover is a first-class state. A visitor can ask for a person, AI can decline/hand over when confidence is low, and any safety/payment/account issue routes to a human.
- Persist model/provider, prompt version, retrieval references, token/cost metadata and handover reason, but avoid storing chain-of-thought.
- Add per-tenant rate limits, budget ceilings, retention controls and a kill switch.

Do not select or hard-code an AI vendor until provider pricing, data handling, regional availability and a free/low-cost development path have been compared. The legacy AI implementation under archive/legacy-marketplace-support/ is archived reference code, not an active supported runtime.

## WooCommerce order lookup: current first increment

The plugin now has an opt-in order lookup route separate from the public product catalogue route. It is designed to require a signed-in WordPress customer and a WordPress REST nonce, and it checks that the order's customer ID equals the current user ID.

This is deliberately stricter than matching an order number plus email address. It does not expose guest-checkout orders, billing addresses, email addresses, payment tokens, notes or full customer records. If the customer is not signed in, the chat falls back to asking them to sign in or contact support.

The endpoint returns only order number, status, created date, currency, formatted total and a bounded item-name/quantity summary. It does not read Paystack payment credentials or attempt refunds.

Known limitation: the current route accepts the WooCommerce order ID. Stores using sequential/custom order-number extensions may display a different public order number. A dedicated adapter is needed before claiming compatibility with custom numbering plugins.

## WCFM Marketplace integration plan

WCFM is not just another WooCommerce product query. A marketplace can have a store owner, multiple vendors and vendor-specific order permissions.

The first WCFM increment must be read-only:

- Detect whether WCFM Marketplace is active and show a diagnostic status.
- Identify vendor ownership using WCFM-supported APIs, not guessed post meta keys.
- For public product search, show only public catalogue products and optionally display the vendor/store name when the official API supports it.
- For order enquiries, verify the customer owns the parent WooCommerce order. Vendor-facing access must be separately permission-checked and limited to that vendor's own line items.
- Never expose another vendor's products, line-item data, buyer contact details, commissions, payout data or store settings.
- Do not modify vendor order status, fulfilment, commissions, Paystack payments or refunds.

WCFM's official documentation describes vendor-specific order capabilities and the separate WCFM Marketplace REST API. Its app documentation lists WCFM Marketplace REST API and JWT authentication as dependencies for that app workflow; this does not mean ChatBridge should copy its credentials or reuse its endpoints without review. We should prefer WCFM's supported in-process permission/vendor APIs where available and add a separate vendor-scoped REST adapter only if the installed WCFM release requires it. See [WCFM capability documentation](https://docs.wclovers.com/capability/), [WCFM app/API requirements](https://docs.wclovers.com/wcfm-app/) and [WCFM Marketplace documentation](https://docs.wclovers.com/). The current plugin does not yet call WCFM APIs.

## WordPress.org distribution gates

Before directory submission:

- Give the plugin a stable public name, author identity, support URL and privacy policy URL.
- Confirm GPL-compatible licensing for every bundled asset and dependency.
- Add complete internationalization/text-domain loading and readme metadata.
- Run Plugin Check, PHP lint, WordPress Coding Standards and security review.
- Test activation, update, deactivation, uninstall, multisite behavior and supported PHP/WordPress/WooCommerce combinations.
- Document outbound requests, data collected, retention, subprocessors and the remote service dependency.
- Remove any Dimdian-specific copy, hard-coded domain, tenant ID, branding or default endpoint.
- Provide a reliable free/local mode or clearly disclose hosted-service requirements and pricing.
- Publish a support and vulnerability-reporting process.

A plugin ZIP can be installable before it is directory-ready. Do not market the current internal connector as WordPress.org approved.

## Current status (2026-11-20)

| Capability | Status |
|---|---|
| Guest chat and WhatsApp transport | Existing PHP implementation; production deployment and real Coexistence remain unverified |
| Supabase Meta diagnostic receiver | Deployed to the separate Dimdian test project; not a multi-tenant platform |
| WordPress connector | Internal installable plugin |
| WooCommerce public product search | Implemented and tested with adapter tests |
| WooCommerce customer order lookup | First increment implemented on feature branch; logged-in owner check and tests added |
| Tenant provisioning and isolation | Architecture defined; implementation not started |
| Embedded Signup | Not implemented; partner status and Meta configuration still required |
| AI and tenant knowledge | Architecture defined; provider adapter not implemented |
| WCFM vendor adapter | Not implemented |
| WordPress.org submission | Not ready |

## Release discipline

All platform work should land through a feature branch and pull request. Do not apply schema changes to the Dimdian production database, connect the live WhatsApp number, enable AI billing, or publish the plugin without an explicit review and owner approval.


## Tenant isolation implementation increment (branch: feat/multi-tenant-isolation)

The first runtime increment is implemented on a feature branch and is not yet merged or deployed:

- Migrations 004–007 create tenants, verified site origins, Meta channel connections, tenant API keys, AI/knowledge/handover records and Coexistence sync/contact/history tables; backfill existing records to the legacy tenant; add tenant IDs to guests, conversations, guest sessions, messages, webhook events, outbox jobs and status events; and add composite foreign keys for tenant-consistent records.
- Public API tenant context is resolved from the verified `tenant_sites.origin` registry. The request body cannot select a tenant. Unknown origins fail closed.
- Guest tokens are accepted only when their session, guest and conversation all belong to the origin's tenant.
- Message reads/writes, idempotency lookup, rate-limit buckets, webhook deduplication, phone-number correlation, status callbacks and outbox worker claims/updates are tenant-scoped.
- Meta webhook changes are resolved by phone-number ID or Messaging Account/WAAC ID to a tenant-owned `channel_connections` row. Account updates handle offboarding/reconnection; Coexistence contact/history requests are queued and triggered within the 24-hour window, with contacts stored and history payloads archived tenant-locally.
- `bin/provision-tenant.php` creates a tenant and a pending site mapping. An operator must verify domain ownership before changing the mapping to `verified`.

### Remaining tenant-platform work

This is not yet a complete hosted multi-tenant service. The CLI outbox worker still uses a single configured transport; per-tenant credential decryption and outbound sending remain outstanding. Per-tenant AI credentials are encrypted, but tenant memberships, billing/limits, automated site verification challenges, data export/deletion, history normalization/retention and operational admin UI remain to be implemented. Do not onboard a second live business or run the migration against production until those pieces and the migration rehearsal are reviewed.

CI now builds the disposable test database from `database/schema.sql` plus migrations 004–007 and includes two-tenant guest-token isolation, AI knowledge/handover, Meta contract, Coexistence contact/history webhook, and WooCommerce/WCFM regression tests. The initial integration run exposed duplicate PDO named-parameter use; the affected queries have been corrected and must pass a fresh run before this increment is considered validated.
