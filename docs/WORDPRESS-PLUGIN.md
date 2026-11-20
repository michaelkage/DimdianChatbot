# ChatBridge WordPress Plugin

## Purpose

The `wordpress-plugin/chatbridge-widget/` directory contains the installable WordPress connector and an optional, read-only WooCommerce catalogue adapter. It is deliberately independent of WooCommerce, Mobex and WCFM so the same plugin can be installed on ordinary WordPress sites.

## Current release boundary

This is a **connector**, not a self-hosted WhatsApp chatbot product. It loads the existing remote widget JavaScript from a configured ChatBridge service and passes the API base URL and display text as script attributes.

The connected service provides guest website chat, optional WhatsApp integration and (on the feature branch) a tenant-scoped AI runtime disabled by default. The plugin includes an optional Meta Embedded Signup v5 launcher for eligible Meta Tech Providers/Solution Partners. Staff replies remain human replies unless a separately configured AI knowledge system generates a reply. When enabled, the WooCommerce adapter searches published, catalogue-visible products directly on the same WordPress site and can attribute products/order line items to WCFM vendors.

## Requirements

- WordPress 6.3 or newer.
- PHP 7.4 or newer for the WordPress plugin itself.
- WooCommerce is optional; enable its product search only when WooCommerce is active.
- The separately hosted ChatBridge PHP service still requires PHP 8.2 or newer.

The WordPress plugin and remote chat service run in different PHP environments. The plugin's lower PHP requirement does not change the remote service's PHP requirement.

## Install

1. Zip the `wordpress-plugin/chatbridge-widget/` directory so the ZIP contains `chatbridge-widget/chatbridge-widget.php` at its root.
2. In WordPress, open **Plugins → Add New → Upload Plugin**, upload the ZIP and activate it.
3. Open **Settings → ChatBridge Widget**.
4. Enter the HTTPS API base URL, for example `https://chat.example.com/api`.
5. Paste the tenant API key supplied by the service operator. The key is stored server-side in a separate non-autoload option and is never passed to the public widget.
6. Save and run **Test chat connection**.
7. Enable the widget only after the connection test passes. If Meta onboarding is enabled on the service, the page also shows **Connect WhatsApp**.

The remote service must have a verified `tenant_sites` mapping for the exact WordPress origin. The plugin never changes that server-side policy.

## Configuration and diagnostics

The plugin stores:
- Whether the widget and optional WooCommerce features are enabled.
- The remote API base URL.
- The widget heading and welcome text.
- A tenant API key in a separate non-autoload WordPress option for server-to-server admin requests.

It does not store WhatsApp access tokens, Meta app secrets, customer conversations or WooCommerce credentials. The tenant API key is never included in the widget script attributes or public REST response. The health check calls the remote `/health.php` endpoint server-to-server and reports only a generic service health state.

If the endpoint is empty, invalid or not HTTPS, the plugin fails closed and does not inject the public widget.

## Security / compatibility notes

- Admin settings require `manage_options` and use the WordPress Settings API nonce.
- The health-check action uses a WordPress nonce, an 8-second timeout and TLS certificate verification.
- The frontend uses `wp_enqueue_script`; no theme file edits are needed.
- WooCommerce is optional. Its adapter exposes only published, catalogue-visible product name, public SKU, formatted price, product URL, image and coarse stock status. SKU matches are retrieved with WooCommerce's product query API; partial product-name searches use the WooCommerce Store API. It does not expose customer/order data or exact inventory counts.
- Product search is read-only, same-origin and rate-limited; it does not alter cart, checkout, stock, prices or orders.
- WCFM vendor attribution uses WCFM's in-process vendor lookup functions only; it does not call vendor-private REST endpoints or grant vendor access to customer orders.
- Paystack and Mobex APIs are not called.
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

## WooCommerce product search (0.2.0)

