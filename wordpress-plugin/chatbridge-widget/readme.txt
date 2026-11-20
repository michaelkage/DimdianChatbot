=== ChatBridge Widget ===
Contributors: chatbridge
Tags: chat, whatsapp, customer-support, live-chat
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add a lightweight website support chat widget connected to a ChatBridge service. Optional WhatsApp continuation is handled by the connected service.

== Description ==

ChatBridge Widget adds a floating support chat launcher to a WordPress website. It loads the chat interface from a separately hosted ChatBridge service.

The WordPress plugin does not store conversations, connect directly to Meta, or hold WhatsApp access tokens. Those responsibilities remain with the configured ChatBridge service.

This initial release is a connector plugin. A ChatBridge-compatible service endpoint is required; installing the plugin alone does not create a hosted chatbot service.

Features:
* Simple WordPress settings screen.
* Enable/disable the public widget without editing theme files.
* Configure the HTTPS chat API address, heading and welcome text.
* Run a read-only service health check from WordPress admin.
* No WordPress theme edits and no third-party PHP dependencies.
* Optional WooCommerce product search for published catalogue-visible products.\n* WooCommerce remains optional; the generic widget works on ordinary WordPress sites too.

== Installation ==

1. Upload the `chatbridge-widget` folder to `/wp-content/plugins/`, or install the ZIP through Plugins > Add New > Upload Plugin.
2. Activate **ChatBridge Widget**.
3. Open Settings > ChatBridge Widget.
4. Enter the API base URL provided by your ChatBridge service administrator (for example, `https://chat.example.com/api`).
5. Save settings.
6. Select **Test chat connection**. Resolve any reported problem before enabling the widget.
7. Tick **Enable widget** and save settings.

The remote service must allow the exact origin of your WordPress site. Ask the service administrator to configure this. Do not try to fix a CORS error by allowing every website origin.

== Frequently Asked Questions ==

= Does this plugin work without WooCommerce? =

Yes. The plugin only injects the support widget. WooCommerce integration is optional and belongs in a separate service integration.

= Does this plugin itself connect to WhatsApp? =

No. It connects the browser widget to a ChatBridge service. WhatsApp credentials and message handling belong on the server, never in WordPress page source or browser JavaScript.

= What WooCommerce data can the widget show? =\n\nWhen enabled, it searches published, catalogue-visible products and returns the product name, formatted price, product URL, image and coarse stock status. It does not expose orders, customer data or exact stock quantities, and it does not change cart or checkout.\n\n= Does installing the plugin create an AI chatbot? =

No. The initial ChatBridge service supports customer enquiries and human support replies. Automated AI responses are not included in this release.

= Does the health check test WhatsApp? =

No. It checks the configured service's PHP API/database/schema health only. It does not send a WhatsApp message or verify Meta credentials.

= What happens if the API URL is empty? =

The public widget is not loaded. The rest of the WordPress website continues to work.

= Does it change my theme or WooCommerce settings? =

No. It uses WordPress's script enqueue system and an independent settings page.

== Privacy ==

The plugin sends a visitor's browser to the configured ChatBridge service to load the widget and communicate with that service. The service's own privacy policy and data-retention rules apply to chat messages. Site owners should disclose the chat service in their privacy notice.

The admin health-check button makes a server-side HTTPS request from WordPress to the configured API's `/health.php` endpoint. It sends no WhatsApp token or customer conversation data.

== Changelog ==

= 0.1.0 =
* Initial connector plugin with settings, safe defaults and remote health check.
