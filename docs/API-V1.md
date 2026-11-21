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

## Authentication and security

Conversation endpoints require the X-DIMDIAN-GUEST-TOKEN header. Session tokens are returned once at session creation and must never be placed in query strings.

The public widget key and browser Origin are not substitutes for guest-session authentication. Origin allowlists and CORS restrict browser origins; the backend must still authenticate and authorize every private operation.

Meta webhook POST requests require a valid X-Hub-Signature-256 signature over the exact raw request body. Meta Embedded Signup requires the server-side administrator key and must not be exposed in browser JavaScript.

## Data and message semantics

- Timestamps are UTC ISO-8601 strings.
- Message submission accepts a client_message_id UUID for idempotency.
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
- Session/message handlers, consent transitions, rate-limit buckets, and webhook-inbox enqueue/lease/completion/retry persistence now use `ChatRepository` backed by the MySQL/MariaDB `PdoChatRepository`. Meta payload interpretation and active connection lookup remain application-service concerns. Provider-event deduplication, inbound/echo message writes, status-event storage and delivery-state reconciliation now use the repository. Retention remains PDO-coupled. Optional staff inbox persistence now has a separate MySQL/MariaDB repository contract and adapter, but authentication, authorization, routes and UI are not implemented; WhatsApp outbox enqueue, claim/reclaim, consent check, cancellation, completion, retry and terminal failure now use the repository. Provider HTTP calls remain outside transactions. This is not yet a complete storage adapter.
- Do not advertise PostgreSQL, SQLite, another runtime, or a provider as supported merely because these aliases or the repository interface exist. The current adapter still uses MySQL/MariaDB-oriented SQL.

## Next API milestone

Before a second backend is introduced, publish a complete OpenAPI 3.1 document and reusable contract fixtures covering successful operations, validation, authentication, tenant isolation, idempotency, pagination, consent, webhooks and failure responses. The versioned route shape is now established; the portable application and storage layers are not yet complete.
