CREATE INDEX IF NOT EXISTS idx_chatbridge_conversations_assigned_to
  ON public.chatbridge_conversations(assigned_to);
