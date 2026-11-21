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
