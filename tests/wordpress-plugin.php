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
    private array $params = [];
    private string $route = '';

    public function __construct($methodOrParams = [], string $route = '')
    {
        $this->route = $route;
        if (is_array($methodOrParams)) {
            $this->params = $methodOrParams;
        }
    }
    public function set_param(string $key, $value): void { $this->params[$key] = $value; }
    public function get_param(string $key) { return $this->params[$key] ?? null; }
}

final class WP_REST_Response
{
    private array $data;
    private int $status;

    public function __construct(array $data, int $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }
    public function get_data(): array { return $this->data; }
    public function get_status(): int { return $this->status; }
}

class WooCommerce {}
class WC_Order_Item_Test
{
    public function get_name(): string { return 'Brake Pad Set'; }
    public function get_quantity(): int { return 2; }
    public function get_product_id(): int { return crc32('Brake Pad Set'); }
}
class WC_Order
{
    private int $customerId;
    public function __construct(int $customerId) { $this->customerId = $customerId; }
    public function get_customer_id(): int { return $this->customerId; }
    public function get_items(): array { return [new WC_Order_Item_Test()]; }
    public function get_order_number(): string { return '1042'; }
    public function get_status(): string { return 'processing'; }
    public function get_date_created() { return new DateTimeImmutable('2026-11-20T09:00:00+00:00'); }
    public function get_currency(): string { return 'NGN'; }
    public function get_formatted_order_total(): string { return '₦90,000.00'; }
}
class WC_Product
{
    private string $name;
    private string $status;
    private string $visibility;
    private string $price;
    private string $stock;
    private int $imageId;

