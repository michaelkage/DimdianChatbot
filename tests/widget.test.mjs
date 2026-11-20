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

test('offline retry is opt-in, bounded and uses local browser storage', () => {
  assert.match(widget, /offlineQueue: script\.dataset\.offlineQueue === 'true'/);
  assert.match(widget, /localStorage\.getItem\(queueKey/);
  assert.match(widget, /queue\.length>=20/);
  assert.match(widget, /window\.addEventListener\('online'/);
});

test('visitor can create and switch between multiple tab-scoped conversations', () => {
  assert.match(widget, /conversation-select/);
  assert.match(widget, /new-conversation/);
  assert.match(widget, /conversations\.slice\(-10\)/);
  assert.match(widget, /function switchConversation/);
});

test('page visibility rules can hide or compact the widget without executing configured code', () => {
  assert.match(widget, /script\.dataset\.hidePaths/);
  assert.match(widget, /script\.dataset\.showPaths/);
  assert.match(widget, /document\.querySelector\(selector\)/);
  assert.match(widget, /compact-mode/);
  assert.doesNotMatch(widget, /eval\(/);
});

test('typing animation and human acknowledgement are independently configurable', () => {
  assert.match(widget, /typingIndicator: script\.dataset\.typingIndicator === 'true'/);
  assert.match(widget, /data-handover-message/);
  assert.match(css, /@keyframes cb-type/);
});

test('widget theming uses validated CSS custom properties and optional logo URL', () => {
  assert.match(widget, /--cb-primary/);
  assert.match(widget, /--cb-accent/);
  assert.match(widget, /script\.dataset\.logoUrl/);
});

test('standalone embed guide exists and never asks users to expose tenant credentials', () => {
  const guide = readFileSync(new URL('../docs/EMBED-INTEGRATION.md', import.meta.url), 'utf8');
  assert.match(guide, /does not require WordPress/i);
  assert.match(guide, /never add a tenant ID, API key, WhatsApp token or AI provider key/i);
});
