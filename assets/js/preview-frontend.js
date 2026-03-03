/**
 * JavaScript for Headless Preview plugin frontend
 */
jQuery(document).ready(function ($) {
  // Preview button in admin bar
  $("#wp-admin-bar-vercel-wp a").on("click", function (e) {
    e.preventDefault();

    var url = $(this).attr("href");
    openPreview(url);
  });

  // Function to open preview
  function openPreview(url) {
    // Create centered popup
    var width = 1200;
    var height = 800;
    var left = (screen.width - width) / 2;
    var top = (screen.height - height) / 2;

    var popup = window.open(
      url,
      "headless-preview",
      "width=" +
        width +
        ",height=" +
        height +
        ",left=" +
        left +
        ",top=" +
        top +
        ",scrollbars=yes,resizable=yes,toolbar=no,menubar=no,location=no,status=no"
    );

    if (popup) {
      popup.focus();

      // Add loading indicator
      popup.document.write(`
                <html>
                    <head>
                        <title>Loading preview...</title>
                        <style>
                            body { 
                                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                                display: flex; 
                                justify-content: center; 
                                align-items: center; 
                                height: 100vh; 
                                margin: 0;
                                background: #f0f0f1;
                            }
                            .loading {
                                text-align: center;
                                padding: 40px;
                                background: white;
                                border-radius: 8px;
                                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                            }
                            .spinner {
                                border: 3px solid #f3f3f3;
                                border-top: 3px solid #0073aa;
                                border-radius: 50%;
                                width: 40px;
                                height: 40px;
                                animation: spin 1s linear infinite;
                                margin: 0 auto 20px;
                            }
                            @keyframes spin {
                                0% { transform: rotate(0deg); }
                                100% { transform: rotate(360deg); }
                            }
                        </style>
                    </head>
                    <body>
                        <div class="loading">
                            <div class="spinner"></div>
                            <h3>Loading preview...</h3>
                            <p>Redirecting to your site</p>
                        </div>
                    </body>
                </html>
            `);

      // Redirect after short delay
      setTimeout(function () {
        popup.location.href = url;
      }, 1000);
    } else {
      alert("Please allow popups for this site to use the preview feature");
    }
  }

  // Message handling between popup and parent page
  window.addEventListener("message", function (event) {
    if (event.data.type === "headless-preview-ready") {
      return;
    }
  });

  // Cache refresh button
  $(".headless-preview-refresh").on("click", function (e) {
    e.preventDefault();

    var button = $(this);
    var url = headlessPreview.currentUrl;

    button.prop("disabled", true).text("Refreshing...");

    $.ajax({
      url: headlessPreview.ajaxUrl,
      type: "POST",
      data: {
        action: "vercel_wp_preview_clear_cache",
        nonce: headlessPreview.nonce,
        url: url,
      },
      success: function (response) {
        if (response.success) {
          // Refresh preview if it's open
          var previewWindow = window.open("", "headless-preview");
          if (previewWindow && !previewWindow.closed) {
            previewWindow.location.reload();
          }

          showNotification("Cache cleared successfully", "success");
        } else {
          showNotification("Error clearing cache", "error");
        }
      },
      error: function () {
        showNotification("Connection error", "error");
      },
      complete: function () {
        button.prop("disabled", false).text("Refresh");
      },
    });
  });

  // Function to display notifications
  function showNotification(message, type) {
    var notification = $(
      '<div class="headless-preview-notification ' +
        type +
        '">' +
        message +
        "</div>"
    );

    $("body").append(notification);

    notification.css({
      position: "fixed",
      top: "20px",
      right: "20px",
      padding: "15px 20px",
      borderRadius: "4px",
      color: "white",
      fontWeight: "bold",
      zIndex: 999999,
      opacity: 0,
      transform: "translateX(100%)",
      transition: "all 0.3s ease",
    });

    if (type === "success") {
      notification.css("background-color", "#46b450");
    } else if (type === "error") {
      notification.css("background-color", "#dc3232");
    }

    // Entry animation
    setTimeout(function () {
      notification.css({
        opacity: 1,
        transform: "translateX(0)",
      });
    }, 100);

    // Auto removal
    setTimeout(function () {
      notification.css({
        opacity: 0,
        transform: "translateX(100%)",
      });

      setTimeout(function () {
        notification.remove();
      }, 300);
    }, 3000);
  }

  // Keyboard shortcut to open preview (Ctrl+P)
  $(document).on("keydown", function (e) {
    if (e.ctrlKey && e.key === "p" && !e.target.matches("input, textarea")) {
      e.preventDefault();

      var previewUrl = $("#wp-admin-bar-vercel-wp a").attr("href");
      if (previewUrl) {
        openPreview(previewUrl);
      }
    }
  });

  // Connection status indicator
  function checkConnectionStatus() {
    $.ajax({
      url: headlessPreview.ajaxUrl,
      type: "POST",
      data: {
        action: "vercel_wp_preview_check_status",
        nonce: headlessPreview.nonce,
      },
      success: function (response) {
        var statusIndicator = $("#wp-admin-bar-vercel-wp .ab-icon");

        if (response.success && response.data.connected) {
          statusIndicator.css("color", "#46b450");
          statusIndicator.attr("title", "Connection active");
        } else {
          statusIndicator.css("color", "#dc3232");
          statusIndicator.attr("title", "Connection inactive");
        }
      },
    });
  }

  // Check status every 30 seconds
  setInterval(checkConnectionStatus, 30000);
  checkConnectionStatus(); // Initial check
});
