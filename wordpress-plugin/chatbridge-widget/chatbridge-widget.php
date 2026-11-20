<?php
/**
 * Plugin Name: ChatBridge Widget
 * Description: Add a lightweight support chat widget connected to ChatBridge, with optional WooCommerce, WCFM and Meta onboarding integrations.
 * Plugin URI: https://github.com/michaelkage/DimdianChatbot/tree/main/wordpress-plugin/chatbridge-widget
 * Version: 0.4.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: ChatBridge Contributors
 * Author URI: https://github.com/michaelkage/DimdianChatbot
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: chatbridge-widget
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CHATBRIDGE_WIDGET_VERSION', '0.4.0');
define('CHATBRIDGE_WIDGET_FILE', __FILE__);

add_action('init', static function (): void {
    load_plugin_textdomain('chatbridge-widget', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

add_action('admin_init', static function (): void {
    if (!function_exists('wp_add_privacy_policy_content')) {
        return;
    }
    $content = '<p>' . esc_html__('The ChatBridge Widget loads a support chat interface from the ChatBridge service configured by this site. When a visitor uses the chat, their messages and any details they choose to submit (such as a name or WhatsApp number) are sent to that service and processed according to the service operator’s privacy policy and retention settings.', 'chatbridge-widget') . '</p>';
    $content .= '<p>' . esc_html__('If WhatsApp continuation is enabled, a visitor’s phone number is sent to the ChatBridge service only after the visitor explicitly opts in. The service may then process the message through Meta WhatsApp Business Platform. This plugin does not store chat conversations or WhatsApp access tokens in the WordPress database.', 'chatbridge-widget') . '</p>';
    $content .= '<p>' . esc_html__('The site administrator may store a tenant API key in WordPress for server-to-server administrative requests. It is not exposed to public visitors or the widget JavaScript. If an administrator starts Meta Embedded Signup, the settings page loads the Facebook JavaScript SDK and the administrator interacts directly with Meta to select and authorize business assets.', 'chatbridge-widget') . '</p>';
    wp_add_privacy_policy_content(__('ChatBridge Widget', 'chatbridge-widget'), wp_kses_post($content));
}, 20);

function chatbridge_widget_defaults(): array
{
    return [
        'enabled' => 0,
        'woocommerce_enabled' => 0,
        'woocommerce_orders_enabled' => 0,
        'api_base' => '',
        'title' => 'Chat with us',
        'subtitle' => 'Send us a message. Our team will reply as soon as we can.',
        'launcher_text' => 'Message us',
        'logo_url' => '',
        'primary_color' => '#171717',
        'accent_color' => '#d7eee6',
        'offline_queue_enabled' => 0,
        'typing_indicator_enabled' => 0,
        'hide_paths' => '',
        'show_paths' => '',
        'hide_selectors' => '',
        'compact_selectors' => '',
        'handover_ack_enabled' => 0,
        'handover_ack_text' => 'We have received your request. A support agent will be with you as soon as possible.',
    ];
}

function chatbridge_widget_settings(): array
{
    $saved = get_option('chatbridge_widget_settings', []);
    $settings = wp_parse_args(is_array($saved) ? $saved : [], chatbridge_widget_defaults());
    $settings['tenant_api_key'] = (string) get_option('chatbridge_widget_tenant_api_key', '');
    return $settings;
}

function chatbridge_widget_sanitize_settings($input): array
{
    $input = is_array($input) ? $input : [];
    $defaults = chatbridge_widget_defaults();
    $api_base = isset($input['api_base']) ? esc_url_raw(trim((string) $input['api_base']), ['https']) : '';
    $api_base = untrailingslashit($api_base);
    $tenant_api_key = isset($input['tenant_api_key']) ? trim((string) $input['tenant_api_key']) : '';
    if ($tenant_api_key !== '' && preg_match('/^[a-f0-9]{64}$/i', $tenant_api_key)) {
        update_option('chatbridge_widget_tenant_api_key', $tenant_api_key, false);
    }

    return [
        'enabled' => !empty($input['enabled']) ? 1 : 0,
        'woocommerce_enabled' => !empty($input['woocommerce_enabled']) ? 1 : 0,
        'woocommerce_orders_enabled' => !empty($input['woocommerce_orders_enabled']) ? 1 : 0,
        'api_base' => $api_base,
        'title' => isset($input['title']) ? sanitize_text_field($input['title']) : $defaults['title'],
        'subtitle' => isset($input['subtitle']) ? sanitize_text_field($input['subtitle']) : $defaults['subtitle'],
        'launcher_text' => isset($input['launcher_text']) ? sanitize_text_field($input['launcher_text']) : $defaults['launcher_text'],
        'logo_url' => isset($input['logo_url']) ? esc_url_raw($input['logo_url'], ['https']) : '',
        'primary_color' => isset($input['primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $input['primary_color']) ? strtoupper((string) $input['primary_color']) : $defaults['primary_color'],
        'accent_color' => isset($input['accent_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $input['accent_color']) ? strtoupper((string) $input['accent_color']) : $defaults['accent_color'],
        'offline_queue_enabled' => !empty($input['offline_queue_enabled']) ? 1 : 0,
        'typing_indicator_enabled' => !empty($input['typing_indicator_enabled']) ? 1 : 0,
        'hide_paths' => isset($input['hide_paths']) ? sanitize_textarea_field($input['hide_paths']) : '',
        'show_paths' => isset($input['show_paths']) ? sanitize_textarea_field($input['show_paths']) : '',
        'hide_selectors' => isset($input['hide_selectors']) ? sanitize_textarea_field($input['hide_selectors']) : '',
        'compact_selectors' => isset($input['compact_selectors']) ? sanitize_textarea_field($input['compact_selectors']) : '',
        'handover_ack_enabled' => !empty($input['handover_ack_enabled']) ? 1 : 0,
        'handover_ack_text' => isset($input['handover_ack_text']) ? sanitize_text_field($input['handover_ack_text']) : $defaults['handover_ack_text'],
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

function chatbridge_widget_meta_status(array $settings)
{
    $api_base = untrailingslashit((string) ($settings['api_base'] ?? ''));
    $api_key = (string) ($settings['tenant_api_key'] ?? '');
    if ($api_base === '' || $api_key === '' || !wp_http_validate_url($api_base) || wp_parse_url($api_base, PHP_URL_SCHEME) !== 'https') {
        return null;
    }
    $response = wp_remote_get($api_base . '/meta/status.php', [
        'timeout' => 10,
        'redirection' => 0,
        'sslverify' => true,
        'headers' => [
            'Accept' => 'application/json',
            'X-ChatBridge-Tenant-Key' => $api_key,
        ],
    ]);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body)) {
        return null;
    }
    return [
        'connections' => isset($body['connections']) && is_array($body['connections']) ? $body['connections'] : [],
        'coexistence_sync' => isset($body['coexistence_sync']) && is_array($body['coexistence_sync']) ? $body['coexistence_sync'] : [],
    ];
}

function chatbridge_widget_meta_config(array $settings)
{
    $api_base = untrailingslashit((string) ($settings['api_base'] ?? ''));
    $api_key = (string) ($settings['tenant_api_key'] ?? '');
    if ($api_base === '' || $api_key === '' || !wp_http_validate_url($api_base) || wp_parse_url($api_base, PHP_URL_SCHEME) !== 'https') {
        return null;
    }
    $response = wp_remote_get($api_base . '/meta/config.php', [
        'timeout' => 10,
        'redirection' => 0,
        'sslverify' => true,
        'headers' => [
            'Accept' => 'application/json',
            'X-ChatBridge-Tenant-Key' => $api_key,
        ],
    ]);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body) || empty($body['enabled']) || empty($body['app_id']) || empty($body['configuration_id'])) {
        return null;
    }
    return [
        'app_id' => sanitize_text_field((string) $body['app_id']),
        'configuration_id' => sanitize_text_field((string) $body['configuration_id']),
        'solution_id' => sanitize_text_field((string) ($body['solution_id'] ?? '')),
        'graph_api_version' => sanitize_text_field((string) ($body['graph_api_version'] ?? 'v27.0')),
        'embedded_signup_version' => 'v5',
    ];
}

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
    $meta_config = chatbridge_widget_meta_config($settings);
    $meta_status = chatbridge_widget_meta_status($settings);
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
                    <th scope="row"><?php esc_html_e('WooCommerce product search', 'chatbridge-widget'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="chatbridge_widget_settings[woocommerce_enabled]" value="1" <?php checked($settings['woocommerce_enabled'], 1); ?> <?php disabled(!class_exists('WooCommerce')); ?>>
                            <?php esc_html_e('Allow visitors to search published WooCommerce products from the chat widget', 'chatbridge-widget'); ?>
                        </label>
                        <?php if (!class_exists('WooCommerce')) : ?>
                            <p class="description"><?php esc_html_e('WooCommerce is not active. Install and activate WooCommerce to enable this optional integration.', 'chatbridge-widget'); ?></p>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e('Read-only catalogue search. It does not change stock, prices, carts, checkout or orders.', 'chatbridge-widget'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Customer order lookup (logged-in customers only)', 'chatbridge-widget'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="chatbridge_widget_settings[woocommerce_orders_enabled]" value="1" <?php checked($settings['woocommerce_orders_enabled'], 1); ?> <?php disabled(!class_exists('WooCommerce')); ?>>
                            <?php esc_html_e('Enable secure order-status lookup for authenticated WordPress customers', 'chatbridge-widget'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('This does not expose guest-checkout orders. Customers must be signed in to the same WordPress account that placed the order. A REST nonce is required.', 'chatbridge-widget'); ?></p>
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
                    <th scope="row"><label for="chatbridge-tenant-api-key"><?php esc_html_e('Private tenant API key', 'chatbridge-widget'); ?></label></th>
                    <td>
                        <input id="chatbridge-tenant-api-key" class="regular-text code" type="password" autocomplete="new-password" name="chatbridge_widget_settings[tenant_api_key]" value="" placeholder="<?php echo !empty($settings['tenant_api_key']) ? esc_attr__('Key already saved — leave blank to keep it', 'chatbridge-widget') : esc_attr__('Paste the one-time key from your service administrator', 'chatbridge-widget'); ?>">
                        <p class="description"><?php esc_html_e('Used only by WordPress server-side requests to your ChatBridge API. It is never added to the public widget JavaScript. Leave blank to keep the saved key.', 'chatbridge-widget'); ?></p>
                        <p class="description"><?php echo !empty($settings['tenant_api_key']) ? esc_html__('Tenant key: configured', 'chatbridge-widget') : esc_html__('Tenant key: not configured', 'chatbridge-widget'); ?></p>
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
                <tr>
                    <th scope="row"><?php esc_html_e('Customer experience', 'chatbridge-widget'); ?></th>
                    <td>
                        <label><input type="checkbox" name="chatbridge_widget_settings[offline_queue_enabled]" value="1" <?php checked($settings['offline_queue_enabled'], 1); ?>> <?php esc_html_e('Save unsent messages on this device and retry when the connection returns (up to 20 messages).', 'chatbridge-widget'); ?></label><br>
                        <label><input type="checkbox" name="chatbridge_widget_settings[typing_indicator_enabled]" value="1" <?php checked($settings['typing_indicator_enabled'], 1); ?>> <?php esc_html_e('Show an animated reply-pending indicator after a message is saved.', 'chatbridge-widget'); ?></label>
                        <p class="description"><?php esc_html_e('Offline messages are stored in this browser’s local storage until sent or cleared. Do not enable this on shared/public kiosk devices.', 'chatbridge-widget'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="chatbridge-launcher"><?php esc_html_e('Launcher label', 'chatbridge-widget'); ?></label></th>
                    <td><input id="chatbridge-launcher" type="text" maxlength="40" name="chatbridge_widget_settings[launcher_text]" value="<?php echo esc_attr($settings['launcher_text']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="chatbridge-logo"><?php esc_html_e('Logo image URL', 'chatbridge-widget'); ?></label></th>
                    <td><input id="chatbridge-logo" class="large-text" type="url" name="chatbridge_widget_settings[logo_url]" value="<?php echo esc_attr($settings['logo_url']); ?>" placeholder="https://example.com/logo.png"><p class="description"><?php esc_html_e('Optional HTTPS image. Use your media library URL.', 'chatbridge-widget'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Widget colours', 'chatbridge-widget'); ?></th>
                    <td>
                        <label><?php esc_html_e('Primary', 'chatbridge-widget'); ?> <input type="color" name="chatbridge_widget_settings[primary_color]" value="<?php echo esc_attr($settings['primary_color']); ?>"></label>
                        <label style="margin-left:16px"><?php esc_html_e('Accent', 'chatbridge-widget'); ?> <input type="color" name="chatbridge_widget_settings[accent_color]" value="<?php echo esc_attr($settings['accent_color']); ?>"></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="chatbridge-hide-paths"><?php esc_html_e('Hide on URL paths', 'chatbridge-widget'); ?></label></th>
                    <td><textarea id="chatbridge-hide-paths" class="large-text code" rows="3" name="chatbridge_widget_settings[hide_paths]" placeholder="/checkout&#10;/watch/*"><?php echo esc_textarea($settings['hide_paths']); ?></textarea><p class="description"><?php esc_html_e('One path per line. Exact paths or prefix patterns ending in *.', 'chatbridge-widget'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="chatbridge-show-paths"><?php esc_html_e('Only show on URL paths', 'chatbridge-widget'); ?></label></th>
                    <td><textarea id="chatbridge-show-paths" class="large-text code" rows="3" name="chatbridge_widget_settings[show_paths]" placeholder="/shop/*&#10;/contact"><?php echo esc_textarea($settings['show_paths']); ?></textarea><p class="description"><?php esc_html_e('Leave blank to allow all paths not hidden above. If set, the widget only appears on matching paths.', 'chatbridge-widget'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="chatbridge-hide-selectors"><?php esc_html_e('Hide when page contains CSS selector', 'chatbridge-widget'); ?></label></th>
                    <td><textarea id="chatbridge-hide-selectors" class="large-text code" rows="2" name="chatbridge_widget_settings[hide_selectors]"><?php echo esc_textarea($settings['hide_selectors']); ?></textarea><p class="description"><?php esc_html_e('Optional CSS selectors, one per line. If any matching element exists, the widget is not loaded.', 'chatbridge-widget'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="chatbridge-compact-selectors"><?php esc_html_e('Use compact launcher when page contains selector', 'chatbridge-widget'); ?></label></th>
                    <td><textarea id="chatbridge-compact-selectors" class="large-text code" rows="2" name="chatbridge_widget_settings[compact_selectors]"><?php echo esc_textarea($settings['compact_selectors']); ?></textarea><p class="description"><?php esc_html_e('Optional CSS selectors, one per line. Matching pages get a smaller launcher.', 'chatbridge-widget'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Human support acknowledgement', 'chatbridge-widget'); ?></th>
                    <td>
                        <label><input type="checkbox" name="chatbridge_widget_settings[handover_ack_enabled]" value="1" <?php checked($settings['handover_ack_enabled'], 1); ?>> <?php esc_html_e('Show a custom acknowledgement when a visitor requests a person or AI hands over.', 'chatbridge-widget'); ?></label>
                        <p><textarea class="large-text" rows="2" maxlength="240" name="chatbridge_widget_settings[handover_ack_text]"><?php echo esc_textarea($settings['handover_ack_text']); ?></textarea></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save settings', 'chatbridge-widget')); ?>
        </form>

        <hr>
        <h2><?php esc_html_e('Store compatibility', 'chatbridge-widget'); ?></h2>
        <ul>
            <li>
                <strong><?php esc_html_e('WordPress:', 'chatbridge-widget'); ?></strong>
                <?php echo esc_html((string) get_bloginfo('version')); ?>
            </li>
            <li>
                <strong><?php esc_html_e('PHP:', 'chatbridge-widget'); ?></strong>
                <?php echo esc_html(PHP_VERSION); ?>
            </li>
            <li>
                <strong><?php esc_html_e('WooCommerce:', 'chatbridge-widget'); ?></strong>
                <?php echo defined('WC_VERSION') ? esc_html(WC_VERSION) : esc_html__('Not detected', 'chatbridge-widget'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('WooCommerce status:', 'chatbridge-widget'); ?></strong>
                <?php echo class_exists('WooCommerce') ? esc_html__('Active', 'chatbridge-widget') : esc_html__('Not detected', 'chatbridge-widget'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('WCFM Marketplace:', 'chatbridge-widget'); ?></strong>
                <?php echo chatbridge_widget_wcfm_available() ? esc_html__('Active — vendor attribution API detected', 'chatbridge-widget') : esc_html__('Not detected — standard WooCommerce catalogue mode', 'chatbridge-widget'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('Product search:', 'chatbridge-widget'); ?></strong>
                <?php
                if (!class_exists('WooCommerce')) {
                    esc_html_e('Unavailable until WooCommerce is active.', 'chatbridge-widget');
                } elseif (!empty($settings['woocommerce_enabled'])) {
                    esc_html_e('Enabled for public catalogue products.', 'chatbridge-widget');
                } else {
                    esc_html_e('Disabled. Enable it above to add product search to the widget.', 'chatbridge-widget');
                }
                ?>
            </li>
        </ul>
        <p class="description"><?php esc_html_e('This connector does not modify Paystack, WCFM, product prices, stock, cart or checkout.', 'chatbridge-widget'); ?></p>

        <hr>
        <h2><?php esc_html_e('WhatsApp connection', 'chatbridge-widget'); ?></h2>
        <?php if ($meta_config !== null) : ?>
            <div id="chatbridge-meta-onboarding"
                 data-app-id="<?php echo esc_attr($meta_config['app_id']); ?>"
                 data-config-id="<?php echo esc_attr($meta_config['configuration_id']); ?>"
                 data-solution-id="<?php echo esc_attr($meta_config['solution_id']); ?>"
                 data-graph-version="<?php echo esc_attr($meta_config['graph_api_version']); ?>"
                 data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                 data-nonce="<?php echo esc_attr(wp_create_nonce('chatbridge_meta_finish')); ?>">
                <p><?php esc_html_e('Connect an eligible WhatsApp Business account using Meta Embedded Signup v5. The business owner completes the flow in Meta and, if eligible, confirms Coexistence inside the WhatsApp Business app.', 'chatbridge-widget'); ?></p>
                <button type="button" class="button button-primary" id="chatbridge-meta-connect"><?php esc_html_e('Connect WhatsApp', 'chatbridge-widget'); ?></button>
                <p id="chatbridge-meta-status" role="status" aria-live="polite"></p>
            </div>
        <?php else : ?>
            <p class="description"><?php esc_html_e('Meta onboarding is unavailable. Confirm the private tenant API key and that the service administrator has enabled a valid Meta app and Embedded Signup configuration.', 'chatbridge-widget'); ?></p>
        <?php endif; ?>

        <?php if (is_array($meta_status)) : ?>
            <h3><?php esc_html_e('Existing WhatsApp connections', 'chatbridge-widget'); ?></h3>
            <?php if ($meta_status['connections'] !== []) : ?>
                <ul>
                    <?php foreach ($meta_status['connections'] as $connection) : ?>
                        <li>
                            <strong><?php echo esc_html((string) ($connection['display_phone_number'] ?: $connection['phone_number_id'])); ?></strong>
                            — <?php echo esc_html(sanitize_key((string) ($connection['connection_status'] ?? 'unknown'))); ?>
                            (<?php echo esc_html(sanitize_key((string) ($connection['connection_mode'] ?? 'unknown'))); ?>)
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p><?php esc_html_e('No WhatsApp phone numbers are connected to this tenant yet.', 'chatbridge-widget'); ?></p>
            <?php endif; ?>
            <?php if ($meta_status['coexistence_sync'] !== []) : ?>
                <h4><?php esc_html_e('Coexistence synchronization', 'chatbridge-widget'); ?></h4>
                <ul>
                    <?php foreach ($meta_status['coexistence_sync'] as $sync_job) : ?>
                        <li>
                            <?php echo esc_html(sanitize_key((string) ($sync_job['sync_type'] ?? 'sync'))); ?>:
                            <?php echo esc_html(sanitize_key((string) ($sync_job['status'] ?? 'unknown'))); ?>
                            <?php if (!empty($sync_job['last_error'])) : ?>
                                — <?php echo esc_html(sanitize_text_field((string) $sync_job['last_error'])); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>

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

add_action('wp_ajax_chatbridge_meta_finish', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You are not allowed to connect a business account.', 'chatbridge-widget')], 403);
    }
    check_ajax_referer('chatbridge_meta_finish', 'nonce');
    $settings = chatbridge_widget_settings();
    $api_base = untrailingslashit((string) $settings['api_base']);
    $api_key = (string) ($settings['tenant_api_key'] ?? '');
    $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
    $session_raw = isset($_POST['session_info']) ? sanitize_textarea_field(wp_unslash($_POST['session_info'])) : '';
    $session_info = is_string($session_raw) ? json_decode($session_raw, true) : null;
    if ($api_key === '' || $code === '' || !is_array($session_info)) {
        wp_send_json_error(['message' => __('The signup response is incomplete. Please try again.', 'chatbridge-widget')], 422);
    }

    $response = wp_remote_post($api_base . '/meta/onboarding.php', [
        'timeout' => 35,
        'redirection' => 0,
        'sslverify' => true,
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-ChatBridge-Tenant-Key' => $api_key,
        ],
        'body' => wp_json_encode(['code' => $code, 'session_info' => $session_info]),
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => __('Could not reach the ChatBridge service. Check the connection and try again.', 'chatbridge-widget')], 502);
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300 || !is_array($body) || empty($body['ok'])) {
        wp_send_json_error(['message' => __('Meta onboarding did not complete. Check the ChatBridge service log for details.', 'chatbridge-widget')], $status >= 400 && $status < 600 ? $status : 502);
    }
    wp_send_json_success([
        'connections' => isset($body['connections']) && is_array($body['connections']) ? $body['connections'] : [],
        'connection_mode' => sanitize_key((string) ($body['connection_mode'] ?? 'unknown')),
    ]);
});

add_action('admin_enqueue_scripts', static function (string $hook): void {
    if ($hook !== 'settings_page_chatbridge-widget') {
        return;
    }
    $settings = chatbridge_widget_settings();
    if (chatbridge_widget_meta_config($settings) === null) {
        return;
    }
    wp_enqueue_script('chatbridge-meta-sdk', 'https://connect.facebook.net/en_US/sdk.js', [], null, true);
    wp_enqueue_script(
        'chatbridge-meta-onboarding',
        plugins_url('assets/meta-onboarding.js', __FILE__),
        ['chatbridge-meta-sdk'],
        CHATBRIDGE_WIDGET_VERSION,
        true
    );
});

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

    // WooCommerce stays an optional, site-local adapter. The browser calls this
    // same-origin public-catalog endpoint; no store credentials leave WordPress.
    $commerce_api = '';
    if (!empty($settings['woocommerce_enabled']) && class_exists('WooCommerce')) {
        $commerce_api = rest_url('chatbridge/v1/products');
    }
    $orders_api = '';
    $rest_nonce = '';
    if (!empty($settings['woocommerce_orders_enabled']) && class_exists('WooCommerce') && is_user_logged_in()) {
        $orders_api = rest_url('chatbridge/v1/orders/');
        $rest_nonce = wp_create_nonce('wp_rest');
    }

    // Add data attributes to the one external script tag; no inline executable code or theme edits.
    add_filter('script_loader_tag', static function (string $tag, string $handle_name, string $src) use ($handle, $api_base, $settings, $commerce_api, $orders_api, $rest_nonce): string {
        if ($handle_name !== $handle) {
            return $tag;
        }

        $attributes = sprintf(
            ' data-api-base="%1$s" data-title="%2$s" data-subtitle="%3$s" data-commerce-api="%4$s" data-orders-api="%5$s" data-rest-nonce="%6$s" data-launcher-text="%7$s" data-logo-url="%8$s" data-primary-color="%9$s" data-accent-color="%10$s" data-offline-queue="%11$s" data-typing-indicator="%12$s" data-hide-paths="%13$s" data-show-paths="%14$s" data-hide-selectors="%15$s" data-compact-selectors="%16$s" data-handover-ack="%17$s" data-handover-message="%18$s"',
            esc_attr($api_base),
            esc_attr($settings['title']),
            esc_attr($settings['subtitle']),
            esc_url($commerce_api),
            esc_url($orders_api),
            esc_attr($rest_nonce),
            esc_attr($settings['launcher_text']),
            esc_url($settings['logo_url']),
            esc_attr($settings['primary_color']),
            esc_attr($settings['accent_color']),
            !empty($settings['offline_queue_enabled']) ? 'true' : 'false',
            !empty($settings['typing_indicator_enabled']) ? 'true' : 'false',
            esc_attr(implode(',', preg_split('/\\r?\\n/', (string) $settings['hide_paths']))),
            esc_attr(implode(',', preg_split('/\\r?\\n/', (string) $settings['show_paths']))),
            esc_attr(implode(',', preg_split('/\\r?\\n/', (string) $settings['hide_selectors']))),
            esc_attr(implode(',', preg_split('/\\r?\\n/', (string) $settings['compact_selectors']))),
            !empty($settings['handover_ack_enabled']) ? 'true' : 'false',
            esc_attr($settings['handover_ack_text'])
        );

        return preg_replace('/<script\b/', '<script' . $attributes, $tag, 1) ?: $tag;
    }, 10, 3);
}, 20);


/**
 * Optional read-only WooCommerce catalogue adapter.
 *
 * Only published, catalogue-visible products are exposed. This endpoint does
 * not expose customer records, orders, private product metadata, stock counts,
 * or store credentials. Order lookup requires a separate verified flow.
 */
