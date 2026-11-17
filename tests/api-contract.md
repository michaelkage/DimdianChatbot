# API acceptance checks

These are integration acceptance criteria for PHP 8.2+ and MySQL/MariaDB. Run against a disposable test database and configured test origin.

## Guest sessions

1. A valid JSON POST with no fields returns 201 and creates a guest, conversation, and guest session.
2. A valid JSON POST with name and phone stores them as optional contact details and returns 201.
3. The response contains a 64-character hex token; the database contains only its SHA-256 hash, never the plaintext token.
4. No phone number, OTP, password, or account is required.
5. An origin outside the exact allowlist returns 403 and creates no records.
6. A non-POST request returns 405 and includes an Allow header.
7. A non-JSON content type returns 415.
8. Malformed JSON returns 400; oversized JSON returns 413.
9. Invalid field types or values longer than the documented limits return 422.
10. The configured session rate limit returns 429 after the allowed request count.
11. A database failure returns a generic 500 response and logs the underlying error server-side only.
12. An OPTIONS preflight returns 204 and creates no session.

## Message submission

13. A request without a guest token returns 401.
14. An invalid, expired or revoked token returns 401.
15. A valid token can submit a non-empty message and receives 201.
16. Empty, non-string, invalid UTF-8 or over-4,000-character message bodies are rejected.
17. A message is stored against the authenticated session's conversation; a caller cannot choose another conversation ID.
18. Visitor messages are stored as sender_type=visitor and delivery_status=queued.
19. The response does not claim WhatsApp delivery; it reports transport=not_configured.
20. Per-session rate limiting returns 429 after the configured number of messages.
21. A closed conversation returns 409 and accepts no new message.

## Polling

22. GET requires a valid guest token.
23. Polling returns only messages belonging to that token's conversation.
24. Messages are returned in ascending sequence_id order.
25. Passing after=N returns only rows with sequence_id greater than N.
26. Cursor and limit reject malformed values; limit is bounded to 1–100.
27. Empty polls return an empty data array and preserve the supplied cursor.
28. A message inserted into another conversation is never disclosed by this session's poll.

## Runtime checks still required

- Import schema and migration into the exact Hostinger MySQL/MariaDB version.
- Exercise CORS preflight and requests from the actual website origin.
- Confirm PHP PDO MySQL is enabled.
- Confirm PHP error logs do not expose secrets.
