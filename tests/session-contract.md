# Guest-session contract checks

These checks describe the acceptance criteria for the first API slice. They require a configured MySQL/MariaDB database and a PHP 8.2+ runtime to execute end-to-end.

1. A valid JSON POST with no fields returns 201 and creates a guest, conversation, and guest session.
2. A valid JSON POST with name and phone stores them as optional contact details and returns 201.
3. The response contains a 64-character hex token; the database contains only its SHA-256 hash, never the plaintext token.
4. No phone number, OTP, password, or account is required.
5. An origin outside the exact allowlist returns 403 and creates no records.
6. A non-POST request returns 405 and includes an Allow header.
7. A non-JSON content type returns 415.
8. Malformed JSON returns 400; oversized JSON returns 413.
9. Invalid field types or values longer than the documented limits return 422.
10. The configured per-IP rate limit returns 429 after the allowed request count.
11. A database failure returns a generic 500 response and logs the underlying error server-side only.
12. A preflight OPTIONS request returns 204 and does not create a session.
