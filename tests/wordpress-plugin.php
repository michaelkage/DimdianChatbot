<?php
declare(strict_types=1);

// Focused adapter tests with WordPress/WooCommerce boundary stubs.
// These validate the plugin's public-catalogue contract; they are not a
// substitute for running the plugin inside a real WordPress installation.

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

final class WP_REST_Server
{
    public const READABLE = 'GET';
}

final class WP_REST_Request
{
    public function __construct(private array $params = []) {}
    public function get_param(string $key) { return $this->params[$key] ?? null; }
}

final class WP_REST_Response
{
    public function __construct(private array $data, private int $status = 200) {}
    public function get_data(): array { return $this->data; }
    public function get_status(): int { return $this->status; }
}

class WooCommerce {}
class WC_Product
{
    public function __construct(
        private string $name,
        private string $status = 'publish',
        private string $visibility = 'visible',
        private string $price = '₦45,000',
        private string $stock = 'instock',
        private int $imageId = 1
    ) {}
    public function get_id(): int { return crc32($this->name); }
    public function get_sku(): string { return $this->name === 'Brake Pad Set' ? 'BP-001' : ''; }
    public function get_status(): string { return $this->status; }
    public function get_catalog_visibility(): string { return $this->visibility; }
    public function get_price_html(): string { return '<span class="price">' . $this->price . '</span>'; }
    public function get_image_id(): int { return $this->imageId; }
    public function get_stock_status(): string { return $this->stock; }
    public function get_type(): string { return 'simple'; }
    public function get_name(): string { return $this->name; }
    public function get_permalink(): string { return 'https://store.example.test/product/' . rawurlencode(strtolower(str_replace(' ', '-', $this->name))); }
}

$GLOBALS['chatbridge_hooks'] = [];
$GLOBALS['chatbridge_routes'] = [];
$GLOBALS['chatbridge_transients'] = [];
$GLOBALS['chatbridge_options'] = [];
$GLOBALS['chatbridge_wc_args'] = null;
$GLOBALS['chatbridge_wc_queries'] = [];

function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['chatbridge_hooks'][$hook][] = $callback;
}
function register_rest_route(string $namespace, string $route, array $args): void
{
    $GLOBALS['chatbridge_routes'][$namespace . $route] = $args;
}
function get_option(string $key, $default = false) { return $GLOBALS['chatbridge_options'][$key] ?? $default; }
function wp_parse_args(array $args, array $defaults): array { return array_merge($defaults, $args); }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function esc_url_raw(string $url, array $protocols = ['http', 'https']): string { return $url; }
function untrailingslashit(string $value): string { return rtrim($value, '/'); }
function wp_parse_url(string $url, int $component = -1) { return parse_url($url, $component); }
function wp_http_validate_url(string $url): bool { return filter_var($url, FILTER_VALIDATE_URL) !== false; }
function wp_salt(string $scheme = 'auth'): string { return 'test-only-salt'; }
function get_transient(string $key) { return $GLOBALS['chatbridge_transients'][$key]['value'] ?? false; }
function set_transient(string $key, $value, int $expiration = 0): bool
{
    $GLOBALS['chatbridge_transients'][$key] = ['value' => $value, 'expiration' => $expiration];
    return true;
}
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
function get_bloginfo(string $show): string { return 'UTF-8'; }
function wp_get_attachment_image_url(int $attachmentId, string $size = 'thumbnail') { return 'https://store.example.test/uploads/product.jpg'; }
function wc_placeholder_img_src(): string { return 'https://store.example.test/placeholder.png'; }
function wc_get_products(array $args): array
{
    $GLOBALS['chatbridge_wc_args'] = $args;
    $GLOBALS['chatbridge_wc_queries'][] = $args;
    return [
        new WC_Product('Brake Pad Set'),
        new WC_Product('Hidden Workshop Item', 'publish', 'hidden'),
        new WC_Product('Unpublished Part', 'draft'),
    ];
}

require_once dirname(__DIR__) . '/wordpress-plugin/chatbridge-widget/chatbridge-widget.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

foreach ($GLOBALS['chatbridge_hooks']['rest_api_init'] ?? [] as $callback) {
    $callback();
}
$route = $GLOBALS['chatbridge_routes']['chatbridge/v1/products'] ?? null;
check(is_array($route), 'registers the optional public product-search route');
check(($route['methods'] ?? null) === WP_REST_Server::READABLE, 'route is read-only');
check(($route['permission_callback'] ?? null) === '__return_true', 'route uses the public catalogue permission callback');

$sanitized = chatbridge_widget_sanitize_settings([
    'enabled' => '1',
    'woocommerce_enabled' => '1',
    'api_base' => 'https://chat.example.test/api/',
]);
check($sanitized['woocommerce_enabled'] === 1, 'WooCommerce setting is saved as an explicit boolean flag');
check($sanitized['api_base'] === 'https://chat.example.test/api', 'API base URL is normalized');

$disabled = chatbridge_widget_search_products(new WP_REST_Request(['search' => 'brake']));
check($disabled->get_status() === 403, 'public endpoint respects the administrator product-search toggle');

$GLOBALS['chatbridge_options']['chatbridge_widget_settings'] = ['woocommerce_enabled' => 1];
$response = chatbridge_widget_search_products(new WP_REST_Request(['search' => 'brake']));
$data = $response->get_data();
check($response->get_status() === 200, 'valid catalogue search returns HTTP 200');
check(($GLOBALS['chatbridge_wc_args']['status'] ?? null) === 'publish', 'WooCommerce query only requests published products');
check(($GLOBALS['chatbridge_wc_args']['limit'] ?? null) === 8, 'WooCommerce query is capped at eight products');
check(($GLOBALS['chatbridge_wc_queries'][0]['sku'] ?? null) === 'brake', 'exact SKU lookup runs before ordinary text search');
check(($GLOBALS['chatbridge_wc_queries'][1]['name'] ?? null) === 'brake', 'product-name search runs after SKU matching');
check(count($data['products'] ?? []) === 1, 'hidden and unpublished products are excluded');
$product = $data['products'][0] ?? [];
check(($product['name'] ?? '') === 'Brake Pad Set', 'product name is returned');
check(($product['sku'] ?? '') === 'BP-001', 'public product SKU is returned for part-number enquiries');
check(($product['price'] ?? '') === '₦45,000', 'formatted product price is returned as plain text');
check(($product['stock_status'] ?? '') === 'instock', 'only coarse stock status is returned');
check(!array_key_exists('id', $product) && !array_key_exists('stock_quantity', $product), 'response omits internal IDs and exact inventory counts');

$invalid = chatbridge_widget_search_products(new WP_REST_Request(['search' => 'x']));
check($invalid->get_status() === 400, 'too-short search terms are rejected');

$GLOBALS['chatbridge_transients'] = [];
for ($i = 0; $i < 30; $i++) {
    $limited = chatbridge_widget_search_products(new WP_REST_Request(['search' => 'brake']));
}
$blocked = chatbridge_widget_search_products(new WP_REST_Request(['search' => 'brake']));
check($blocked->get_status() === 429, 'public product search is rate limited after thirty requests per minute');

fwrite(STDOUT, "WooCommerce adapter tests passed.\n");
