-- Keep staff audit history append-only for ordinary service operations.
-- Retention can remove only events older than each tenant's configured audit period.

CREATE OR REPLACE FUNCTION public.chatbridge_retention_delete_audit_events(p_tenant_id uuid)
RETURNS integer
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = ''
AS $retention$
DECLARE
  v_deleted integer := 0;
BEGIN
  DELETE FROM public.chatbridge_staff_audit_events AS e
  USING public.chatbridge_retention_settings AS s
  WHERE s.tenant_id = p_tenant_id
    AND e.tenant_id = s.tenant_id
    AND s.audit_days > 0
    AND e.created_at < now() - make_interval(days => s.audit_days);
  GET DIAGNOSTICS v_deleted = ROW_COUNT;
  RETURN v_deleted;
END;
$retention$;

REVOKE ALL ON FUNCTION public.chatbridge_retention_delete_audit_events(uuid) FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.chatbridge_retention_delete_audit_events(uuid) TO service_role;
