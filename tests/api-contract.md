# API acceptance checks

These checks describe the expected behavior. `tests/integration.php` exercises the HTTP API, webhook and outbox against a disposable MySQL database in CI.

## Guest sessions

1. A valid JSON POST with no fields returns 201 and creates a guest, conversation and guest session.
2. Name and phone are optional contact details.
3. The response contains a 64-character hex token; the database stores only its SHA-256 hash.
4. No phone number, OTP, password or account is required.
5. An origin outside the exact allowlist returns 403 and creates no records.
6. WhatsApp opt-in requires a valid international phone number and is stored separately from the phone.
7. A non-POST request returns 405; non-JSON returns 415; malformed JSON returns 400.
8. Invalid field types, malformed phone numbers and overlong values return 422.
9. The configured session rate limit returns 429.
10. A preflight OPTIONS request returns 204 and creates no session.

## Message submission

11. A request without a guest token returns 401; invalid, expired or revoked tokens also return 401.
12. A valid token can submit a non-empty message and receives 201.
13. Messages are stored against the authenticated conversation; clients cannot select another conversation.
14. Messages without configured WhatsApp delivery eligibility are stored as `web_only`.
15. An opted-in visitor message is added to the outbox in the same transaction as the website message.
16. Repeating the same `client_message_id` and body returns the original message without creating another row or outbox job.
17. Reusing a `client_message_id` with different text returns 409.
18. Empty, non-string, invalid UTF-8 or over-4,000-character bodies are rejected.
19. Per-session rate limiting returns 429.
20. Closed conversations reject POST with 409 but still allow history retrieval.

## Polling and delivery state

21. Polling returns only messages belonging to the authenticated conversation, in ascending sequence order.
22. `after=N` returns only rows with sequence IDs greater than N.
23. Cursor and limit reject malformed values; limit is bounded to 1–100.
24. Poll metadata includes recent delivery-state updates, even when no new message was added.
25. A message inserted into another conversation is never disclosed.

## WhatsApp webhook and outbox

26. GET webhook verification returns the challenge only for the configured verify token.
27. POST webhook requests require a valid HMAC-SHA256 `X-Hub-Signature-256` over the exact raw body.
28. Duplicate inbound and app-echo WAMIDs are not inserted twice.
29. Inbound text messages and supported `smb_message_echoes` text messages are correlated by normalized visitor phone.
30. Status callbacks update outbound message state without downgrading delivered/read.
31. Status callbacks arriving before the send response are retained and reconciled when the WAMID is later attached to the message.
32. Outbox jobs are claimed under row locks, retried with backoff, and marked failed after permanent failure or exhausted attempts.

## Runtime checks still required

- Run the schema against the exact target Hostinger MySQL/MariaDB version.
- Verify PHP PDO MySQL and outbound HTTPS are available.
- Verify the approved template and Meta credentials with a dedicated test number.
- Verify actual +234 Coexistence eligibility and Business-app echo delivery.


## Optional staff inbox

The staff API is specified separately in `docs/openapi-staff.yaml`. It is optional, server-to-server and uses a request-bound HMAC assertion rather than a guest token.

33. Requests without a valid staff assertion return 401; assertions are bound to the exact method, request target including query, raw-body SHA-256, issue time and one-time nonce.
34. A staff assertion cannot be replayed or reused against another route, query string or modified body.
35. The authenticated identity is resolved to a local staff identity; external role claims never override the local role.
36. Owners/admins can list all conversations; agents can see only their active assignments and unassigned conversations.
37. Agents can self-assign only unassigned open conversations and can unassign only their own active assignment.
38. Conversation message, note and assignment-history reads enforce the agent's current assignment.
39. Private notes are stored separately, are idempotent by note ID, and never appear in the visitor message stream or conversation search.
40. Staff replies are idempotent by client message ID, attributed to the local staff identity, visible in website polling and never added to the WhatsApp outbox.
41. Only owners can update local roles and account status; the last active owner cannot be demoted or disabled.
42. Only administrators can list staff identities or read audit events.
43. A disabled staff inbox returns 404; an enabled module without an assertion secret returns 503.

The existing HTTP acceptance runner is `tests/staff-inbox-http.php`; repository/policy and HMAC replay tests are in `tests/staff-inbox.php`.

## Backend-neutral executable acceptance

`tests/api-acceptance.mjs` uses only Node's built-in fetch and crypto modules. It accepts `DIMDIAN_API_BASE_URL` (default `http://127.0.0.1:8080/api/v1/`) and exercises session creation, message persistence, idempotent replay/conflict, guest-token authorization, polling, WhatsApp opt-in preconditions and opt-out. It does not inspect or require direct database access, so the same runner can be pointed at another backend implementing the v1 contract.


### Current CI validation

The backend-neutral runner and both OpenAPI structural checks passed in [GitHub Actions run 45407583097](https://github.com/michaelkage/DimdianChatbot/actions/runs/45407583097) on 2026-11-24. This validates the current PHP/MySQL implementation only; no alternate backend has yet been tested against the same runner.


## Backend-neutral staff acceptance

`tests/staff-api-acceptance.mjs` uses Node's built-in crypto/fetch to sign the same per-request HMAC assertions described in `docs/openapi-staff.yaml`. It creates two visitor conversations and two first-seen local agent identities without direct database access, then verifies unassigned visibility, self-assignment, assignment isolation, denial of cross-agent reads and assignment theft, private-note idempotency and isolation from the visitor thread, website-only staff replies, assignment history, administrator-only route denial and immediate access loss after unassignment. The same runner can be pointed at another implementation by setting `DIMDIAN_API_BASE_URL`, `DIMDIAN_STAFF_ASSERTION_SECRET` and (when not using the legacy single-tenant default) `DIMDIAN_STAFF_TENANT_ID`. The signed assertion now includes the tenant UUID.


### Latest full validation

[GitHub Actions run 45409680687](https://github.com/michaelkage/DimdianChatbot/actions/runs/45409680687) passed on 2026-11-24 after adding the backend-neutral staff runner and fixing the native-PDO assignment parameter defect. It includes both Node acceptance runners, PHP/MySQL and staff HTTP integration suites, widget tests, OpenAPI structural validation and WordPress PHP 7.4/8.2 compatibility.
