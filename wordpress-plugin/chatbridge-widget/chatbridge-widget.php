<?php
/**
 * Plugin Name: ChatBridge Widget
 * Description: Add a lightweight website support chat widget connected to your ChatBridge service. Optional WhatsApp continuation is handled by your service.
 * Version: 0.2.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: ChatBridge
 * License: GPL-2.0-or-later
 * Text Domain: chatbridge-widget
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CHATBRIDGE_WIDGET_VERSION', '0.2.0');
define('CHATBRIDGE_WIDGET_FILE', __FILE__);

function chatbridge_widget_defaults(): array
{
    return [
        'enabled' => 0,
        'woocommerce_enabled' => 0,
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
        'woocommerce_enabled' => !empty($input['woocommerce_enabled']) ? 1 : 0,
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

    // WooCommerce stays an optional, site-local adapter. The browser calls this
    // same-origin public-catalog endpoint; no store credentials leave WordPress.
    $commerce_api = '';
    if (!empty($settings['woocommerce_enabled']) && class_exists('WooCommerce')) {
        $commerce_api = rest_url('chatbridge/v1/products');
    }

    // Add data attributes to the one external script tag; no inline executable code or theme edits.
    add_filter('script_loader_tag', static function (string $tag, string $handle_name, string $src) use ($handle, $api_base, $settings, $commerce_api): string {
        if ($handle_name !== $handle) {
            return $tag;
        }

        $attributes = sprintf(
            ' data-api-base="%1$s" data-title="%2$s" data-subtitle="%3$s" data-commerce-api="%4$s"',
            esc_attr($api_base),
            esc_attr($settings['title']),
            esc_attr($settings['subtitle']),
            esc_url($commerce_api)
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
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
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

        $items[] = [
            'name' => sanitize_text_field($product->get_name()),
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
