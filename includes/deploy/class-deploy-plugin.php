<?php
/**
 * Vercel WP - Deploy Module - Main Class
 * 
 * from wp-webhook-vercel-deploy
 * 
 * @package VercelWP
 * @since 2.0.0
 */

defined('ABSPATH') or die('Access denied');

class VercelWP_Deploy_Plugin {
    
    // from wp-webhook-vercel-deploy
    /**
     * Plugin version
     */
    const VERSION = '1.3.6';
    
    // from wp-webhook-vercel-deploy
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    // from wp-webhook-vercel-deploy
    /**
     * Admin handler instance
     */
    private $admin_handler;
    
    // from wp-webhook-vercel-deploy
    /**
     * API handler instance
     */
    private $api_handler;
    
    // from wp-webhook-vercel-deploy
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Constructor
     */
    private function __construct() {
        $this->init();
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Initialize plugin
     */
    private function init() {
        // Load dependencies
        $this->load_dependencies();
        
        // Initialize handlers
        $this->admin_handler = new VercelWP_Deploy_Admin();
        $this->api_handler = new VercelWP_Deploy_API();
        
        // Hook into WordPress
        add_action('init', array($this, 'load_textdomain'));
        
        // from plugin-headless-preview
        // Initialize Preview module
        VercelWP_Preview_Manager::get_instance();
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Note: Files are already loaded by vercel-wp.php
        // This method is kept for consistency but does nothing
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        vercel_wp_load_textdomain();
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Get admin handler
     */
    public function get_admin_handler() {
        return $this->admin_handler;
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Get API handler
     */
    public function get_api_handler() {
        return $this->api_handler;
    }
}

// Note: Deploy module initialization is now handled in vercel-wp.php
// The plugin will be initialized via plugins_loaded hook
