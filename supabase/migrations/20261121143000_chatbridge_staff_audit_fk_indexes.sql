-- Cover the two audit-event foreign keys independently of the tenant-scoped feed index.
CREATE INDEX IF NOT EXISTS idx_chatbridge_staff_audit_conversation_fk
  ON public.chatbridge_staff_audit_events(conversation_id);

CREATE INDEX IF NOT EXISTS idx_chatbridge_staff_audit_actor_fk
  ON public.chatbridge_staff_audit_events(actor_user_id);
