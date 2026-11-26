# ADR-001: Shared-database logical tenancy

- **Status:** Accepted for the next release architecture; implementation is not yet enabled
- **Date:** 2026-11-26
- **Decision owners:** ChatBridge maintainers
- **Scope:** PHP reference backend, future backend adapters, WhatsApp, AI runtime and operational workers

## Context

ChatBridge is intended to support multiple businesses on shared infrastructure while allowing customers to choose their backend, database and hosting. WhatsApp, AI and commerce integrations are optional and business-owned. A self-hosted installation must remain usable without a central management service.

The current `main` branch is still operationally single-tenant:

- `config.example.php` defaults tenancy to `single_tenant`.
- `TenantResolver::resolve()` returns the configured default tenant in that mode and explicitly fails closed for shared mode.
- Core tables already carry `tenant_id` and some composite tenant-aware foreign keys.
- Several provider-facing persistence paths are still global rather than tenant-scoped. In particular, the current Meta connection repository selects the latest active connection without a tenant argument; the webhook inbox and provider-event repository also lack a complete tenant execution context.
- The webhook worker resolves one active phone-number configuration rather than resolving the connection belonging to the tenant represented by each verified Meta event.

Therefore, the presence of tenant columns and a tenant registry is not proof that shared tenancy is implemented safely.

## Decision

**ChatBridge will use shared-database logical tenancy as its default multi-business architecture.**

Every business is represented by a tenant. Tenant-owned data and operations are isolated by a server-resolved tenant context. The application must enforce that context at every persistence, authorization, integration and background-job boundary.

Physical database separation is still permitted as a deployment option. A customer may run a dedicated ChatBridge installation or dedicated database for operational, contractual or isolation reasons. That option does not change the logical tenant model or justify maintaining a separate code path.

This is a product architecture decision, not permission to enable shared mode immediately. Shared mode remains disabled until the implementation and isolation acceptance criteria below pass.

## Architectural rules

### 1. Tenant context and identity

1. Create an immutable request-scoped `TenantContext` only after server-side resolution.
2. Public widget requests resolve tenant ownership from an active public widget identifier and an exact, registered origin.
3. Staff, administrative, CLI and service-to-service operations resolve tenant ownership from authenticated server-side identity and explicit authorization.
4. Never trust a client-supplied tenant ID as proof of ownership.
5. Every tenant-aware repository operation must require a tenant context or explicit tenant ID. No implicit default-tenant fallback is allowed in shared mode.
6. A legacy single-tenant installation may retain a configured default tenant as a compatibility mode.

### 2. Database ownership and constraints

Every tenant-owned row must carry `tenant_id`, including (at minimum):

- widgets, origins and tenant settings;
- guests, sessions, conversations and messages;
- consent records and WhatsApp identity links;
- provider events, message status events and webhook inbox records;
- WhatsApp outbox jobs and delivery attempts;
- Meta signup state and channel connections;
- AI provider configuration, encrypted credentials, knowledge documents/chunks, retrieval metadata, jobs, usage records and handover records;
- staff membership, assignments, notes and audit records.

Use composite uniqueness and foreign keys such as `(tenant_id, id)` and `(tenant_id, parent_id)` wherever a child references a tenant-owned parent. Unique constraints for provider identifiers must be designed with the correct ownership scope; do not accidentally make a tenant's identifier globally unique unless the external provider guarantees global uniqueness and the ownership mapping is separately enforced.

Tenant filters must be part of the SQL operation itself, not merely checked in PHP after fetching a row. Repository methods must not offer unscoped lookups for tenant-owned records.

### 3. WhatsApp and Meta routing

Separate platform-level Meta application configuration from business-owned channel configuration.

- Platform-level configuration may include the Meta app ID, app secret and callback configuration used by this ChatBridge deployment.
- Each tenant owns its WhatsApp Business Account association, phone-number connection(s), encrypted access token(s), connection status, permissions and channel settings.
- A verified webhook is mapped to a tenant using trusted Meta identifiers, principally the receiving `phone_number_id` and, where relevant, the WABA/account identifier.
- The mapping must be unique, active and ownership-checked. Missing, revoked, expired, unknown or ambiguous mappings fail closed; they must never fall back to another tenant's connection or a global “latest active” connection.
- Verify the Meta signature before accepting or processing webhook content. Do not use message text, sender phone number or an arbitrary tenant field in the payload to decide tenant ownership.
- Store the resolved tenant and connection identity with the durable webhook job so later processing cannot silently resolve against a different connection.
- Enforce atomic ownership when connecting or reconnecting a phone number. One external phone-number identity cannot be claimed by two tenants in the same deployment.
- Outbound sends load the exact active connection belonging to the job's tenant and selected channel. No tenant may borrow another tenant's token, phone number or template configuration.

The shared callback may be platform-wide, but business credentials and message routing remain tenant-owned. Any per-tenant webhook verification mechanism must follow the actual Meta app/subscription model supported by the deployment; do not invent a per-tenant verification handshake if Meta does not provide one.

### 4. Operational workers and queues

Every durable job carries an immutable `tenant_id` and the resource identifiers needed to process it. Workers must:

1. claim a job with a fenced lease;
2. establish tenant context from the trusted job record;
3. load only that tenant's connection, settings and related records;
4. perform external I/O outside database transactions;
5. complete, retry or fail the job using both job ID and lease token;
6. record tenant-scoped diagnostics without logging secrets or raw personal message content.

