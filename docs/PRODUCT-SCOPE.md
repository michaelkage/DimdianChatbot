# Product Scope

## Product definition

ChatBridge is a modular, platform-independent customer communication and support platform. It provides an embeddable website chat widget, a backend API, interchangeable storage and deployment implementations, optional communication channels, optional AI capabilities, and platform-specific business integrations.

WordPress and WooCommerce are the first major distribution and commerce integrations. They are not the product boundary. The first Dimdian deployment and GitHub Pages demo are product pilots, not restrictions on how other businesses must deploy ChatBridge.

## Customer experience

- A business can embed the widget on a supported website or application.
- A visitor can start as a guest without creating an account.
- The widget communicates with a configured ChatBridge API endpoint.
- The business can configure branding, welcome text and supported widget behaviour.
- Website chat does not require WhatsApp or AI.
- The widget can be served by ChatBridge or self-hosted by the business.
- Conversation history and session behaviour are controlled by the configured backend.

## Deployment and infrastructure choices

The product direction supports managed, self-hosted and hybrid deployments.

Potential backend runtimes include PHP, Node.js, containers and compatible serverless environments. Potential storage targets include MySQL, MariaDB, PostgreSQL, PostgreSQL-compatible services such as Neon and Supabase, and SQLite where operational constraints permit.

These are target compatibility options, not a claim that every adapter is already implemented. The current PHP application is PDO-based and uses MySQL/MariaDB-oriented SQL. PostgreSQL, SQLite and alternative backend runtimes must not be advertised as supported until their implementations, migrations and integration tests exist.

Supabase is one existing hosted demonstration backend. It is not a mandatory runtime or database dependency.

## Platform integrations

### WordPress

The WordPress plugin provides settings, widget injection, health diagnostics and optional integration-specific features. It remains an optional connector.

### WooCommerce

The WooCommerce adapter may expose public catalogue search and product enquiry handoff. It must expose only published, customer-visible catalogue information.

Private order lookup requires a separate verified flow. Public endpoints must never expose private customer or order data.

### Other platforms

The universal script embed is the initial non-WordPress integration. An NPM package and additional CMS/e-commerce adapters are future distribution targets.

## Communication channels

WhatsApp Business Platform is an optional channel. Each business must be able to connect its own eligible business account and configure its own credentials through secure server-side onboarding.

The platform must support website-only chat without WhatsApp. Future channels must be implemented behind channel-provider boundaries rather than embedded in the widget.

## Human support and AI

Human support and AI are separate capabilities.

A human-operated deployment must not require AI configuration. AI is optional and should support interchangeable providers, tenant-specific instructions and knowledge sources, usage controls, and human escalation.

A staff inbox is an optional capability. Its opt-in MySQL/MariaDB schema, repository, HMAC server-assertion authentication, local role/assignment authorization, versioned API routes, WordPress server-side assertion proxy and separate WordPress admin UI are implemented. The assertion binds the exact request target and body digest; roles are provisioned locally, with an explicit CLI command for the initial owner. Conversation search, in-product role administration, export/restore and shared-database multi-tenant isolation remain incomplete. It is not enabled for the Dimdian deployment.

## Backend responsibilities

- Tenant resolution and isolation.
- Guest sessions and authentication.
- Conversation and message lifecycle.
- Consent and privacy enforcement.
- Rate limits and abuse protection.
- Origin allowlists and CORS.
- Attachments and retention where enabled.
- Channel orchestration and webhook processing.
- Provider-independent API responses.
- Auditing and operational diagnostics.

## Current repository status

- The active reference backend is PHP with PDO and MySQL/MariaDB-oriented SQL.
- Existing guest-session, message, consent, webhook and health endpoints are documented in API.md.
- The embeddable JavaScript widget is implemented and currently calls the PHP API in API-backed mode.
- The WordPress connector and optional read-only WooCommerce catalogue integration exist.
- The production-hardening branch includes consent lifecycle controls, durable encrypted webhook ingestion, Meta onboarding operator functionality and retention processing.
- A Supabase-backed mock API and Meta diagnostic Edge Functions exist separately; they do not yet establish complete API parity with PHP.
- The GitHub Pages demo currently runs the widget in browser-only simulated mode and does not persist conversations.
- A full storage-adapter architecture, PostgreSQL implementation, alternative backend runtimes, NPM distribution, self-service tenant portal and complete helpdesk-grade staff inbox/AI provider system are not yet complete.

## Technical and release constraints

- Keep the widget lightweight and independent of backend/database vendors.
- Keep all private credentials server-side.
- Maintain backward compatibility while introducing a versioned API.
- Extract persistence behind repository interfaces before claiming database portability.
- Require contract tests and dialect-specific integration tests for each advertised backend/database combination.
- Keep optional integrations disabled unless explicitly configured.
- Do not claim live WhatsApp delivery, AI responses, persistence or staff functionality unless the deployed implementation actually provides it.

See PORTABLE-ARCHITECTURE.md for the architecture decision and ../tests/PORTABILITY-ACCEPTANCE.md for acceptance criteria.
