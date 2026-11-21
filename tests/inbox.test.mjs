import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const html = readFileSync(new URL('../docs/inbox/index.html', import.meta.url), 'utf8');
const edge = readFileSync(new URL('../supabase/functions/chatbridge-mock-api/index.ts', import.meta.url), 'utf8');
const migration = readFileSync(new URL('../supabase/migrations/20261121120000_chatbridge_staff_inbox.sql', import.meta.url), 'utf8');
const auditMigration = readFileSync(new URL('../supabase/migrations/20261121140000_chatbridge_staff_audit_events.sql', import.meta.url), 'utf8');

test('agent inbox inline JavaScript parses without third-party dependencies', () => {
  const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)];
  assert.equal(scripts.length, 1);
  assert.doesNotThrow(() => new vm.Script(scripts[0][1]));
});

test('staff UI authenticates through Supabase Auth and stores tokens only in tab session storage', () => {
  assert.match(html, /token\?grant_type=password/);
  assert.match(html, /grant_type=refresh_token/);
  assert.match(html, /sessionStorage\.setItem/);
  assert.doesNotMatch(html, /SUPABASE_SERVICE_ROLE_KEY|sb_secret_|service_role/i);
});

test('staff UI lets the signed-in user request an email change through Supabase Auth', () => {
  assert.match(html, /Account settings/);
  assert.match(html, /Change sign-in email/);
  assert.match(html, /auth\/v1\/user/);
  assert.match(html, /method:'PATCH'/);
  assert.match(html, /JSON\.stringify\(\{email:nextEmail\}\)/);
  assert.match(html, /confirmation/i);
});

test('staff UI supports conversation history, replies, status, assignment and private attachments', () => {
  assert.match(html, /staff\/conversations/);
  assert.match(html, /staff_only/);
  assert.match(html, /Send reply/);
  assert.match(html, /Close conversation/);
  assert.match(html, /Assign to me/);
  assert.match(html, /noopener noreferrer/);
});

test('Edge Function verifies Auth identity and active tenant membership before staff routes', () => {
  assert.match(edge, /auth\/v1\/user/);
  assert.match(edge, /chatbridge_staff_memberships/);
  assert.match(edge, /authenticateStaff/);
  assert.match(edge, /route\.startsWith\("\/staff\/"\)/);
  assert.match(edge, /Access-Control-Allow-Headers.*Authorization/);
  assert.match(edge, /Access-Control-Allow-Methods.*PATCH/);
});

test('staff schema and RPCs enforce tenant membership and keep direct public table access revoked', () => {
  assert.match(migration, /REFERENCES auth\.users/);
  assert.match(migration, /ENABLE ROW LEVEL SECURITY/);
  assert.match(migration, /FROM PUBLIC, anon, authenticated/);
  assert.match(migration, /staff_membership_required/);
  assert.match(migration, /assignee_not_in_tenant/);
  assert.match(migration, /sender_type, body, delivery_status, metadata/);
});

test('staff inbox exposes tenant-scoped activity history without recording message content', () => {
  assert.match(edge, /staffConversationAudit/);
  assert.match(edge, /chatbridge_staff_audit_events/);
  assert.match(edge, /\/audit/);
  assert.match(html, /ACTIVITY HISTORY/);
  assert.match(html, /loadAudit/);
  assert.match(auditMigration, /ENABLE ROW LEVEL SECURITY/);
  assert.match(auditMigration, /FROM PUBLIC, anon, authenticated/);
  assert.match(auditMigration, /GRANT SELECT, INSERT ON TABLE public\.chatbridge_staff_audit_events TO service_role/);
  assert.match(auditMigration, /REVOKE ALL ON TABLE public\.chatbridge_staff_audit_events FROM service_role/);
  assert.match(auditMigration, /'reply_sent'/);
  assert.match(auditMigration, /'conversation_status_changed'/);
  assert.match(auditMigration, /'conversation_assignment_changed'/);
  assert.match(auditMigration, /reply_auto_assignment/);
  assert.match(auditMigration, /FOR UPDATE/);
  assert.match(auditMigration, /jsonb_build_object\('message_id', v_message\.id, 'channel', 'website'\)/);
  assert.doesNotMatch(auditMigration, /p_body\s*,\s*'reply_sent'/);
});

const retentionMigration = readFileSync(new URL('../supabase/migrations/20261121150000_chatbridge_retention_settings.sql', import.meta.url), 'utf8');
const retentionAuditMigration = readFileSync(new URL('../supabase/migrations/20261121154000_chatbridge_retention_audit_rpc.sql', import.meta.url), 'utf8');

test('retention settings default to fourteen days and support per-category indefinite retention', () => {
  assert.match(retentionMigration, /conversation_days integer NOT NULL DEFAULT 14/);
  assert.match(retentionMigration, /attachment_days integer NOT NULL DEFAULT 14/);
  assert.match(retentionMigration, /diagnostic_days integer NOT NULL DEFAULT 14/);
  assert.match(retentionMigration, /audit_days integer NOT NULL DEFAULT 14/);
  assert.match(retentionMigration, /BETWEEN 0 AND 3650/);
  assert.match(html, /retentionConversations/);
  assert.match(html, /retentionAttachments/);
  assert.match(html, /retentionDiagnostics/);
  assert.match(html, /retentionAudit/);
  assert.match(html, /Use 0 to keep a category indefinitely/);
  assert.match(edge, /Only tenant owners and admins can change data retention/);
  assert.match(edge, /Only tenant owners and admins can run data retention/);
  assert.match(edge, /expiredAttachments/);
  assert.match(edge, /expiredConversations/);
  assert.match(edge, /chatbridge_staff_audit_events/);
  assert.match(edge, /storage\("DELETE"/);
  assert.match(edge, /internal\/retention-cleanup/);
  assert.match(retentionMigration, /cron\.schedule/);
  assert.match(retentionMigration, /vault\.decrypted_secrets/);
});

test('retention API validates bounded day counts and keeps public database access revoked', () => {
  assert.match(edge, /invalid_retention_period/);
  assert.match(edge, /value > 3650/);
  assert.match(edge, /route === "\/staff\/retention"/);
  assert.match(edge, /route === "\/staff\/retention\/run"/);
  assert.match(retentionMigration, /ENABLE ROW LEVEL SECURITY/);
  assert.match(retentionMigration, /FROM PUBLIC, anon, authenticated/);
  assert.match(retentionMigration, /GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public\.chatbridge_retention_settings TO service_role/);
  assert.match(retentionMigration, /GRANT SELECT, INSERT ON TABLE public\.chatbridge_retention_runs TO service_role/);
});

test('audit retention uses a restricted server-only function instead of granting direct delete access', () => {
  assert.match(retentionAuditMigration, /SECURITY DEFINER/);
  assert.match(retentionAuditMigration, /SET search_path = ''/);
  assert.match(retentionAuditMigration, /s\.audit_days > 0/);
  assert.match(retentionAuditMigration, /e\.created_at < now\(\) - make_interval/);
  assert.match(retentionAuditMigration, /REVOKE ALL ON FUNCTION/);
  assert.match(retentionAuditMigration, /GRANT EXECUTE ON FUNCTION public\.chatbridge_retention_delete_audit_events\(uuid\) TO service_role/);
});
