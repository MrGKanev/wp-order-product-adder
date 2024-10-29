jQuery(document).ready(function ($) {
  // Function to load logs
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

  // Function to display logs
  function displayLogs(logs) {
    var container = $("#opa-log-container");
    container.empty();

    logs.forEach(function (log) {
      var logClass = "log-" + log.status;
      var html = `
                <div class="log-entry ${logClass}">
                    <strong>Order #${log.order_id}</strong> - ${log.message}
                    <div class="log-meta">
                        SKU: ${log.product_sku} | Quantity: ${log.quantity} | 
                        ${new Date(log.created_at).toLocaleString()}
                    </div>
                </div>
            `;
      container.append(html);
    });
  }

  // Load logs on page load
  loadLogs();

  // Handle form submission
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
          // Clear form
          form[0].reset();
          // Reload logs
          loadLogs();
        } else {
          alert("Error: " + response.data.message);
        }
      },
      error: function () {
        alert("An error occurred while processing your request.");
      },
      complete: function () {
        submitButton.prop("disabled", false).text("Add Products");
      },
    });
  });

  // Auto-refresh logs every 30 seconds
  setInterval(loadLogs, 30000);
});
