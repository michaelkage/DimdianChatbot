# Milestone acceptance status

This checklist distinguishes shipped repository changes from operational work and deliberately retained product boundaries. A checked item means the corresponding code path or safeguard is present; it does not mean production credentials, Meta eligibility or Hostinger deployment have been verified.

| # | Milestone | Status | Evidence / boundary |
|---:|---|---|---|
| 1 | Application service architecture refactoring | Partial | Existing PHP service boundary remains framework-free. New consent, inbox and Meta onboarding responsibilities are separated into focused files. The legacy app/whatsapp.php is still a large procedural service and should be split further in a dedicated refactor with full DB integration tests. |
| 2 | Centralized configuration and environment management | Implemented | app/bootstrap.php overlays documented DIMDIAN_* environment variables on private config.php. |
| 3 | Database integrity and migration management | Implemented | Migration 004 and fresh-install schema add consent ledger, active identity links, encrypted webhook inbox and Meta connection/state tables. Migration backfills legacy consent timestamps and deterministic active phone links. |
| 4 | Guest identity and session lifecycle management | Implemented | Existing random guest tokens remain hash-only, expiring and revocable; session creation and authenticated lifecycle are retained. |
| 5 | Customer consent and privacy lifecycle | Implemented | Append-only consent events, explicit post-session opt-out, route deactivation, queued-send cancellation and an immediate pre-send consent recheck. An already in-flight provider request cannot be recalled. |
| 6 | Conversation identity and message correlation | Implemented | Webhook routing now uses one active consent-backed phone-to-conversation link instead of choosing the latest open conversation. |
| 7 | Durable webhook ingestion pipeline | Implemented | Signature-verified raw payloads are AES-GCM encrypted and committed before Meta is acknowledged; inline processing plus CLI recovery worker. |
| 8 | Unified message delivery state machine | Existing + regression coverage | Queued/sent/delivered/read/failed/web-only states and early/late callback reconciliation remain. Delivery is still at-least-once. |
| 9 | Outbound messaging reliability engine | Existing + integration | Transactional outbox, worker leases, exponential retry and terminal failure handling remain; duplicate send is possible after an ambiguous provider timeout. |
| 10 | Complete WhatsApp conversation synchronization | Implemented within the agreed text-only scope | Inbound text, Business App echoes and outbound delivery statuses synchronize when Coexistence is supported. Media, voice notes, edits, reactions and deletion sync remain explicitly out of scope. |
| 11 | Production-grade chat widget UX | Improved | Adds a post-session WhatsApp opt-out control; existing shadow-DOM isolation, responsive layout, keyboard handling and safe text rendering remain. |
| 12 | Resilient widget networking and synchronization | Existing | Session-scoped token persistence, idempotent submissions, cursor polling, delivery refresh and exponential retry remain. |
| 13 | WordPress plugin integration hardening | Implemented | Version 0.4.0, HTTPS-only API URL handling, safe remote health check, non-autoloaded private Meta key, manage_options/nonce-protected AJAX proxy and v4 operator UI; read-only WooCommerce catalogue boundary retained. |
| 14 | Meta Embedded Signup v4 integration | Implemented; live Meta approval pending | WordPress administrator launcher and server-side AJAX proxy, one-time state, server-side code exchange, WABA webhook subscription, encrypted token storage, 60-day token refresh and outbox credential loading. Actual Meta app review, partner eligibility and live account onboarding remain manual work. |
| 15 | Security, observability and diagnostics | Improved | AES-GCM payload/token storage, request IDs, PII-minimized structured logs, expanded health checks, queue counts, environment checks, exact-origin CORS, webhook HMAC and Supabase message-body minimization. Centralized alerting and live external checks remain future work. |
| 16 | Data retention, lifecycle automation and privacy controls | Implemented | CLI retention job deletes old closed conversations and related rows, expired sessions, processed webhook records, stale signup states and rate-limit buckets. Defaults require business/privacy approval. |
| 17 | Storage repository extraction | Partial — first MySQL/MariaDB slice implemented | `ChatRepository` and `PdoChatRepository` now own guest-session creation/authentication, visitor-message creation, polling and delivery-state reads. Message + WhatsApp outbox writes share a transaction; integration coverage verifies idempotent replay and rollback on outbox failure. Consent transitions and fixed-window rate-limit persistence use the repository contract, with MySQL integration coverage. Encrypted webhook inbox enqueue, lease claiming, completion and retry/failure transitions now also use the repository contract. Meta event interpretation/event-to-message persistence, staff operations, retention and outbox-worker persistence remain PDO-coupled. No PostgreSQL adapter exists yet. |

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
- [ ] Keep outbound disabled until every WhatsApp launch gate passes.
