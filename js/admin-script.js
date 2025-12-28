/**
 * Order Product Adder Admin JavaScript
 *
 * Handles AJAX operations for adding products to orders and displaying logs.
 *
 * @package OrderProductAdder
 * @since 1.0.0
 */
jQuery(document).ready(function ($) {
  /**
   * Load logs from the server via AJAX.
   *
   * Fetches the most recent logs and displays them in the log container.
   *
   * @since 1.0.0
   */
  function loadLogs() {
    $.ajax({
      url: opaAjax.ajaxurl,
      type: "POST",
      data: {
        action: "opa_get_logs",
        nonce: opaAjax.nonce,
      },
      success: function (response) {
        if (response.success) {
          displayLogs(response.data.logs);
        }
      },
    });
  }

  /**
   * Escape HTML special characters to prevent XSS attacks.
   *
   * @since 1.2.0
   * @param {string} text - The text to escape.
   * @return {string} The escaped text.
   */
  function escapeHtml(text) {
    var map = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    };
    return String(text).replace(/[&<>"']/g, function (m) {
      return map[m];
    });
  }

  /**
   * Display logs in the log container.
   *
   * Creates properly escaped log entries and appends them to the container.
   *
   * @since 1.0.0
   * @param {Array} logs - Array of log objects from the server.
   */
  function displayLogs(logs) {
    var container = $("#opa-log-container");
    container.empty();

    logs.forEach(function (log) {
      var logClass = "log-" + escapeHtml(log.status);
      var logEntry = $("<div>")
        .addClass("log-entry")
        .addClass(logClass);

      // Build log entry elements with proper escaping
      var orderInfo = $("<strong>").text("Order #" + log.order_id);
      var message = document.createTextNode(" - " + log.message);

      var metaDiv = $("<div>").addClass("log-meta");
      var metaText =
        "SKU: " +
        log.product_sku +
        " | Quantity: " +
        log.quantity +
        " | " +
        new Date(log.created_at).toLocaleString();
      metaDiv.text(metaText);

      logEntry.append(orderInfo);
      logEntry.append(message);
      logEntry.append(metaDiv);
      container.append(logEntry);
    });
  }

  // Load logs when page is ready
  loadLogs();

  /**
   * Handle form submission for adding products to orders.
   *
   * Submits the form data via AJAX and handles the response.
   *
   * @since 1.0.0
   */
  $("#opa-add-product-form").on("submit", function (e) {
    e.preventDefault();

    var form = $(this);
    var submitButton = form.find('button[type="submit"]');

    submitButton.prop("disabled", true).text("Adding...");

    $.ajax({
      url: opaAjax.ajaxurl,
      type: "POST",
      data: {
        action: "opa_add_product",
        nonce: opaAjax.nonce,
        order_ids: $("#order_ids").val(),
        product_sku: $("#product_sku").val(),
        quantity: $("#quantity").val(),
      },
      success: function (response) {
        if (response.success) {
          // Reset form and reload logs on success
          form[0].reset();
          loadLogs();
        } else {
          alert("Error: " + response.data.message);
        }
      },
      error: function () {
        alert("An error occurred while processing your request.");
      },
      complete: function () {
        // Re-enable button after request completes
        submitButton.prop("disabled", false).text("Add Products");
      },
    });
  });

  /**
   * Auto-refresh logs every 30 seconds.
   *
   * @since 1.0.0
   */
  setInterval(loadLogs, 30000);
});
