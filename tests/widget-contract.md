# Widget acceptance checks

Run against a test website origin, configured API and disposable database.

1. Launcher appears without changing host page layout.
2. Opening exposes a labelled chat dialog; closing hides it and stops polling.
3. Shadow DOM isolates widget styles from host-page styles.
4. Visitor can send with no name and no phone number.
5. Name and phone are optional and sent only on first message/session creation.
6. No session is created merely by loading the script or opening the panel.
7. Token is stored in sessionStorage, never in a URL, and scoped by API base and website origin.
8. Reopening and reloading the same tab restores the session and retrieves messages after the saved cursor.
9. A 401 response clears the expired session and allows a fresh conversation.
10. Visitor and support message bodies are inserted as text, not HTML.
11. Duplicate polled messages are not rendered twice.
12. Polling runs only while open and visible; it backs off on failures.
13. Enter sends; Shift+Enter inserts a newline.
14. Successful submission is labelled saved, not delivered.
15. Network and API errors are shown without exposing server internals.
16. Layout remains usable at narrow mobile widths and by keyboard.
