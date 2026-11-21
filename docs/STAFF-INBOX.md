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

## Change the support account email

The first owner account is intended to use the email address selected during setup. A signed-in staff member can open **Account settings → Change sign-in email** in the inbox and request an email change. Supabase Auth handles the verification/confirmation flow; the new address is not treated as active merely because the form was submitted. The existing Auth user UUID and its `chatbridge_staff_memberships` row remain the same, so tenant access does not need to be re-provisioned. The email change is available to any signed-in staff account; it does not change another user's email or transfer ownership.

## Current inbox behaviour

- Lists open, closed or all conversations for the tenant resolved from the verified GitHub Pages origin.
- Shows visitor name, optional phone, last message, conversation status and attachment count.
- Loads the conversation's ordered message history.
- Sends support replies as `business` messages, so they appear as support replies in the visitor widget.
- Assigns a conversation to the signed-in agent or leaves it unassigned.
- Closes and reopens conversations.
- Lists customer-visible uploads and `staff_only` diagnostic bundles. Signed URLs expire after 120 seconds.
- Shows a per-conversation activity history for staff replies, status changes and assignment changes. Event details exclude message bodies and attachment contents.
- Provides owner/admin retention settings for conversations/messages, customer uploads, diagnostic bundles and staff audit events. Each category defaults to 14 days; set a category to 0 to retain it indefinitely. A daily cleanup runs at approximately 04:15 Lagos time, and owners/admins can run it manually from Account settings.
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

This is a first functional support inbox, not yet a production-grade team console. It has no teammate directory, team assignment, internal notes, canned replies, file upload by agents or presence/Realtime. The audit history currently records staff replies, status changes and assignment changes only; it is not a database-wide audit trail. The retention worker deletes expired objects through the Supabase Storage API before removing their metadata. Cleanup is bounded per run; failures are counted and retried by later scheduled runs. Signed attachment links are short-lived, but a link remains usable until it expires. This retention implementation applies to the Supabase mock tenant; self-hosted PHP/MySQL/MariaDB deployments must configure their own retention policy.
