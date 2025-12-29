<?php
/**
 * Vercel WP - Deploy Module - Admin Handler
 * 
 * from wp-webhook-vercel-deploy
 * 
 * @package VercelWP
 * @since 2.0.0
 */

defined('ABSPATH') or die('Access denied');

class VercelWP_Deploy_Admin {
    
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
        // Note: menu creation is now handled by admin/settings.php
        add_action('admin_init', array($this, 'setup_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_bar_menu', array($this, 'add_admin_bar_items'), 90);
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Setup plugin settings
     */
    public function setup_settings() {
        $this->setup_sections();
        $this->setup_developer_fields();
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Setup settings sections
     */
    private function setup_sections() {
        add_settings_section(
            'vercel_wp_deploy_section',
            __('Webhook Settings', 'vercel-wp'),
            array($this, 'section_callback'),
            'vercel_wp_deploy_settings'
        );
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Section callback
     */
    public function section_callback($arguments) {
        switch($arguments['id']) {
            case 'vercel_wp_deploy_section':
                echo __('The build and deploy status will not work without these fields entered correctly', 'vercel-wp');
                break;
        }
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Setup developer fields
     */
    private function setup_developer_fields() {
        $fields = array(
            array(
                'uid' => 'webhook_address',
                'label' => __('Webhook Build URL', 'vercel-wp'),
                'section' => 'vercel_wp_deploy_section',
                'type' => 'text',
                'placeholder' => 'https://api.vercel.com/v1/integrations/deploy/prj_4EsKMhzMvcutbtnOR1Zh5NW0MNVO/na4U6T7r9a',
                'default' => '',
            ),
            array(
                'uid' => 'vercel_site_id',
                'label' => __('Vercel Project ID', 'vercel-wp'),
                'section' => 'vercel_wp_deploy_section',
                'type' => 'text',
                'placeholder' => 'e.g. 5b8e927e-82e1-4786-4770-a9a8321yes43',
                'default' => '',
            ),
            array(
                'uid' => 'vercel_api_key',
                'label' => __('Vercel API Key', 'vercel-wp'),
                'section' => 'vercel_wp_deploy_section',
                'type' => 'text',
                'placeholder' => 'a8dm0EUHYN3oXhSerUr9LJng',
                'default' => '',
            ),
        );
        
        foreach($fields as $field) {
            add_settings_field(
                $field['uid'],
                $field['label'],
                array($this, 'field_callback'),
                'vercel_wp_deploy_settings',
                $field['section'],
                $field
            );
            register_setting('vercel_wp_deploy_settings', $field['uid'], array(
                'sanitize_callback' => array($this, 'sanitize_field')
            ));
        }
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Field callback
     */
    public function field_callback($arguments) {
        // Use encrypted getter for sensitive fields
        $value = $this->get_encrypted_option($arguments['uid'], $arguments['default']);
        
        if (!$value) {
            $value = $arguments['default'];
        }
        
        // Determine placeholder based on field value
        $placeholder = $arguments['placeholder'];
        $is_sensitive = in_array($arguments['uid'], array('vercel_api_key', 'webhook_address', 'vercel_site_id'));
        $has_value = !empty($value);
        
        if (empty($value)) {
            // Show placeholder when field is empty
            $display_value = '';
        } else {
            // For sensitive fields with values, mask them by default
            if ($is_sensitive && $has_value) {
                $display_value = str_repeat('•', min(strlen($value), 20));
            } else {
                // Show actual value when field has content
                $display_value = $value;
            }
        }
        
        // Determine input type - use password for sensitive fields when they have values
        $input_type = $arguments['type'];
        if ($is_sensitive && $has_value && $input_type === 'text') {
            $input_type = 'password';
        }
        
        switch($arguments['type']) {
            case 'text':
            case 'password':
            case 'number':
                // Add wrapper for sensitive fields
                if ($is_sensitive && $has_value) {
                    printf(
                        '<div class="vercel-sensitive-field-wrapper" style="display: flex; align-items: center; gap: 8px; max-width: 600px;">' .
                        '<input name="%1$s" id="%1$s" type="%2$s" placeholder="%3$s" value="%4$s" class="regular-text vercel-sensitive-input" data-original-value="%5$s" readonly style="flex: 1; background-color: #f6f7f7; cursor: not-allowed;" />' .
                        '<button type="button" class="button button-primary vercel-edit-field" data-field-id="%1$s" data-text-replace="%6$s" data-text-cancel="%7$s">' .
                        esc_html(__('Éditer', 'vercel-wp')) .
                        '</button>' .
                        '</div>' .
                        '<p class="description vercel-field-description" style="margin-top: 6px; color: #646970; font-size: 12px; line-height: 1.5;">%8$s</p>',
                        $arguments['uid'],
                        $input_type,
                        $placeholder,
                        esc_attr($display_value),
                        esc_attr($value),
                        esc_attr(__('Éditer', 'vercel-wp')),
                        esc_attr(__('Annuler', 'vercel-wp')),
                        esc_html(__('Valeur masquée pour sécurité. Cliquez sur "Éditer" pour la remplacer.', 'vercel-wp'))
                    );
                } else {
                    printf(
                        '<input name="%1$s" id="%1$s" type="%2$s" placeholder="%3$s" value="%4$s" class="regular-text" />',
                        $arguments['uid'],
                        $arguments['type'],
                        $placeholder,
                        esc_attr($display_value)
                    );
                }
                break;
            case 'textarea':
                printf(
                    '<textarea name="%1$s" id="%1$s" placeholder="%2$s" rows="5" cols="50" class="large-text">%3$s</textarea>',
                    $arguments['uid'],
                    $placeholder,
                    esc_textarea($display_value)
                );
                break;
        }
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Encrypt sensitive data before storing in database
     */
    private static function encrypt_sensitive_data($value) {
        if (empty($value)) {
            return $value;
        }
        
        // Check if value is already encrypted (starts with our marker)
        if (strpos($value, 'VERCEL_ENCRYPTED:') === 0) {
            return $value; // Already encrypted
        }
        
        // Use WordPress salt for encryption key
        $key = wp_salt('auth');
        
        // Use AES-256-CBC encryption if OpenSSL is available
        if (function_exists('openssl_encrypt')) {
            $iv_length = openssl_cipher_iv_length('AES-256-CBC');
            $iv = openssl_random_pseudo_bytes($iv_length);
            $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
            
            if ($encrypted !== false) {
                // Store IV with encrypted data
                return 'VERCEL_ENCRYPTED:' . base64_encode($iv . $encrypted);
            }
        }
        
        // Fallback to simple obfuscation if OpenSSL is not available
        // This is not secure but better than plain text
        return 'VERCEL_ENCRYPTED:' . base64_encode($value . '|' . wp_hash($value));
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Decrypt sensitive data when reading from database
     */
    private static function decrypt_sensitive_data($value) {
        if (empty($value)) {
            return $value;
        }
        
        // Check if value is encrypted
        if (strpos($value, 'VERCEL_ENCRYPTED:') !== 0) {
            return $value; // Not encrypted, return as-is (for backward compatibility)
        }
        
        // Remove marker
        $encrypted_data = substr($value, strlen('VERCEL_ENCRYPTED:'));
        $decoded = base64_decode($encrypted_data, true);
        
        if ($decoded === false) {
            return ''; // Invalid base64
        }
        
        // Use WordPress salt for decryption key
        $key = wp_salt('auth');
        
        // Try AES-256-CBC decryption if OpenSSL is available
        if (function_exists('openssl_decrypt')) {
            $iv_length = openssl_cipher_iv_length('AES-256-CBC');
            
            if (strlen($decoded) > $iv_length) {
                $iv = substr($decoded, 0, $iv_length);
                $encrypted = substr($decoded, $iv_length);
                $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
                
                if ($decrypted !== false) {
                    return $decrypted;
                }
            }
        }
        
        // Fallback: try to decode simple obfuscation
        $parts = explode('|', $decoded);
        if (count($parts) === 2 && wp_hash($parts[0]) === $parts[1]) {
            return $parts[0];
        }
        
        // If decryption fails, return empty (data corrupted or key changed)
        return '';
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Sanitize field input with enhanced validation and encryption
     */
    public function sanitize_field($value) {
        if (is_string($value)) {
            $sanitized = sanitize_text_field($value);
            
            // Get the field name from the current filter
            $filter_name = current_filter();
            $field_name = null;
            
            if (strpos($filter_name, 'pre_update_option_') === 0) {
                $field_name = str_replace('pre_update_option_', '', $filter_name);
            }
            
            // Check if this is a sensitive field
            $is_sensitive = in_array($field_name, array('vercel_api_key', 'webhook_address', 'vercel_site_id'));
            
            // Additional validation for specific fields
            if ($field_name === 'webhook_address') {
                if (!VercelWP_Deploy_API::validate_webhook_url($sanitized)) {
                    add_settings_error(
                        'webhook_address',
                        'invalid_webhook_url',
                        __('Invalid webhook URL. Must be a valid HTTPS URL from Vercel domains.', 'vercel-wp')
                    );
                    return $this->get_encrypted_option('webhook_address', ''); // Return previous value
                }
            } elseif ($field_name === 'vercel_api_key') {
                if (!VercelWP_Deploy_API::validate_api_key($sanitized)) {
                    add_settings_error(
                        'vercel_api_key',
                        'invalid_api_key',
                        __('Invalid API key format. Must be at least 20 characters and contain only valid characters.', 'vercel-wp')
                    );
                    return $this->get_encrypted_option('vercel_api_key', ''); // Return previous value
                }
            } elseif ($field_name === 'vercel_site_id') {
                if (!VercelWP_Deploy_API::validate_project_id($sanitized)) {
                    add_settings_error(
                        'vercel_site_id',
                        'invalid_project_id',
                        __('Invalid project ID format. Must contain only valid characters and be between 5-100 characters.', 'vercel-wp')
                    );
                    return $this->get_encrypted_option('vercel_site_id', ''); // Return previous value
                }
            }
            
            // Encrypt sensitive fields before saving
            if ($is_sensitive && !empty($sanitized)) {
                return self::encrypt_sensitive_data($sanitized);
            }
            
            return $sanitized;
        }
        
        if (is_array($value)) {
            return array_map('sanitize_text_field', $value);
        }
        
        return $value;
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Get option value with automatic decryption for sensitive fields
     */
    private function get_encrypted_option($option_name, $default = false) {
        $value = get_option($option_name, $default);
        
        // Check if this is a sensitive field
        $is_sensitive = in_array($option_name, array('vercel_api_key', 'webhook_address', 'vercel_site_id'));
        
        // Decrypt if sensitive
        if ($is_sensitive && !empty($value)) {
            return self::decrypt_sensitive_data($value);
        }
        
        return $value;
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Public static method to get decrypted sensitive option
     * Used by other classes that need to access encrypted values
     */
    public static function get_sensitive_option($option_name, $default = false) {
        $value = get_option($option_name, $default);
        
        // Check if this is a sensitive field
        $is_sensitive = in_array($option_name, array('vercel_api_key', 'webhook_address', 'vercel_site_id'));
        
        // Decrypt if sensitive
        if ($is_sensitive && !empty($value)) {
            return self::decrypt_sensitive_data($value);
        }
        
        return $value;
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Enqueue scripts and styles with optimizations
     */
    public function enqueue_scripts($hook) {
        // Debug logging (only in debug mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Debug logs removed
        }
        
        // Only load assets on relevant pages or when admin bar is needed
        $should_load_assets = $this->should_load_assets($hook);
        
        if (!$should_load_assets) {
            return;
        }
        
        // Load CSS with optimizations
        wp_enqueue_style(
            'vercel-wp-deploy-css',
            VERCEL_WP_PLUGIN_URL . 'assets/css/deploy.css',
            array(), // No dependencies for faster loading
            VERCEL_WP_VERSION,
            'all' // Media type
        );

        // Load JavaScript with optimizations
        wp_enqueue_script(
            'vercel-wp-deploy-js',
            VERCEL_WP_PLUGIN_URL . 'assets/js/deploy.js',
            array('jquery'), // Only jQuery dependency
            VERCEL_WP_VERSION,
            true // Load in footer for better performance
        );

        // Localize script with nonces and data (API keys removed for security)
        wp_localize_script('vercel-wp-deploy-js', 'vercelDeployNonces', array(
            'deploy' => wp_create_nonce('vercel_deploy_nonce'),
            'status' => wp_create_nonce('vercel_status_nonce'),
            'deployments' => wp_create_nonce('vercel_deployments_nonce'),
            'assets_url' => VERCEL_WP_PLUGIN_URL . 'assets/',
            'ajaxurl' => admin_url('admin-ajax.php'),
            'settings_url' => admin_url('admin.php?page=vercel-wp&tab=deploy'),
            'webhook_url' => $this->get_encrypted_option('webhook_address', ''),
            // Security: API keys removed from client-side exposure
            'deploying_text' => __('Deploying…', 'vercel-wp'),
            'deploy_site_text' => __('Deploy Site', 'vercel-wp'),
            'deploy_building_text' => __('Deploy building...', 'vercel-wp'),
            'deployment_completed_text' => __('Deployment completed successfully!', 'vercel-wp'),
            'deployment_failed_text' => __('Deployment failed', 'vercel-wp'),
            'deployment_canceled_text' => __('Deployment canceled', 'vercel-wp'),
            'created_text' => __('Created:', 'vercel-wp'),
            'duration_text' => __('Duration:', 'vercel-wp'),
            'branch_text' => __('Branch:', 'vercel-wp'),
            'status_text' => __('Status:', 'vercel-wp')
        ));
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Determine if assets should be loaded based on current page/context
     */
    private function should_load_assets($hook) {
        // Always load on plugin pages
        if (strpos($hook, 'vercel-wp') !== false) {
            return true;
        }
        
        // Load on frontend if user can see admin bar
        if (!is_admin() && current_user_can('manage_options')) {
            return true;
        }
        
        // Load on admin pages if user has permissions
        if (is_admin() && current_user_can('manage_options')) {
            return true;
        }
        
        return false;
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Add items to admin bar
     */
    public function add_admin_bar_items($admin_bar) {
        $see_deploy_status = apply_filters('vercel_deploy_capability', 'manage_options');
        $run_deploys = apply_filters('vercel_deploy_capability', 'manage_options');

        if (current_user_can($run_deploys)) {
            $webhook_address = $this->get_encrypted_option('webhook_address');

            if ($webhook_address) {
                $button = array(
                    'id' => 'vercel-deploy-button',
                    'title' => '<div style="cursor: pointer;"><span class="ab-icon dashicons dashicons-hammer"></span> <span class="ab-label">'. __('Deploy Site', 'vercel-wp') .'</span></div>'
                );

                $admin_bar->add_node($button);
            }
        }

        if (current_user_can($see_deploy_status)) {
            $vercel_site_id = $this->get_encrypted_option('vercel_site_id');
    
            if ($vercel_site_id) {
                $badge = array(
                    'id' => 'vercel-deploy-status-badge',
                    'title' => sprintf(
                        '<div style="display: flex; height: 100%%; align-items: center;">
                            <img id="admin-bar-vercel-deploy-status-badge" src="%s" alt="%s" style="width: auto; height: 16px;" />
                        </div>',
                        VERCEL_WP_PLUGIN_URL . 'assets/vercel-pending.svg',
                        __('Vercel deploy status', 'vercel-wp')
                    )
                );
    
                $admin_bar->add_node($badge);
            }
        }

        // Add Vercel status indicator
        if (current_user_can($see_deploy_status)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
            $vercel_status = array(
                'id' => 'wp-admin-bar-vercel-status-indicator',
                'title' => sprintf(
                    '<a href="https://vercel-status.com" target="_blank" style="display: flex; height: 100%%; align-items: center; cursor: pointer; text-decoration: none; color: inherit; padding: 0;" title="%s">
                        <span id="vercel-status-dot" style="width: 8px; height: 8px; border-radius: 50%%; background: #646970;"></span>
                    </a>',
                    __('Vercel Status - Click to view', 'vercel-wp')
                )
            );

            $admin_bar->add_node($vercel_status);
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Debug logs removed
            }
        }
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Check if deployment is possible (only webhook URL required)
     */
    public function can_deploy() {
        $webhook_url = $this->get_encrypted_option('webhook_address');
        return !empty($webhook_url);
    }

    // from wp-webhook-vercel-deploy
    /**
     * Check configuration status and return detailed information
     */
    public function get_configuration_status() {
        $webhook_address = $this->get_encrypted_option('webhook_address');
        $vercel_api_key = $this->get_encrypted_option('vercel_api_key');
        $vercel_site_id = $this->get_encrypted_option('vercel_site_id');
        
        $status = array(
            'is_configured' => true,
            'missing_fields' => array(),
            'field_status' => array()
        );
        
        // Check Webhook URL
        if (empty($webhook_address)) {
            $status['is_configured'] = false;
            $status['missing_fields'][] = __('Webhook URL', 'vercel-wp');
            $status['field_status']['webhook'] = false;
        } else {
            $status['field_status']['webhook'] = true;
        }
        
        // Check Vercel API Key
        if (empty($vercel_api_key)) {
            $status['is_configured'] = false;
            $status['missing_fields'][] = __('Vercel API Key', 'vercel-wp');
            $status['field_status']['api_key'] = false;
        } else {
            $status['field_status']['api_key'] = true;
        }
        
        // Check Vercel Site ID
        if (empty($vercel_site_id)) {
            $status['is_configured'] = false;
            $status['missing_fields'][] = __('Vercel Project ID', 'vercel-wp');
            $status['field_status']['site_id'] = false;
        } else {
            $status['field_status']['site_id'] = true;
        }
        
        return $status;
    }

    // from wp-webhook-vercel-deploy
    /**
     * Render main page content (used in tab-deploy.php)
     */
    public function render_main_page_content() {
        $config_status = $this->get_configuration_status();
        $is_configured = $config_status['is_configured'];
        $missing_fields = $config_status['missing_fields'];
        $can_deploy = $this->can_deploy();
        ?>
        <div class="wrap vercel-deploy-page">
            <h1><?php _e('Vercel Deploy', 'vercel-wp');?></h1>
            <p class="description"><?php _e('Deploy your WordPress site to Vercel with one click', 'vercel-wp');?></p>

            <?php if (!$can_deploy): ?>
            <div class="notice notice-error">
                <p><strong><?php _e('Deployment Not Available', 'vercel-wp');?></strong></p>
                <p><?php _e('Webhook URL is required to deploy your site. Please configure it in the settings.', 'vercel-wp');?></p>
                <p><a href="<?php echo admin_url('admin.php?page=vercel-wp&tab=deploy'); ?>" class="button button-primary">
                    <?php _e('Configure Webhook URL', 'vercel-wp');?>
                </a></p>
            </div>
            <?php endif; ?>

            <?php if (!$is_configured && $can_deploy): ?>
            <div class="notice notice-warning">
                <p><strong><?php _e('Additional Configuration Available', 'vercel-wp');?></strong></p>
                <p><?php _e('You can deploy now, but for full functionality, configure these additional settings:', 'vercel-wp');?></p>
                <ul>
                    <?php foreach ($missing_fields as $field): ?>
                        <?php if ($field !== __('Webhook URL', 'vercel-wp')): ?>
                            <li><strong><?php echo esc_html($field); ?></strong></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                <p><a href="<?php echo admin_url('admin.php?page=vercel-wp&tab=deploy'); ?>" class="button">
                    <?php _e('Configure Additional Settings', 'vercel-wp');?>
                </a></p>
            </div>
            <?php endif; ?>

            <div class="vercel-layout">
                <!-- Main Content -->
                <div class="vercel-main-content" style="width: 100%;">
                    <div class="vercel-grid">
                        <!-- Deploy Section -->
                        <div class="vercel-card">
                            <h2><?php _e('Deploy Now', 'vercel-wp');?></h2>
                            <p><?php _e('Trigger a new deployment', 'vercel-wp');?></p>
                            <button id="build_button" class="button button-primary button-large" <?php echo $can_deploy ? '' : 'disabled'; ?>>
                                <?php _e('Deploy Site', 'vercel-wp');?>
                            </button>
                            <div id="build_status" class="vercel-status-message"></div>
                            <p class="description"><?php _e('This will trigger a new build and deployment of your site.', 'vercel-wp');?></p>
                        </div>

                        <!-- Status Section -->
                        <div class="vercel-card">
                            <h2><?php _e('Deployment Status', 'vercel-wp');?></h2>
                            <div id="status_display" class="vercel-status-display">
                                <div class="vercel-status-details">
                                    <div id="deploy_id" class="vercel-status-item"></div>
                                    <div id="deploy_finish_time" class="vercel-status-item"></div>
                                    <div id="deploy_finish_status" class="vercel-status-item"></div>
                                    <div id="deploy_branch" class="vercel-status-item"></div>
                                    <div id="deploy_author" class="vercel-status-item"></div>
                                    <div id="deploy_commit_message" class="vercel-status-item"></div>
                                    <div id="deploy_environment" class="vercel-status-item"></div>
                                    <div id="deploy_loading" class="vercel-status-item"></div>
                                </div>
                            </div>
                            <div id="no_status_message" class="vercel-no-status" style="display:none">
                                <p><?php _e('No active deployment found', 'vercel-wp');?></p>
                            </div>
                        </div>

                        <!-- Previous Deployments Section -->
                        <div class="vercel-card">
                            <h2><?php _e('Deployment History', 'vercel-wp');?></h2>
                            <p><?php _e('Recent deployments', 'vercel-wp');?></p>
                            <div id="previous_deploys_container" class="vercel-deployments-container">
                                <div class="vercel-loading">
                                    <span class="spinner is-active"></span>
                                    <?php _e('Loading recent deployments...', 'vercel-wp');?>
                                </div>
                            </div>
                            <div id="load_full_history_container" style="display: none; margin-top: 15px;">
                                <button id="load_full_history" class="button button-secondary">
                                    <?php _e('Load Full History', 'vercel-wp');?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Render settings content (used in tab-deploy.php)
     */
    public function render_settings_content() {
        ?>
        <div class="wrap">
            <h2><?php _e('Deploy Settings', 'vercel-wp');?></h2>
            <p><?php _e('Configure your Vercel deployment settings.', 'vercel-wp');?></p>
            <hr>

            <?php
            if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Your settings have been updated!', 'vercel-wp');?></p>
                </div>
                <?php
            }
            ?>
            
            <form method="POST" action="options.php">
                <?php
                settings_fields('vercel_wp_deploy_settings');
                do_settings_sections('vercel_wp_deploy_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    // from wp-webhook-vercel-deploy
    /**
     * Render configuration guide (for sidebar)
     */
    public function render_configuration_guide() {
        ?>
        <!-- Vercel Status Widget -->
        <div class="vercel-widget" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 20px;">
            <h3 style="margin-top: 0;"><?php _e('Vercel Status', 'vercel-wp');?></h3>
            <div id="vercel-status-widget" class="vercel-status-widget">
                <div class="vercel-status-loading">
                    <span class="spinner is-active"></span>
                    <?php _e('Checking status...', 'vercel-wp');?>
                </div>
            </div>
            <p class="vercel-status-refresh" style="margin-top: 15px; margin-bottom: 0;">
                <button id="refresh_status" class="button button-small">
                    <?php _e('Refresh', 'vercel-wp');?>
                </button>
                <span class="vercel-last-updated" style="margin-left: 10px; font-size: 12px; color: #646970;"></span>
            </p>
        </div>
        
        <div class="vercel-widget" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 20px;">
            <h3 style="margin-top: 0;"><?php _e('Configuration Guide', 'vercel-wp');?></h3>
            <p><?php _e('Follow these steps to get the required information from Vercel:', 'vercel-wp');?></p>
            
            <h4><?php _e('1. Webhook Build URL', 'vercel-wp');?></h4>
            <ol style="font-size: 13px; line-height: 1.6;">
                <li><?php _e('Go to your Vercel project dashboard', 'vercel-wp');?></li>
                <li><?php _e('Navigate to Settings → Git', 'vercel-wp');?></li>
                <li><?php _e('Scroll down to "Deploy Hooks" section', 'vercel-wp');?></li>
                <li><?php _e('Click "Create Hook" and give it a name', 'vercel-wp');?></li>
                <li><?php _e('Copy the generated webhook URL', 'vercel-wp');?></li>
            </ol>
            <p><a href="https://vercel.com/docs/deployments/deploy-hooks" target="_blank" class="button button-small"><?php _e('📖 Deploy Hook Docs', 'vercel-wp');?></a></p>
            
            <hr style="margin: 20px 0;">
            
            <h4><?php _e('2. Vercel Project ID', 'vercel-wp');?></h4>
            <ol style="font-size: 13px; line-height: 1.6;">
                <li><?php _e('Go to your Vercel project dashboard', 'vercel-wp');?></li>
                <li><?php _e('Navigate to Settings → General', 'vercel-wp');?></li>
                <li><?php _e('Find "Project ID" in the Project Information section', 'vercel-wp');?></li>
                <li><?php _e('Copy the Project ID', 'vercel-wp');?></li>
            </ol>
            
            <hr style="margin: 20px 0;">
            
            <h4><?php _e('3. Vercel API Key', 'vercel-wp');?></h4>
            <ol style="font-size: 13px; line-height: 1.6;">
                <li><?php _e('Go to Vercel Account Settings', 'vercel-wp');?></li>
                <li><?php _e('Navigate to Tokens section', 'vercel-wp');?></li>
                <li><?php _e('Click "Create Token" and give it a name', 'vercel-wp');?></li>
                <li><?php _e('Set expiration date (recommended: 1 year)', 'vercel-wp');?></li>
                <li><?php _e('Copy the generated token', 'vercel-wp');?></li>
            </ol>
            <p><a href="https://vercel.com/docs/rest-api#authentication" target="_blank" class="button button-small"><?php _e('📖 API Key Docs', 'vercel-wp');?></a></p>
            
            <div style="background: #e7f3ff; border-left: 4px solid #0073aa; padding: 12px; margin-top: 20px; font-size: 13px;">
                <p style="margin: 0;"><strong><?php _e('💡 Important:', 'vercel-wp');?></strong> <?php _e('If your project is in a Team, make sure to set your team as the Default Team in Account Settings.', 'vercel-wp');?></p>
            </div>
        </div>
        
        <div class="vercel-widget" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h3 style="margin-top: 0;"><?php _e('Quick Links', 'vercel-wp');?></h3>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <a href="https://vercel.com/dashboard" target="_blank" class="button" style="text-align: center;">
                    <?php _e('Vercel Dashboard', 'vercel-wp');?>
                </a>
                <a href="https://vercel.com/docs" target="_blank" class="button" style="text-align: center;">
                    <?php _e('Vercel Docs', 'vercel-wp');?>
                </a>
            </div>
        </div>
        <?php
    }
}

