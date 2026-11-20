<?php
/**
 * Uninstall cleanup for ChatBridge Widget.
 *
 * This removes only plugin-owned WordPress options. It does not delete remote
 * ChatBridge conversations, tenant records, WhatsApp connections or service
 * data. Those are managed by the service operator.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('chatbridge_widget_settings');
delete_option('chatbridge_widget_tenant_api_key');
