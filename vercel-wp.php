<?php
/**
 * Plugin Name: Vercel WP
 * Plugin URI: https://github.com/doreybenjamin/vercel-wp
 * Description: Unofficial Vercel plugin - Complete Vercel integration for WordPress.
 * Version: 1.0.5
 * Author: Dorey Benjamin
 * Author URI: https://benjamindorey.fr/
 * License: GPLv3 or later
 * Text Domain: vercel-wp
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 8.0
 */


/*
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

defined('ABSPATH') or die('Access denied');

// Define plugin constants
define('VERCEL_WP_VERSION', '1.0.5');
define('VERCEL_WP_PLUGIN_FILE', __FILE__);
define('VERCEL_WP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VERCEL_WP_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 */
class VercelWP {
    
    private static $instance = null;
    
    /**
     * Get plugin instance (Singleton)
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        
        // from plugin-headless-preview
        // Load Preview Manager FIRST (required by Deploy module)
        require_once VERCEL_WP_PLUGIN_DIR . 'includes/preview/class-preview-manager.php';
        
        // from wp-webhook-vercel-deploy
        require_once VERCEL_WP_PLUGIN_DIR . 'includes/deploy/class-deploy-plugin.php';
        require_once VERCEL_WP_PLUGIN_DIR . 'includes/deploy/class-deploy-admin.php';
        require_once VERCEL_WP_PLUGIN_DIR . 'includes/deploy/class-deploy-api.php';
        
        // Admin settings page
        require_once VERCEL_WP_PLUGIN_DIR . 'admin/settings.php';
        
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('init', array($this, 'init_modules'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
    }
    
    /**
     * Initialize plugin modules
     */
    public function init_modules() {
        
        // Initialize Deploy module (which will also init Preview module)
        VercelWP_Deploy_Plugin::get_instance();
        
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        
        $plugin_rel_path = dirname(plugin_basename(__FILE__)) . '/languages';
        $loaded = load_plugin_textdomain('vercel-wp', false, $plugin_rel_path);
        
        // If the standard method fails, try loading with absolute path
        if (!$loaded) {
            $mofile = VERCEL_WP_PLUGIN_DIR . 'languages/vercel-wp-' . get_locale() . '.mo';
            if (file_exists($mofile)) {
                load_textdomain('vercel-wp', $mofile);
            }
        } else {
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        
        // Activation tasks
        flush_rewrite_rules();
        
    }
    
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        
        // Deactivation tasks
        flush_rewrite_rules();
        
    }
}

/**
 * Initialize the plugin
 */
function vercel_wp_init() {
    
    return VercelWP::get_instance();
}


// Start the plugin
add_action('plugins_loaded', 'vercel_wp_init');

