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
        add_filter('preview_post_link', array($this, 'filter_native_preview_link'), 20, 2);
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
        add_action('wp_ajax_vercel_wp_preview_prepare_session', array($this, 'ajax_prepare_preview_session'));
        add_action('wp_ajax_vercel_wp_preview_get_acf_debug', array($this, 'ajax_get_acf_debug'));
        add_action('wp_ajax_vercel_wp_preview_clear_acf_debug', array($this, 'ajax_clear_acf_debug'));
        add_action('wp_ajax_vercel_wp_preview_inspect_acf_field', array($this, 'ajax_inspect_acf_field'));
        add_action('wp_ajax_vercel_wp_preview_test_permalinks', array($this, 'ajax_test_permalinks'));
        add_action('wp_ajax_vercel_wp_preview_clear_permalink_cache', array($this, 'ajax_clear_permalink_cache'));
        add_action('rest_api_init', array($this, 'register_preview_rest_routes'));
        add_filter('theme_page_templates', array($this, 'register_custom_page_templates'), 20, 4);
        add_filter('template_include', array($this, 'use_custom_page_template'), 20);
        
        
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
        
        // Register admin menu removal hook early (before init)
        // This ensures it runs before WordPress renders the menu
        add_action('admin_menu', array($this, 'remove_themes_menu_item'), 1);
        add_action('admin_menu', array($this, 'add_headless_menus_shortcut'), 80);
        add_action('admin_init', array($this, 'ensure_headless_theme_supports'), 0);
        add_action('admin_init', array($this, 'redirect_themes_page'), 1);
        add_action('load-themes.php', array($this, 'block_themes_page_access'));
        add_action('admin_head', array($this, 'hide_appearance_menu_css'));
        add_action('after_setup_theme', array($this, 'ensure_headless_theme_supports'), 20);
        
        // Admin bar URL fix - add hooks directly in constructor
        add_action('admin_footer', array($this, 'fix_admin_bar_urls_js'), 999);
        add_action('wp_footer', array($this, 'fix_admin_bar_urls_js'), 999);
        
        // Redirect to Production URL or Preview URL
        add_action('template_redirect', array($this, 'redirect_to_frontend_url'));
        
        // Note: activation/deactivation hooks are now in vercel-wp.php
    }
    
    public function init() {
        vercel_wp_load_textdomain();
        
        // Update existing settings to include new options
        $this->update_existing_settings();
    }

    /**
     * Shared AJAX capability check helper.
     */
    private function ensure_ajax_capability($capability) {
        if (!current_user_can($capability)) {
            wp_send_json_error(array('message' => __('Permission refusée', 'vercel-wp')));
        }
    }

    /**
     * Return a sanitized POST value.
     */
    private function get_post_value($key) {
        if (!isset($_POST[$key])) {
            return '';
        }

        return sanitize_text_field(wp_unslash($_POST[$key]));
    }

    /**
     * Validate target URL for remote checks (HTTPS + public host only).
     */
    private function is_safe_remote_url($url) {
        $url = esc_url_raw($url);
        if (empty($url) || !wp_http_validate_url($url)) {
            return false;
        }

        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        $host = strtolower($parts['host']);
        if (in_array($host, array('localhost', '127.0.0.1', '::1'), true)) {
            return false;
        }

        if (preg_match('/(\.local|\.internal)$/', $host)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && !$this->is_public_ip($host)) {
            return false;
        }

        $resolved_ips = gethostbynamel($host);
        if ($resolved_ips !== false) {
            foreach ($resolved_ips as $ip) {
                if (!$this->is_public_ip($ip)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Return true for non-private/non-reserved IPs.
     */
    private function is_public_ip($ip) {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Return sanitized custom templates registered from plugin options.
     */
    private function get_custom_page_templates() {
        $templates = get_option('vercel_wp_custom_page_templates', array());
        if (!is_array($templates)) {
            return array();
        }

        $normalized = array();

        foreach ($templates as $template_file => $template_data) {
            $template_file = sanitize_file_name((string) $template_file);
            if (empty($template_file)) {
                continue;
            }

            if (!is_array($template_data)) {
                $template_data = array();
            }

            $name = isset($template_data['name']) ? sanitize_text_field($template_data['name']) : '';
            if ($name === '') {
                $name = ucfirst(trim(str_replace(array('vercel-wp-template-', 'template-', '.php', '-', '_'), array('', '', '', ' ', ' '), $template_file)));
            }

            $slug = isset($template_data['slug']) ? sanitize_title($template_data['slug']) : '';
            if ($slug === '') {
                $slug = sanitize_title(str_replace(array('vercel-wp-template-', 'template-', '.php'), '', $template_file));
            }

            $description = isset($template_data['description']) ? sanitize_text_field($template_data['description']) : '';

            $normalized[$template_file] = array(
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
            );
        }

        return $normalized;
    }

    /**
     * Expose plugin templates in the WordPress "Page Attributes > Template" selector.
     */
    public function register_custom_page_templates($page_templates, $wp_theme = null, $post = null, $post_type = 'page') {
        if ($post_type !== 'page') {
            return $page_templates;
        }

        $custom_templates = $this->get_custom_page_templates();
        if (empty($custom_templates)) {
            return $page_templates;
        }

        foreach ($custom_templates as $template_file => $template_data) {
            $page_templates[$template_file] = $template_data['name'];
        }

        return $page_templates;
    }

    /**
     * Route custom template slugs to a plugin renderer.
     */
    public function use_custom_page_template($template) {
        if (!is_singular('page')) {
            return $template;
        }

        $page_id = get_queried_object_id();
        if (empty($page_id)) {
            return $template;
        }

        $selected_template = get_page_template_slug($page_id);
        if (empty($selected_template) || $selected_template === 'default') {
            return $template;
        }

        $custom_templates = $this->get_custom_page_templates();
        if (!isset($custom_templates[$selected_template])) {
            return $template;
        }

        $renderer = VERCEL_WP_PLUGIN_DIR . 'includes/preview/template-custom-page.php';
        if (!file_exists($renderer)) {
            return $template;
        }

        return $renderer;
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

        // Don't redirect technical endpoints.
        if (is_feed() || is_trackback() || is_robots() || (function_exists('is_favicon') && is_favicon())) {
            return;
        }
        
        // Get settings
        $settings = get_option('vercel_wp_preview_settings', array(
            'vercel_preview_url' => '',
            'production_url' => '',
        ));
        
        $production_url = !empty($settings['production_url']) ? rtrim($settings['production_url'], '/') : '';
        $preview_url = !empty($settings['vercel_preview_url']) ? rtrim($settings['vercel_preview_url'], '/') : '';
        $framework = $this->get_preview_framework($settings);
        
        // Determine redirect URL
        $redirect_url = '';
        
        if (!empty($production_url)) {
            // Redirect to Production URL if it's set
            $redirect_url = $production_url;
        } elseif ($framework !== 'draft_revalidate' && !empty($preview_url)) {
            // Otherwise redirect to Preview URL if it's set
            $redirect_url = $preview_url;
        }
        
        // Only redirect if we have a URL and we're not already on that domain
        if (!empty($redirect_url)) {
            // Get current request URI safely
            $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
            
            // Get current host
            $current_host = isset($_SERVER['HTTP_HOST']) ? wp_unslash($_SERVER['HTTP_HOST']) : parse_url(home_url(), PHP_URL_HOST);
            $current_host = strtolower(preg_replace('/:\d+$/', '', $current_host));
            $redirect_host = strtolower((string) parse_url($redirect_url, PHP_URL_HOST));
            
            // Only redirect if we're not already on the target domain
            if ($current_host !== $redirect_host) {
                // Preserve the path and query string
                $full_redirect_url = rtrim($redirect_url, '/') . $request_uri;
                $full_redirect_url = wp_sanitize_redirect($full_redirect_url);

                if (!wp_http_validate_url($full_redirect_url)) {
                    return;
                }
                
                // Perform redirect with 301 (permanent redirect)
                wp_redirect($full_redirect_url, 301, 'Vercel-WP');
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
            'framework_mode' => 'static',
            'vercel_preview_url' => '',
            'production_url' => '',
            'nextjs_draft_url' => '',
            'nextjs_revalidate_url' => '',
            'nextjs_draft_param' => 'slug',
            'nextjs_revalidate_param' => 'path',
            'draft_revalidate_secret' => '',
            'draft_revalidate_secret_param' => 'secret',
            'cache_duration' => 300, // 5 minutes
            'auto_refresh' => true,
            'show_button_admin_bar' => true,
            'show_deploy_button_admin_bar' => true,
            'show_button_editor' => true,
            'disable_theme_page' => true,
            'headless_show_menus_menu' => true
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
        $is_plugin_page = strpos($hook, 'vercel-wp') !== false;
        $is_editor_page = ('post.php' === $hook || 'post-new.php' === $hook);
        $settings = get_option('vercel_wp_preview_settings', array());
        $use_native_preview_button = $this->use_native_preview_button($settings);

        if ($is_plugin_page) {
            wp_enqueue_style('vercel-wp-preview-admin', VERCEL_WP_PLUGIN_URL . 'assets/css/preview-admin.css', array(), VERCEL_WP_VERSION);
        }

        if ($is_editor_page && !empty($settings['show_button_editor']) && !$use_native_preview_button) {
            $preview_interface_css = VERCEL_WP_PLUGIN_DIR . 'assets/css/preview-interface.css';
            $preview_interface_js = VERCEL_WP_PLUGIN_DIR . 'assets/js/preview-interface.js';
            $preview_interface_css_ver = file_exists($preview_interface_css) ? filemtime($preview_interface_css) : VERCEL_WP_VERSION;
            $preview_interface_js_ver = file_exists($preview_interface_js) ? filemtime($preview_interface_js) : VERCEL_WP_VERSION;

            wp_enqueue_style('vercel-wp-preview-interface', VERCEL_WP_PLUGIN_URL . 'assets/css/preview-interface.css', array(), $preview_interface_css_ver);
            wp_enqueue_script('vercel-wp-preview-interface', VERCEL_WP_PLUGIN_URL . 'assets/js/preview-interface.js', array('jquery'), $preview_interface_js_ver, true);

            wp_localize_script('vercel-wp-preview-interface', 'headlessPreview', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('vercel_wp_preview_nonce'),
                'framework' => $this->get_preview_framework($settings),
                'previewSessionParam' => 'wp_preview_session',
                'previewSessionEndpointParam' => 'wp_preview_endpoint',
                'previewSessionPostIdParam' => 'wp_preview_post_id',
                'autoRefresh' => !empty($settings['auto_refresh']),
                'autoRafraîchir' => !empty($settings['auto_refresh']),
                'refreshInterval' => max(5000, intval($settings['cache_duration'] ?? 300) * 1000),
                'strings' => array(
                    'syncing' => __('Synchronisation des modifications…', 'vercel-wp'),
                    'loadingPreview' => __('Chargement de la prévisualisation…', 'vercel-wp'),
                    'previewUpdatedAt' => __('Prévisualisation mise à jour à', 'vercel-wp'),
                    'previewReady' => __('Prévisualisation prête', 'vercel-wp'),
                    'previewError' => __('Prévisualisation indisponible', 'vercel-wp'),
                    'copyUrl' => __('Copier l’URL', 'vercel-wp'),
                    'urlCopied' => __('URL copiée dans le presse-papiers', 'vercel-wp'),
                    'copyFailed' => __('Impossible de copier l’URL', 'vercel-wp'),
                    'clearingCache' => __('Vidage du cache…', 'vercel-wp'),
                    'cacheCleared' => __('Cache vidé avec succès', 'vercel-wp'),
                    'cacheClearFailed' => __('Impossible de vider le cache', 'vercel-wp'),
                    'previewSessionError' => __('Session de prévisualisation indisponible. Ouverture de l’aperçu standard.', 'vercel-wp'),
                ),
            ));
        }

        if ($is_editor_page && !empty($settings['show_button_editor']) && $use_native_preview_button) {
            wp_enqueue_script('vercel-wp-preview-native', VERCEL_WP_PLUGIN_URL . 'assets/js/preview-native.js', array('jquery'), VERCEL_WP_VERSION, true);

            wp_localize_script('vercel-wp-preview-native', 'headlessPreviewNative', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('vercel_wp_preview_nonce'),
                'previewSessionParam' => 'wp_preview_session',
                'previewSessionEndpointParam' => 'wp_preview_endpoint',
                'previewSessionPostIdParam' => 'wp_preview_post_id',
            ));
        }
    }
    
    public function enqueue_frontend_scripts() {
        $settings = get_option('vercel_wp_preview_settings', array());
        $show_admin_bar_button = !empty($settings['show_button_admin_bar']);
        $use_native_preview_button = $this->use_native_preview_button($settings);

        if (is_admin_bar_showing() && $show_admin_bar_button && !$use_native_preview_button && $this->has_preview_target($settings) && current_user_can('edit_posts')) {
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

        if ($this->use_native_preview_button($settings)) {
            return;
        }

        if (!$this->has_preview_target($settings)) {
            return;
        }
        
        $current_url = $this->get_current_page_url();
        if (!$current_url) {
            return;
        }
        
        $preview_url = $this->get_preview_url($current_url);
        
        $wp_admin_bar->add_node(array(
            'id' => 'vercel-wp',
            'title' => '<span class="ab-icon"></span>' . __('Prévisualiser', 'vercel-wp'),
            'href' => $preview_url,
            'meta' => array(
                'target' => '_blank',
                'title' => __('Ouvrir la prévisualisation', 'vercel-wp')
            )
        ));
    }

    /**
     * Rewrite native WordPress preview button URL to framework Draft endpoint.
     */
    public function filter_native_preview_link($preview_link, $post) {
        if (!$post instanceof WP_Post) {
            return $preview_link;
        }

        $settings = get_option('vercel_wp_preview_settings', array());
        if (!$this->use_native_preview_button($settings)) {
            return $preview_link;
        }

        if (!$this->has_preview_target($settings)) {
            return $preview_link;
        }

        if (empty($settings['nextjs_draft_url'])) {
            return $preview_link;
        }

        $wordpress_url = get_permalink($post);
        if (empty($wordpress_url)) {
            $wordpress_url = $preview_link;
        }

        if (empty($wordpress_url)) {
            return $preview_link;
        }

        $mapped_preview = $this->get_preview_url($wordpress_url);
        if (empty($mapped_preview)) {
            return $preview_link;
        }

        return $mapped_preview;
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

        if ($this->use_native_preview_button($settings)) {
            return;
        }

        if (!$this->has_preview_target($settings)) {
            return;
        }
        
        $preview_url = $this->get_preview_url($current_url);
        
        echo '<div class="headless-preview-buttons-container" style="margin-top: 10px; margin-bottom: 15px; background: #fff; border: 1px solid #e1e1e1; border-radius: 4px; padding: 10px; width: 100%;">';
        echo '<h3 style="margin: 0 0 10px 0; color: #333; font-size: 13px; font-weight: 500;">' . __('Prévisualiser', 'vercel-wp') . '</h3>';
        echo '<div class="headless-preview-buttons" style="display: flex; gap: 8px; align-items: center;">';
        
        // Preview button (simple style)
        echo '<button type="button" class="button button-secondary headless-preview-toggle" data-url="' . esc_url($preview_url) . '" style="font-size: 13px; display: flex; align-items: center;">';
        echo __('Prévisualiser', 'vercel-wp');
        echo '</button>';
        
        // Vider le cache button (simple style)
        echo '<button type="button" class="button button-secondary headless-preview-clear-cache" data-url="' . esc_url($current_url) . '" style="font-size: 13px; display: flex; align-items: center;">';
        echo '<span class="dashicons dashicons-update" style="font-size: 14px;"></span> ' . __('Vider le cache', 'vercel-wp');
        echo '</button>';
        
        echo '</div>';
        echo '</div>';
        
        // Enhanced preview interface
        echo '<div class="headless-preview-container" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 999999; backdrop-filter: blur(5px);">';
        
        // Simple header
        echo '<div class="headless-preview-header" style="background: #fff; color: #333; padding: 4px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e1e1e1;">';
        echo '<div class="headless-preview-header-left" style="display: flex; align-items: center;">';
        echo '<div>';
        echo '<h1 style="margin: 0; font-size: 18px; font-weight: 500; color: #333;">' . get_the_title($post->ID) . '</h1>';
        echo '<p class="headless-preview-deploy-hint" style="margin: 4px 0 0; font-size: 12px; color: #646970;">' . __('Après vos modifications, pensez à Déployer le site pour la mise en production.', 'vercel-wp') . '</p>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="headless-preview-header-right" style="display: flex; align-items: center; gap: 10px;">';
        
        // Simple URL bar
        echo '<div class="headless-preview-url-bar" style="display: flex; align-items: center; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; padding: 6px 10px; margin-right: 10px;">';
        echo '<input type="text" value="' . esc_url($preview_url) . '" readonly style="background: transparent; border: none; color: #333; font-size: 13px; width: 250px; outline: none;">';
        echo '</div>';
        
        // Simple action buttons
        echo '<div class="headless-preview-controls" style="display: flex; gap: 5px;">';
        echo '<button type="button" class="button headless-preview-refresh-iframe" style="padding: 6px 10px; font-size: 12px;" title="' . __('Rafraîchir', 'vercel-wp') . '">';
        echo '<span class="dashicons dashicons-update"></span>';
        echo '</button>';
        echo '<button type="button" class="button headless-preview-open-new-tab" style="padding: 6px 10px; font-size: 12px;" title="' . __('Nouvel onglet', 'vercel-wp') . '">';
        echo '<span class="dashicons dashicons-external"></span>';
        echo '</button>';
        echo '<button type="button" class="button headless-preview-close" style="padding: 6px 10px; font-size: 12px; background: #dc3545; border-color: #dc3545; color: white;" title="' . __('Fermer', 'vercel-wp') . '">';
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
        echo '<button type="button" class="device-btn active" data-device="desktop" style="padding: 4px 8px; border: 1px solid #0073aa; background: #0073aa; color: white; border-radius: 3px; cursor: pointer; font-size: 11px;">' . __('Bureau', 'vercel-wp') . '</button>';
        echo '<button type="button" class="device-btn" data-device="tablet" style="padding: 4px 8px; border: 1px solid #ddd; background: white; color: #666; border-radius: 3px; cursor: pointer; font-size: 11px;">' . __('Tablette', 'vercel-wp') . '</button>';
        echo '<button type="button" class="device-btn" data-device="mobile" style="padding: 4px 8px; border: 1px solid #ddd; background: white; color: #666; border-radius: 3px; cursor: pointer; font-size: 11px;">' . __('Mobile', 'vercel-wp') . '</button>';
        echo '</div>';
        echo '<div class="headless-preview-status-container" style="display: flex; align-items: center; gap: 8px;">';
        echo '<div class="headless-preview-status" style="width: 8px; height: 8px; border-radius: 50%; background: #28a745;"></div>';
        echo '<span class="headless-preview-status-label" style="font-size: 11px; color: #666;">' . __('Prévisualisation prête', 'vercel-wp') . '</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        // Iframe area with enhanced error handling
        echo '<div class="headless-preview-iframe-container" style="height: calc(100% - 60px); position: relative; background: white;">';
        
        // Simple loading message
        echo '<div class="headless-preview-loading" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; z-index: 10;">';
        echo '<div style="width: 40px; height: 40px; border: 3px solid #f3f3f3; border-top: 3px solid #0073aa; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 15px;"></div>';
        echo '<p style="color: #666; margin: 0; font-size: 14px;">' . __('Chargement...', 'vercel-wp') . '</p>';
        echo '</div>';
        
        // Simple error message
        echo '<div class="headless-preview-fallback" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; background: white; padding: 30px; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-width: 350px;">';
        echo '<div style="width: 40px; height: 40px; background: #dc3545; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">';
        echo '<span class="dashicons dashicons-warning" style="color: white; font-size: 18px;"></span>';
        echo '</div>';
        echo '<h4 style="color: #dc3545; margin: 0 0 10px 0; font-size: 16px;">' . __('Prévisualisation bloquée', 'vercel-wp') . '</h4>';
        echo '<p style="color: #666; margin: 0 0 15px 0; font-size: 13px; line-height: 1.4;">' . __('Votre navigateur bloque l\'affichage. Cliquez ci-dessous pour ouvrir dans un nouvel onglet.', 'vercel-wp') . '</p>';
        echo '<button type="button" class="button button-primary headless-preview-open-new-tab-fallback" style="padding: 8px 16px; font-size: 13px;">';
        echo '<span class="dashicons dashicons-external" style="margin-right: 6px;"></span> ' . __('Ouvrir dans un nouvel onglet', 'vercel-wp');
        echo '</button>';
        echo '</div>';
        
        // Preview iframe
        echo '<iframe id="headless-preview-iframe" src="" width="100%" height="100%" frameborder="0" style="border: none; background: white;"></iframe>';
        echo '</div>';
        echo '</div>';
        
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

        if ($this->use_native_preview_button($settings)) {
            return;
        }

        if (!$this->has_preview_target($settings)) {
            return;
        }
        
        $preview_url = $this->get_preview_url($current_url);
        
        // Native WordPress style for Publish section with misc-pub-section classes
        echo '<div class="misc-pub-section headless-preview-section" style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">';
        echo '<label style="font-weight: 600; color: #23282d; margin-bottom: 8px; display: block;">' . __('Prévisualiser', 'vercel-wp') . '</label>';
        
        // Button container with WordPress style
        echo '<div class="headless-preview-buttons" style="display: flex; gap: 6px; margin-top: 8px;">';
        
        // Preview button with WordPress style
        echo '<button type="button" class="button button-secondary headless-preview-toggle" data-url="' . esc_url($preview_url) . '" style="font-size: 12px; height: 28px; line-height: 26px; padding: 0 8px; display: inline-flex; align-items: center; gap: 4px;">';
        echo __('Prévisualiser', 'vercel-wp');
        echo '</button>';
        
        // Vider le cache button with WordPress style
        echo '<button type="button" class="button button-secondary headless-preview-clear-cache" data-url="' . esc_url($current_url) . '" style="font-size: 12px; height: 28px; line-height: 26px; padding: 0 8px; display: inline-flex; align-items: center; gap: 4px;">';
        echo '<span class="dashicons dashicons-update" style="font-size: 14px; width: 14px; height: 14px;"></span>';
        echo __('Vider le cache', 'vercel-wp');
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
        echo '<p class="headless-preview-deploy-hint" style="margin: 4px 0 0; font-size: 12px; color: #646970;">' . __('Après vos modifications, pensez à Déployer le site pour la mise en production.', 'vercel-wp') . '</p>';
        echo '</div>';
        
        // URL bar on the right
        echo '<div class="headless-preview-url-bar" style="display: flex; align-items: center; background: #f8f9fa; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 12px; margin-right: 12px; flex: 1; max-width: 400px;">';
        echo '<input type="text" value="' . esc_url($preview_url) . '" readonly style="background: transparent; border: none; color: #374151; font-size: 13px; width: 100%; outline: none; font-family: \'SF Mono\', Monaco, \'Cascadia Code\', \'Roboto Mono\', Consolas, \'Courier New\', monospace;">';
        echo '</div>';
        
        // Action buttons with Sanity style
        echo '<div class="headless-preview-controls" style="display: flex; gap: 6px;">';
        echo '<button type="button" class="button button-secondary headless-preview-refresh-iframe" style="padding: 8px; font-size: 14px; height: 36px; width: 36px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; color: #374151; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease;" title="' . __('Rafraîchir', 'vercel-wp') . '">';
        echo '<span class="dashicons dashicons-update" style="font-size: 16px; font-weight: bold;"></span>';
        echo '</button>';
        echo '<button type="button" class="button button-secondary headless-preview-open-new-tab" style="padding: 8px; font-size: 14px; height: 36px; width: 36px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; color: #374151; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease;" title="' . __('Nouvel onglet', 'vercel-wp') . '">';
        echo '<span class="dashicons dashicons-external" style="font-size: 16px; font-weight: bold;"></span>';
        echo '</button>';
        echo '<button type="button" class="button button-secondary headless-preview-close" style="padding: 8px; font-size: 14px; height: 36px; width: 36px; border-radius: 6px; border: 1px solid #dc2626; background: #dc2626; color: white; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease;" title="' . __('Fermer', 'vercel-wp') . '">';
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
        echo '<button type="button" class="device-btn active" data-device="desktop" style="padding: 6px 12px; border: 1px solid #2271b1; background: #2271b1; color: white; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 500; transition: all 0.2s ease;">' . __('Bureau', 'vercel-wp') . '</button>';
        echo '<button type="button" class="device-btn" data-device="tablet" style="padding: 6px 12px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 500; transition: all 0.2s ease;">' . __('Tablette', 'vercel-wp') . '</button>';
        echo '<button type="button" class="device-btn" data-device="mobile" style="padding: 6px 12px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 500; transition: all 0.2s ease;">' . __('Mobile', 'vercel-wp') . '</button>';
        echo '</div>';
        
        // Connection status with Sanity style
        echo '<div class="headless-preview-status-container" style="display: flex; align-items: center; gap: 8px;">';
        echo '<div class="headless-preview-status" style="width: 8px; height: 8px; border-radius: 50%; background: #10b981;"></div>';
        echo '<span class="headless-preview-status-label" style="font-size: 13px; color: #374151; font-weight: 500;">' . __('Prévisualisation prête', 'vercel-wp') . '</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        // Iframe area
        echo '<div class="headless-preview-iframe-container" style="height: calc(100% - 50px); position: relative; background: white;">';
        
        // Loading message with WordPress style
        echo '<div class="headless-preview-loading" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; z-index: 10;">';
        echo '<div style="width: 32px; height: 32px; border: 3px solid #f0f0f1; border-top: 3px solid #2271b1; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 12px;"></div>';
        echo '<p style="color: #1d2327; margin: 0; font-size: 14px; font-weight: 500;">' . __('Chargement...', 'vercel-wp') . '</p>';
        echo '</div>';
        
        // Error message with WordPress style
        echo '<div class="headless-preview-fallback" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; background: white; padding: 24px; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); max-width: 320px; border: 1px solid #c3c4c7;">';
        echo '<div style="width: 32px; height: 32px; background: #d63638; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px;">';
        echo '<span class="dashicons dashicons-warning" style="color: white; font-size: 16px;"></span>';
        echo '</div>';
        echo '<h3 style="margin: 0 0 8px 0; color: #1d2327; font-size: 16px; font-weight: 600;">' . __('Impossible de charger l\'aperçu', 'vercel-wp') . '</h3>';
        echo '<p style="margin: 0 0 16px 0; color: #646970; font-size: 14px; line-height: 1.4;">' . __('L\'aperçu ne peut pas être chargé. Vérifiez votre configuration.', 'vercel-wp') . '</p>';
        echo '<div style="display: flex; gap: 8px; justify-content: center;">';
        echo '<button type="button" class="button button-primary headless-preview-retry" style="font-size: 12px; height: 28px; line-height: 26px; padding: 0 12px;">' . __('Réessayer', 'vercel-wp') . '</button>';
        echo '<button type="button" class="button button-secondary headless-preview-open-external" style="font-size: 12px; height: 28px; line-height: 26px; padding: 0 12px;">' . __('Ouvrir dans un nouvel onglet', 'vercel-wp') . '</button>';
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
        global $pagenow;
        if (!in_array($pagenow, array('post.php', 'post-new.php'), true)) {
            return;
        }

        $settings = get_option('vercel_wp_preview_settings', array());
        $show_custom_editor_button = isset($settings['show_button_editor']) && $settings['show_button_editor'];

        if ($show_custom_editor_button && !$this->use_native_preview_button($settings)) {
            // Hide default WordPress preview button
            echo '<style>#preview-action { display: none !important; }</style>';
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
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission refusée', 'vercel-wp'));
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'vercel_wp_preview_settings')) {
            wp_die(__('Erreur de sécurité', 'vercel-wp'));
        }
        
        $old_settings = get_option('vercel_wp_preview_settings', array());
        $old_production_url = isset($old_settings['production_url']) ? $old_settings['production_url'] : '';
        
        // Preserve existing settings and only update submitted ones
        $settings = $old_settings; // Start with existing settings
        
                // Update only the submitted fields
                if (isset($_POST['vercel_preview_url'])) {
                    $settings['vercel_preview_url'] = rtrim(esc_url_raw($this->get_post_value('vercel_preview_url')), '/');
                }
                if (isset($_POST['production_url'])) {
                    $settings['production_url'] = rtrim(esc_url_raw($this->get_post_value('production_url')), '/');
                }
        if (isset($_POST['cache_duration'])) {
            $settings['cache_duration'] = intval($_POST['cache_duration']);
        }
        
        // Checkboxes
        $settings['auto_refresh'] = isset($_POST['auto_refresh']);
        $settings['show_button_admin_bar'] = isset($_POST['show_button_admin_bar']);
        $settings['show_deploy_button_admin_bar'] = !isset($_POST['show_deploy_button_admin_bar']);
        $settings['show_button_editor'] = isset($_POST['show_button_editor']);
        $settings['disable_theme_page'] = isset($_POST['disable_theme_page']);
        $settings['headless_show_menus_menu'] = isset($_POST['headless_show_menus_menu']);
        
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
            echo '<div class="notice notice-success"><p>' . __('Réglages enregistrés', 'vercel-wp') . '</p></div>';
        }
    }
    
    public function ajax_get_preview_url() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        $this->ensure_ajax_capability('edit_posts');
        
        $url = esc_url_raw($this->get_post_value('url'));
        if (empty($url)) {
            wp_send_json_error(array('message' => __('L\'URL est requise', 'vercel-wp')));
        }
        $preview_url = $this->get_preview_url($url);
        
        wp_send_json_success(array('preview_url' => $preview_url));
    }

    /**
     * Register public REST route used by frontend draft pages to fetch preview session data.
     */
    public function register_preview_rest_routes() {
        register_rest_route('vercel-wp/v1', '/preview-session', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'rest_get_preview_session'),
            'permission_callback' => '__return_true',
            'args' => array(
                'token' => array(
                    'required' => true,
                    'type' => 'string',
                ),
                'post_id' => array(
                    'required' => false,
                    'type' => 'integer',
                ),
            ),
        ));
    }

    /**
     * REST callback: return stored preview session payload.
     */
    public function rest_get_preview_session($request) {
        $token = sanitize_text_field((string) $request->get_param('token'));
        if (empty($token) || !preg_match('/^[A-Za-z0-9]+$/', $token)) {
            return new WP_Error('vercel_wp_invalid_preview_token', __('Token de preview invalide', 'vercel-wp'), array('status' => 400));
        }

        $session = get_transient('vercel_wp_preview_session_' . $token);
        if (!is_array($session)) {
            return new WP_Error('vercel_wp_preview_session_not_found', __('Session de preview introuvable ou expirée', 'vercel-wp'), array('status' => 404));
        }

        $requested_post_id = absint($request->get_param('post_id'));
        if ($requested_post_id > 0 && (!isset($session['postId']) || $requested_post_id !== (int) $session['postId'])) {
            return new WP_Error('vercel_wp_preview_post_mismatch', __('Le post demandé ne correspond pas à la session', 'vercel-wp'), array('status' => 403));
        }

        return rest_ensure_response(array(
            'success' => true,
            'data' => $session,
        ));
    }

    /**
     * Store a short-lived preview session with current editor snapshot (Gutenberg + ACF form values).
     */
    public function ajax_prepare_preview_session() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        $this->ensure_ajax_capability('edit_posts');

        $post_id = absint($this->get_post_value('post_id'));
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('Post invalide ou permissions insuffisantes', 'vercel-wp')));
        }

        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            wp_send_json_error(array('message' => __('Post introuvable', 'vercel-wp')));
        }

        $snapshot_raw = isset($_POST['snapshot']) ? wp_unslash($_POST['snapshot']) : '';
        $snapshot = array();
        if (is_string($snapshot_raw) && $snapshot_raw !== '') {
            $decoded_snapshot = json_decode($snapshot_raw, true);
            if (is_array($decoded_snapshot)) {
                $snapshot = $decoded_snapshot;
            }
        }

        $form_data_raw = isset($_POST['form_data']) ? wp_unslash($_POST['form_data']) : '';
        $parsed_form = array();
        if (is_string($form_data_raw) && $form_data_raw !== '') {
            parse_str($form_data_raw, $parsed_form);
        }

        $title = isset($snapshot['title']) ? (string) $snapshot['title'] : '';
        if ($title === '' && isset($parsed_form['post_title'])) {
            $title = (string) $parsed_form['post_title'];
        }
        if ($title === '') {
            $title = (string) $post->post_title;
        }

        $content = isset($snapshot['content']) ? (string) $snapshot['content'] : '';
        if ($content === '' && isset($parsed_form['content'])) {
            $content = (string) $parsed_form['content'];
        }
        if ($content === '') {
            $autosave = wp_get_post_autosave($post_id, get_current_user_id());
            if ($autosave instanceof WP_Post && !empty($autosave->post_content)) {
                $content = (string) $autosave->post_content;
            } else {
                $content = (string) $post->post_content;
            }
        }

        $excerpt = isset($snapshot['excerpt']) ? (string) $snapshot['excerpt'] : '';
        if ($excerpt === '' && isset($parsed_form['excerpt'])) {
            $excerpt = (string) $parsed_form['excerpt'];
        }
        if ($excerpt === '') {
            $excerpt = (string) $post->post_excerpt;
        }

        $meta = array();
        if (isset($snapshot['meta']) && is_array($snapshot['meta'])) {
            $meta = $snapshot['meta'];
        }

        $acf = array();
        if (isset($parsed_form['acf']) && is_array($parsed_form['acf'])) {
            $acf = $parsed_form['acf'];
        }

        $token = wp_generate_password(48, false, false);
        $session_ttl = 20 * MINUTE_IN_SECONDS;
        $session = array(
            'postId' => $post_id,
            'postType' => (string) $post->post_type,
            'postStatus' => (string) $post->post_status,
            'title' => $title,
            'content' => $content,
            'excerpt' => $excerpt,
            'meta' => $meta,
            'acf' => $acf,
            'updatedAt' => time(),
            'source' => 'vercel-wp-preview-session',
        );

        set_transient('vercel_wp_preview_session_' . $token, $session, $session_ttl);

        wp_send_json_success(array(
            'token' => $token,
            'post_id' => $post_id,
            'endpoint' => rest_url('vercel-wp/v1/preview-session'),
            'expires_in' => $session_ttl,
        ));
    }
    
    public function ajax_clear_cache() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        $this->ensure_ajax_capability('edit_posts');
        
        $url = esc_url_raw($this->get_post_value('url'));
        if (empty($url)) {
            wp_send_json_error(array('message' => __('L\'URL est requise', 'vercel-wp')));
        }
        
        // Get Vercel API credentials to determine method used
        $api_key = VercelWP_Deploy_Admin::get_sensitive_option('vercel_api_key');
        $project_id = VercelWP_Deploy_Admin::get_sensitive_option('vercel_site_id');
        
        $result = $this->clear_cache_for_url($url);
        
        // Determine which method was used for user feedback
        if (!empty($result['method']) && $result['method'] === 'nextjs_revalidate') {
            $message = !empty($result['success'])
                ? __('Revalidation demandée via le point d’entrée du framework', 'vercel-wp')
                : __('Le point d’entrée Draft/Revalidate n’a pas répondu correctement', 'vercel-wp');
        } elseif ($api_key && $project_id) {
            $message = __('Cache vidé via l\'API Vercel', 'vercel-wp');
        } else {
            $message = __('Horodatage du cache mis à jour (API Vercel non configurée)', 'vercel-wp');
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
        if (!is_array($settings)) {
            $settings = array();
        }

        $framework = $this->get_preview_framework($settings);
        $vercel_preview_url = isset($settings['vercel_preview_url']) ? $settings['vercel_preview_url'] : '';

        if ($framework === 'draft_revalidate') {
            if (!empty($settings['nextjs_draft_url'])) {
                return $this->build_nextjs_draft_url($wordpress_url, $settings);
            }

            // Graceful fallback: keep preview button usable with static mapping
            // when Draft endpoint is not yet configured.
            if (empty($vercel_preview_url)) {
                return $wordpress_url;
            }
        }

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
        $production_url = isset($settings['production_url']) ? $settings['production_url'] : '';
        $vercel_preview_url = isset($settings['vercel_preview_url']) ? $settings['vercel_preview_url'] : '';
        
        // If we have a production URL, we can map paths
        if (!empty($production_url)) {
            $parsed_wp = wp_parse_url($wordpress_url);
            
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
            $parsed_wp = wp_parse_url($wordpress_url);
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

    /**
     * Return current preview framework.
     */
    private function get_preview_framework($settings) {
        if (!is_array($settings)) {
            return 'static';
        }

        $mode = isset($settings['framework_mode']) ? sanitize_key($settings['framework_mode']) : 'static';
        if (in_array($mode, array('nextjs', 'draft_revalidate'), true)) {
            return 'draft_revalidate';
        }

        return 'static';
    }

    /**
     * In draft/revalidate mode, keep the native WordPress preview button.
     */
    private function use_native_preview_button($settings) {
        return $this->get_preview_framework($settings) === 'draft_revalidate';
    }

    /**
     * Check if preview is configured for the selected framework.
     */
    private function has_preview_target($settings) {
        if (!is_array($settings)) {
            $settings = array();
        }

        if ($this->get_preview_framework($settings) === 'draft_revalidate') {
            return !empty($settings['nextjs_draft_url']) || !empty($settings['vercel_preview_url']);
        }

        return !empty($settings['vercel_preview_url']);
    }

    /**
     * Build normalized path + query from a WordPress URL.
     */
    private function get_path_with_query($url) {
        $parsed = wp_parse_url($url);
        if (!$parsed) {
            return '/';
        }

        $path = isset($parsed['path']) ? $parsed['path'] : '/';
        if (empty($path)) {
            $path = '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        return $path;
    }

    /**
     * Resolve current post context for preview from URL/editor context.
     */
    private function get_preview_post_from_url($wordpress_url) {
        $post_id = url_to_postid($wordpress_url);

        if (!$post_id && is_admin() && isset($_GET['post'])) {
            $post_id = absint(wp_unslash($_GET['post']));
        }

        if (!$post_id && is_admin() && isset($_POST['post_ID'])) {
            $post_id = absint(wp_unslash($_POST['post_ID']));
        }

        if (!$post_id) {
            global $post;
            if ($post instanceof WP_Post) {
                $post_id = (int) $post->ID;
            }
        }

        if (!$post_id) {
            return null;
        }

        $post_obj = get_post($post_id);
        if (!$post_obj instanceof WP_Post) {
            return null;
        }

        return $post_obj;
    }

    /**
     * Append WordPress preview context args (id/nonce) for frontend draft handlers.
     */
    private function append_wordpress_preview_args($target_url, $wordpress_url) {
        $post_obj = $this->get_preview_post_from_url($wordpress_url);
        if (!$post_obj instanceof WP_Post) {
            return $target_url;
        }

        $preview_id = (int) $post_obj->ID;
        $preview_nonce = wp_create_nonce('post_preview_' . $preview_id);
        $preview_path = $this->get_path_with_query($wordpress_url);

        return add_query_arg(array(
            'preview' => 'true',
            'p' => $preview_id,
            'id' => $preview_id,
            'preview_id' => $preview_id,
            'preview_nonce' => $preview_nonce,
            'wp_post_id' => $preview_id,
            'wp_post_type' => sanitize_key($post_obj->post_type),
            'wp_post_status' => sanitize_key($post_obj->post_status),
            'wp_preview_path' => $preview_path,
        ), $target_url);
    }

    /**
     * Build a Draft Mode preview URL from current content URL.
     */
    private function build_nextjs_draft_url($wordpress_url, $settings) {
        $draft_url = isset($settings['nextjs_draft_url']) ? rtrim($settings['nextjs_draft_url'], '/') : '';
        if (empty($draft_url)) {
            return $wordpress_url;
        }

        $slug_param = isset($settings['nextjs_draft_param']) ? sanitize_key($settings['nextjs_draft_param']) : 'slug';
        if (empty($slug_param)) {
            $slug_param = 'slug';
        }

        $target_path = $this->get_path_with_query($wordpress_url);
        $draft_preview_url = add_query_arg($slug_param, $target_path, $draft_url);
        $secret = $this->get_draft_revalidate_secret($settings);
        if (!empty($secret)) {
            $secret_param = $this->get_draft_revalidate_secret_param($settings);
            $draft_preview_url = add_query_arg($secret_param, $secret, $draft_preview_url);
        }

        // Add WP preview context so frontend can resolve autosave/revision content.
        $draft_preview_url = $this->append_wordpress_preview_args($draft_preview_url, $wordpress_url);

        // Keep behavior consistent with static mode by adding a cache-buster marker.
        return add_query_arg('wp_preview', time(), $draft_preview_url);
    }
    
    public function ajax_test_connection() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        $this->ensure_ajax_capability('manage_options');
        
        $vercel_url = esc_url_raw($this->get_post_value('vercel_url'));
        
        if (empty($vercel_url)) {
            wp_send_json_error(array('message' => __('URL de preview manquante', 'vercel-wp')));
        }

        if (!$this->is_safe_remote_url($vercel_url)) {
            wp_send_json_error(array('message' => __('URL invalide. Utilisez uniquement une URL HTTPS publique.', 'vercel-wp')));
        }
        
        // Debug: Log tested URL
        // Debug logs removed
        
        // Test first with HEAD request to avoid redirections
        $response = wp_safe_remote_head($vercel_url, array(
            'timeout' => 10,
            'redirection' => 0, // No redirection for HEAD
            'headers' => array(
                'User-Agent' => 'HeadlessPreview/1.0',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
            )
        ));
        
        // If HEAD fails, try GET with limited redirections
        if (is_wp_error($response)) {
            // Debug logs removed
            
            $response = wp_safe_remote_get($vercel_url, array(
                'timeout' => 15,
                'redirection' => 2, // Limit to 2 redirections only
                'headers' => array(
                    'User-Agent' => 'HeadlessPreview/1.0',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
                )
            ));
        }
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            // Debug logs removed
            
            // More specific error messages
            if (strpos($error_message, 'too many redirects') !== false) {
                // Try to diagnose redirect issue
                $diagnosis = $this->diagnose_redirect_issue($vercel_url);
                wp_send_json_error(array('message' => __('Trop de redirections détectées. ', 'vercel-wp') . $diagnosis));
            } elseif (strpos($error_message, 'timeout') !== false) {
                wp_send_json_error(array('message' => __('Délai de connexion dépassé. L\'URL met trop de temps à répondre.', 'vercel-wp')));
            } elseif (strpos($error_message, 'SSL') !== false) {
                wp_send_json_error(array('message' => __('Erreur SSL. Vérifiez que l\'URL utilise correctement HTTPS.', 'vercel-wp')));
            } else {
                wp_send_json_error(array('message' => sprintf(__('Erreur de connexion : %s', 'vercel-wp'), $error_message)));
            }
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $final_url = wp_remote_retrieve_header($response, 'location');
        
        // Debug logs removed
        
        if ($status_code >= 200 && $status_code < 400) {
            $message = sprintf(__('Connexion réussie ! (Code HTTP : %d)', 'vercel-wp'), $status_code);
            if ($final_url && $final_url !== $vercel_url) {
                $message .= sprintf(__(' - Redirigé vers : %s', 'vercel-wp'), $final_url);
            }
            wp_send_json_success(array('message' => $message));
        } else {
            wp_send_json_error(array('message' => sprintf(__('Erreur HTTP %d. Vérifiez que l\'URL est accessible.', 'vercel-wp'), $status_code)));
        }
    }
    
    private function diagnose_redirect_issue($url) {
        // Try to follow redirects manually to diagnose
        $current_url = $url;
        $redirects = array();
        $max_redirects = 5;
        
        for ($i = 0; $i < $max_redirects; $i++) {
            if (!$this->is_safe_remote_url($current_url)) {
                break;
            }

            $response = wp_safe_remote_head($current_url, array(
                'timeout' => 5,
                'redirection' => 0
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
                if (strpos($location, '//') === 0) {
                    $location = 'https:' . $location;
                } elseif (strpos($location, '/') === 0) {
                    $parsed_current = wp_parse_url($current_url);
                    if (empty($parsed_current['scheme']) || empty($parsed_current['host'])) {
                        break;
                    }
                    $location = $parsed_current['scheme'] . '://' . $parsed_current['host'] . $location;
                }

                if (!$this->is_safe_remote_url($location)) {
                    break;
                }

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
            return __('Chaîne de redirections détectée : ' . rtrim($chain, ' → '), 'vercel-wp');
        }
        
        return __('Vérifiez que l\'URL est correcte et accessible.', 'vercel-wp');
    }
    
    public function ajax_test_connection_debug() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        $this->ensure_ajax_capability('manage_options');
        
        $vercel_url = esc_url_raw($this->get_post_value('vercel_url'));
        
        if (empty($vercel_url)) {
            wp_send_json_error(array('debug_info' => __('URL de preview manquante', 'vercel-wp')));
        }

        if (!$this->is_safe_remote_url($vercel_url)) {
            wp_send_json_error(array('debug_info' => __('URL invalide. Utilisez uniquement une URL HTTPS publique.', 'vercel-wp')));
        }
        
        $debug_info = "=== ADVANCED DIAGNOSTIC ===\n";
        $debug_info .= "Tested URL: " . $vercel_url . "\n\n";
        
        // Test 1: Simple HEAD request
        $debug_info .= "1. HEAD test (without redirects):\n";
        $response = wp_safe_remote_head($vercel_url, array(
            'timeout' => 10,
            'redirection' => 0
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
        
        // Test 2: Manual redirect tracking
        $debug_info .= "\n2. Redirect tracking:\n";
        $current_url = $vercel_url;
        $redirects = array();
        $max_redirects = 5;
        
        for ($i = 0; $i < $max_redirects; $i++) {
            $debug_info .= "   Step " . ($i + 1) . ": " . $current_url . "\n";
            
            if (!$this->is_safe_remote_url($current_url)) {
                $debug_info .= "   ❌ Unsafe redirect target blocked\n";
                break;
            }

            $response = wp_safe_remote_head($current_url, array(
                'timeout' => 5,
                'redirection' => 0
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
                if (strpos($location, '//') === 0) {
                    $location = 'https:' . $location;
                } elseif (strpos($location, '/') === 0) {
                    $parsed_current = wp_parse_url($current_url);
                    if (empty($parsed_current['scheme']) || empty($parsed_current['host'])) {
                        $debug_info .= "   ❌ Failed to parse redirect\n";
                        break;
                    }
                    $location = $parsed_current['scheme'] . '://' . $parsed_current['host'] . $location;
                }
                if (!$this->is_safe_remote_url($location)) {
                    $debug_info .= "   ❌ Redirect blocked (private target/non-HTTPS)\n";
                    break;
                }
                $current_url = $location;
                $redirects[] = $current_url;
            } else {
                $debug_info .= "   ✅ End of redirect chain\n";
                break;
            }
        }
        
        if (count($redirects) >= $max_redirects) {
            $debug_info .= "\n⚠️  ISSUE DETECTED: too many redirects!\n";
            $debug_info .= "Full chain:\n";
            $debug_info .= $vercel_url . "\n";
            foreach ($redirects as $redirect) {
                $debug_info .= "→ " . $redirect . "\n";
            }
        }
        
        // Test 3: DNS check
        $debug_info .= "\n3. DNS check:\n";
        $parsed_url = parse_url($vercel_url);
        $host = $parsed_url['host'];
        $debug_info .= "   Host: " . $host . "\n";
        
        $ip = gethostbyname($host);
        if ($ip === $host) {
            $debug_info .= "   ❌ DNS resolution failed\n";
        } else {
            $debug_info .= "   ✅ Resolved IP: " . $ip . "\n";
        }
        
        // Test 4: Connectivity test
        $debug_info .= "\n4. Connectivity test:\n";
        $socket = @fsockopen($host, 443, $errno, $errstr, 5);
        if ($socket) {
            $debug_info .= "   ✅ Port 443 (HTTPS) reachable\n";
            fclose($socket);
        } else {
            $debug_info .= "   ❌ Port 443 unreachable: " . $errstr . "\n";
        }
        
        $debug_info .= "\n=== FIN DU DIAGNOSTIC ===";

        wp_send_json_success(array('debug_info' => $debug_info));
    }

    public function ajax_check_status() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        $this->ensure_ajax_capability('edit_posts');

        $settings = get_option('vercel_wp_preview_settings', array('vercel_preview_url' => ''));
        $framework = $this->get_preview_framework($settings);
        if ($framework === 'draft_revalidate') {
            $vercel_url = !empty($settings['nextjs_draft_url'])
                ? $this->build_nextjs_draft_url(home_url('/'), $settings)
                : '';
        } else {
            $vercel_url = isset($settings['vercel_preview_url']) ? $settings['vercel_preview_url'] : '';
        }
        
        if (empty($vercel_url)) {
            wp_send_json_success(array('connected' => false, 'message' => __('Point d’entrée de preview non configuré', 'vercel-wp')));
        }

        if (!$this->is_safe_remote_url($vercel_url)) {
            wp_send_json_error(array('message' => __('L\'URL de preview configurée n\'est pas une URL HTTPS publique valide', 'vercel-wp')));
        }
        
        // Quick connection test
        $response = wp_safe_remote_get($vercel_url, array(
            'timeout' => 5,
            'headers' => array(
                'User-Agent' => 'HeadlessPreview/1.0'
            )
        ));
        
        $connected = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        
        wp_send_json_success(array(
            'connected' => $connected,
            'message' => $connected ? __('Connexion active', 'vercel-wp') : __('Connexion inactive', 'vercel-wp')
        ));
    }
    
    /**
     * AJAX handler for previewing URL replacements
     */
    public function ajax_preview_urls() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        $this->ensure_ajax_capability('manage_options');
        
        $old_url = esc_url_raw($this->get_post_value('old_url'));
        $new_url = esc_url_raw($this->get_post_value('new_url'));
        
        if (empty($old_url) || empty($new_url)) {
            wp_send_json_error(array('message' => __('Les URLs sont requises', 'vercel-wp')));
        }

        if (!wp_http_validate_url($old_url) || !wp_http_validate_url($new_url)) {
            wp_send_json_error(array('message' => __('Format d\'URL invalide', 'vercel-wp')));
        }
        
        try {
            // Count occurrences in different areas
            $preview = $this->count_url_occurrences($old_url);
            
            wp_send_json_success(array('preview' => $preview));
            
        } catch (Exception $e) {
            // Debug logs removed
            wp_send_json_error(array('message' => __('Une erreur est survenue lors de la prévisualisation des URLs', 'vercel-wp')));
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
        $this->ensure_ajax_capability('manage_options');
        
        $old_url = esc_url_raw($this->get_post_value('old_url'));
        $new_url = esc_url_raw($this->get_post_value('new_url'));
        
        if (empty($old_url) || empty($new_url)) {
            wp_send_json_error(array('message' => __('Les URLs sont requises', 'vercel-wp')));
        }

        if (!wp_http_validate_url($old_url) || !wp_http_validate_url($new_url)) {
            wp_send_json_error(array('message' => __('Format d\'URL invalide', 'vercel-wp')));
        }
        
        try {
            // Perform the URL replacement
            $replaced_count = $this->replace_urls_in_content($old_url, $new_url);
            
            // Store replacement info for potential production URL update
            if ($replaced_count > 0) {
                $this->store_replacement_info($old_url, $new_url);
            }
            
            wp_send_json_success(array(
                'message' => sprintf(__('%d occurrences remplacées avec succès', 'vercel-wp'), $replaced_count),
                'replaced_count' => $replaced_count
            ));
            
        } catch (Exception $e) {
            // Debug logs removed
            wp_send_json_error(array('message' => __('Une erreur est survenue lors du remplacement des URLs', 'vercel-wp')));
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
        $this->ensure_ajax_capability('manage_options');
        
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
                    'message' => __('URL de production mise à jour avec succès', 'vercel-wp'),
                    'new_production_url' => $update_info['new_url']
                ));
            } else {
                wp_send_json_error(array('message' => __('Échec de la mise à jour de l\'URL de production', 'vercel-wp')));
            }
        } else {
            wp_send_json_error(array('message' => __('Aucune mise à jour d\'URL de production en attente trouvée', 'vercel-wp')));
        }
    }
    
    /**
     * AJAX handler for saving settings
     */
    public function ajax_save_settings() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        $this->ensure_ajax_capability('manage_options');
        
        $settings = get_option('vercel_wp_preview_settings', array());

        if (isset($_POST['framework_mode'])) {
            $framework_mode = sanitize_key($this->get_post_value('framework_mode'));
            $settings['framework_mode'] = in_array($framework_mode, array('nextjs', 'draft_revalidate'), true) ? 'draft_revalidate' : 'static';
        }

        if (isset($_POST['vercel_preview_url'])) {
            $settings['vercel_preview_url'] = rtrim(esc_url_raw($this->get_post_value('vercel_preview_url')), '/');
        }

        if (isset($_POST['nextjs_draft_url'])) {
            $settings['nextjs_draft_url'] = rtrim(esc_url_raw($this->get_post_value('nextjs_draft_url')), '/');
        }

        if (isset($_POST['nextjs_revalidate_url'])) {
            $settings['nextjs_revalidate_url'] = rtrim(esc_url_raw($this->get_post_value('nextjs_revalidate_url')), '/');
        }

        if (isset($_POST['nextjs_draft_param'])) {
            $settings['nextjs_draft_param'] = sanitize_key($this->get_post_value('nextjs_draft_param'));
        }

        if (isset($_POST['nextjs_revalidate_param'])) {
            $settings['nextjs_revalidate_param'] = sanitize_key($this->get_post_value('nextjs_revalidate_param'));
        }

        if (isset($_POST['draft_revalidate_secret_param'])) {
            $secret_param = sanitize_key($this->get_post_value('draft_revalidate_secret_param'));
            $settings['draft_revalidate_secret_param'] = !empty($secret_param) ? $secret_param : 'secret';
        }

        if (isset($_POST['draft_revalidate_secret'])) {
            $secret = sanitize_text_field($this->get_post_value('draft_revalidate_secret'));
            if (!empty($secret)) {
                $settings['draft_revalidate_secret'] = $secret;
            }
        }

        if (isset($_POST['headless_show_menus_menu'])) {
            $settings['headless_show_menus_menu'] = filter_var(wp_unslash($_POST['headless_show_menus_menu']), FILTER_VALIDATE_BOOLEAN);
        }

        // Update production URL if provided
        if (isset($_POST['production_url'])) {
            $production_url = esc_url_raw($this->get_post_value('production_url'));

            if (!empty($production_url) && !$this->is_safe_remote_url($production_url)) {
                wp_send_json_error(array('message' => __('L\'URL de production doit être une URL HTTPS publique valide', 'vercel-wp')));
            }

            $settings['production_url'] = $production_url;
            // Debug logs removed
        }

        // Auto-fill Draft/Revalidate endpoints from production URL when empty.
        $current_mode = isset($settings['framework_mode']) ? sanitize_key($settings['framework_mode']) : 'static';
        if ($current_mode === 'draft_revalidate' && !empty($settings['production_url'])) {
            $production_base = rtrim((string) $settings['production_url'], '/');
            if (empty($settings['nextjs_draft_url'])) {
                $settings['nextjs_draft_url'] = $production_base . '/api/draft';
            }
            if (empty($settings['nextjs_revalidate_url'])) {
                $settings['nextjs_revalidate_url'] = $production_base . '/api/revalidate';
            }
        }

        if ($current_mode === 'draft_revalidate' && empty(trim((string) ($settings['draft_revalidate_secret'] ?? '')))) {
            wp_send_json_error(array('message' => __('Le secret est obligatoire en mode Draft + Revalidate. Utilisez le bouton "Générer".', 'vercel-wp')));
        }
        
        $result = update_option('vercel_wp_preview_settings', $settings);
        
        // Debug: Log the save result
        // Debug logs removed
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Réglages enregistrés avec succès', 'vercel-wp'),
                'production_url' => $settings['production_url']
            ));
        } else {
            wp_send_json_error(array('message' => __('Échec de l\'enregistrement des réglages', 'vercel-wp')));
        }
    }

    /**
     * Backward-compatible autosave endpoint.
     */
    public function ajax_auto_save() {
        $this->ajax_save_settings();
    }

    /**
     * Return lightweight ACF debug information.
     */
    public function ajax_get_acf_debug() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        $this->ensure_ajax_capability('manage_options');

        $debug = get_option('vercel_wp_preview_acf_debug', array());
        wp_send_json_success(array('entries' => $debug));
    }

    /**
     * Clear stored ACF debug information.
     */
    public function ajax_clear_acf_debug() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        $this->ensure_ajax_capability('manage_options');

        delete_option('vercel_wp_preview_acf_debug');
        wp_send_json_success(array('message' => __('Diagnostic ACF effacé', 'vercel-wp')));
    }

    /**
     * Inspect one ACF/meta field for a specific post.
     */
    public function ajax_inspect_acf_field() {
        check_ajax_referer('vercel_wp_preview_nonce', 'nonce');
        $this->ensure_ajax_capability('manage_options');

        $post_id = absint($this->get_post_value('post_id'));
        $field_name = sanitize_key($this->get_post_value('field_name'));

        if (!$post_id || empty($field_name)) {
            wp_send_json_error(array('message' => __('post_id et field_name sont requis', 'vercel-wp')));
        }

        $raw_meta = get_post_meta($post_id, $field_name, true);
        $acf_value = function_exists('get_field') ? get_field($field_name, $post_id) : null;

        wp_send_json_success(array(
            'post_id' => $post_id,
            'field_name' => $field_name,
            'meta_value' => $raw_meta,
            'acf_value' => $acf_value,
        ));
    }

    private function clear_cache_for_url($url) {
        $settings = get_option('vercel_wp_preview_settings', array());
        if ($this->get_preview_framework($settings) === 'draft_revalidate' && !empty($settings['nextjs_revalidate_url'])) {
            $revalidation_result = $this->trigger_nextjs_revalidate($url, $settings);
            $settings['last_cache_clear'] = time();
            update_option('vercel_wp_preview_settings', $settings);

            return $revalidation_result;
        }

        // Get Vercel API credentials
        $api_key = VercelWP_Deploy_Admin::get_sensitive_option('vercel_api_key');
        $project_id = VercelWP_Deploy_Admin::get_sensitive_option('vercel_site_id');
        
        if (!$api_key || !$project_id) {
            // Fallback to timestamp method if API credentials not configured
            $settings['last_cache_clear'] = time();
            update_option('vercel_wp_preview_settings', $settings);
            return array(
                'method' => 'timestamp',
                'success' => true,
            );
        }
        
        // Try to clear Vercel cache directly via API
        $purged = $this->purge_vercel_cache_direct($api_key, $project_id);
        
        // Also update timestamp as fallback
        $settings['last_cache_clear'] = time();
        update_option('vercel_wp_preview_settings', $settings);

        return array(
            'method' => 'vercel_api',
            'success' => (bool) $purged,
        );
    }

    /**
     * Trigger revalidation endpoint for a specific path.
     */
    private function trigger_nextjs_revalidate($wordpress_url, $settings) {
        $endpoint = isset($settings['nextjs_revalidate_url']) ? rtrim($settings['nextjs_revalidate_url'], '/') : '';
        if (empty($endpoint) || !$this->is_safe_remote_url($endpoint)) {
            return array(
                'method' => 'nextjs_revalidate',
                'success' => false,
            );
        }

        $path_param = isset($settings['nextjs_revalidate_param']) ? sanitize_key($settings['nextjs_revalidate_param']) : 'path';
        if (empty($path_param)) {
            $path_param = 'path';
        }

        $path = $this->get_path_with_query($wordpress_url);
        $request_url = add_query_arg($path_param, $path, $endpoint);
        $secret = $this->get_draft_revalidate_secret($settings);
        $secret_param = $this->get_draft_revalidate_secret_param($settings);
        if (!empty($secret)) {
            $request_url = add_query_arg($secret_param, $secret, $request_url);
        }

        $response = wp_safe_remote_get($request_url, array(
            'timeout' => 20,
            'redirection' => 3,
            'headers' => array(
                'User-Agent' => 'WordPress-Vercel-WP/' . VERCEL_WP_VERSION,
                'Accept' => 'application/json,text/plain,*/*',
            ),
        ));

        if (!is_wp_error($response)) {
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code >= 200 && $status_code < 300) {
                return array(
                    'method' => 'nextjs_revalidate',
                    'success' => true,
                );
            }
        }

        // Fallback for implementations expecting POST payload.
        $response = wp_safe_remote_post($endpoint, array(
            'timeout' => 20,
            'redirection' => 3,
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress-Vercel-WP/' . VERCEL_WP_VERSION,
                'Accept' => 'application/json,text/plain,*/*',
            ),
            'body' => wp_json_encode(array(
                $path_param => $path,
                'path' => $path,
                $secret_param => $secret,
                'source' => 'vercel-wp',
            )),
        ));

        if (is_wp_error($response)) {
            return array(
                'method' => 'nextjs_revalidate',
                'success' => false,
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        return array(
            'method' => 'nextjs_revalidate',
            'success' => ($status_code >= 200 && $status_code < 300),
        );
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
            'framework_mode' => 'static',
            'vercel_preview_url' => '',
            'production_url' => '',
            'nextjs_draft_url' => '',
            'nextjs_revalidate_url' => '',
            'nextjs_draft_param' => 'slug',
            'nextjs_revalidate_param' => 'path',
            'draft_revalidate_secret' => '',
            'draft_revalidate_secret_param' => 'secret',
            'cache_duration' => 300,
            'auto_refresh' => true,
            'show_button_admin_bar' => true,
            'show_deploy_button_admin_bar' => true,
            'show_button_editor' => true,
            'disable_theme_page' => true,
            'headless_show_menus_menu' => true
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
        // Define URL constants
        $this->define_url_constants();
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
            if ($this->get_preview_framework($settings) === 'draft_revalidate' && !empty($settings['nextjs_draft_url'])) {
                $preview_url = $settings['nextjs_draft_url'];
            } else {
                $preview_url = !empty($settings['vercel_preview_url']) ? $settings['vercel_preview_url'] : $frontend_url;
            }
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
            wp_send_json_error(array('message' => __('Permission refusée', 'vercel-wp')));
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
            wp_send_json_error(array('message' => __('Permission refusée', 'vercel-wp')));
        }
        
        // Clear permalink cache
        $this->clear_permalink_cache();
        
        wp_send_json_success(array('message' => __('Cache des permaliens vidé avec succès', 'vercel-wp')));
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
        // Clear permalink structure cache.
        delete_option('rewrite_rules');
        
        // Soft refresh avoids unnecessary .htaccess writes.
        flush_rewrite_rules(false);
        
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
                }
                
                if (viewSiteLink) {
                    viewSiteLink.setAttribute('href', productionUrl);
                }
                
                // Also try with .ab-item class
                var abItems = document.querySelectorAll('#wp-admin-bar-site-name .ab-item, #wp-admin-bar-view-site .ab-item');
                abItems.forEach(function(item) {
                    if (item.tagName === 'A') {
                        item.setAttribute('href', productionUrl);
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
     * Ensure menus/widgets are available in headless mode.
     */
    public function ensure_headless_theme_supports() {
        $settings = get_option('vercel_wp_preview_settings', array());
        if (empty($settings['disable_theme_page']) || empty($settings['headless_show_menus_menu'])) {
            return;
        }

        if (!current_theme_supports('menus')) {
            add_theme_support('menus');
        }

        if (!current_theme_supports('widgets')) {
            add_theme_support('widgets');
        }
    }

    /**
     * Add a direct "Menus" item in admin sidebar for headless setups.
     */
    public function add_headless_menus_shortcut() {
        $settings = get_option('vercel_wp_preview_settings', array());
        if (empty($settings['disable_theme_page']) || empty($settings['headless_show_menus_menu'])) {
            return;
        }

        add_menu_page(
            __('Menus', 'vercel-wp'),
            __('Menus', 'vercel-wp'),
            'edit_theme_options',
            'vercel-wp-headless-menus',
            array($this, 'render_headless_menus_shortcut'),
            'dashicons-menu-alt3',
            61
        );
    }

    /**
     * Redirect shortcut page to WordPress nav menus screen.
     */
    public function render_headless_menus_shortcut() {
        if (!current_user_can('edit_theme_options')) {
            wp_die(__('Permission refusée', 'vercel-wp'));
        }
        $menus_url = admin_url('nav-menus.php');
        echo '<div class="wrap"><h1>' . esc_html__('Menus', 'vercel-wp') . '</h1>';
        echo '<p>' . esc_html__('Redirection vers la page Menus…', 'vercel-wp') . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($menus_url) . '">' . esc_html__('Ouvrir Menus', 'vercel-wp') . '</a></p>';
        echo '<script>window.location.replace(' . wp_json_encode($menus_url) . ');</script>';
        echo '</div>';
    }
    
    /**
     * Disable WordPress theme admin page
     */
    public function remove_themes_menu_item() {
        // Double-check settings
        $settings = get_option('vercel_wp_preview_settings', array());
        if (!isset($settings['disable_theme_page']) || !$settings['disable_theme_page']) {
            return;
        }
        
        // Remove main themes menu (Appearance menu)
        remove_menu_page('themes.php');
        
        // Also remove all submenu items under Appearance
        global $submenu;
        if (isset($submenu['themes.php'])) {
            // Remove all submenu items
            unset($submenu['themes.php']);
        }
        
        // Try multiple methods to remove submenu items
        remove_submenu_page('themes.php', 'themes.php');
        remove_submenu_page('themes.php', 'theme-install.php');
        remove_submenu_page('themes.php', 'theme-editor.php');
        remove_submenu_page('themes.php', 'customize.php');
        remove_submenu_page('themes.php', 'widgets.php');
        remove_submenu_page('themes.php', 'nav-menus.php');
        
        // Also remove via global $menu
        global $menu;
        foreach ($menu as $key => $item) {
            if (isset($item[2]) && $item[2] === 'themes.php') {
                unset($menu[$key]);
            }
        }
    }
    
    /**
     * Redirect themes page to admin dashboard
     */
    public function redirect_themes_page() {
        // Double-check settings first
        $settings = get_option('vercel_wp_preview_settings', array());
        if (!isset($settings['disable_theme_page']) || !$settings['disable_theme_page']) {
            return;
        }
        
        global $pagenow;
        
        // Check if we're on the themes page via various methods
        $is_themes_page = false;
        
        // Method 1: Check pagenow global
        if ($pagenow === 'themes.php') {
            $is_themes_page = true;
        }
        
        // Method 2: Check GET parameter
        if (isset($_GET['page']) && $_GET['page'] === 'themes.php') {
            $is_themes_page = true;
        }
        
        // Method 3: Check REQUEST_URI
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'themes.php') !== false) {
            $is_themes_page = true;
        }
        
        if ($is_themes_page) {
            // Verify user has capability (should always be true if they reached admin)
            if (current_user_can('manage_options')) {
                wp_safe_redirect(admin_url('index.php'));
                exit;
            }
        }
    }
    
    /**
     * Block direct access to themes.php page
     * This hook fires before the themes page loads
     */
    public function block_themes_page_access() {
        // Double-check settings
        $settings = get_option('vercel_wp_preview_settings', array());
        if (isset($settings['disable_theme_page']) && $settings['disable_theme_page']) {
            wp_safe_redirect(admin_url('index.php'));
            exit;
        }
    }
    
    /**
     * Hide Appearance menu via CSS as fallback
     */
    public function hide_appearance_menu_css() {
        // Double-check settings
        $settings = get_option('vercel_wp_preview_settings', array());
        if (!isset($settings['disable_theme_page']) || !$settings['disable_theme_page']) {
            return;
        }
        
        ?>
        <style type="text/css">
            /* Hide Appearance menu completely */
            #toplevel_page_themes,
            #menu-appearance,
            li#toplevel_page_themes,
            li#menu-appearance,
            #adminmenu a[href*="themes.php"],
            #adminmenu a[href*="theme-install.php"],
            #adminmenu a[href*="theme-editor.php"] {
                display: none !important;
                visibility: hidden !important;
            }
            
            /* Hide Appearance submenu items */
            #toplevel_page_themes ul.wp-submenu,
            #menu-appearance ul.wp-submenu {
                display: none !important;
            }
        </style>
        <?php
    }
    
    /**
     * Redirect all public routes to the production URL
     */
    public function redirect_all_public_routes() {
        // Backward-compatible wrapper around the unified redirect logic.
        $this->redirect_to_frontend_url();
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

            // Replace in widgets/customizer/theme options for each URL variation.
            $this->replace_in_widgets($search_pattern, $replacement, $replaced_count);
            $this->replace_in_customizer($search_pattern, $replacement, $replaced_count);
            $this->replace_in_theme_mods($search_pattern, $replacement, $replaced_count);
        }
        
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
        
        // Clear object cache once at the end.
        wp_cache_flush();
        
        // Debug logs removed
    }

    /**
     * Generate a URL-safe shared secret for draft/revalidate endpoints.
     */
    private function generate_draft_revalidate_secret() {
        return wp_generate_password(40, false, false);
    }

    /**
     * Return configured shared secret.
     */
    private function get_draft_revalidate_secret($settings) {
        $secret = isset($settings['draft_revalidate_secret']) ? sanitize_text_field($settings['draft_revalidate_secret']) : '';
        return trim($secret);
    }

    /**
     * Return configured query/body parameter name for secret.
     */
    private function get_draft_revalidate_secret_param($settings) {
        $secret_param = isset($settings['draft_revalidate_secret_param']) ? sanitize_key($settings['draft_revalidate_secret_param']) : 'secret';
        return !empty($secret_param) ? $secret_param : 'secret';
    }
}

// Note: Preview module initialization is now handled in includes/deploy/class-deploy-plugin.php
// VercelWP_Preview_Manager::get_instance(); will be called there
