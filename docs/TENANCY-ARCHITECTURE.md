# Tenant-aware architecture: implementation boundary

> Status: design proposal only. The current PHP/MySQL reference deployment remains one business per database. Nothing in this document claims shared-database multi-tenancy is implemented.

## 1. Verified current state

The current source and DDL have these characteristics:

- The core schema defines guests, conversations, guest sessions, messages, webhook events, rate-limit buckets, WhatsApp outbox rows, message-status events, consent events, WhatsApp identity links, encrypted webhook inbox rows, Meta signup states and Meta connections.
- The optional staff schema defines staff identities, conversation assignments, internal notes, staff-message authors, audit events and staff-authentication nonces.
- Neither schema defines a business/tenant root table or a tenant identifier on its records. The core tables are related by guest, conversation and message foreign keys; staff records relate to the same installation-wide conversations and staff identities.
- The ChatRepository, MetaConnectionRepository and StaffInboxRepository contracts do not accept a tenant context. Their current PDO adapters therefore operate against the database selected by the installation configuration.
- The public widget derives its API base URL from its script data attribute or script URL. Session creation currently sends name, phone and WhatsApp consent; it does not send a tenant or public widget identifier.
- Staff HMAC assertions now bind a server-configured tenant UUID alongside identity, issue time, nonce, HTTP method, exact request target and body digest. The verified tenant is preserved in the application actor context. Staff repositories, assignments, audit reads and administrative operations are still installation-wide and do not yet enforce that tenant context.
- The optional staff module is installed separately and is disabled by default.

This is consistent with the current documented deployment boundary: one business per database. It is not a shared-database tenant model.

### Staff assertion implementation boundary

The HMAC assertion payload now includes `tenant_id`, covered by the same HMAC as the identity and request binding fields. The WordPress proxy reads this from the server-only `CHATBRIDGE_STAFF_TENANT_ID` constant, falling back to the explicit legacy single-tenant UUID when the constant is absent. The browser cannot choose or override it. A deployment that needs an explicit value can define it in `wp-config.php`, for example:

```php
define('CHATBRIDGE_STAFF_TENANT_ID', '00000000-0000-4000-8000-000000000001');
```

This constant is a routing/authentication claim only; it does not provision the tenant or make global staff persistence safe.

This is only an authentication-context prerequisite. The current staff identity, assignment, note, message-author, audit and nonce persistence is not yet tenant-scoped. A signed tenant claim does not make those global queries safe. Do not enable shared-database mode until tenant context is required by all staff repository methods and enforced in their SQL and relational constraints.

## 2. Non-negotiable isolation rule

A tenant identifier supplied by a browser is not authorization. The server must resolve a public integration identifier to a tenant, authenticate the caller, and carry the resolved tenant context through every application service, repository operation, background job and administrative action.

No repository method may infer tenant scope from the most recent conversation, a phone number alone, a URL parameter, an Origin header, or a caller-controlled tenant_id field.

## 3. Proposed domain and routing model

### Tenant root and public widget identity

Introduce a tenant root record with a stable UUID, lifecycle status and audit timestamps. A separate public widget registration maps an opaque public widget identifier to exactly one tenant and its allowed website origins.

The widget sends the public widget identifier during session creation. The API resolves it server-side, verifies the request origin against that widget's allowlist, and creates the guest/session/conversation under the resolved tenant. The identifier is a routing hint, not a secret and not a substitute for guest-session authentication.

The server returns no tenant-selection authority to the browser. Subsequent visitor requests derive tenant scope from the authenticated guest session record.

### Tenant context

Introduce an immutable request-scoped tenant context resolved by trusted adapters:

- Public widget: public widget identifier resolves to the tenant before session creation; authenticated guest session resolves it for later requests.
- Staff API: a tenant-scoped server integration credential or tenant-specific HMAC secret binds the signed staff identity to one tenant. A body-supplied tenant identifier is never trusted.
- Meta webhook: the platform verifies the raw-body signature first, then routes each provider event using the connected phone_number_id/WABA mapping. The webhook envelope may contain multiple entries, so routing must be event-aware rather than assuming one tenant per HTTP request.
- Meta Embedded Signup: the server-side administrator credential is scoped to one tenant before state creation, code exchange or connection mutation.
- CLI workers: the worker obtains tenant context from the persisted job/connection record or iterates an explicit tenant registry. It must not reuse one tenant's credentials for another tenant's job.

Tenant context is required input to tenant-owned application services and repository methods. Missing context is an error, not an implicit default, except in a deliberately configured single-tenant compatibility mode.

## 4. Tenant ownership and relational integrity

The following records are tenant-owned and need a tenant key:

| Domain | Existing records |
|---|---|
| Visitor identity | guests |
| Conversation | conversations |
| Authentication | guest_sessions |
| Messages | messages |
| Provider event ledger | webhook_events, message_status_events |
| Rate limiting | rate_limits |
| Outbound work | message_outbox |
| Consent and routing | guest_consent_events, whatsapp_identity_links |
| Durable webhook work | webhook_inbox |
| Meta connection lifecycle | meta_signup_states, meta_connections |
| Staff identity and access | staff_identities, staff_auth_nonces |
| Staff conversation data | staff_conversation_assignments, staff_internal_notes, staff_message_authors, staff_audit_events |

