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
