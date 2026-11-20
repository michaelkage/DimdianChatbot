# ChatBridge database adapters

## Decision

ChatBridge has one conversation store per tenant deployment. It must not dual-write a conversation to two databases.

- **Mock/reference environment:** Supabase Postgres + private Supabase Storage.
- **Self-hosted deployments:** the existing PHP service with MySQL 8+ or compatible MariaDB through PDO.
- **Custom PHP backend:** supported through the documented server-side adapter contract; it must preserve the same API semantics and tenant/security guarantees. A customer does not give ChatBridge browser-side database credentials.

Supabase is a reference adapter for development and product testing, not a requirement for customer deployments.

## Supabase mock schema

The migration `supabase/migrations/20261120120000_chatbridge_mock_conversation_store.sql` creates:

- `chatbridge_tenants` and `chatbridge_site_origins`: server-owned tenant and verified-origin registry.
- `chatbridge_guests`, `chatbridge_conversations`, `chatbridge_guest_sessions`: visitor identity, conversation and hashed session-token state.
- `chatbridge_messages`: ordered, idempotent message history.
- `chatbridge_attachments`: attachment metadata, tenant/conversation linkage, staff-only visibility and retention fields.
- `chatbridge_rate_limits`: per-tenant abuse-control state.

All ChatBridge tables have RLS enabled and no anon/authenticated policies. Table privileges are revoked from `PUBLIC`, `anon` and `authenticated`; only the backend `service_role` is granted table access. The service-role key must stay in server-side PHP configuration and must never be sent to the widget, embedded in a page, committed to Git or stored in WordPress options.

The `chatbridge-private` Storage bucket is private, limited to 10 MiB per object, and allowlists JPEG, PNG, WebP, PDF, plain text and JSON. Upload/download operations must be mediated by the authenticated backend. Do not create public object URLs or browser-side Storage policies.

## Adapter contract

Any supported database/backend must implement these operations with tenant scope derived from a verified site origin or a server-to-server tenant credential:

1. Resolve an active tenant from the request origin.
2. Create a guest, conversation and hashed guest session atomically.
3. Authenticate a guest token against its tenant, conversation, expiry and revocation state.
4. Append messages with a per-conversation client idempotency key.
5. Read messages using a stable monotonic cursor.
6. Create attachment metadata only after a validated private upload; enforce `staff_only` visibility on every read.
7. Delete attachment objects and metadata according to the tenant retention policy.
8. Enforce tenant-scoped rate limits and conversation status transitions.

The adapter must never trust a tenant ID supplied by widget JSON. It must not expose database connection strings, SQL credentials, Supabase service-role keys or storage credentials to a browser.

## Current implementation status

The Supabase schema, server-only RPC functions and private bucket are provisioned in the connected Dimdian test project. The PHP API now has a selectable `chat_store.driver` and server-side Supabase adapter for verified-origin resolution, guest-session creation/authentication, rate limits, idempotent message writes and cursor-based message reads. Set the mock deployment to `chat_store.driver = supabase` and provide the project URL plus service-role key in private PHP configuration. The tenant and verified site-origin rows must be seeded before the widget can connect.

The attachment metadata and private bucket are provisioned, but the authenticated attachment upload/download HTTP endpoints and visitor screenshot/debug-consent UI are not implemented yet. Supabase mock mode is website-chat-only: the existing WhatsApp outbox and AI/handover pipeline remain part of the MySQL runtime and are not silently dual-written. Do not point the production widget at Supabase until attachment endpoints, tenant seeding and adapter integration tests are complete.

The MySQL/MariaDB implementation remains the existing working backend. It is not removed or migrated by the Supabase test schema.
