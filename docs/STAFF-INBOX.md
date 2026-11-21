# Dimdian mock support inbox

The static agent workspace lives at:

`https://michaelkage.github.io/DimdianChatbot/inbox/`

It uses Supabase Auth for identity and the `chatbridge-mock-api` Edge Function for tenant-scoped authorization and data access. The browser receives only the project's publishable key and the signed-in user's short-lived Auth session. It never receives the Supabase secret/service-role key.

## Provision the first support user

1. Open the Dimdian Supabase project: `gpafuuoxrbzveaddomgt`.
2. In **Authentication → Users**, create/invite the support user's email. Do not enable unrestricted public signups just to provision agents.
3. Confirm the user exists and copy its Auth user UUID.
4. In the SQL Editor, grant that user access to the mock tenant:

```sql
INSERT INTO public.chatbridge_staff_memberships
  (tenant_id, user_id, role, status)
VALUES
  (
    '00000000-0000-4000-8000-000000000001',
    'REPLACE-WITH-AUTH-USER-UUID',
    'owner',
    'active'
  )
ON CONFLICT (tenant_id, user_id)
DO UPDATE SET role = EXCLUDED.role, status = 'active', updated_at = now();
```

Use `owner`, `admin`, or `agent` as the role. All three roles currently have the same mock inbox actions; the role distinction is reserved for later permission granularity. Remove access by setting `status = 'revoked'` or `suspended'`.

## Current inbox behaviour

- Lists open, closed or all conversations for the tenant resolved from the verified GitHub Pages origin.
- Shows visitor name, optional phone, last message, conversation status and attachment count.
- Loads the conversation's ordered message history.
- Sends support replies as `business` messages, so they appear as support replies in the visitor widget.
- Assigns a conversation to the signed-in agent or leaves it unassigned.
- Closes and reopens conversations.
- Lists customer-visible uploads and `staff_only` diagnostic bundles. Signed URLs expire after 120 seconds.
- Refreshes the inbox every six seconds. This is polling, not Supabase Realtime.

## Security model

- The Edge Function validates the Supabase Auth access token against the Auth service.
- It then checks an active `chatbridge_staff_memberships` row for the resolved tenant and authenticated user.
- All staff database operations include the server-resolved tenant ID. Browser-supplied tenant IDs are ignored.
- Staff RPCs also check active membership and tenant scope before reading or changing conversations.
- RLS remains enabled and no direct `anon` or `authenticated` table policies are added. Only the server-side service role can access the tables.
- Visitor attachment listing still filters to `customer_visible`; staff attachment listing is a separate authenticated route.
- Staff replies are website-only in this mock environment. They do not send WhatsApp messages or trigger AI/handover.

## Limitations

This is a first functional support inbox, not yet a production-grade team console. It has no teammate directory, team assignment, internal notes, canned replies, file upload by agents, presence/Realtime, audit UI, retention cleanup job or attachment deletion control. Signed attachment links are short-lived, but the browser can access a link until it expires. Add an authenticated staff audit log and retention/deletion workflow before using it for sensitive production support.
