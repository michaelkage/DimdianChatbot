# Versioned HTTP API

## Status

The first versioned PHP entry points are available under /api/v1. They are compatibility entry points over the existing PHP implementation, not a second backend and not yet proof of database portability.

The legacy /api/*.php paths remain supported. Both route families currently execute the same handlers and therefore share their authentication, validation, CORS, persistence and error behaviour.

## Base URL

Configure the widget's API base URL as:

- Legacy: https://your-chat-host.example/api
- Versioned: https://your-chat-host.example/api/v1

The widget's current API client appends endpoint filenames to the configured base URL. Existing embeds can remain on /api; new integrations can use /api/v1.

## Endpoints

| Method | Versioned path | Legacy path | Purpose |
|---|---|---|---|
| POST | /api/v1/session.php | /api/session.php | Create a guest session |
| GET, POST | /api/v1/messages.php | /api/messages.php | Poll or submit conversation messages |
| POST | /api/v1/consent.php | /api/consent.php | Change WhatsApp consent |
| GET | /api/v1/health.php | /api/health.php | Read-only service health |
| GET, POST | /api/v1/webhook.php | /api/webhook.php | Meta verification and signed webhook delivery |
| POST | /api/v1/meta-embedded-signup.php | /api/meta-embedded-signup.php | Administrator-only Meta onboarding operations |
| GET | /api/v1/staff/inbox.php | — | Optional authenticated staff conversation list |
| GET | /api/v1/staff/me.php | — | Resolve the authenticated staff identity and local role |
| GET | /api/v1/staff/agents.php | — | Administrator-only staff directory, including disabled identities |
| GET | /api/v1/staff/messages.php | — | Read visitor-visible conversation messages |
| POST | /api/v1/staff/assignments.php | — | Assign or unassign a conversation |
| POST | /api/v1/staff/roles.php | — | Owner-only staff role and account-status management |
| GET, POST | /api/v1/staff/notes.php | — | Read or create private internal notes |
| POST | /api/v1/staff/replies.php | — | Create a website-visible staff reply |
| GET | /api/v1/staff/history.php | — | Read assignment history |
| GET | /api/v1/staff/audit.php | — | Read administrator-only audit events |

The staff inbox list accepts `status` (open, closed or all), `after`, `limit` and an optional `search` string (maximum 100 Unicode characters). Search performs a contains match against visitor display name, phone number and message body; it does not expand an agent's assignment visibility.

## Authentication and security

Conversation endpoints require the X-DIMDIAN-GUEST-TOKEN header. Session tokens are returned once at session creation and must never be placed in query strings.

The public widget key and browser Origin are not substitutes for guest-session authentication. Origin allowlists and CORS restrict browser origins; the backend must still authenticate and authorize every private operation.

Meta webhook POST requests require a valid X-Hub-Signature-256 signature over the exact raw request body. Meta Embedded Signup requires the server-side administrator key and must not be exposed in browser JavaScript.

### Optional staff API

The staff routes exist only when the operator sets staff_inbox.enabled=true and imports database/optional/staff-inbox.sql. They are server-to-server endpoints; the visitor widget and guest-session token cannot authenticate to them.

Each request requires X-ChatBridge-Staff-Assertion. The header contains a base64url JSON payload and HMAC-SHA256 signature. The payload binds the verified external identity, issue time, one-time nonce, HTTP method, exact request target including query string, and SHA-256 digest of the exact request body. Assertions expire after a short clock-skew window and replayed nonces are rejected. The HMAC secret must remain on trusted servers and must never be sent to browser JavaScript.

Local staff roles are authoritative; role claims from the external identity provider are ignored. Owners/admins can manage the installation's conversations; agents are scoped to their active assignments and can self-assign unassigned conversations. Conversation search matches visitor name, phone and message body without widening the actor's assignment visibility. Only owners can change local roles or disable/re-enable staff identities; the repository prevents demotion or disabling of the last active owner. Internal notes are never customer messages. Replies created through this first inbox implementation are website-only and do not enter the WhatsApp outbox.

This reference implementation assumes one business per database installation. It is not a shared-database multi-tenant staff API.

## Data and message semantics

- Timestamps are UTC ISO-8601 strings.
- Message submission accepts a client_message_id UUID for idempotency.
- Staff note creation accepts an optional `id` UUID; clients should retain and reuse it when retrying an uncertain request. Reusing the same note ID, author, conversation and body returns `created: false` without creating a duplicate; reusing it for different content returns HTTP 409. The WordPress inbox retains the pending note/reply ID after an uncertain request so retrying the same draft is safe.
- Repeating an identical message ID and body in the same conversation returns the original record.
- Reusing the same message ID with different content returns HTTP 409.
- Polling uses an ascending sequence_id cursor.
- A successful message response confirms persistence, not WhatsApp delivery.
- WhatsApp continuation requires explicit consent and server-side channel configuration.
- Consent withdrawal is recorded and deactivates the corresponding active WhatsApp route where applicable.

See API.md for the detailed request/response and error contract.

## Compatibility policy

- Legacy /api/*.php routes remain available during the migration.
- The v1 aliases must pass the same integration tests as the legacy routes.
- Behaviour changes that break existing clients require a new API version or an explicitly documented compatibility window.
- Additional backend implementations must implement this contract independently and pass the shared API acceptance suite.
- Session/message handlers, consent transitions, rate-limit buckets, and webhook-inbox enqueue/lease/completion/retry persistence now use `ChatRepository` backed by the MySQL/MariaDB `PdoChatRepository`. Meta payload interpretation and active connection lookup remain application-service concerns. Provider-event deduplication, inbound/echo message writes, status-event storage and delivery-state reconciliation now use the repository. Retention remains PDO-coupled. Optional staff inbox persistence has a separate MySQL/MariaDB repository, HMAC signed-assertion adapter with request-target/body binding and one-time nonce replay protection, role/assignment authorization service, conversation search, owner-only role management, versioned HTTP routes and a separate WordPress admin inbox UI; WhatsApp outbox enqueue, claim/reclaim, consent check, cancellation, completion, retry and terminal failure now use the repository. Provider HTTP calls remain outside transactions. This is not yet a complete storage adapter.
- Do not advertise PostgreSQL, SQLite, another runtime, or a provider as supported merely because these aliases or the repository interface exist. The current adapter still uses MySQL/MariaDB-oriented SQL.

## Next API milestone

Before a second backend is introduced, publish a complete OpenAPI 3.1 document and reusable contract fixtures covering successful operations, validation, authentication, tenant isolation, idempotency, pagination, consent, webhooks and failure responses. The versioned route shape is now established; the portable application and storage layers are not yet complete.
