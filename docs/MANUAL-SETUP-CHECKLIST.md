# Manual production setup checklist

This is the operator runbook for deploying the hardened branch. Complete it in staging first. Do not enable WhatsApp outbound until the final launch gate is passed.

## 1. Hosting and runtime

- [ ] Create the dedicated API host (planned: chat.dimdian.com) and enable HTTPS.
- [ ] Point its document root to the repository's public/ directory. Keep app/, database/, docs/, tests/ and config.php outside the web root.
- [ ] Use PHP 8.2 or newer for the backend.
- [ ] Enable PDO, PDO MySQL and OpenSSL.
- [ ] Use MySQL 8.0+ or a compatible MariaDB release.
- [ ] Confirm PHP CLI is available to Hostinger cron.
- [ ] Create a dedicated MySQL database and database user. Do not reuse WordPress credentials.

## 2. Database

For a fresh install:
- [ ] Import database/schema.sql once into an empty database.
- [ ] Do not run migrations 002, 003 or 004 on top of the fresh schema.

For an existing install:
- [ ] Take and verify a full database backup.
- [ ] Confirm which schema revision is installed.
- [ ] Apply missing migrations in order: 002_message_sequence.sql, 003_whatsapp_outbox.sql, then 004_production_hardening.sql.
- [ ] Confirm migration 004 backfilled legacy opt-in timestamps and one deterministic active identity link per opted-in phone.
- [ ] Open /api/health.php and require status=healthy with no missing tables.

Never run the existing-install migration sequence against a fresh schema.

## 3. Private configuration

- [ ] Copy config.example.php to config.php in the private project root.
- [ ] Set app.app_key to a newly generated random value of at least 32 characters. Example: openssl rand -hex 32.
- [ ] Set app.timezone to Africa/Lagos.
- [ ] Set app.public_base_url to the HTTPS API host.
- [ ] Set app.allowed_origins to the exact WordPress site origins, without paths or trailing slashes.
- [ ] Set database.dsn, database.username and database.password for the dedicated chat database.
- [ ] Set privacy retention periods only after the owner approves them.
- [ ] Keep whatsapp.outbound_enabled=false for initial deployment.
- [ ] Keep Meta access tokens and app secrets private. Never commit config.php.

The same values can be supplied through the documented DIMDIAN_* environment variables; environment values override config.php. The supported names are listed in docs/PRODUCTION-HARDENING.md.

Important: app.app_key derives the encryption keys for webhook payloads and Meta tokens. Rotating it makes existing encrypted inbox rows and Meta connection tokens unreadable. Drain the webhook inbox and reconnect Meta before a planned rotation.

## 4. WordPress connector

- [ ] Install wordpress-plugin/chatbridge-widget/chatbridge-widget.php as the ChatBridge Widget plugin.
- [ ] Activate the plugin.
- [ ] Open Settings → ChatBridge Widget.
- [ ] Set the HTTPS API base URL, for example https://chat.dimdian.com/api.
- [ ] Configure the widget heading and subtitle.
- [ ] Enable the widget only after the API health check is healthy.
- [ ] Keep WooCommerce product search disabled unless the owner wants the read-only public catalogue adapter.
- [ ] Use the plugin's connection check. It confirms the API/database/schema only, not WhatsApp delivery.
- [ ] Test the widget on the real WordPress site on desktop and mobile.
- [ ] Confirm the exact browser Origin is present in app.allowed_origins.

## 5. Meta app and Embedded Signup v4

- [ ] Use a Meta Developer App with WhatsApp product and Facebook Login for Business.
- [ ] Create a WhatsApp Embedded Signup v4 configuration. Do not use retired v2/v3 launch snippets.
- [ ] Complete the Tech Provider/Solution Partner prerequisites, business verification and required Meta app review/permissions for the actual integration.
- [ ] Select WhatsApp Cloud API and the required WhatsApp Business assets and permissions.
- [ ] Enable HTTPS, JavaScript SDK login and the required OAuth settings in Meta.
- [ ] Add the real operator page domain to Meta's allowed JavaScript SDK domains and OAuth redirect allowlist.
- [ ] Configure meta.app_id and meta.config_id in private config.
- [ ] Configure whatsapp.app_secret, whatsapp.graph_api_version and whatsapp.verify_token.
- [ ] Generate meta.admin_api_key as a separate random secret of at least 32 characters.
- [ ] Build or configure a secure server-side operator proxy. It must authenticate the operator and call /api/meta-embedded-signup.php with X-DIMDIAN-ADMIN-KEY. Never place this key in JavaScript, a WordPress option exposed to visitors, or a public page.
- [ ] Call action=begin, launch the Meta SDK with the returned config_id and state, and receive the authorization code plus WABA/phone-number identifiers.
- [ ] Immediately call action=exchange through the server-side proxy. The code is short-lived and should not be queued for later.
- [ ] Confirm the response reports connected=true and webhook_subscribed=true.
- [ ] If webhook_subscribed=false, inspect Meta permissions and retry action=subscribe through the secure proxy.
- [ ] Confirm the resulting Meta connection appears active by running the outbox worker in a controlled test.
- [ ] Test token refresh before expiry in a non-production environment where possible.

