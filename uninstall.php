<?php
/**
 * Vercel WP Uninstall Script
 * 
 * Fired when the plugin is uninstalled.
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// from wp-webhook-vercel-deploy
// Delete deploy options
delete_option('webhook_address');
delete_option('vercel_api_key');
delete_option('vercel_site_id');

// from plugin-headless-preview
// Delete preview options
delete_option('vercel_wp_preview_settings');
delete_option('vercel_wp_custom_page_templates');

// Clear any transients
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vercel_wp_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_vercel_wp_%'");
