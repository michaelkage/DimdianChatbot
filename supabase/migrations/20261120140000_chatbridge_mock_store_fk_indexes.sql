-- Cover composite foreign keys identified by Supabase performance advisors.
CREATE INDEX IF NOT EXISTS idx_chatbridge_attachments_message_fk
  ON public.chatbridge_attachments (tenant_id, conversation_id, message_id);
CREATE INDEX IF NOT EXISTS idx_chatbridge_guest_sessions_guest_fk
  ON public.chatbridge_guest_sessions (tenant_id, guest_id);
CREATE INDEX IF NOT EXISTS idx_chatbridge_rate_limits_tenant_fk
  ON public.chatbridge_rate_limits (tenant_id);