add_action('rest_api_init', static function (): void {
    register_rest_route('chatbridge/v1', '/orders/(?P<order_id>\\d+)', [
        'methods' => WP_REST_Server::READABLE,
        'permission_callback' => 'chatbridge_widget_can_read_own_order',
        'callback' => 'chatbridge_widget_get_own_order',
        'args' => [
            'order_id' => ['required' => true, 'validate_callback' => static function ($value): bool { return ctype_digit((string) $value) && (int) $value > 0; }],
        ],
    ]);

    register_rest_route('chatbridge/v1', '/products', [
        'methods' => WP_REST_Server::READABLE,
        'permission_callback' => '__return_true',
        'args' => [
            'search' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => static function ($value): bool {
                    $value = trim((string) $value);
                    return strlen($value) >= 2 && strlen($value) <= 80;
                },
            ],
        ],
        'callback' => 'chatbridge_widget_search_products',
    ]);
});

/**
 * Resolve public vendor attribution through WCFM's supported in-process API.
 * No vendor post-meta key or commission table is queried directly.
 */
function chatbridge_widget_wcfm_available(): bool
{
    return function_exists('wcfm_get_vendor_id_by_post') && function_exists('wcfm_get_vendor_store');
}

function chatbridge_widget_product_vendor(int $product_id): ?array
{
    if ($product_id < 1 || !chatbridge_widget_wcfm_available()) {
        return null;
    }

    $vendor_id = (int) wcfm_get_vendor_id_by_post($product_id);
    if ($vendor_id < 1 || (function_exists('wcfm_is_vendor') && !wcfm_is_vendor($vendor_id))) {
        return null;
    }

    $store_name = wcfm_get_vendor_store($vendor_id);
    if (!is_string($store_name) || trim($store_name) === '') {
        $vendor = get_userdata($vendor_id);
        $store_name = $vendor instanceof WP_User ? $vendor->display_name : '';
    }

    $store_name = sanitize_text_field((string) $store_name);
    return $store_name !== '' ? ['name' => $store_name] : null;
}