The repository provides the protected backend begin/exchange/subscribe actions; it does not provide the operator-facing admin UI or proxy.

## 6. Meta webhook configuration

- [ ] Set the callback URL to https://YOUR-API-HOST/api/webhook.php.
- [ ] Set the verify token to the exact private whatsapp.verify_token value.
- [ ] Complete the GET verification handshake in Meta.
- [ ] Subscribe to messages and smb_message_echoes where supported by the connected Coexistence flow.
- [ ] Confirm WABA app subscription succeeds after Embedded Signup.
- [ ] Send a test webhook and confirm a row appears in webhook_inbox before processing.
- [ ] Confirm processed webhook events appear in webhook_events.
- [ ] Confirm duplicate deliveries do not duplicate messages.
- [ ] Confirm invalid X-Hub-Signature-256 requests are rejected.
- [ ] Keep the Supabase demo webhook URL separate; production Meta must call the PHP API webhook URL.

## 7. WhatsApp behavior tests

Use a dedicated eligible test number first. Do not experiment on the live Dimdian number.

- [ ] Website-only session with no phone number.
- [ ] Website-only session with a phone number but no WhatsApp opt-in.
- [ ] Explicit opt-in creates a consent ledger row and one active identity link.
- [ ] Website message is persisted even while outbound is disabled.
- [ ] Approve a WhatsApp template with exactly one BODY text parameter {{1}} and matching name/language in config.
- [ ] Enable outbound only in the test environment and run process-outbox.php.
- [ ] Confirm the template reaches the opted-in visitor and is visible in the intended Business app workflow.
- [ ] Send a Business App text reply and confirm an smb_message_echoes webhook arrives.
- [ ] Confirm the reply appears in the matching website conversation, not another conversation using a similar/old number.
- [ ] Confirm inbound WhatsApp text appears in the correct active conversation.
- [ ] Confirm sent/delivered/read/failed callbacks update the visitor message status.
- [ ] Confirm a duplicate webhook does not duplicate the chat message.
- [ ] Withdraw consent in the widget; confirm the opt-out ledger row, disabled identity link and cancellation of pending sends.
- [ ] Confirm website chat remains usable after WhatsApp opt-out.
- [ ] Confirm a revoked phone route no longer receives incoming webhook messages in the old conversation.

Do not infer Coexistence eligibility from the +234 country code. Verify the exact business number with Meta and confirm real Business App echo delivery.

## 8. Scheduled CLI jobs

Use the exact PHP CLI binary and absolute project path reported by Hostinger. Do not expose these scripts as browser endpoints.

- [ ] Schedule public/cron/process-webhook-inbox.php every minute.
- [ ] Schedule public/cron/process-outbox.php every minute after WhatsApp outbound is approved and enabled.
- [ ] Schedule public/cron/retention.php daily during a quiet period.
- [ ] Run each script manually once over SSH and inspect its JSON summary.
- [ ] Confirm non-CLI HTTP requests to the cron scripts return 404.
- [ ] Confirm cron logs do not contain secrets or message bodies.

## 9. Privacy, backups and operations

- [ ] Publish an accurate privacy notice describing guest chat, WhatsApp opt-in, consent withdrawal and retention.
- [ ] Approve closed-conversation, webhook-event and expired-session retention periods with the business owner.
- [ ] Restrict Hostinger, Meta Business and database access to trusted operators.
- [ ] Back up the MySQL database before each migration and on a regular schedule.
- [ ] Store config.php and secrets in a restricted password manager or equivalent secure backup.
- [ ] Test a database restore into a separate staging database.
- [ ] Monitor /api/health.php queue counts and the private PHP error log.
- [ ] Agree who monitors the WhatsApp Business app and when.
- [ ] Keep outbound disabled until the owner signs off every WhatsApp launch gate.

## 10. Regression test commands

Run from the repository root in a configured disposable environment:

```sh
find app public tests wordpress-plugin -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php -l config.example.php
php tests/run.php
php tests/integration.php
php tests/wordpress-plugin.php
node --test tests/widget.test.mjs
```

The integration suite requires MySQL, a disposable database created from database/schema.sql, a private config.php and a local PHP server. GitHub Actions configures these automatically for the repository test workflow.