    public function __construct(
        string $name,
        string $status = 'publish',
        string $visibility = 'visible',
        string $price = '₦45,000',
        string $stock = 'instock',
        int $imageId = 1
    ) {
        $this->name = $name;
        $this->status = $status;
        $this->visibility = $visibility;
        $this->price = $price;
        $this->stock = $stock;
        $this->imageId = $imageId;
    }
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
$GLOBALS['chatbridge_store_api_params'] = [];
$GLOBALS['chatbridge_current_user_id'] = 0;
$GLOBALS['chatbridge_is_logged_in'] = false;
$GLOBALS['chatbridge_test_order'] = null;

function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['chatbridge_hooks'][$hook][] = $callback;
}
function register_rest_route(string $namespace, string $route, array $args): void
{
    $GLOBALS['chatbridge_routes'][$namespace . $route] = $args;
}
function is_user_logged_in(): bool { return $GLOBALS['chatbridge_is_logged_in']; }
function get_current_user_id(): int { return (int) $GLOBALS['chatbridge_current_user_id']; }
function wcfm_get_vendor_id_by_post(int $productId): int { return $productId === crc32('Brake Pad Set') ? 77 : 0; }
function wcfm_get_vendor_store(int $vendorId): string { return $vendorId === 77 ? 'Brake Parts Hub' : ''; }
function wcfm_is_vendor(int $vendorId): bool { return $vendorId === 77; }
function wc_get_order(int $orderId): ?WC_Order { return $orderId === 1042 ? $GLOBALS['chatbridge_test_order'] : null; }
function get_option(string $key, $default = false) { return $GLOBALS['chatbridge_options'][$key] ?? $default; }
function delete_option(string $key): bool { unset($GLOBALS['chatbridge_options'][$key]); return true; }
function update_option(string $key, $value, $autoload = null): bool { $GLOBALS['chatbridge_options'][$key] = $value; $GLOBALS['chatbridge_autoload'][$key] = $autoload; return true; }
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
function wc_get_product(int $productId): ?WC_Product
{
    foreach ([
        new WC_Product('Brake Pad Set'),
        new WC_Product('Hidden Workshop Item', 'publish', 'hidden'),
        new WC_Product('Unpublished Part', 'draft'),
    ] as $product) {
        if ($product->get_id() === $productId) return $product;
    }
    return null;
}
function rest_do_request(WP_REST_Request $request): WP_REST_Response
{
    $GLOBALS['chatbridge_store_api_params'] = [
        'search' => $request->get_param('search'),
        'per_page' => $request->get_param('per_page'),
    ];
    return new WP_REST_Response([
        ['id' => (new WC_Product('Brake Pad Set'))->get_id()],
        ['id' => (new WC_Product('Hidden Workshop Item', 'publish', 'hidden'))->get_id()],
        ['id' => (new WC_Product('Unpublished Part', 'draft'))->get_id()],
    ], 200);
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

$secretKey = str_repeat('a', 64);
$sanitizedWithKey = chatbridge_widget_sanitize_settings(['tenant_api_key' => $secretKey]);
check(($GLOBALS['chatbridge_options']['chatbridge_widget_tenant_api_key'] ?? '') === $secretKey && ($GLOBALS['chatbridge_autoload']['chatbridge_widget_tenant_api_key'] ?? true) === false, 'tenant API key is stored separately and non-autoloaded');
check(!isset($sanitizedWithKey['tenant_api_key']), 'tenant API key is excluded from the public widget settings array');

$disabled = chatbridge_widget_search_products(new WP_REST_Request(['search' => 'brake']));
check($disabled->get_status() === 403, 'public endpoint respects the administrator product-search toggle');

$GLOBALS['chatbridge_options']['chatbridge_widget_settings'] = ['woocommerce_enabled' => 1];
$response = chatbridge_widget_search_products(new WP_REST_Request(['search' => 'brake']));
$data = $response->get_data();
check($response->get_status() === 200, 'valid catalogue search returns HTTP 200');
check(($GLOBALS['chatbridge_wc_args']['status'] ?? null) === 'publish', 'WooCommerce query only requests published products');
check(($GLOBALS['chatbridge_wc_args']['limit'] ?? null) === 8, 'WooCommerce query is capped at eight products');
check(($GLOBALS['chatbridge_wc_queries'][0]['sku'] ?? null) === 'brake', 'SKU matching runs through WC_Product_Query');
check(($GLOBALS['chatbridge_store_api_params']['search'] ?? null) === 'brake', 'partial product-name search uses WooCommerce Store API');
check(($GLOBALS['chatbridge_store_api_params']['per_page'] ?? null) === 8, 'Store API product search is capped at eight results');
check(count($data['products'] ?? []) === 1, 'hidden and unpublished products are excluded');
$product = $data['products'][0] ?? [];
check(($product['name'] ?? '') === 'Brake Pad Set', 'product name is returned');
check(($product['vendor'] ?? '') === 'Brake Parts Hub', 'WCFM vendor attribution uses the supported product-to-vendor API');
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

$GLOBALS['chatbridge_options']['chatbridge_widget_settings'] = ['woocommerce_orders_enabled' => 1];
$orderRoute = $GLOBALS['chatbridge_routes']['chatbridge/v1/orders/(?P<order_id>\\d+)'] ?? null;
check(is_array($orderRoute), 'registers a separate customer order lookup route');
check(($orderRoute['permission_callback'] ?? null) === 'chatbridge_widget_can_read_own_order', 'order route requires authenticated ownership permission');
check(chatbridge_widget_can_read_own_order() === false, 'anonymous visitors cannot access order lookup');
$GLOBALS['chatbridge_is_logged_in'] = true;
$GLOBALS['chatbridge_current_user_id'] = 55;
$GLOBALS['chatbridge_test_order'] = new WC_Order(55);
check(chatbridge_widget_can_read_own_order() === true, 'signed-in customers can use order lookup when enabled');
$ownOrder = chatbridge_widget_get_own_order(new WP_REST_Request(['order_id' => '1042']));
$ownData = $ownOrder->get_data();
check($ownOrder->get_status() === 200, 'customer can retrieve their own order');
check(($ownData['order']['number'] ?? '') === '1042', 'order lookup returns public order number');
check(count($ownData['order']['items'] ?? []) === 1, 'order lookup returns a bounded item summary');
check(($ownData['order']['items'][0]['vendor'] ?? '') === 'Brake Parts Hub', 'customer order summary attributes each line item to its WCFM vendor');
$GLOBALS['chatbridge_test_order'] = new WC_Order(99);
$foreignOrder = chatbridge_widget_get_own_order(new WP_REST_Request(['order_id' => '1042']));
check($foreignOrder->get_status() === 404, 'another customer order is indistinguishable from a missing order');
$GLOBALS['chatbridge_is_logged_in'] = false;

fwrite(STDOUT, "WooCommerce adapter tests passed.\n");
