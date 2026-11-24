# Milestone acceptance status

This checklist distinguishes shipped repository changes from operational work and deliberately retained product boundaries. A checked item means the corresponding code path or safeguard is present; it does not mean production credentials, Meta eligibility or production infrastructure have been verified.

| # | Milestone | Status | Evidence / boundary |
|---:|---|---|---|
| 1 | Application service architecture refactoring | Partial — transport, signature verifier, webhook processor, inbox worker and payload cipher extracted | `MetaCloudApiTransport` defines the text transport boundary and Meta adapter. `MetaWebhookSignatureVerifier` verifies the exact raw body against Meta's HMAC signature. `MetaWebhookProcessor` maps inbound text, Business App echoes and delivery statuses through `ProviderEventRepository`. `WebhookPayloadCipher` owns AES-256-GCM payload sealing with the existing key derivation/AAD. `WebhookInboxWorker` owns lease, decrypt, validate, replay-safe event processing, completion and retry orchestration through `WebhookInboxRepository`; active Meta phone-number lookup is exposed by `WhatsAppIdentityRepository`. `MetaTokenCipher` owns AES-256-GCM Meta token encryption with the existing key/AAD, and `MetaGraphApiClient` pins HTTPS requests to `graph.facebook.com` with redirects disabled. `MetaConnectionRepository` / `PdoMetaConnectionRepository` own one-time signup-state consumption, active connection persistence/lookup, token refresh writes and expiry transitions. `MetaEmbeddedSignupService` coordinates state creation/consumption, Graph token exchange, WABA subscription, encrypted persistence and refresh without PDO or request globals. `RetentionService` applies bounded privacy windows and optional-module policy; `PdoRetentionRepository` executes dependency-ordered cleanup atomically. `HealthService` composes readiness and queue diagnostics through `HealthRepository` / `PdoHealthRepository`, leaving the HTTP route as a response adapter. `GuestSessionService` owns session ID/token generation, rate-limit consumption and guest/session creation through `GuestSessionOperationsRepository`; `ChatMessageService` owns visitor-message send/poll orchestration through `VisitorMessagingRepository`; `WhatsAppConsentService` applies consent policy through `ConsentRepository`. The routes retain HTTP validation and response mapping. `WhatsAppOutboxWorker` owns outbound job orchestration through `WhatsAppOutboxRepository`. Legacy functions remain compatibility facades. Raw PHP request/response I/O remains in route adapters.
| 2 | Centralized configuration and environment management | Implemented | app/bootstrap.php overlays documented DIMDIAN_* environment variables on private config.php. |
| 3 | Database integrity and migration management | Implemented | Migration 004 and fresh-install schema add consent ledger, active identity links, encrypted webhook inbox and Meta connection/state tables. Migration backfills legacy consent timestamps and deterministic active phone links. |
| 4 | Guest identity and session lifecycle management | Implemented | Existing random guest tokens remain hash-only, expiring and revocable; `GuestSessionService` now owns session creation and rate-limit signaling while the route retains input validation and HTTP mapping. |
| 5 | Customer consent and privacy lifecycle | Implemented | Append-only consent events, explicit post-session opt-out, route deactivation, queued-send cancellation and an immediate pre-send consent recheck. `WhatsAppConsentService` now owns the visitor consent use case and the route maps its missing-phone domain signal to HTTP 422. An already in-flight provider request cannot be recalled. |
| 6 | Conversation identity and message correlation | Implemented | Webhook routing now uses one active consent-backed phone-to-conversation link instead of choosing the latest open conversation. |
| 7 | Durable webhook ingestion pipeline | Implemented | Signature-verified raw payloads are AES-GCM encrypted and committed before Meta is acknowledged; inline processing plus CLI recovery worker. |
| 8 | Unified message delivery state machine | Existing + regression coverage | Queued/sent/delivered/read/failed/web-only states and early/late callback reconciliation remain. Delivery is still at-least-once. |
| 9 | Outbound messaging reliability engine | Existing + integration | Transactional outbox, worker leases, exponential retry and terminal failure handling remain; duplicate send is possible after an ambiguous provider timeout. |
| 10 | Complete WhatsApp conversation synchronization | Implemented within the agreed text-only scope | Inbound text, Business App echoes and outbound delivery statuses synchronize when Coexistence is supported. Media, voice notes, edits, reactions and deletion sync remain explicitly out of scope. |
| 11 | Production-grade chat widget UX | Improved | Adds a post-session WhatsApp opt-out control; existing shadow-DOM isolation, responsive layout, keyboard handling and safe text rendering remain. |
| 12 | Resilient widget networking and synchronization | Existing | Session-scoped token persistence, idempotent submissions, cursor polling, delivery refresh and exponential retry remain. |
| 13 | WordPress plugin integration hardening | Implemented | Version 0.5.1, HTTPS-only API URL handling, safe remote health check, non-autoloaded private Meta key, manage_options/nonce-protected AJAX proxy and v4 operator UI; read-only WooCommerce catalogue boundary retained. |
| 14 | Meta Embedded Signup v4 integration | Implemented; live Meta approval pending | WordPress administrator launcher and server-side AJAX proxy, one-time state, server-side code exchange, WABA webhook subscription, encrypted token storage, 60-day token refresh and outbox credential loading. Actual Meta app review, partner eligibility and live account onboarding remain manual work. |
| 15 | Security, observability and diagnostics | Improved | AES-GCM payload/token storage, request IDs, PII-minimized structured logs, expanded health checks, queue counts, environment checks, exact-origin CORS, webhook HMAC and Supabase message-body minimization. Health schema inspection and queue queries now use a read-only repository; the route no longer issues SQL. Centralized alerting and live external checks remain future work. |
| 16 | Data retention, lifecycle automation and privacy controls | Implemented | CLI retention job delegates bounded policy to `RetentionService` and dependency-ordered transactional cleanup to `RetentionRepository` / `PdoRetentionRepository`; it prunes old closed conversations and related rows, expired sessions, processed webhook records, stale signup states and rate-limit buckets. Optional staff cleanup runs only when the staff module is enabled. Defaults require business/privacy approval. |
| 17 | Storage repository extraction | Partial — first MySQL/MariaDB slice implemented | `ChatRepository` and `PdoChatRepository` now own guest-session creation/authentication, visitor-message creation, polling and delivery-state reads. Message + WhatsApp outbox writes share a transaction; integration coverage verifies idempotent replay and rollback on outbox failure. Consent transitions and fixed-window rate-limit persistence use the repository contract, with MySQL integration coverage. Encrypted webhook inbox enqueue, lease claiming, completion and retry/failure transitions now also use the repository contract. Meta payload interpretation remains a separate application-service concern; retention policy and cleanup now use `RetentionService` plus a dedicated repository. Optional staff inbox persistence and authorization use a separate repository and service boundary. The WhatsApp outbox worker now uses repository operations for enqueue, atomic claim/reclaim, pre-send consent checks, cancellation, completion, retry and terminal failure; the Meta HTTP request remains outside transactions. Provider event deduplication, inbound/echo message writes, status-event storage and delivery-state reconciliation are repository-backed. Meta signup-state and connected-account lifecycle persistence now use a separate `MetaConnectionRepository` contract with a MySQL/MariaDB adapter. Health schema/queue reads use `HealthRepository` / `PdoHealthRepository`. Guest session orchestration is separated into `GuestSessionService` and persists through `ChatRepository`; visitor send/poll orchestration is separated into `ChatMessageService` and uses the same contract. No PostgreSQL adapter exists yet. |
| 18 | Optional staff inbox | Partial — core workflow implemented | Opt-in schema, separate MySQL/MariaDB repository, trusted WordPress HMAC proxy, versioned staff API, WordPress admin inbox UI, local owner/admin/agent policy, assignment history, private notes, website-only replies and audit trail are implemented. Assertions bind method, exact request target, body digest, short expiry and one-time nonce. Agent reads are assignment-scoped in SQL; note/reply writes and self-assignment/unassignment are guarded atomically under conversation locks. Both CLI role-provisioning entry points establish or update local roles after first sign-in; each locks active owner rows and refuses to demote the final active owner. The owner-only WordPress panel can promote/demote staff and disable/re-enable identities; repository transactions serialize owner changes, prevent removal of the last active owner and prevent self-lockout. Search covers visitor name, phone and message body while preserving each actor's assignment visibility. Staff reply and private-note writes support idempotent client retries. Disabling an identity preserves assignment history; active work remains visible to administrators for explicit reassignment. Export/restore tooling and shared multi-tenant isolation remain out of scope. The module is disabled by default and assumes one business per database. |

