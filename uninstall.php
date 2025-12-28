<?php
/**
 * Uninstall script for Order Product Adder
 *
 * This file is executed when the plugin is deleted via the WordPress admin.
 * It removes all plugin data including the database table.
 */

// If uninstall is not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
  exit;
}

// Delete the plugin's database table
global $wpdb;
$table_name = $wpdb->prefix . 'opa_logs';

// Drop table if it exists
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

// Clean up any options (if we add them in the future)
// delete_option('opa_option_name');

// For multisite installations
if (is_multisite()) {
  // Get all blog IDs using the recommended multisite API
  $blog_ids = get_sites(array('fields' => 'ids'));

  foreach ($blog_ids as $blog_id) {
    switch_to_blog($blog_id);

    // Drop table for this blog
    $table_name = $wpdb->prefix . 'opa_logs';
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}");

    // Clean up options for this blog
    // delete_option('opa_option_name');

    restore_current_blog();
  }
}
