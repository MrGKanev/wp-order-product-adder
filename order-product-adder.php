<?php

/**
 * Plugin Name: Order Product Adder
 * Description: Add products to existing orders with logging functionality
 * Version: 1.2.0
 * Author: Gabriel Kanev
 * Author URI: https://gkanev.com
 * Requires at least: 6.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.5
 * Text Domain: order-product-adder
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
  exit;
}

// Define plugin constants
define('OPA_VERSION', '1.2.0');
define('OPA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OPA_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load plugin text domain for translations
add_action('plugins_loaded', 'opa_load_textdomain');
function opa_load_textdomain()
{
  load_plugin_textdomain('order-product-adder', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// Check if WooCommerce is active
add_action('plugins_loaded', 'opa_check_woocommerce', 20);
function opa_check_woocommerce()
{
  if (!class_exists('WooCommerce')) {
    add_action('admin_notices', 'opa_woocommerce_missing_notice');
    deactivate_plugins(plugin_basename(__FILE__));
  }
}

// Admin notice if WooCommerce is not active
function opa_woocommerce_missing_notice()
{
  echo '<div class="error"><p>' . esc_html__('Order Product Adder requires WooCommerce to be installed and active.', 'order-product-adder') . '</p></div>';
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function () {
  if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
  }
});

// Create database table on plugin activation
register_activation_hook(__FILE__, 'opa_create_logs_table');
function opa_create_logs_table()
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'opa_logs';

  $charset_collate = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        product_sku VARCHAR(100) NOT NULL,
        quantity INT UNSIGNED NOT NULL,
        status VARCHAR(50) NOT NULL,
        message TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY order_id (order_id),
        KEY created_at (created_at)
    ) $charset_collate;";

  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);

  // Check if table was created successfully
  if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
    error_log('Order Product Adder: Failed to create database table ' . $table_name);
  }
}

// Add menu item
add_action('admin_menu', 'opa_add_admin_menu');
function opa_add_admin_menu()
{
  add_menu_page(
    __('Order Product Adder', 'order-product-adder'),
    __('Order Product Adder', 'order-product-adder'),
    'manage_woocommerce',
    'order-product-adder',
    'opa_admin_page',
    'dashicons-plus',
    56
  );
}

// Enqueue scripts and styles
add_action('admin_enqueue_scripts', 'opa_enqueue_scripts');
function opa_enqueue_scripts($hook)
{
  if ($hook != 'toplevel_page_order-product-adder') {
    return;
  }

  wp_enqueue_style('opa-admin-style', OPA_PLUGIN_URL . 'css/admin-style.css', array(), OPA_VERSION);
  wp_enqueue_script('opa-admin-script', OPA_PLUGIN_URL . 'js/admin-script.js', array('jquery'), OPA_VERSION, true);
  wp_localize_script('opa-admin-script', 'opaAjax', array(
    'ajaxurl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('opa-ajax-nonce')
  ));
}

// Admin page HTML
function opa_admin_page()
{
?>
  <div class="wrap">
    <h1><?php echo esc_html__('Order Product Adder', 'order-product-adder'); ?></h1>

    <div class="opa-container">
      <div class="opa-form">
        <h2><?php echo esc_html__('Add Products to Order', 'order-product-adder'); ?></h2>
        <form id="opa-add-product-form">
          <div class="form-group">
            <label for="order_ids"><?php echo esc_html__('Order IDs (comma-separated):', 'order-product-adder'); ?></label>
            <input type="text" id="order_ids" name="order_ids" required>
          </div>

          <div class="form-group">
            <label for="product_sku"><?php echo esc_html__('Product SKU:', 'order-product-adder'); ?></label>
            <input type="text" id="product_sku" name="product_sku" required>
          </div>

          <div class="form-group">
            <label for="quantity"><?php echo esc_html__('Quantity:', 'order-product-adder'); ?></label>
            <input type="number" id="quantity" name="quantity" min="1" value="1" required>
          </div>

          <button type="submit" class="button button-primary"><?php echo esc_html__('Add Products', 'order-product-adder'); ?></button>
        </form>
      </div>

      <div class="opa-logs">
        <h2><?php echo esc_html__('Logs', 'order-product-adder'); ?></h2>
        <div id="opa-log-container">
          <!-- Logs will be loaded here via AJAX -->
        </div>
      </div>
    </div>
  </div>
<?php
}

// AJAX handler for adding products
add_action('wp_ajax_opa_add_product', 'opa_add_product_to_orders');
function opa_add_product_to_orders()
{
  check_ajax_referer('opa-ajax-nonce', 'nonce');

  // Check user capabilities
  if (!current_user_can('manage_woocommerce')) {
    wp_send_json_error(array('message' => __('Insufficient permissions', 'order-product-adder')));
    return;
  }

  // Validate and sanitize inputs
  if (empty($_POST['order_ids']) || empty($_POST['product_sku']) || empty($_POST['quantity'])) {
    wp_send_json_error(array('message' => __('Missing required fields', 'order-product-adder')));
    return;
  }

  $order_ids = array_filter(array_map('absint', explode(',', wp_unslash($_POST['order_ids']))));
  $product_sku = sanitize_text_field(wp_unslash($_POST['product_sku']));
  $quantity = absint($_POST['quantity']);

  // Validate quantity
  if ($quantity < 1) {
    wp_send_json_error(array('message' => __('Quantity must be at least 1', 'order-product-adder')));
    return;
  }

  $product_id = wc_get_product_id_by_sku($product_sku);
  if (!$product_id) {
    wp_send_json_error(array('message' => __('Product not found', 'order-product-adder')));
    return;
  }

  $results = array();
  foreach ($order_ids as $order_id) {
    $order_id = trim($order_id);
    $order = wc_get_order($order_id);

    if (!$order) {
      opa_log($order_id, $product_sku, $quantity, 'error', __('Order not found', 'order-product-adder'));
      continue;
    }

    try {
      $product = wc_get_product($product_id);

      if (!$product) {
        throw new Exception(__('Product could not be loaded', 'order-product-adder'));
      }

      // Use modern WooCommerce method to add product
      $item = new WC_Order_Item_Product();
      $item->set_product($product);
      $item->set_quantity($quantity);

      // Add item to order
      $order->add_item($item);
      $order->calculate_totals();
      $order->save();

      opa_log($order_id, $product_sku, $quantity, 'success', __('Product added successfully', 'order-product-adder'));
      $results[] = array(
        'order_id' => $order_id,
        'status' => 'success',
        'message' => __('Product added successfully', 'order-product-adder')
      );
    } catch (Exception $e) {
      opa_log($order_id, $product_sku, $quantity, 'error', $e->getMessage());
      $results[] = array(
        'order_id' => $order_id,
        'status' => 'error',
        'message' => $e->getMessage()
      );
    }
  }

  wp_send_json_success(array('results' => $results));
}

// Helper function to add log entry
function opa_log($order_id, $product_sku, $quantity, $status, $message)
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'opa_logs';

  $result = $wpdb->insert(
    $table_name,
    array(
      'order_id' => $order_id,
      'product_sku' => $product_sku,
      'quantity' => $quantity,
      'status' => $status,
      'message' => $message
    ),
    array('%d', '%s', '%d', '%s', '%s')
  );

  // Log database errors
  if ($result === false) {
    error_log('Order Product Adder: Database insert failed - ' . $wpdb->last_error);
  }

  return $result;
}

// AJAX handler for fetching logs
add_action('wp_ajax_opa_get_logs', 'opa_get_logs');
function opa_get_logs()
{
  check_ajax_referer('opa-ajax-nonce', 'nonce');

  // Check user capabilities
  if (!current_user_can('manage_woocommerce')) {
    wp_send_json_error(array('message' => __('Insufficient permissions', 'order-product-adder')));
    return;
  }

  global $wpdb;
  $table_name = $wpdb->prefix . 'opa_logs';

  // Use prepared statement for security
  $logs = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d",
      50
    )
  );

  // Check for database errors
  if ($logs === null) {
    error_log('Order Product Adder: Database query failed - ' . $wpdb->last_error);
    wp_send_json_error(array('message' => __('Failed to retrieve logs', 'order-product-adder')));
    return;
  }

  wp_send_json_success(array('logs' => $logs));
}
