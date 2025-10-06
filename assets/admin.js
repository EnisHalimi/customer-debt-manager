/* Admin JavaScript for Customer Debt Manager */

jQuery(document).ready(function ($) {
  // Payment modal functionality
  window.openPaymentModal = function (debtId) {
    $("#debt-id").val(debtId);
    $("#payment-modal").fadeIn();
    $("#payment-amount").focus();
  };

  window.closePaymentModal = function () {
    $("#payment-modal").fadeOut();
    $("#payment-form")[0].reset();
  };

  // Handle payment form submission
  $("#payment-form").on("submit", function (e) {
    e.preventDefault();

    var $form = $(this);
    var $submitBtn = $form.find('button[type="submit"]');
    var originalText = $submitBtn.text();

    // Validate form
    var paymentAmount = parseFloat($("#payment-amount").val());
    if (isNaN(paymentAmount) || paymentAmount <= 0) {
      alert("Please enter a valid payment amount.");
      return;
    }

    // Show loading state
    $submitBtn.text("Processing...").prop("disabled", true);

    var formData = {
      action: "cdm_add_payment",
      nonce: cdm_ajax.nonce,
      debt_id: $("#debt-id").val(),
      payment_amount: paymentAmount,
      payment_type: $("#payment-type").val(),
      payment_note: $("#payment-note").val(),
    };

    $.post(cdm_ajax.ajax_url, formData)
      .done(function (response) {
        if (response.success) {
          showMessage("Payment added successfully!", "success");
          closePaymentModal();

          // Reload page after short delay
          setTimeout(function () {
            location.reload();
          }, 1000);
        } else {
          showMessage(
            "Error: " + (response.data || "Unknown error occurred"),
            "error"
          );
        }
      })
      .fail(function () {
        showMessage(
          "Error: Failed to process payment. Please try again.",
          "error"
        );
      })
      .always(function () {
        $submitBtn.text(originalText).prop("disabled", false);
      });
  });

  // Open payment modal when button is clicked
  $(document).on("click", ".add-payment-btn", function () {
    var debtId = $(this).data("debt-id");
    openPaymentModal(debtId);
  });

  // Close modal when clicking outside
  $(document).on("click", "#payment-modal", function (e) {
    if (e.target === this) {
      closePaymentModal();
    }
  });

  // Close modal with Escape key
  $(document).on("keydown", function (e) {
    if (e.keyCode === 27 && $("#payment-modal").is(":visible")) {
      closePaymentModal();
    }
  });

  // Show message function
  function showMessage(message, type) {
    var $message = $(
      '<div class="cdm-message ' + type + '">' + message + "</div>"
    );

    // Remove existing messages
    $(".cdm-message").remove();

    // Add new message
    $(".wrap h1").after($message);

    // Auto-hide success messages
    if (type === "success") {
      setTimeout(function () {
        $message.fadeOut();
      }, 3000);
    }
  }

  // Debt details AJAX loading
  function loadDebtDetails(debtId) {
    var $container = $("#debt-details-container");

    if (!$container.length) {
      return;
    }

    $container.addClass("cdm-loading");

    $.post(cdm_ajax.ajax_url, {
      action: "cdm_get_debt_details",
      nonce: cdm_ajax.nonce,
      debt_id: debtId,
    })
      .done(function (response) {
        if (response.success) {
          $container.html(response.data);
        } else {
          $container.html("<p>Error loading debt details.</p>");
        }
      })
      .fail(function () {
        $container.html("<p>Error loading debt details.</p>");
      })
      .always(function () {
        $container.removeClass("cdm-loading");
      });
  }

  // Auto-refresh functionality for real-time updates
  if (
    $(".debt-summary-cards").length &&
    typeof cdm_auto_refresh !== "undefined" &&
    cdm_auto_refresh
  ) {
    setInterval(function () {
      $(".debt-summary-cards").load(location.href + " .debt-summary-cards > *");
    }, 30000); // Refresh every 30 seconds
  }

  // Confirmation dialogs for destructive actions
  $(document).on("click", ".debt-delete-btn", function (e) {
    if (
      !confirm(
        "Are you sure you want to delete this debt record? This action cannot be undone."
      )
    ) {
      e.preventDefault();
    }
  });

  // Enhanced search functionality
  $("#debt-search").on("input", function () {
    var searchTerm = $(this).val().toLowerCase();
    var $rows = $(".wp-list-table tbody tr");

    $rows.each(function () {
      var $row = $(this);
      var text = $row.text().toLowerCase();

      if (text.indexOf(searchTerm) === -1) {
        $row.hide();
      } else {
        $row.show();
      }
    });
  });

  // Export functionality
  $("#export-debts").on("click", function () {
    var $btn = $(this);
    var originalText = $btn.text();

    $btn.text("Exporting...").prop("disabled", true);

    // Create form for export
    var $form = $("<form>", {
      method: "POST",
      action: cdm_ajax.ajax_url,
    });

    $form.append(
      '<input type="hidden" name="action" value="cdm_export_debts">'
    );
    $form.append(
      '<input type="hidden" name="nonce" value="' + cdm_ajax.nonce + '">'
    );

    $("body").append($form);
    $form.submit();
    $form.remove();

    setTimeout(function () {
      $btn.text(originalText).prop("disabled", false);
    }, 2000);
  });

  // Bulk actions
  $("#bulk-action-selector-top, #bulk-action-selector-bottom").on(
    "change",
    function () {
      var action = $(this).val();
      var $applyBtn = $(this).siblings(".button.action");

      if (action === "-1") {
        $applyBtn.prop("disabled", true);
      } else {
        $applyBtn.prop("disabled", false);
      }
    }
  );

  // Select all checkboxes
  $("#cb-select-all-1, #cb-select-all-2").on("change", function () {
    var isChecked = $(this).prop("checked");
    $('.wp-list-table tbody input[type="checkbox"]').prop("checked", isChecked);
  });

  // Individual checkbox change
  $(".wp-list-table tbody").on("change", 'input[type="checkbox"]', function () {
    var $allCheckboxes = $('.wp-list-table tbody input[type="checkbox"]');
    var $checkedBoxes = $allCheckboxes.filter(":checked");
    var $selectAllBoxes = $("#cb-select-all-1, #cb-select-all-2");

    if ($checkedBoxes.length === $allCheckboxes.length) {
      $selectAllBoxes.prop("checked", true);
    } else {
      $selectAllBoxes.prop("checked", false);
    }
  });

  // Tooltips for better UX
  if (typeof $.fn.tooltip !== "undefined") {
    $("[data-tooltip]").tooltip({
      content: function () {
        return $(this).data("tooltip");
      },
    });
  }

  // Format currency inputs
  $(".currency-input").on("input", function () {
    var value = $(this).val();

    // Remove non-numeric characters except decimal point
    value = value.replace(/[^0-9.]/g, "");

    // Ensure only one decimal point
    var parts = value.split(".");
    if (parts.length > 2) {
      value = parts[0] + "." + parts.slice(1).join("");
    }

    // Limit to 2 decimal places
    if (parts[1] && parts[1].length > 2) {
      value = parts[0] + "." + parts[1].substring(0, 2);
    }

    $(this).val(value);
  });

  // Auto-save draft notes
  var noteTimeout;
  $("#payment-note").on("input", function () {
    clearTimeout(noteTimeout);
    var note = $(this).val();

    noteTimeout = setTimeout(function () {
      localStorage.setItem("cdm_draft_note", note);
    }, 1000);
  });

  // Restore draft notes
  var draftNote = localStorage.getItem("cdm_draft_note");
  if (draftNote && $("#payment-note").val() === "") {
    $("#payment-note").val(draftNote);
  }

  // Clear draft when form is submitted successfully
  $("#payment-form").on("submit", function () {
    localStorage.removeItem("cdm_draft_note");
  });
});
