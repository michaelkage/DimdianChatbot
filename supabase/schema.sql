-- Supabase schema for the GitHub Pages WhatsApp integration test.
-- This is separate from the production Hostinger/MySQL database.
-- Run once in the SQL Editor after creating the Supabase project.

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

-- The table is not a public browser API. Only the Edge Function's server-side
-- service credential can insert/read events. Do not add anon/authenticated policies.
revoke all on table public.meta_webhook_events from anon, authenticated;
grant select, insert on table public.meta_webhook_events to service_role;
grant usage, select on sequence public.meta_webhook_events_id_seq to service_role;
