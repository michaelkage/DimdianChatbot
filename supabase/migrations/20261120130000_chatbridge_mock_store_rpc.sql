-- RPC surface for the server-side PHP Supabase adapter.
-- All functions are SECURITY INVOKER and executable only by service_role.

CREATE OR REPLACE FUNCTION public.chatbridge_take_rate_limit(
  p_bucket_key char(64), p_tenant_id uuid, p_max_requests integer, p_window_seconds integer
) RETURNS boolean
LANGUAGE plpgsql SECURITY INVOKER SET search_path = public
AS $$
DECLARE v_count integer;
BEGIN
  INSERT INTO public.chatbridge_rate_limits (bucket_key, tenant_id, window_started_at, request_count)
  VALUES (p_bucket_key, p_tenant_id, now(), 1)
  ON CONFLICT (bucket_key) DO UPDATE SET
    request_count = CASE WHEN chatbridge_rate_limits.window_started_at <= now() - make_interval(secs => greatest(1, p_window_seconds))
      THEN 1 ELSE chatbridge_rate_limits.request_count + 1 END,
    window_started_at = CASE WHEN chatbridge_rate_limits.window_started_at <= now() - make_interval(secs => greatest(1, p_window_seconds))
      THEN now() ELSE chatbridge_rate_limits.window_started_at END
  RETURNING request_count INTO v_count;
  RETURN v_count <= greatest(1, p_max_requests);
END;
$$;

CREATE OR REPLACE FUNCTION public.chatbridge_create_guest_session(
  p_tenant_id uuid, p_name text, p_phone text, p_phone_normalized text,
  p_whatsapp_opt_in boolean, p_token_hash char(64), p_expires_at timestamptz
) RETURNS jsonb
LANGUAGE plpgsql SECURITY INVOKER SET search_path = public
AS $$
DECLARE
  v_guest_id uuid := gen_random_uuid();
  v_conversation_id uuid := gen_random_uuid();
  v_session_id uuid := gen_random_uuid();
  v_now timestamptz := now();
BEGIN
  IF NOT EXISTS (SELECT 1 FROM public.chatbridge_tenants WHERE id = p_tenant_id AND status = 'active') THEN
    RAISE EXCEPTION 'tenant_not_active';
  END IF;
  INSERT INTO public.chatbridge_guests (id, tenant_id, display_name, phone, phone_normalized, whatsapp_opt_in_at)
  VALUES (v_guest_id, p_tenant_id, p_name, p_phone, p_phone_normalized,
    CASE WHEN p_whatsapp_opt_in THEN v_now ELSE NULL END);
  INSERT INTO public.chatbridge_conversations (id, tenant_id, guest_id, status)
  VALUES (v_conversation_id, p_tenant_id, v_guest_id, 'open');
  INSERT INTO public.chatbridge_guest_sessions (id, tenant_id, guest_id, conversation_id, token_hash, expires_at)
  VALUES (v_session_id, p_tenant_id, v_guest_id, v_conversation_id, p_token_hash, p_expires_at);
  RETURN jsonb_build_object('session_id', v_session_id, 'conversation_id', v_conversation_id,
    'expires_at', p_expires_at, 'created_at', v_now);
END;
$$;

