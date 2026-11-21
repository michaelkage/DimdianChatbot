# ChatBridge WordPress Plugin

## Purpose

The `wordpress-plugin/chatbridge-widget/` directory contains the installable WordPress connector and an optional, read-only WooCommerce catalogue adapter. It is deliberately independent of WooCommerce, Mobex and WCFM so the same plugin can be installed on ordinary WordPress sites.

## Current release boundary

This is a **connector**, not a self-hosted WhatsApp chatbot product. It loads the existing remote widget JavaScript from a configured ChatBridge service and passes the API base URL and display text as script attributes.

The connected service currently provides guest website chat and optional WhatsApp continuation after explicit opt-in and server-side Meta configuration. Staff replies remain human replies. There is no AI auto-responder in this release. When enabled, the optional WooCommerce adapter searches published, catalogue-visible products directly on the same WordPress site.

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
- WooCommerce is optional. Its adapter exposes only published, catalogue-visible product name, public SKU, formatted price, product URL, image and coarse stock status. SKU matches are retrieved with WooCommerce's product query API; partial product-name searches use the WooCommerce Store API. It does not expose customer/order data or exact inventory counts.
- Product search is read-only, same-origin and rate-limited; it does not alter cart, checkout, stock, prices or orders.
- WCFM, Paystack and Mobex APIs are not called.
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


## Meta Embedded Signup v4 (administrator only)

Plugin version 0.4.0 adds an operator-only Embedded Signup section to Settings → ChatBridge Widget. Save the PHP service's private Meta admin API key and the Partner Solution ID, then use **Connect WhatsApp Business account**. The plugin requests a one-time state from the PHP service, loads the Meta JavaScript SDK, and calls the backend through authenticated WordPress AJAX handlers. The admin key stays in a separate non-autoloaded WordPress option and is never sent to the browser.

Meta returns the authorization code and a WhatsApp signup completion event. The plugin forwards the code, WABA ID and Phone Number ID to the PHP service over HTTPS. If Meta does not include an identifier, an administrator can enter it in the two manual ID fields. The backend exchanges the code, stores the access token encrypted and subscribes the WABA to webhooks. If subscription fails, use **Retry webhook subscription** after checking Meta app permissions.

This screen is available only to WordPress users with manage_options and uses a WordPress nonce. Do not expose the admin key in public pages, widget attributes or browser JavaScript. The real Meta app must already have an Embedded Signup v4 configuration and the required Tech Provider/Solution Partner approval. The UI does not bypass Meta eligibility or App Review requirements.


## Optional staff inbox

The WordPress connector includes a separate **ChatBridge Inbox** admin page. It is disabled by default and is independent of the visitor-facing widget. When deliberately enabled, authorized WordPress users can view conversations, assign or self-assign work, add private internal notes and send website-only replies. Internal notes are stored separately from customer messages. Replies from this inbox do not automatically go to WhatsApp.

### Enablement

1. Import `database/optional/staff-inbox.sql` into the ChatBridge service database. Do not import it for a WhatsApp-only installation that does not need the inbox.
2. Set `staff_inbox.enabled = true` in the private PHP service configuration and configure a random shared `staff_inbox.assertion_secret` of at least 32 characters.
3. In WordPress **Settings → ChatBridge Widget**, enable the staff inbox and enter the exact same shared assertion secret. The secret is used only by the server-side WordPress proxy; it is never localized into browser JavaScript.
4. Sign in to WordPress as the intended initial administrator and open **ChatBridge Inbox** once. This creates the local staff identity with the default `agent` role.
5. On the ChatBridge server, promote that identity to owner using its WordPress user ID (visible in the WordPress user-edit URL):

   ```sh
   php bin/set-staff-role.php wordpress user:123 owner
   ```

   Replace `123` with the actual WordPress user ID. The script is CLI-only and only updates an already-provisioned active identity. It does not create an identity or accept a browser-supplied role.
6. Sign in to the inbox again. The owner can view all conversations and assign work. Other identities are agents by default; operators can provision `admin` or `agent` roles with the same CLI command.

The WordPress AJAX proxy checks the current WordPress login, capability and nonce, then signs the exact HTTP method, request target (including query string), request-body digest and a one-time nonce. The ChatBridge service verifies that assertion and applies its own local role and assignment policy. Use HTTPS end-to-end and keep both server clocks synchronized. The optional schema is currently for one business per database; it is not a shared multi-tenant database design.

To disable the feature, set `staff_inbox.enabled = false` on the service and disable the WordPress setting. Disabling it stops API access but does not delete staff records or conversation history. Retention cleanup removes staff notes, assignments, message-author links, audit rows and expired auth nonces for old closed conversations when the module is enabled.

This is the core staff-inbox workflow, not a full helpdesk: conversation search, in-product role administration, export tooling and multi-tenant shared-database isolation are not included yet.
