# File-change manifest — production hardening

## New files
- app/staff/StaffInboxRepository.php — optional staff persistence contract.
- app/staff/PdoStaffInboxRepository.php — MySQL/MariaDB staff identity, assignment, private-note, website-reply and audit persistence.
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
- wordpress-plugin/chatbridge-widget/chatbridge-widget.php — version 0.4.0, safe remote health request, private non-autoloaded Meta key storage, server-side Meta proxy and optional staff inbox integration.
- wordpress-plugin/chatbridge-widget/readme.txt — plugin stable version and changelog.
- tests/run.php / tests/integration.php / tests/staff-inbox.php / tests/wordpress-plugin.php — encryption, PII-safe logging, consent lifecycle, staff persistence and private admin-key regression coverage.
- .github/workflows/tests.yml — imports the opt-in staff schema only in the disposable CI database and runs staff inbox integration tests.
- .github/workflows/tests.yml — checks admin/customer widget JavaScript syntax.
- docs/API.md / docs/WHATSAPP.md / docs/WORDPRESS-PLUGIN.md / README.md — updated contracts and operations.
- supabase/functions/meta-webhook/index.ts — message-body persistence disabled by default.
- supabase/README.md — diagnostic data minimization and existing service-role-only RLS boundary.

## Deliberately not included / external gates
- The optional staff inbox core is implemented: opt-in schema, HMAC assertion authentication, local role/assignment authorization, versioned API, WordPress server-side proxy and separate admin UI. Initial owner provisioning is CLI-only. Conversation search, in-product role management, export/restore and shared-database multi-tenant isolation remain incomplete. Its schema is opt-in and is not part of the default deployment.
- No media/voice-note/reaction/edit/delete synchronization; WhatsApp sync is text and delivery-status scoped.
- Meta token renewal is attempted automatically within the final seven days; operator reconnection is still required after an unrecoverable expiry.
- Meta business verification, app review and Partner/Solution eligibility must be completed by the business in Meta.
- The legacy app/whatsapp.php has not been fully decomposed into smaller service classes.
- No live Meta, Hostinger or WordPress production configuration was changed by these repository commits.