Foreign keys must enforce same-tenant relationships, not merely reference globally unique UUIDs. The migration should add tenant-scoped unique keys such as (tenant_id, id) on parent records and composite foreign keys such as (tenant_id, conversation_id) from child records.

Existing uniqueness rules must be reviewed individually:

- Active WhatsApp phone uniqueness becomes tenant-scoped. Two unrelated businesses must not collide merely because they use the same normalized visitor phone.
- Staff external identity uniqueness becomes tenant-scoped: (tenant_id, auth_provider, external_subject).
- Active conversation assignment uniqueness becomes tenant-scoped while still allowing only one active assignee per conversation.
- Client message idempotency remains scoped to a tenant and conversation.
- Provider WAMID/event uniqueness must be evaluated against Meta's identifier guarantees and then represented with the tenant key.
- Rate-limit bucket identity includes tenant scope, so one tenant cannot consume another tenant's allowance.
- Meta phone-number and WABA uniqueness is tenant-scoped in the application contract; any provider-global uniqueness can remain an additional constraint only if verified against the provider's guarantees.

### Webhook ingress exception

A signed webhook can contain multiple entries and the tenant is not known until the provider phone-number identity is resolved. Do not assign an arbitrary tenant before verifying the signature.

The implementation must choose and test one explicit ingestion model:
1. A platform-level encrypted ingress queue stores the verified raw payload without exposing it to tenant-facing queries; a router resolves each event to a tenant and writes tenant-scoped work; or
2. The endpoint safely splits a verified payload into tenant-specific encrypted inbox records after extracting only the routing identifiers needed for lookup.

The queue must preserve the exact signed raw payload for audit/replay, avoid cross-tenant fan-out, and handle unknown or disconnected phone-number IDs without guessing a tenant.

## 5. Repository and application changes required

Tenant isolation is incomplete until all of the following are true:

1. Every tenant-owned repository method requires a tenant context or tenant ID.
2. Every SELECT, UPDATE, DELETE, INSERT and uniqueness check is scoped to that tenant.
3. Parent-child writes validate that all referenced records belong to the same tenant in the same transaction.
4. Staff list/search/history/audit and role operations cannot cross tenant boundaries.
5. Meta token lookup, refresh, webhook routing and outbound transport configuration are tenant-scoped.
6. Outbox, webhook and retention workers process tenant-scoped jobs and cannot load another tenant's credentials.
7. Health and operations endpoints distinguish tenant health from platform-wide worker health without leaking another tenant's queue counts.
8. Export/import preserves tenant ownership and refuses to attach records to a different tenant implicitly.

The current repository contracts are a useful starting point, but changing only their signatures is not sufficient. The PDO adapters must enforce the scope in their SQL and transaction boundaries.

## 6. Safe migration sequence

Do not introduce a shared-database deployment in one large, unverified migration. Use an expand/backfill/constrain sequence:

1. Add the tenant root and public widget registry, but keep existing installations in single-tenant mode.
2. Create one tenant for each existing database installation using an explicit operator-supplied identity. Do not derive tenant identity from a visitor, phone number or domain guess.
3. Add nullable tenant keys to core and optional staff tables.
4. Backfill every existing row to the installation's one tenant. Verify counts, orphan checks and foreign-key relationships before continuing.
5. Add tenant-scoped unique indexes and composite foreign keys while retaining old constraints until the new constraints are proven.
6. Update repository contracts and PDO adapters to require tenant context; remove unscoped tenant-owned methods.
7. Update widget session creation, staff assertion verification, Meta connection resolution, cron workers, retention and operations endpoints.
8. Run cross-tenant negative tests against every API family, every repository write path and every background queue.
9. Make tenant keys NOT NULL and remove obsolete global constraints only after backfill and isolation tests pass.
10. Enable shared-database mode only behind an explicit deployment feature flag and operator migration approval.

Rollback must be planned before the NOT NULL/composite-constraint phase. A failed backfill must leave the existing one-business-per-database installation usable.

## 7. Required acceptance tests before claiming multi-tenancy

- A public widget identifier resolves only to its registered tenant; unknown and disabled identifiers are rejected.
- A guest token from tenant A cannot read, write, poll, opt out, or mutate any tenant B resource.
- Replacing conversation, guest, message, assignment, note or staff IDs in a request never crosses tenant scope.
- Duplicate phone numbers across different tenants do not collide or route to the wrong conversation.
- A tenant A staff assertion cannot authenticate against tenant B, even if the external subject is identical.
- Staff agents cannot enumerate, search, read, assign, note, reply to or audit tenant B conversations.
- Meta phone-number routing selects the owning tenant and never broadcasts a provider event to all tenants.
- Outbox and webhook workers cannot send using another tenant's Meta token or recipient identity.
- Retention, exports and health metrics are tenant-scoped.
- Concurrent assignment, idempotency and webhook retry tests remain correct under tenant scope.
- The backend-neutral public and staff API acceptance runners pass against each supported backend implementation.
- A tenant export can be restored into a separate installation without leaking or merging records.

## 8. Current release boundary

Until the migration and acceptance criteria above are implemented, keep the current deployment model: one business per database, separate configuration/secrets, and no shared-database multi-tenant claim. The presence of a tenant identifier in a request or a future schema column alone must never be treated as evidence of isolation.
