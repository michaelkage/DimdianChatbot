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

