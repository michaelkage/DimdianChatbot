# File-change manifest — production hardening

## New files
- app/consent.php — append-only consent audit and opt-in/opt-out lifecycle.
- app/webhook_inbox.php — encrypted durable webhook inbox, deduplication and retry worker.
- app/meta_signup.php — admin-protected Embedded Signup state, token exchange and encrypted token storage.
- public/api/consent.php — guest-authenticated consent preference endpoint.
- public/api/meta-embedded-signup.php — admin-only Meta onboarding endpoint.
- public/cron/process-webhook-inbox.php — CLI webhook recovery worker.
- public/cron/retention.php — CLI data-retention job.
- database/migrations/004_production_hardening.sql — existing-database upgrade.
- docs/PRODUCTION-HARDENING.md — deployment and operations guide.
- docs/MILESTONE-STATUS.md — milestone status and production acceptance gates.

## Updated files
- app/bootstrap.php — private environment-variable overrides.
- app/whatsapp.php — exact active identity-link correlation instead of latest-open-conversation matching.
- config.example.php — privacy, Meta onboarding and message-rate configuration.
- database/schema.sql — fresh-install schema for all hardening tables.
- public/api/health.php — checks the new tables, OpenSSL/PDO MySQL runtime and queue counts.
- public/api/session.php — records explicit WhatsApp opt-in and creates the active identity link.
- public/api/webhook.php — verifies, encrypts and durably queues before acknowledging Meta.
- public/cron/process-outbox.php — uses encrypted Embedded Signup credentials when configured.
- public/widget.js / public/widget.css — post-session WhatsApp opt-out control.
- wordpress-plugin/chatbridge-widget/chatbridge-widget.php — version 0.3.0 and safe remote health request.
- wordpress-plugin/chatbridge-widget/readme.txt — plugin stable version.
- tests/run.php / tests/integration.php — encryption and consent lifecycle regression coverage.
- docs/API.md / docs/WHATSAPP.md / README.md — updated contracts and operations.
- supabase/functions/meta-webhook/index.ts — message-body persistence disabled by default.
- supabase/README.md — diagnostic data minimization and RLS boundary documentation.

## Deliberately not included
- No staff accounts, agent inbox or staff dashboard.
- No media/voice-note synchronization.
- Meta token refresh is attempted automatically within the final seven days; operator reconnection is still needed after an unrecoverable expiry.
- No operator-facing Embedded Signup UI; a secure server-side admin proxy remains required.
- The procedural app/whatsapp.php has not been fully decomposed into smaller service classes.
- No live Meta, Hostinger or WordPress production configuration was changed by these repository commits.