CREATE OR REPLACE FUNCTION public.chatbridge_auth_guest_session(
  p_tenant_id uuid, p_token_hash char(64), p_require_open boolean DEFAULT true
) RETURNS jsonb
LANGUAGE plpgsql SECURITY INVOKER SET search_path = public
AS $$
DECLARE v_row record;
BEGIN
  SELECT gs.id AS session_id, gs.tenant_id, gs.guest_id, gs.conversation_id,
    gs.expires_at, g.display_name, g.phone, g.phone_normalized,
    g.whatsapp_opt_in_at, c.status AS conversation_status
  INTO v_row
  FROM public.chatbridge_guest_sessions gs
  JOIN public.chatbridge_conversations c ON c.tenant_id = gs.tenant_id AND c.id = gs.conversation_id
  JOIN public.chatbridge_guests g ON g.tenant_id = gs.tenant_id AND g.id = gs.guest_id
  WHERE gs.tenant_id = p_tenant_id AND gs.token_hash = p_token_hash
    AND gs.revoked_at IS NULL AND gs.expires_at > now()
  LIMIT 1;
  IF NOT FOUND THEN RAISE EXCEPTION 'session_expired'; END IF;
  IF p_require_open AND v_row.conversation_status <> 'open' THEN RAISE EXCEPTION 'conversation_closed'; END IF;
  UPDATE public.chatbridge_guest_sessions SET last_seen_at = now()
  WHERE id = v_row.session_id AND tenant_id = p_tenant_id;
  RETURN to_jsonb(v_row);
END;
$$;

CREATE OR REPLACE FUNCTION public.chatbridge_append_message(
  p_tenant_id uuid, p_conversation_id uuid, p_client_message_id uuid, p_body text
) RETURNS jsonb
LANGUAGE plpgsql SECURITY INVOKER SET search_path = public
AS $$
DECLARE v_row public.chatbridge_messages%ROWTYPE; v_inserted boolean := false;
BEGIN
  IF NOT EXISTS (SELECT 1 FROM public.chatbridge_conversations
    WHERE tenant_id = p_tenant_id AND id = p_conversation_id AND status = 'open') THEN
    RAISE EXCEPTION 'conversation_closed_or_missing';
  END IF;
  INSERT INTO public.chatbridge_messages
    (tenant_id, conversation_id, client_message_id, sender_type, body, delivery_status)
  VALUES (p_tenant_id, p_conversation_id, p_client_message_id, 'visitor', p_body, 'web_only')
  ON CONFLICT (tenant_id, conversation_id, client_message_id) DO NOTHING
  RETURNING * INTO v_row;
  v_inserted := FOUND;
  IF NOT v_inserted THEN
    SELECT * INTO v_row FROM public.chatbridge_messages
    WHERE tenant_id = p_tenant_id AND conversation_id = p_conversation_id
      AND client_message_id = p_client_message_id LIMIT 1;
    IF v_row.body IS DISTINCT FROM p_body THEN RAISE EXCEPTION 'idempotency_conflict'; END IF;
  ELSE
    UPDATE public.chatbridge_conversations SET last_message_at = now(), updated_at = now()
    WHERE tenant_id = p_tenant_id AND id = p_conversation_id;
  END IF;
  RETURN jsonb_build_object('id', v_row.id, 'sequence_id', v_row.sequence_id,
    'conversation_id', v_row.conversation_id, 'sender', v_row.sender_type,
    'message', v_row.body, 'delivery_status', v_row.delivery_status,
    'created_at', v_row.created_at, 'inserted', v_inserted);
END;
$$;

REVOKE EXECUTE ON FUNCTION public.chatbridge_take_rate_limit(char, uuid, integer, integer) FROM PUBLIC, anon, authenticated;
REVOKE EXECUTE ON FUNCTION public.chatbridge_create_guest_session(uuid, text, text, text, boolean, char, timestamptz) FROM PUBLIC, anon, authenticated;
REVOKE EXECUTE ON FUNCTION public.chatbridge_auth_guest_session(uuid, char, boolean) FROM PUBLIC, anon, authenticated;
REVOKE EXECUTE ON FUNCTION public.chatbridge_append_message(uuid, uuid, uuid, text) FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.chatbridge_take_rate_limit(char, uuid, integer, integer) TO service_role;
GRANT EXECUTE ON FUNCTION public.chatbridge_create_guest_session(uuid, text, text, text, boolean, char, timestamptz) TO service_role;
GRANT EXECUTE ON FUNCTION public.chatbridge_auth_guest_session(uuid, char, boolean) TO service_role;
GRANT EXECUTE ON FUNCTION public.chatbridge_append_message(uuid, uuid, uuid, text) TO service_role;
