-- Append-only staff activity history for the Dimdian Supabase mock inbox.
-- Event payloads deliberately exclude message bodies, attachment contents and credentials.

CREATE TABLE IF NOT EXISTS public.chatbridge_staff_audit_events (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id uuid NOT NULL REFERENCES public.chatbridge_tenants(id) ON DELETE CASCADE,
  conversation_id uuid REFERENCES public.chatbridge_conversations(id) ON DELETE SET NULL,
  actor_user_id uuid REFERENCES auth.users(id) ON DELETE SET NULL,
  event_type text NOT NULL CHECK (event_type IN (
    'reply_sent',
    'conversation_status_changed',
    'conversation_assignment_changed'
  )),
  details jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(details) = 'object'),
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_chatbridge_staff_audit_conversation_created
  ON public.chatbridge_staff_audit_events(tenant_id, conversation_id, created_at DESC, id DESC);

ALTER TABLE public.chatbridge_staff_audit_events ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE public.chatbridge_staff_audit_events FROM PUBLIC, anon, authenticated;
REVOKE ALL ON TABLE public.chatbridge_staff_audit_events FROM service_role;
GRANT SELECT, INSERT ON TABLE public.chatbridge_staff_audit_events TO service_role;
GRANT USAGE, SELECT ON SEQUENCE public.chatbridge_staff_audit_events_id_seq TO service_role;

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

    INSERT INTO public.chatbridge_staff_audit_events
      (tenant_id, conversation_id, actor_user_id, event_type, details)
    VALUES (
      p_tenant_id, p_conversation_id, p_user_id, 'reply_sent',
      jsonb_build_object('message_id', v_message.id, 'channel', 'website')
    );
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
DECLARE
  v_row public.chatbridge_conversations%ROWTYPE;
  v_previous public.chatbridge_conversations%ROWTYPE;
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

  SELECT * INTO v_previous
  FROM public.chatbridge_conversations
  WHERE tenant_id = p_tenant_id AND id = p_conversation_id
  FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'conversation_not_found'; END IF;

  UPDATE public.chatbridge_conversations
  SET status = coalesce(p_status, status),
      assigned_to = CASE WHEN p_update_assignment THEN p_assigned_to ELSE assigned_to END,
      updated_at = now()
  WHERE tenant_id = p_tenant_id AND id = p_conversation_id
  RETURNING * INTO v_row;

  IF v_previous.status IS DISTINCT FROM v_row.status THEN
    INSERT INTO public.chatbridge_staff_audit_events
      (tenant_id, conversation_id, actor_user_id, event_type, details)
    VALUES (
      p_tenant_id, p_conversation_id, p_user_id, 'conversation_status_changed',
      jsonb_build_object('from', v_previous.status, 'to', v_row.status)
    );
  END IF;

  IF v_previous.assigned_to IS DISTINCT FROM v_row.assigned_to THEN
    INSERT INTO public.chatbridge_staff_audit_events
      (tenant_id, conversation_id, actor_user_id, event_type, details)
    VALUES (
      p_tenant_id, p_conversation_id, p_user_id, 'conversation_assignment_changed',
      jsonb_build_object('from_user_id', v_previous.assigned_to, 'to_user_id', v_row.assigned_to)
    );
  END IF;

  RETURN jsonb_build_object('id',v_row.id,'status',v_row.status,'assigned_to',v_row.assigned_to,'updated_at',v_row.updated_at);
END;
$$;

REVOKE EXECUTE ON FUNCTION public.chatbridge_staff_append_message(uuid,uuid,uuid,uuid,text) FROM PUBLIC, anon, authenticated;
REVOKE EXECUTE ON FUNCTION public.chatbridge_staff_update_conversation(uuid,uuid,uuid,text,uuid,boolean) FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.chatbridge_staff_append_message(uuid,uuid,uuid,uuid,text) TO service_role;
GRANT EXECUTE ON FUNCTION public.chatbridge_staff_update_conversation(uuid,uuid,uuid,text,uuid,boolean) TO service_role;
