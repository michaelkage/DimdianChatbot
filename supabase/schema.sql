-- Supabase schema for Dimdian ChatBridge mock-site testing.
-- This is separate from any customer's MySQL/MariaDB database.
-- For an existing project, apply files under supabase/migrations/ in order.

create table if not exists public.meta_webhook_events (
  id bigint generated always as identity primary key,
  provider_event_id text not null unique,
  event_field text not null,
  event_kind text not null,
  message_type text,
  from_number_masked text,
  to_number_masked text,
  message_text text,
  event_timestamp timestamptz,
  phone_number_id text,
  payload_sha256 text not null,
  processing_status text not null default 'received',
  received_at timestamptz not null default now()
);

create index if not exists meta_webhook_events_received_at_idx
  on public.meta_webhook_events (received_at desc);
create index if not exists meta_webhook_events_event_kind_idx
  on public.meta_webhook_events (event_kind, received_at desc);

alter table public.meta_webhook_events enable row level security;
revoke all on table public.meta_webhook_events from anon, authenticated;
grant select, insert on table public.meta_webhook_events to service_role;
grant usage, select on sequence public.meta_webhook_events_id_seq to service_role;

-- The mock conversation store and private attachment bucket.
-- ChatBridge mock-site conversation and attachment store.
-- Apply only to the dedicated Dimdian Supabase test project.
-- The PHP API uses the server-side service_role credential; browser clients do not access these tables.

CREATE TABLE IF NOT EXISTS public.chatbridge_tenants (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  external_tenant_id uuid NOT NULL UNIQUE,
  display_name text NOT NULL,
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended','deleted')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.chatbridge_site_origins (
  origin text PRIMARY KEY,
  tenant_id uuid NOT NULL REFERENCES public.chatbridge_tenants(id) ON DELETE RESTRICT,
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','verified','disabled')),
  verified_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_chatbridge_site_origins_tenant ON public.chatbridge_site_origins(tenant_id);

CREATE TABLE IF NOT EXISTS public.chatbridge_guests (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES public.chatbridge_tenants(id) ON DELETE RESTRICT,
  display_name text,
  phone text,
  phone_normalized text,
  whatsapp_opt_in_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id)
);
CREATE INDEX IF NOT EXISTS idx_chatbridge_guests_phone ON public.chatbridge_guests(tenant_id, phone_normalized);

