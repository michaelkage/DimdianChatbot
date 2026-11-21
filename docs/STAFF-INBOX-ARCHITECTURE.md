# Optional Staff Inbox Architecture

## Decision

The staff inbox is an optional ChatBridge capability for businesses that want to manage website conversations inside their own website or application.

It does not change the Dimdian deployment model:

- Dimdian continues to use WhatsApp Business as its staff-facing support channel.
- WhatsApp-only installations do not enable the inbox, provision staff identities, or import its optional schema.
- Other businesses may deliberately enable the inbox and use it alongside or instead of their existing WhatsApp workflow.

The inbox is a module, not a mandatory part of the widget or core chat API.

## Capability boundary

The planned module contains:

- Staff identities mapped from a trusted external authentication provider.
- Roles and authorization policy: owner, admin and agent.
- Conversation list and search.
- One active assignee per conversation, with full assignment history.
- Private, immutable internal notes.
- Staff replies that appear as ordinary business messages to the visitor.
- An append-only operational audit trail.

AI is not required. WhatsApp is not required. The widget does not authenticate staff.

## Authentication and authorization

The browser must never establish a staff identity by submitting an email, user ID, role or external subject.

A trusted server-side authentication adapter must validate the session/token and return a stable provider + subject pair. ChatBridge maps that verified pair to a local staff_identities record. If no trusted adapter is configured, inbox requests fail closed.

Authorization is checked on every staff operation. A public widget key, guest token, CORS allowlist or WordPress manage_options check is not a substitute for staff authentication and per-operation authorization.

The persistence contract accepts already-resolved staff identity IDs; it does not authenticate users or decide roles.

## Data isolation

The current PHP reference deployment is one business per application/database installation. Its optional inbox tables reference only that installation's conversations.

This is not a multi-tenant shared-database design. Do not place multiple businesses in one database and rely on the current schema for isolation. A managed multi-tenant deployment must add tenant ownership to every relevant resource and scope every repository query, foreign key, authorization check, background job and audit read by tenant before it is advertised.

## Privacy and message semantics

- Internal notes live in staff_internal_notes, never in messages.
- Visitor message polling must never return notes or audit metadata.
- Staff replies are persisted as business messages and linked to their author in staff_message_authors.
- The initial staff reply path is website-visible only. It does not enqueue WhatsApp messages.
- A later WhatsApp-from-inbox feature must use the channel provider, respect consent and Meta's messaging-window/template rules, and preserve delivery state. It must not bypass the existing outbox.
- Audit metadata must not contain message bodies, note bodies, access tokens, session tokens or other secrets.
- Notes and audit events are immutable through the application contract; retention/export policy must explicitly include them.

## Optional schema and activation

database/optional/staff-inbox.sql is deliberately outside the default fresh-install schema and normal migrations. Import it only when the operator chooses to enable the staff inbox.

The module remains unavailable until all of these exist:

1. An explicit staff_inbox.enabled setting.
2. A trusted authentication adapter.
3. Server-side authorization middleware and role checks.
4. The optional schema has been installed.
5. The staff inbox API routes and contract tests have been deployed.

Missing configuration, schema or identity validation must fail closed. Disabling the module must stop staff routes from operating; it must not silently delete staff history.

## Implementation sequence

1. Persistence contract and opt-in schema.
2. MySQL/MariaDB repository implementation with transaction and isolation tests.
3. Concrete authentication-provider integrations and fail-closed HTTP middleware using the provider-neutral assertion contract.
4. Staff API endpoints: implemented for inbox list, assignment, notes, website replies, assignment history and audit history.
5. Staff UI as a separate optional client, not code bundled into the visitor widget. (Implemented in the WordPress connector.)
6. Export, retention, backup/restore and operational documentation.
7. A separate PostgreSQL implementation only after the shared repository contract and acceptance tests are stable.

## Current implementation status

The optional schema, repository interface, MySQL/MariaDB PDO adapter, HMAC-signed server assertion adapter, local role/assignment authorization service, versioned staff API routes, WordPress server-side proxy, separate WordPress admin inbox UI and integration suites are implemented. Assertions bind the HTTP method, exact request target (including query string), SHA-256 request-body digest, short expiry and one-time nonce. The WordPress connector keeps the shared secret server-side and requires a logged-in user with the inbox capability. New external identities default to the local agent role; an operator must explicitly provision the first owner with php bin/set-staff-role.php wordpress user:<WORDPRESS_USER_ID> owner after that administrator signs in once. Local roles are not copied from browser claims or external profile fields.

The core workflow is implemented, but the module is not yet a complete support-desk product: conversation search, in-product staff role management, export/restore procedures and broader end-to-end security coverage remain future work. The optional tables are excluded from default installs, and the current PHP schema assumes one business per database.


## Assignment authorization and concurrency

Agent access is not granted by a one-time service-layer check. Conversation message, assignment-history and private-note reads are scoped to the current active assignment in the SQL query itself. Note creation and website-reply creation lock the parent conversation row and re-check the agent's active assignment inside the same transaction. Assignment changes use the same parent-row lock, so an assignment revoked while a request is in flight cannot authorize a later write.

Self-assignment is a dedicated atomic repository operation: it succeeds only when the conversation is still open and unassigned (or already assigned to that same agent). Agent self-unassignment updates only the row still assigned to that agent. An agent cannot overwrite another agent's assignment or release another agent's work, even if the assignment changes between the initial request check and the database write. Administrators retain explicit reassignment and unassignment authority.
