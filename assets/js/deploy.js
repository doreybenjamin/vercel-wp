/**
 * Vercel Deploy JavaScript
 * Secure AJAX implementation for WordPress
 */

(function ($) {
  "use strict";

  var VercelDeploy = {
    isDeploying: false,
    pollingInterval: null,
    statusRequestInProgress: false,
    deploymentStartTime: null,
    debugMode: false, // Disable debug mode - improvements applied

    // Performance optimizations
    pollingConfig: {
      initialDelay: 1000, // 1 second initial delay
      buildingInterval: 2000, // 2 seconds when building (faster refresh)
      readyInterval: 5000, // 5 seconds when ready
      errorInterval: 3000, // 3 seconds when error
      maxPollingTime: 300000, // 5 minutes max polling
      backoffMultiplier: 1.5, // Exponential backoff
    },
    lastStatusCheck: 0,
    consecutiveErrors: 0,
    cache: {
      status: null,
      deployments: null,
      vercelStatus: null,
      lastUpdate: 0,
      ttl: 30000, // 30 seconds cache TTL
    },

    // Cache management
    getCachedData: function (key) {
      var now = Date.now();
      if (this.cache[key] && now - this.cache.lastUpdate < this.cache.ttl) {
        return this.cache[key];
      }
      return null;
    },

    setCachedData: function (key, data) {
      this.cache[key] = data;
      this.cache.lastUpdate = Date.now();
    },

    clearCache: function () {
      this.cache.status = null;
      this.cache.deployments = null;
      this.cache.vercelStatus = null;
      this.cache.lastUpdate = 0;
    },

    // Smart polling with adaptive intervals
    getPollingInterval: function (state) {
      var baseInterval = this.pollingConfig.buildingInterval;

      switch (state) {
        case "BUILDING":
          baseInterval = this.pollingConfig.buildingInterval;
          break;
        case "READY":
          baseInterval = this.pollingConfig.readyInterval;
          break;
        case "ERROR":
        case "CANCELED":
          baseInterval = this.pollingConfig.errorInterval;
          break;
        default:
          baseInterval = this.pollingConfig.buildingInterval;
      }

      // Apply exponential backoff for consecutive errors
      if (this.consecutiveErrors > 0) {
        baseInterval *= Math.pow(
          this.pollingConfig.backoffMultiplier,
          this.consecutiveErrors
        );
        baseInterval = Math.min(baseInterval, 30000); // Max 30 seconds
      }

      return baseInterval;
    },

    // Mobile detection and optimizations
    detectMobile: function () {
      return (
        /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(
          navigator.userAgent
        ) || window.innerWidth <= 768
      );
    },

    // Mobile-optimized touch handling
    handleMobileTouch: function (element, callback) {
      if (!this.isMobile) return;

      var startY = 0;
      var startX = 0;

      element.on("touchstart", function (e) {
        startY = e.originalEvent.touches[0].clientY;
        startX = e.originalEvent.touches[0].clientX;
      });

      element.on("touchend", function (e) {
        var endY = e.originalEvent.changedTouches[0].clientY;
        var endX = e.originalEvent.changedTouches[0].clientX;

        var deltaY = Math.abs(endY - startY);
        var deltaX = Math.abs(endX - startX);

        // If it's a tap (not a swipe)
        if (deltaY < 10 && deltaX < 10) {
          callback.call(this, e);
        }
      });
    },

    // Mobile-optimized notifications
    showMobileNotification: function (message, type, options) {
      if (this.isMobile) {
        // Use native-like notifications on mobile
        options = options || {};
        options.duration = options.duration || 3000; // Shorter duration on mobile

        // Position differently on mobile
        var toast = $(".vercel-toast");
        if (toast.length > 0) {
          toast.css({
            top: "10px",
            right: "10px",
            left: "10px",
            maxWidth: "none",
          });
        }
      }

      this.showNotification(message, type, options);
    },

    debug: function (message, data) {
      if (this.debugMode) {
        console.log(
          "[VERCEL DEBUG]",
          new Date().toLocaleTimeString(),
          "-",
          message,
          data || ""
        );

        // Also show debug info in a visible debug panel
        this.showDebugInfo(message, data);
      }
    },

    showDebugInfo: function (message, data) {
      // Create debug panel if it doesn't exist
      if (!$("#vercel-debug-panel").length) {
        $("body").append(`
          <div id="vercel-debug-panel" style="
            position: fixed;
            top: 10px;
            right: 10px;
            width: 300px;
            max-height: 400px;
            background: #000;
            color: #0f0;
            font-family: monospace;
            font-size: 11px;
            padding: 10px;
            border-radius: 5px;
            z-index: 999999;
            overflow-y: auto;
            border: 1px solid #333;
          ">
            <div style="color: #fff; font-weight: bold; margin-bottom: 10px;">VERCEL DEBUG</div>
            <div id="vercel-debug-content"></div>
          </div>
        `);
      }

      // Add debug message
      var timestamp = new Date().toLocaleTimeString();
      var dataStr = data ? JSON.stringify(data, null, 2) : "";
      var messageHtml = `<div style="margin-bottom: 5px; border-bottom: 1px solid #333; padding-bottom: 3px;">
        <div style="color: #0ff;">${timestamp}</div>
        <div style="color: #0f0;">${message}</div>
        ${
          dataStr
            ? `<div style="color: #ff0; font-size: 10px;">${dataStr}</div>`
            : ""
        }
      </div>`;

      $("#vercel-debug-content").prepend(messageHtml);

      // Keep only last 20 messages
      var messages = $("#vercel-debug-content").children();
      if (messages.length > 20) {
        messages.slice(20).remove();
      }
    },

    init: function () {
      this.debug("Initializing VercelDeploy");

      // Detect mobile device
      this.isMobile = this.detectMobile();
      this.debug("Mobile device detected: " + this.isMobile);

      // Always initialize admin bar functionality globally
      this.initGlobalAdminBar();

      // Check if deployment is in progress from localStorage
      var deploymentInProgress = localStorage.getItem(
        "vercel_deployment_in_progress"
      );
      var deploymentStartTime = localStorage.getItem(
        "vercel_deployment_start_time"
      );

      if (deploymentInProgress === "true" && deploymentStartTime) {
        // Check if deployment is not too old (more than 10 minutes)
        var startTime = new Date(deploymentStartTime).getTime();
        var now = Date.now();
        var timeDiff = now - startTime;
        var maxDeploymentTime = 10 * 60 * 1000; // 10 minutes

        if (timeDiff > maxDeploymentTime) {
          this.debug("Old deployment detected - cleaning up", {
            startTime: startTime,
            now: now,
            timeDiff: timeDiff,
            maxDeploymentTime: maxDeploymentTime,
          });
          this.cleanupOldDeployment();
        } else {
          this.debug("Deployment in progress detected from localStorage", {
            startTime: startTime,
            timeDiff: timeDiff,
          });
          this.isDeploying = true;
          this.deploymentStartTime = startTime;
          this.disableAllDeployButtons();
          // Start polling to check status
          this.startStatusPolling();
        }
      } else if (deploymentInProgress === "true") {
        this.debug("Corrupted localStorage detected - cleaning up");
        this.cleanupOldDeployment();
      }

      // Initialize sensitive fields handling (always, for settings page)
      this.initSensitiveFields();

      // Only initialize page-specific functionality if on the deploy page
      if ($("#build_button").length > 0) {
        this.debug("On deploy page - initializing page-specific functionality");
        this.bindEvents();
        this.loadInitialStatus();
        this.loadVercelStatus();
        this.updateVercelStatusIndicator();
        this.cleanButtonState();
        this.updateAdminBarStatus();
      } else {
        this.debug("Not on deploy page - admin bar only");
        // Always load Vercel status for admin bar on all pages
        this.debug("Loading Vercel status for admin bar");
        this.loadVercelStatus();
        this.updateVercelStatusIndicator();
        // Also load initial status to get current deployment state
        this.loadInitialStatus();
        // Start polling to detect deployment state changes
        this.startStatusPolling();
      }
    },

    cleanupOldDeployment: function () {
      this.debug("Cleaning up old deployment state");

      // Reset all state
      this.isDeploying = false;
      this.deploymentStartTime = null;
      this.stopStatusPolling();

      // Clear localStorage
      localStorage.removeItem("vercel_deployment_in_progress");
      localStorage.removeItem("vercel_deployment_start_time");

      // Re-enable all buttons
      this.enableAllDeployButtons();

      this.debug("Old deployment cleanup completed");
    },

    initGlobalAdminBar: function () {
      this.debug("Initializing global admin bar functionality");

      // Bind admin bar deploy button globally
      $(document).on(
        "click",
        "#wp-admin-bar-vercel-deploy-button",
        function (e) {
          VercelDeploy.handleAdminBarDeploy(e);
        }
      );

      // Bind admin bar status indicator globally
      $(document).on(
        "click",
        "#wp-admin-bar-vercel-status-indicator",
        function (e) {
          VercelDeploy.showDeploymentHistory();
        }
      );

      this.debug("Global admin bar events bound");
    },

    bindEvents: function () {
      // Build button
      $("#build_button").on("click", this.handleBuildClick.bind(this));

      // Load recent deployments automatically
      this.loadRecentDeployments();

      // Load full history button
      $("#load_full_history").on(
        "click",
        this.handleLoadFullHistoryClick.bind(this)
      );

      // Refresh status button
      $("#refresh_status").on("click", this.loadVercelStatus.bind(this));
    },

    loadInitialStatus: function () {
      this.getDeployStatus();
    },

    handleBuildClick: function (e) {
      e.preventDefault();

      // Prevent multiple clicks and spam
      if (this.isDeploying) {
        this.debug("Deploy already in progress, ignoring main button click");
        return false;
      }

      var $button = $(e.target);

      // Additional check: if button is disabled, ignore click
      if (
        $button.prop("disabled") ||
        $button.css("pointer-events") === "none"
      ) {
        this.debug("Button is disabled, ignoring click");
        return false;
      }

      // Validate configuration first
      if (!this.validateConfiguration()) {
        return;
      }

      // Set deploying state immediately
      this.isDeploying = true;
      this.debug("Setting isDeploying to true from main button");

      // Disable both buttons immediately
      this.disableAllDeployButtons();
      this.debug("All deploy buttons disabled from main button");

      // Clear previous status
      this.clearStatusDisplay();

      // Disable button
      this.setButtonLoadingState($button, true);
      $("#build_status")
        .html(
          '<span style="color: #0073aa;">Déploiement déclenché... Attente de confirmation...</span>'
        )
        .css("margin-top", "10px");

      // Update admin bar status immediately
      $("#admin-bar-vercel-deploy-status-badge").attr(
        "src",
        this.getAssetUrl("vercel-building.svg")
      );

      // Store the current timestamp to track the new deployment
      this.deploymentStartTime = Date.now();
      this.debug("Deployment start time recorded from main button", {
        timestamp: this.deploymentStartTime,
      });

      // Store deployment state to survive page reloads
      localStorage.setItem("vercel_deployment_in_progress", "true");
      localStorage.setItem(
        "vercel_deployment_start_time",
        new Date().toISOString()
      );

      // Start polling for status updates immediately
      this.startStatusPolling();
      this.debug("Status polling started from main button");

      // Trigger deploy
      this.triggerDeploy()
        .done(
          function () {
            this.debug("Deploy triggered successfully from main button");
            // Force immediate status check after deploy trigger
            setTimeout(
              function () {
                this.getDeployStatus();
              }.bind(this),
              2000
            ); // Check after 2 seconds
          }.bind(this)
        )
        .fail(
          function (error) {
            this.debug("Deploy failed from main button", error);
            // Reset state on failure
            this.resetDeployState(true); // Called from click context
            $("#build_status").html(
              '<span class="vercel-error">Deploy failed: ' + error + "</span>"
            );
          }.bind(this)
        );
    },

    loadRecentDeployments: function () {
      this.getAllPreviousBuilds(3); // Load only last 3 deployments automatically
    },

    handleLoadFullHistoryClick: function (e) {
      e.preventDefault();
      this.getAllPreviousBuilds(); // Load all deployments
    },

    handleAdminBarDeploy: function (e) {
      e.preventDefault();
      e.stopPropagation();

      this.debug("Admin bar deploy clicked", { isDeploying: this.isDeploying });

      // Prevent multiple clicks and spam
      if (this.isDeploying) {
        this.debug("Deploy already in progress, ignoring click");
        return false;
      }

      // Get the button element directly by ID to avoid jQuery context issues
      var $button = $("#wp-admin-bar-vercel-deploy-button");
      var $buttonContent = $button.find(".ab-item:first");

      if ($button.length === 0) {
        this.debug("Admin bar button not found - aborting");
        return false;
      }

      // Set deploying state immediately
      this.isDeploying = true;
      this.debug("Setting isDeploying to true");

      // Disable both buttons
      this.disableAllDeployButtons();
      this.debug("All deploy buttons disabled");

      // Set building status badge immediately
      $("#admin-bar-vercel-deploy-status-badge").attr(
        "src",
        this.getAssetUrl("vercel-building.svg")
      );
      this.debug("Status badge updated to building");

      // Store the current timestamp to track the new deployment
      this.deploymentStartTime = Date.now();
      this.debug("Deployment start time recorded", {
        timestamp: this.deploymentStartTime,
      });

      // Start polling immediately
      this.startStatusPolling();
      this.debug("Status polling started");

      // Trigger deploy
      this.triggerDeploy()
        .done(
          function () {
            this.debug("Deploy triggered successfully");
          }.bind(this)
        )
        .fail(
          function (error) {
            this.debug("Deploy failed", error);
            // Reset state on failure
            this.resetDeployState(true); // Called from click context
            $buttonContent
              .find(".ab-label")
              .text(vercelDeployNonces.deploy_site_text || "Deploy Site");
            $buttonContent
              .find(".dashicons-hammer")
              .removeClass("dashicons-hammer")
              .addClass("dashicons-warning");

            // Reset status badge on failure
            $("#admin-bar-vercel-deploy-status-badge").attr(
              "src",
              this.getAssetUrl("vercel-failed.svg")
            );
          }.bind(this)
        );
    },

    triggerDeploy: function () {
      return $.ajax({
        type: "POST",
        url: ajaxurl,
        data: {
          action: "vercel_deploy",
          nonce: vercelDeployNonces.deploy,
        },
        timeout: 10000, // 10 second timeout
      })
        .done(function (response) {
          if (response.success) {
            // Show starting message instead of success
            $("#build_status").html(
              '<span style="color: #0073aa;">Déploiement déclenché... Vérification de l\'état en cours...</span>'
            );
            // Don't show success notification - polling will handle status updates
          } else {
            VercelDeploy.showNotification(
              "Deploy failed: " + response.data,
              "error"
            );
            console.error("Deploy failed:", response.data);
            $("#build_status").html(
              '<span class="vercel-error">Deploy failed: ' +
                response.data +
                "</span>"
            );
            // Reset state on failure
            VercelDeploy.resetDeployState();
          }
        })
        .fail(function (xhr, status, error) {
          VercelDeploy.showNotification(
            "Deploy request failed: " + error,
            "error"
          );
          console.error("Deploy request failed:", error);
          $("#build_status").html(
            '<span class="vercel-error">Deploy request failed: ' +
              error +
              "</span>"
          );
          // Reset state on failure
          VercelDeploy.resetDeployState();
        });
    },

    getDeployStatus: function () {
      this.debug("getDeployStatus called");

      // Disable cache for status to always get fresh data
      // This ensures the UI updates immediately after deployment

      // Prevent multiple simultaneous requests
      if (this.statusRequestInProgress) {
        this.debug("Status request already in progress - skipping");
        return;
      }

      this.statusRequestInProgress = true;
      this.lastStatusCheck = Date.now();

      $.ajax({
        type: "POST",
        url: ajaxurl,
        data: {
          action: "vercel_status",
          nonce: vercelDeployNonces.status,
        },
      })
        .done(
          function (response) {
            this.debug("getDeployStatus response received", response);
            if (response.success) {
              this.debug(
                "Status check successful, updating display",
                response.data
              );

              // Cache the successful response
              this.setCachedData("status", response.data);
              this.consecutiveErrors = 0; // Reset error counter

              this.updateStatusDisplay(response.data);
            } else {
              this.debug("Status check failed - no deployment found");
              this.consecutiveErrors++;

              // Show no status message when no deployment found
              $("#status_display").hide();
              $("#no_status_message").show();
            }
            this.statusRequestInProgress = false;
          }.bind(this)
        )
        .fail(
          function (xhr, status, error) {
            this.debug("Status request failed", {
              xhr: xhr,
              status: status,
              error: error,
            });

            this.consecutiveErrors++;

            // Don't show notification for empty errors (likely network issues)
            if (error && error.trim() !== "") {
              this.showNotification("Status request failed: " + error, "error");
            } else {
              this.debug("Status request failed silently (empty error)");
            }

            console.error("Status request failed:", error);
            this.statusRequestInProgress = false;
          }.bind(this)
        );
    },

    getAllPreviousBuilds: function (limit) {
      // Check cache first
      var cachedDeployments = this.getCachedData("deployments");
      if (cachedDeployments) {
        this.debug("Using cached deployments data");
        this.displayPreviousDeployments(cachedDeployments, limit);
        return;
      }

      $.ajax({
        type: "POST",
        url: ajaxurl,
        data: {
          action: "vercel_deployments",
          nonce: vercelDeployNonces.deployments,
        },
      })
        .done(
          function (response) {
            if (response.success) {
              // Cache the successful response
              this.setCachedData("deployments", response.data);
              this.displayPreviousDeployments(response.data, limit);
            } else {
              console.error("Deployments list failed:", response.data);
            }
          }.bind(this)
        )
        .fail(
          function (xhr, status, error) {
            this.debug("Deployments request failed", {
              xhr: xhr,
              status: status,
              error: error,
            });

            // Don't show notification for empty errors (likely network issues)
            if (error && error.trim() !== "") {
              console.error("Deployments request failed:", error);
            } else {
              this.debug("Deployments request failed silently (empty error)");
            }
          }.bind(this)
        );
    },

    updateStatusDisplay: function (data) {
      this.debug("updateStatusDisplay called", data);

      var state = data.state;
      this.debug("Deployment state detected", {
        state: state,
        isDeploying: this.isDeploying,
      });

      // If we're deploying and this is an old deployment (before our start time),
      // ignore ALL old deployments regardless of state to prevent premature reactivation
      if (this.isDeploying && this.deploymentStartTime) {
        var deploymentTime = new Date(data.createdAt).getTime();
        if (deploymentTime < this.deploymentStartTime) {
          this.debug("Ignoring old deployment (any state)", {
            deploymentTime: deploymentTime,
            startTime: this.deploymentStartTime,
            state: state,
            isOlder: deploymentTime < this.deploymentStartTime,
          });
          return; // Don't update the display with old deployment data
        } else {
          this.debug("Processing new deployment", {
            deploymentTime: deploymentTime,
            startTime: this.deploymentStartTime,
            isNewer: deploymentTime >= this.deploymentStartTime,
            state: data.state,
          });
        }
      }

      var createdAt = new Date(data.createdAt);
      var formattedCreatedAt = createdAt.toLocaleString("en-GB", {
        day: "numeric",
        month: "long",
        year: "numeric",
        hour: "numeric",
        minute: "numeric",
        second: "numeric",
      });

      // Show status display and hide no status message (only on deploy page)
      if ($("#status_display").length > 0) {
        $("#status_display").show();
        $("#no_status_message").hide();
      }

      // Update status image
      $("#admin-bar-vercel-deploy-status-badge").attr(
        "src",
        this.getStatusImageSrc(state)
      );
      this.debug("Status badge updated", {
        state: state,
        src: this.getStatusImageSrc(state),
      });

      // Update admin bar button state
      this.updateAdminBarState(state);

      // Update status text with better formatting (only on deploy page)
      if ($("#deploy_finish_time").length > 0) {
        $("#deploy_finish_time").html(
          "<strong>Last Deploy:</strong> " + formattedCreatedAt
        );
        $("#deploy_finish_status").html(
          '<strong>Status:</strong> <span class="vercel-status-' +
            state.toLowerCase() +
            '">' +
            this.getStatusLabel(state) +
            "</span>"
        );
      }

      // Update deploy ID if available (only on deploy page)
      if ($("#deploy_id").length > 0) {
        if (data.uid) {
          $("#deploy_id").html("<strong>Deploy ID:</strong> " + data.uid);
        } else {
          $("#deploy_id").html("");
        }
      }

      // Update branch information (only on deploy page)
      if ($("#deploy_branch").length > 0) {
        var branch =
          data.meta && data.meta.githubCommitRef
            ? data.meta.githubCommitRef
            : data.git && data.git.ref
            ? data.git.ref
            : "main";
        $("#deploy_branch").html(
          "<strong>" +
            (vercelDeployNonces.branch_text || "Branch:") +
            "</strong> " +
            branch
        );
      }

      // Update author information (only on deploy page)
      if ($("#deploy_author").length > 0) {
        var author =
          data.creator && data.creator.username
            ? data.creator.username
            : data.meta && data.meta.githubCommitAuthorName
            ? data.meta.githubCommitAuthorName
            : "Unknown";
        $("#deploy_author").html("<strong>Deployed by:</strong> " + author);
      }

      // Update commit message (only on deploy page)
      if ($("#deploy_commit_message").length > 0) {
        var commitMessage =
          data.meta && data.meta.githubCommitMessage
            ? data.meta.githubCommitMessage.substring(0, 80) +
              (data.meta.githubCommitMessage.length > 80 ? "..." : "")
            : data.git && data.git.commitMessage
            ? data.git.commitMessage.substring(0, 80) +
              (data.git.commitMessage.length > 80 ? "..." : "")
            : "No commit message";
        $("#deploy_commit_message").html(
          "<strong>Commit:</strong> " + commitMessage
        );
      }

      // Update environment (only on deploy page)
      if ($("#deploy_environment").length > 0) {
        var environment = "production"; // Default
        if (data.target) {
          environment = data.target;
        } else if (data.meta && data.meta.githubCommitRef) {
          // If it's a branch other than main/master, it's likely preview
          var branch = data.meta.githubCommitRef;
          if (branch !== "main" && branch !== "master") {
            environment = "preview";
          }
        } else if (data.git && data.git.ref) {
          var branch = data.git.ref;
          if (branch !== "main" && branch !== "master") {
            environment = "preview";
          }
        }
        $("#deploy_environment").html(
          "<strong>Environment:</strong> " + environment
        );
      }

      // Add status class to container for styling (only on deploy page)
      if ($("#status_display").length > 0) {
        $("#status_display")
          .removeClass(
            "vercel-status-ready vercel-status-building vercel-status-error vercel-status-canceled"
          )
          .addClass("vercel-status-" + state.toLowerCase());
      }

      // Only reset deploy state when deployment is actually complete
      // AND we're tracking this specific deployment
      if (state === "READY" && this.isDeploying) {
        this.debug("State is READY - resetting deploy state", {
          isDeploying: this.isDeploying,
          deploymentStartTime: this.deploymentStartTime,
        });
        // Only reset if we're actively deploying
        this.isDeploying = false;
        this.resetDeployState(false); // Not from click context
        // Stop polling since deployment is complete
        this.stopStatusPolling();
        // Force button update immediately
        this.enableAllDeployButtons();
      } else if (
        (state === "ERROR" || state === "CANCELED") &&
        this.isDeploying
      ) {
        this.debug("State is ERROR/CANCELED - resetting deploy state", {
          isDeploying: this.isDeploying,
          deploymentStartTime: this.deploymentStartTime,
        });
        // Only reset if we're actively deploying
        this.isDeploying = false;
        this.resetDeployState(false); // Not from click context
        // Stop polling since deployment is complete
        this.stopStatusPolling();
        // Force button update immediately
        this.enableAllDeployButtons();
      } else if (state === "BUILDING") {
        this.debug(
          "State is BUILDING - starting polling if not already active",
          {
            isDeploying: this.isDeploying,
            deploymentStartTime: this.deploymentStartTime,
          }
        );
        // If we detect a BUILDING state and we're not already polling, start polling
        if (!this.pollingInterval) {
          this.debug("Starting polling for BUILDING state");
          this.startStatusPolling();
        }
        // Disable buttons when BUILDING state is detected
        if (!this.isDeploying) {
          this.debug("BUILDING state detected - disabling buttons");
          this.isDeploying = true;
          this.disableAllDeployButtons();
        }
      } else {
        this.debug("State is " + state + " - keeping deploy state", {
          isDeploying: this.isDeploying,
          deploymentStartTime: this.deploymentStartTime,
        });
        // For other states (like QUEUED), stop polling if not deploying
        if (!this.isDeploying && this.pollingInterval) {
          this.debug("Stopping polling for non-deploying state: " + state);
          this.stopStatusPolling();
        }
      }

      // Update build status message - ALWAYS update if we have deployment data
      this.debug("Updating status display", {
        state: state,
        isDeploying: this.isDeploying,
        deploymentStartTime: this.deploymentStartTime,
        hasDeploymentData: !!data,
      });

      if (state === "READY") {
        $("#build_status")
          .html(
            '<span class="vercel-success">' +
              (vercelDeployNonces.deployment_completed_text ||
                "Déploiement terminé avec succès !") +
              "</span>"
          )
          .css("margin-top", "10px");
        // Force update button text when deployment is complete
        var $mainButton = $("#build_button");
        if ($mainButton.length > 0) {
          this.setButtonLoadingState($mainButton, false);
          this.debug("Button updated to ready state", {
            text: $mainButton.text(),
            disabled: $mainButton.prop("disabled"),
          });
        }
      } else if (state === "ERROR") {
        $("#build_status")
          .html(
            '<span class="vercel-error">' +
              (vercelDeployNonces.deployment_failed_text ||
                "Déploiement échoué") +
              "</span>"
          )
          .css("margin-top", "10px");
        // Force update button text when deployment fails
        var $mainButton = $("#build_button");
        if ($mainButton.length > 0) {
          this.setButtonLoadingState($mainButton, false);
        }
      } else if (state === "BUILDING") {
        $("#build_status")
          .html(
            '<span style="color: #0073aa;">Build en cours sur Vercel...</span>'
          )
          .css("margin-top", "10px");
      } else if (state === "CANCELED") {
        $("#build_status")
          .html(
            '<span class="vercel-error">' +
              (vercelDeployNonces.deployment_canceled_text ||
                "Déploiement annulé") +
              "</span>"
          )
          .css("margin-top", "10px");
        // Force update button text when deployment is canceled
        var $mainButton = $("#build_button");
        if ($mainButton.length > 0) {
          this.setButtonLoadingState($mainButton, false);
        }
      } else if (state === "QUEUED" || state === "INITIALIZING") {
        $("#build_status")
          .html(
            '<span style="color: #0073aa;">Déploiement en file d\'attente...</span>'
          )
          .css("margin-top", "10px");
      }

      this.debug("updateStatusDisplay completed", {
        state: state,
        isDeploying: this.isDeploying,
        buildStatusText: $("#build_status").text(),
      });
    },

    updateAdminBarState: function (state) {
      this.debug("updateAdminBarState called", {
        state: state,
        isDeploying: this.isDeploying,
      });

      var $button = $("#wp-admin-bar-vercel-deploy-button");
      var $buttonContent = $button.find(".ab-item:first");

      if (state === "READY") {
        this.debug("State is READY - updating button text only");
        // Don't reset here - updateStatusDisplay handles it
        $buttonContent
          .find(".ab-label")
          .text(vercelDeployNonces.deploy_site_text || "Deploy Site");
        // Reset icon if it was changed to warning
        $buttonContent
          .find(".dashicons-warning")
          .removeClass("dashicons-warning")
          .addClass("dashicons-hammer");
      } else if (state === "BUILDING") {
        this.debug("State is BUILDING - keeping deploying state");
        // Keep deploying state - don't reset
        $buttonContent.addClass("running").css("opacity", "0.5");
        $buttonContent.addClass("deploying");
        $buttonContent.find(".ab-label").text("Déploiement en cours...");
      } else if (state === "ERROR") {
        this.debug("State is ERROR - updating button text only");
        // Don't reset here - updateStatusDisplay handles it
        $buttonContent
          .find(".ab-label")
          .text(vercelDeployNonces.deploy_site_text || "Deploy Site");
        $buttonContent
          .find(".dashicons-hammer")
          .removeClass("dashicons-hammer")
          .addClass("dashicons-warning");
      } else if (state === "CANCELED") {
        this.debug("State is CANCELED - updating button text only");
        // Don't reset here - updateStatusDisplay handles it
        $buttonContent
          .find(".ab-label")
          .text(vercelDeployNonces.deploy_site_text || "Deploy Site");
        // Reset icon if it was changed to warning
        $buttonContent
          .find(".dashicons-warning")
          .removeClass("dashicons-warning")
          .addClass("dashicons-hammer");
      }

      this.debug("updateAdminBarState completed", {
        finalText: $buttonContent.find(".ab-label").text(),
        finalClasses: $button.attr("class") || "no-classes",
        buttonExists: $button.length > 0,
      });
    },

    displayPreviousDeployments: function (deployments, limit) {
      var container = $("#previous_deploys_container");
      var loadFullHistoryContainer = $("#load_full_history_container");
      container.empty();

      if (deployments.length === 0) {
        container.append(
          '<div class="vercel-no-deployments">' +
            "<p>No deployments found.</p>" +
            "</div>"
        );
        return;
      }

      // Apply limit if specified
      var deploymentsToShow = limit ? deployments.slice(0, limit) : deployments;

      // Show "Load Full History" button if we're showing limited results and there are more deployments
      if (limit && deployments.length > limit) {
        loadFullHistoryContainer.show();
      } else {
        loadFullHistoryContainer.hide();
      }

      deploymentsToShow.forEach(
        function (deployment, index) {
          var createdAt = new Date(deployment.createdAt);
          var formattedCreatedAt = createdAt.toLocaleString("en-GB", {
            day: "numeric",
            month: "long",
            year: "numeric",
            hour: "numeric",
            minute: "numeric",
            second: "numeric",
          });

          var buildingAt = new Date(deployment.buildingAt);
          var formattedBuildingAt = buildingAt.toLocaleString("en-GB", {
            day: "numeric",
            month: "long",
            year: "numeric",
            hour: "numeric",
            minute: "numeric",
            second: "numeric",
          });

          var branch =
            deployment.meta && deployment.meta.githubCommitRef
              ? deployment.meta.githubCommitRef
              : "main";

          var duration = this.calculateDuration(createdAt, buildingAt);
          var isRecent = index < 3; // Mark first 3 as recent

          var deploymentHtml =
            '<div class="vercel-deployment-item' +
            (isRecent ? " new" : "") +
            '">' +
            '<div class="vercel-deployment-info">' +
            "<h4>" +
            (deployment.name || "Deployment #" + (index + 1)) +
            "</h4>" +
            '<div class="vercel-deployment-meta">' +
            "<span><strong>" +
            (vercelDeployNonces.created_text || "Created:") +
            "</strong> " +
            formattedCreatedAt +
            "</span>" +
            "<span><strong>" +
            (vercelDeployNonces.duration_text || "Duration:") +
            "</strong> " +
            duration +
            "</span>" +
            "<span><strong>" +
            (vercelDeployNonces.branch_text || "Branch:") +
            "</strong> " +
            branch +
            "</span>" +
            "<span><strong>" +
            (vercelDeployNonces.status_text || "Status:") +
            '</strong> <span class="vercel-status-' +
            deployment.state.toLowerCase() +
            '">' +
            VercelDeploy.getStatusLabel(deployment.state) +
            "</span></span>" +
            "</div>" +
            "</div>" +
            "</div>";

          container.append(deploymentHtml);
        }.bind(this)
      );
    },

    getStatusImageSrc: function (state) {
      var baseUrl = vercelDeployNonces.assets_url;

      switch (state) {
        case "CANCELED":
          return baseUrl + "vercel-none.svg";
        case "ERROR":
          return baseUrl + "vercel-failed.svg";
        case "INITIALIZING":
        case "QUEUED":
          return baseUrl + "vercel-pending.svg";
        case "READY":
          return baseUrl + "vercel-ready.svg";
        case "BUILDING":
          return baseUrl + "vercel-building.svg";
        default:
          return baseUrl + "vercel-pending.svg";
      }
    },

    getAssetUrl: function (filename) {
      return vercelDeployNonces.assets_url + filename;
    },

    clearStatusDisplay: function () {
      $("#deploy_id").html("");
      $("#deploy_finish_time").html("");
      $("#deploy_finish_status").html("");
      $("#deploy_branch").html("");
      $("#deploy_author").html("");
      $("#deploy_commit_message").html("");
      $("#deploy_environment").html("");
      $("#deploy_loading").html("");
    },

    startStatusPolling: function () {
      this.debug("startStatusPolling called", {
        existingInterval: this.pollingInterval,
        isDeploying: this.isDeploying,
      });

      // Clear any existing polling interval
      if (this.pollingInterval) {
        clearInterval(this.pollingInterval);
        this.debug("Existing polling interval cleared");
      }

      // Start with initial delay
      var self = this;
      var pollOnce = function () {
        self.debug("Polling interval triggered - calling getDeployStatus");
        self.getDeployStatus();

        // Schedule next poll with adaptive interval
        var currentState = self.getCurrentDeploymentState();
        var nextInterval = self.getPollingInterval(currentState);

        self.debug(
          "Next poll scheduled in " +
            nextInterval +
            "ms for state: " +
            currentState
        );

        self.pollingInterval = setTimeout(pollOnce, nextInterval);
      };

      // Start polling with initial delay
      this.pollingInterval = setTimeout(
        pollOnce,
        this.pollingConfig.initialDelay
      );

      this.debug("New adaptive polling started", {
        initialDelay: this.pollingConfig.initialDelay,
      });

      // Stop polling after max time
      setTimeout(
        function () {
          if (this.pollingInterval) {
            this.debug("Polling timeout reached - stopping polling");
            clearTimeout(this.pollingInterval);
            this.pollingInterval = null;
          }
        }.bind(this),
        this.pollingConfig.maxPollingTime
      );
    },

    getCurrentDeploymentState: function () {
      // Try to get current state from DOM or cache
      var $statusDisplay = $("#status_display");
      if ($statusDisplay.length > 0 && $statusDisplay.is(":visible")) {
        var statusClass = $statusDisplay.attr("class");
        if (statusClass) {
          if (statusClass.includes("vercel-status-ready")) return "READY";
          if (statusClass.includes("vercel-status-building")) return "BUILDING";
          if (statusClass.includes("vercel-status-error")) return "ERROR";
          if (statusClass.includes("vercel-status-canceled")) return "CANCELED";
        }
      }

      // Default to BUILDING if deploying
      return this.isDeploying ? "BUILDING" : "READY";
    },

    stopStatusPolling: function () {
      if (this.pollingInterval) {
        clearTimeout(this.pollingInterval);
        this.pollingInterval = null;
        this.debug("Polling stopped");
      } else {
        this.debug("No polling interval to stop");
      }
    },

    disableAllDeployButtons: function () {
      this.debug("Disabling all deploy buttons");

      // Disable admin bar button
      var $adminButton = $("#wp-admin-bar-vercel-deploy-button");
      if ($adminButton.length > 0) {
        $adminButton.addClass("running deploying");
        $adminButton.css({
          opacity: "0.5",
          "pointer-events": "none",
          cursor: "not-allowed",
        });
        $adminButton.off("click");

        var $adminButtonContent = $adminButton.find(".ab-item:first");
        $adminButtonContent.css({
          "pointer-events": "none",
          cursor: "not-allowed",
        });
        $adminButtonContent.find(".ab-label").text("Déploiement en cours...");

        this.debug("Admin bar button disabled", {
          classes: $adminButton.attr("class"),
          opacity: $adminButton.css("opacity"),
          text: $adminButtonContent.find(".ab-label").text(),
        });
      } else {
        this.debug("Admin bar button not found during disable");
      }

      // Disable main button
      var $mainButton = $("#build_button");
      if ($mainButton.length > 0) {
        this.setButtonLoadingState($mainButton, true);
        this.debug("Main button disabled", {
          disabled: $mainButton.prop("disabled"),
          classes: $mainButton.attr("class"),
          text: $mainButton.text(),
        });
      } else {
        this.debug("Main button not found during disable");
      }
    },

    enableAllDeployButtons: function () {
      // Double check that we're not deploying before enabling buttons
      if (this.isDeploying) {
        this.debug("Still deploying, not enabling buttons");
        return;
      }

      this.debug("Enabling all deploy buttons");

      // Enable admin bar button
      var $adminButton = $("#wp-admin-bar-vercel-deploy-button");
      if ($adminButton.length > 0) {
        $adminButton.removeClass("running deploying");

        // Reset all styles on admin button
        $adminButton.css({
          opacity: "1",
          "pointer-events": "auto",
          cursor: "pointer",
        });

        // Apply opacity to the .ab-item element (not the main button)
        var $adminButtonItem = $adminButton.find(".ab-item:first");
        $adminButtonItem.css({
          "pointer-events": "auto",
          opacity: "1",
          cursor: "pointer",
        });

        $adminButtonItem
          .find(".ab-label")
          .text(vercelDeployNonces.deploy_site_text || "Deploy Site");

        // Re-bind click event
        $adminButton.off("click").on("click", function (e) {
          VercelDeploy.handleAdminBarDeploy(e);
        });

        this.debug("Admin bar button enabled", {
          classes: $adminButton.attr("class"),
          opacity: $adminButtonItem.css("opacity"),
          text: $adminButtonItem.find(".ab-label").text(),
        });
      } else {
        this.debug("Admin bar button not found during enable");
      }

      // Enable main button
      var $mainButton = $("#build_button");
      if ($mainButton.length > 0) {
        this.setButtonLoadingState($mainButton, false);
        this.debug("Main button enabled", {
          disabled: $mainButton.prop("disabled"),
          classes: $mainButton.attr("class"),
          text: $mainButton.text(),
        });
      } else {
        this.debug("Main button not found during enable");
      }

      // Update status badge to ready state
      $("#admin-bar-vercel-deploy-status-badge").attr(
        "src",
        this.getAssetUrl("vercel-ready.svg")
      );
      this.debug("Status badge updated to ready");
    },

    resetDeployState: function (fromClickContext) {
      this.debug("resetDeployState called", {
        isDeploying: this.isDeploying,
        fromClickContext: fromClickContext || false,
      });

      this.isDeploying = false;
      this.stopStatusPolling();
      this.debug("isDeploying set to false, polling stopped");

      // Re-enable all buttons
      this.enableAllDeployButtons();

      // Clear localStorage
      localStorage.removeItem("vercel_deployment_in_progress");
      localStorage.removeItem("vercel_deployment_start_time");
      this.debug("LocalStorage cleared");

      // Clear deployment start time
      this.deploymentStartTime = null;
      this.debug("Deployment start time cleared");

      // Force immediate status refresh to update UI
      setTimeout(
        function () {
          this.getDeployStatus();
        }.bind(this),
        1000
      );

      // Also force update button state immediately
      var $mainButton = $("#build_button");
      if ($mainButton.length > 0) {
        this.setButtonLoadingState($mainButton, false);
        this.debug("Button state forced to ready after reset");
      }

      console.log("Deploy state reset - ready for new deployment");
      this.debug("resetDeployState completed");
    },

    showNotification: function (message, type, options) {
      options = options || {};

      // Remove existing notifications
      $(".vercel-toast").remove();

      var iconClass = "dashicons-yes-alt";
      var notificationClass = "vercel-toast-success";
      var title = "Success";

      switch (type) {
        case "error":
          iconClass = "dashicons-warning";
          notificationClass = "vercel-toast-error";
          title = "Error";
          break;
        case "warning":
          iconClass = "dashicons-warning";
          notificationClass = "vercel-toast-warning";
          title = "Warning";
          break;
        case "info":
          iconClass = "dashicons-info";
          notificationClass = "vercel-toast-info";
          title = "Info";
          break;
        default:
          iconClass = "dashicons-yes-alt";
          notificationClass = "vercel-toast-success";
          title = "Success";
      }

      var toast = $(
        '<div class="vercel-toast ' +
          notificationClass +
          '">' +
          '<div class="vercel-toast-content">' +
          '<div class="vercel-toast-icon">' +
          '<span class="dashicons ' +
          iconClass +
          '"></span>' +
          "</div>" +
          '<div class="vercel-toast-body">' +
          '<div class="vercel-toast-title">' +
          title +
          "</div>" +
          '<div class="vercel-toast-message">' +
          message +
          "</div>" +
          "</div>" +
          '<div class="vercel-toast-close">' +
          '<span class="dashicons dashicons-no-alt"></span>' +
          "</div>" +
          "</div>" +
          "</div>"
      );

      // Add to body
      $("body").append(toast);

      // Animate in
      toast
        .css({
          transform: "translateX(100%)",
          opacity: 0,
        })
        .animate(
          {
            transform: "translateX(0)",
            opacity: 1,
          },
          300
        );

      // Close button functionality
      toast.find(".vercel-toast-close").on("click", function () {
        this.hideToast(toast);
      });

      // Auto-hide after specified time (default 5 seconds)
      var duration = options.duration || 5000;
      if (duration > 0) {
        setTimeout(
          function () {
            this.hideToast(toast);
          }.bind(this),
          duration
        );
      }
    },

    hideToast: function (toast) {
      toast.animate(
        {
          transform: "translateX(100%)",
          opacity: 0,
        },
        300,
        function () {
          toast.remove();
        }
      );
    },

    validateConfiguration: function () {
      // Check if we're on the settings page or main page
      var webhookUrl = "";

      // Try to get from current page fields first
      var $webhookField = $('input[name="webhook_address"]');
      if ($webhookField.length > 0) {
        webhookUrl = $webhookField.val();
      } else {
        // If not found, get from WordPress options (for admin bar)
        webhookUrl = vercelDeployNonces.webhook_url || "";
      }

      if (!webhookUrl) {
        this.showNotification(
          "Webhook URL not configured. Please set up your webhook URL in the settings.",
          "error"
        );
        return false;
      }

      // Enhanced URL validation
      if (!webhookUrl.startsWith("https://")) {
        this.showNotification("Webhook URL must use HTTPS.", "error");
        return false;
      }

      // Validate Vercel domain
      try {
        var url = new URL(webhookUrl);
        var hostname = url.hostname.toLowerCase();
        var validDomains = ["api.vercel.com", "vercel.com", "vercel.app"];
        var isValidDomain = false;

        for (var i = 0; i < validDomains.length; i++) {
          if (
            hostname === validDomains[i] ||
            hostname.endsWith("." + validDomains[i])
          ) {
            isValidDomain = true;
            break;
          }
        }

        if (!isValidDomain) {
          this.showNotification(
            "Webhook URL must be from a valid Vercel domain.",
            "error"
          );
          return false;
        }
      } catch (e) {
        this.showNotification("Invalid webhook URL format.", "error");
        return false;
      }

      return true;
    },

    getStatusLabel: function (state) {
      var labels = {
        READY: "Ready",
        BUILDING: "Building",
        ERROR: "Failed",
        CANCELED: "Canceled",
        INITIALIZING: "Initializing",
        QUEUED: "Queued",
      };
      return labels[state] || state;
    },

    calculateDuration: function (startDate, endDate) {
      var diff = endDate.getTime() - startDate.getTime();
      var minutes = Math.floor(diff / 60000);
      var seconds = Math.floor((diff % 60000) / 1000);

      if (minutes > 0) {
        return minutes + "m " + seconds + "s";
      } else {
        return seconds + "s";
      }
    },

    findAdminBar: function () {
      // Try different selectors to find the admin bar
      var selectors = [
        "#wp-admin-bar",
        ".wp-admin-bar",
        "#admin-bar",
        ".admin-bar",
        "#wpadminbar",
        ".wpadminbar",
      ];

      for (var i = 0; i < selectors.length; i++) {
        var $element = $(selectors[i]);
        if ($element.length > 0) {
          this.debug("Found admin bar with selector: " + selectors[i]);
          return $element;
        }
      }

      this.debug("No admin bar found with any selector");
      return $();
    },

    findVercelIndicator: function () {
      // Try different selectors to find the Vercel status indicator
      var selectors = [
        "#wp-admin-bar-vercel-status-indicator",
        "#wp-admin-bar-vercel-status-indicator .ab-item",
        ".vercel-status-indicator",
        "#vercel-status-indicator",
      ];

      for (var i = 0; i < selectors.length; i++) {
        var $element = $(selectors[i]);
        if ($element.length > 0) {
          this.debug("Found Vercel indicator with selector: " + selectors[i]);
          return $element;
        }
      }

      this.debug("No Vercel indicator found with any selector");
      return $();
    },

    loadVercelStatus: function () {
      this.debug("loadVercelStatus called");

      // Wait a bit to ensure admin bar is fully loaded
      setTimeout(function () {
        VercelDeploy.loadVercelStatusDelayed();
      }, 100);
    },

    loadVercelStatusDelayed: function () {
      this.debug("loadVercelStatusDelayed called");

      var $widget = $("#vercel-status-widget");
      var $refreshBtn = $("#refresh_status");
      var $indicator = this.findVercelIndicator();
      var $dot = $("#vercel-status-dot");
      var $adminBar = this.findAdminBar();

      this.debug("Elements found", {
        widget: $widget.length,
        refreshBtn: $refreshBtn.length,
        indicator: $indicator.length,
        dot: $dot.length,
        admin_bar: $adminBar.length,
        indicator_by_class: $(".vercel-status-indicator").length,
        indicator_by_id_direct: $("#wp-admin-bar-vercel-status-indicator")
          .length,
        all_admin_bar_items: $("#wp-admin-bar .ab-item").length,
        admin_bar_html: $("#wp-admin-bar").html(),
        all_admin_bar_ids: $("#wp-admin-bar [id]")
          .map(function () {
            return this.id;
          })
          .get(),
        is_admin_page: window.location.href.includes("/wp-admin/"),
        is_frontend: !window.location.href.includes("/wp-admin/"),
        // Debug admin bar structure
        admin_bar_exists: $("#wp-admin-bar").length,
        admin_bar_alternative: $(".wp-admin-bar").length,
        admin_bar_body: $("body").hasClass("wp-admin"),
        all_admin_bar_elements: $("[id*='admin-bar']").length,
        admin_bar_children: $("#wp-admin-bar").children().length,
        admin_bar_find_vercel: $("#wp-admin-bar").find("[id*='vercel']").length,
      });

      // Show loading state
      if ($widget.length > 0) {
        $widget.html(
          '<div class="vercel-status-loading"><span class="spinner is-active"></span>Checking status...</div>'
        );
      }
      if ($refreshBtn.length > 0) {
        $refreshBtn.prop("disabled", true);
      }

      // Update indicator loading state (only if we're on frontend with admin bar)
      if ($indicator.length > 0) {
        $dot.css("background", "#646970");
        $indicator.attr("title", "Checking Vercel status...");
        this.debug("Indicator updated to loading state");
      } else {
        this.debug(
          "No admin bar indicator found - this is normal on admin pages"
        );

        // On admin pages, we don't have admin bar, so just update the dot if it exists
        if ($dot.length > 0) {
          $dot.css("background", "#646970");
          $dot.removeAttr("title"); // Remove any title attribute
          this.debug("Updated dot to loading state (admin page)");
        }
      }

      // Check Vercel status via our server-side proxy to avoid CORS issues
      this.debug("Attempting to fetch Vercel status via server proxy", {
        url: ajaxurl,
        action: "vercel_services_status",
        ajaxurl_defined: typeof ajaxurl !== "undefined",
        vercelDeployNonces_defined: typeof vercelDeployNonces !== "undefined",
        nonce: vercelDeployNonces ? vercelDeployNonces.status : "undefined",
      });

      $.ajax({
        type: "POST",
        url: ajaxurl,
        data: {
          action: "vercel_services_status",
          nonce: vercelDeployNonces.status,
        },
        timeout: 10000,
        dataType: "json",
      })
        .done(
          function (response) {
            this.debug("Vercel services status response received", response);

            if (response.success && response.data) {
              var status = response.data;
              this.debug("Using server-parsed status", status);

              // Store status data
              if ($indicator.length > 0) {
                $indicator.data("vercel-status", status);
              }

              // Update display
              VercelDeploy.displayVercelStatus(status);
              VercelDeploy.updateVercelStatusIndicator(status);

              if ($refreshBtn.length > 0) {
                $refreshBtn.prop("disabled", false);
              }
            } else {
              this.debug("Invalid response format", response);
              // Fallback to default status
              var fallbackStatus = {
                api: "operational",
                cdn: "operational",
                deployments: "operational",
                functions: "operational",
                lastUpdated: new Date().toISOString(),
              };

              if ($indicator.length > 0) {
                $indicator.data("vercel-status", fallbackStatus);
              }

              VercelDeploy.displayVercelStatus(fallbackStatus);
              VercelDeploy.updateVercelStatusIndicator(fallbackStatus);

              if ($refreshBtn.length > 0) {
                $refreshBtn.prop("disabled", false);
              }
            }
          }.bind(this)
        )
        .fail(
          function (xhr, status, error) {
            this.debug("Vercel status API request failed", {
              xhr: xhr,
              status: status,
              error: error,
              readyState: xhr.readyState,
              responseText: xhr.responseText,
            });

            // Fallback to operational status on API failure
            var fallbackStatus = {
              api: "operational",
              cdn: "operational",
              deployments: "operational",
              functions: "operational",
              lastUpdated: new Date().toISOString(),
            };

            if ($indicator.length > 0) {
              $indicator.data("vercel-status", fallbackStatus);
              $dot.css("background", "#dba617");
              $indicator.attr(
                "title",
                "Unable to verify Vercel status - Server error"
              );
            }

            VercelDeploy.displayVercelStatus(fallbackStatus);

            if ($refreshBtn.length > 0) {
              $refreshBtn.prop("disabled", false);
            }
          }.bind(this)
        );
    },

    displayVercelStatus: function (status) {
      var $widget = $("#vercel-status-widget");
      var $lastUpdated = $(".vercel-last-updated");

      var statusHtml =
        '<div class="vercel-status-item">' +
        '<span><span class="status-indicator ' +
        status.api +
        '"></span>API</span>' +
        "<span>" +
        this.getStatusLabel(status.api) +
        "</span>" +
        "</div>" +
        '<div class="vercel-status-item">' +
        '<span><span class="status-indicator ' +
        status.cdn +
        '"></span>CDN</span>' +
        "<span>" +
        this.getStatusLabel(status.cdn) +
        "</span>" +
        "</div>" +
        '<div class="vercel-status-item">' +
        '<span><span class="status-indicator ' +
        status.deployments +
        '"></span>Deployments</span>' +
        "<span>" +
        this.getStatusLabel(status.deployments) +
        "</span>" +
        "</div>" +
        '<div class="vercel-status-item">' +
        '<span><span class="status-indicator ' +
        status.functions +
        '"></span>Functions</span>' +
        "<span>" +
        this.getStatusLabel(status.functions) +
        "</span>" +
        "</div>";

      $widget.html(statusHtml);
      // Handle lastUpdated - could be Date object or string
      var lastUpdatedText = "Last updated: ";
      if (status.lastUpdated instanceof Date) {
        lastUpdatedText += status.lastUpdated.toLocaleTimeString();
      } else if (typeof status.lastUpdated === "string") {
        // Convert MySQL datetime string to readable format
        var date = new Date(status.lastUpdated);
        if (!isNaN(date.getTime())) {
          lastUpdatedText += date.toLocaleTimeString();
        } else {
          lastUpdatedText += status.lastUpdated;
        }
      } else {
        lastUpdatedText += "Unknown";
      }

      $lastUpdated.text(lastUpdatedText);
    },

    getStatusLabel: function (status) {
      var labels = {
        operational: "Operational",
        degraded: "Degraded",
        outage: "Outage",
        unknown: "Unknown",
      };
      return labels[status] || status;
    },

    updateDotColorDirect: function ($dot, status) {
      if (!$dot || $dot.length === 0) return;

      // Provide default status if none provided
      if (!status) {
        status = {
          api: "operational",
          cdn: "operational",
          deployments: "operational",
          functions: "operational",
        };
      }

      // Determine overall status color based on services
      var allOperational =
        status.api === "operational" &&
        status.cdn === "operational" &&
        status.deployments === "operational" &&
        status.functions === "operational";

      var statusColor = "#00a32a"; // Green by default
      var statusText = "Vercel is operational";

      if (!allOperational) {
        // Check which services have issues
        var issues = [];
        if (status.api !== "operational") issues.push("API: " + status.api);
        if (status.cdn !== "operational") issues.push("CDN: " + status.cdn);
        if (status.deployments !== "operational")
          issues.push("Deployments: " + status.deployments);
        if (status.functions !== "operational")
          issues.push("Functions: " + status.functions);

        if (issues.length > 0) {
          statusColor = "#d63638"; // Red
          statusText = "Vercel issues detected: " + issues.join(", ");
        }
      }

      $dot.css("background", statusColor);
      $dot.data("vercel-status", status); // Store status data on dot
      $dot.removeAttr("title"); // Remove any title attribute that might show old tooltip
      this.debug(
        "Dot color updated to: " + statusColor + " (" + statusText + ")"
      );
    },

    updateVercelStatusIndicator: function (status) {
      var $indicator = this.findVercelIndicator();
      var $dot = $("#vercel-status-dot");

      // Check if we're on admin page (no admin bar)
      var isAdminPage = window.location.href.includes("/wp-admin/");

      if (isAdminPage) {
        this.debug("On admin page - no admin bar indicator to update");
        // On admin pages, we might still have a dot somewhere, so update it
        if ($dot.length > 0) {
          this.updateDotColorDirect($dot, status);
          this.debug("Updated dot color on admin page");
        }
        return;
      }

      if ($indicator.length === 0) {
        this.debug("No admin bar indicator found on frontend page");
        return;
      }

      // If status is provided, use it; otherwise check current data
      if (!status) {
        status = $indicator.data("vercel-status") || {
          api: "operational",
          cdn: "operational",
          deployments: "operational",
          functions: "operational",
          lastUpdated: new Date().toISOString(),
        };
      }

      // Determine overall status color based on services
      var allOperational =
        status.api === "operational" &&
        status.cdn === "operational" &&
        status.deployments === "operational" &&
        status.functions === "operational";

      var statusColor = "#00a32a"; // Green by default
      var statusText = "Vercel is operational";

      if (!allOperational) {
        // Check which services have issues
        var issues = [];
        if (status.api !== "operational") issues.push("API: " + status.api);
        if (status.cdn !== "operational") issues.push("CDN: " + status.cdn);
        if (status.deployments !== "operational")
          issues.push("Deployments: " + status.deployments);
        if (status.functions !== "operational")
          issues.push("Functions: " + status.functions);

        if (issues.length > 0) {
          statusColor = "#d63638"; // Red
          statusText = "Vercel issues detected: " + issues.join(", ");
        }
      }

      $dot.css("background", statusColor);
      $indicator.attr("title", statusText);

      // Store status data for hover
      $indicator.data("vercel-status", status);
    },

    checkVercelAPIStatus: function () {
      var $indicator = $("#wp-admin-bar-vercel-status-indicator");
      var $dot = $("#vercel-status-dot");

      if ($indicator.length === 0) {
        return;
      }

      // Set initial loading state
      $dot.css("background", "#646970");
      $indicator.attr("title", "Checking Vercel status...");

      // API credentials are now handled server-side for security
      // No need to check client-side credentials

      // Make API call to check deployment status
      $.ajax({
        type: "POST",
        url: ajaxurl,
        data: {
          action: "vercel_status",
          nonce: vercelDeployNonces.status,
        },
      })
        .done(function (response) {
          if (response.success) {
            var deployment = response.data;
            var statusColor = "#00a32a"; // Green by default
            var statusText = "Vercel is operational";

            // Determine status based on deployment state
            switch (deployment.state) {
              case "READY":
                statusColor = "#00a32a";
                statusText =
                  "Vercel is operational - Last deployment successful";
                break;
              case "BUILDING":
                statusColor = "#dba617";
                statusText = "Vercel is operational - Deployment in progress";
                break;
              case "ERROR":
                statusColor = "#d63638";
                statusText = "Vercel deployment failed - Check dashboard";
                break;
              case "CANCELED":
                statusColor = "#646970";
                statusText = "Vercel deployment canceled";
                break;
              default:
                statusColor = "#646970";
                statusText = "Vercel status unknown";
            }

            $dot.css("background", statusColor);
            $indicator.attr("title", statusText);

            // Store deployment data for hover tooltip
            $indicator.data("deployment", deployment);
          } else {
            // API call failed, assume operational but show warning
            $dot.css("background", "#dba617");
            $indicator.attr(
              "title",
              "Unable to verify Vercel status - " + response.data
            );
          }
        })
        .fail(function () {
          // Network error, show error state
          $dot.css("background", "#d63638");
          $indicator.attr("title", "Failed to check Vercel status");
        });
    },

    showDeploymentHistory: function () {
      // Load recent deployments and show in a modal-like tooltip
      $.ajax({
        type: "POST",
        url: ajaxurl,
        data: {
          action: "vercel_deployments",
          nonce: vercelDeployNonces.deployments,
        },
      })
        .done(function (response) {
          if (response.success) {
            var deployments = response.data.slice(0, 5); // Show last 5 deployments
            var historyHtml = '<div class="vercel-history-tooltip">';
            historyHtml += "<h4>Recent Deployments</h4>";

            deployments.forEach(function (deployment) {
              var createdAt = new Date(deployment.createdAt);
              var timeAgo = VercelDeploy.getTimeAgo(createdAt);
              var statusClass = deployment.state.toLowerCase();

              historyHtml += '<div class="vercel-history-item">';
              historyHtml +=
                '<span class="vercel-history-status ' +
                statusClass +
                '">' +
                deployment.state +
                "</span>";
              historyHtml +=
                '<span class="vercel-history-time">' + timeAgo + "</span>";
              if (deployment.meta && deployment.meta.githubCommitMessage) {
                var commitMsg = deployment.meta.githubCommitMessage.substring(
                  0,
                  50
                );
                if (deployment.meta.githubCommitMessage.length > 50)
                  commitMsg += "...";
                historyHtml +=
                  '<span class="vercel-history-commit">' +
                  commitMsg +
                  "</span>";
              }
              historyHtml += "</div>";
            });

            historyHtml += "</div>";

            // Show tooltip
            VercelDeploy.showTooltip(historyHtml);
          }
        })
        .fail(function () {
          VercelDeploy.showTooltip(
            '<div class="vercel-history-tooltip"><p>Unable to load deployment history</p></div>'
          );
        });
    },

    getTimeAgo: function (date) {
      var now = new Date();
      var diff = now.getTime() - date.getTime();
      var minutes = Math.floor(diff / 60000);
      var hours = Math.floor(diff / 3600000);
      var days = Math.floor(diff / 86400000);

      if (days > 0) return days + " day" + (days > 1 ? "s" : "") + " ago";
      if (hours > 0) return hours + " hour" + (hours > 1 ? "s" : "") + " ago";
      if (minutes > 0)
        return minutes + " minute" + (minutes > 1 ? "s" : "") + " ago";
      return "Just now";
    },

    showTooltip: function (content) {
      // Remove existing tooltip
      $(".vercel-tooltip").remove();

      // Create tooltip
      var $tooltip = $('<div class="vercel-tooltip">' + content + "</div>");
      $("body").append($tooltip);

      // Position tooltip near the indicator
      var $indicator = $("#vercel-status-indicator");
      var indicatorPos = $indicator.offset();
      var indicatorWidth = $indicator.outerWidth();

      $tooltip.css({
        position: "absolute",
        top: indicatorPos.top + 30,
        left: indicatorPos.left - 100,
        zIndex: 999999,
        background: "#fff",
        border: "1px solid #ccc",
        borderRadius: "4px",
        padding: "10px",
        boxShadow: "0 2px 10px rgba(0,0,0,0.1)",
        maxWidth: "300px",
        fontSize: "12px",
      });

      // Auto-hide after 5 seconds
      setTimeout(function () {
        $tooltip.fadeOut(function () {
          $tooltip.remove();
        });
      }, 5000);

      // Hide on click outside
      $(document).on("click.vercel-tooltip", function (e) {
        if (
          !$(e.target).closest(".vercel-tooltip, #vercel-status-indicator")
            .length
        ) {
          $tooltip.fadeOut(function () {
            $tooltip.remove();
          });
          $(document).off("click.vercel-tooltip");
        }
      });
    },

    refreshStatus: function () {
      this.checkVercelAPIStatus();
    },

    refreshVercelStatus: function () {
      this.loadVercelStatus();
    },

    updateAdminBarVercelStatus: function (status) {
      var $indicator = $("#wp-admin-bar-vercel-status-indicator");
      var $dot = $("#vercel-status-dot");

      if ($indicator.length === 0) {
        return;
      }

      // Check if all services are operational
      var allOperational =
        status.api === "operational" &&
        status.cdn === "operational" &&
        status.deployments === "operational" &&
        status.functions === "operational";

      var statusColor = "#646970"; // Default gray
      var statusText = "Checking Vercel status...";

      if (allOperational) {
        statusColor = "#00a32a"; // Green
        statusText = "Vercel is online - All services operational";
      } else {
        // Check which services have issues
        var issues = [];
        if (status.api !== "operational") issues.push("API: " + status.api);
        if (status.cdn !== "operational") issues.push("CDN: " + status.cdn);
        if (status.deployments !== "operational")
          issues.push("Deployments: " + status.deployments);
        if (status.functions !== "operational")
          issues.push("Functions: " + status.functions);

        statusColor = "#d63638"; // Red
        statusText = "Vercel issues detected: " + issues.join(", ");
      }

      // Update the dot color
      $dot.css("background", statusColor);

      // Update the tooltip
      $indicator.attr("title", statusText);
    },

    cleanButtonState: function () {
      // Force reset button state on page load
      var $button = $("#build_button");
      if ($button.length > 0) {
        $button.prop("disabled", false);
        if ($button.data("original-text")) {
          $button.text($button.data("original-text"));
        } else {
          // Store current text as original if not already stored
          $button.data("original-text", $button.text());
        }
      }
    },

    setButtonLoadingState: function ($button, isLoading) {
      this.debug("setButtonLoadingState called", {
        isLoading: isLoading,
        buttonText: $button.text(),
        buttonId: $button.attr("id"),
      });

      if (isLoading) {
        $button.prop("disabled", true);
        $button.css({
          "pointer-events": "none",
          opacity: "0.6",
        });
        $button.data("original-text", $button.text());
        $button.text("Déploiement en cours...");
        this.debug("Button set to loading state", {
          text: $button.text(),
          disabled: $button.prop("disabled"),
        });
      } else {
        $button.prop("disabled", false);
        $button.css({
          "pointer-events": "auto",
          opacity: "1",
        });
        // Always restore to "Deploy Site" text when not loading
        $button.text(vercelDeployNonces.deploy_site_text || "Deploy Site");
        this.debug("Button set to ready state", {
          text: $button.text(),
          disabled: $button.prop("disabled"),
        });
      }
    },

    updateProgressIndicator: function (percentage, message) {
      var $progressFill = $(".vercel-progress-fill");
      var $progressText = $(".vercel-progress-text");

      if ($progressFill.length > 0) {
        $progressFill.css("width", percentage + "%");
      }

      if ($progressText.length > 0 && message) {
        $progressText.text(message);
      }
    },

    showProgressModal: function (title, message) {
      // Remove existing modal
      $(".vercel-progress-modal").remove();

      var modal = $(
        '<div class="vercel-progress-modal">' +
          '<div class="vercel-progress-modal-content">' +
          '<div class="vercel-progress-modal-header">' +
          "<h3>" +
          title +
          "</h3>" +
          '<button class="vercel-progress-modal-close">' +
          '<span class="dashicons dashicons-no-alt"></span>' +
          "</button>" +
          "</div>" +
          '<div class="vercel-progress-modal-body">' +
          '<div class="vercel-progress-indicator-large">' +
          '<div class="vercel-progress-bar-large">' +
          '<div class="vercel-progress-fill-large"></div>' +
          "</div>" +
          '<div class="vercel-progress-text-large">' +
          message +
          "</div>" +
          '<div class="vercel-progress-percentage">0%</div>' +
          "</div>" +
          "</div>" +
          "</div>" +
          "</div>"
      );

      $("body").append(modal);

      // Close button functionality
      modal.find(".vercel-progress-modal-close").on("click", function () {
        modal.fadeOut(300, function () {
          modal.remove();
        });
      });

      // Animate in
      modal.fadeIn(300);
    },

    updateProgressModal: function (percentage, message, status) {
      var $modal = $(".vercel-progress-modal");
      if ($modal.length === 0) return;

      var $progressFill = $modal.find(".vercel-progress-fill-large");
      var $progressText = $modal.find(".vercel-progress-text-large");
      var $progressPercentage = $modal.find(".vercel-progress-percentage");

      if ($progressFill.length > 0) {
        $progressFill.css("width", percentage + "%");
      }

      if ($progressText.length > 0 && message) {
        $progressText.text(message);
      }

      if ($progressPercentage.length > 0) {
        $progressPercentage.text(Math.round(percentage) + "%");
      }

      // Update status color
      if (status) {
        $modal.removeClass(
          "vercel-progress-success vercel-progress-error vercel-progress-warning"
        );
        $modal.addClass("vercel-progress-" + status);
      }
    },

    hideProgressModal: function () {
      var $modal = $(".vercel-progress-modal");
      if ($modal.length > 0) {
        $modal.fadeOut(300, function () {
          $modal.remove();
        });
      }
    },

    updateAdminBarStatus: function () {
      // Update admin bar status on all pages
      this.getDeployStatus();
    },

    checkDeploymentState: function () {
      // Check if there was a deployment in progress before page reload
      var deploymentInProgress = localStorage.getItem(
        "vercel_deployment_in_progress"
      );
      var deploymentStartTime = localStorage.getItem(
        "vercel_deployment_start_time"
      );

      if (deploymentInProgress === "true" && deploymentStartTime) {
        var startTime = new Date(deploymentStartTime);
        var now = new Date();
        var timeDiff = now.getTime() - startTime.getTime();

        // If deployment started less than 10 minutes ago, assume it's still in progress
        if (timeDiff < 600000) {
          // 10 minutes
          var $button = $("#build_button");
          if ($button.length > 0) {
            this.setButtonLoadingState($button, true);
            $("#build_status")
              .html("Checking deployment status...")
              .css("margin-top", "10px");

            // Start polling to check current status
            this.startStatusPolling();
          }
        } else {
          // Clear old deployment state
          localStorage.removeItem("vercel_deployment_in_progress");
          localStorage.removeItem("vercel_deployment_start_time");
        }
      }
    },

    initSensitiveFields: function () {
      var self = this;
      
      // Handle edit button - replace value completely
      $(document).on("click", ".vercel-edit-field", function (e) {
        e.preventDefault();
        var $button = $(this);
        var fieldId = $button.data("field-id");
        var $input = $("#" + fieldId);
        var $description = $input.closest(".vercel-sensitive-field-wrapper").next(".description");
        
        // Check if already in edit mode
        if ($input.hasClass("vercel-editing")) {
          // Cancel edit mode - restore masked value
          var originalValue = $input.data("original-value");
          var maskedValue = "•".repeat(Math.min(originalValue.length, 20));
          $input.val(maskedValue);
          $input.attr("type", "password");
          $input.prop("readonly", true);
          $input.removeClass("vercel-editing");
          $input.css({
            "background-color": "#f6f7f7",
            "cursor": "not-allowed"
          });
          
          // Remove editing mode class from wrapper
          $input.closest(".vercel-sensitive-field-wrapper").removeClass("editing-mode");
          
          // Restore description
          if ($description.length) {
            $description.text("Valeur masquée pour sécurité. Cliquez sur \"Éditer\" pour la remplacer.");
          }
          
          // Change button text back to edit
          var editText = $button.data("text-replace") || "Éditer";
          $button.text(editText);
          return;
        }
        
        // Clear the input completely and enable editing (no confirmation)
        $input.val("");
        $input.attr("type", "text");
        $input.prop("readonly", false);
        $input.removeAttr("readonly");
        $input.addClass("vercel-editing");
        $input.css({
          "background-color": "#ffffff",
          "cursor": "text",
          "border-color": "#2271b1"
        });
        $input.focus();
        
        // Add editing mode class to wrapper
        $input.closest(".vercel-sensitive-field-wrapper").addClass("editing-mode");
        
        // Update description
        if ($description.length) {
          $description.text("Saisissez la nouvelle valeur, puis sauvegardez le formulaire.");
        }
        
        // Change button text to cancel
        var cancelText = $button.data("text-cancel") || "Annuler";
        $button.text(cancelText);
      });

      // Handle form submission - restore original values if not edited and disable fields after save
      $("form").on("submit", function (e) {
        // Restore original values for masked fields that weren't edited (readonly fields)
        $(".vercel-sensitive-input").each(function () {
          var $input = $(this);
          var originalValue = $input.data("original-value");
          var currentValue = $input.val();
          
          // If field is readonly and masked (contains only bullets), restore original
          if ($input.prop("readonly") && originalValue && 
              currentValue === "•".repeat(Math.min(originalValue.length, 20))) {
            $input.val(originalValue);
          }
        });
        
        // After form submission, fields will be disabled again by page reload
        // But we can prepare them to be disabled immediately after successful save
        $(".vercel-sensitive-input.vercel-editing").each(function () {
          var $input = $(this);
          var $wrapper = $input.closest(".vercel-sensitive-field-wrapper");
          var $button = $wrapper.find(".vercel-edit-field");
          var $description = $wrapper.next(".description");
          
          // Mark as will be disabled after save
          $input.data("will-disable", true);
        });
      });
      
      // After successful form save, disable edited fields
      if (window.location.search.indexOf("settings-updated=true") !== -1) {
        setTimeout(function() {
          $(".vercel-sensitive-input").each(function () {
            var $input = $(this);
            var $wrapper = $input.closest(".vercel-sensitive-field-wrapper");
            var $button = $wrapper.find(".vercel-edit-field");
            var $description = $wrapper.next(".description");
            var originalValue = $input.data("original-value");
            
            if (originalValue) {
              // Restore masked display
              var maskedValue = "•".repeat(Math.min(originalValue.length, 20));
              $input.val(maskedValue);
              $input.attr("type", "password");
              $input.prop("readonly", true);
              $input.removeClass("vercel-editing");
              $input.css({
                "background-color": "#f6f7f7",
                "cursor": "not-allowed",
                "border-color": "#c3c4c7"
              });
              
              $wrapper.removeClass("editing-mode");
              
              if ($description.length) {
                $description.text("Valeur masquée pour sécurité. Cliquez sur \"Éditer\" pour la remplacer.");
              }
              
              var editText = $button.data("text-replace") || "Éditer";
              $button.text(editText);
            }
          });
        }, 100);
      }

      // Prevent any interaction with readonly masked fields
      $(document).on("focus click", ".vercel-sensitive-input[readonly]", function (e) {
        e.preventDefault();
        $(this).blur();
        return false;
      });
    },
  };

  // Initialize when document is ready
  $(document).ready(function () {
    VercelDeploy.init();
    VercelDeploy.checkDeploymentState();
  });
})(jQuery);
