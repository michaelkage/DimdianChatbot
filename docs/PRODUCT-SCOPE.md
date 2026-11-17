# Product Scope

## Purpose

Provide a small website chat widget that lets prospective and existing customers send quick enquiries and receive replies from the business's WhatsApp support team.

## Customer experience

- Chat is embedded on the business website.
- A visitor can start as a guest without creating an account.
- No OTP, password, or registration step.
- A visitor can send a short enquiry immediately.
- A phone number may be captured as a contact detail; it is not used as an identity-verification gate.
- Conversation history remains available in the same browser session and can be restored when the widget is reopened.
- The widget shows customer and support messages in chronological order.
- No full-page customer portal.

## Support experience

- Support staff use the WhatsApp Business app.
- No separate staff dashboard or inbox.
- A website enquiry is routed to the configured WhatsApp Business channel.
- A support reply sent from the WhatsApp Business app must be reflected in the originating website conversation.
- The backend stores message IDs and routing metadata to associate each reply with the correct website conversation and prevent duplicate webhook processing.

## Backend responsibilities

- Create and maintain anonymous guest sessions.
- Store conversations and messages.
- Send website-originated messages through the supported WhatsApp Business Platform API.
- Receive and verify WhatsApp webhooks.
- Associate app-originated support replies with website conversations.
- Expose a small polling API for the widget.
- Apply request validation, rate limits, CSRF/origin protections where applicable, and webhook idempotency.
- Keep credentials outside the public web root and never commit secrets.

## WhatsApp integration gate

The intended experience depends on the business continuing to use the WhatsApp Business app while the same number is connected to the Cloud API. Confirm that Meta's supported coexistence onboarding is available for the actual +234 business number and that app-originated messages are delivered through the required webhook event. Validate this with a test number/account before migrating or changing the live business number.

If coexistence is unavailable, stop and present the operational alternatives before changing the product architecture. Do not silently substitute a separate staff dashboard.

## Explicitly out of scope

- AI chat, LLM providers, prompts, AI triage or AI settings
- Staff/admin inbox, agent accounts, assignment or ticket dashboards
- WooCommerce or other catalogue integrations
- Product search, MPN lookup, fitment checks, VIN capture or vehicle records
- Inventory, order management, payments or ERP workflows
- Customer accounts, passwords or OTP verification
- Native mobile applications
- Persistent WebSocket servers or mandatory paid SaaS infrastructure

## Technical constraints

- PHP 8.2+ with PDO
- MySQL / MariaDB
- Vanilla browser JavaScript, HTML and CSS
- Shared-hosting compatible HTTP endpoints and short polling
- Keep the active codebase small and dependency-light

## Current status

This document defines the new direction. The old marketplace-support source is retained under `archive/legacy-marketplace-support/` for reference only. The new runtime implementation has not yet been created.
