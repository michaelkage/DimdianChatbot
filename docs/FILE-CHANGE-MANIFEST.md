# File-change manifest — production hardening

## New files
- app/staff/StaffInboxRepository.php — optional staff persistence contract.
- app/staff/PdoStaffInboxRepository.php — MySQL/MariaDB staff identity, assignment, private-note, website-reply, audit, owner-safe role updates and conversation search persistence.
- app/staff/HmacStaffIdentityProvider.php — signed server assertion verification, exact request/body binding and replay protection.
- app/staff/StaffInboxService.php — local role, assignment and staff-operation authorization.
- app/staff/bootstrap.php — optional staff module wiring.
- public/api/v1/staff/ — versioned staff inbox, notes, replies, assignments, audit and identity routes.
- wordpress-plugin/chatbridge-widget/staff-inbox.php — authenticated WordPress AJAX proxy and admin inbox integration.
- wordpress-plugin/chatbridge-widget/assets/staff-inbox-admin.js / staff-inbox-admin.css — separate staff inbox UI.
- bin/set-staff-role.php — CLI-only local staff role provisioning.
- tests/staff-inbox-http.php — signed staff API route integration tests.
- database/optional/staff-inbox.sql — opt-in staff inbox schema, excluded from the default install.
- docs/STAFF-INBOX-ARCHITECTURE.md — optional module boundaries, security model and implementation sequence.
- tests/staff-inbox.php — staff persistence integration tests.
- app/consent.php — append-only consent audit and opt-in/opt-out lifecycle.
- app/webhook_inbox.php — encrypted durable webhook inbox, payload deduplication and retry worker.
- app/meta_signup.php — one-time Embedded Signup state, server-side code exchange, WABA subscription, encrypted token storage and token renewal.
- app/observability.php — request IDs and PII-minimized structured event logging.
- public/api/consent.php — guest-authenticated consent preference endpoint.
- public/api/meta-embedded-signup.php — administrator-only Meta onboarding endpoint.
- public/cron/process-webhook-inbox.php — CLI webhook recovery worker.
- public/cron/retention.php — CLI data-retention job.
- wordpress-plugin/chatbridge-widget/assets/meta-signup-admin.js — administrator-only Meta Embedded Signup v4 UI.
- wordpress-plugin/chatbridge-widget/assets/staff-inbox-admin.js / .css — optional staff inbox UI, conversation search and owner staff-access panel.
- database/migrations/004_production_hardening.sql — existing-database upgrade and legacy opt-in backfill.
- docs/PRODUCTION-HARDENING.md — deployment and operations guide.
- docs/MILESTONE-STATUS.md — milestone status and production acceptance gates.
- docs/MANUAL-SETUP-CHECKLIST.md — full Hostinger, MySQL, WordPress, Meta, cron, privacy and regression-test runbook.

## Updated files
- app/bootstrap.php — private environment-variable overrides, request-ID initialization and raw request-body preservation for HMAC verification.
- app/whatsapp.php — exact active identity-link correlation, immediate pre-send consent recheck and PII-minimized outbox failure events.
- config.example.php — privacy, Meta onboarding and message-rate configuration.
- database/schema.sql — fresh-install schema for all hardening tables.
- public/api/health.php — required-table/runtime checks, queue counts and request ID.
- public/api/messages.php — structured failure events without raw exception messages.
- public/api/session.php — explicit opt-in audit and active identity-link creation.
- public/api/webhook.php — signature verification, encrypted durable queueing before Meta acknowledgement.
- public/cron/process-outbox.php — encrypted Embedded Signup credentials and structured worker logs.
- public/widget.js / public/widget.css — post-session WhatsApp opt-out control, including access for pre-upgrade sessions.
- wordpress-plugin/chatbridge-widget/chatbridge-widget.php — version 0.5.1, safe remote health request, private non-autoloaded Meta key storage, server-side Meta proxy and optional staff inbox integration.
- wordpress-plugin/chatbridge-widget/readme.txt — plugin stable version and changelog.
- tests/run.php / tests/integration.php / tests/staff-inbox.php / tests/wordpress-plugin.php — encryption, PII-safe logging, consent lifecycle, staff persistence and private admin-key regression coverage.
- .github/workflows/tests.yml — imports the opt-in staff schema only in the disposable CI database and runs staff inbox integration tests.
- .github/workflows/tests.yml — checks admin/customer widget JavaScript syntax.
- docs/API.md / docs/WHATSAPP.md / docs/WORDPRESS-PLUGIN.md / README.md — updated contracts and operations.
- supabase/functions/meta-webhook/index.ts — message-body persistence disabled by default.
- supabase/README.md — diagnostic data minimization and existing service-role-only RLS boundary.

