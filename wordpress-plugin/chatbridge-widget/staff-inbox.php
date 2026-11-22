<?php
declare(strict_types=1);

if (!defined('ABSPATH')) exit;

add_action('admin_init', static function (): void {
    $administrator = get_role('administrator');
    if ($administrator && !$administrator->has_cap('chatbridge_access_inbox')) {
        $administrator->add_cap('chatbridge_access_inbox');
    }
});

add_action('admin_menu', static function (): void {
    add_menu_page(
        __('ChatBridge Inbox', 'chatbridge-widget'),
        __('ChatBridge Inbox', 'chatbridge-widget'),
        'chatbridge_access_inbox',
        'chatbridge-inbox',
        'chatbridge_staff_inbox_render_page',
        'dashicons-format-chat',
        58
    );
});

function chatbridge_staff_inbox_is_configured(): bool
{
    $settings = chatbridge_widget_settings();
    return !empty($settings['staff_inbox_enabled'])
        && strlen((string)($settings['staff_assertion_secret'] ?? '')) >= 32
        && !empty($settings['api_base'])
        && wp_parse_url((string)$settings['api_base'], PHP_URL_SCHEME) === 'https';
}

function chatbridge_staff_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/**
 * Server-side WordPress proxy. The shared HMAC secret never reaches the browser.
 */
function chatbridge_staff_proxy_request(string $method, string $endpoint, array $payload = [], array $query = [])
{
    $settings = chatbridge_widget_settings();
    $apiBase = untrailingslashit((string)($settings['api_base'] ?? ''));
    $secret = (string)($settings['staff_assertion_secret'] ?? '');
    if ($apiBase === '' || !wp_http_validate_url($apiBase) || wp_parse_url($apiBase, PHP_URL_SCHEME) !== 'https') {
        return new WP_Error('chatbridge_staff_api_url', __('Configure a valid HTTPS Chat API address first.', 'chatbridge-widget'));
    }
    if (empty($settings['staff_inbox_enabled']) || strlen($secret) < 32) {
        return new WP_Error('chatbridge_staff_not_configured', __('Enable the staff inbox and save its private assertion secret first.', 'chatbridge-widget'));
    }

    $user = wp_get_current_user();
    if (!$user || (int)$user->ID < 1) {
        return new WP_Error('chatbridge_staff_identity', __('A signed-in WordPress staff account is required.', 'chatbridge-widget'));
    }

    $method = strtoupper($method);
    $endpoint = trim($endpoint, '/');
    if (!preg_match('/^[a-z-]+\.php$/', $endpoint)) {
        return new WP_Error('chatbridge_staff_endpoint', __('Invalid staff endpoint.', 'chatbridge-widget'));
    }

    $basePath = (string)wp_parse_url($apiBase, PHP_URL_PATH);
    $requestPath = rtrim($basePath, '/') . '/v1/staff/' . $endpoint;
    if ($requestPath === '') $requestPath = '/v1/staff/' . $endpoint;
    $queryString = $query !== [] ? http_build_query($query) : '';
    $requestTarget = $requestPath . ($queryString !== '' ? '?' . $queryString : '');
    $requestBody = '';
    if ($method !== 'GET') {
        $encodedBody = wp_json_encode($payload);
        if (!is_string($encodedBody)) {
            return new WP_Error('chatbridge_staff_payload', __('The staff request could not be encoded.', 'chatbridge-widget'));
        }
        $requestBody = $encodedBody;
    }

    $assertion = [
        'auth_provider' => 'wordpress',
        'external_subject' => 'user:' . (int)$user->ID,
        'display_name' => (string)($user->display_name ?: $user->user_login),
        'email' => $user->user_email !== '' ? (string)$user->user_email : null,
        'iat' => time(),
        'nonce' => bin2hex(random_bytes(32)),
        'method' => $method,
        'target' => $requestTarget,
        'body_sha256' => hash('sha256', $requestBody),
    ];
    $encodedPayload = chatbridge_staff_base64url(wp_json_encode($assertion));
    $signature = chatbridge_staff_base64url(hash_hmac('sha256', $encodedPayload, $secret, true));
    $headers = [
        'Accept' => 'application/json',
        'X-ChatBridge-Staff-Assertion' => $encodedPayload . '.' . $signature,
    ];

    $url = $apiBase . '/v1/staff/' . $endpoint;
    if ($queryString !== '') $url .= '?' . $queryString;
    $args = [
        'method' => $method,
        'timeout' => 15,
        'redirection' => 0,
        'sslverify' => true,
        'headers' => $headers,
    ];
    if ($method !== 'GET') {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = $requestBody;
    }

    $response = wp_safe_remote_request($url, $args);
    if (is_wp_error($response)) {
        return new WP_Error('chatbridge_staff_transport', __('The ChatBridge service could not be reached securely.', 'chatbridge-widget'));
    }
    $status = (int)wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300 || !is_array($body) || !array_key_exists('data', $body)) {
        $message = is_array($body['error'] ?? null) ? (string)($body['error']['message'] ?? '') : '';
        if ($status === 401) $message = __('Staff authentication failed. Check the shared assertion secret and server clock.', 'chatbridge-widget');
        elseif ($status === 403) $message = __('Your ChatBridge staff role does not allow this operation.', 'chatbridge-widget');
        elseif ($status === 404 || $status === 503) $message = __('The staff inbox is not enabled or configured on the ChatBridge service.', 'chatbridge-widget');
        return new WP_Error('chatbridge_staff_request_failed', $message !== '' ? sanitize_text_field($message) : __('The staff request was rejected.', 'chatbridge-widget'));
    }
    return $body['data'];
}