Webhook processing, WhatsApp delivery, AI generation, knowledge ingestion, retention and scheduled maintenance must all follow this rule. A worker must not resolve a tenant from mutable global configuration after claiming a job.

### 5. AI runtime and knowledge

AI remains optional and disabled by default.

- Provider/model selection, encrypted provider credentials, system instructions, usage budgets and fallback policy belong to a tenant.
- Knowledge sources, chunks, retrieval indexes and citations are tenant-scoped. Retrieval must include the tenant filter before candidate selection, not after results are returned.
- AI jobs carry tenant and conversation ownership and re-check that ownership before reading context or writing a response.
- Enforce per-tenant request, token and cost budgets before provider calls; retries must not bypass budgets.
- If AI is disabled, credentials are missing, limits are reached, or safe grounding is unavailable, the system falls back to the configured human-support path. It must not silently use another tenant's provider key or knowledge.
- Provider adapters remain replaceable; the conversation system must not depend on one AI vendor.

### 6. Backend and database portability

The tenant model belongs in the backend service and repository contracts, not in the widget. MySQL/MariaDB and PostgreSQL adapters must implement the same tenant-aware semantics and pass the same isolation contract tests. Supabase-specific Row Level Security may provide defence in depth, but it is not the universal isolation mechanism and must not be required by the PHP backend.

A dedicated database deployment must pass the same contract tests, with its installation represented as a single tenant or a deliberately isolated tenant shard.

## Explicitly rejected alternatives

### One database per business as the only supported model

Rejected as the product default because it complicates managed multi-business operations, onboarding, shared workers, cross-business administration and support. It would also create parallel operational patterns for features that should have one tenant-aware implementation.

### Shared database with partial tenant columns

Rejected. Tenant columns without tenant-scoped repositories, provider mappings, worker context, authorization and isolation tests create a false sense of security.

### One global WhatsApp connection for all tenants

Rejected. Each tenant must connect and own its eligible WhatsApp Business infrastructure. A platform-level Meta app does not imply a shared business phone number or shared customer data.

## Migration and delivery plan

Implement this in small, reviewable increments on top of current `main`; do not merge PR #2 as-is.

1. **Inventory and contract:** enumerate every tenant-owned table, repository, endpoint, CLI command, staff permission and worker. Define mandatory tenant-aware repository interfaces.
2. **Schema:** add/backfill tenant ownership for provider events, webhook inbox, Meta signup state, Meta connections, status events and every remaining tenant-owned table. Add composite keys, indexes and foreign keys. Backfill legacy rows into the deterministic legacy tenant.
3. **Request resolution:** complete widget-key plus exact-origin resolution; add authenticated staff/admin/service resolution; reject missing or inactive tenants.
4. **Core persistence:** make guest/session/conversation/message/consent/identity/rate-limit/provider-event operations tenant-scoped. Remove unscoped repository methods.
5. **WhatsApp:** resolve verified inbound webhook identifiers to one tenant and connection; persist that mapping in the inbox; tenant-scope delivery, token lookup, status callbacks, signup state and reconnect lifecycle.
6. **Workers:** establish tenant context from each claimed job and test concurrent jobs for different tenants.
7. **AI:** implement tenant-owned provider settings, encrypted keys, knowledge retrieval, job queues, budgets and handover only after the tenant context contract is stable.
8. **Compatibility:** preserve single-tenant installations and provide additive migrations, preflight diagnostics, backup guidance and rollback instructions.
9. **Enablement:** keep shared mode unavailable until all acceptance criteria pass on both MySQL/MariaDB and PostgreSQL.

## Acceptance criteria before shared mode can be enabled

- Two or more tenants can operate concurrently in the same database.
- A tenant cannot read, mutate, enumerate, infer or delete another tenant's guests, sessions, conversations, messages, consent, staff records, integrations, knowledge, jobs or audit records.
- Changing or omitting a client-supplied tenant ID cannot change the resolved tenant.
- A public widget key paired with an unregistered origin is rejected.
- A webhook for tenant A's phone number can only create/update tenant A's records; unknown, revoked, expired and ambiguous phone IDs are rejected or quarantined.
- A webhook for tenant A cannot be replayed into tenant B by changing message contents or sender identity.
- Outbound workers always use the exact tenant-owned connection and cannot send if that connection is missing, inactive or expired.
- Concurrent workers cannot claim or complete each other's jobs; stale lease tokens cannot complete work.
- AI retrieval and generation for tenant A cannot access tenant B's documents, provider key, conversation or budget.
- Staff and CLI authorization are tenant-scoped; privileged cross-tenant operations require an explicit platform-level permission and are audited.
- Tests include adversarial cross-tenant IDs, duplicate external identifiers, race conditions, retries, revoked connections and fresh-install plus upgrade paths.
- The same shared isolation contract suite passes for MySQL/MariaDB and PostgreSQL.

## Consequences

**Positive:** one coherent multi-business product model; simpler shared hosting and onboarding; per-business WhatsApp and AI ownership; portable storage adapters; dedicated deployments remain possible without forking product logic.

**Costs:** tenant scope must be threaded through nearly every repository and worker; schema migrations are substantial; isolation testing becomes a release gate; mistakes in query scoping have high impact.

**Operational rule:** until the acceptance criteria pass, the supported production mode remains single-tenant. The existence of tenant tables or green general CI does not authorize enabling shared mode.