## Explicit production gates

- [ ] Run migration 004 against a backed-up staging database, then verify health.
- [ ] Run all CI checks and review the resulting pull request.
- [ ] Configure Meta App ID, Embedded Signup v4 configuration, Partner Solution ID in the secure operator page, App Secret, verify token and Graph version.
- [ ] Build/deploy a secure server-side operator proxy for the Meta begin/exchange endpoint; never expose the admin key in browser code.
- [ ] Complete test-number Embedded Signup and confirm smb_message_echoes from the Business app.
- [ ] Confirm the real Dimdian number's Coexistence eligibility with Meta.
- [ ] Approve the outbound template and test opt-in, opt-out, retries and status callbacks.
- [ ] Configure the three CLI jobs and test a restore from backup.
- [ ] Approve retention periods and privacy-notice wording.
- [ ] For businesses enabling the optional staff inbox, import its opt-in schema, configure matching server-side HMAC secrets, provision the first owner through the CLI, and verify agent assignment and private-note isolation.
- [ ] Keep outbound disabled until every WhatsApp launch gate passes.


Latest outbox extraction validation: [GitHub Actions run 44939900785](https://github.com/michaelkage/DimdianChatbot/actions/runs/44939900785) passed the MySQL integration suite and WordPress adapter compatibility jobs on PHP 7.4 and 8.2. Integration coverage includes worker success, retryable and permanent failure, exhausted leases, expired-lease reclaim, stale-token fencing, consent lookup, and atomic message/outbox completion.

Latest full CI validation: [GitHub Actions run 45200920244](https://github.com/michaelkage/DimdianChatbot/actions/runs/45200920244) passed all three jobs on the current PR head. PHP 8.2 lint and MySQL integration passed, including 17 helper tests, 65 application integration tests, 66 staff repository/policy tests (including both CLI last-owner guards), 18 signed staff HTTP tests, the WordPress plugin contract suite, and all 6 widget tests. WordPress adapter compatibility also passed on PHP 7.4 and 8.2.


### Follow-up implementation — outbox worker service boundary

- Extracted `WhatsAppOutboxWorker` into `app/channels/whatsapp/WhatsAppOutboxWorker.php`.
- The worker owns bounded batch processing, durable job claiming, the immediate consent recheck, provider invocation, retry classification, lease-fenced completion/failure and operational attempt logging.
- Repository calls remain the only persistence interface; provider HTTP I/O remains outside database transactions.
- Preserved `process_whatsapp_outbox(PDO, WhatsAppTextTransport, ...)` as a compatibility wrapper so existing CLI and application call sites do not change.
- Existing integration transports continue to exercise success, retryable failure, permanent failure and lease-reclaim behavior through the wrapper. CI validation is pending for this extraction.


### Follow-up implementation — webhook processor service boundary

- Extracted `MetaWebhookProcessor` into `app/channels/whatsapp/MetaWebhookProcessor.php`.
- The processor maps text inbound messages, Business App text echoes and delivery-status events into the existing `ChatRepository` provider-event contract, including event identifiers, payload hashes, timestamps and unsupported-event accounting.
- Preserved `process_meta_webhook()` as a compatibility facade. Signature verification and durable raw-webhook inbox lifecycle remain separate concerns and were not moved into this processor.
- Existing integration cases for inbound correlation, echo deduplication, early status reconciliation and late-failure precedence continue to exercise the facade. Full CI run [45258505392](https://github.com/michaelkage/DimdianChatbot/actions/runs/45258505392) passed all three jobs.


### Follow-up implementation — durable webhook inbox service boundary

- Extracted `WebhookPayloadCipher` into `app/channels/whatsapp/WebhookPayloadCipher.php`. It preserves the original SHA-256-derived AES-256-GCM key and `dimdian-webhook-v1` associated data, so previously encrypted inbox rows remain decryptable.
- Extracted `WebhookInboxWorker` into `app/channels/whatsapp/WebhookInboxWorker.php`. The worker owns leasing, authenticated decryption, payload validation, active phone-number resolution, event processing, lease-fenced completion, retry scheduling and failure logging. It has no PDO or HTTP dependency.
- Added `ChatRepository::activeWhatsAppPhoneNumberId()` and its MySQL/MariaDB implementation so connection lookup is behind the repository contract.
- Kept `encrypt_webhook_payload()`, `decrypt_webhook_payload()`, `enqueue_meta_webhook()` and `process_webhook_inbox(PDO, ...)` as compatibility facades. The legacy PDO parameter remains accepted by the latter but is no longer used by the worker.
- Removed the encompassing PDO transaction around the whole payload. Each provider event is now committed through the repository's idempotent event operation; inbox completion is lease-fenced and occurs only after all events are processed. If a worker crashes mid-payload, retry safely skips previously committed event IDs and resumes remaining events. This deliberately uses durable event-level idempotency rather than keeping a long transaction open while processing an entire provider payload.
- Added authenticated-encryption tamper regression coverage. Existing HTTP integration cases exercise inline processing, inbound messages, Business App echoes, duplicate delivery and status callbacks through the same worker. Full CI run [45259452485](https://github.com/michaelkage/DimdianChatbot/actions/runs/45259452485) passed all three jobs on the implementation head.


### Follow-up implementation — signature verification boundary

- Extracted `MetaWebhookSignatureVerifier` into `app/channels/whatsapp/MetaWebhookSignatureVerifier.php`.
- The verifier checks the exact raw request bytes, requires the SHA-256 signature format and uses constant-time comparison. The legacy `verify_meta_webhook_signature()` function delegates to the class.
- HTTP method negotiation, request-size enforcement, durable enqueue-before-acknowledgement and HTTP response generation remain in `public/api/webhook.php`. The verifier is covered by full CI run [45259452485](https://github.com/michaelkage/DimdianChatbot/actions/runs/45259452485).


### Follow-up implementation — HTTP endpoint boundary

- Extracted `MetaWebhookEndpoint` into `app/channels/whatsapp/MetaWebhookEndpoint.php`.
- The endpoint owns Meta GET handshake validation, POST size/signature/JSON/envelope validation, encrypted inbox enqueue-before-acknowledgement and bounded inline processing. Invalid POST requests are rejected before a database connection is opened. It returns a response description and does not emit headers or terminate the process.
- `public/api/webhook.php` is now a thin PHP adapter for reading the raw body, handling Meta's GET verification handshake, mapping the response to HTTP and logging unexpected failures. The route invokes the endpoint's static handshake and POST validation paths before opening a database connection, preserving database-independent verification and avoiding DB work for invalid requests.
- The CLI recovery endpoint calls the same repository-backed inbox worker directly; it does not route through HTTP.
- Added integration coverage for unsupported HTTP methods and malformed JSON carrying a valid signature. Full CI run [45259452485](https://github.com/michaelkage/DimdianChatbot/actions/runs/45259452485) passed all three jobs.


### Follow-up implementation — Meta signup infrastructure boundaries

- Extracted `MetaTokenCipher` into `app/channels/whatsapp/MetaTokenCipher.php`. It preserves the existing SHA-256 key derivation and `dimdian-meta-token-v1` AAD, so already-encrypted access tokens remain decryptable.
- Extracted `MetaGraphApiClient` into `app/channels/whatsapp/MetaGraphApiClient.php`. It centralizes the existing GET/POST behavior, pins requests to HTTPS `graph.facebook.com` versioned endpoints, disables redirects to prevent bearer-token forwarding, and rejects CR/LF in bearer tokens.
- Kept `meta_seal_token()`, `meta_open_token()`, `meta_graph_get()` and `meta_graph_post()` as compatibility facades.
- Added `MetaConnectionRepository` / `PdoMetaConnectionRepository` for atomic one-time signup-state consumption, connection upsert/lookup, encrypted token refresh persistence and expiry transitions. Existing table definitions and encrypted token formats are unchanged.
- Refactored the existing Embedded Signup procedural entry points to delegate all Meta connection/state SQL to the repository. `MetaEmbeddedSignupService` now owns the higher-level workflow behind injected repository, cipher, Graph client and configuration dependencies; the procedural functions are compatibility facades.
- Added MySQL integration coverage for state replay rejection, active connection persistence, token refresh persistence and expiry transitions, plus isolated application-service tests for code exchange, encrypted token storage, replay rejection, refresh and WABA subscription. Added regression tests for token ciphertext tampering, non-Meta hosts and non-HTTPS Graph URLs. Earlier transport/cipher CI runs [45259832664](https://github.com/michaelkage/DimdianChatbot/actions/runs/45259832664) and [45259935499](https://github.com/michaelkage/DimdianChatbot/actions/runs/45259935499) passed all three jobs; validation for the repository extraction is pending.


### Meta Embedded Signup extraction validation (2026-11-24)

- GitHub Actions run [45394603873](https://github.com/michaelkage/DimdianChatbot/actions/runs/45394603873) passed all three jobs on commit `adeea59e8b5e92ce901c9edc50d9e991dfbec15f`: PHP lint/MySQL integration and WordPress compatibility on PHP 7.4 and 8.2.
- The run includes the Meta repository lifecycle integration cases and isolated application-service tests for state issuance/consumption, code exchange, token encryption, replay rejection, refresh and WABA subscription.


### Operational health extraction validation (2026-11-24)

- GitHub Actions run [45395726992](https://github.com/michaelkage/DimdianChatbot/actions/runs/45395726992) passed all three jobs on the health-service implementation: PHP/MySQL integration tests and WordPress compatibility on PHP 7.4 and 8.2.
- The existing health-route integration assertions now execute through `PdoHealthRepository`; isolated service tests cover healthy reports and the incomplete-schema path that must suppress queue queries.


### Guest session service extraction validation (2026-11-24)

- GitHub Actions run [45396431439](https://github.com/michaelkage/DimdianChatbot/actions/runs/45396431439) passed all three jobs on the guest-session service implementation: PHP/MySQL integration tests, widget checks/tests and WordPress compatibility on PHP 7.4 and 8.2.
- Integration coverage verifies that only the SHA-256 token hash is stored and that exhausted session-rate limits produce the bounded retry signal translated by the HTTP route.


### Visitor message application service validation (2026-11-24)

- GitHub Actions run [45396953010](https://github.com/michaelkage/DimdianChatbot/actions/runs/45396953010) passed all three jobs: PHP/MySQL integration tests, widget tests and WordPress compatibility on PHP 7.4 and 8.2.
- Existing API integration scenarios exercise message creation, idempotent replay/conflict, delivery-state polling and delivery updates through `ChatMessageService`. Rate-limit consumption remains before idempotency-key validation, preserving the previous request-ordering behavior.


### Visitor consent service extraction validation (2026-11-24)

- GitHub Actions run [45397234701](https://github.com/michaelkage/DimdianChatbot/actions/runs/45397234701) passed all three jobs: PHP/MySQL integration tests, widget tests and WordPress compatibility on PHP 7.4 and 8.2.
- The existing consent API integration case verifies that opt-out clears current consent and disables WhatsApp identity routing through `WhatsAppConsentService` and the repository's transactional transition.


### Versioned API contract status (2026-11-24)

- `docs/openapi.yaml` publishes the OpenAPI 3.1 contract for the core v1 widget/operator endpoints: guest session, message send/poll, consent, health, Meta webhook and administrator Embedded Signup.
- `docs/openapi-staff.yaml` separately specifies all ten implemented optional staff routes, including the request-bound HMAC assertion, role and assignment restrictions, private notes, website-only replies and administrator audit access.
- CI runs a dependency-free structural validator over both documents. It checks OpenAPI 3.1, expected route coverage, unique operation IDs, declared responses, request-body content, all local references and required security schemes. Reusable request/response fixtures, full schema conformance and cross-backend acceptance tests remain future work.


### Backend-neutral API acceptance validation (2026-11-24)

- GitHub Actions run [45407583097](https://github.com/michaelkage/DimdianChatbot/actions/runs/45407583097) passed on PR head `eba66dc1b2be96287b72a8887e52f33f134e14aa`.
- The new Node acceptance runner executed against the PHP/MySQL test deployment and passed session creation, token shape, message persistence, idempotent replay/conflict, invalid-token rejection, polling/cursor output, opt-in phone precondition and consent withdrawal.
- The same run passed the OpenAPI structural validator, PHP/MySQL integration and staff inbox HTTP/policy suites, widget tests and WordPress compatibility on PHP 7.4 and 8.2.


### Staff assignment SQL compatibility and acceptance validation (2026-11-24)

- The backend-neutral staff API runner exposed a native-PDO-prepares defect: the owner and agent assignment INSERT statements reused the same named parameter for both `conversation_id` and `active_conversation_id`, causing SQLSTATE `HY093` in production-style native prepares.
- Updated both statements to use distinct bound parameter names. Added HTTP coverage for owner assignment and unassignment, plus assertions that both assignment-history rows remain with their ended timestamps.
- GitHub Actions run [45409680687](https://github.com/michaelkage/DimdianChatbot/actions/runs/45409680687) passed all three jobs on commit `ea52fa105028c6d214d619229aa10bc7a411e1dc`. The backend-neutral public and staff runners, PHP/MySQL integration, staff repository/HTTP suites, widget tests and WordPress compatibility on PHP 7.4/8.2 all passed.


### Tenant-aware domain model — design boundary (2026-11-24)

- Inspected the actual core and optional staff DDL, widget bootstrap and repository contracts. The current schema has no tenant/business root or tenant keys; ChatRepository, MetaConnectionRepository and StaffInboxRepository are installation-scoped; the widget currently sends no tenant-resolving public identifier.
- Added [TENANCY-ARCHITECTURE.md](TENANCY-ARCHITECTURE.md) with the current table ownership inventory, tenant-context resolution rules, composite foreign-key/unique-index requirements, the multi-entry Meta webhook routing exception, staged migration sequence and cross-tenant negative acceptance suite.
- This is a design and migration guardrail only. No tenant columns, resolver, tenant-scoped SQL or shared-database mode have been implemented. The supported boundary remains one business per database.


### Storage capability-interface decomposition (2026-11-24)

- Split the broad chat persistence contract into guest-session, rate-limit, visitor-message, consent, WhatsApp-identity, webhook-inbox, provider-event and WhatsApp-outbox capabilities.
- Added focused composition interfaces for guest-session operations, visitor messaging and webhook processing. The existing `ChatRepository` remains the backward-compatible aggregate implemented by `PdoChatRepository` and existing test adapters.
- Narrowed application dependencies: session service, visitor message service, consent service, Meta webhook processor, webhook inbox worker, WhatsApp outbox worker and HTTP helper functions now depend only on the capabilities they call.
- Added contract tests asserting that the aggregate retains every capability. This is interface segregation, not a second database adapter; the implementation remains MySQL/MariaDB PDO and milestone 17 remains partial.
- GitHub Actions run [45411946544](https://github.com/michaelkage/DimdianChatbot/actions/runs/45411946544) passed all three jobs on the latest capability-interface and documentation head.


### Dedicated webhook inbox PDO adapter (2026-11-24)

- Extracted encrypted inbox enqueue, atomic lease claim/reclaim, lease-fenced completion and retry/failure persistence into `PdoWebhookInboxRepository`.
- `PdoChatRepository` remains the backward-compatible aggregate and delegates the four inbox capability methods to the dedicated adapter using the same PDO connection. Provider-event interpretation and provider-event persistence remain separate concerns.
- The shared integration suite continues to exercise the aggregate path, so existing cron and HTTP composition do not change. The new adapter is also checked directly against `WebhookInboxRepository`.
- This is the first physical PDO adapter split; other chat persistence capabilities still live in `PdoChatRepository` and are not claimed as extracted.


### Dedicated rate-limit PDO adapter (2026-11-24)

- Extracted fixed-window bucket hashing, expiry calculation, atomic upsert/count enforcement and transaction handling into `PdoRateLimitRepository`.
- `PdoChatRepository` delegates `consumeRateLimit()` to the new adapter using the same PDO connection, preserving the aggregate contract and existing transaction semantics.
- Added a direct adapter-interface contract assertion. Existing session and visitor-message integration tests continue to exercise rate limiting through the aggregate.
- The remaining guest/session, consent, visitor-message, provider-event and outbox SQL has not yet been physically split from `PdoChatRepository`.


### Dedicated consent PDO adapter (2026-11-24)

- Extracted WhatsApp consent validation, active identity-route replacement/deactivation, guest opt-in state, consent-ledger writes and opt-out cancellation of pending/processing outbox jobs into `PdoConsentRepository`.
- `PdoChatRepository` delegates the consent transition to this adapter on the same PDO connection. Guest-session creation can still include initial opt-in in its existing outer transaction; the adapter detects and participates in that transaction rather than committing independently.
- Added a direct adapter-interface contract assertion. Existing consent and session integration tests exercise aggregate delegation, including opt-out/outbox cancellation semantics.
- Visitor/session and outbox SQL remain partially in the aggregate; this is not full persistence decomposition.


### Dedicated guest-session PDO adapter (2026-11-24)

- Extracted guest, conversation and guest-session creation, token-hash lookup and session-touch persistence into `PdoGuestSessionRepository`.
- Initial WhatsApp opt-in still uses `PdoConsentRepository` on the same PDO connection and participates in the outer guest/session transaction. A failure in the consent ledger or identity-route write therefore rolls back guest, conversation and session creation together.
- `PdoChatRepository` delegates the three guest-session operations to the dedicated adapter; existing public session and authentication routes remain unchanged.
- Added a direct adapter-interface contract assertion. Existing integration coverage exercises session creation, lookup and authentication through the aggregate.


### Dedicated visitor-message PDO adapter (2026-11-24)

- Extracted visitor message creation, idempotency replay/conflict detection, atomic optional WhatsApp outbox insertion, conversation activity updates, visitor polling and delivery-state polling into `PdoVisitorMessageRepository`.
- `PdoChatRepository` delegates the visitor-message capability to this adapter on the same PDO connection. Message and outbox writes remain in one transaction; an outbox insert failure still rolls back the message.
- Added a direct adapter-interface contract assertion. Existing MySQL integration tests cover idempotent replay, no duplicate outbox job and rollback on outbox failure through the aggregate.


### Adapter extraction validation update (2026-11-24)

[GitHub Actions run 45414518711](https://github.com/michaelkage/DimdianChatbot/actions/runs/45414518711) passed all three jobs on the visitor-message adapter commit. This includes PHP lint, OpenAPI validation, backend-neutral public and staff acceptance suites, MySQL integration for consent/session/message idempotency and outbox rollback, staff HTTP tests, widget tests and PHP 7.4/8.2 WordPress compatibility.


### Dedicated provider-event PDO adapter (2026-11-24)

- Extracted provider event deduplication, active WhatsApp identity routing, inbound/Business App message insertion, ignored-event accounting, provider status-event persistence and delivery-state precedence reconciliation into `PdoProviderEventRepository`.
- `PdoChatRepository` delegates the provider-event capability to this adapter on the same PDO connection. Outbox completion continues to reconcile early-arriving provider statuses through the aggregate compatibility method.
- Added a direct adapter-interface contract assertion. Existing MySQL integration coverage exercises webhook event deduplication, inbound routing and status reconciliation through the aggregate.


### Dedicated WhatsApp outbox PDO adapter (2026-11-24)

- Extracted outbound job enqueue, bounded claim/reclaim, attempt exhaustion, pre-send consent lookup, lease-fenced cancellation/completion/retry and terminal failure into `PdoWhatsAppOutboxRepository`.
- The adapter receives `ProviderEventRepository` and reconciles an early-arriving provider status in the same PDO transaction when send acceptance is persisted. Provider network I/O remains outside database transactions in `WhatsAppOutboxWorker`.
- `PdoChatRepository` delegates the full outbox capability to the new adapter; visitor-message atomic enqueue remains owned by `PdoVisitorMessageRepository` within its own message/outbox transaction.
- Added a direct adapter-interface contract assertion. Existing integration coverage exercises retry, terminal failure, consent withdrawal, lease fencing and status reconciliation through the aggregate.


### Meta connection lookup consolidation (2026-11-24)

- Removed the duplicate active-phone-number SQL from `PdoChatRepository`; its `WhatsAppIdentityRepository` compatibility method now delegates to `PdoMetaConnectionRepository::activePhoneNumberId()` on the same PDO connection.
- Reordered bootstrap loading so the Meta connection contract and adapter are loaded before the aggregate adapter that composes them.
- This keeps active connection selection in one storage implementation while preserving the webhook worker's existing identity capability.


### PDO aggregate converted to composition-only adapter (2026-11-24)

- After extracting the capability slices, removed the remaining transaction helper and direct PDO property from `PdoChatRepository`. It no longer executes SQL or owns transaction logic.
- The class now only constructs the focused adapters over the same PDO connection and delegates the legacy aggregate methods. The actual MySQL/MariaDB SQL and transaction boundaries reside in the focused adapters.
- Added a reflection regression test confirming that `PdoChatRepository` still implements `ChatRepository` but does not retain direct PDO state.


### PDO aggregate composition validation

[GitHub Actions run 45415674271](https://github.com/michaelkage/DimdianChatbot/actions/runs/45415674271) passed all three jobs after converting `PdoChatRepository` to a composition-only adapter and adding the reflection regression assertion. [Run 45415643135](https://github.com/michaelkage/DimdianChatbot/actions/runs/45415643135) also passed on the composition refactor commit itself.


### Shared outbox adapter used by visitor-message transaction (2026-11-24)

- Removed the duplicate `message_outbox` INSERT from `PdoVisitorMessageRepository`. It now invokes the injected `WhatsAppOutboxRepository` capability to enqueue the outbound job.
- The aggregate composes the outbox adapter first and injects the same adapter into the visitor-message adapter. Both share the same PDO connection; enqueue remains inside the visitor-message transaction, preserving rollback and idempotency behavior.
- Added a reflection contract check for the outbox capability dependency. Existing MySQL integration tests continue to verify one outbox row on first send, no second row on idempotent replay, and rollback when enqueue fails.


### Shared outbox enqueue validation

[GitHub Actions run 45416144989](https://github.com/michaelkage/DimdianChatbot/actions/runs/45416144989) passed all three jobs after the visitor-message adapter began using the injected outbox capability for atomic queue insertion. MySQL integration again passed the idempotent replay and message/outbox rollback assertions; PHP 7.4 and 8.2 WordPress compatibility also passed.


### Guest-session authentication service extraction (2026-11-24)

- Added `GuestSessionAuthenticator`, which validates the opaque token format, hashes it before lookup, resolves the session, updates last-seen state and enforces the optional open-conversation requirement.
- The service receives only `GuestSessionRepository`; it does not read `$_SERVER`, emit JSON or terminate the request. It raises typed failures for malformed token, missing/expired session and closed conversation.
- `authenticate_guest_session()` remains the HTTP compatibility adapter: it extracts `HTTP_X_DIMDIAN_GUEST_TOKEN` and maps typed failures to the existing 401/409 API error codes and messages.
- Added unit coverage for hashing, lookup/touch, invalid and missing sessions, and closed-session behavior, plus a dependency-boundary assertion.
