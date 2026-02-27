<?php
/**
 * Vercel WP - Main Settings Pages
 *
 * @package VercelWP
 * @since 2.0.0
 */

defined('ABSPATH') or die('Access denied');

class VercelWP_Settings {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_settings_save'));
    }
    
    /**
     * Add admin menu and subpages.
     */
    public function add_admin_menu() {
        $capability = apply_filters('vercel_wp_settings_capability', 'manage_options');
        
        if (current_user_can($capability)) {
            add_menu_page(
                __('Vercel WP', 'vercel-wp'),
                __('Vercel WP', 'vercel-wp'),
                $capability,
                'vercel-wp',
                array($this, 'render_deploy_page'),
                VERCEL_WP_PLUGIN_URL . 'assets/vercel-logo.svg',
                100
            );

            add_submenu_page(
                'vercel-wp',
                __('Deploy', 'vercel-wp'),
                __('Deploy', 'vercel-wp'),
                $capability,
                'vercel-wp',
                array($this, 'render_deploy_page')
            );

            add_submenu_page(
                'vercel-wp',
                __('Preview', 'vercel-wp'),
                __('Preview', 'vercel-wp'),
                $capability,
                'vercel-wp-preview',
                array($this, 'render_preview_page')
            );

            add_submenu_page(
                'vercel-wp',
                __('Options', 'vercel-wp'),
                __('Options', 'vercel-wp'),
                $capability,
                'vercel-wp-options',
                array($this, 'render_options_page')
            );
        }
    }
    
    /**
     * Handle settings save
     */
    public function handle_settings_save() {
        // This will be handled by WordPress settings API
        // Individual tabs will register their own settings
    }
    
    /**
     * Legacy entrypoint kept for compatibility.
     */
    public function render_settings_page() {
        $this->render_deploy_page();
    }

    /**
     * Redirect legacy tab URLs to dedicated pages.
     */
    private function maybe_redirect_legacy_tab_query() {
        if (!isset($_GET['tab'])) {
            return;
        }

        $legacy_tab = sanitize_key(wp_unslash($_GET['tab']));
        $tab_to_page = array(
            'deploy' => 'vercel-wp',
            'preview' => 'vercel-wp-preview',
            'options' => 'vercel-wp-options',
        );

        if (!isset($tab_to_page[$legacy_tab])) {
            return;
        }

        $redirect_args = array(
            'page' => $tab_to_page[$legacy_tab],
        );

        $passthrough_params = array(
            'vercel_wp_notice',
            'vercel_wp_production_changed',
            'vercel_wp_options_notice',
        );
        foreach ($passthrough_params as $param) {
            if (isset($_GET[$param])) {
                $redirect_args[$param] = sanitize_text_field(wp_unslash($_GET[$param]));
            }
        }

        $redirect_url = add_query_arg($redirect_args, admin_url('admin.php'));
        if (!headers_sent()) {
            wp_safe_redirect($redirect_url);
            exit;
        }
    }
    
    /**
     * Render Deploy page content.
     */
    public function render_deploy_page() {
        $this->maybe_redirect_legacy_tab_query();
        include VERCEL_WP_PLUGIN_DIR . 'admin/views/tab-deploy.php';
    }
    
    /**
     * Render Preview page content.
     */
    public function render_preview_page() {
        include VERCEL_WP_PLUGIN_DIR . 'admin/views/tab-preview.php';
    }

    /**
     * Render Options page content.
     */
    public function render_options_page() {
        include VERCEL_WP_PLUGIN_DIR . 'admin/views/tab-options.php';
    }
}

// Initialize settings page
VercelWP_Settings::get_instance();
