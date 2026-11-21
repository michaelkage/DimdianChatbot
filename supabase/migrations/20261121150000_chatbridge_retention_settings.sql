-- Tenant-configurable retention for conversations, uploads, diagnostics and staff audit history.

CREATE EXTENSION IF NOT EXISTS pg_cron WITH SCHEMA pg_catalog;
GRANT USAGE ON SCHEMA cron TO postgres;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA cron TO postgres;
CREATE EXTENSION IF NOT EXISTS pg_net WITH SCHEMA extensions;
-- A value of 0 means keep indefinitely. Defaults are 14 days for every category.

CREATE TABLE IF NOT EXISTS public.chatbridge_retention_settings (
  tenant_id uuid PRIMARY KEY REFERENCES public.chatbridge_tenants(id) ON DELETE CASCADE,
  conversation_days integer NOT NULL DEFAULT 14 CHECK (conversation_days BETWEEN 0 AND 3650),
  attachment_days integer NOT NULL DEFAULT 14 CHECK (attachment_days BETWEEN 0 AND 3650),
  diagnostic_days integer NOT NULL DEFAULT 14 CHECK (diagnostic_days BETWEEN 0 AND 3650),
  audit_days integer NOT NULL DEFAULT 14 CHECK (audit_days BETWEEN 0 AND 3650),
  updated_by uuid REFERENCES auth.users(id) ON DELETE SET NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

INSERT INTO public.chatbridge_retention_settings (tenant_id)
SELECT id FROM public.chatbridge_tenants
ON CONFLICT (tenant_id) DO NOTHING;

CREATE OR REPLACE FUNCTION public.chatbridge_seed_retention_settings()
RETURNS trigger
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public
AS $seed$
BEGIN
  INSERT INTO public.chatbridge_retention_settings (tenant_id)
  VALUES (NEW.id)
  ON CONFLICT (tenant_id) DO NOTHING;
  RETURN NEW;
END;
$seed$;

DROP TRIGGER IF EXISTS chatbridge_tenant_seed_retention ON public.chatbridge_tenants;
CREATE TRIGGER chatbridge_tenant_seed_retention
AFTER INSERT ON public.chatbridge_tenants
FOR EACH ROW EXECUTE FUNCTION public.chatbridge_seed_retention_settings();

CREATE INDEX IF NOT EXISTS idx_chatbridge_retention_settings_updated_by
  ON public.chatbridge_retention_settings(updated_by);

CREATE TABLE IF NOT EXISTS public.chatbridge_retention_runs (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id uuid NOT NULL REFERENCES public.chatbridge_tenants(id) ON DELETE CASCADE,
  conversations_deleted integer NOT NULL DEFAULT 0,
  attachments_deleted integer NOT NULL DEFAULT 0,
  audit_events_deleted integer NOT NULL DEFAULT 0,
  failures integer NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_chatbridge_retention_runs_tenant_created
  ON public.chatbridge_retention_runs(tenant_id, created_at DESC);

ALTER TABLE public.chatbridge_retention_settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.chatbridge_retention_runs ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE public.chatbridge_retention_settings FROM PUBLIC, anon, authenticated;
REVOKE ALL ON TABLE public.chatbridge_retention_runs FROM PUBLIC, anon, authenticated;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.chatbridge_retention_settings TO service_role;
GRANT SELECT, INSERT ON TABLE public.chatbridge_retention_runs TO service_role;
GRANT USAGE, SELECT ON SEQUENCE public.chatbridge_retention_runs_id_seq TO service_role;

-- The cleanup Edge Function can retrieve the shared cron secret only with the service-role key.
CREATE OR REPLACE FUNCTION public.chatbridge_get_retention_secret()
RETURNS text
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = ''
AS $$
  SELECT decrypted_secret
  FROM vault.decrypted_secrets
  WHERE name = 'chatbridge_retention_secret'
  LIMIT 1;
$$;
REVOKE ALL ON FUNCTION public.chatbridge_get_retention_secret() FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.chatbridge_get_retention_secret() TO service_role;

-- Store the invocation details and a random bearer secret in Vault, not in source control.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM vault.decrypted_secrets WHERE name = 'chatbridge_retention_secret') THEN
    PERFORM vault.create_secret(encode(extensions.gen_random_bytes(32), 'hex'), 'chatbridge_retention_secret', 'Internal authorization for scheduled Dimdian retention cleanup');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM vault.decrypted_secrets WHERE name = 'chatbridge_retention_project_url') THEN
    PERFORM vault.create_secret('https://gpafuuoxrbzveaddomgt.supabase.co', 'chatbridge_retention_project_url', 'Supabase project URL used by the retention cron job');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM vault.decrypted_secrets WHERE name = 'chatbridge_retention_publishable_key') THEN
    PERFORM vault.create_secret('sb_publishable_NdGKg0v6WajasP8G7yQbjg_X1KqZEwA', 'chatbridge_retention_publishable_key', 'Publishable key used by the retention cron job gateway request');
  END IF;
END;
$$;

-- Daily at 03:15 UTC (04:15 Lagos time). Re-running this migration replaces the same named job.
SELECT cron.schedule(
  'chatbridge-retention-cleanup',
  '15 3 * * *',
  $job$
    SELECT net.http_post(
      url := (SELECT decrypted_secret FROM vault.decrypted_secrets WHERE name = 'chatbridge_retention_project_url')
        || '/functions/v1/chatbridge-mock-api/internal/retention-cleanup',
      headers := jsonb_build_object(
        'Content-Type', 'application/json',
        'apikey', (SELECT decrypted_secret FROM vault.decrypted_secrets WHERE name = 'chatbridge_retention_publishable_key'),
        'Authorization', 'Bearer ' || (SELECT decrypted_secret FROM vault.decrypted_secrets WHERE name = 'chatbridge_retention_publishable_key'),
        'x-chatbridge-retention-secret', (SELECT decrypted_secret FROM vault.decrypted_secrets WHERE name = 'chatbridge_retention_secret')
      ),
      body := jsonb_build_object('source', 'scheduled'),
      timeout_milliseconds := 120000
    );
  $job$
);