function chatbridge_staff_ajax_guard(): void
{
    check_ajax_referer('chatbridge_staff_inbox', 'nonce');
    if (!current_user_can('chatbridge_access_inbox') && !current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Staff inbox access is required.', 'chatbridge-widget')], 403);
    }
    if (!chatbridge_staff_inbox_is_configured()) {
        wp_send_json_error(['message' => __('Enable and configure the optional staff inbox in ChatBridge settings first.', 'chatbridge-widget')], 503);
    }
}

function chatbridge_staff_ajax_forward(string $method, string $endpoint, array $payload = [], array $query = []): void
{
    chatbridge_staff_ajax_guard();
    $result = chatbridge_staff_proxy_request($method, $endpoint, $payload, $query);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 400);
    }
    wp_send_json_success($result);
}

add_action('wp_ajax_chatbridge_staff_me', static function (): void {
    chatbridge_staff_ajax_forward('GET', 'me.php');
});
add_action('wp_ajax_chatbridge_staff_inbox', static function (): void {
    $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'open';
    $after = isset($_POST['after']) ? absint($_POST['after']) : 0;
    $limit = isset($_POST['limit']) ? min(100, max(1, absint($_POST['limit']))) : 50;
    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    chatbridge_staff_ajax_forward('GET', 'inbox.php', [], ['status' => $status, 'after' => $after, 'limit' => $limit, 'search' => $search]);
});
add_action('wp_ajax_chatbridge_staff_agents', static function (): void {
    chatbridge_staff_ajax_forward('GET', 'agents.php');
});
add_action('wp_ajax_chatbridge_staff_messages', static function (): void {
    $conversation = isset($_POST['conversation_id']) ? sanitize_text_field(wp_unslash($_POST['conversation_id'])) : '';
    $after = isset($_POST['after']) ? absint($_POST['after']) : 0;
    chatbridge_staff_ajax_forward('GET', 'messages.php', [], ['conversation_id' => $conversation, 'after' => $after, 'limit' => 100]);
});
add_action('wp_ajax_chatbridge_staff_notes', static function (): void {
    $conversation = isset($_POST['conversation_id']) ? sanitize_text_field(wp_unslash($_POST['conversation_id'])) : '';
    $after = isset($_POST['after']) ? absint($_POST['after']) : 0;
    chatbridge_staff_ajax_forward('GET', 'notes.php', [], ['conversation_id' => $conversation, 'after' => $after, 'limit' => 100]);
});
add_action('wp_ajax_chatbridge_staff_note_create', static function (): void {
    $conversation = isset($_POST['conversation_id']) ? sanitize_text_field(wp_unslash($_POST['conversation_id'])) : '';
    $body = isset($_POST['body']) ? sanitize_textarea_field(wp_unslash($_POST['body'])) : '';
    chatbridge_staff_ajax_forward('POST', 'notes.php', ['conversation_id' => $conversation, 'body' => $body]);
});
add_action('wp_ajax_chatbridge_staff_reply', static function (): void {
    $conversation = isset($_POST['conversation_id']) ? sanitize_text_field(wp_unslash($_POST['conversation_id'])) : '';
    $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
    $clientId = isset($_POST['client_message_id']) ? sanitize_text_field(wp_unslash($_POST['client_message_id'])) : '';
    if ($clientId === '') $clientId = wp_generate_uuid4();
    chatbridge_staff_ajax_forward('POST', 'replies.php', [
        'conversation_id' => $conversation,
        'message' => $message,
        'client_message_id' => $clientId,
    ]);
});
add_action('wp_ajax_chatbridge_staff_assign', static function (): void {
    $conversation = isset($_POST['conversation_id']) ? sanitize_text_field(wp_unslash($_POST['conversation_id'])) : '';
    $staffId = isset($_POST['staff_identity_id']) ? sanitize_text_field(wp_unslash($_POST['staff_identity_id'])) : '';
    $action = isset($_POST['assignment_action']) ? sanitize_key(wp_unslash($_POST['assignment_action'])) : 'assign';
    $payload = ['conversation_id' => $conversation, 'action' => $action];
    if ($action === 'assign') $payload['staff_identity_id'] = $staffId;
    chatbridge_staff_ajax_forward('POST', 'assignments.php', $payload);
});
add_action('wp_ajax_chatbridge_staff_role_update', static function (): void {
    $staffId = isset($_POST['staff_identity_id']) ? sanitize_text_field(wp_unslash($_POST['staff_identity_id'])) : '';
    $role = isset($_POST['role']) ? sanitize_key(wp_unslash($_POST['role'])) : '';
    $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
    chatbridge_staff_ajax_forward('POST', 'roles.php', [
        'staff_identity_id' => $staffId,
        'role' => $role,
        'status' => $status,
    ]);
});
add_action('wp_ajax_chatbridge_staff_audit', static function (): void {
    $conversation = isset($_POST['conversation_id']) ? sanitize_text_field(wp_unslash($_POST['conversation_id'])) : '';
    chatbridge_staff_ajax_forward('GET', 'audit.php', [], ['conversation_id' => $conversation, 'after' => 0, 'limit' => 50]);
});

