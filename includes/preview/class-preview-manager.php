<?php
/**
 * Vercel WP - Preview Module - Manager Class
 * 
 * from plugin-headless-preview
 * 
 * @package VercelWP
 * @since 2.0.0
 */

defined('ABSPATH') or die('Access denied');

// from plugin-headless-preview
/**
 * Preview Manager Class
 */
class VercelWP_Preview_Manager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        // Note: admin_menu hook removed - menu handled by admin/settings.php
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('wp_ajax_vercel_wp_preview_get_url', array($this, 'ajax_get_preview_url'));
        add_action('wp_ajax_vercel_wp_preview_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_vercel_wp_preview_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_vercel_wp_preview_test_connection_debug', array($this, 'ajax_test_connection_debug'));
        add_action('wp_ajax_vercel_wp_preview_check_status', array($this, 'ajax_check_status'));
        add_action('wp_ajax_vercel_wp_preview_auto_save', array($this, 'ajax_auto_save'));
        add_action('wp_ajax_vercel_wp_preview_replace_urls', array($this, 'ajax_replace_urls'));
        add_action('wp_ajax_vercel_wp_preview_preview_urls', array($this, 'ajax_preview_urls'));
        add_action('wp_ajax_vercel_wp_preview_apply_production_url_update', array($this, 'ajax_apply_production_url_update'));
        add_action('wp_ajax_vercel_wp_preview_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_vercel_wp_preview_get_acf_debug', array($this, 'ajax_get_acf_debug'));
        add_action('wp_ajax_vercel_wp_preview_clear_acf_debug', array($this, 'ajax_clear_acf_debug'));
        add_action('wp_ajax_vercel_wp_preview_inspect_acf_field', array($this, 'ajax_inspect_acf_field'));
        add_action('wp_ajax_vercel_wp_preview_test_permalinks', array($this, 'ajax_test_permalinks'));
        add_action('wp_ajax_vercel_wp_preview_clear_permalink_cache', array($this, 'ajax_clear_permalink_cache'));
        
        
        // Hook to add preview button in editor
        add_action('admin_bar_menu', array($this, 'add_preview_button'), 100);
        add_action('post_submitbox_misc_actions', array($this, 'add_preview_buttons_in_publish_box'));
        
        // Fallback solution removed - keeping only meta-box
        
        // Hide default WordPress preview button
        add_action('admin_head', array($this, 'hide_default_preview_button'));
        
        // Add permalink filters IMMEDIATELY in constructor to ensure they're always active
        $this->init_permalink_filters();
        
        // Headless functionality hooks
        add_action('init', array($this, 'init_headless_functionality'));
        
        // Admin bar URL fix - add hooks directly in constructor
        add_action('admin_footer', array($this, 'fix_admin_bar_urls_js'), 999);
        add_action('wp_footer', array($this, 'fix_admin_bar_urls_js'), 999);
        
        // Redirect to Production URL or Preview URL
        add_action('template_redirect', array($this, 'redirect_to_frontend_url'));
        
        // Note: activation/deactivation hooks are now in vercel-wp.php
    }
    
    public function init() {
        load_plugin_textdomain('vercel-wp', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Update existing settings to include new options
        $this->update_existing_settings();
    }
    
    /**
     * Redirect to Production URL or Preview URL if configured
     */
    public function redirect_to_frontend_url() {
        // Only redirect on frontend, not in admin
        if (is_admin()) {
            return;
        }
        
        // Don't redirect AJAX requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        
        // Don't redirect REST API requests
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        
        // Don't redirect if doing cron
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }
        
        // Get settings
        $settings = get_option('vercel_wp_preview_settings', array(
            'vercel_preview_url' => '',
            'production_url' => '',
        ));
        
        $production_url = !empty($settings['production_url']) ? rtrim($settings['production_url'], '/') : '';
        $preview_url = !empty($settings['vercel_preview_url']) ? rtrim($settings['vercel_preview_url'], '/') : '';
        
        // Determine redirect URL
        $redirect_url = '';
        
        if (!empty($production_url)) {
            // Redirect to Production URL if it's set
            $redirect_url = $production_url;
        } elseif (!empty($preview_url)) {
            // Otherwise redirect to Preview URL if it's set
            $redirect_url = $preview_url;
        }
        
        // Only redirect if we have a URL and we're not already on that domain
        if (!empty($redirect_url)) {
            // Get current request URI safely
            $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
            
            // Get current host
            $current_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : parse_url(home_url(), PHP_URL_HOST);
            $redirect_host = parse_url($redirect_url, PHP_URL_HOST);
            
            // Only redirect if we're not already on the target domain
            if ($current_host !== $redirect_host) {
                // Preserve the path and query string
                $full_redirect_url = rtrim($redirect_url, '/') . $request_uri;
                
                // Perform redirect with 301 (permanent redirect)
                wp_redirect($full_redirect_url, 301);
                exit;
            }
        }
    }
    
    /**
     * Initialize permalink filters early
     */
    public function init_permalink_filters() {
        static $initialized = false;
        
        // Prevent duplicate filter registration
        if ($initialized) {
            return;
        }
        
        // Add rewrite permalink filter with high priority
        add_filter('post_link', array($this, 'rewrite_permalink'), 10, 3);
        add_filter('page_link', array($this, 'rewrite_permalink'), 10, 3);
        add_filter('post_type_link', array($this, 'rewrite_permalink'), 10, 3);
        
        // Also add filters for admin context
        add_filter('get_permalink', array($this, 'rewrite_permalink'), 10, 3);
        
        // Force permalink refresh in admin
        if (is_admin() && !has_action('admin_footer', array($this, 'force_permalink_refresh'))) {
            add_action('admin_footer', array($this, 'force_permalink_refresh'));
        }
        
        // Clear permalink cache when production URL changes
        if (!has_action('update_option_vercel_wp_preview_settings', array($this, 'clear_permalink_cache'))) {
            add_action('update_option_vercel_wp_preview_settings', array($this, 'clear_permalink_cache'));
        }
        
        $initialized = true;
    }
    
    public function activate() {
        // Create default options
        add_option('vercel_wp_preview_settings', array(
            'vercel_preview_url' => '',
            'production_url' => '',
            'cache_duration' => 300, // 5 minutes
            'auto_refresh' => true,
            'show_button_admin_bar' => true,
            'show_button_editor' => true,
            'disable_theme_page' => true
        ));
        
        // Define constants based on settings
        $this->define_url_constants();
    }
    
    public function deactivate() {
        // Clean options if necessary
    }
    
    // Note: admin menu is now handled by admin/settings.php
    // This method is no longer used but kept for reference
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'vercel-wp') !== false || 'post.php' === $hook || 'post-new.php' === $hook) {
            wp_enqueue_script('vercel-wp-preview-admin', VERCEL_WP_PLUGIN_URL . 'assets/js/preview-admin.js', array('jquery'), VERCEL_WP_VERSION, true);
            wp_enqueue_style('vercel-wp-preview-admin', VERCEL_WP_PLUGIN_URL . 'assets/css/preview-admin.css', array(), VERCEL_WP_VERSION);
            
            wp_localize_script('vercel-wp-preview-admin', 'headlessPreview', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('vercel_wp_preview_nonce'),
                'strings' => array(
                    'loading' => __('Loading...', 'vercel-wp'),
                    'error' => __('Error loading', 'vercel-wp'),
                    'clearCache' => __('Clear cache', 'vercel-wp'),
                    'openPreview' => __('Open preview', 'vercel-wp')
                )
            ));
        }
    }
    
    public function enqueue_frontend_scripts() {
        if (is_admin_bar_showing()) {
            wp_enqueue_script('vercel-wp-preview-frontend', VERCEL_WP_PLUGIN_URL . 'assets/js/preview-frontend.js', array('jquery'), VERCEL_WP_VERSION, true);
            wp_enqueue_style('vercel-wp-preview-frontend', VERCEL_WP_PLUGIN_URL . 'assets/css/preview-frontend.css', array(), VERCEL_WP_VERSION);
            
            wp_localize_script('vercel-wp-preview-frontend', 'headlessPreview', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('vercel_wp_preview_nonce'),
                'currentUrl' => get_permalink(),
                'postId' => get_the_ID()
            ));
        }
    }
    
    public function add_preview_button($wp_admin_bar) {
        $settings = get_option('vercel_wp_preview_settings');
        
        if (!isset($settings['show_button_admin_bar']) || !$settings['show_button_admin_bar'] || !current_user_can('edit_posts')) {
            return;
        }
        
        $current_url = $this->get_current_page_url();
        if (!$current_url) {
            return;
        }
        
        $preview_url = $this->get_preview_url($current_url);
        
        $wp_admin_bar->add_node(array(
            'id' => 'vercel-wp',
            'title' => '<span class="ab-icon"></span>' . __('Preview', 'vercel-wp'),
            'href' => $preview_url,
            'meta' => array(
                'target' => '_blank',
                'title' => __('Open preview', 'vercel-wp')
            )
        ));
    }
    
    
    public function add_preview_buttons_after_title() {
        global $post;
        
        if (!$post || !$this->is_editable_post_type($post->post_type)) {
            return;
        }
        
        // Additional check: ensure this specific post has a permalink
        $current_url = get_permalink($post->ID);
        if (!$current_url || $current_url === get_site_url() . '/') {
            return;
        }
        
        $settings = get_option('vercel_wp_preview_settings');
        
        if (!isset($settings['show_button_editor']) || !$settings['show_button_editor'] || !current_user_can('edit_posts')) {
            return;
        }
        
        $preview_url = $this->get_preview_url($current_url);
        
        echo '<div class="headless-preview-buttons-container" style="margin-top: 10px; margin-bottom: 15px; background: #fff; border: 1px solid #e1e1e1; border-radius: 4px; padding: 10px; width: 100%;">';
        echo '<h3 style="margin: 0 0 10px 0; color: #333; font-size: 13px; font-weight: 500;">' . __('Preview', 'vercel-wp') . '</h3>';
        echo '<div class="headless-preview-buttons" style="display: flex; gap: 8px; align-items: center;">';
        
        // Preview button (simple style)
        echo '<button type="button" class="button button-secondary headless-preview-toggle" data-url="' . esc_url($preview_url) . '" style="font-size: 13px; display: flex; align-items: center;">';
        echo __('Preview', 'vercel-wp');
        echo '</button>';
        
        // Clear cache button (simple style)
        echo '<button type="button" class="button button-secondary headless-preview-clear-cache" data-url="' . esc_url($current_url) . '" style="font-size: 13px; display: flex; align-items: center;">';
        echo '<span class="dashicons dashicons-update" style="font-size: 14px;"></span> ' . __('Clear cache', 'vercel-wp');
        echo '</button>';
        
        echo '</div>';
        echo '</div>';
        
        // Enhanced preview interface
        echo '<div class="headless-preview-container" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 999999; backdrop-filter: blur(5px);">';
        
        // Simple header
        echo '<div class="headless-preview-header" style="background: #fff; color: #333; padding: 4px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e1e1e1;">';
        echo '<div class="headless-preview-header-left" style="display: flex; align-items: center;">';
        echo '<h1 style="margin: 0; font-size: 18px; font-weight: 500; color: #333;">' . get_the_title($post->ID) . '</h1>';
        echo '</div>';
        
        echo '<div class="headless-preview-header-right" style="display: flex; align-items: center; gap: 10px;">';
        
        // Simple URL bar
        echo '<div class="headless-preview-url-bar" style="display: flex; align-items: center; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; padding: 6px 10px; margin-right: 10px;">';
        echo '<input type="text" value="' . esc_url($preview_url) . '" readonly style="background: transparent; border: none; color: #333; font-size: 13px; width: 250px; outline: none;">';
        echo '</div>';
        
        // Simple action buttons
        echo '<div class="headless-preview-controls" style="display: flex; gap: 5px;">';
        echo '<button type="button" class="button headless-preview-refresh-iframe" style="padding: 6px 10px; font-size: 12px;" title="' . __('Refresh', 'vercel-wp') . '">';
        echo '<span class="dashicons dashicons-update"></span>';
        echo '</button>';
        echo '<button type="button" class="button headless-preview-open-new-tab" style="padding: 6px 10px; font-size: 12px;" title="' . __('New tab', 'vercel-wp') . '">';
        echo '<span class="dashicons dashicons-external"></span>';
        echo '</button>';
        echo '<button type="button" class="button headless-preview-close" style="padding: 6px 10px; font-size: 12px; background: #dc3545; border-color: #dc3545; color: white;" title="' . __('Close', 'vercel-wp') . '">';
        echo '<span class="dashicons dashicons-no-alt"></span>';
        echo '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        // Enhanced preview area
        echo '<div class="headless-preview-content" style="height: calc(100% - 80px); position: relative;">';
        
        // Simple toolbar
        echo '<div class="headless-preview-toolbar" style="background: #f8f9fa; border-bottom: 1px solid #e1e1e1; padding: 8px 15px; display: flex; justify-content: space-between; align-items: center;">';
        echo '<div class="headless-preview-toolbar-left" style="display: flex; align-items: center; gap: 10px;">';
        echo '<div class="headless-preview-device-selector" style="display: flex; gap: 3px;">';
        echo '<button type="button" class="device-btn active" data-device="desktop" style="padding: 4px 8px; border: 1px solid #0073aa; background: #0073aa; color: white; border-radius: 3px; cursor: pointer; font-size: 11px;">' . __('Desktop', 'vercel-wp') . '</button>';
        echo '<button type="button" class="device-btn" data-device="tablet" style="padding: 4px 8px; border: 1px solid #ddd; background: white; color: #666; border-radius: 3px; cursor: pointer; font-size: 11px;">' . __('Tablet', 'vercel-wp') . '</button>';
        echo '<button type="button" class="device-btn" data-device="mobile" style="padding: 4px 8px; border: 1px solid #ddd; background: white; color: #666; border-radius: 3px; cursor: pointer; font-size: 11px;">' . __('Mobile', 'vercel-wp') . '</button>';
        echo '</div>';
        echo '<div class="headless-preview-status-container" style="display: flex; align-items: center; gap: 8px;">';
        echo '<div class="headless-preview-status" style="width: 8px; height: 8px; border-radius: 50%; background: #28a745;"></div>';
        echo '<span style="font-size: 11px; color: #666;">' . __('Connected', 'vercel-wp') . '</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        // Iframe area with enhanced error handling
        echo '<div class="headless-preview-iframe-container" style="height: calc(100% - 60px); position: relative; background: white;">';
        
        // Simple loading message
        echo '<div class="headless-preview-loading" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; z-index: 10;">';
        echo '<div style="width: 40px; height: 40px; border: 3px solid #f3f3f3; border-top: 3px solid #0073aa; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 15px;"></div>';
        echo '<p style="color: #666; margin: 0; font-size: 14px;">' . __('Loading...', 'vercel-wp') . '</p>';
        echo '</div>';
        
        // Simple error message
        echo '<div class="headless-preview-fallback" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; background: white; padding: 30px; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-width: 350px;">';
        echo '<div style="width: 40px; height: 40px; background: #dc3545; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">';
        echo '<span class="dashicons dashicons-warning" style="color: white; font-size: 18px;"></span>';
        echo '</div>';
        echo '<h4 style="color: #dc3545; margin: 0 0 10px 0; font-size: 16px;">' . __('Preview blocked', 'vercel-wp') . '</h4>';
        echo '<p style="color: #666; margin: 0 0 15px 0; font-size: 13px; line-height: 1.4;">' . __('Your browser blocks the display. Click below to open in a new tab.', 'vercel-wp') . '</p>';
        echo '<button type="button" class="button button-primary headless-preview-open-new-tab-fallback" style="padding: 8px 16px; font-size: 13px;">';
        echo '<span class="dashicons dashicons-external" style="margin-right: 6px;"></span> ' . __('Open in new tab', 'vercel-wp');
        echo '</button>';
        echo '</div>';
        
        // Preview iframe
        echo '<iframe id="headless-preview-iframe" src="" width="100%" height="100%" frameborder="0" style="border: none; background: white;"></iframe>';
        echo '</div>';
        echo '</div>';
        
        // Load external CSS and JS files
        wp_enqueue_style('headless-preview-interface', VERCEL_WP_PLUGIN_URL . 'assets/css/preview-interface.css', array(), VERCEL_WP_VERSION);
        wp_enqueue_script('headless-preview-interface', VERCEL_WP_PLUGIN_URL . 'assets/js/preview-interface.js', array('jquery'), VERCEL_WP_VERSION, true);
        
        echo '</div>';
    }

    /**
     * Check if a post type should show preview functionality
     * Only shows for post types that have URLs/permaliens
     */
    private function is_editable_post_type($post_type) {
        // Always include posts and pages (WordPress defaults)
        if (in_array($post_type, array('post', 'page'))) {
            return true;
        }
        
        // Include all public post types (posts, pages, and custom post types)
        $post_type_object = get_post_type_object($post_type);
        
        if (!$post_type_object) {
            return false;
        }
        
        // Include if it's public, has a public UI, and has permalinks/URLs
        if (!$post_type_object->public || !$post_type_object->show_ui) {
            return false;
        }
        
        // Check if this post type has permalinks/URLs
        // Skip if it's not publicly queryable (no frontend URLs)
        if (!$post_type_object->publicly_queryable) {
            return false;
        }
        
        // For custom post types, ensure they have rewrite rules
        // Posts and pages are handled above, so this only applies to custom post types
        if (empty($post_type_object->rewrite)) {
            return false;
        }
        
        return true;
    }
    
    public function add_preview_buttons_in_publish_box() {
        global $post;
        
        if (!$post || !$this->is_editable_post_type($post->post_type)) {
            return;
        }
        
        // Additional check: ensure this specific post has a permalink
        $current_url = get_permalink($post->ID);
        if (!$current_url || $current_url === get_site_url() . '/') {
            return;
        }
        
        $settings = get_option('vercel_wp_preview_settings');
        if (!isset($settings['show_button_editor']) || !$settings['show_button_editor']) {
            return;
        }
        
        $preview_url = $this->get_preview_url($current_url);
        
        // Native WordPress style for Publish section with misc-pub-section classes
        echo '<div class="misc-pub-section headless-preview-section" style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">';
        echo '<label style="font-weight: 600; color: #23282d; margin-bottom: 8px; display: block;">' . __('Preview', 'vercel-wp') . '</label>';
        
        // Button container with WordPress style
        echo '<div class="headless-preview-buttons" style="display: flex; gap: 6px; margin-top: 8px;">';
        
        // Preview button with WordPress style
        echo '<button type="button" class="button button-secondary headless-preview-toggle" data-url="' . esc_url($preview_url) . '" style="font-size: 12px; height: 28px; line-height: 26px; padding: 0 8px; display: inline-flex; align-items: center; gap: 4px;">';
        echo __('Preview', 'vercel-wp');
        echo '</button>';
        
        // Clear cache button with WordPress style
        echo '<button type="button" class="button button-secondary headless-preview-clear-cache" data-url="' . esc_url($current_url) . '" style="font-size: 12px; height: 28px; line-height: 26px; padding: 0 8px; display: inline-flex; align-items: center; gap: 4px;">';
        echo '<span class="dashicons dashicons-update" style="font-size: 14px; width: 14px; height: 14px;"></span>';
        echo __('Clear cache', 'vercel-wp');
        echo '</button>';
        
        echo '</div>';
        echo '</div>';
        
        // Enhanced preview interface
        echo '<div class="headless-preview-container" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 999999; backdrop-filter: blur(5px);">';
        
        // Header with modern Sanity style
        echo '<div class="headless-preview-header" style="background: #fff; border-bottom: 1px solid #e1e1e1; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">';
        
        // Page title on the left
        echo '<div class="headless-preview-title" style="flex: 1; margin-right: 20px;">';
        echo '<h1 style="margin: 0; font-size: 18px; font-weight: 600; color: #1d2327; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">' . get_the_title($post->ID) . '</h1>';
        echo '</div>';
        
        // URL bar on the right
        echo '<div class="headless-preview-url-bar" style="display: flex; align-items: center; background: #f8f9fa; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 12px; margin-right: 12px; flex: 1; max-width: 400px;">';
        echo '<input type="text" value="' . esc_url($preview_url) . '" readonly style="background: transparent; border: none; color: #374151; font-size: 13px; width: 100%; outline: none; font-family: \'SF Mono\', Monaco, \'Cascadia Code\', \'Roboto Mono\', Consolas, \'Courier New\', monospace;">';
        echo '</div>';
        
        // Action buttons with Sanity style
        echo '<div class="headless-preview-controls" style="display: flex; gap: 6px;">';
        echo '<button type="button" class="button button-secondary headless-preview-refresh-iframe" style="padding: 8px; font-size: 14px; height: 36px; width: 36px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; color: #374151; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease;" title="' . __('Refresh', 'vercel-wp') . '">';
        echo '<span class="dashicons dashicons-update" style="font-size: 16px; font-weight: bold;"></span>';
        echo '</button>';
        echo '<button type="button" class="button button-secondary headless-preview-open-new-tab" style="padding: 8px; font-size: 14px; height: 36px; width: 36px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; color: #374151; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease;" title="' . __('New tab', 'vercel-wp') . '">';
        echo '<span class="dashicons dashicons-external" style="font-size: 16px; font-weight: bold;"></span>';
        echo '</button>';
        echo '<button type="button" class="button button-secondary headless-preview-close" style="padding: 8px; font-size: 14px; height: 36px; width: 36px; border-radius: 6px; border: 1px solid #dc2626; background: #dc2626; color: white; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease;" title="' . __('Close', 'vercel-wp') . '">';
        echo '<span class="dashicons dashicons-no-alt" style="font-size: 16px; font-weight: bold;"></span>';
        echo '</button>';
        echo '</div>';
        echo '</div>';
        
        // Preview area
        echo '<div class="headless-preview-content" style="height: calc(100% - 60px); position: relative;">';
        
        // Toolbar with Sanity style
        echo '<div class="headless-preview-toolbar" style="background: #f8f9fa; border-bottom: 1px solid #e1e1e1; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center;">';
        echo '<div class="headless-preview-toolbar-left" style="display: flex; align-items: center; gap: 16px;">';
        
        // Device selector with Sanity style
        echo '<div class="headless-preview-device-selector" style="display: flex; gap: 4px;">';
        echo '<button type="button" class="device-btn active" data-device="desktop" style="padding: 6px 12px; border: 1px solid #2271b1; background: #2271b1; color: white; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 500; transition: all 0.2s ease;">' . __('Desktop', 'vercel-wp') . '</button>';
        echo '<button type="button" class="device-btn" data-device="tablet" style="padding: 6px 12px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 500; transition: all 0.2s ease;">' . __('Tablet', 'vercel-wp') . '</button>';
        echo '<button type="button" class="device-btn" data-device="mobile" style="padding: 6px 12px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 500; transition: all 0.2s ease;">' . __('Mobile', 'vercel-wp') . '</button>';
        echo '</div>';
        
        // Connection status with Sanity style
        echo '<div class="headless-preview-status-container" style="display: flex; align-items: center; gap: 8px;">';
        echo '<div class="headless-preview-status" style="width: 8px; height: 8px; border-radius: 50%; background: #10b981;"></div>';
        echo '<span style="font-size: 13px; color: #374151; font-weight: 500;">' . __('Connected', 'vercel-wp') . '</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        // Iframe area
        echo '<div class="headless-preview-iframe-container" style="height: calc(100% - 50px); position: relative; background: white;">';
        
        // Loading message with WordPress style
        echo '<div class="headless-preview-loading" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; z-index: 10;">';
        echo '<div style="width: 32px; height: 32px; border: 3px solid #f0f0f1; border-top: 3px solid #2271b1; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 12px;"></div>';
        echo '<p style="color: #1d2327; margin: 0; font-size: 14px; font-weight: 500;">' . __('Loading...', 'vercel-wp') . '</p>';
        echo '</div>';
        
        // Error message with WordPress style
        echo '<div class="headless-preview-fallback" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; background: white; padding: 24px; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); max-width: 320px; border: 1px solid #c3c4c7;">';
        echo '<div style="width: 32px; height: 32px; background: #d63638; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px;">';
        echo '<span class="dashicons dashicons-warning" style="color: white; font-size: 16px;"></span>';
        echo '</div>';
        echo '<h3 style="margin: 0 0 8px 0; color: #1d2327; font-size: 16px; font-weight: 600;">' . __('Unable to load preview', 'vercel-wp') . '</h3>';
        echo '<p style="margin: 0 0 16px 0; color: #646970; font-size: 14px; line-height: 1.4;">' . __('Preview cannot be loaded. Check your configuration.', 'vercel-wp') . '</p>';
        echo '<div style="display: flex; gap: 8px; justify-content: center;">';
        echo '<button type="button" class="button button-primary headless-preview-retry" style="font-size: 12px; height: 28px; line-height: 26px; padding: 0 12px;">' . __('Retry', 'vercel-wp') . '</button>';
        echo '<button type="button" class="button button-secondary headless-preview-open-external" style="font-size: 12px; height: 28px; line-height: 26px; padding: 0 12px;">' . __('Open in new tab', 'vercel-wp') . '</button>';
        echo '</div>';
        echo '</div>';
        
        // Preview iframe
        echo '<iframe id="headless-preview-iframe" src="' . esc_url($preview_url) . '" style="width: 100%; height: 100%; border: none; background: white;"></iframe>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        // CSS for loading animation
        echo '<style>';
        echo '@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }';
        echo '</style>';
    }
    public function hide_default_preview_button() {
        $settings = get_option('vercel_wp_preview_settings');
        
        if (isset($settings['show_button_editor']) && $settings['show_button_editor']) {
            // Hide default WordPress preview button
            echo '<style>#preview-action { display: none !important; }</style>';
            
            // Load external CSS and JS files
            wp_enqueue_style('vercel-wp-preview-interface', VERCEL_WP_PLUGIN_URL . 'assets/css/preview-interface.css', array(), VERCEL_WP_VERSION);
            wp_enqueue_script('vercel-wp-preview-interface', VERCEL_WP_PLUGIN_URL . 'assets/js/preview-interface.js', array('jquery'), VERCEL_WP_VERSION, true);
        }
    }
    
    public function admin_page() {
        if (isset($_POST['submit'])) {
            $this->save_settings();
        }
        
        $settings = get_option('vercel_wp_preview_settings');
        include VERCEL_WP_PLUGIN_DIR . 'templates/admin-page.php';
    }
    
    private function save_settings() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'vercel_wp_preview_settings')) {
            wp_die(__('Security error', 'vercel-wp'));
        }
        
        $old_settings = get_option('vercel_wp_preview_settings', array());
        $old_production_url = isset($old_settings['production_url']) ? $old_settings['production_url'] : '';
        
        // Preserve existing settings and only update submitted ones
        $settings = $old_settings; // Start with existing settings
        
                // Update only the submitted fields
                if (isset($_POST['vercel_preview_url'])) {
                    $settings['vercel_preview_url'] = rtrim(sanitize_url($_POST['vercel_preview_url']), '/');
                }
                if (isset($_POST['production_url'])) {
                    $settings['production_url'] = rtrim(sanitize_url($_POST['production_url']), '/');
                }
        if (isset($_POST['cache_duration'])) {
            $settings['cache_duration'] = intval($_POST['cache_duration']);
        }
        
        // Checkboxes
        $settings['auto_refresh'] = isset($_POST['auto_refresh']);
        $settings['show_button_admin_bar'] = isset($_POST['show_button_admin_bar']);
        $settings['show_button_editor'] = isset($_POST['show_button_editor']);
        $settings['disable_theme_page'] = isset($_POST['disable_theme_page']);
        
        // Preserve last production URL for comparison
        $settings['last_production_url'] = $old_production_url;
        
        update_option('vercel_wp_preview_settings', $settings);
        
        // Check if production URL has changed and show notification
        $new_production_url = $settings['production_url'];
        if (!empty($old_production_url) && !empty($new_production_url) && $old_production_url !== $new_production_url) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>' . __('URL de production modifiée !', 'vercel-wp') . '</strong><br>';
            echo sprintf(__('Ancienne URL : %s', 'vercel-wp'), '<code>' . esc_html($old_production_url) . '</code>') . '<br>';
            echo sprintf(__('Nouvelle URL : %s', 'vercel-wp'), '<code>' . esc_html($new_production_url) . '</code>') . '<br><br>';
            echo __('<strong>Action recommandée :</strong> Utilisez l\'outil "Remplacement d\'URLs" ci-dessous pour mettre à jour tous les liens dans votre contenu.', 'vercel-wp');
            echo '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>' . __('Settings saved', 'vercel-wp') . '</p></div>';
        }
    }
    
    public function ajax_get_preview_url() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        
        $url = sanitize_url($_POST['url']);
        $preview_url = $this->get_preview_url($url);
        
        wp_send_json_success(array('preview_url' => $preview_url));
    }
    
    public function ajax_clear_cache() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        
        $url = sanitize_url($_POST['url']);
        
        // Get Vercel API credentials to determine method used
        $api_key = get_option('vercel_api_key');
        $project_id = get_option('vercel_site_id');
        
        $this->clear_cache_for_url($url);
        
        // Determine which method was used for user feedback
        if ($api_key && $project_id) {
            $message = __('Cache cleared via Vercel API', 'vercel-wp');
        } else {
            $message = __('Cache timestamp updated (Vercel API not configured)', 'vercel-wp');
        }
        
        wp_send_json_success(array('message' => $message));
    }
    
    private function get_current_page_url() {
        if (is_singular()) {
            return get_permalink();
        } elseif (is_home()) {
            return home_url();
        } elseif (is_category()) {
            return get_category_link(get_queried_object_id());
        } elseif (is_tag()) {
            return get_tag_link(get_queried_object_id());
        } elseif (is_archive()) {
            return get_post_type_archive_link(get_post_type());
        }
        
        return null;
    }
    
    private function get_preview_url($wordpress_url) {
        $settings = get_option('vercel_wp_preview_settings');
        $vercel_preview_url = $settings['vercel_preview_url'];
        
        if (empty($vercel_preview_url)) {
            return $wordpress_url;
        }
        
        // Map WordPress URL to Vercel URL
        $mapped_url = $this->map_wordpress_to_vercel_url($wordpress_url, $settings);
        
        // Add parameter to force cache refresh
        $cache_buster = time();
        $separator = strpos($mapped_url, '?') !== false ? '&' : '?';
        
        return $mapped_url . $separator . 'wp_preview=' . $cache_buster;
    }
    
    private function map_wordpress_to_vercel_url($wordpress_url, $settings) {
        $production_url = $settings['production_url'];
        $vercel_preview_url = $settings['vercel_preview_url'];
        
        // If we have a production URL, we can map paths
        if (!empty($production_url)) {
            $parsed_wp = parse_url($wordpress_url);
            $parsed_prod = parse_url($production_url);
            
            // Replace domain with Vercel preview URL
            $path = isset($parsed_wp['path']) ? $parsed_wp['path'] : '/';
            $query = isset($parsed_wp['query']) ? '?' . $parsed_wp['query'] : '';
            
            // Ensure path starts with /
            if (!empty($path) && $path[0] !== '/') {
                $path = '/' . $path;
            }
            
            $mapped_url = rtrim($vercel_preview_url, '/') . $path . $query;
            
            return $mapped_url;
        }
        
        // If no production URL but we have Vercel URL, try to map anyway
        if (!empty($vercel_preview_url)) {
            $parsed_wp = parse_url($wordpress_url);
            $path = isset($parsed_wp['path']) ? $parsed_wp['path'] : '/';
            $query = isset($parsed_wp['query']) ? '?' . $parsed_wp['query'] : '';
            
            // Ensure path starts with /
            if (!empty($path) && $path[0] !== '/') {
                $path = '/' . $path;
            }
            
            $mapped_url = rtrim($vercel_preview_url, '/') . $path . $query;
            
            return $mapped_url;
        }
        
        // Fallback: use preview URL directly
        return $vercel_preview_url;
    }
    
    public function ajax_test_connection() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        
        $vercel_url = sanitize_url($_POST['vercel_url']);
        
        if (empty($vercel_url)) {
            wp_send_json_error(array('message' => __('Preview URL missing', 'vercel-wp')));
        }
        
        // Debug: Log tested URL
        // Debug logs removed
        
        // Test first with HEAD request to avoid redirections
        $response = wp_remote_head($vercel_url, array(
            'timeout' => 10,
            'redirection' => 0, // No redirection for HEAD
            'headers' => array(
                'User-Agent' => 'HeadlessPreview/1.0',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
            ),
            'sslverify' => false
        ));
        
        // If HEAD fails, try GET with limited redirections
        if (is_wp_error($response)) {
            // Debug logs removed
            
            $response = wp_remote_get($vercel_url, array(
                'timeout' => 15,
                'redirection' => 2, // Limit to 2 redirections only
                'headers' => array(
                    'User-Agent' => 'HeadlessPreview/1.0',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
                ),
                'sslverify' => false
            ));
        }
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            // Debug logs removed
            
            // More specific error messages
            if (strpos($error_message, 'too many redirects') !== false) {
                // Try to diagnose redirect issue
                $diagnosis = $this->diagnose_redirect_issue($vercel_url);
                wp_send_json_error(array('message' => __('Too many redirects detected. ', 'vercel-wp') . $diagnosis));
            } elseif (strpos($error_message, 'timeout') !== false) {
                wp_send_json_error(array('message' => __('Connection timeout. URL takes too long to respond.', 'vercel-wp')));
            } elseif (strpos($error_message, 'SSL') !== false) {
                wp_send_json_error(array('message' => __('SSL error. Check that the URL uses HTTPS correctly.', 'vercel-wp')));
            } else {
                wp_send_json_error(array('message' => sprintf(__('Connection error: %s', 'vercel-wp'), $error_message)));
            }
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $final_url = wp_remote_retrieve_header($response, 'location');
        
        // Debug logs removed
        
        if ($status_code >= 200 && $status_code < 400) {
            $message = sprintf(__('Connection successful! (HTTP Code: %d)', 'vercel-wp'), $status_code);
            if ($final_url && $final_url !== $vercel_url) {
                $message .= sprintf(__(' - Redirected to: %s', 'vercel-wp'), $final_url);
            }
            wp_send_json_success(array('message' => $message));
        } else {
            wp_send_json_error(array('message' => sprintf(__('HTTP error %d. Check that the URL is accessible.', 'vercel-wp'), $status_code)));
        }
    }
    
    private function diagnose_redirect_issue($url) {
        // Try to follow redirects manually to diagnose
        $current_url = $url;
        $redirects = array();
        $max_redirects = 5;
        
        for ($i = 0; $i < $max_redirects; $i++) {
            $response = wp_remote_head($current_url, array(
                'timeout' => 5,
                'redirection' => 0,
                'sslverify' => false
            ));
            
            if (is_wp_error($response)) {
                break;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $location = wp_remote_retrieve_header($response, 'location');
            
            $redirects[] = array(
                'url' => $current_url,
                'status' => $status_code,
                'location' => $location
            );
            
            if ($status_code >= 300 && $status_code < 400 && $location) {
                $current_url = $location;
            } else {
                break;
            }
        }
        
        if (count($redirects) > 3) {
            $chain = '';
            foreach ($redirects as $redirect) {
                $chain .= $redirect['url'] . ' → ';
            }
            return __('Redirect chain detected: ' . rtrim($chain, ' → '), 'vercel-wp');
        }
        
        return __('Check that the URL is correct and accessible.', 'vercel-wp');
    }
    
    public function ajax_test_connection_debug() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        
        $vercel_url = sanitize_url($_POST['vercel_url']);
        
        if (empty($vercel_url)) {
            wp_send_json_error(array('debug_info' => 'Preview URL missing'));
        }
        
        $debug_info = "=== ADVANCED DIAGNOSTIC ===\n";
        $debug_info .= "Tested URL: " . $vercel_url . "\n\n";
        
        // Test 1: Simple HEAD request
        $debug_info .= "1. HEAD test (no redirection):\n";
        $response = wp_remote_head($vercel_url, array(
            'timeout' => 10,
            'redirection' => 0,
            'sslverify' => false
        ));
        
        if (is_wp_error($response)) {
            $debug_info .= "   ❌ Error: " . $response->get_error_message() . "\n";
        } else {
            $status = wp_remote_retrieve_response_code($response);
            $location = wp_remote_retrieve_header($response, 'location');
            $debug_info .= "   ✅ Status: " . $status . "\n";
            if ($location) {
                $debug_info .= "   🔄 Redirect to: " . $location . "\n";
            }
        }
        
        // Test 2: Manual redirect following
        $debug_info .= "\n2. Redirect following:\n";
        $current_url = $vercel_url;
        $redirects = array();
        $max_redirects = 5;
        
        for ($i = 0; $i < $max_redirects; $i++) {
            $debug_info .= "   Step " . ($i + 1) . ": " . $current_url . "\n";
            
            $response = wp_remote_head($current_url, array(
                'timeout' => 5,
                'redirection' => 0,
                'sslverify' => false
            ));
            
            if (is_wp_error($response)) {
                $debug_info .= "   ❌ Error: " . $response->get_error_message() . "\n";
                break;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $location = wp_remote_retrieve_header($response, 'location');
            
            $debug_info .= "   Status: " . $status_code . "\n";
            
            if ($status_code >= 300 && $status_code < 400 && $location) {
                $debug_info .= "   🔄 Redirect to: " . $location . "\n";
                $current_url = $location;
                $redirects[] = $current_url;
            } else {
                $debug_info .= "   ✅ End of redirect chain\n";
                break;
            }
        }
        
        if (count($redirects) >= $max_redirects) {
            $debug_info .= "\n⚠️  PROBLEM DETECTED: Too many redirects!\n";
            $debug_info .= "Complete chain:\n";
            $debug_info .= $vercel_url . "\n";
            foreach ($redirects as $redirect) {
                $debug_info .= "→ " . $redirect . "\n";
            }
        }
        
        // Test 3: DNS verification
        $debug_info .= "\n3. DNS verification:\n";
        $parsed_url = parse_url($vercel_url);
        $host = $parsed_url['host'];
        $debug_info .= "   Host: " . $host . "\n";
        
        $ip = gethostbyname($host);
        if ($ip === $host) {
            $debug_info .= "   ❌ DNS resolution failed\n";
        } else {
            $debug_info .= "   ✅ IP resolved: " . $ip . "\n";
        }
        
        // Test 4: Basic connectivity test
        $debug_info .= "\n4. Connectivity test:\n";
        $socket = @fsockopen($host, 443, $errno, $errstr, 5);
        if ($socket) {
            $debug_info .= "   ✅ Port 443 (HTTPS) accessible\n";
            fclose($socket);
        } else {
            $debug_info .= "   ❌ Port 443 inaccessible: " . $errstr . "\n";
        }
        
        $debug_info .= "\n=== END OF DIAGNOSTIC ===";
        
        wp_send_json_success(array('debug_info' => $debug_info));
    }
    
    public function ajax_check_status() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        
        $settings = get_option('vercel_wp_preview_settings');
        $vercel_url = $settings['vercel_preview_url'];
        
        if (empty($vercel_url)) {
            wp_send_json_success(array('connected' => false, 'message' => __('URL not configured', 'vercel-wp')));
        }
        
        // Quick connection test
        $response = wp_remote_get($vercel_url, array(
            'timeout' => 5,
            'headers' => array(
                'User-Agent' => 'HeadlessPreview/1.0'
            )
        ));
        
        $connected = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        
        wp_send_json_success(array(
            'connected' => $connected,
            'message' => $connected ? __('Connection active', 'vercel-wp') : __('Connection inactive', 'vercel-wp')
        ));
    }
    
    /**
     * AJAX handler for previewing URL replacements
     */
    public function ajax_preview_urls() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        
        $old_url = sanitize_url($_POST['old_url']);
        $new_url = sanitize_url($_POST['new_url']);
        
        if (empty($old_url) || empty($new_url)) {
            wp_send_json_error(array('message' => __('URLs are required', 'vercel-wp')));
        }
        
        try {
            // Count occurrences in different areas
            $preview = $this->count_url_occurrences($old_url);
            
            wp_send_json_success(array('preview' => $preview));
            
        } catch (Exception $e) {
            // Debug logs removed
            wp_send_json_error(array('message' => __('Error occurred while previewing URLs', 'vercel-wp')));
        }
    }
    
    /**
     * Count URL occurrences in different areas
     */
    private function count_url_occurrences($search_url) {
        global $wpdb;
        
        $counts = array(
            'posts' => 0,
            'postmeta' => 0,
            'comments' => 0,
            'options' => 0,
            'widgets' => 0,
            'customizer' => 0,
            'theme_mods' => 0,
            'total_count' => 0
        );
        
        // Count in posts content and excerpts
        $posts_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE (post_content LIKE %s OR post_excerpt LIKE %s) 
             AND post_status = 'publish'",
            '%' . $wpdb->esc_like($search_url) . '%',
            '%' . $wpdb->esc_like($search_url) . '%'
        ));
        $counts['posts'] = intval($posts_count);
        
        // Count in postmeta (excluding plugin data)
        $postmeta_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_value LIKE %s 
             AND meta_key NOT LIKE 'vercel_wp_preview_%'",
            '%' . $wpdb->esc_like($search_url) . '%'
        ));
        $counts['postmeta'] = intval($postmeta_count);
        
        // Count in comments
        $comments_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} 
             WHERE comment_content LIKE %s 
             AND comment_approved = '1'",
            '%' . $wpdb->esc_like($search_url) . '%'
        ));
        $counts['comments'] = intval($comments_count);
        
        // Count in options (excluding plugin data and critical options)
        $options_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} 
             WHERE option_value LIKE %s 
             AND option_name NOT LIKE 'vercel_wp_preview_%'
             AND option_name NOT IN ('siteurl', 'home', 'admin_email')",
            '%' . $wpdb->esc_like($search_url) . '%'
        ));
        $counts['options'] = intval($options_count);
        
        // Count in widgets
        $widgets_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} 
             WHERE option_name LIKE 'widget_%' 
             AND option_value LIKE %s",
            '%' . $wpdb->esc_like($search_url) . '%'
        ));
        $counts['widgets'] = intval($widgets_count);
        
        // Count in customizer options
        $customizer_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} 
             WHERE option_name LIKE 'theme_mods_%' 
             AND option_value LIKE %s",
            '%' . $wpdb->esc_like($search_url) . '%'
        ));
        $counts['customizer'] = intval($customizer_count);
        
        // Count in theme mods
        $theme_mods_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} 
             WHERE option_name = 'theme_mods_' 
             AND option_value LIKE %s",
            '%' . $wpdb->esc_like($search_url) . '%'
        ));
        $counts['theme_mods'] = intval($theme_mods_count);
        
        // Calculate total
        $counts['total_count'] = $counts['posts'] + $counts['postmeta'] + $counts['comments'] + 
                                $counts['options'] + $counts['widgets'] + $counts['customizer'] + $counts['theme_mods'];
        
        return $counts;
    }
    
    /**
     * AJAX handler for replacing URLs
     */
    public function ajax_replace_urls() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        
        $old_url = sanitize_url($_POST['old_url']);
        $new_url = sanitize_url($_POST['new_url']);
        
        if (empty($old_url) || empty($new_url)) {
            wp_send_json_error(array('message' => __('URLs are required', 'vercel-wp')));
        }
        
        try {
            // Perform the URL replacement
            $replaced_count = $this->replace_urls_in_content($old_url, $new_url);
            
            // Store replacement info for potential production URL update
            if ($replaced_count > 0) {
                $this->store_replacement_info($old_url, $new_url);
            }
            
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully replaced %d occurrences', 'vercel-wp'), $replaced_count),
                'replaced_count' => $replaced_count
            ));
            
        } catch (Exception $e) {
            // Debug logs removed
            wp_send_json_error(array('message' => __('Error occurred while replacing URLs', 'vercel-wp')));
        }
    }
    
    /**
     * Store replacement info for potential production URL update
     */
    private function store_replacement_info($old_url, $new_url) {
        $settings = get_option('vercel_wp_preview_settings', array());
        
        // Check if the old URL matches the current production URL
        $current_production_url = isset($settings['production_url']) ? rtrim($settings['production_url'], '/') : '';
        $old_url_clean = rtrim($old_url, '/');
        
        // If the old URL matches the current production URL, store the info for later update
        if ($current_production_url === $old_url_clean) {
            $settings['pending_production_url_update'] = array(
                'old_url' => $old_url_clean,
                'new_url' => rtrim($new_url, '/'),
                'timestamp' => time()
            );
            update_option('vercel_wp_preview_settings', $settings);
            
            // Debug logs removed
        }
    }
    
    /**
     * AJAX handler for applying production URL update
     */
    public function ajax_apply_production_url_update() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        
        $settings = get_option('vercel_wp_preview_settings', array());
        
        if (isset($settings['pending_production_url_update'])) {
            $update_info = $settings['pending_production_url_update'];
            
            // Apply the production URL update
            $settings['production_url'] = $update_info['new_url'];
            unset($settings['pending_production_url_update']); // Remove pending update
            
            $result = update_option('vercel_wp_preview_settings', $settings);
            
            if ($result) {
                // Debug logs removed
                wp_send_json_success(array(
                    'message' => __('Production URL updated successfully', 'vercel-wp'),
                    'new_production_url' => $update_info['new_url']
                ));
            } else {
                wp_send_json_error(array('message' => __('Failed to update production URL', 'vercel-wp')));
            }
        } else {
            wp_send_json_error(array('message' => __('No pending production URL update found', 'vercel-wp')));
        }
    }
    
    /**
     * AJAX handler for saving settings
     */
    public function ajax_save_settings() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        
        $settings = get_option('vercel_wp_preview_settings', array());
        
        // Update production URL if provided
        if (isset($_POST['production_url'])) {
            $settings['production_url'] = sanitize_url($_POST['production_url']);
            // Debug logs removed
        }
        
        $result = update_option('vercel_wp_preview_settings', $settings);
        
        // Debug: Log the save result
        // Debug logs removed
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Settings saved successfully', 'vercel-wp'),
                'production_url' => $settings['production_url']
            ));
        } else {
            wp_send_json_error(array('message' => __('Failed to save settings', 'vercel-wp')));
        }
    }
    
    
    private function clear_cache_for_url($url) {
        // Get Vercel API credentials
        $api_key = get_option('vercel_api_key');
        $project_id = get_option('vercel_site_id');
        
        if (!$api_key || !$project_id) {
            // Fallback to timestamp method if API credentials not configured
            $settings = get_option('vercel_wp_preview_settings');
            $settings['last_cache_clear'] = time();
            update_option('vercel_wp_preview_settings', $settings);
            return;
        }
        
        // Try to clear Vercel cache directly via API
        $this->purge_vercel_cache_direct($api_key, $project_id);
        
        // Also update timestamp as fallback
        $settings = get_option('vercel_wp_preview_settings');
        $settings['last_cache_clear'] = time();
        update_option('vercel_wp_preview_settings', $settings);
    }
    
    /**
     * Try to purge Vercel cache directly via API
     */
    private function purge_vercel_cache_direct($api_key, $project_id) {
        // Try different possible endpoints for cache purging
        $endpoints = array(
            "https://api.vercel.com/v1/integrations/deploy/{$project_id}/clear-cache",
            "https://api.vercel.com/v1/projects/{$project_id}/cache/purge",
            "https://api.vercel.com/v1/edge-cache/purge"
        );
        
        foreach ($endpoints as $endpoint) {
            $response = wp_remote_post($endpoint, array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'WordPress-Vercel-WP/' . VERCEL_WP_VERSION
                ),
                'body' => json_encode(array(
                    'paths' => ['/*'],
                    'projectId' => $project_id
                ))
            ));
            
            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code >= 200 && $status_code < 300) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Update existing settings to include new options
     */
    private function update_existing_settings() {
        $settings = get_option('vercel_wp_preview_settings', array());
        
        // Add new options if they don't exist
        $default_options = array(
            'vercel_preview_url' => '',
            'production_url' => '',
            'cache_duration' => 300,
            'auto_refresh' => true,
            'show_button_admin_bar' => true,
            'show_button_editor' => true,
            'disable_theme_page' => true
        );
        
        $updated = false;
        foreach ($default_options as $key => $default_value) {
            if (!isset($settings[$key])) {
                $settings[$key] = $default_value;
                $updated = true;
            }
        }
        
        if ($updated) {
            update_option('vercel_wp_preview_settings', $settings);
        }
    }
    
    /**
     * Initialize headless functionality
     */
    public function init_headless_functionality() {
        // Get current settings
        $settings = get_option('vercel_wp_preview_settings', array());
        
        // Debug: Log initialization
        error_log('Vercel WP: init_headless_functionality called');
        error_log('Vercel WP: Settings: ' . print_r($settings, true));
        
        // Define URL constants
        $this->define_url_constants();
        
        // Admin bar URL modification is now handled directly in constructor
        if (!empty($settings['production_url'])) {
            error_log('Vercel WP: Production URL found: ' . $settings['production_url']);
        } else {
            error_log('Vercel WP: No production URL configured');
        }
        
        // Handle theme page disabling
        if (isset($settings['disable_theme_page']) && $settings['disable_theme_page']) {
            add_action('admin_menu', array($this, 'remove_themes_menu_item'), 999);
            add_action('admin_init', array($this, 'redirect_themes_page'));
        }
        
        // Redirect all public routes only if production URL is configured
        if (!empty($settings['production_url'])) {
            add_action('template_redirect', array($this, 'redirect_all_public_routes'), 1);
        }
    }
    
    /**
     * Define URL constants based on settings
     */
    private function define_url_constants() {
        $settings = get_option('vercel_wp_preview_settings');
        
        // Define FRONTEND_URL (production URL)
        if (!defined('FRONTEND_URL')) {
            $frontend_url = !empty($settings['production_url']) ? $settings['production_url'] : home_url();
            define('FRONTEND_URL', rtrim($frontend_url, '/'));
        }
        
        // Define PREVIEW_URL (Vercel preview URL)
        if (!defined('PREVIEW_URL')) {
            $preview_url = !empty($settings['vercel_preview_url']) ? $settings['vercel_preview_url'] : $frontend_url;
            define('PREVIEW_URL', rtrim($preview_url, '/'));
        }
        
        // Define ADMIN_URL (WordPress admin URL)
        if (!defined('ADMIN_URL')) {
            define('ADMIN_URL', rtrim(home_url(), '/'));
        }
    }
    
    /**
     * Rewrite permalink to use production URL instead of WordPress admin URL
     * Adds language prefix for secondary languages only (not for primary language)
     * 
     * @param string $permalink The original permalink
     * @param WP_Post $post The post object
     * @param bool $leavename Whether to leave the post name
     * @return string The rewritten permalink
     */
    public function rewrite_permalink($permalink, $post, $leavename) {
        // Get production URL from plugin settings
        $settings = get_option('vercel_wp_preview_settings', array());
        $production_url = !empty($settings['production_url']) ? rtrim($settings['production_url'], '/') : '';
        
        // If no production URL is configured, return original permalink
        if (empty($production_url)) {
            return $permalink;
        }
        
        // Get WordPress admin URL (source URL to replace)
        $wp_admin_url = rtrim(home_url(), '/');
        
        // Skip if permalink doesn't contain WordPress admin URL
        if (strpos($permalink, $wp_admin_url) === false) {
            return $permalink;
        }
        
        // Replace WordPress admin URL with production URL
        $rewritten_permalink = str_replace($wp_admin_url, $production_url, $permalink);
        
        // Get the language of the post/content
        $post_lang_code = $this->get_post_language_code($post);
        $primary_lang_code = $this->get_primary_language_code();
        
        // Only add language prefix if:
        // 1. Site is multilingual
        // 2. Post has a language code
        // 3. Post language is NOT the primary language
        if ($this->is_multilingual_site() && !empty($post_lang_code) && !empty($primary_lang_code)) {
            if (strtolower($post_lang_code) !== strtolower($primary_lang_code)) {
                // This is a secondary language, add the prefix
                $parsed_rewritten = parse_url($rewritten_permalink);
                $rewritten_path = isset($parsed_rewritten['path']) ? $parsed_rewritten['path'] : '/';
                $rewritten_query = isset($parsed_rewritten['query']) ? '?' . $parsed_rewritten['query'] : '';
                $rewritten_fragment = isset($parsed_rewritten['fragment']) ? '#' . $parsed_rewritten['fragment'] : '';
                
                // Check if prefix is already present
                $normalized_path = ltrim($rewritten_path, '/');
                $prefix_pattern = '/^' . preg_quote($post_lang_code, '/') . '\//i';
                
                if (!preg_match($prefix_pattern, $normalized_path)) {
                    // Add the language prefix
                    $path_with_prefix = '/' . $post_lang_code . $rewritten_path;
                    $path_with_prefix = preg_replace('#/+#', '/', $path_with_prefix);
                    
                    // Reconstruct the URL with language prefix
                    $rewritten_scheme = isset($parsed_rewritten['scheme']) ? $parsed_rewritten['scheme'] . '://' : '';
                    $rewritten_host = isset($parsed_rewritten['host']) ? $parsed_rewritten['host'] : '';
                    $rewritten_permalink = $rewritten_scheme . $rewritten_host . $path_with_prefix . $rewritten_query . $rewritten_fragment;
                }
            }
            // If it's the primary language, don't add prefix (keep as is)
        }
        
        // Debug logging for admin users
        if (current_user_can('manage_options') && WP_DEBUG) {
            $action = (!empty($post_lang_code) && !empty($primary_lang_code) && strtolower($post_lang_code) !== strtolower($primary_lang_code)) ? 'added' : 'none';
            error_log(sprintf(
                'Vercel WP Permalink Rewrite: %s -> %s (post lang: %s, primary: %s, action: %s)',
                $permalink,
                $rewritten_permalink,
                $post_lang_code ?: 'none',
                $primary_lang_code ?: 'none',
                $action
            ));
        }
        
        return $rewritten_permalink;
    }
    
    /**
     * Extract language prefix from permalink URL
     * Supports WPML, Polylang, and other multilingual plugins
     * 
     * @param string $permalink The permalink URL
     * @param string $base_url The base WordPress URL
     * @return string The language prefix (e.g., '/en/', '/it/') or empty string
     */
    private function extract_language_prefix($permalink, $base_url) {
        // First, try to extract from the URL path directly
        $parsed = parse_url($permalink);
        $path = isset($parsed['path']) ? $parsed['path'] : '';
        
        // Remove leading slash for pattern matching
        $path = ltrim($path, '/');
        
        // Pattern to match language codes (2-3 letters, sometimes with country code)
        // Matches patterns like: /en/, /en-US/, /it/, /fr/, /de/, etc.
        // Also supports custom language codes from multilingual plugins
        if (!empty($path) && preg_match('#^([a-z]{2,3}(?:-[a-z]{2,3})?)/#i', $path, $matches)) {
            $lang_code = $matches[1];
            
            // Verify it's likely a language prefix by checking against known patterns
            // WPML and Polylang typically use 2-3 letter codes
            // Also check if it's a valid language code format
            if (strlen($lang_code) >= 2 && strlen($lang_code) <= 10) {
                // Additional validation: check if multilingual plugin is active
                // This helps avoid false positives
                if ($this->is_multilingual_site()) {
                    return '/' . $lang_code . '/';
                }
            }
        }
        
        // If not found in URL, try to get language from multilingual plugins directly
        // This is useful when the permalink doesn't yet contain the language prefix
        // but we're on a multilingual site
        if ($this->is_multilingual_site()) {
            $lang_code = $this->get_current_language_code();
            if (!empty($lang_code)) {
                return '/' . $lang_code . '/';
            }
        }
        
        return '';
    }
    
    /**
     * Check if site is multilingual (WPML, Polylang, etc.)
     * 
     * @return bool True if multilingual plugin is active
     */
    private function is_multilingual_site() {
        // Check for WPML
        if (defined('ICL_SITEPRESS_VERSION') || function_exists('icl_object_id')) {
            return true;
        }
        
        // Check for Polylang
        if (function_exists('pll_current_language') || function_exists('PLL')) {
            return true;
        }
        
        // Check for TranslatePress
        if (class_exists('TRP_Translate_Press')) {
            return true;
        }
        
        // Check for Weglot
        if (class_exists('WeglotWPInit')) {
            return true;
        }
        
        // Check for qTranslate-X
        if (function_exists('qtranxf_getLanguage')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get current language code from multilingual plugin
     * 
     * @return string Language code or empty string
     */
    private function get_current_language_code() {
        // WPML
        if (defined('ICL_LANGUAGE_CODE')) {
            return ICL_LANGUAGE_CODE;
        }
        if (function_exists('icl_get_current_language')) {
            $lang = icl_get_current_language();
            if ($lang) {
                return $lang;
            }
        }
        
        // Polylang
        if (function_exists('pll_current_language')) {
            $lang = pll_current_language('slug');
            if ($lang) {
                return $lang;
            }
        }
        
        // TranslatePress
        if (class_exists('TRP_Translate_Press')) {
            global $TRP_LANGUAGE;
            if (isset($TRP_LANGUAGE) && !empty($TRP_LANGUAGE)) {
                return $TRP_LANGUAGE;
            }
        }
        
        // Weglot
        if (class_exists('WeglotWPInit')) {
            $lang = weglot_get_current_language();
            if ($lang) {
                return $lang;
            }
        }
        
        // qTranslate-X
        if (function_exists('qtranxf_getLanguage')) {
            $lang = qtranxf_getLanguage();
            if ($lang) {
                return $lang;
            }
        }
        
        return '';
    }
    
    /**
     * Get language code for a specific post
     * 
     * @param WP_Post|int|null $post Post object or post ID
     * @return string Language code or empty string
     */
    private function get_post_language_code($post) {
        // If post is null or not provided, try to get current language
        if (empty($post)) {
            return $this->get_current_language_code();
        }
        
        // Get post ID
        $post_id = is_object($post) ? $post->ID : intval($post);
        
        if (empty($post_id)) {
            return $this->get_current_language_code();
        }
        
        // WPML
        if (function_exists('wpml_get_language_information')) {
            $lang_info = wpml_get_language_information($post_id);
            // Check if result is not a WP_Error and is an array
            if (!is_wp_error($lang_info) && is_array($lang_info) && isset($lang_info['language_code']) && !empty($lang_info['language_code'])) {
                return $lang_info['language_code'];
            }
        }
        // WPML alternative using filter
        if (function_exists('apply_filters')) {
            $lang = apply_filters('wpml_post_language_details', null, $post_id);
            // Check if result is not a WP_Error and is an array
            if (!is_wp_error($lang) && is_array($lang) && isset($lang['language_code']) && !empty($lang['language_code'])) {
                return $lang['language_code'];
            }
        }
        // WPML alternative using element language
        if (function_exists('apply_filters')) {
            $lang = apply_filters('wpml_element_language_code', null, array('element_id' => $post_id, 'element_type' => 'post'));
            // Check if result is not a WP_Error and is a string
            if (!is_wp_error($lang) && is_string($lang) && !empty($lang)) {
                return $lang;
            }
        }
        
        // Polylang
        if (function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($post_id, 'slug');
            if ($lang) {
                return $lang;
            }
        }
        
        // TranslatePress
        if (class_exists('TRP_Translate_Press')) {
            // TranslatePress stores language in post meta
            $lang = get_post_meta($post_id, 'trp_language', true);
            if ($lang) {
                return $lang;
            }
        }
        
        // Weglot - doesn't store per-post language, use current language
        if (class_exists('WeglotWPInit')) {
            return $this->get_current_language_code();
        }
        
        // qTranslate-X
        if (function_exists('qtranxf_getLanguage')) {
            // qTranslate-X stores language in post meta
            $lang = get_post_meta($post_id, '_qts_slug_en', true);
            if (!$lang) {
                $lang = get_post_meta($post_id, '_qts_slug', true);
            }
            if (!$lang) {
                return $this->get_current_language_code();
            }
        }
        
        // Fallback to current language if post-specific language not found
        return $this->get_current_language_code();
    }
    
    /**
     * Get primary/default language code from multilingual plugin
     * 
     * @return string Primary language code or empty string
     */
    private function get_primary_language_code() {
        // WPML
        if (function_exists('icl_get_default_language')) {
            $lang = icl_get_default_language();
            if ($lang) {
                return $lang;
            }
        }
        // WPML alternative method
        if (function_exists('apply_filters')) {
            $lang = apply_filters('wpml_default_language', '');
            if ($lang) {
                return $lang;
            }
        }
        
        // Polylang
        if (function_exists('pll_default_language')) {
            $lang = pll_default_language('slug');
            if ($lang) {
                return $lang;
            }
        }
        
        // TranslatePress
        if (class_exists('TRP_Translate_Press')) {
            $settings = get_option('trp_settings');
            if (isset($settings['default-language']) && !empty($settings['default-language'])) {
                return $settings['default-language'];
            }
        }
        
        // Weglot
        if (class_exists('WeglotWPInit')) {
            $weglot_settings = get_option('weglot_settings');
            if (isset($weglot_settings['original_language']) && !empty($weglot_settings['original_language'])) {
                return $weglot_settings['original_language'];
            }
        }
        
        // qTranslate-X
        if (function_exists('qtranxf_getDefaultLanguage')) {
            $lang = qtranxf_getDefaultLanguage();
            if ($lang) {
                return $lang;
            }
        }
        
        // Fallback: try to get from WordPress locale
        $locale = get_locale();
        if ($locale) {
            // Extract language code from locale (e.g., 'fr_FR' -> 'fr')
            $lang_parts = explode('_', $locale);
            if (!empty($lang_parts[0])) {
                return strtolower($lang_parts[0]);
            }
        }
        
        return '';
    }
    
    /**
     * Test permalinks functionality
     */
    public function ajax_test_permalinks() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'vercel-wp')));
        }
        
        $settings = get_option('vercel_wp_preview_settings', array());
        $production_url = !empty($settings['production_url']) ? rtrim($settings['production_url'], '/') : '';
        
        // Re-ensure filters are active (in case they were removed)
        $this->init_permalink_filters();
        
        // Test with a sample permalink
        $test_permalink = home_url('/test-page/');
        $rewritten_permalink = $this->rewrite_permalink($test_permalink, null, false);
        
        // Check permalink structure
        $permalink_structure = get_option('permalink_structure');
        
        // Check if filters are actually working by testing WordPress functions
        $test_post_id = wp_insert_post(array(
            'post_title' => 'Test Post for Permalink',
            'post_name' => 'test-post-for-permalink',
            'post_content' => 'Test content',
            'post_status' => 'publish', // Must be publish to get proper permalinks
            'post_type' => 'post'
        ));
        
        $test_page_id = wp_insert_post(array(
            'post_title' => 'Test Page for Permalink',
            'post_name' => 'test-page-for-permalink',
            'post_content' => 'Test content',
            'post_status' => 'publish', // Must be publish to get proper permalinks
            'post_type' => 'page'
        ));
        
        $post_permalink = get_permalink($test_post_id);
        $page_permalink = get_permalink($test_page_id);
        
        // Clean up test posts
        wp_delete_post($test_post_id, true);
        wp_delete_post($test_page_id, true);
        
        $result = array(
            'original_permalink' => $test_permalink,
            'rewritten_permalink' => $rewritten_permalink,
            'production_url' => $production_url,
            'home_url' => home_url(),
            'admin_url' => defined('ADMIN_URL') ? ADMIN_URL : rtrim(home_url(), '/'),
            'frontend_url' => !empty($settings['production_url']) ? rtrim($settings['production_url'], '/') : '',
            'permalink_structure' => $permalink_structure ?: __('Non configurée (utilise ?p=)', 'vercel-wp'),
            'filters_active' => array(
                'post_link' => has_filter('post_link', array($this, 'rewrite_permalink')),
                'page_link' => has_filter('page_link', array($this, 'rewrite_permalink')),
                'post_type_link' => has_filter('post_type_link', array($this, 'rewrite_permalink')),
                'get_permalink' => has_filter('get_permalink', array($this, 'rewrite_permalink'))
            ),
            'rewrite_working' => ($rewritten_permalink !== $test_permalink),
            'actual_permalink_test' => array(
                'post_permalink' => $post_permalink,
                'page_permalink' => $page_permalink,
                'post_rewritten' => (strpos($post_permalink, $production_url) !== false),
                'page_rewritten' => (strpos($page_permalink, $production_url) !== false)
            )
        );
        
        wp_send_json_success($result);
    }
    
    /**
     * Clear permalink cache via AJAX
     */
    public function ajax_clear_permalink_cache() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'vercel-wp')));
        }
        
        // Clear permalink cache
        $this->clear_permalink_cache();
        
        wp_send_json_success(array('message' => __('Permalink cache cleared successfully', 'vercel-wp')));
    }
    
    /**
     * Force permalink refresh in admin
     */
    public function force_permalink_refresh() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Force refresh permalink fields in admin
            $('.permalink-field input').each(function() {
                var $input = $(this);
                var originalValue = $input.val();
                if (originalValue && originalValue.indexOf('<?php echo home_url(); ?>') !== -1) {
                    // Trigger change event to force refresh
                    $input.trigger('change');
                }
            });
            
            // Also refresh any permalink displays
            $('.permalink-display').each(function() {
                var $display = $(this);
                var originalValue = $display.text();
                if (originalValue && originalValue.indexOf('<?php echo home_url(); ?>') !== -1) {
                    // Force refresh by triggering a custom event
                    $display.trigger('permalink-refresh');
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Clear permalink cache when settings change
     */
    public function clear_permalink_cache() {
        // Clear WordPress object cache
        wp_cache_flush();
        
        // Clear permalink structure cache
        delete_option('rewrite_rules');
        
        // Force rewrite rules refresh
        flush_rewrite_rules();
        
        // Clear any custom permalink caches
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%permalink%' AND option_name != 'permalink_structure'");
        
        // Update URL constants
        $this->define_url_constants();
    }
    
    /**
     * Fix admin bar URLs using JavaScript
     */
    public function fix_admin_bar_urls_js() {
        $settings = get_option('vercel_wp_preview_settings', array());
        $production_url = !empty($settings['production_url']) ? rtrim($settings['production_url'], '/') : '';
        
        if (empty($production_url)) {
            return;
        }
        ?>
        <script type="text/javascript">
        (function() {
            var productionUrl = '<?php echo esc_js($production_url); ?>';
            
            function fixAdminBarUrls() {
                // Fix using pure JavaScript (no jQuery dependency)
                var siteNameLink = document.querySelector('#wp-admin-bar-site-name > a');
                var viewSiteLink = document.querySelector('#wp-admin-bar-view-site > a');
                
                if (siteNameLink) {
                    siteNameLink.setAttribute('href', productionUrl);
                    console.log('Vercel WP: Updated site-name link to:', productionUrl);
                }
                
                if (viewSiteLink) {
                    viewSiteLink.setAttribute('href', productionUrl);
                    console.log('Vercel WP: Updated view-site link to:', productionUrl);
                }
                
                // Also try with .ab-item class
                var abItems = document.querySelectorAll('#wp-admin-bar-site-name .ab-item, #wp-admin-bar-view-site .ab-item');
                abItems.forEach(function(item) {
                    if (item.tagName === 'A') {
                        item.setAttribute('href', productionUrl);
                        console.log('Vercel WP: Updated ab-item link to:', productionUrl);
                    }
                });
            }
            
            // Run on DOM ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fixAdminBarUrls);
            } else {
                fixAdminBarUrls();
            }
            
            // Also run after delays to catch late renders
            setTimeout(fixAdminBarUrls, 100);
            setTimeout(fixAdminBarUrls, 500);
            setTimeout(fixAdminBarUrls, 1000);
        })();
        </script>
        <?php
    }
    
    /**
     * Disable WordPress theme admin page
     */
    public function remove_themes_menu_item() {
        remove_menu_page('themes.php');
    }
    
    /**
     * Redirect themes page to admin dashboard
     */
    public function redirect_themes_page() {
        global $pagenow;
        if ($pagenow == 'themes.php') {
            wp_redirect(admin_url());
            exit;
        }
    }
    
    /**
     * Redirect all public routes to the production URL
     */
    public function redirect_all_public_routes() {
        if (!is_admin()) {
            $settings = get_option('vercel_wp_preview_settings', array());
            $production_url = !empty($settings['production_url']) ? rtrim($settings['production_url'], '/') : '';
            
            // If no production URL is configured, don't redirect
            if (empty($production_url)) {
                return;
            }
            
            global $wp;
            $current_url = home_url(add_query_arg(array(), $wp->request));
            $new_url = str_replace(home_url(), $production_url, $current_url);
            wp_redirect($new_url, 301);
            exit;
        }
    }
    
    /**
     * Replace URLs in all WordPress content (classic search and replace)
     */
    private function replace_urls_in_content($old_url, $new_url) {
        global $wpdb;
        
        $old_url = rtrim($old_url, '/');
        $new_url = rtrim($new_url, '/');
        
        $replaced_count = 0;
        
        // Get all variations of the old URL to search for
        $search_patterns = array(
            $old_url,                    // Exact URL
            $old_url . '/',             // URL with trailing slash
        );
        
        // Add HTTP/HTTPS variations only if they're different
        $url_without_protocol = preg_replace('/^https?:\/\//', '', $old_url);
        if (!empty($url_without_protocol)) {
            $search_patterns[] = 'http://' . $url_without_protocol;   // HTTP version
            $search_patterns[] = 'https://' . $url_without_protocol;  // HTTPS version
            $search_patterns[] = 'http://' . $url_without_protocol . '/';   // HTTP version with slash
            $search_patterns[] = 'https://' . $url_without_protocol . '/';  // HTTPS version with slash
        }
        
        // Remove duplicates and empty values
        $search_patterns = array_unique(array_filter($search_patterns));
        
        foreach ($search_patterns as $search_pattern) {
            $replacement = str_replace($old_url, $new_url, $search_pattern);
            
            // Replace in posts content
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                $search_pattern,
                $replacement,
                '%' . $wpdb->esc_like($search_pattern) . '%'
            ));
            $replaced_count += $result;
            
            // Replace in post excerpts
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_excerpt = REPLACE(post_excerpt, %s, %s) WHERE post_excerpt LIKE %s",
                $search_pattern,
                $replacement,
                '%' . $wpdb->esc_like($search_pattern) . '%'
            ));
            $replaced_count += $result;
            
            // Replace in post meta (with ACF safety)
            $this->replace_in_postmeta_safe($search_pattern, $replacement, $replaced_count);
            
            // Replace in comments
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->comments} SET comment_content = REPLACE(comment_content, %s, %s) WHERE comment_content LIKE %s",
                $search_pattern,
                $replacement,
                '%' . $wpdb->esc_like($search_pattern) . '%'
            ));
            $replaced_count += $result;
            
        // Replace in options using enhanced method (excluding plugin data and admin URLs)
        $this->replace_in_options_safe($search_pattern, $replacement, $replaced_count);
        }
        
        // Replace in widgets (more comprehensive)
        $this->replace_in_widgets($search_pattern, $replacement, $replaced_count);
        
        // Replace in customizer options
        $this->replace_in_customizer($search_pattern, $replacement, $replaced_count);
        
        // Replace in theme mods
        $this->replace_in_theme_mods($search_pattern, $replacement, $replaced_count);
        
        // Log the replacement
        // Debug logs removed
        
        // Clear ACF caches after replacement - inspired by BSR approach
        $this->clear_acf_caches_after_replacement();
        
        return $replaced_count;
    }
    
    /**
     * Replace URLs in widgets (more comprehensive)
     */
    private function replace_in_widgets($search_pattern, $replacement, &$replaced_count) {
        global $wpdb;
        
        // Widget data is stored in options table
        $widget_options = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name, option_value FROM {$wpdb->options} 
             WHERE option_name LIKE 'widget_%' 
             AND option_value LIKE %s",
            '%' . $wpdb->esc_like($search_pattern) . '%'
        ));
        
        foreach ($widget_options as $widget) {
            $original_value = $widget->option_value;
            $new_value = $this->recursive_unserialize_replace($search_pattern, $replacement, $original_value, false, false);
            
            if ($new_value !== $original_value) {
                $result = $wpdb->update(
                    $wpdb->options,
                    array('option_value' => $new_value),
                    array('option_id' => $widget->option_id),
                    array('%s'),
                    array('%d')
                );
                
                if ($result !== false) {
                    $replaced_count++;
                }
            }
        }
    }
    
    /**
     * Replace URLs in customizer options
     */
    private function replace_in_customizer($search_pattern, $replacement, &$replaced_count) {
        global $wpdb;
        
        $customizer_options = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name, option_value FROM {$wpdb->options} 
             WHERE option_name LIKE 'theme_mods_%' 
             AND option_value LIKE %s",
            '%' . $wpdb->esc_like($search_pattern) . '%'
        ));
        
        foreach ($customizer_options as $option) {
            $original_value = $option->option_value;
            $new_value = $this->recursive_unserialize_replace($search_pattern, $replacement, $original_value, false, false);
            
            if ($new_value !== $original_value) {
                $result = $wpdb->update(
                    $wpdb->options,
                    array('option_value' => $new_value),
                    array('option_id' => $option->option_id),
                    array('%s'),
                    array('%d')
                );
                
                if ($result !== false) {
                    $replaced_count++;
                }
            }
        }
    }
    
    /**
     * Replace URLs in theme mods
     */
    private function replace_in_theme_mods($search_pattern, $replacement, &$replaced_count) {
        global $wpdb;
        
        $theme_mods = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name, option_value FROM {$wpdb->options} 
             WHERE option_name = 'theme_mods_' 
             AND option_value LIKE %s",
            '%' . $wpdb->esc_like($search_pattern) . '%'
        ));
        
        foreach ($theme_mods as $mod) {
            $original_value = $mod->option_value;
            $new_value = $this->recursive_unserialize_replace($search_pattern, $replacement, $original_value, false, false);
            
            if ($new_value !== $original_value) {
                $result = $wpdb->update(
                    $wpdb->options,
                    array('option_value' => $new_value),
                    array('option_id' => $mod->option_id),
                    array('%s'),
                    array('%d')
                );
                
                if ($result !== false) {
                    $replaced_count++;
                }
            }
        }
    }
    
    /**
     * Replace URLs in postmeta - Serialization-safe version for ACF Link fields
     * Optimized to prevent timeouts with batch processing
     */
    /**
     * Enhanced postmeta replacement inspired by Better Search Replace
     * Handles serialized data properly with recursive unserialize replace and batch processing
     */
    private function replace_in_postmeta_safe($search_pattern, $replacement, &$replaced_count) {
        global $wpdb;
        
        // Debug logs removed
        
        // Process in batches to prevent timeouts - inspired by BSR
        $batch_size = 50;
        $offset = 0;
        $total_processed = 0;
        
        do {
            // Get batch of postmeta entries that contain the search pattern (excluding plugin data)
        $meta_entries = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} 
             WHERE meta_value LIKE %s 
             AND meta_key NOT LIKE 'vercel_wp_preview_%'
                 LIMIT %d OFFSET %d",
                '%' . $wpdb->esc_like($search_pattern) . '%',
                $batch_size,
                $offset
            ));
            
            if (empty($meta_entries)) {
                break;
            }
            
            // Debug logs removed
        
        foreach ($meta_entries as $meta) {
            $original_value = $meta->meta_value;
            
            // Use enhanced recursive replacement inspired by Better Search Replace
                $new_value = $this->recursive_unserialize_replace($search_pattern, $replacement, $original_value, false, false);
            
            if ($new_value !== $original_value) {
                $result = $wpdb->update(
                    $wpdb->postmeta,
                    array('meta_value' => $new_value),
                    array('meta_id' => $meta->meta_id),
                    array('%s'),
                    array('%d')
                );
                
                if ($result !== false) {
                    $replaced_count++;
                    // Debug logs removed
                }
            }
                
                $total_processed++;
            }
            
            $offset += $batch_size;
            
            // Prevent infinite loops
            if ($total_processed > 1000) {
                // Debug logs removed
                break;
            }
            
        } while (count($meta_entries) === $batch_size);
        
        // Debug logs removed
    }
    
    /**
     * Enhanced options replacement inspired by Better Search Replace
     * Handles serialized data properly with recursive unserialize replace and batch processing
     */
    private function replace_in_options_safe($search_pattern, $replacement, &$replaced_count) {
        global $wpdb;
        
        // Debug logs removed
        
        // Process in batches to prevent timeouts - inspired by BSR
        $batch_size = 50;
        $offset = 0;
        $total_processed = 0;
        
        do {
            // Get batch of options that contain the search pattern (excluding plugin data and critical options)
            $option_entries = $wpdb->get_results($wpdb->prepare(
                "SELECT option_id, option_name, option_value FROM {$wpdb->options} 
                 WHERE option_value LIKE %s 
                 AND option_name NOT LIKE 'vercel_wp_preview_%'
                 AND option_name NOT IN ('siteurl', 'home', 'admin_email', '_transient_bsr_results', 'bsr_profiles', 'bsr_update_site_url', 'bsr_data')
                 LIMIT %d OFFSET %d",
                '%' . $wpdb->esc_like($search_pattern) . '%',
                $batch_size,
                $offset
            ));
            
            if (empty($option_entries)) {
                break;
            }
            
            // Debug logs removed
            
            foreach ($option_entries as $option) {
                $original_value = $option->option_value;
                
                // Use enhanced recursive replacement inspired by Better Search Replace
                $new_value = $this->recursive_unserialize_replace($search_pattern, $replacement, $original_value, false, false);
                
                if ($new_value !== $original_value) {
                    $result = $wpdb->update(
                        $wpdb->options,
                        array('option_value' => $new_value),
                        array('option_id' => $option->option_id),
                        array('%s'),
                        array('%d')
                    );
                    
                    if ($result !== false) {
                        $replaced_count++;
                        // Debug logs removed
                    }
                }
                
                $total_processed++;
            }
            
            $offset += $batch_size;
            
            // Prevent infinite loops
            if ($total_processed > 1000) {
                // Debug logs removed
                break;
            }
            
        } while (count($option_entries) === $batch_size);
        
        // Debug logs removed
    }
    
    /**
     * Enhanced recursive unserialize replace inspired by Better Search Replace
     * Handles serialized arrays and objects properly with improved ACF support
     */
    private function recursive_unserialize_replace($from = '', $to = '', $data = '', $serialised = false, $case_insensitive = false) {
        try {
            // Exit early if $data is a string but has no search matches
            if (is_string($data)) {
                $has_match = $case_insensitive ? false !== stripos($data, $from) : false !== strpos($data, $from);
                if (!$has_match) {
                    return $data;
                }
            }
            
            // Handle serialized data - improved detection logic from BSR
            if (is_string($data) && !is_serialized_string($data) && ($unserialized = $this->unserialize($data)) !== false) {
                $data = $this->recursive_unserialize_replace($from, $to, $unserialized, true, $case_insensitive);
            }
            
            // Handle arrays
            elseif (is_array($data)) {
                $_tmp = array();
                foreach ($data as $key => $value) {
                    $_tmp[$key] = $this->recursive_unserialize_replace($from, $to, $value, false, $case_insensitive);
                }
                $data = $_tmp;
                unset($_tmp);
            }
            
            // Handle objects - improved from BSR
            elseif ('object' == gettype($data)) {
                if ($this->is_object_cloneable($data)) {
                    $_tmp = clone $data;
                    $props = get_object_vars($data);
                    foreach ($props as $key => $value) {
                        // Skip integer properties
                        if (is_int($key)) {
                            continue;
                        }
                        
                        // Skip protected properties
                        if (is_string($key) && 1 === preg_match("/^(\\\\0).+/im", preg_quote($key))) {
                            continue;
                        }
                        
                        $_tmp->$key = $this->recursive_unserialize_replace($from, $to, $value, false, $case_insensitive);
                    }
                    $data = $_tmp;
                    unset($_tmp);
                }
            }
            
            // Handle serialized strings
            elseif (is_serialized_string($data)) {
                $unserialized = $this->unserialize($data);
                if ($unserialized !== false) {
                    $data = $this->recursive_unserialize_replace($from, $to, $unserialized, true, $case_insensitive);
                }
            }
            
            // Handle regular strings
            else {
                if (is_string($data)) {
                    $data = $this->str_replace($from, $to, $data, $case_insensitive);
                }
            }
            
            if ($serialised) {
                return serialize($data);
            }
            
        } catch (Exception $error) {
            // Log error but continue
            // Debug logs removed
        }
        
        return $data;
    }
    
    /**
     * Return unserialized object or array - Enhanced from BSR
     */
    private function unserialize($serialized_string) {
        if (!is_serialized($serialized_string)) {
            return false;
        }
        
        $serialized_string = trim($serialized_string);
        
        if (PHP_VERSION_ID >= 70000) {
            $unserialized_string = @unserialize($serialized_string, array('allowed_classes' => false));
        } else {
            // Fallback for older PHP versions
            $unserialized_string = @unserialize($serialized_string);
        }
        
        return $unserialized_string;
    }
    
    /**
     * Wrapper for str_replace with case insensitive support - from BSR
     */
    private function str_replace($from, $to, $data, $case_insensitive = false) {
        if ($case_insensitive) {
            $data = str_ireplace($from, $to, $data);
        } else {
            $data = str_replace($from, $to, $data);
        }
        
        return $data;
    }
    
    /**
     * Check if a given object can be cloned
     */
    private function is_object_cloneable($object) {
        return (new \ReflectionClass(get_class($object)))->isCloneable();
    }
    
    /**
     * Clear all ACF caches (optimized for speed)
     */
    private function clear_all_acf_caches() {
        // WordPress caches
        wp_cache_flush();
        
        // ACF caches
        if (function_exists('acf_get_store')) {
            $fields_store = acf_get_store('fields');
            if ($fields_store) {
                $fields_store->reset();
            }
            $values_store = acf_get_store('values');
            if ($values_store) {
                $values_store->reset();
            }
        }
        
        // ACF transients (limited to prevent timeout)
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acf_%' LIMIT 50");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_acf_%' LIMIT 50");
    }
    
    /**
     * Clear post-specific ACF caches
     */
    private function clear_post_acf_caches($post_id) {
        wp_cache_delete($post_id, 'posts');
        wp_cache_delete($post_id, 'post_meta');
        
        if (function_exists('acf_get_store')) {
            $values_store = acf_get_store('values');
            if ($values_store) {
                $values_store->remove($post_id);
            }
        }
        
        delete_transient('acf_post_' . $post_id);
    }
    
    /**
     * Clear ACF caches after replacement - Enhanced approach inspired by BSR
     */
    private function clear_acf_caches_after_replacement() {
        // Debug logs removed
        
        // Clear WordPress object cache
        wp_cache_flush();
        
        // Clear ACF stores if available
        if (function_exists('acf_get_store')) {
            $stores = array('fields', 'values', 'groups', 'local-fields', 'local-groups');
            foreach ($stores as $store_name) {
                $store = acf_get_store($store_name);
                if ($store) {
                    $store->reset();
                    // Debug logs removed
                }
            }
        }
        
        // Clear ACF transients in batches to prevent timeout
        global $wpdb;
        $transient_patterns = array(
            '_transient_acf_%',
            '_transient_timeout_acf_%',
            '_transient_acf_field_%',
            '_transient_timeout_acf_field_%'
        );
        
        foreach ($transient_patterns as $pattern) {
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 100",
                $pattern
            ));
            if ($result > 0) {
                // Debug logs removed
            }
        }
        
        // Clear any ACF-related meta caches
        // Note: wp_cache_delete_group() doesn't exist in WordPress core
        // Using wp_cache_flush() as fallback
        wp_cache_flush();
        
        // Debug logs removed
    }
}

// Note: Preview module initialization is now handled in includes/deploy/class-deploy-plugin.php
// VercelWP_Preview_Manager::get_instance(); will be called there
