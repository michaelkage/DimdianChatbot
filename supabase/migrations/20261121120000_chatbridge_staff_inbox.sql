-- Staff inbox authorization and operations for the Dimdian Supabase mock site.
-- Staff identity comes from Supabase Auth; tenant membership is explicit and tenant-scoped.

CREATE TABLE IF NOT EXISTS public.chatbridge_staff_memberships (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES public.chatbridge_tenants(id) ON DELETE CASCADE,
  user_id uuid NOT NULL REFERENCES auth.users(id) ON DELETE CASCADE,
  role text NOT NULL DEFAULT 'agent' CHECK (role IN ('owner','admin','agent')),
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended','revoked')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_chatbridge_staff_memberships_user
  ON public.chatbridge_staff_memberships(user_id, status, tenant_id);

ALTER TABLE public.chatbridge_conversations
  ADD COLUMN IF NOT EXISTS assigned_to uuid REFERENCES auth.users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_chatbridge_conversations_assigned
  ON public.chatbridge_conversations(tenant_id, assigned_to, status, updated_at DESC);

ALTER TABLE public.chatbridge_staff_memberships ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE public.chatbridge_staff_memberships FROM PUBLIC, anon, authenticated;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.chatbridge_staff_memberships TO service_role;

CREATE OR REPLACE FUNCTION public.chatbridge_staff_list_conversations(
  p_tenant_id uuid,
  p_user_id uuid,
  p_status text DEFAULT 'open',
  p_limit integer DEFAULT 50,
  p_before timestamptz DEFAULT NULL
) RETURNS SETOF jsonb
LANGUAGE plpgsql SECURITY INVOKER SET search_path = public
AS $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM public.chatbridge_staff_memberships m
    WHERE m.tenant_id = p_tenant_id AND m.user_id = p_user_id AND m.status = 'active'
  ) THEN
    RAISE EXCEPTION 'staff_membership_required';
  END IF;
  IF p_status NOT IN ('open','closed','all') THEN RAISE EXCEPTION 'invalid_status_filter'; END IF;
  RETURN QUERY
  SELECT jsonb_build_object(
    'id', c.id,
    'guest_name', g.display_name,
    'guest_phone', g.phone,
    'status', c.status,
    'channel', c.channel,
    'assigned_to', c.assigned_to,
    'created_at', c.created_at,
    'last_message_at', c.last_message_at,
    'last_message', (
      SELECT jsonb_build_object(
        'sender', m.sender_type,
        'message', left(m.body, 500),
        'created_at', m.created_at
      )
      FROM public.chatbridge_messages m
      WHERE m.tenant_id = c.tenant_id AND m.conversation_id = c.id
      ORDER BY m.sequence_id DESC LIMIT 1
    ),
    'attachment_count', (
      SELECT count(*) FROM public.chatbridge_attachments a
      WHERE a.tenant_id = c.tenant_id AND a.conversation_id = c.id AND a.deleted_at IS NULL
    )
  )
  FROM public.chatbridge_conversations c
  JOIN public.chatbridge_guests g ON g.tenant_id = c.tenant_id AND g.id = c.guest_id
  WHERE c.tenant_id = p_tenant_id
    AND (p_status = 'all' OR c.status = p_status)
    AND (p_before IS NULL OR coalesce(c.last_message_at, c.created_at) < p_before)
  ORDER BY coalesce(c.last_message_at, c.created_at) DESC, c.id DESC
  LIMIT greatest(1, least(100, p_limit));
END;
$$;

