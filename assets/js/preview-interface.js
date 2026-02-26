jQuery(function ($) {
  "use strict";

  var $container = $(".headless-preview-container");
  var $iframe = $("#headless-preview-iframe");
  var $loading = $(".headless-preview-loading");
  var $fallback = $(".headless-preview-fallback");
  var currentUrl = "";
  var blockedTimeout = null;

  function openPreview(url) {
    if (!url) return;

    currentUrl = url;
    $container.show();
    $loading.show();
    $fallback.hide();
    $iframe.attr("src", url);
    startBlockedDetection();
  }

  function closePreview() {
    clearBlockedDetection();
    $container.hide();
    $fallback.hide();
  }

  function refreshPreview() {
    var src = $iframe.attr("src") || currentUrl;
    if (!src) return;

    $loading.show();
    $fallback.hide();
    $iframe.attr("src", appendTimestamp(src));
    startBlockedDetection();
  }

  function startBlockedDetection() {
    clearBlockedDetection();
    blockedTimeout = setTimeout(function () {
      if ($container.is(":visible") && $loading.is(":visible")) {
        $loading.hide();
        $fallback.show();
      }
    }, 4000);
  }

  function clearBlockedDetection() {
    if (blockedTimeout) {
      clearTimeout(blockedTimeout);
      blockedTimeout = null;
    }
  }

  function appendTimestamp(url) {
    return url + (url.indexOf("?") === -1 ? "?" : "&") + "t=" + Date.now();
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

  function clearCacheForCurrentPage(url) {
    if (!url || typeof headlessPreview === "undefined") return;

    $.ajax({
      url: headlessPreview.ajaxUrl,
      type: "POST",
      data: {
        action: "vercel_wp_preview_clear_cache",
        nonce: headlessPreview.nonce,
        url: url,
      },
    }).always(function () {
      if ($container.is(":visible")) {
        refreshPreview();
      }
    });
  }

  $(document).on("click", ".headless-preview-toggle", function (e) {
    e.preventDefault();
    e.stopPropagation();
    openPreview($(this).data("url"));
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
    clearCacheForCurrentPage($(this).data("url"));
  });

  $(document).on("click", ".device-btn", function (e) {
    e.preventDefault();
    updateDeviceButtons($(this));
    updateDeviceViewport($(this).data("device"));
  });

  $iframe.on("load", function () {
    clearBlockedDetection();
    $loading.hide();
    $fallback.hide();
  });

  $iframe.on("error", function () {
    clearBlockedDetection();
    $loading.hide();
    $fallback.show();
  });

  if (typeof headlessPreview !== "undefined" && headlessPreview.autoRefresh) {
    setInterval(function () {
      if ($container.is(":visible")) {
        refreshPreview();
      }
    }, headlessPreview.refreshInterval || 30000);
  }
});
