<?php

/**
 * Plugin Name: Order Product Adder
 * Description: Add products to existing orders with logging functionality
 * Version: 1.1.0
 * Author: Gabriel Kanev
 * Author URI: https://gkanev.com
 * Requires at least: 6.0
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

// Check if WooCommerce is active
add_action('plugins_loaded', 'opa_check_woocommerce');
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
        id bigint(20) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        product_sku varchar(100) NOT NULL,
        quantity int(11) NOT NULL,
        status varchar(50) NOT NULL,
        message text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
}

// Add menu item
add_action('admin_menu', 'opa_add_admin_menu');
function opa_add_admin_menu()
{
  add_menu_page(
    'Order Product Adder',
    'Order Product Adder',
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

  wp_enqueue_style('opa-admin-style', plugins_url('css/admin-style.css', __FILE__));
  wp_enqueue_script('opa-admin-script', plugins_url('js/admin-script.js', __FILE__), array('jquery'), null, true);
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
    <h1>Order Product Adder</h1>

    <div class="opa-container">
      <div class="opa-form">
        <h2>Add Products to Order</h2>
        <form id="opa-add-product-form">
          <div class="form-group">
            <label for="order_ids">Order IDs (comma-separated):</label>
            <input type="text" id="order_ids" name="order_ids" required>
          </div>

          <div class="form-group">
            <label for="product_sku">Product SKU:</label>
            <input type="text" id="product_sku" name="product_sku" required>
          </div>

          <div class="form-group">
            <label for="quantity">Quantity:</label>
            <input type="number" id="quantity" name="quantity" min="1" value="1" required>
          </div>

          <button type="submit" class="button button-primary">Add Products</button>
        </form>
      </div>

      <div class="opa-logs">
        <h2>Logs</h2>
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
      opa_log($order_id, $product_sku, $quantity, 'error', 'Order not found');
      continue;
    }

    try {
      $product = wc_get_product($product_id);

      if (!$product) {
        throw new Exception('Product could not be loaded');
      }

      // Use modern WooCommerce method to add product
      $item = new WC_Order_Item_Product();
      $item->set_product($product);
      $item->set_quantity($quantity);

      // Add item to order
      $order->add_item($item);
      $order->calculate_totals();
      $order->save();

      opa_log($order_id, $product_sku, $quantity, 'success', 'Product added successfully');
      $results[] = array(
        'order_id' => $order_id,
        'status' => 'success',
        'message' => 'Product added successfully'
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

  $wpdb->insert(
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

  wp_send_json_success(array('logs' => $logs));
}