function chatbridge_widget_search_products(WP_REST_Request $request): WP_REST_Response
{
    $settings = chatbridge_widget_settings();
    if (empty($settings['woocommerce_enabled'])) {
        return new WP_REST_Response([
            'code' => 'product_search_disabled',
            'message' => 'Product search is disabled by this website.',
        ], 403);
    }

    if (!class_exists('WooCommerce') || !function_exists('wc_get_products')) {
        return new WP_REST_Response([
            'code' => 'woocommerce_unavailable',
            'message' => 'WooCommerce is not active on this website.',
        ], 503);
    }

    $search = trim(sanitize_text_field((string) $request->get_param('search')));
    if (strlen($search) < 2 || strlen($search) > 80) {
        return new WP_REST_Response([
            'code' => 'invalid_search',
            'message' => 'Enter a search term between 2 and 80 characters.',
        ], 400);
    }

    // Apply a modest per-IP limit. Only a keyed hash is stored, not the IP itself.
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    $bucket = 'chatbridge_wc_' . hash_hmac('sha256', $ip, wp_salt('auth'));
    $count = (int) get_transient($bucket);
    if ($count >= 30) {
        return new WP_REST_Response([
            'code' => 'rate_limited',
            'message' => 'Please wait a moment before searching again.',
        ], 429);
    }
    set_transient($bucket, $count + 1, MINUTE_IN_SECONDS);

    $cache_key = 'chatbridge_products_' . md5(strtolower($search));
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return new WP_REST_Response($cached, 200);
    }

    // SKU matching is supported directly by WC_Product_Query and is useful
    // for parts. Use WooCommerce's public Store API for partial product-name
    // search, rather than raw SQL or undocumented query arguments.
    $skuMatches = wc_get_products([
        'status' => 'publish',
        'limit' => 8,
        'sku' => $search,
        'return' => 'objects',
    ]);

    $nameMatches = [];
    $storeRequest = new WP_REST_Request('GET', '/wc/store/v1/products');
    $storeRequest->set_param('search', $search);
    $storeRequest->set_param('per_page', 8);
    $storeResponse = rest_do_request($storeRequest);
    if ($storeResponse instanceof WP_REST_Response && $storeResponse->get_status() < 400) {
        foreach ($storeResponse->get_data() as $storeProduct) {
            $productId = isset($storeProduct['id']) ? (int) $storeProduct['id'] : 0;
            if ($productId > 0) {
                $candidate = wc_get_product($productId);
                if ($candidate instanceof WC_Product) {
                    $nameMatches[] = $candidate;
                }
            }
        }
    }

    $products = [];
    $seenProductIds = [];
    foreach (array_merge($skuMatches, $nameMatches) as $candidate) {
        if (!$candidate instanceof WC_Product) {
            continue;
        }
        $candidateId = $candidate->get_id();
        if (isset($seenProductIds[$candidateId])) {
            continue;
        }
        $seenProductIds[$candidateId] = true;
        $products[] = $candidate;
        if (count($products) >= 8) {
            break;
        }
    }

    $items = [];
    foreach ($products as $product) {
        if (!$product instanceof WC_Product || $product->get_status() !== 'publish') {
            continue;
        }
        if ($product->get_catalog_visibility() === 'hidden') {
            continue;
        }

        $price_html = $product->get_price_html();
        $price = html_entity_decode(wp_strip_all_tags((string) $price_html), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
        $image = $product->get_image_id()
            ? wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail')
            : wc_placeholder_img_src();

        $vendor = chatbridge_widget_product_vendor((int) $product->get_id());
        $items[] = [
            'name' => sanitize_text_field($product->get_name()),
            'vendor' => $vendor ? $vendor['name'] : null,
            'sku' => sanitize_text_field((string) $product->get_sku()),
            'price' => sanitize_text_field($price),
            'url' => esc_url_raw($product->get_permalink()),
            'image' => $image ? esc_url_raw($image) : '',
            'stock_status' => sanitize_key($product->get_stock_status()),
            'type' => sanitize_key($product->get_type()),
        ];
    }

    $payload = [
        'products' => $items,
        'count' => count($items),
    ];
    set_transient($cache_key, $payload, 30);
    return new WP_REST_Response($payload, 200);
}


