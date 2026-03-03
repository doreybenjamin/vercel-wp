/**
 * JavaScript for Sanity Split Screen interface - Secure version
 */
jQuery(document).ready(function ($) {
  // Debug and meta-box reorganization
  $(document).ready(function () {
    setTimeout(function () {
      var previewBox = $("#headless-preview-actions");
      var publishBox = $("#submitdiv");
      var attributesBox = $("#pageparentdiv");

      console.log("Debug meta-boxes:");
      console.log("- Preview box:", previewBox.length);
      console.log("- Publish box:", publishBox.length);
      console.log("- Attributes box:", attributesBox.length);

      if (previewBox.length) {
        console.log("Preview meta-box found");

        if (publishBox.length && attributesBox.length) {
          // Insert preview meta-box between Publish and Attributes
          publishBox.after(previewBox);
          console.log("Preview meta-box repositioned");
        } else {
          console.log("Publish or Attributes meta-boxes not found");
        }
      } else {
        console.log("Preview meta-box not found - check settings");
      }
    }, 500);
  });

  // Test de connexion
  $("#test-connection, #test_connection_btn").on("click", function () {
    var button = $(this);
    var result = $("#connection_test_result, #connection-result").first();

    button.prop("disabled", true).text(headlessPreview.strings.loading);
    result.hide();

    $.ajax({
      url: headlessPreview.ajaxUrl,
      type: "POST",
      data: {
        action: "vercel_wp_preview_test_connection",
        nonce: headlessPreview.nonce,
        vercel_url: $("#vercel_preview_url").val(),
      },
      success: function (response) {
        if (response.success) {
          result
            .removeClass("error")
            .addClass("success")
            .html("<strong>✓</strong> " + response.data.message)
            .show();
        } else {
          result
            .removeClass("success")
            .addClass("error")
            .html("<strong>✗</strong> " + response.data.message)
            .show();
        }
      },
      error: function () {
        result
          .removeClass("success")
          .addClass("error")
          .html("<strong>✗</strong> " + headlessPreview.strings.error)
          .show();
      },
      complete: function () {
        button.prop("disabled", false).text("Tester la connexion");
      },
    });
  });

  // Real-time URL validation
  $("#vercel_preview_url, #production_url").on("blur", function () {
    var url = $(this).val();
    var field = $(this);

    if (url && !isValidUrl(url)) {
      field.addClass("error");
      if (!field.next(".url-error").length) {
        field.after(
          '<div class="url-error" style="color: red; font-size: 12px;">URL invalide</div>'
        );
      }
    } else {
      field.removeClass("error");
      field.next(".url-error").remove();
    }
  });

  // Fonction pour valider les URLs
  function isValidUrl(string) {
    try {
      new URL(string);
      return true;
    } catch (_) {
      return false;
    }
  }

  // Fonction pour afficher les notifications
  function showNotice(message, type) {
    var notice = $(
      '<div class="notice notice-' +
        type +
        ' is-dismissible"><p>' +
        message +
        "</p></div>"
    );
    $(".wrap h1").after(notice);

    setTimeout(function () {
      notice.fadeOut(function () {
        notice.remove();
      });
    }, 3000);
  }

  // Gestion du cache
  $(document).on("click", ".headless-preview-clear-cache", function () {
    var button = $(this);
    var url = button.data("url");

    button.prop("disabled", true).text("Vidage...");

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
          showNotice(response.data.message, "success");
          // Refresh iframe if it's open
          var iframe = $("#headless-preview-iframe");
          if (iframe.length && iframe.attr("src")) {
            iframe.attr("src", iframe.attr("src") + "&cache=" + Date.now());
          }
        } else {
          showNotice(response.data.message, "error");
        }
      },
      error: function () {
        showNotice(headlessPreview.strings.error, "error");
      },
      complete: function () {
        button.prop("disabled", false).text("Vider le cache");
      },
    });
  });

  // Function to detect if an iframe is blocked (without security error)
  function detectIframeBlocking(iframe, fallback, status, callback) {
    var iframeLoaded = false;
    var iframeBlocked = false;
    var checkCount = 0;
    var maxChecks = 40; // 20 seconds with checks every 500ms
    var reason = "";
    var firstAttempt = true;

    // Function to check status
    function checkStatus() {
      checkCount++;

      if (iframeLoaded) {
        // Iframe triggered load event
        // Wait a bit then check size
        setTimeout(function () {
          try {
            // Check if iframe has reasonable size
            var iframeHeight = iframe.height();
            var iframeWidth = iframe.width();

            console.log(
              "Iframe dimensions check:",
              iframeWidth + "x" + iframeHeight
            );

            // Check if iframe has visible content
            if (iframeHeight > 50 && iframeWidth > 50) {
              // Check if iframe has real content (not just empty frame)
              try {
                // Try to access iframe content to verify it's not blocked
                var iframeDoc =
                  iframe[0].contentDocument || iframe[0].contentWindow.document;
                if (
                  iframeDoc &&
                  iframeDoc.body &&
                  iframeDoc.body.innerHTML.trim().length > 0
                ) {
                  console.log("Iframe has real content, loaded successfully");
                  iframeBlocked = false;
                  if (callback) callback(false, "Loaded");
                } else {
                  console.log("Iframe appears to be blocked (no content)");
                  iframeBlocked = true;
                  reason = "Empty content - X-Frame-Options blocking detected";
                  if (callback) callback(true, reason);
                }
              } catch (e) {
                // If we can't access content, it's probably blocked
                console.log(
                  "Cannot access iframe content, likely blocked:",
                  e.message
                );
                iframeBlocked = true;
                reason = "Accès refusé - Blocage X-Frame-Options détecté";
                if (callback) callback(true, reason);
              }
            } else {
              // L'iframe est probablement bloquée
              console.log(
                "Iframe appears to be blocked (small size):",
                iframeWidth + "x" + iframeHeight
              );
              iframeBlocked = true;
              reason = "Taille anormale - Blocage X-Frame-Options détecté";
              if (callback) callback(true, reason);
            }
          } catch (e) {
            console.log("Error checking iframe size:", e.message);
            iframeBlocked = true;
            reason = "Erreur d'accès - Blocage de sécurité";
            if (callback) callback(true, reason);
          }
        }, 3000); // Attendre 3 secondes pour que le contenu se stabilise
      } else if (checkCount >= maxChecks) {
        // Timeout - l'iframe n'a pas chargé
        console.log("Iframe load timeout - likely blocked by X-Frame-Options");
        iframeBlocked = true;
        if (firstAttempt) {
          reason = "Chargement en cours...";
          firstAttempt = false;
          // Relancer une vérification après 5 secondes supplémentaires
          setTimeout(function () {
            checkCount = 0;
            maxChecks = 20; // 10 secondes supplémentaires
            checkStatus();
          }, 5000);
        } else {
          reason =
            "Prévisualisation bloquée par le navigateur\n\nVotre navigateur bloque l'affichage de la page pour des raisons de sécurité. Cliquez sur le bouton ci-dessous pour ouvrir la prévisualisation dans un nouvel onglet.";
        }
        if (callback) callback(true, reason);
      } else {
        // Continuer à vérifier
        setTimeout(checkStatus, 500);
      }
    }

    // Écouter les événements de l'iframe
    iframe
      .off("load error")
      .on("load", function () {
        iframeLoaded = true;
        console.log("Iframe load event triggered");
      })
      .on("error", function () {
        console.log("Iframe load error");
        iframeBlocked = true;
        reason = "Erreur de chargement";
        if (callback) callback(true, reason);
      });

    // Démarrer la vérification
    setTimeout(checkStatus, 500);
  }

  // Ouvrir/fermer l'interface Sanity
  $(document).on("click", ".headless-preview-toggle", function (e) {
    e.preventDefault();

    var url = $(this).data("url");
    var container = $(".headless-preview-container");
    var iframe = $("#headless-preview-iframe");
    var fallback = $(".headless-preview-fallback");
    var status = $(".headless-preview-status");

    console.log("Toggle Sanity preview clicked, URL:", url);

    if (container.is(":visible")) {
      // Fermer l'interface
      container.fadeOut(300);
      iframe.attr("src", "");
      $(this).find("span:not(.dashicons)").text("Prévisualiser");
    } else {
      // Ouvrir l'interface
      container.fadeIn(300);
      iframe.attr("src", url);
      $(this).find("span:not(.dashicons)").text("Fermer la prévisualisation");

      // Detect if iframe is blocked
      console.log("Detecting X-Frame-Options issue...");
      fallback.hide(); // Don't show fallback immediately
      status.removeClass("error").addClass("loading");
      var statusMessage = $(".headless-preview-status-message");
      statusMessage
        .removeClass("error success")
        .addClass("loading")
        .text("Loading...");

      // Use new detection function
      detectIframeBlocking(
        iframe,
        fallback,
        status,
        function (isBlocked, reason) {
          var statusMessage = $(".headless-preview-status-message");

          if (isBlocked) {
            console.log("Iframe is blocked, showing fallback. Reason:", reason);
            status.removeClass("loading").addClass("error");
            statusMessage
              .removeClass("loading success")
              .addClass("error")
              .text(reason);
            // Show fallback only if really blocked
            fallback.show();
          } else {
            console.log("Iframe loaded successfully, hiding fallback");
            fallback.hide();
            status.removeClass("loading").addClass("success");
            statusMessage
              .removeClass("loading error")
              .addClass("success")
              .text("Chargé");
          }
        }
      );
    }
  });

  // Fermer l'interface
  $(document).on("click", ".headless-preview-close", function (e) {
    e.preventDefault();

    var container = $(".headless-preview-container");
    var iframe = $("#headless-preview-iframe");
    var toggleButton = $(".headless-preview-toggle");
    var fallback = $(".headless-preview-fallback");
    var status = $(".headless-preview-status");

    container.fadeOut(300);
    iframe.attr("src", "");
    fallback.hide();
    status.removeClass("loading error success");
    toggleButton.find("span:not(.dashicons)").text("Prévisualiser");
  });

  // Actualiser l'iframe
  $(document).on("click", ".headless-preview-refresh-iframe", function (e) {
    e.preventDefault();

    var iframe = $("#headless-preview-iframe");
    var fallback = $(".headless-preview-fallback");
    var status = $(".headless-preview-status");

    if (iframe.attr("src")) {
      fallback.show();
      status.removeClass("error").addClass("loading");
      var statusMessage = $(".headless-preview-status-message");
      statusMessage
        .removeClass("error success")
        .addClass("loading")
        .text("Actualisation...");
      iframe.attr("src", iframe.attr("src") + "&refresh=" + Date.now());

      // Use same detection function
      detectIframeBlocking(
        iframe,
        fallback,
        status,
        function (isBlocked, reason) {
          var statusMessage = $(".headless-preview-status-message");

          if (isBlocked) {
            status.removeClass("loading").addClass("error");
            statusMessage
              .removeClass("loading success")
              .addClass("error")
              .text(reason);
          } else {
            fallback.hide();
            status.removeClass("loading").addClass("success");
            statusMessage
              .removeClass("loading error")
              .addClass("success")
              .text("Chargé");
          }
        }
      );
    }
  });

  // Ouvrir dans un nouvel onglet
  $(document).on(
    "click",
    ".headless-preview-open-new-tab, .headless-preview-open-new-tab-fallback",
    function (e) {
      e.preventDefault();

      var iframe = $("#headless-preview-iframe");
      var url = iframe.attr("src");

      if (url) {
        window.open(url, "_blank");
      }
    }
  );

  // Gestion des onglets (Preview uniquement)
  $(document).on("click", ".headless-preview-tab", function (e) {
    e.preventDefault();
    // Plus besoin de gestion des onglets car il n'y a que Preview
  });

  // No more resizing needed as single panel

  // Reposition preview meta-box after Publish box
  function repositionMetaBox() {
    var metaBox = $("#headless-preview-actions");
    var publishBox = $("#submitdiv");

    if (metaBox.length && publishBox.length) {
      // Insert meta-box after Publish box
      publishBox.after(metaBox);
      console.log("Meta-box repositioned after Publish box");

      // Force style to ensure it's visible
      metaBox.css({
        display: "block",
        "margin-top": "0",
        order: "1",
      });
    }
  }

  // Execute repositioning on load
  $(document).ready(function () {
    setTimeout(repositionMetaBox, 1000);
  });

  // Update button in meta-box
  $(document).on("click", "#headless-preview-save", function (e) {
    e.preventDefault();

    // Trigger original WordPress Update button
    var originalButton = $("#publish");
    if (originalButton.length) {
      originalButton.click();
    } else {
      // Fallback: submit form
      $("#post").submit();
    }
  });

  // Copy URL
  $(document).on("click", ".headless-preview-url-bar .button", function (e) {
    e.preventDefault();

    var button = $(this);
    var input = button.siblings("input");

    if (button.find(".dashicons-admin-page").length) {
      // Copy URL
      input.select();
      document.execCommand("copy");

      // Visual feedback
      var originalText = button.html();
      button.html('<span class="dashicons dashicons-yes"></span>');
      setTimeout(function () {
        button.html(originalText);
      }, 1000);
    }
  });

  // Preview in popup (fallback)
  $(".headless-preview-button").on("click", function (e) {
    e.preventDefault();

    var url = $(this).attr("href");
    var popup = window.open(
      url,
      "headless-preview",
      "width=1200,height=800,scrollbars=yes,resizable=yes"
    );

    if (popup) {
      popup.focus();
    } else {
      alert("Veuillez autoriser les popups pour ce site");
    }
  });

  // Actualisation automatique si activée
  if (headlessPreview.autoRefresh) {
    setInterval(function () {
      // Vérifier si on est sur une page de prévisualisation
      if (window.name === "headless-preview") {
        location.reload();
      }
    }, headlessPreview.refreshInterval || 30000);
  }
});