## Additional service and repository decomposition

### New files
- app/channels/whatsapp/MetaGraphApi.php — injectable provider API contract for Meta onboarding.
- app/channels/whatsapp/MetaEmbeddedSignupService.php — injected Embedded Signup state machine, code exchange, WABA subscription and token lifecycle.
- app/channels/whatsapp/MetaTokenCipher.php / MetaGraphApiClient.php — encrypted token boundary and HTTPS-pinned Meta Graph transport.
- app/storage/MetaConnectionRepository.php / PdoMetaConnectionRepository.php — atomic signup-state consumption and connected-account/token lifecycle persistence.
- app/maintenance/RetentionService.php — bounded retention policy and optional staff-module gating.
- app/storage/RetentionRepository.php / PdoRetentionRepository.php — dependency-ordered cleanup in one transaction.
- app/operations/HealthService.php — runtime/schema readiness and queue-metric composition.
- app/storage/HealthRepository.php / PdoHealthRepository.php — read-only required-table and queue diagnostics.
- app/chat/GuestSessionService.php — session creation, one-time token generation/hash persistence, expiry and rate-limit signaling.
- app/chat/ChatMessageService.php — visitor message send/poll orchestration, consent-aware outbound eligibility and idempotency.
- app/chat/WhatsAppConsentService.php — visitor opt-in/opt-out application service and missing-phone domain signal.
- docs/openapi.yaml / docs/openapi-staff.yaml — separate OpenAPI 3.1 contracts for the core v1 widget API and optional server-to-server staff API.
- tests/validate-openapi.rb — dependency-free structural validation of OpenAPI version, expected route coverage, operation IDs, responses, request-body content, security schemes and local references.
- tests/api-acceptance.mjs — backend-neutral HTTP acceptance runner for guest sessions, messages, idempotency, authentication, polling and consent.
- tests/staff-api-acceptance.mjs — backend-neutral HMAC-authenticated staff API runner for role/assignment isolation, notes, replies, history and forbidden operations.
- app/staff/PdoStaffInboxRepository.php — uses distinct PDO parameter names for conversation and active-conversation assignment columns, compatible with native prepared statements.