CREATE OR REPLACE FUNCTION public.chatbridge_staff_append_message(
  p_tenant_id uuid,
  p_conversation_id uuid,
  p_user_id uuid,
  p_client_message_id uuid,
  p_body text
) RETURNS jsonb
LANGUAGE plpgsql SECURITY INVOKER SET search_path = public
AS $$
DECLARE
  v_message public.chatbridge_messages%ROWTYPE;
  v_inserted boolean := false;
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM public.chatbridge_staff_memberships m
    WHERE m.tenant_id = p_tenant_id AND m.user_id = p_user_id AND m.status = 'active'
  ) THEN RAISE EXCEPTION 'staff_membership_required'; END IF;
  IF length(trim(coalesce(p_body,''))) < 1 OR length(p_body) > 4000 THEN
    RAISE EXCEPTION 'invalid_message_body';
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM public.chatbridge_conversations
    WHERE tenant_id = p_tenant_id AND id = p_conversation_id AND status = 'open'
  ) THEN RAISE EXCEPTION 'conversation_closed_or_missing'; END IF;

  INSERT INTO public.chatbridge_messages
    (tenant_id, conversation_id, client_message_id, sender_type, body, delivery_status, metadata)
  VALUES (
    p_tenant_id, p_conversation_id, p_client_message_id, 'business',
    trim(p_body), 'web_only',
    jsonb_build_object('source','staff_inbox','actor_user_id',p_user_id)
  )
  ON CONFLICT (tenant_id, conversation_id, client_message_id) DO NOTHING
  RETURNING * INTO v_message;
  v_inserted := FOUND;
  IF NOT v_inserted THEN
    SELECT * INTO v_message FROM public.chatbridge_messages
    WHERE tenant_id = p_tenant_id AND conversation_id = p_conversation_id
      AND client_message_id = p_client_message_id LIMIT 1;
    IF v_message.body IS DISTINCT FROM trim(p_body) THEN RAISE EXCEPTION 'idempotency_conflict'; END IF;
  ELSE
    UPDATE public.chatbridge_conversations
    SET last_message_at = now(), updated_at = now(), assigned_to = coalesce(assigned_to, p_user_id)
    WHERE tenant_id = p_tenant_id AND id = p_conversation_id;
  END IF;
  RETURN jsonb_build_object(
    'id',v_message.id,'sequence_id',v_message.sequence_id,
    'conversation_id',v_message.conversation_id,'sender',v_message.sender_type,
    'message',v_message.body,'delivery_status',v_message.delivery_status,
    'created_at',v_message.created_at,'inserted',v_inserted
  );
END;
$$;

CREATE OR REPLACE FUNCTION public.chatbridge_staff_update_conversation(
  p_tenant_id uuid,
  p_conversation_id uuid,
  p_user_id uuid,
  p_status text DEFAULT NULL,
  p_assigned_to uuid DEFAULT NULL,
  p_update_assignment boolean DEFAULT false
) RETURNS jsonb
LANGUAGE plpgsql SECURITY INVOKER SET search_path = public
AS $$
DECLARE v_row public.chatbridge_conversations%ROWTYPE;
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM public.chatbridge_staff_memberships m
    WHERE m.tenant_id = p_tenant_id AND m.user_id = p_user_id AND m.status = 'active'
  ) THEN RAISE EXCEPTION 'staff_membership_required'; END IF;
  IF p_status IS NOT NULL AND p_status NOT IN ('open','closed') THEN RAISE EXCEPTION 'invalid_conversation_status'; END IF;
  IF p_update_assignment AND p_assigned_to IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM public.chatbridge_staff_memberships m
    WHERE m.tenant_id = p_tenant_id AND m.user_id = p_assigned_to AND m.status = 'active'
  ) THEN RAISE EXCEPTION 'assignee_not_in_tenant'; END IF;

  UPDATE public.chatbridge_conversations
  SET status = coalesce(p_status, status),
      assigned_to = CASE WHEN p_update_assignment THEN p_assigned_to ELSE assigned_to END,
      updated_at = now()
  WHERE tenant_id = p_tenant_id AND id = p_conversation_id
  RETURNING * INTO v_row;
  IF NOT FOUND THEN RAISE EXCEPTION 'conversation_not_found'; END IF;
  RETURN jsonb_build_object('id',v_row.id,'status',v_row.status,'assigned_to',v_row.assigned_to,'updated_at',v_row.updated_at);
END;
$$;

REVOKE EXECUTE ON FUNCTION public.chatbridge_staff_list_conversations(uuid,uuid,text,integer,timestamptz) FROM PUBLIC, anon, authenticated;
REVOKE EXECUTE ON FUNCTION public.chatbridge_staff_append_message(uuid,uuid,uuid,uuid,text) FROM PUBLIC, anon, authenticated;
REVOKE EXECUTE ON FUNCTION public.chatbridge_staff_update_conversation(uuid,uuid,uuid,text,uuid,boolean) FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.chatbridge_staff_list_conversations(uuid,uuid,text,integer,timestamptz) TO service_role;
GRANT EXECUTE ON FUNCTION public.chatbridge_staff_append_message(uuid,uuid,uuid,uuid,text) TO service_role;
GRANT EXECUTE ON FUNCTION public.chatbridge_staff_update_conversation(uuid,uuid,uuid,text,uuid,boolean) TO service_role;
