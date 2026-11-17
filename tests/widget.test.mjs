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