### Additional updated files
- app/meta_signup.php — compatibility functions now delegate to MetaEmbeddedSignupService and MetaConnectionRepository.
- app/bootstrap.php — repository contracts/adapters and factories for Meta connection, retention and health.
- public/api/session.php / messages.php / consent.php / health.php — thin HTTP adapters over application services.
- public/api/meta-embedded-signup.php — correct supported-action guidance for begin, exchange and subscribe.
- public/api/v1/*.php — refreshed compatibility-alias architecture notes; all aliases delegate to shared handlers.
- public/cron/retention.php — CLI-only adapter over RetentionService and RetentionRepository.
- tests/run.php / tests/integration.php / tests/staff-inbox.php / tests/staff-inbox-http.php — service policy, encryption, token lifecycle, health, retention, session token, rate-limit and staff HTTP regression coverage, including owner assignment/unassignment and retained assignment history.
- tests/api-contract.md — documents the core and optional staff acceptance requirements and how to run the backend-neutral suite.
- .github/workflows/tests.yml — runs the OpenAPI structural validator and both backend-neutral public/staff API acceptance runners, alongside the PHP/MySQL-specific suites.
- docs/API-V1.md / API.md — links to both OpenAPI contracts and updates current service/storage boundaries.
- docs/PORTABLE-ARCHITECTURE.md / MILESTONE-STATUS.md — documents extracted service/repository boundaries and remaining portability work.
- docs/FILE-CHANGE-MANIFEST.md — this additional implementation inventory.

## Deliberately not included / external gates
- The optional staff inbox core is implemented: opt-in schema, HMAC assertion authentication, local role/assignment authorization, versioned API, WordPress server-side proxy and separate admin UI. Initial owner provisioning is CLI-only; owners then manage staff roles and account status in-product. Conversation search is bounded to visitor name, phone and message body. Export/restore and shared-database multi-tenant isolation remain incomplete. Its schema is opt-in and is not part of the default deployment.
- No media/voice-note/reaction/edit/delete synchronization; WhatsApp sync is text and delivery-status scoped.
- Meta token renewal is attempted automatically within the final seven days; operator reconnection is still required after an unrecoverable expiry.
- Meta business verification, app review and Partner/Solution eligibility must be completed by the business in Meta.
- app/whatsapp.php remains a compatibility facade with some procedural transport/utility helpers; the webhook, outbox, Meta onboarding, retention, health, guest-session, message and consent use cases have been extracted, but the entire legacy file is not yet decomposed.
- The two OpenAPI 3.1 documents cover the core v1 widget/operator routes and all ten optional staff routes. Reusable request/response fixtures and deeper schema/conformance testing remain outstanding.
- The PHP storage adapters remain MySQL/MariaDB-specific; no PostgreSQL or SQLite adapter is implemented.
- No live Meta, hosting-provider or WordPress production configuration was changed by these repository commits.

- docs/TENANCY-ARCHITECTURE.md — design-only tenant ownership inventory, context resolution, composite constraints, Meta webhook routing, staged migration and isolation acceptance requirements; no tenant behavior is implemented.

- app/storage/ChatRepository.php — split the aggregate contract into session, rate-limit, visitor-message, consent, WhatsApp-identity, webhook-inbox, provider-event and outbox capability interfaces; ChatRepository remains the aggregate compatibility contract.
- app/chat/* and app/channels/whatsapp/* — narrow application-service and worker constructor dependencies to the capabilities each uses.
- tests/run.php — verifies ChatRepository still implements each capability interface and asserts that application-service constructors depend on their narrow repository capability rather than PDO or the aggregate ChatRepository.

- app/storage/PdoWebhookInboxRepository.php — dedicated MySQL/MariaDB adapter for encrypted webhook inbox enqueue, atomic lease claim/reclaim, completion and retry/failure transitions.
- app/storage/PdoChatRepository.php — retains the aggregate compatibility contract and delegates webhook inbox operations to the dedicated adapter on the same PDO connection.
- app/bootstrap.php — loads the dedicated inbox adapter before the aggregate PDO adapter.

- app/storage/PdoRateLimitRepository.php — dedicated MySQL/MariaDB fixed-window rate-limit adapter.
- app/storage/PdoChatRepository.php — delegates rate-limit operations to PdoRateLimitRepository while preserving ChatRepository.

- app/storage/PdoConsentRepository.php — dedicated MySQL/MariaDB adapter for transactional WhatsApp consent, identity routing and opt-out cancellation.
- app/storage/PdoChatRepository.php — delegates consent transitions to PdoConsentRepository while preserving aggregate compatibility and nested transaction behavior.

- app/storage/PdoGuestSessionRepository.php — dedicated MySQL/MariaDB adapter for guest, conversation and guest-session persistence; shares the consent adapter for atomic initial opt-in.
- app/storage/PdoChatRepository.php — delegates guest-session creation, token lookup and touch operations to PdoGuestSessionRepository.

- app/storage/PdoVisitorMessageRepository.php — dedicated MySQL/MariaDB adapter for visitor message creation/idempotency, atomic WhatsApp outbox insertion, polling and delivery-state reads.
- app/storage/PdoChatRepository.php — delegates visitor message and polling methods to PdoVisitorMessageRepository.
