# Dimdian Chat — Owner's Quick Fix Guide

This guide is for the person running the Dimdian store. You do not need to understand PHP or edit code to use it.

## First: know which system is having trouble

Dimdian has two separate systems:

- **The store:** WordPress, Mobex Child Theme, WooCommerce, Paystack and WCFM. This controls the website, products, vendors, checkout and payments.
- **The chat service:** a separate PHP/MySQL application intended for `chat.dimdian.com`. It handles website chat messages.
- **The public demo:** GitHub Pages. It is only a preview and does not save real customer messages.
- **WhatsApp connection monitoring:** a separate Supabase diagnostic tool. It is not the customer chat inbox.

A problem with chat should not require switching off WooCommerce, Paystack, WCFM or the Mobex theme.

## The 3 checks to try first

Only do these checks after the chat service has been deployed to Hostinger.

1. Open the chat health check: [https://chat.dimdian.com/api/health.php](https://chat.dimdian.com/api/health.php).
2. If it says **healthy**, the chat PHP service can reach its database and the required tables exist. This does not prove that WhatsApp or the WordPress embed is working.
3. Open the main store in a private/incognito browser window and refresh it. If the store works but chat does not, the issue is probably limited to the chat embed or chat service.

If the health page is unavailable, shows **degraded**, or returns an error, take a screenshot of the result and send it to the technical maintainer. Do not send passwords, API keys or secret values.

> The health URL is not live until the PHP backend is installed on Hostinger. The GitHub Pages demo is not the production chat service.

## Common problems

| What you see | What it usually means | What to do |
|---|---|---|
| The store itself will not open | WordPress, hosting, DNS or theme/plugin issue | Contact the website host/WordPress maintainer. Do not change chat settings first. |
| Store works, but the chat button is missing | The widget script may be missing, blocked or cached | Ask the website maintainer to confirm the chat script is installed once in the site-wide footer. Clear the WordPress/cache plugin and browser cache only if the maintainer recommends it. |
| Chat opens but cannot start a conversation | Chat API, configuration or database connection problem | Open the health URL above and send the result to the maintainer. |
| Chat says the message was saved but nobody receives it | WhatsApp delivery is disabled, not configured, or not enabled for that visitor | This is not proof that WhatsApp sent anything. Contact the maintainer; do not ask customers to resend sensitive details. |
| Customer receives a WhatsApp message, but staff reply does not appear in website chat | Meta webhook, Coexistence or webhook event problem | The maintainer should inspect webhook delivery and the private diagnostics page. |
| Customer sees “session expired” | Their temporary chat session has expired | Ask them to start a new chat. Their old browser session may no longer be usable. |
| Chat works on one device but not another | Browser cache, content blocker, network or site-origin configuration | Try a private browser window and another network. Send the device/browser details to the maintainer. |

## What to send when reporting a fault

Please send:

- A screenshot of the problem (hide customer phone numbers and message contents).
- The page address where it happened.
- The approximate time and date.
- Whether the main store still works.
- The exact error text, if one appears.
- The result of the health check, if available.

Never send a customer's full conversation, database export, WordPress password, Hostinger password, Paystack secret key, Meta access token, webhook verify token or Supabase key in an ordinary WhatsApp message.

## Things not to do

- Do not delete or reinstall WordPress, WooCommerce, WCFM or Paystack to fix a chat-only issue.
- Do not edit theme PHP files or WordPress core files.
- Do not delete chat database tables or import the database schema again as a troubleshooting experiment.
- Do not switch on WhatsApp outbound sending just to see whether it works.
- Do not disconnect, migrate or re-register the existing WhatsApp Business number without the business owner's explicit approval and a tested recovery plan.
- Do not share private configuration files or API keys with anyone who is not authorised.

## Who owns what?

| Area | Responsible system |
|---|---|
| Website layout and theme | WordPress + Mobex Child Theme |
| Products, stock and orders | WooCommerce |
| Marketplace vendors | WCFM |
| Checkout and payment processing | WooCommerce + Paystack |
| Website chat messages | Separate PHP + MySQL chat service |
| WhatsApp message delivery and replies | Meta WhatsApp Business Platform, subject to supported Coexistence |
| Demo and code history | GitHub Pages + GitHub repository |
| Webhook event diagnostics | Supabase |

The chat project does not currently read WooCommerce products/orders, WCFM vendor records or Paystack transactions. A chat fault should not be treated as a store-payment fault.

## Escalation

If the first checks do not solve it, contact the technical maintainer and provide the fault report above. The maintainer should check the Hostinger PHP error log and chat database connection. Check GitHub Pages deployment status only when the public demo is the part that failed; GitHub Actions does not deploy the PHP backend to Hostinger. For WhatsApp-only issues, inspect Meta webhook diagnostics.

**Keep the store running. Isolate the fault before changing anything.**
