<?php
declare(strict_types=1);

$settings = get_option('chatbridge_widget_settings', []);
$settings['woocommerce_enabled'] = 1;
update_option('chatbridge_widget_settings', $settings);
fwrite(STDOUT, "Enabled optional WooCommerce product search.\n");
