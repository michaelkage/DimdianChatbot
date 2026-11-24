import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const plugin = readFileSync(new URL('../wordpress-plugin/chatbridge-widget/chatbridge-widget.php', import.meta.url), 'utf8');
const staff = readFileSync(new URL('../wordpress-plugin/chatbridge-widget/staff-inbox.php', import.meta.url), 'utf8');

test('Meta AJAX actions verify the WordPress nonce and administrator capability', () => {
  assert.match(plugin, /function chatbridge_meta_ajax_guard\(\)[\s\S]*check_ajax_referer\('chatbridge_meta_signup', 'nonce'\)[\s\S]*current_user_can\('manage_options'\)/);
  for (const action of ['begin', 'exchange', 'subscribe']) {
    const route = new RegExp("add_action\\('wp_ajax_chatbridge_meta_" + action + "'[\\s\\S]*?chatbridge_meta_ajax_guard\\(\\);");
    assert.match(plugin, route, 'Meta AJAX action ' + action + ' must call the shared guard');
  }
});

test('Staff AJAX forwarding verifies nonce, capability and feature configuration before proxying', () => {
  assert.match(staff, /function chatbridge_staff_ajax_guard\(\)[\s\S]*check_ajax_referer\('chatbridge_staff_inbox', 'nonce'\)[\s\S]*current_user_can\('chatbridge_access_inbox'\)[\s\S]*chatbridge_staff_inbox_is_configured\(\)/);
  assert.match(staff, /function chatbridge_staff_ajax_forward\([\s\S]*chatbridge_staff_ajax_guard\(\);[\s\S]*chatbridge_staff_proxy_request\(/);
  const handlers = [...staff.matchAll(/add_action\('wp_ajax_(chatbridge_staff_[a-z_]+)'[\s\S]*?\}\);/g)];
  assert.ok(handlers.length >= 8, 'expected all staff AJAX handlers to be registered');
  for (const handler of handlers) {
    assert.match(handler[0], /chatbridge_staff_ajax_forward\(/, handler[1] + ' must route through the guarded forwarder');
  }
});

test('WooCommerce IP rate limiting unslashes and sanitizes the remote address', () => {
  assert.match(plugin, /sanitize_text_field\(wp_unslash\(\$_SERVER\['REMOTE_ADDR'\]\)\)/);
});
