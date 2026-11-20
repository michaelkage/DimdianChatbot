# Dimdian Store Integration Notes

## Confirmed storefront stack

The existing Dimdian storefront uses:

- WordPress
- Mobex Child Theme
- WooCommerce
- Paystack for WooCommerce payments
- WCFM Multi-vendor Marketplace

This chat repository is a separate service. The WordPress stack is the source of truth for products, orders, stock, vendor ownership, checkout and payment status.

## What currently integrates

The active widget is a small, dependency-free JavaScript/CSS embed. It can be loaded on WordPress pages using the site-wide footer/custom-code area. It does not require editing the Mobex Child Theme source.

The PHP chat service stores guest sessions, conversations and messages in its own MySQL database. It does not share the WordPress database.

The WhatsApp adapter is a separate server-side transport. It is designed to send an approved WhatsApp template after explicit customer opt-in and to receive supported WhatsApp webhook events. Live Business App Coexistence remains a separate Meta eligibility and operational gate.

## What does not currently integrate

The active code does not currently:

- Query WooCommerce products, SKUs, stock or product categories.
- Read WooCommerce orders or payment state.
- Read WCFM vendor profiles, commissions, store ownership or vendor dashboards.
- Query Paystack transactions or use Paystack credentials.
- Provide product recommendations, order tracking, fitment or MPN lookup.

There is older WooCommerce lookup code and a WordPress MPN route under `archive/legacy-marketplace-support/`. That code is archived reference material, not part of the active runtime. It must not be copied into production without a fresh security and compatibility review.

## Recommended safe integration boundary

If store-aware chat is added later:

1. Keep WordPress/WooCommerce as the authoritative source of commerce data.
2. Add a small WordPress plugin or carefully scoped REST endpoints for the chat to read only the fields it needs.
3. Use a dedicated read-only WooCommerce REST API key where appropriate; never put consumer secrets in browser JavaScript.
4. Treat order lookup as private customer data. Require a safe order-ownership check before returning order details. A phone number typed into chat is not proof of identity.
5. Respect WCFM vendor ownership. A vendor should only see their own products/orders through the existing WCFM permissions; the public chat must not expose vendor-private information.
6. Treat WooCommerce order/payment status as the source of truth for checkout. Do not independently infer payment success from a browser redirect or a Paystack reference supplied by a visitor.
7. Do not store Paystack secret keys in the chat service unless a separately reviewed server-to-server payment use case truly requires them.
8. Keep commerce reads optional: if the store API is temporarily unavailable, ordinary customer enquiries should still work.

## WordPress embed

After the PHP chat backend is deployed and tested, add the following once using the site's approved site-wide footer/custom-code method:

```html
<script
  src="https://chat.dimdian.com/widget.js"
  data-api-base="https://chat.dimdian.com/api"
  data-title="Chat with Dimdian"
  data-subtitle="Send us a message. Our team will reply as soon as we can."
  defer
></script>
```

Do not add the script to every page manually if the chosen footer method already injects it globally. Avoid editing WordPress core, Mobex parent theme files or generated theme files.

## Deployment boundary

The GitHub Pages workflow deploys only `docs/` as a public demo. It does not deploy the PHP backend to Hostinger and does not modify WordPress. The intended production API is `chat.dimdian.com`, hosted separately with its own database and private `config.php`.

Before enabling the embed on the live store, the technical maintainer must verify the production API, database schema, exact allowed website origins, backups and the website-only support process. WhatsApp should remain disabled until the separate Meta launch gate is completed.
