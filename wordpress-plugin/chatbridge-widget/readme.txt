=== ChatBridge Widget ===

Contributors: chatbridge
Tags: chat, whatsapp, customer-support, live-chat
Requires at least: 6.3
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add a lightweight website support chat widget connected to a ChatBridge service. Optional WhatsApp continuation is handled by the connected service.

== Description ==

ChatBridge Widget adds a floating support chat launcher to a WordPress website. It loads the chat interface from a separately hosted ChatBridge service.

The WordPress plugin does not store conversations or hold WhatsApp access tokens. When the ChatBridge service administrator enables Meta Embedded Signup v5, a site administrator can launch the Meta-hosted flow from the plugin settings page. The WordPress server relays the authorization code to ChatBridge using a tenant API key; the service exchanges the code and stores encrypted credentials.

This initial release is a connector plugin. A ChatBridge-compatible service endpoint is required; installing the plugin alone does not create a hosted chatbot service.

Features:
* Simple WordPress settings screen.
* Enable/disable the public widget without editing theme files.
* Configure the HTTPS chat API address, heading and welcome text.
* Run a read-only service health check from WordPress admin.
* No WordPress theme edits and no third-party PHP dependencies.
* Optional Meta Embedded Signup v5 launcher in WordPress admin, gated by the service configuration and tenant API key.
* Read-only WCFM vendor attribution for public products and customer order line items when WCFM Marketplace is active.
* Optional WooCommerce product search for published catalogue-visible products.
* WooCommerce remains optional; the generic widget works on ordinary WordPress sites too.

== Installation ==

1. Upload the `chatbridge-widget` folder to `/wp-content/plugins/`, or install the ZIP through Plugins > Add New > Upload Plugin.
2. Activate **ChatBridge Widget**.
3. Open Settings > ChatBridge Widget.
4. Enter the API base URL provided by your ChatBridge service administrator (for example, `https://chat.example.com/api`).
5. Paste the tenant API key supplied by the service administrator. The key is stored server-side in a non-autoload WordPress option and is not added to public widget JavaScript.
6. Save settings.
7. Select **Test chat connection**. Resolve any reported problem before enabling the widget.
8. Tick **Enable widget** and save settings. If the service has enabled Meta onboarding and its app is approved, the **Connect WhatsApp** control appears in this settings page.

The remote service must allow the exact origin of your WordPress site. Ask the service administrator to configure this. Do not try to fix a CORS error by allowing every website origin.

== Frequently Asked Questions ==

= Does this plugin work without WooCommerce? =

Yes. The generic support widget works without WooCommerce. Product search is optional and is available only when WooCommerce is active.

= Can this plugin connect WhatsApp? =

When the ChatBridge service administrator enables Meta Embedded Signup v5 and provides the required approved Meta app configuration, a WordPress administrator can launch the Meta-hosted signup flow from Settings > ChatBridge Widget. The plugin sends the authorization code to ChatBridge server-to-server. WhatsApp access tokens and app secrets are never exposed to browser JavaScript or stored in the public widget settings.

= What WooCommerce data can the widget show? =

When enabled, it searches published, catalogue-visible products and returns the product name, public SKU, formatted price, product URL, image and coarse stock status. It checks SKU matches as well as partial product-name searches. It does not expose orders, customer data or exact stock quantities, and it does not change cart or checkout.

= Does installing the plugin create an AI chatbot? =

No. The optional AI provider and business-knowledge runtime is configured on the ChatBridge service and is disabled by default. The plugin does not configure AI providers or knowledge sources.

= Does the health check test WhatsApp? =

No. It checks the configured service's PHP API/database/schema health only. It does not send a WhatsApp message or verify Meta credentials.

= What happens if the API URL is empty? =

The public widget is not loaded. The rest of the WordPress website continues to work.

= Does it change my theme or WooCommerce settings? =

No. It uses WordPress's script enqueue system and an independent settings page.

== Privacy ==

The plugin sends a visitor's browser to the configured ChatBridge service to load the widget and communicate with that service. The service's own privacy policy and data-retention rules apply to chat messages. Site owners should disclose the chat service in their privacy notice.

The admin health-check button makes a server-side HTTPS request from WordPress to the configured API's `/health.php` endpoint. The optional Meta onboarding flow uses a tenant API key only in server-side WordPress requests; the API key is not exposed to the public page or widget JavaScript.

== Changelog ==

= 0.4.0 =
* Add a gated Meta Embedded Signup v5 launcher with server-side authorization-code relay.
* Add WCFM vendor attribution to public product search and customer order line items.

= 0.3.0 =
* Add opt-in order lookup for authenticated WordPress customers, with strict order ownership checks.
* Add a widget order lookup panel that is shown only to signed-in customers when enabled.

= 0.2.0 =
* Add optional read-only WooCommerce product search and SKU lookup.
* Add product-specific enquiry handoff and local compatibility diagnostics.

= 0.1.0 =
* Initial connector plugin with settings, safe defaults and remote health check.
