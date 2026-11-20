<?php
declare(strict_types=1);

if (!class_exists('WooCommerce') || !function_exists('wc_get_products')) {
    fwrite(STDERR, "WooCommerce did not load.\n");
    exit(1);
}

update_option('chatbridge_widget_settings', [
    'enabled' => 1,
    'woocommerce_enabled' => 0,
    'api_base' => 'https://chat.example.test/api',
    'title' => 'Chat with us',
    'subtitle' => 'Ask us a question.',
]);

$visible = new WC_Product_Simple();
$visible->set_name('Brake Pad Set');
$visible->set_sku('BP-001');
$visible->set_regular_price('45000');
$visible->set_status('publish');
$visible->set_catalog_visibility('visible');
$visible->set_stock_status('instock');
$visible->save();

$hidden = new WC_Product_Simple();
$hidden->set_name('Secret Hidden Part');
$hidden->set_sku('SECRET-HIDDEN');
$hidden->set_regular_price('1000');
$hidden->set_status('publish');
$hidden->set_catalog_visibility('hidden');
$hidden->save();

$draft = new WC_Product_Simple();
$draft->set_name('Unpublished Draft Part');
$draft->set_sku('DRAFT-PART');
$draft->set_regular_price('1000');
$draft->set_status('draft');
$draft->save();

fwrite(STDOUT, "Created public, hidden and draft WooCommerce fixtures.\n");
