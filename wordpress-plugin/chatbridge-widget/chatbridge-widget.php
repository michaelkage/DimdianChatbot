<?php
/**
 * Plugin Name: ChatBridge Widget
 * Description: Add a lightweight website support chat widget connected to your ChatBridge service. Optional WhatsApp continuation is handled by your service.
 * Version: 0.1.0
 * Requires at least: 6.3
 * Requires PHP: 8.2
 * Author: ChatBridge
 * License: GPL-2.0-or-later
 * Text Domain: chatbridge-widget
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CHATBRIDGE_WIDGET_VERSION', '0.1.0');
define('CHATBRIDGE_WIDGET_FILE', __FILE__);

function chatbridge_widget_defaults(): array
{
    return [
        'enabled' => 0,
        'api_base' => '',
        'title' => 'Chat with us',
        'subtitle' => 'Send us a message. Our team will reply as soon as we can.',
    ];
}

function chatbridge_widget_settings(): array
{
    $saved = get_option('chatbridge_widget_settings', []);
    return wp_parse_args(is_array($saved) ? $saved : [], chatbridge_widget_defaults());
}

function chatbridge_widget_sanitize_settings($input): array
{
    $input = is_array($input) ? $input : [];
    $defaults = chatbridge_widget_defaults();
    $api_base = isset($input['api_base']) ? esc_url_raw(trim((string) $input['api_base']), ['https']) : '';
    $api_base = untrailingslashit($api_base);

    return [
        'enabled' => !empty($input['enabled']) ? 1 : 0,
        'api_base' => $api_base,
        'title' => isset($input['title']) ? sanitize_text_field($input['title']) : $defaults['title'],
        'subtitle' => isset($input['subtitle']) ? sanitize_text_field($input['subtitle']) : $defaults['subtitle'],
    ];
}

add_action('admin_init', static function (): void {
    register_setting(
        'chatbridge_widget',
        'chatbridge_widget_settings',
        [
            'type' => 'array',
            'sanitize_callback' => 'chatbridge_widget_sanitize_settings',
            'default' => chatbridge_widget_defaults(),
        ]
    );
});

add_action('admin_menu', static function (): void {
    add_options_page(
        __('ChatBridge Widget', 'chatbridge-widget'),
        __('ChatBridge Widget', 'chatbridge-widget'),
        'manage_options',
        'chatbridge-widget',
        'chatbridge_widget_render_settings_page'
    );
});

function chatbridge_widget_health_check(string $api_base)
{
    if ($api_base === '' || !wp_http_validate_url($api_base) || wp_parse_url($api_base, PHP_URL_SCHEME) !== 'https') {
        return new WP_Error('chatbridge_invalid_url', __('Enter a valid HTTPS API address first.', 'chatbridge-widget'));
    }

    // The health endpoint reports service/database/schema state only. It does not verify WhatsApp delivery.
    return wp_remote_get($api_base . '/health.php', [
        'timeout' => 8,
        'redirection' => 2,
        'sslverify' => true,
        'headers' => ['Accept' => 'application/json'],
    ]);
}

function chatbridge_widget_render_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = chatbridge_widget_settings();
    $health_result = null;

    if (isset($_GET['chatbridge_check'])) {
        check_admin_referer('chatbridge_widget_health_check');
        $health_result = chatbridge_widget_health_check($settings['api_base']);
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('ChatBridge Widget', 'chatbridge-widget'); ?></h1>
        <p><?php esc_html_e('Connect this WordPress site to a ChatBridge support service. The service handles chat sessions and, if configured there, WhatsApp message delivery.', 'chatbridge-widget'); ?></p>

        <?php if ($health_result !== null) : ?>
            <?php if (is_wp_error($health_result)) : ?>
                <div class="notice notice-error"><p><strong><?php esc_html_e('Connection check failed:', 'chatbridge-widget'); ?></strong> <?php echo esc_html($health_result->get_error_message()); ?></p></div>
            <?php else : ?>
                <?php
                $status_code = (int) wp_remote_retrieve_response_code($health_result);
                $body = json_decode(wp_remote_retrieve_body($health_result), true);
                $healthy = $status_code === 200 && is_array($body) && ($body['status'] ?? '') === 'healthy';
                ?>
                <div class="notice <?php echo $healthy ? 'notice-success' : 'notice-warning'; ?>">
                    <p>
                        <strong><?php echo $healthy ? esc_html__('Chat service is healthy.', 'chatbridge-widget') : esc_html__('Chat service needs attention.', 'chatbridge-widget'); ?></strong>
                        <?php
                        if (is_array($body)) {
                            echo esc_html(sprintf(
                                /* translators: %s: service status */
                                __('Reported status: %s. ', 'chatbridge-widget'),
                                sanitize_text_field((string) ($body['status'] ?? 'unknown'))
                            ));
                        } else {
                            esc_html_e('The endpoint did not return a readable health report. ', 'chatbridge-widget');
                        }
                        esc_html_e('This check does not test WordPress, WooCommerce, WCFM, Paystack or live WhatsApp delivery.', 'chatbridge-widget');
                        ?>
                    </p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('chatbridge_widget'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable widget', 'chatbridge-widget'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="chatbridge_widget_settings[enabled]" value="1" <?php checked($settings['enabled'], 1); ?>>
                            <?php esc_html_e('Show the chat launcher on the public website', 'chatbridge-widget'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="chatbridge-api-base"><?php esc_html_e('Chat API base URL', 'chatbridge-widget'); ?></label></th>
                    <td>
                        <input id="chatbridge-api-base" class="regular-text code" type="url" inputmode="url" name="chatbridge_widget_settings[api_base]" value="<?php echo esc_attr($settings['api_base']); ?>" placeholder="https://chat.example.com/api" required>
                        <p class="description"><?php esc_html_e('Use the API address supplied by your ChatBridge service administrator. HTTPS is required. Do not enter API keys or WhatsApp tokens here.', 'chatbridge-widget'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="chatbridge-title"><?php esc_html_e('Widget heading', 'chatbridge-widget'); ?></label></th>
                    <td><input id="chatbridge-title" class="regular-text" type="text" maxlength="80" name="chatbridge_widget_settings[title]" value="<?php echo esc_attr($settings['title']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="chatbridge-subtitle"><?php esc_html_e('Welcome text', 'chatbridge-widget'); ?></label></th>
                    <td><input id="chatbridge-subtitle" class="large-text" type="text" maxlength="160" name="chatbridge_widget_settings[subtitle]" value="<?php echo esc_attr($settings['subtitle']); ?>"></td>
                </tr>
            </table>
            <?php submit_button(__('Save settings', 'chatbridge-widget')); ?>
        </form>

        <hr>
        <h2><?php esc_html_e('Connection check', 'chatbridge-widget'); ?></h2>
        <p><?php esc_html_e('Save your settings, then check whether the configured chat API and its database/schema are responding.', 'chatbridge-widget'); ?></p>
        <?php
        $check_url = wp_nonce_url(
            add_query_arg(['page' => 'chatbridge-widget', 'chatbridge_check' => '1'], admin_url('options-general.php')),
            'chatbridge_widget_health_check'
        );
        ?>
        <p><a class="button button-secondary" href="<?php echo esc_url($check_url); ?>"><?php esc_html_e('Test chat connection', 'chatbridge-widget'); ?></a></p>
        <p class="description"><?php esc_html_e('A healthy result confirms the remote chat service health endpoint only. It does not send a WhatsApp message or test Meta credentials.', 'chatbridge-widget'); ?></p>

        <hr>
        <h2><?php esc_html_e('Need help?', 'chatbridge-widget'); ?></h2>
        <p><?php esc_html_e('If the test fails, copy the status and error text (never passwords or tokens) and send it to your website support person.', 'chatbridge-widget'); ?></p>
    </div>
    <?php
}

add_action('wp_enqueue_scripts', static function (): void {
    $settings = chatbridge_widget_settings();

    // Fail closed: do not inject a widget with an empty or non-HTTPS API endpoint.
    if (empty($settings['enabled']) || empty($settings['api_base'])) {
        return;
    }

    $api_base = untrailingslashit($settings['api_base']);
    $scheme = wp_parse_url($api_base, PHP_URL_SCHEME);
    if ($scheme !== 'https' || !wp_http_validate_url($api_base)) {
        return;
    }

    $service_root = preg_replace('~/api$~', '', $api_base);
    if (!is_string($service_root) || $service_root === '') {
        return;
    }

    $handle = 'chatbridge-widget';
    wp_enqueue_script(
        $handle,
        $service_root . '/widget.js',
        [],
        CHATBRIDGE_WIDGET_VERSION,
        ['strategy' => 'defer', 'in_footer' => true]
    );

    // Add data attributes to the one external script tag; no inline executable code or theme edits.
    add_filter('script_loader_tag', static function (string $tag, string $handle_name, string $src) use ($handle, $api_base, $settings): string {
        if ($handle_name !== $handle) {
            return $tag;
        }

        $attributes = sprintf(
            ' data-api-base="%1$s" data-title="%2$s" data-subtitle="%3$s"',
            esc_attr($api_base),
            esc_attr($settings['title']),
            esc_attr($settings['subtitle'])
        );

        return preg_replace('/<script\b/', '<script' . $attributes, $tag, 1) ?: $tag;
    }, 10, 3);
}, 20);
