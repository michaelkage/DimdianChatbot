import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const widget = readFileSync(new URL('../public/widget.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../public/widget.css', import.meta.url), 'utf8');

test('widget JavaScript parses without a browser or third-party package', () => {
  assert.doesNotThrow(() => new vm.Script(widget));
});

test('widget keeps guest credentials in tab-scoped storage', () => {
  assert.match(widget, /sessionStorage\.getItem/);
  assert.match(widget, /sessionStorage\.setItem/);
  assert.match(widget, /X-DIMDIAN-GUEST-TOKEN/);
  assert.doesNotMatch(widget, /[?&]token=/i);
});

test('WhatsApp consent is explicit and phone remains optional', () => {
  assert.match(widget, /wa-opt-in/);
  assert.match(widget, /whatsapp_opt_in/);
  assert.match(widget, /Phone <small>optional<\/small>/);
});

test('message content is rendered as text, not interpreted as HTML', () => {
  assert.match(widget, /body\.textContent=String\(m\.message\|\|''\)/);
  assert.match(widget, /label\.textContent/);
});

test('delivery states are refreshed while polling', () => {
  assert.match(widget, /applyDeliveryUpdates/);
  assert.match(widget, /delivery_updates/);
  assert.match(widget, /web_only:'Website only'/);
});

test('styles are isolated and mobile layout is present', () => {
  assert.match(widget, /attachShadow/);
  assert.match(css, /@media\(max-width:520px\)/);
  assert.match(css, /\.wa-consent/);
});


test('offline outbox is disabled unless the embed explicitly opts in', () => {
  assert.match(widget, /offlineQueueEnabled = script\.dataset\.offlineQueue === 'true'/);
  assert.match(widget, /if\(!offlineQueueEnabled\)return \[\]/);
});

test('offline outbox validates and bounds saved messages to twenty', () => {
  assert.match(widget, /\.slice\(0,20\)/);
  assert.match(widget, /queue\.length>=20/);
  assert.match(widget, /item\.text\.length<=4000/);
});

test('composer clears only after the outbox write succeeds', () => {
  const start = widget.indexOf('function queueMessage(text,id)');
  const end = widget.indexOf('async function deliverQueued', start);
  const queueMessage = widget.slice(start, end);
  assert.ok(start >= 0 && end > start);
  const persistIndex = queueMessage.indexOf('if(!writeQueue(queue.concat(item)))return false;');
  const clearIndex = queueMessage.indexOf("input.value=''", persistIndex);
  assert.ok(persistIndex >= 0 && clearIndex > persistIndex);
  assert.match(widget, /function writeQueue\(items\)[\s\S]*return true[\s\S]*return false/);
});

test('queued delivery reuses the same idempotency key and avoids treating a content conflict as session expiry', () => {
  assert.match(widget, /client_message_id:item\.id/);
  assert.match(widget, /error\.code!=='idempotency_conflict'/);
  assert.match(widget, /e\.code!=='idempotency_conflict'/);
});

test('transient API failures schedule bounded retries while permanent failures remain queued', () => {
  assert.match(widget, /queueRetryDelay=Math\.min\(30000,queueRetryDelay\*2\)/);
  assert.match(widget, /error\.status===429\|\|error\.status>=500/);
  assert.match(widget, /scheduleQueueRetry\(\)/);
});

test('queued message placeholders are restored when the visible conversation is cleared', () => {
  assert.match(widget, /function clear\(\)[^\n]*queuedNodes\.clear\(\)[^\n]*readQueue\(\)\.forEach\(showQueued\)/);
});