CREATE TABLE IF NOT EXISTS public.chatbridge_conversations (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES public.chatbridge_tenants(id) ON DELETE RESTRICT,
  guest_id uuid NOT NULL,
  status text NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
  channel text NOT NULL DEFAULT 'website' CHECK (channel IN ('website','whatsapp')),
  page_url text,
  subject text,
  last_message_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, guest_id) REFERENCES public.chatbridge_guests(tenant_id, id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_chatbridge_conversations_tenant_status ON public.chatbridge_conversations(tenant_id, status, updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_chatbridge_conversations_guest ON public.chatbridge_conversations(tenant_id, guest_id, created_at DESC);

CREATE TABLE IF NOT EXISTS public.chatbridge_guest_sessions (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES public.chatbridge_tenants(id) ON DELETE RESTRICT,
  guest_id uuid NOT NULL,
  conversation_id uuid NOT NULL,
  token_hash char(64) NOT NULL UNIQUE CHECK (token_hash ~ '^[a-f0-9]{64}$'),
  created_at timestamptz NOT NULL DEFAULT now(),
  expires_at timestamptz NOT NULL,
  last_seen_at timestamptz,
  revoked_at timestamptz,
  FOREIGN KEY (tenant_id, guest_id) REFERENCES public.chatbridge_guests(tenant_id, id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id, conversation_id) REFERENCES public.chatbridge_conversations(tenant_id, id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_chatbridge_guest_sessions_expiry ON public.chatbridge_guest_sessions(expires_at) WHERE revoked_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_chatbridge_guest_sessions_conversation ON public.chatbridge_guest_sessions(tenant_id, conversation_id);

CREATE TABLE IF NOT EXISTS public.chatbridge_messages (
  sequence_id bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES public.chatbridge_tenants(id) ON DELETE RESTRICT,
  conversation_id uuid NOT NULL,
  client_message_id uuid,
  sender_type text NOT NULL CHECK (sender_type IN ('visitor','business','system','ai')),
  body text NOT NULL DEFAULT '',
  delivery_status text NOT NULL DEFAULT 'received' CHECK (delivery_status IN ('queued','sent','delivered','read','failed','received','web_only')),
  metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, conversation_id, id),
  UNIQUE (tenant_id, conversation_id, client_message_id),
  FOREIGN KEY (tenant_id, conversation_id) REFERENCES public.chatbridge_conversations(tenant_id, id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_chatbridge_messages_order ON public.chatbridge_messages(tenant_id, conversation_id, sequence_id);

CREATE TABLE IF NOT EXISTS public.chatbridge_attachments (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES public.chatbridge_tenants(id) ON DELETE RESTRICT,
  conversation_id uuid NOT NULL,
  message_id uuid,
  storage_bucket text NOT NULL DEFAULT 'chatbridge-private',
  storage_path text NOT NULL UNIQUE,
  original_filename text NOT NULL,
  mime_type text NOT NULL,
  byte_size bigint NOT NULL CHECK (byte_size > 0 AND byte_size <= 10485760),
  sha256 char(64) NOT NULL CHECK (sha256 ~ '^[a-f0-9]{64}$'),
  visibility text NOT NULL DEFAULT 'customer_visible' CHECK (visibility IN ('customer_visible','staff_only')),
  uploader_type text NOT NULL CHECK (uploader_type IN ('visitor','agent','system')),
  metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  expires_at timestamptz,
  deleted_at timestamptz,
  FOREIGN KEY (tenant_id, conversation_id) REFERENCES public.chatbridge_conversations(tenant_id, id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id, conversation_id, message_id) REFERENCES public.chatbridge_messages(tenant_id, conversation_id, id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_chatbridge_attachments_conversation ON public.chatbridge_attachments(tenant_id, conversation_id, created_at);
CREATE INDEX IF NOT EXISTS idx_chatbridge_attachments_retention ON public.chatbridge_attachments(expires_at) WHERE deleted_at IS NULL;

CREATE TABLE IF NOT EXISTS public.chatbridge_rate_limits (
  bucket_key char(64) PRIMARY KEY CHECK (bucket_key ~ '^[a-f0-9]{64}$'),
  tenant_id uuid NOT NULL REFERENCES public.chatbridge_tenants(id) ON DELETE RESTRICT,
  window_started_at timestamptz NOT NULL,
  request_count integer NOT NULL DEFAULT 0 CHECK (request_count >= 0)
);

ALTER TABLE public.chatbridge_tenants ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.chatbridge_site_origins ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.chatbridge_guests ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.chatbridge_conversations ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.chatbridge_guest_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.chatbridge_messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.chatbridge_attachments ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.chatbridge_rate_limits ENABLE ROW LEVEL SECURITY;

REVOKE ALL ON TABLE public.chatbridge_tenants, public.chatbridge_site_origins,
  public.chatbridge_guests, public.chatbridge_conversations,
  public.chatbridge_guest_sessions, public.chatbridge_messages,
  public.chatbridge_attachments, public.chatbridge_rate_limits
  FROM PUBLIC, anon, authenticated;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.chatbridge_tenants,
  public.chatbridge_site_origins, public.chatbridge_guests,
  public.chatbridge_conversations, public.chatbridge_guest_sessions,
  public.chatbridge_messages, public.chatbridge_attachments,
  public.chatbridge_rate_limits TO service_role;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO service_role;

-- Private Storage bucket. All upload/download operations are server-mediated.
INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
VALUES (
  'chatbridge-private',
  'chatbridge-private',
  false,
  10485760,
  ARRAY['image/jpeg','image/png','image/webp','application/pdf','text/plain','application/json']
)
ON CONFLICT (id) DO UPDATE SET
  public = false,
  file_size_limit = EXCLUDED.file_size_limit,
  allowed_mime_types = EXCLUDED.allowed_mime_types;



-- Server-only transactional RPC functions.
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


-- Cover composite foreign keys identified by Supabase performance advisors.
CREATE INDEX IF NOT EXISTS idx_chatbridge_attachments_message_fk
  ON public.chatbridge_attachments (tenant_id, conversation_id, message_id);
CREATE INDEX IF NOT EXISTS idx_chatbridge_guest_sessions_guest_fk
  ON public.chatbridge_guest_sessions (tenant_id, guest_id);
CREATE INDEX IF NOT EXISTS idx_chatbridge_rate_limits_tenant_fk
  ON public.chatbridge_rate_limits (tenant_id);