add_action('admin_enqueue_scripts', static function (string $hook): void {
    if ($hook !== 'toplevel_page_chatbridge-inbox') return;
    wp_enqueue_script(
        'chatbridge-staff-inbox-admin',
        plugins_url('assets/staff-inbox-admin.js', __FILE__),
        [],
        CHATBRIDGE_WIDGET_VERSION,
        true
    );
    wp_enqueue_style(
        'chatbridge-staff-inbox-admin',
        plugins_url('assets/staff-inbox-admin.css', __FILE__),
        [],
        CHATBRIDGE_WIDGET_VERSION
    );
    wp_localize_script('chatbridge-staff-inbox-admin', 'ChatBridgeStaffInbox', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('chatbridge_staff_inbox'),
    ]);
});

function chatbridge_staff_inbox_render_page(): void
{
    if (!current_user_can('chatbridge_access_inbox') && !current_user_can('manage_options')) return;
    ?>
    <div class="wrap cb-staff-wrap">
        <h1><?php esc_html_e('ChatBridge Inbox', 'chatbridge-widget'); ?></h1>
        <?php if (!chatbridge_staff_inbox_is_configured()) : ?>
            <div class="notice notice-warning"><p><?php esc_html_e('The staff inbox is not configured. Enable it and save the shared assertion secret under Settings → ChatBridge Widget, then enable the optional schema and staff inbox on your ChatBridge service.', 'chatbridge-widget'); ?></p></div>
        <?php endif; ?>
        <div id="chatbridge-staff-inbox" class="cb-staff-app">
            <section id="cb-staff-role-panel" class="cb-staff-role-panel" hidden>
                <h2><?php esc_html_e('Staff access', 'chatbridge-widget'); ?></h2>
                <p><?php esc_html_e('Only the owner can change staff roles or disable accounts. New identities start as agents.', 'chatbridge-widget'); ?></p>
                <div id="cb-staff-role-list"><p class="cb-staff-empty"><?php esc_html_e('Loading staff…', 'chatbridge-widget'); ?></p></div>
            </section>
            <div class="cb-staff-toolbar">
                <label for="cb-staff-status"><?php esc_html_e('Conversations', 'chatbridge-widget'); ?></label>
                <input type="search" id="cb-staff-search" maxlength="100" placeholder="<?php esc_attr_e('Search name, phone or message…', 'chatbridge-widget'); ?>" />
                <select id="cb-staff-status">
                    <option value="open"><?php esc_html_e('Open', 'chatbridge-widget'); ?></option>
                    <option value="closed"><?php esc_html_e('Closed', 'chatbridge-widget'); ?></option>
                    <option value="all"><?php esc_html_e('All', 'chatbridge-widget'); ?></option>
                </select>
                <button type="button" class="button" id="cb-staff-refresh"><?php esc_html_e('Refresh', 'chatbridge-widget'); ?></button>
                <span id="cb-staff-status-text" role="status" aria-live="polite"></span>
            </div>
            <div class="cb-staff-layout">
                <aside class="cb-staff-list" aria-label="<?php esc_attr_e('Conversation list', 'chatbridge-widget'); ?>">
                    <div id="cb-staff-conversations"><p class="cb-staff-empty"><?php esc_html_e('Loading conversations…', 'chatbridge-widget'); ?></p></div>
                </aside>
                <section class="cb-staff-detail" aria-label="<?php esc_attr_e('Conversation details', 'chatbridge-widget'); ?>">
                    <header class="cb-staff-detail-header">
                        <div><h2 id="cb-staff-thread-title"><?php esc_html_e('Select a conversation', 'chatbridge-widget'); ?></h2><p id="cb-staff-thread-subtitle"></p></div>
                        <div class="cb-staff-assignment">
                            <select id="cb-staff-assignee" aria-label="<?php esc_attr_e('Assign conversation', 'chatbridge-widget'); ?>" hidden></select>
                            <button type="button" class="button" id="cb-staff-self-assign" hidden><?php esc_html_e('Assign to me', 'chatbridge-widget'); ?></button>
                            <button type="button" class="button" id="cb-staff-unassign" hidden><?php esc_html_e('Unassign', 'chatbridge-widget'); ?></button>
                        </div>
                    </header>
                    <div id="cb-staff-messages" class="cb-staff-messages"><p class="cb-staff-empty"><?php esc_html_e('Messages will appear here.', 'chatbridge-widget'); ?></p></div>
                    <div class="cb-staff-compose">
                        <label for="cb-staff-reply"><?php esc_html_e('Reply to customer', 'chatbridge-widget'); ?></label>
                        <textarea id="cb-staff-reply" rows="3" maxlength="5000" placeholder="<?php esc_attr_e('Write a website reply…', 'chatbridge-widget'); ?>"></textarea>
                        <button type="button" class="button button-primary" id="cb-staff-send"><?php esc_html_e('Send reply', 'chatbridge-widget'); ?></button>
                    </div>
                    <details class="cb-staff-notes" open>
                        <summary><?php esc_html_e('Private internal notes', 'chatbridge-widget'); ?></summary>
                        <div id="cb-staff-note-list"></div>
                        <textarea id="cb-staff-note" rows="2" maxlength="10000" placeholder="<?php esc_attr_e('Add a note visible only to staff…', 'chatbridge-widget'); ?>"></textarea>
                        <button type="button" class="button" id="cb-staff-add-note"><?php esc_html_e('Add private note', 'chatbridge-widget'); ?></button>
                    </details>
                </section>
            </div>
        </div>
    </div>
    <?php
}
