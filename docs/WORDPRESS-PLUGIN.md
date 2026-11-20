# ChatBridge WordPress Plugin

## Purpose

The `wordpress-plugin/chatbridge-widget/` directory contains the first installable WordPress connector. It is deliberately independent of WooCommerce, Mobex and WCFM so the same plugin can be installed on ordinary WordPress sites.

## Current release boundary

This is a **connector**, not a self-hosted WhatsApp chatbot product. It loads the existing remote widget JavaScript from a configured ChatBridge service and passes the API base URL and display text as script attributes.

The connected service currently provides guest website chat and optional WhatsApp continuation after explicit opt-in and server-side Meta configuration. Staff replies remain human replies. There is no AI auto-responder in this release.

## Install

1. Zip the `wordpress-plugin/chatbridge-widget/` directory so the ZIP contains `chatbridge-widget/chatbridge-widget.php` at its root.
2. In WordPress, open **Plugins → Add New → Upload Plugin**, upload the ZIP and activate it.
3. Open **Settings → ChatBridge Widget**.
4. Enter the HTTPS API base URL, for example `https://chat.example.com/api`.
5. Save and run **Test chat connection**.
6. Enable the widget only after the connection test passes.

The remote service must allow the exact WordPress origin in its CORS allowlist. The plugin never changes that server-side policy.

## Configuration and diagnostics

The plugin stores only:
- Whether the widget is enabled.
- The remote API base URL.
- The widget heading and welcome text.

It does not store WhatsApp access tokens, Meta app secrets, customer conversations or WooCommerce credentials. The health check calls the remote `/health.php` endpoint server-to-server and reports only a generic service health state.

If the endpoint is empty, invalid or not HTTPS, the plugin fails closed and does not inject the public widget.

## Security / compatibility notes

- Admin settings require `manage_options` and use the WordPress Settings API nonce.
- The health-check action uses a WordPress nonce, an 8-second timeout and TLS certificate verification.
- The frontend uses `wp_enqueue_script`; no theme file edits are needed.
- No WooCommerce, WCFM, Paystack or Mobex APIs are called.
- The plugin does not create a chat backend or make the service public automatically.

## Before public distribution

This code is an initial internal connector. Before submitting it to the WordPress Plugin Directory or advertising broad compatibility, complete:
- A real WordPress test matrix across supported WordPress/PHP versions.
- Static analysis and WordPress Coding Standards.
- Plugin Check and security review.
- Tested install, update, deactivate, multisite and uninstall behavior.
- Privacy policy and support URLs.
- A stable service onboarding, account provisioning and abuse-control model.
- Clear compatibility documentation for each ChatBridge service version.
