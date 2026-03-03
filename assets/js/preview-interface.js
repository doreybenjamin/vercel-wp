jQuery(function ($) {
  "use strict";

  var $container = $(".headless-preview-container");
  if (!$container.length) {
    return;
  }

  var $iframe = $("#headless-preview-iframe");
  if (!$iframe.length) {
    $iframe = $container.find("iframe").first();
  }

  var $loading = $(".headless-preview-loading");
  var $fallback = $(".headless-preview-fallback");
  var $statusDot = $container.find(".headless-preview-status").first();
  var $statusLabel = $container.find(".headless-preview-status-label").first();
  if (!$statusLabel.length) {
    $statusLabel = $container.find(".headless-preview-status-container span").first();
  }

  var currentUrl = "";
  var latestBasePreviewUrl = "";
  var blockedTimeout = null;
  var isOpeningPreview = false;
  var classicBaseline = captureClassicSnapshot();

  function t(key, fallback) {
    if (
      typeof headlessPreview !== "undefined" &&
      headlessPreview.strings &&
      headlessPreview.strings[key]
    ) {
      return headlessPreview.strings[key];
    }
    return fallback;
  }

  function nowLabel() {
    try {
      return new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
    } catch (e) {
      return "";
    }
  }

  function setStatus(state, message) {
    var color = "#10b981";
    if (state === "loading") color = "#f59e0b";
    if (state === "error") color = "#dc2626";
    if (state === "idle") color = "#6b7280";

    if ($statusDot.length) {
      $statusDot.css("background", color);
    }
    if ($statusLabel.length && message) {
      $statusLabel.text(message);
    }
  }

  function showNotification(message, type) {
    if (!message) {
      return;
    }

    var $notif = $(".headless-preview-notification");
    if (!$notif.length) {
      $notif = $('<div class="headless-preview-notification"></div>').appendTo("body");
    }

    $notif.removeClass("success error show").text(message);
    if (type === "success") {
      $notif.addClass("success");
    }
    if (type === "error") {
      $notif.addClass("error");
    }

    requestAnimationFrame(function () {
      $notif.addClass("show");
      setTimeout(function () {
        $notif.removeClass("show");
      }, 2200);
    });
  }

  function appendTimestamp(url) {
    return url + (url.indexOf("?") === -1 ? "?" : "&") + "t=" + Date.now();
  }

  function isDraftRevalidateMode() {
    return (
      typeof headlessPreview !== "undefined" &&
      headlessPreview.framework === "draft_revalidate"
    );
  }

  function getEditorPostId() {
    var postId = parseInt($("#post_ID").val(), 10);
    if (!postId && typeof headlessPreview !== "undefined" && headlessPreview.postId) {
      postId = parseInt(headlessPreview.postId, 10);
    }

    if (!postId || isNaN(postId)) {
      return 0;
    }

    return postId;
  }

  function normalizeEditorTextValue(value) {
    if (typeof value === "string") {
      return value;
    }

    if (value && typeof value === "object") {
      if (typeof value.raw === "string") {
        return value.raw;
      }
      if (typeof value.rendered === "string") {
        return value.rendered;
      }
    }

    return "";
  }

  function captureEditorSnapshot() {
    var snapshot = {
      title: "",
      content: "",
      excerpt: "",
      meta: {},
    };

    if (hasBlockEditorStore()) {
      try {
        var editorSelect = window.wp.data.select("core/editor");
        if (editorSelect) {
          snapshot.title = normalizeEditorTextValue(editorSelect.getEditedPostAttribute("title"));
          if (typeof editorSelect.getEditedPostContent === "function") {
            snapshot.content = normalizeEditorTextValue(editorSelect.getEditedPostContent());
          } else {
            snapshot.content = normalizeEditorTextValue(editorSelect.getEditedPostAttribute("content"));
          }
          snapshot.excerpt = normalizeEditorTextValue(editorSelect.getEditedPostAttribute("excerpt"));

          var meta = editorSelect.getEditedPostAttribute("meta");
          if (meta && typeof meta === "object") {
            snapshot.meta = meta;
          }
        }
      } catch (e) {
        // Fall back to classic editor fields below.
      }
    }

    if (!snapshot.title) {
      snapshot.title = $("#title").val() || "";
    }
    if (!snapshot.content) {
      snapshot.content = $("#content").val() || "";
    }
    if (!snapshot.excerpt) {
      snapshot.excerpt = $("#excerpt").val() || "";
    }

    return snapshot;
  }

  function getSerializedEditorFormData() {
    var $form = $("#post");
    if (!$form.length) {
      return "";
    }

    return $form.serialize();
  }

  function addQueryParamsToUrl(url, params) {
    if (!url || !params || typeof params !== "object") {
      return url;
    }

    try {
      var parsed = new URL(url, window.location.origin);
      Object.keys(params).forEach(function (key) {
        var value = params[key];
        if (value !== undefined && value !== null && value !== "") {
          parsed.searchParams.set(key, String(value));
        }
      });
      return parsed.toString();
    } catch (e) {
      var hash = "";
      var baseUrl = url;
      var hashIndex = baseUrl.indexOf("#");
      if (hashIndex !== -1) {
        hash = baseUrl.substring(hashIndex);
        baseUrl = baseUrl.substring(0, hashIndex);
      }

      var pairs = [];
      Object.keys(params).forEach(function (key) {
        var value = params[key];
        if (value !== undefined && value !== null && value !== "") {
          pairs.push(encodeURIComponent(key) + "=" + encodeURIComponent(String(value)));
        }
      });

      if (!pairs.length) {
        return url;
      }

      var separator = baseUrl.indexOf("?") === -1 ? "?" : "&";
      return baseUrl + separator + pairs.join("&") + hash;
    }
  }

  function preparePreviewSession(baseUrl) {
    if (!isDraftRevalidateMode()) {
      return Promise.resolve(baseUrl);
    }

    var postId = getEditorPostId();
    if (!postId || typeof headlessPreview === "undefined" || !headlessPreview.ajaxUrl) {
      return Promise.resolve(baseUrl);
    }

    var snapshot = captureEditorSnapshot();
    var formData = getSerializedEditorFormData();

    return new Promise(function (resolve) {
      $.ajax({
        url: headlessPreview.ajaxUrl,
        type: "POST",
        dataType: "json",
        data: {
          action: "vercel_wp_preview_prepare_session",
          nonce: headlessPreview.nonce,
          post_id: postId,
          snapshot: JSON.stringify(snapshot),
          form_data: formData,
        },
        success: function (response) {
          if (!response || !response.success || !response.data || !response.data.token) {
            showNotification(
              t(
                "previewSessionError",
                "Session de prévisualisation indisponible. Ouverture de l’aperçu standard."
              ),
              "error"
            );
            resolve(baseUrl);
            return;
          }

          var sessionParam =
            (headlessPreview && headlessPreview.previewSessionParam) || "wp_preview_session";
          var endpointParam =
            (headlessPreview && headlessPreview.previewSessionEndpointParam) || "wp_preview_endpoint";
          var postIdParam =
            (headlessPreview && headlessPreview.previewSessionPostIdParam) || "wp_preview_post_id";

          var queryParams = {};
          queryParams[sessionParam] = response.data.token;
          queryParams[postIdParam] = postId;
          if (response.data.endpoint) {
            queryParams[endpointParam] = response.data.endpoint;
          }

          resolve(addQueryParamsToUrl(baseUrl, queryParams));
        },
        error: function () {
          showNotification(
            t(
              "previewSessionError",
              "Session de prévisualisation indisponible. Ouverture de l’aperçu standard."
            ),
            "error"
          );
          resolve(baseUrl);
        },
      });
    });
  }

  function hasBlockEditorStore() {
    return (
      window.wp &&
      window.wp.data &&
      typeof window.wp.data.select === "function" &&
      window.wp.data.select("core/editor")
    );
  }

  function isBlockEditorDirty() {
    if (!hasBlockEditorStore()) {
      return null;
    }

    try {
      var editorSelect = window.wp.data.select("core/editor");
      if (!editorSelect || typeof editorSelect.isEditedPostDirty !== "function") {
        return null;
      }
      return Boolean(editorSelect.isEditedPostDirty());
    } catch (e) {
      return null;
    }
  }

  function captureClassicSnapshot() {
    var $form = $("#post");
    if (!$form.length) {
      return "";
    }
    return $form.serialize();
  }

  function isClassicEditorDirty() {
    var snapshot = captureClassicSnapshot();
    if (!snapshot) {
      return false;
    }
    return snapshot !== classicBaseline;
  }

  function isEditorDirty() {
    var blockDirty = isBlockEditorDirty();
    if (blockDirty !== null) {
      return blockDirty;
    }
    return isClassicEditorDirty();
  }

  function markClassicAsSynced() {
    var snapshot = captureClassicSnapshot();
    if (snapshot) {
      classicBaseline = snapshot;
    }
  }

  function ensureCopyButton() {
    var $controls = $container.find(".headless-preview-controls").first();
    if (!$controls.length || $controls.find(".headless-preview-copy-url").length) {
      return;
    }

    var buttonHtml =
      '<button type="button" class="button button-secondary headless-preview-copy-url" title="' +
      t("copyUrl", "Copier l’URL") +
      '">' +
      '<span class="dashicons dashicons-admin-page"></span>' +
      "</button>";
    $controls.prepend(buttonHtml);
    hydrateControlTooltips();
  }

  function hydrateControlTooltips() {
    $container.find(".headless-preview-controls button").each(function () {
      var $btn = $(this);
      var label = $btn.attr("title") || $btn.attr("aria-label");
      if (!label) {
        return;
      }

      $btn.attr("aria-label", label);
      $btn.attr("data-tooltip", label);
    });
  }

  function copyText(text) {
    if (!text) {
      return Promise.reject(new Error("empty"));
    }

    if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
      return navigator.clipboard.writeText(text);
    }

    return new Promise(function (resolve, reject) {
      var textarea = document.createElement("textarea");
      textarea.value = text;
      textarea.setAttribute("readonly", "");
      textarea.style.position = "absolute";
      textarea.style.left = "-9999px";
      document.body.appendChild(textarea);
      textarea.select();

      try {
        var ok = document.execCommand("copy");
        document.body.removeChild(textarea);
        if (ok) {
          resolve();
        } else {
          reject(new Error("copy_failed"));
        }
      } catch (e) {
        document.body.removeChild(textarea);
        reject(e);
      }
    });
  }

  function openPreview(url) {
    if (!url) return;

    ensureCopyButton();
    hydrateControlTooltips();
    currentUrl = appendTimestamp(url);
    $container.show().addClass("headless-preview-visible");
    $("body").addClass("headless-preview-open");
    $loading.show();
    $fallback.hide();
    setStatus("loading", t("loadingPreview", "Chargement de la prévisualisation…"));
    $iframe.attr("src", currentUrl);
    startBlockedDetection();
  }

  function closePreview() {
    clearBlockedDetection();
    $container.removeClass("headless-preview-visible").hide();
    $("body").removeClass("headless-preview-open");
    $fallback.hide();
    setStatus("idle", t("previewReady", "Prévisualisation prête"));
  }

  function refreshPreview() {
    var baseUrl =
      latestBasePreviewUrl || $(".headless-preview-toggle").first().data("url") || currentUrl;
    if (!baseUrl) return;

    $loading.show();
    $fallback.hide();
    setStatus("loading", t("loadingPreview", "Chargement de la prévisualisation…"));
    syncAndBuildPreviewUrl(baseUrl).then(function (preparedUrl) {
      currentUrl = appendTimestamp(preparedUrl || baseUrl);
      $iframe.attr("src", currentUrl);
      startBlockedDetection();
    });
  }

  function startBlockedDetection() {
    clearBlockedDetection();
    blockedTimeout = setTimeout(function () {
      if ($container.is(":visible") && $loading.is(":visible")) {
        $loading.hide();
        $fallback.show();
        setStatus("error", t("previewError", "Prévisualisation indisponible"));
      }
    }, 4000);
  }

  function clearBlockedDetection() {
    if (blockedTimeout) {
      clearTimeout(blockedTimeout);
      blockedTimeout = null;
    }
  }

  function updateDeviceButtons($active) {
    $(".device-btn")
      .removeClass("active")
      .css({ background: "white", color: "#666", "border-color": "#ddd" });

    $active
      .addClass("active")
      .css({ background: "#0073aa", color: "white", "border-color": "#0073aa" });
  }

  function updateDeviceViewport(device) {
    var width = "100%";
    if (device === "tablet") width = "768px";
    if (device === "mobile") width = "375px";

    $(".headless-preview-iframe-container").css({
      display: "flex",
      "justify-content": "center",
    });
    $iframe.css({ width: width, "max-width": width });
  }

  function clearCacheForCurrentPage(url, $triggerButton) {
    if (!url || typeof headlessPreview === "undefined") return;

    var $button =
      $triggerButton && $triggerButton.length
        ? $triggerButton
        : $(".headless-preview-clear-cache").first();
    var originalHtml = $button.data("original-html");
    if (!originalHtml) {
      originalHtml = $button.html();
      $button.data("original-html", originalHtml);
    }

    var clearingLabel = t("clearingCache", "Vidage du cache…");
    var startedAt = Date.now();
    var minimumLoadingMs = 600;
    var originalTitle = $button.attr("title") || "";
    var originalTooltip = $button.attr("data-tooltip") || "";
    var originalAria = $button.attr("aria-label") || "";

    $button
      .prop("disabled", true)
      .addClass("loading")
      .attr("aria-busy", "true")
      .attr("title", clearingLabel)
      .attr("data-tooltip", clearingLabel)
      .attr("aria-label", clearingLabel)
      .html('<span class="dashicons dashicons-update"></span> ' + clearingLabel);
    setStatus("loading", clearingLabel);

    $.ajax({
      url: headlessPreview.ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "vercel_wp_preview_clear_cache",
        nonce: headlessPreview.nonce,
        url: url,
      },
    })
      .done(function (response) {
        var message =
          (response &&
            response.data &&
            typeof response.data.message === "string" &&
            response.data.message) ||
          t("cacheCleared", "Cache vidé avec succès");

        showNotification(message, "success");
        setStatus("ready", t("previewUpdatedAt", "Prévisualisation mise à jour à") + " " + nowLabel());
      })
      .fail(function (xhr) {
        var message =
          (xhr &&
            xhr.responseJSON &&
            xhr.responseJSON.data &&
            typeof xhr.responseJSON.data.message === "string" &&
            xhr.responseJSON.data.message) ||
          t("cacheClearFailed", "Impossible de vider le cache");

        showNotification(message, "error");
        setStatus("error", message);
      })
      .always(function () {
        var elapsed = Date.now() - startedAt;
        var waitMs = Math.max(0, minimumLoadingMs - elapsed);

        setTimeout(function () {
          $button
            .prop("disabled", false)
            .removeClass("loading")
            .removeAttr("aria-busy")
            .html(originalHtml);

          if (originalTitle) {
            $button.attr("title", originalTitle);
          } else {
            $button.removeAttr("title");
          }

          if (originalTooltip) {
            $button.attr("data-tooltip", originalTooltip);
          } else {
            $button.removeAttr("data-tooltip");
          }

          if (originalAria) {
            $button.attr("aria-label", originalAria);
          } else {
            $button.removeAttr("aria-label");
          }

          if ($container.is(":visible")) {
            refreshPreview();
          }
        }, waitMs);
      });
  }

  function syncBlockEditorIfNeeded() {
    try {
      if (!window.wp || !window.wp.data || !window.wp.data.select || !window.wp.data.dispatch) {
        return null;
      }
      var editorSelect = window.wp.data.select("core/editor");
      var editorDispatch = window.wp.data.dispatch("core/editor");
      if (!editorSelect || !editorDispatch || typeof editorDispatch.autosave !== "function") {
        return null;
      }
      if (typeof editorSelect.isEditedPostDirty === "function" && !editorSelect.isEditedPostDirty()) {
        return null;
      }

      return Promise.resolve(editorDispatch.autosave()).catch(function () {
        return null;
      });
    } catch (e) {
      return null;
    }
  }

  function syncClassicAutosaveIfAvailable() {
    try {
      if (window.tinymce && typeof window.tinymce.triggerSave === "function") {
        window.tinymce.triggerSave();
      }

      if (
        window.autosave &&
        window.autosave.server &&
        typeof window.autosave.server.triggerSave === "function"
      ) {
        window.autosave.server.triggerSave();
        return new Promise(function (resolve) {
          setTimeout(resolve, 900);
        });
      }
    } catch (e) {
      return null;
    }

    return null;
  }

  function syncUnsavedChanges() {
    var dirtyState = isEditorDirty();
    if (dirtyState === false) {
      return Promise.resolve();
    }

    var syncJobs = [];
    var blockSync = syncBlockEditorIfNeeded();
    var classicSync = syncClassicAutosaveIfAvailable();

    if (blockSync) {
      syncJobs.push(blockSync);
    }
    if (classicSync) {
      syncJobs.push(classicSync);
    }

    if (!syncJobs.length) {
      return Promise.resolve();
    }

    setStatus("loading", t("syncing", "Synchronisation des modifications…"));
    return Promise.allSettled(syncJobs).then(function () {
      markClassicAsSynced();
      return null;
    });
  }

  function syncAndBuildPreviewUrl(baseUrl) {
    var targetUrl =
      baseUrl || latestBasePreviewUrl || $(".headless-preview-toggle").first().data("url");
    if (!targetUrl) {
      return Promise.resolve("");
    }

    return syncUnsavedChanges()
      .catch(function () {
        return null;
      })
      .then(function () {
        return preparePreviewSession(targetUrl);
      })
      .then(function (preparedUrl) {
        return preparedUrl || targetUrl;
      });
  }

  $(document).on("click", ".headless-preview-toggle", function (e) {
    e.preventDefault();
    e.stopPropagation();

    if (isOpeningPreview) {
      return;
    }

    var $button = $(this);
    var baseUrl = $button.data("url");
    if (!baseUrl) {
      return;
    }

    latestBasePreviewUrl = baseUrl;

    var originalHtml = $button.html();
    isOpeningPreview = true;
    $button
      .prop("disabled", true)
      .addClass("loading")
      .html('<span class="dashicons dashicons-update"></span>' + t("syncing", "Synchronisation…"));

    syncAndBuildPreviewUrl(baseUrl)
      .then(function (preparedUrl) {
        openPreview(preparedUrl || baseUrl);
      })
      .finally(function () {
        $button.prop("disabled", false).removeClass("loading").html(originalHtml);
        isOpeningPreview = false;
      });
  });

  $(document).on("click", ".headless-preview-close", function (e) {
    e.preventDefault();
    e.stopPropagation();
    closePreview();
  });

  $(document).on("click", ".headless-preview-refresh-iframe", function (e) {
    e.preventDefault();
    refreshPreview();
  });

  $(document).on("click", ".headless-preview-copy-url", function (e) {
    e.preventDefault();
    var $button = $(this);
    var originalHtml = $button.data("original-html") || $button.html();
    $button.data("original-html", originalHtml);

    var url = $iframe.attr("src") || currentUrl || $(".headless-preview-toggle").data("url");
    copyText(url)
      .then(function () {
        var copiedLabel = t("urlCopied", "URL copiée dans le presse-papiers");
        $button
          .html('<span class="dashicons dashicons-yes-alt"></span>')
          .attr("title", copiedLabel)
          .attr("aria-label", copiedLabel)
          .attr("data-tooltip", copiedLabel)
          .addClass("is-copied");
        showNotification(t("urlCopied", "URL copiée dans le presse-papiers"), "success");

        setTimeout(function () {
          var copyLabel = t("copyUrl", "Copier l’URL");
          $button
            .html(originalHtml)
            .attr("title", copyLabel)
            .attr("aria-label", copyLabel)
            .attr("data-tooltip", copyLabel)
            .removeClass("is-copied");
        }, 1400);
      })
      .catch(function () {
        showNotification(t("copyFailed", "Impossible de copier l’URL"), "error");
      });
  });

  $(document).on(
    "click",
    ".headless-preview-open-new-tab, .headless-preview-open-new-tab-fallback, .headless-preview-open-external",
    function (e) {
      e.preventDefault();
      var url = $iframe.attr("src") || currentUrl || $(".headless-preview-toggle").data("url");
      if (url) {
        window.open(url, "_blank");
      }
    }
  );

  $(document).on("click", ".headless-preview-retry", function (e) {
    e.preventDefault();
    refreshPreview();
  });

  $(document).on("click", ".headless-preview-clear-cache", function (e) {
    e.preventDefault();
    clearCacheForCurrentPage($(this).data("url"), $(this));
  });

  $(document).on("click", ".device-btn", function (e) {
    e.preventDefault();
    updateDeviceButtons($(this));
    updateDeviceViewport($(this).data("device"));
  });

  $(document).on("click", ".headless-preview-container", function (e) {
    if (e.target === this) {
      closePreview();
    }
  });

  $(document).on("keydown", function (e) {
    if (e.key === "Escape" && $container.is(":visible")) {
      closePreview();
    }
  });

  $iframe.on("load", function () {
    clearBlockedDetection();
    $loading.hide();
    $fallback.hide();
    var updatedAtText = t("previewUpdatedAt", "Prévisualisation mise à jour à");
    setStatus("ready", updatedAtText + " " + nowLabel());
  });

  $iframe.on("error", function () {
    clearBlockedDetection();
    $loading.hide();
    $fallback.show();
    setStatus("error", t("previewError", "Prévisualisation indisponible"));
  });

  var autoRefreshEnabled =
    typeof headlessPreview !== "undefined" &&
    Boolean(headlessPreview.autoRefresh || headlessPreview.autoRafraîchir);
  if (autoRefreshEnabled) {
    var interval = parseInt(headlessPreview.refreshInterval, 10);
    if (!interval || interval < 5000) {
      interval = 30000;
    }

    setInterval(function () {
      if ($container.is(":visible")) {
        refreshPreview();
      }
    }, interval);
  }

  ensureCopyButton();
  hydrateControlTooltips();
});
