<?php

/**
 * Plugin Name: Order Product Adder
 * Description: Add products to existing orders with logging functionality
 * Version: 1.0
 * Author: Your Name
 */

// Prevent direct access
if (!defined('ABSPATH')) {
  exit;
}

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

  $order_ids = explode(',', $_POST['order_ids']);
  $product_sku = sanitize_text_field($_POST['product_sku']);
  $quantity = intval($_POST['quantity']);

  $product_id = wc_get_product_id_by_sku($product_sku);
  if (!$product_id) {
    wp_send_json_error(array('message' => 'Product not found'));
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
      $order->add_product($product, $quantity);
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

  global $wpdb;
  $table_name = $wpdb->prefix . 'opa_logs';

  $logs = $wpdb->get_results("
        SELECT * FROM $table_name 
        ORDER BY created_at DESC 
        LIMIT 50
    ");

  wp_send_json_success(array('logs' => $logs));
}
