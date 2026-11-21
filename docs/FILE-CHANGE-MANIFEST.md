# File-change manifest — production hardening

## New files
- app/consent.php — append-only consent audit and opt-in/opt-out lifecycle.
- app/webhook_inbox.php — encrypted durable webhook inbox, deduplication and retry worker.
- app/meta_signup.php — admin-protected Embedded Signup state, token exchange, WABA subscription and encrypted token storage.
- app/observability.php — request IDs and PII-minimized structured event logging.
- public/api/consent.php — guest-authenticated consent preference endpoint.
- public/api/meta-embedded-signup.php — admin-only Meta onboarding endpoint.
- public/cron/process-webhook-inbox.php — CLI webhook recovery worker.
- public/cron/retention.php — CLI data-retention job.
- wordpress-plugin/chatbridge-widget/assets/meta-signup-admin.js — administrator-only Meta Embedded Signup v4 UI.
- database/migrations/004_production_hardening.sql — existing-database upgrade.
- docs/PRODUCTION-HARDENING.md — deployment and operations guide.
- docs/MILESTONE-STATUS.md — milestone status and production acceptance gates.
- docs/MANUAL-SETUP-CHECKLIST.md — full Hostinger, MySQL, WordPress, Meta, cron, privacy and regression-test runbook.

## Updated files
- app/bootstrap.php — private environment-variable overrides and request-ID initialization.
- app/whatsapp.php — exact active identity-link correlation instead of latest-open-conversation matching.
- config.example.php — privacy, Meta onboarding and message-rate configuration.
- database/schema.sql — fresh-install schema for all hardening tables.
- public/api/health.php — checks the new tables, OpenSSL/PDO MySQL runtime, queue counts and request ID.
- public/api/session.php / public/api/messages.php — structured failure events without raw exception messages.
- public/cron/process-outbox.php / public/cron/process-webhook-inbox.php / public/cron/retention.php — structured CLI failure events.
- public/api/session.php — records explicit WhatsApp opt-in and creates the active identity link.
- public/api/webhook.php — verifies, encrypts and durably queues before acknowledging Meta.
- public/cron/process-outbox.php — uses encrypted Embedded Signup credentials when configured.
- public/widget.js / public/widget.css — post-session WhatsApp opt-out control.
- wordpress-plugin/chatbridge-widget/chatbridge-widget.php — version 0.4.0, safe remote health request, private non-autoloaded Meta key storage and server-side AJAX proxy.
- wordpress-plugin/chatbridge-widget/readme.txt — plugin stable version and changelog.
- tests/wordpress-plugin.php — verifies private Meta key storage and settings sanitization.
- .github/workflows/tests.yml — checks admin/customer widget JavaScript syntax.
- tests/run.php / tests/integration.php / tests/wordpress-plugin.php — encryption, PII-safe logging, consent lifecycle and private admin-key regression coverage.
- docs/API.md / docs/WHATSAPP.md / docs/WORDPRESS-PLUGIN.md / README.md — updated contracts and operations.
- supabase/functions/meta-webhook/index.ts — message-body persistence disabled by default.
- supabase/README.md — diagnostic data minimization and RLS boundary documentation.

## Deliberately not included
- No staff accounts, agent inbox or staff dashboard.
- No media/voice-note synchronization.
- Meta token refresh is attempted automatically within the final seven days; operator reconnection is still needed after an unrecoverable expiry.
- No Meta business verification, app review or partner approval is performed by code; those remain Meta-side manual gates.
- The procedural app/whatsapp.php has not been fully decomposed into smaller service classes.
- No live Meta, Hostinger or WordPress production configuration was changed by these repository commits.
