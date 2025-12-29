<?php
/**
 * Vercel WP - Deploy Module - API Handler
 * 
 * from wp-webhook-vercel-deploy
 * 
 * @package VercelWP
 * @since 2.0.0
 */

defined('ABSPATH') or die('Access denied');

class VercelWP_Deploy_API {
    
    // from wp-webhook-vercel-deploy
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('wp_ajax_vercel_deploy', array($this, 'handle_vercel_deploy'));
        add_action('wp_ajax_vercel_status', array($this, 'handle_vercel_status'));
        add_action('wp_ajax_vercel_deployments', array($this, 'handle_vercel_deployments'));
        add_action('wp_ajax_vercel_services_status', array($this, 'handle_vercel_services_status'));
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Handle secure Vercel deploy via AJAX
     */
    public function handle_vercel_deploy() {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'vercel_deploy_nonce')) {
            wp_die('Security check failed');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $webhook_url = VercelWP_Deploy_Admin::get_sensitive_option('webhook_address');
        
        // For deployment, only webhook URL is required
        if (empty($webhook_url)) {
            wp_send_json_error(__('Webhook URL not configured. Please set up your webhook URL in the settings.', 'vercel-wp'));
        }

        $response = wp_remote_post($webhook_url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress-Vercel-WP/' . VERCEL_WP_VERSION
            )
        ));

        // Debug logging (only in debug mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Debug logs removed
        }

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
            wp_send_json_error($response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Debug logs removed
        }
        
        if ($response_code === 200 || $response_code === 201) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
            wp_send_json_success('Deploy triggered successfully');
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
            wp_send_json_error('Deploy failed with status: ' . $response_code);
        }
    }

    // from wp-webhook-vercel-deploy
    /**
     * Handle secure Vercel status check via AJAX
     */
    public function handle_vercel_status() {
        // Debug logging (only in debug mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Debug logs removed
        }
        
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'vercel_status_nonce')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
            wp_die('Security check failed');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
            wp_die('Insufficient permissions');
        }

        $api_key = VercelWP_Deploy_Admin::get_sensitive_option('vercel_api_key');
        $site_id = VercelWP_Deploy_Admin::get_sensitive_option('vercel_site_id');
        
        if (!$api_key || !$site_id) {
            wp_send_json_error('API credentials not configured');
        }

        $url = "https://api.vercel.com/v6/deployments?projectId=" . $site_id;
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'User-Agent' => 'WordPress-Vercel-WP/' . VERCEL_WP_VERSION
            )
        ));

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
            wp_send_json_error($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Debug logs removed
        }
        
        $data = json_decode($body, true);

        if (!$data || !isset($data['deployments'][0])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
            wp_send_json_error('No deployment data found');
        }

        wp_send_json_success($data['deployments'][0]);
    }

    // from wp-webhook-vercel-deploy
    /**
     * Handle secure Vercel deployments list via AJAX
     */
    public function handle_vercel_deployments() {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'vercel_deployments_nonce')) {
            wp_die('Security check failed');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $api_key = VercelWP_Deploy_Admin::get_sensitive_option('vercel_api_key');
        $site_id = VercelWP_Deploy_Admin::get_sensitive_option('vercel_site_id');
        
        if (!$api_key || !$site_id) {
            wp_send_json_error('API credentials not configured');
        }

        $url = "https://api.vercel.com/v6/deployments?projectId=" . $site_id;
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'User-Agent' => 'WordPress-Vercel-WP/' . VERCEL_WP_VERSION
            )
        ));

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['deployments'])) {
            wp_send_json_error('No deployments found');
        }

        wp_send_json_success($data['deployments']);
    }

    // from wp-webhook-vercel-deploy
    /**
     * Handle Vercel services status check via AJAX (server-side proxy to avoid CORS)
     */
    public function handle_vercel_services_status() {
        // Debug logging (only in debug mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Debug logs removed
        }
        
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'vercel_status_nonce')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
            wp_die('Security check failed');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
            wp_die('Insufficient permissions');
        }

        // Default status
        $default_status = array(
            'api' => 'operational',
            'cdn' => 'operational',
            'deployments' => 'operational',
            'functions' => 'operational',
            'lastUpdated' => current_time('mysql')
        );

        // Try to fetch from Vercel Status API
        $response = wp_remote_get('https://vercel-status.com/api/v2/status.json', array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'WordPress-Vercel-WP/' . VERCEL_WP_VERSION
            )
        ));

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
            wp_send_json_success($default_status);
        }

        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Debug logs removed
        }

        if ($status_code !== 200) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
            wp_send_json_success($default_status);
        }

        $data = json_decode($body, true);
        
        if (!$data) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
            wp_send_json_success($default_status);
        }

        // Parse the response
        $status = $default_status;
        
        if (isset($data['status']['indicators']) && is_array($data['status']['indicators'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
            
            foreach ($data['status']['indicators'] as $indicator) {
                $service_name = strtolower($indicator['name']);
                $service_status = strtolower($indicator['status']);
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // Debug logs removed
                }
                
                if (strpos($service_name, 'api') !== false) {
                    $status['api'] = $service_status;
                } elseif (strpos($service_name, 'cdn') !== false || strpos($service_name, 'edge') !== false) {
                    $status['cdn'] = $service_status;
                } elseif (strpos($service_name, 'deployment') !== false) {
                    $status['deployments'] = $service_status;
                } elseif (strpos($service_name, 'function') !== false) {
                    $status['functions'] = $service_status;
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Debug logs removed
        }
        wp_send_json_success($status);
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Get deployment status image source
     */
    public static function get_status_image_src($state) {
        $base_url = VERCEL_WP_PLUGIN_URL . 'assets/';
        
        switch ($state) {
            case 'CANCELED':
                return $base_url . 'vercel-none.svg';
            case 'ERROR':
                return $base_url . 'vercel-failed.svg';
            case 'INITIALIZING':
            case 'QUEUED':
                return $base_url . 'vercel-pending.svg';
            case 'READY':
                return $base_url . 'vercel-ready.svg';
            case 'BUILDING':
                return $base_url . 'vercel-building.svg';
            default:
                return $base_url . 'vercel-pending.svg';
        }
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Format date for display
     */
    public static function format_date($date_string) {
        $date = new DateTime($date_string);
        return $date->format('j F Y, H:i:s');
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Validate webhook URL with enhanced security checks
     */
    public static function validate_webhook_url($url) {
        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Must use HTTPS
        if (strpos($url, 'https://') !== 0) {
            return false;
        }
        
        // Parse URL for additional validation
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return false;
        }
        
        // Validate Vercel domains
        $allowed_domains = array(
            'api.vercel.com',
            'vercel.com',
            'vercel.app'
        );
        
        $host = strtolower($parsed['host']);
        foreach ($allowed_domains as $domain) {
            if ($host === $domain || substr($host, -strlen($domain) - 1) === '.' . $domain) {
                return true;
            }
        }
        
        return false;
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Validate Vercel API key format with enhanced security checks
     */
    public static function validate_api_key($key) {
        // Basic validation
        if (empty($key) || !is_string($key)) {
            return false;
        }
        
        // Check minimum length
        if (strlen($key) < 20) {
            return false;
        }
        
        // Check for valid characters (alphanumeric and some special chars)
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $key)) {
            return false;
        }
        
        // Check maximum length to prevent abuse
        if (strlen($key) > 200) {
            return false;
        }
        
        return true;
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Validate Vercel project ID format with enhanced security checks
     */
    public static function validate_project_id($id) {
        // Basic validation
        if (empty($id) || !is_string($id)) {
            return false;
        }
        
        // Check for valid characters (alphanumeric, hyphens, underscores)
        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $id)) {
            return false;
        }
        
        // Check length constraints
        if (strlen($id) < 5 || strlen($id) > 100) {
            return false;
        }
        
        // Check for common patterns (prj_ prefix for project IDs)
        if (strpos($id, 'prj_') === 0) {
            return true;
        }
        
        // Allow other valid formats
        return true;
    }
}