1. Confirm WooCommerce is installed and active on the same WordPress site.
2. Open **Settings → ChatBridge Widget**.
3. Enable **WooCommerce product search** and save.
4. Enable the chat widget and open the public site.
5. Select **Find a product**, search for a product name or keyword, and inspect the result.
6. Select **Ask about this** to place a product-specific question in the chat composer, or **View product** to open the product page.

The adapter registers the public, read-only REST route `/wp-json/chatbridge/v1/products?search=...`. It returns a maximum of eight published products and excludes products with catalogue visibility set to hidden. The endpoint is disabled unless the administrator enables the integration, even if someone calls the REST URL directly. Search results are cached briefly and the endpoint applies a modest per-IP request limit. Only public catalogue information is returned.

**Order questions are not enabled yet.** Order status, customer addresses, email addresses and other private order data must never be exposed through a public product-search endpoint. A future order lookup must use an ownership-verification flow and server-side WooCommerce permissions.

This adapter runs on the WordPress site itself. The remote chat backend does not need direct database access or WooCommerce API keys. It is deliberately compatible with a store that also uses Paystack and WCFM, but it does not yet read payment-provider state or vendor-private data.


## WooCommerce order lookup (0.3.0)

Order lookup is an opt-in setting. It is intentionally separate from public product search.

- Enable **Customer order lookup (logged-in customers only)** under **Settings → ChatBridge Widget**.
- The order lookup action is passed to the browser only when the current visitor is signed in.
- The browser calls the same-site WordPress REST route with the REST nonce and same-origin cookies.
- The endpoint verifies that the requested order belongs to the currently authenticated WordPress user.
- The response is restricted to order number, status, creation date, currency, formatted total and up to 20 item names/quantities.

This first increment does not support guest checkout, email-only verification, order-key lookup, sequential/custom order-number plugins, vendor dashboards or Paystack transaction details. It does not expose addresses, email, payment tokens, notes or private metadata. If the visitor is not signed in, the order lookup control is not offered by the widget.

The order lookup endpoint accepts a WooCommerce order ID. On a default WooCommerce installation this normally matches the displayed order number; custom order-number extensions require a dedicated compatibility adapter.


## Meta Embedded Signup v5 (0.4.0)

The settings page shows existing tenant WhatsApp connection and Coexistence sync status when the tenant API key is configured. The **Connect WhatsApp** control appears only when the remote service returns a valid public Meta app/configuration pair for the tenant API key. The flow is deliberately split:

1. The WordPress administrator launches Meta's Facebook Login for Business dialog from the plugin settings page.
2. The JavaScript SDK returns an authorization code; the WA_EMBEDDED_SIGNUP message event supplies v5 session info (messaging_account_ids, primary_waac_ids, is_on_biz_app).
3. WordPress sends the code and session info to its own admin-ajax.php endpoint. The tenant API key remains server-side.
4. WordPress relays the payload to the ChatBridge service over HTTPS.
5. ChatBridge exchanges the code, resolves Messaging Account/PMA and WhatsApp Account/WAAC IDs, discovers and verifies phone numbers through Graph API, subscribes the app to the Messaging Account and stores encrypted credentials under the authenticated tenant.

The service must be configured with an approved Meta app, current stable Embedded Signup configuration ID, Graph API version, App Secret and (where required) Partner Solution ID. The platform must meet Meta's Tech Provider/Solution Partner and App Review requirements. The plugin does not bypass those requirements.

Do not run this flow on the live Dimdian site until a separate staging WordPress installation and test Meta business account have passed the complete signup/webhook round trip. The public GitHub Pages demo cannot run the WordPress admin connector.

## WCFM vendor attribution (0.4.0)

When WCFM Marketplace is active, each public product result and each item in the authenticated customer's own order summary can include the WCFM store name. The adapter uses wcfm_get_vendor_id_by_post() and wcfm_get_vendor_store() when available. If those functions are unavailable, the plugin falls back to ordinary WooCommerce output.

This does not add vendor dashboard access, vendor order filtering, commission details, payouts, or vendor-scoped customer PII. The order endpoint continues to require that the signed-in WordPress user owns the parent WooCommerce order.
