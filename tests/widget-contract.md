# Widget acceptance checks

Run against a test website origin and disposable database.

1. Launcher appears without changing host page layout.
2. Opening exposes a labelled chat dialog; closing hides it and stops polling.
3. Shadow DOM isolates widget styles from host-page styles.
4. Visitor can send with no name and no phone number.
5. Name and phone are optional; WhatsApp consent is an explicit, separate checkbox.
6. Opting into WhatsApp without a phone number is rejected with a clear validation message.
7. No session is created merely by loading the script or opening the panel.
8. Token is stored in sessionStorage, never in a URL, and scoped by API base and website origin.
9. Reloading the same tab restores the session and fetches the full conversation history.
10. A 401 or closed-conversation 409 allows the visitor to start a fresh conversation.
11. Visitor and support message bodies are inserted as text, not HTML.
12. Duplicate polled messages are not rendered twice.
13. Polling runs only while open and visible; it backs off on failures.
14. Delivery status changes are refreshed for recent visitor messages.
15. Enter sends; Shift+Enter inserts a newline.
16. A message without WhatsApp delivery eligibility is labelled Website only, not queued.
17. Network and API errors are shown without exposing server internals.
18. Layout remains usable at narrow mobile widths and by keyboard.
