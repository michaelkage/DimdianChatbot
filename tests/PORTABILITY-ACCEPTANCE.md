# Portability foundation acceptance criteria

Tenant isolation is not implemented. The proposed schema, context-resolution and migration boundary is documented in [TENANCY-ARCHITECTURE.md](../docs/TENANCY-ARCHITECTURE.md). Keep the shared-database acceptance items below unchecked until the full migration and negative isolation suite pass.

These checks govern the transition from the current PHP/MySQL implementation to a portable ChatBridge platform.

## Product boundaries

- [ ] Widget configuration accepts an explicit API base URL and public tenant/widget identifier.
- [ ] Widget source contains no database driver, SQL, Meta secret, AI secret, or admin credential.
- [ ] WordPress and WooCommerce remain optional integrations.
- [ ] Website-only chat remains functional with WhatsApp and AI disabled.
- [ ] Supabase is not required by the generic widget or PHP backend.

## Backend API

- [ ] A canonical versioned API specification exists before a second backend claims compatibility.
- [ ] Every operation defines method, path, authentication, request schema, response schema and error responses.
- [ ] Session tokens are never accepted in query strings.
- [ ] Tenant identity is resolved and authorized server-side.
- [ ] Message submission has documented idempotency semantics.
- [ ] Message retrieval has documented cursor and ordering semantics.
- [ ] Consent withdrawal is auditable and prevents future channel sends where possible.
- [ ] Webhook verification, idempotency, durable acknowledgement and retry semantics are specified.

## Storage

- [x] Application services depend on repository interfaces, not PDO or database-specific SQL.
- [x] Repository contracts define transaction boundaries and concurrency guarantees.
- [x] The MySQL/MariaDB implementation passes the existing integration suite.
- [x] Clean-install schemas are versioned per SQL dialect; existing-database PostgreSQL upgrades remain a separate migration task.
- [x] PostgreSQL 15+ is supported through the dialect-aware PDO repositories, separate clean-install schemas and PostgreSQL 16 CI acceptance/integration tests. Existing-database PostgreSQL upgrade migrations remain outstanding.
- [ ] Export/import preserves tenant, conversation, message, consent and provider relationships.

## Backend runtime portability

- [ ] A second runtime implements the same API contract and shared fixtures.
- [ ] Background jobs, webhook processing, scheduled retention and attachment storage are accounted for.
- [ ] Configuration and secrets are supplied through documented server-side mechanisms.
- [ ] Self-hosted core chat does not depend on the hosted management portal.

## Existing implementation regression

- [ ] Existing PHP/MySQL API tests continue to pass.
- [ ] Existing widget tests continue to pass.
- [ ] WordPress connector tests continue to pass.
- [ ] Existing WhatsApp consent, webhook inbox and outbox safety tests continue to pass.
- [ ] The GitHub Pages demonstration remains clearly labelled as simulated until it uses a persistent backend.
