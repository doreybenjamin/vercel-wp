jQuery(function ($) {
  "use strict";

  if (typeof headlessPreviewNative === "undefined") {
    return;
  }

  var isPreparing = false;

  function getPostId() {
    var postId = parseInt($("#post_ID").val(), 10);
    if (!postId || isNaN(postId)) {
      return 0;
    }
    return postId;
  }

  function captureSnapshot() {
    var snapshot = {
      title: $("#title").val() || "",
      content: $("#content").val() || "",
      excerpt: $("#excerpt").val() || "",
      meta: {},
    };

    try {
      if (
        window.wp &&
        window.wp.data &&
        typeof window.wp.data.select === "function" &&
        window.wp.data.select("core/editor")
      ) {
        var editorSelect = window.wp.data.select("core/editor");
        if (editorSelect) {
          var title = editorSelect.getEditedPostAttribute("title");
          var content =
            typeof editorSelect.getEditedPostContent === "function"
              ? editorSelect.getEditedPostContent()
              : editorSelect.getEditedPostAttribute("content");
          var excerpt = editorSelect.getEditedPostAttribute("excerpt");
          var meta = editorSelect.getEditedPostAttribute("meta");

          if (typeof title === "string" && title) snapshot.title = title;
          if (typeof content === "string" && content) snapshot.content = content;
          if (typeof excerpt === "string" && excerpt) snapshot.excerpt = excerpt;
          if (meta && typeof meta === "object") snapshot.meta = meta;
        }
      }
    } catch (e) {
      // Keep classic snapshot fallback.
    }

    return snapshot;
  }

  function serializeEditorForm() {
    var $form = $("#post");
    if (!$form.length) {
      return "";
    }
    return $form.serialize();
  }

  function addQueryParams(url, params) {
    if (!url) {
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
      return url;
    }
  }

  function preparePreviewSession() {
    return new Promise(function (resolve) {
      var postId = getPostId();
      if (!postId) {
        resolve(null);
        return;
      }

      try {
        if (window.tinymce && typeof window.tinymce.triggerSave === "function") {
          window.tinymce.triggerSave();
        }
      } catch (e) {
        // Ignore TinyMCE sync errors.
      }

      $.ajax({
        url: headlessPreviewNative.ajaxUrl,
        type: "POST",
        dataType: "json",
        data: {
          action: "vercel_wp_preview_prepare_session",
          nonce: headlessPreviewNative.nonce,
          post_id: postId,
          snapshot: JSON.stringify(captureSnapshot()),
          form_data: serializeEditorForm(),
        },
        success: function (response) {
          if (response && response.success && response.data && response.data.token) {
            resolve({
              token: response.data.token,
              endpoint: response.data.endpoint || "",
              postId: postId,
            });
            return;
          }
          resolve(null);
        },
        error: function () {
          resolve(null);
        },
      });
    });
  }

  function openPreview(url, target) {
    if (!target || target === "_self") {
      window.location.href = url;
      return;
    }

    window.open(url, target);
  }

  function resolvePreviewLink($trigger) {
    if ($trigger.is("a") && $trigger.attr("href")) {
      return {
        url: $trigger.attr("href"),
        target: $trigger.attr("target") || "_blank",
      };
    }

    var $anchor = $trigger.closest("a[href]");
    if ($anchor.length) {
      return {
        url: $anchor.attr("href"),
        target: $anchor.attr("target") || "_blank",
      };
    }

    return null;
  }

  $(document).on("click", "#post-preview, #preview-action a", function (e) {
    var $trigger = $(this);
    var link = resolvePreviewLink($trigger);
    if (!link || !link.url) {
      return;
    }

    if (isPreparing) {
      e.preventDefault();
      return;
    }

    e.preventDefault();
    isPreparing = true;

    preparePreviewSession()
      .then(function (session) {
        if (!session) {
          return link.url;
        }

        var params = {};
        params[headlessPreviewNative.previewSessionParam || "wp_preview_session"] = session.token;
        params[headlessPreviewNative.previewSessionPostIdParam || "wp_preview_post_id"] =
          session.postId;
        if (session.endpoint) {
          params[headlessPreviewNative.previewSessionEndpointParam || "wp_preview_endpoint"] =
            session.endpoint;
        }

        return addQueryParams(link.url, params);
      })
      .catch(function () {
        return link.url;
      })
      .then(function (finalUrl) {
        openPreview(finalUrl || link.url, link.target);
      })
      .finally(function () {
        isPreparing = false;
      });
  });
});