/**
 * Order lookup is deliberately stricter than public catalogue search.
 *
 * It only permits an authenticated WordPress customer to read an order whose
 * customer_id is exactly their current user ID. Guest checkout, email-only
 * lookup, order-key lookup, vendor-wide access and administrator impersonation
 * are not supported by this endpoint.
 */
function chatbridge_widget_can_read_own_order(): bool
{
    $settings = chatbridge_widget_settings();
    return !empty($settings['woocommerce_orders_enabled'])
        && class_exists('WooCommerce')
        && function_exists('wc_get_order')
        && is_user_logged_in()
        && get_current_user_id() > 0;
}

function chatbridge_widget_get_own_order(WP_REST_Request $request): WP_REST_Response
{
    if (!chatbridge_widget_can_read_own_order()) {
        return new WP_REST_Response([
            'code' => 'order_lookup_unavailable',
            'message' => 'Secure order lookup is unavailable. Sign in to your customer account and try again.',
        ], 403);
    }

    $orderId = (int) $request->get_param('order_id');
    if ($orderId < 1) {
        return new WP_REST_Response(['code' => 'invalid_order', 'message' => 'A valid order number is required.'], 400);
    }

    $order = wc_get_order($orderId);
    if (!$order instanceof WC_Order) {
        return new WP_REST_Response(['code' => 'order_not_found', 'message' => 'We could not find that order.'], 404);
    }

    // Do not use the order key or billing email as a substitute for ownership.
    if ((int) $order->get_customer_id() !== (int) get_current_user_id()) {
        return new WP_REST_Response(['code' => 'order_not_found', 'message' => 'We could not find that order.'], 404);
    }

    $items = [];
    foreach ($order->get_items() as $item) {
        if (!is_object($item) || !method_exists($item, 'get_name') || !method_exists($item, 'get_quantity')) {
            continue;
        }
        $vendor = method_exists($item, 'get_product_id')
            ? chatbridge_widget_product_vendor((int) $item->get_product_id())
            : null;
        $items[] = [
            'name' => sanitize_text_field((string) $item->get_name()),
            'quantity' => max(1, (int) $item->get_quantity()),
            'vendor' => $vendor ? $vendor['name'] : null,
        ];
        if (count($items) >= 20) {
            break;
        }
    }

    $dateCreated = $order->get_date_created();

    return new WP_REST_Response([
        'order' => [
            'number' => sanitize_text_field((string) $order->get_order_number()),
            'status' => sanitize_key((string) $order->get_status()),
            'date_created' => $dateCreated instanceof DateTimeInterface ? $dateCreated->format(DATE_ATOM) : null,
            'currency' => sanitize_key((string) $order->get_currency()),
            'total' => html_entity_decode(wp_strip_all_tags((string) $order->get_formatted_order_total()), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8'),
            'items' => $items,
        ],
    ], 200);
}
