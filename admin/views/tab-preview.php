<?php
/**
 * Vercel WP - Preview Tab View
 * 
 * from plugin-headless-preview
 * 
 * @package VercelWP
 * @since 2.0.0
 */

defined('ABSPATH') or die('Access denied');

// Get settings
$settings = get_option('vercel_wp_preview_settings', array(
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
    'show_button_editor' => true,
    'disable_theme_page' => true,
    'headless_show_menus_menu' => true
));

$framework_mode_value = (
    isset($settings['framework_mode']) && in_array($settings['framework_mode'], array('nextjs', 'draft_revalidate'), true)
) ? 'draft_revalidate' : 'static';
$is_draft_revalidate_mode = ($framework_mode_value === 'draft_revalidate');
$notice_key = isset($_GET['vercel_wp_notice']) ? sanitize_key(wp_unslash($_GET['vercel_wp_notice'])) : '';
$show_production_change_notice = isset($_GET['vercel_wp_production_changed']) && '1' === sanitize_text_field(wp_unslash($_GET['vercel_wp_production_changed']));
$client_redirect_url = '';

// Handle form submission
$is_settings_submit = isset($_POST['submit']) || isset($_POST['regenerate_draft_revalidate_secret']);
if ($is_settings_submit) {
    $notice_key = '';
    $show_production_change_notice = false;
}

if ($is_settings_submit && isset($_POST['vercel_wp_preview_settings_nonce'])) {
    if (!current_user_can('manage_options')) {
        wp_die(__('Permission refusée', 'vercel-wp'));
    }

    if (wp_verify_nonce($_POST['vercel_wp_preview_settings_nonce'], 'vercel_wp_preview_settings')) {
        $old_production_url = isset($settings['production_url']) ? $settings['production_url'] : '';
        $has_validation_error = false;
        $secret_regenerated = false;

        if (isset($_POST['framework_mode'])) {
            $framework_mode = sanitize_key(wp_unslash($_POST['framework_mode']));
            $settings['framework_mode'] = in_array($framework_mode, array('nextjs', 'draft_revalidate'), true) ? 'draft_revalidate' : 'static';
        }
        
        // Update settings
        if (isset($_POST['vercel_preview_url'])) {
            $preview_url = rtrim(esc_url_raw(wp_unslash($_POST['vercel_preview_url'])), '/');
            if (!empty($preview_url)) {
                $preview_parts = wp_parse_url($preview_url);
                if (!wp_http_validate_url($preview_url) || empty($preview_parts['scheme']) || strtolower($preview_parts['scheme']) !== 'https') {
                    $has_validation_error = true;
                    echo '<div class="notice notice-error"><p>' . __('L\'URL de preview doit être une URL HTTPS valide.', 'vercel-wp') . '</p></div>';
                } else {
                    $settings['vercel_preview_url'] = $preview_url;
                }
            } else {
                $settings['vercel_preview_url'] = '';
            }
        }
        if (isset($_POST['production_url'])) {
            $production_url = rtrim(esc_url_raw(wp_unslash($_POST['production_url'])), '/');
            if (!empty($production_url)) {
                $production_parts = wp_parse_url($production_url);
                if (!wp_http_validate_url($production_url) || empty($production_parts['scheme']) || strtolower($production_parts['scheme']) !== 'https') {
                    $has_validation_error = true;
                    echo '<div class="notice notice-error"><p>' . __('L\'URL de production doit être une URL HTTPS valide.', 'vercel-wp') . '</p></div>';
                } else {
                    $settings['production_url'] = $production_url;
                }
            } else {
                $settings['production_url'] = '';
            }
        }
        if (isset($_POST['nextjs_draft_url'])) {
            $nextjs_draft_url = rtrim(esc_url_raw(wp_unslash($_POST['nextjs_draft_url'])), '/');
            if (!empty($nextjs_draft_url)) {
                $draft_parts = wp_parse_url($nextjs_draft_url);
                if (!wp_http_validate_url($nextjs_draft_url) || empty($draft_parts['scheme']) || strtolower($draft_parts['scheme']) !== 'https') {
                    $has_validation_error = true;
                    echo '<div class="notice notice-error"><p>' . __('L’URL Draft doit être une URL HTTPS valide.', 'vercel-wp') . '</p></div>';
                } else {
                    $settings['nextjs_draft_url'] = $nextjs_draft_url;
                }
            } else {
                $settings['nextjs_draft_url'] = '';
            }
        }
        if (isset($_POST['nextjs_revalidate_url'])) {
            $nextjs_revalidate_url = rtrim(esc_url_raw(wp_unslash($_POST['nextjs_revalidate_url'])), '/');
            if (!empty($nextjs_revalidate_url)) {
                $revalidate_parts = wp_parse_url($nextjs_revalidate_url);
                if (!wp_http_validate_url($nextjs_revalidate_url) || empty($revalidate_parts['scheme']) || strtolower($revalidate_parts['scheme']) !== 'https') {
                    $has_validation_error = true;
                    echo '<div class="notice notice-error"><p>' . __('L’URL Revalidate doit être une URL HTTPS valide.', 'vercel-wp') . '</p></div>';
                } else {
                    $settings['nextjs_revalidate_url'] = $nextjs_revalidate_url;
                }
            } else {
                $settings['nextjs_revalidate_url'] = '';
            }
        }

        // Auto-fill Draft/Revalidate endpoints from production URL when empty.
        if ($settings['framework_mode'] === 'draft_revalidate') {
            $production_base = isset($settings['production_url']) ? rtrim((string) $settings['production_url'], '/') : '';
            if (!empty($production_base)) {
                if (empty($settings['nextjs_draft_url'])) {
                    $settings['nextjs_draft_url'] = $production_base . '/api/draft';
                }
                if (empty($settings['nextjs_revalidate_url'])) {
                    $settings['nextjs_revalidate_url'] = $production_base . '/api/revalidate';
                }
            }
        }

        if (isset($_POST['nextjs_draft_param'])) {
            $nextjs_draft_param = sanitize_key(wp_unslash($_POST['nextjs_draft_param']));
            $settings['nextjs_draft_param'] = !empty($nextjs_draft_param) ? $nextjs_draft_param : 'slug';
        }
        if (isset($_POST['nextjs_revalidate_param'])) {
            $nextjs_revalidate_param = sanitize_key(wp_unslash($_POST['nextjs_revalidate_param']));
            $settings['nextjs_revalidate_param'] = !empty($nextjs_revalidate_param) ? $nextjs_revalidate_param : 'path';
        }
        if (isset($_POST['draft_revalidate_secret_param'])) {
            $secret_param = sanitize_key(wp_unslash($_POST['draft_revalidate_secret_param']));
            $settings['draft_revalidate_secret_param'] = !empty($secret_param) ? $secret_param : 'secret';
        }
        if (isset($_POST['draft_revalidate_secret'])) {
            $secret_value = sanitize_text_field(wp_unslash($_POST['draft_revalidate_secret']));
            if (!empty($secret_value)) {
                $settings['draft_revalidate_secret'] = $secret_value;
            }
        }
        if (isset($_POST['regenerate_draft_revalidate_secret'])) {
            // Server-side generation guarantees a fresh random secret per regeneration.
            $settings['draft_revalidate_secret'] = wp_generate_password(40, false, false);
            $secret_regenerated = true;
        }
        if ($settings['framework_mode'] === 'draft_revalidate' && empty(trim((string) $settings['draft_revalidate_secret']))) {
            $has_validation_error = true;
            echo '<div class="notice notice-error"><p>' . __('Le secret est obligatoire en mode Draft + Revalidate. Cliquez sur "Générer".', 'vercel-wp') . '</p></div>';
        }
        if (isset($_POST['cache_duration'])) {
            $settings['cache_duration'] = intval($_POST['cache_duration']);
        }
        
        if (!$has_validation_error) {
            update_option('vercel_wp_preview_settings', $settings);

            $new_production_url = $settings['production_url'];
            $page_slug = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'vercel-wp-preview';
            $redirect_args = array(
                'page' => $page_slug,
                'vercel_wp_notice' => $secret_regenerated ? 'secret_regenerated' : 'settings_saved',
            );

            if (!empty($old_production_url) && !empty($new_production_url) && $old_production_url !== $new_production_url) {
                $redirect_args['vercel_wp_production_changed'] = '1';
            }

            $redirect_url = add_query_arg($redirect_args, admin_url('admin.php'));
            if (!headers_sent()) {
                wp_safe_redirect($redirect_url);
                exit;
            }

            // Fallback when another plugin already sent output.
            $client_redirect_url = $redirect_url;
            $notice_key = $secret_regenerated ? 'secret_regenerated' : 'settings_saved';
            $show_production_change_notice = isset($redirect_args['vercel_wp_production_changed']) && $redirect_args['vercel_wp_production_changed'] === '1';
        }
    }
}
?>

<?php if (!empty($client_redirect_url)) : ?>
    <script>
        window.location.replace(<?php echo wp_json_encode($client_redirect_url); ?>);
    </script>
<?php endif; ?>

<?php if ($notice_key === 'secret_regenerated') : ?>
    <div class="notice notice-success is-dismissible">
        <p><?php _e('Secret régénéré avec succès.', 'vercel-wp'); ?></p>
    </div>
<?php elseif ($notice_key === 'settings_saved') : ?>
    <div class="notice notice-success is-dismissible">
        <p><?php _e('Réglages enregistrés avec succès !', 'vercel-wp'); ?></p>
    </div>
<?php endif; ?>

<?php if ($show_production_change_notice) : ?>
    <div class="notice notice-warning is-dismissible">
        <p><?php _e('URL de production modifiée. Utilisez l’outil "Remplacement d’URLs" ci-dessous pour mettre à jour les liens existants.', 'vercel-wp'); ?></p>
    </div>
<?php endif; ?>

<div class="vercel-preview-layout" style="display: flex; gap: 20px; margin-top: 20px; padding-right: 20px; box-sizing: border-box;">
    <!-- Main Content (70%) -->
    <div class="vercel-preview-main" style="flex: 1; max-width: 70%;">
        <h2><?php _e('Réglages de preview', 'vercel-wp'); ?></h2>
        <p><?php _e('Configurez votre preview Vercel (URLs, mode framework et cache).', 'vercel-wp'); ?></p>
    
    <form method="post" action="">
        <?php wp_nonce_field('vercel_wp_preview_settings', 'vercel_wp_preview_settings_nonce'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="framework_mode"><?php _e('Framework du frontend', 'vercel-wp'); ?></label>
                </th>
                <td>
                    <select id="framework_mode" name="framework_mode">
                        <option value="static" <?php selected($framework_mode_value, 'static'); ?>>
                            <?php _e('Static (mapping URL de preview)', 'vercel-wp'); ?>
                        </option>
                        <option value="draft_revalidate" <?php selected($framework_mode_value, 'draft_revalidate'); ?>>
                            <?php _e('Draft + Revalidate (frameworks SSR)', 'vercel-wp'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php _e('Choisissez comment gérer les URLs de preview et l’invalidation du cache.', 'vercel-wp'); ?>
                    </p>
                </td>
            </tr>
            <tr class="framework-static-only" <?php echo $is_draft_revalidate_mode ? 'style="display:none;"' : ''; ?>>
                <th scope="row" class="framework-static-only">
                    <label for="vercel_preview_url"><?php _e('URL de preview Vercel', 'vercel-wp'); ?></label>
                </th>
                <td class="framework-static-only">
                    <input type="url" id="vercel_preview_url" name="vercel_preview_url" 
                           value="<?php echo esc_attr($settings['vercel_preview_url']); ?>" 
                           class="regular-text" placeholder="https://your-preview-url.vercel.app" />
                    <p class="description">
                        <?php _e('URL de votre site en mode preview sur Vercel (ex. : https://votre-site-git-branche.vercel.app)', 'vercel-wp'); ?>
                    </p>
                </td>
            </tr>

            <tr class="framework-nextjs-only" <?php echo $is_draft_revalidate_mode ? '' : 'style="display:none;"'; ?>>
                <th scope="row" class="framework-nextjs-only">
                    <label for="nextjs_draft_url"><?php _e('URL mode Draft', 'vercel-wp'); ?></label>
                </th>
                <td class="framework-nextjs-only">
                    <input type="url" id="nextjs_draft_url" name="nextjs_draft_url"
                           value="<?php echo esc_attr(isset($settings['nextjs_draft_url']) ? $settings['nextjs_draft_url'] : ''); ?>"
                           class="regular-text" placeholder="https://your-site.com/api/draft?secret=..." />
                    <p class="description">
                        <?php _e('Point d’entrée qui active le mode draft puis redirige vers l’URL/chemin demandé. Le plugin ajoute aussi p/preview_id/preview_nonce pour un comportement proche du prévisualiser WordPress.', 'vercel-wp'); ?>
                    </p>
                </td>
            </tr>

            <tr class="framework-nextjs-only" <?php echo $is_draft_revalidate_mode ? '' : 'style="display:none;"'; ?>>
                <th scope="row" class="framework-nextjs-only">
                    <label for="nextjs_revalidate_url"><?php _e('URL de Revalidate', 'vercel-wp'); ?></label>
                </th>
                <td class="framework-nextjs-only">
                    <input type="url" id="nextjs_revalidate_url" name="nextjs_revalidate_url"
                           value="<?php echo esc_attr(isset($settings['nextjs_revalidate_url']) ? $settings['nextjs_revalidate_url'] : ''); ?>"
                           class="regular-text" placeholder="https://your-site.com/api/revalidate?secret=..." />
                    <p class="description">
                        <?php _e('Point d’entrée appelé au clic sur "Vider le cache" pour déclencher la revalidation frontend.', 'vercel-wp'); ?>
                    </p>
                </td>
            </tr>

            <tr class="framework-nextjs-only" <?php echo $is_draft_revalidate_mode ? '' : 'style="display:none;"'; ?>>
                <th scope="row" class="framework-nextjs-only">
                    <label for="nextjs_draft_param"><?php _e('Noms des paramètres Draft/Revalidate', 'vercel-wp'); ?></label>
                </th>
                <td class="framework-nextjs-only">
                    <label for="nextjs_draft_param" style="display: inline-block; min-width: 90px;"><?php _e('Chemin Draft', 'vercel-wp'); ?></label>
                    <input type="text" id="nextjs_draft_param" name="nextjs_draft_param"
                           value="<?php echo esc_attr(isset($settings['nextjs_draft_param']) ? $settings['nextjs_draft_param'] : 'slug'); ?>"
                           class="regular-text" style="max-width: 220px;" placeholder="slug" />
                    <br><br>
                    <label for="nextjs_revalidate_param" style="display: inline-block; min-width: 90px;"><?php _e('Chemin Revalidate', 'vercel-wp'); ?></label>
                    <input type="text" id="nextjs_revalidate_param" name="nextjs_revalidate_param"
                           value="<?php echo esc_attr(isset($settings['nextjs_revalidate_param']) ? $settings['nextjs_revalidate_param'] : 'path'); ?>"
                           class="regular-text" style="max-width: 220px;" placeholder="path" />
                    <br><br>
                    <label for="draft_revalidate_secret_param" style="display: inline-block; min-width: 90px;"><?php _e('Paramètre secret', 'vercel-wp'); ?></label>
                    <input type="text" id="draft_revalidate_secret_param" name="draft_revalidate_secret_param"
                           value="<?php echo esc_attr(isset($settings['draft_revalidate_secret_param']) ? $settings['draft_revalidate_secret_param'] : 'secret'); ?>"
                           class="regular-text" style="max-width: 220px;" placeholder="secret" />
                    <p class="description">
                        <?php _e('La plupart des configurations utilisent "slug" (ou "path") pour Draft et "path" pour Revalidate.', 'vercel-wp'); ?>
                    </p>
                </td>
            </tr>

            <tr class="framework-nextjs-only" <?php echo $is_draft_revalidate_mode ? '' : 'style="display:none;"'; ?>>
                <th scope="row" class="framework-nextjs-only">
                    <label for="draft_revalidate_secret"><?php _e('Secret partagé (ENV)', 'vercel-wp'); ?></label>
                </th>
                <td class="framework-nextjs-only">
                    <?php $has_secret = !empty($settings['draft_revalidate_secret']); ?>
                    <input type="text" id="draft_revalidate_secret" name="draft_revalidate_secret"
                           value="<?php echo esc_attr(isset($settings['draft_revalidate_secret']) ? $settings['draft_revalidate_secret'] : ''); ?>"
                           class="regular-text code" readonly />
                    <button type="submit"
                            name="regenerate_draft_revalidate_secret"
                            value="1"
                            class="button button-secondary"
                            style="margin-left: 8px;">
                        <?php echo $has_secret ? esc_html__('Régénérer', 'vercel-wp') : esc_html__('Générer', 'vercel-wp'); ?>
                    </button>
                    <p class="description">
                        <?php _e('Obligatoire en mode Draft + Revalidate. Définissez cette même valeur dans les variables d’environnement de votre frontend.', 'vercel-wp'); ?>
                    </p>
                    <pre style="background:#f6f7f7; border:1px solid #dcdcde; padding:10px; border-radius:4px; margin:8px 0 0; max-width:640px;"><code><?php echo esc_html('HEADLESS_PREVIEW_SECRET=' . (isset($settings['draft_revalidate_secret']) ? $settings['draft_revalidate_secret'] : '')); ?></code></pre>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="production_url"><?php _e('URL de production', 'vercel-wp'); ?></label>
                </th>
                <td>
                    <input type="url" id="production_url" name="production_url" 
                           value="<?php echo esc_attr($settings['production_url']); ?>" 
                           class="regular-text" placeholder="https://your-production-site.com" />
                    <p class="description">
                        <?php _e('URL de production de votre site pour le mapping des chemins', 'vercel-wp'); ?>
                    </p>
                    
                    <!-- URL Replacement Section -->
                    <div id="url-replacement-section" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #e1e1e1; border-radius: 4px; display: none;">
                        <h4 style="margin: 0 0 15px 0; color: #333; font-size: 14px; font-weight: 600;">
                            <span class="dashicons dashicons-update" style="margin-right: 6px; font-size: 16px;"></span>
                            <?php _e('Remplacement d\'URLs', 'vercel-wp'); ?>
                        </h4>
                        <p style="margin: 0 0 15px 0; color: #666; font-size: 13px;">
                            <?php _e('Si vous avez changé votre URL de production, utilisez cet outil pour remplacer toutes les anciennes URLs dans votre contenu.', 'vercel-wp'); ?>
                        </p>
                        <div style="background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 8px; border-radius: 4px; margin-bottom: 15px; font-size: 12px;">
                            <strong>🛡️ <?php _e('Protection :', 'vercel-wp'); ?></strong> <?php _e('Les données de ce plugin sont automatiquement exclues du remplacement pour éviter toute corruption.', 'vercel-wp'); ?>
                        </div>
                        
                        <div class="url-replacement-form">
                            <div style="margin-bottom: 10px;">
                                <label for="old_url" style="display: block; margin-bottom: 5px; font-weight: 600;"><?php _e('Ancienne URL :', 'vercel-wp'); ?></label>
                                <input type="url" id="old_url" placeholder="https://ancien-site.com" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" />
                            </div>
                            
                            <div style="margin-bottom: 15px;">
                                <label for="new_url" style="display: block; margin-bottom: 5px; font-weight: 600;"><?php _e('Nouvelle URL :', 'vercel-wp'); ?></label>
                                <input type="url" id="new_url" placeholder="https://nouveau-site.com" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" />
                            </div>
                            
                            <div style="display: flex; gap: 6px;">
                                <button type="button" id="preview-urls" class="button button-secondary url-replacement-btn">
                                    <span class="dashicons dashicons-visibility"></span>
                                    <span><?php _e('Analyser le contenu', 'vercel-wp'); ?></span>
                                </button>
                                <button type="button" id="confirm-replace-urls" class="button button-primary url-replacement-btn" style="display: none;">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <span><?php _e('Confirmer le remplacement', 'vercel-wp'); ?></span>
                                </button>
                                <button type="button" id="cancel-replace-urls" class="button button-secondary url-replacement-btn" style="display: none;">
                                    <span class="dashicons dashicons-no-alt"></span>
                                    <span><?php _e('Annuler', 'vercel-wp'); ?></span>
                                </button>
                            </div>
                        </div>
                        
                        <div id="url-preview-result" style="margin-top: 15px; padding: 12px; border-radius: 4px; display: none; font-size: 13px;"></div>
                        <div id="url-replacement-summary" style="margin-top: 15px; padding: 12px; border-radius: 4px; display: none; font-size: 13px;"></div>
                        <div id="url-replacement-result" style="margin-top: 15px; padding: 12px; border-radius: 4px; display: none; font-size: 13px;">
                            <!-- Le contenu sera généré dynamiquement -->
                        </div>
                        
                        <!-- Debug Section -->
                        <div id="acf-debug-section" style="margin-top: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; display: none;">
                            <h4 style="margin: 0 0 15px 0; color: #856404; font-size: 14px; font-weight: 600;">
                                <span class="dashicons dashicons-warning" style="margin-right: 6px; font-size: 16px;"></span>
                                <?php _e('Diagnostic ACF - Informations de diagnostic', 'vercel-wp'); ?>
                            </h4>
                            <p style="margin: 0 0 15px 0; color: #856404; font-size: 13px;">
                                <?php _e('Informations de diagnostic pour analyser les problèmes de remplacement des champs ACF.', 'vercel-wp'); ?>
                            </p>
                            <button type="button" id="show-acf-debug" class="button button-secondary" style="margin-right: 10px;">
                                <span class="dashicons dashicons-visibility" style="margin-right: 4px; font-size: 14px;"></span>
                                <?php _e('Afficher le diagnostic ACF', 'vercel-wp'); ?>
                            </button>
                            <button type="button" id="clear-acf-debug" class="button button-secondary" style="margin-right: 10px;">
                                <span class="dashicons dashicons-trash" style="margin-right: 4px; font-size: 14px;"></span>
                                <?php _e('Effacer le diagnostic', 'vercel-wp'); ?>
                            </button>
                            <button type="button" id="inspect-acf-field" class="button button-primary">
                                <span class="dashicons dashicons-admin-tools" style="margin-right: 4px; font-size: 14px;"></span>
                                <?php _e('Inspecter un champ ACF', 'vercel-wp'); ?>
                            </button>
                            <div id="acf-debug-content" style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 4px; font-family: monospace; font-size: 12px; display: none; max-height: 400px; overflow-y: auto;"></div>
                        </div>
                    </div>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="cache_duration"><?php _e('Durée du cache', 'vercel-wp'); ?></label>
                </th>
                <td>
                    <input type="number" id="cache_duration" name="cache_duration" 
                           value="<?php echo esc_attr($settings['cache_duration']); ?>" 
                           class="small-text" min="0" step="1" />
                    <span><?php _e('secondes', 'vercel-wp'); ?></span>
                    <p class="description">
                        <?php _e('Durée de mise en cache des URLs de preview (par défaut : 300 secondes / 5 minutes)', 'vercel-wp'); ?>
                    </p>
                </td>
            </tr>
            
        </table>
        
        <?php submit_button(); ?>
    </form>
    </div>
    
    <!-- Sidebar (30%) -->
    <div class="vercel-preview-sidebar" style="flex: 0 0 30%; max-width: 30%;">
        <!-- How to use -->
        <div class="vercel-widget" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 20px;">
            <h3 style="margin-top: 0;"><?php _e('Comment utiliser', 'vercel-wp'); ?></h3>
            <ol style="font-size: 13px; line-height: 1.6;">
                <li><?php _e('Configurez votre URL de preview Vercel', 'vercel-wp'); ?></li>
                <li><?php _e('Ajoutez éventuellement votre URL de production pour le mapping', 'vercel-wp'); ?></li>
                <li><?php _e('Le bouton de preview apparaîtra dans la barre d\'administration et l\'éditeur', 'vercel-wp'); ?></li>
                <li><?php _e('Cliquez pour voir vos changements en temps réel', 'vercel-wp'); ?></li>
            </ol>
        </div>
        
        <!-- Required Vercel configuration -->
        <div class="vercel-widget" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 20px;">
            <h3 style="margin-top: 0;"><?php _e('Configuration Vercel requise', 'vercel-wp'); ?></h3>
            <p><strong><?php _e('Important :', 'vercel-wp'); ?></strong> <?php _e('Pour que la preview fonctionne, ajoutez cette configuration à votre fichier vercel.json :', 'vercel-wp'); ?></p>
            <pre style="background: #f1f1f1; padding: 10px; border-radius: 4px; font-size: 12px; overflow-x: auto;"><code>{
  "headers": [
    {
      "source": "/(.*)",
      "headers": [
        {
          "key": "Content-Security-Policy",
          "value": "upgrade-insecure-requests; base-uri 'self'; frame-ancestors 'self' https://votre-domaine-wordpress.com https://*.vercel.app"
        }
      ]
    }
  ]
}</code></pre>
        </div>
        
        <!-- Connection Test -->
        <div class="vercel-widget" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 20px;">
            <h3 style="margin-top: 0;"><?php _e('Test de connexion', 'vercel-wp'); ?></h3>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <button type="button" id="test_connection_btn" class="button button-primary" style="text-align: center;">
                    <?php _e('Tester la connexion', 'vercel-wp'); ?>
                </button>
                <button type="button" id="test_connection_debug_btn" class="button button-secondary" style="text-align: center;">
                    <?php _e('Diagnostic avancé', 'vercel-wp'); ?>
                </button>
            </div>
            <div id="connection_test_result" style="margin-top: 15px;"></div>
        </div>
        
        <!-- Statistics -->
        <div class="vercel-widget" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h3 style="margin-top: 0;"><?php _e('Statistiques', 'vercel-wp'); ?></h3>
            <p>
                <strong><?php _e('Dernier rafraîchissement :', 'vercel-wp'); ?></strong><br>
                <?php 
                $last_clear = isset($settings['last_cache_clear']) ? $settings['last_cache_clear'] : 0;
                if ($last_clear) {
                    echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_clear);
                } else {
                    _e('Jamais', 'vercel-wp');
                }
                ?>
            </p>
            
            <?php if (isset($settings['last_production_url']) && !empty($settings['last_production_url'])): ?>
            <p>
                <strong><?php _e('Dernière URL de production :', 'vercel-wp'); ?></strong><br>
                <code style="background: #f1f1f1; padding: 2px 6px; border-radius: 3px; font-size: 12px;"><?php echo esc_html($settings['last_production_url']); ?></code>
            </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function() {
    function setGroupVisibility(className, show) {
        var elements = document.querySelectorAll('.' + className);
        elements.forEach(function(el) {
            el.style.display = show ? '' : 'none';
        });
    }

    function applyFrameworkModeVisibility() {
        var frameworkSelect = document.getElementById('framework_mode');
        if (!frameworkSelect) {
            return;
        }

        var isDraftRevalidate = frameworkSelect.value === 'draft_revalidate' || frameworkSelect.value === 'nextjs';
        setGroupVisibility('framework-nextjs-only', isDraftRevalidate);
        setGroupVisibility('framework-static-only', !isDraftRevalidate);
    }

    function initFrameworkModeVisibility() {
        var frameworkSelect = document.getElementById('framework_mode');
        if (!frameworkSelect) {
            return;
        }

        frameworkSelect.addEventListener('change', function() {
            applyFrameworkModeVisibility();
            window.setTimeout(applyFrameworkModeVisibility, 0);
        });

        applyFrameworkModeVisibility();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFrameworkModeVisibility);
    } else {
        initFrameworkModeVisibility();
    }
})();
</script>

<style>
.vercel-preview-layout {
    padding-right: 20px;
    box-sizing: border-box;
}

@media (max-width: 1280px) {
    .vercel-preview-layout {
        flex-direction: column !important;
    }
    .vercel-preview-main,
    .vercel-preview-sidebar {
        max-width: 100% !important;
    }
}

/* URL Replacement Tool Styles - Exact copy from plugin-headless-preview */
#url-replacement-section {
    margin-top: 20px;
    padding: 15px;
    background: #f8f9fa;
    border: 1px solid #e1e1e1;
    border-radius: 4px;
    display: none;
}

#url-replacement-section h4 {
    margin: 0 0 15px 0;
    color: #333;
    font-size: 14px;
    font-weight: 600;
}

.url-replacement-form {
    margin-bottom: 15px;
}

.url-replacement-form label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
}

.url-replacement-form input[type="url"] {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 10px;
    transition: border-color 0.2s ease;
}

.url-replacement-form input[type="url"]:focus {
    border-color: #0073aa;
    box-shadow: 0 0 0 1px #0073aa;
    outline: none;
}

/* URL Replacement Buttons - Perfect alignment like plugin-headless-preview */
.url-replacement-btn {
    flex: 1;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 500;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 3px;
    transition: all 0.2s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    vertical-align: middle;
}

.url-replacement-btn .dashicons {
    font-size: 12px;
    width: 12px;
    height: 12px;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: 6px;
    vertical-align: middle;
    flex-shrink: 0;
}

.url-replacement-btn span:last-child {
    white-space: nowrap;
    line-height: 1;
    vertical-align: middle;
}

.url-replacement-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.url-replacement-btn:active {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

.url-replacement-btn:focus {
    outline: none;
    box-shadow: 0 0 0 1px #0073aa;
}

/* Specific styles for primary button */
.url-replacement-btn.button-primary {
    background: #0073aa;
    border-color: #0073aa;
    color: white;
}

.url-replacement-btn.button-primary:hover {
    background: #005a87;
    border-color: #005a87;
    color: white;
}

.url-replacement-btn.button-primary .dashicons {
    color: white;
}

/* Specific styles for secondary button */
.url-replacement-btn.button-secondary {
    background: white;
    border-color: #ddd;
    color: #333;
}

.url-replacement-btn.button-secondary:hover {
    background: #f8f9fa;
    border-color: #0073aa;
    color: #0073aa;
}

.url-replacement-btn.button-secondary .dashicons {
    color: inherit;
}

/* Preview details styling */
.preview-details {
    margin-top: 10px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 4px;
    border-left: 4px solid #0073aa;
}

.preview-counts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 10px;
    margin: 10px 0;
}

.preview-count-item {
    text-align: center;
    padding: 8px;
    background: white;
    border-radius: 4px;
    border: 1px solid #ddd;
}

.preview-count-item.clickable {
    cursor: pointer;
    transition: all 0.2s ease;
}

.preview-count-item.clickable:hover {
    background: #e9ecef;
    border-color: #0073aa;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.preview-count-number {
    font-size: 18px;
    font-weight: bold;
    color: #0073aa;
}

.preview-count-label {
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
}

.preview-samples {
    margin-top: 10px;
}

.preview-sample {
    margin-bottom: 8px;
    padding: 8px;
    background: white;
    border-radius: 4px;
    border: 1px solid #ddd;
}

.preview-sample-title {
    font-weight: bold;
    color: #333;
    margin-bottom: 4px;
}

.preview-sample-content {
    font-size: 12px;
    color: #666;
    line-height: 1.4;
}

/* Category details styling */
.category-details-content {
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
    margin-top: 10px;
}

.details-list {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 10px;
    background: #f9f9f9;
}

.detail-item {
    margin-bottom: 15px;
    padding: 10px;
    background: white;
    border-radius: 4px;
    border-left: 3px solid #0073aa;
}

/* Result containers */
#url-preview-result,
#url-replacement-summary,
#url-replacement-result {
    margin-top: 15px;
    padding: 12px 15px;
    border-radius: 4px;
    display: none;
    font-size: 13px;
    line-height: 1.4;
}

#url-preview-result.success,
#url-replacement-result.success {
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

#url-preview-result.error,
#url-replacement-result.error {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

#url-preview-result.loading,
#url-replacement-result.loading {
    background-color: #d1ecf1;
    border: 1px solid #bee5eb;
    color: #0c5460;
}

#url-replacement-summary.warning {
    background-color: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
}

/* Replacement summary styling */
.replacement-summary {
    background: #f8f9fa;
    border-radius: 4px;
    border-left: 4px solid #ffc107;
    padding: 15px;
    margin: 10px 0;
}

.replacement-summary h4 {
    margin: 0 0 10px 0;
    color: #856404;
    font-size: 14px;
    font-weight: 600;
}

.replacement-summary-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 10px;
    margin: 10px 0;
}

.replacement-summary-item {
    text-align: center;
    padding: 8px;
    background: white;
    border-radius: 4px;
    border: 1px solid #ddd;
}

.replacement-summary-number {
    font-size: 18px;
    font-weight: bold;
    color: #ffc107;
}

.replacement-summary-label {
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
}

.replacement-summary-samples {
    margin-top: 10px;
}

.replacement-summary-sample {
    margin-bottom: 8px;
    padding: 8px;
    background: white;
    border-radius: 4px;
    border: 1px solid #ddd;
}

.replacement-summary-sample-title {
    font-weight: bold;
    color: #333;
    margin-bottom: 4px;
}

.replacement-summary-sample-content {
    font-size: 12px;
    color: #666;
    line-height: 1.4;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<script>
jQuery(document).ready(function($) {
    // URL Replacement Tool visibility management
    var originalProductionUrl = $('#production_url').val();
    
    // Function to remove trailing slash from URL
    function removeTrailingSlash(url) {
        if (!url) return url;
        return url.replace(/\/$/, '');
    }

    function isNextjsMode() {
        var mode = $('#framework_mode').val();
        return mode === 'nextjs' || mode === 'draft_revalidate';
    }

    function getValidProductionBaseUrl() {
        var raw = ($('#production_url').val() || '').trim();
        if (!raw) {
            return '';
        }

        try {
            var parsed = new URL(raw);
            if (parsed.protocol !== 'https:' || !parsed.hostname) {
                return '';
            }
            return removeTrailingSlash(raw);
        } catch (e) {
            return '';
        }
    }

    function maybeAutofillDraftEndpoints() {
        if (!isNextjsMode()) {
            return;
        }

        var baseUrl = getValidProductionBaseUrl();
        if (!baseUrl) {
            return;
        }

        var draftField = $('#nextjs_draft_url');
        var revalidateField = $('#nextjs_revalidate_url');

        if (!draftField.val().trim()) {
            draftField.val(baseUrl + '/api/draft');
        }
        if (!revalidateField.val().trim()) {
            revalidateField.val(baseUrl + '/api/revalidate');
        }
    }

    function updateFrameworkVisibility() {
        var showNext = isNextjsMode();
        $('.framework-nextjs-only').toggle(showNext);
        $('.framework-static-only').toggle(!showNext);
    }

    function getConnectionTestUrl() {
        if (!isNextjsMode()) {
            return $('#vercel_preview_url').val();
        }

        var url = $('#nextjs_draft_url').val();
        if (!url) {
            return url;
        }

        var draftParam = $('#nextjs_draft_param').val() || 'slug';
        var secretParam = $('#draft_revalidate_secret_param').val() || 'secret';
        var secretValue = $('#draft_revalidate_secret').val() || '';

        try {
            var parsed = new URL(url);
            if (!parsed.searchParams.get(draftParam)) {
                parsed.searchParams.set(draftParam, '/');
            }
            if (secretValue && !parsed.searchParams.get(secretParam)) {
                parsed.searchParams.set(secretParam, secretValue);
            }
            return parsed.toString();
        } catch (e) {
            return url;
        }
    }

    function getConnectionTargetLabel() {
        return isNextjsMode()
            ? '<?php echo esc_js(__('URL mode Draft', 'vercel-wp')); ?>'
            : '<?php echo esc_js(__('URL de preview Vercel', 'vercel-wp')); ?>';
    }

    $('#framework_mode').on('change', function() {
        updateFrameworkVisibility();
        maybeAutofillDraftEndpoints();
        setTimeout(updateFrameworkVisibility, 0);
    });

    updateFrameworkVisibility();
    maybeAutofillDraftEndpoints();
    
    $('#production_url').on('input change', function(event) {
        var currentUrl = $(this).val();
        var replacementSection = $('#url-replacement-section');
        
        // Show replacement tool if URL has changed and is not empty
        if (currentUrl !== originalProductionUrl && currentUrl.trim() !== '') {
            replacementSection.show();
            // Pre-fill the old URL field with the original value (without trailing slash)
            $('#old_url').val(removeTrailingSlash(originalProductionUrl));
            // Pre-fill the new URL field with the current value (without trailing slash)
            $('#new_url').val(removeTrailingSlash(currentUrl));
        } else {
            replacementSection.hide();
        }

        if (event && event.type === 'change') {
            maybeAutofillDraftEndpoints();
        }
    });
    
    // URL Preview
    $('#preview-urls').on('click', function() {
        var oldUrl = removeTrailingSlash($('#old_url').val());
        var newUrl = removeTrailingSlash($('#new_url').val());
        
        if (!oldUrl || !newUrl) {
            $('#url_replacement_result').html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Veuillez saisir les deux URLs', 'vercel-wp')); ?></p></div>');
            return;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'vercel_wp_preview_preview_urls',
                nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>',
                old_url: oldUrl,
                new_url: newUrl
            },
            success: function(response) {
                if (response.success) {
                    var preview = response.data.preview;
                    var html = '<div class="notice notice-success inline"><p><strong><?php echo esc_js(__('Résultats de l\'analyse :', 'vercel-wp')); ?></strong><br>';
                    html += '<?php echo esc_js(__('Articles :', 'vercel-wp')); ?> ' + preview.posts + '<br>';
                    html += '<?php echo esc_js(__('Métadonnées :', 'vercel-wp')); ?> ' + preview.postmeta + '<br>';
                    html += '<?php echo esc_js(__('Commentaires :', 'vercel-wp')); ?> ' + preview.comments + '<br>';
                    html += '<?php echo esc_js(__('Options :', 'vercel-wp')); ?> ' + preview.options + '<br>';
                    html += '<strong><?php echo esc_js(__('Total :', 'vercel-wp')); ?> ' + preview.total_count + '</strong></p></div>';
                    $('#url_replacement_result').html(html);
                    $('#replace_urls_btn').prop('disabled', false);
                } else {
                    $('#url_replacement_result').html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                }
            }
        });
    });
    
    // URL Replacement
    $('#replace_urls_btn').on('click', function() {
        if (!confirm('<?php echo esc_js(__('Confirmez-vous le remplacement de ces URLs ? Cette action est irréversible.', 'vercel-wp')); ?>')) {
            return;
        }
        
        var oldUrl = removeTrailingSlash($('#old_url').val());
        var newUrl = removeTrailingSlash($('#new_url').val());
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'vercel_wp_preview_replace_urls',
                nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>',
                old_url: oldUrl,
                new_url: newUrl
            },
            success: function(response) {
                if (response.success) {
                    $('#url_replacement_result').html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    $('#replace_urls_btn').prop('disabled', true);
                } else {
                    $('#url_replacement_result').html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                }
            }
        });
    });
    
    // Connection Test
    $('#test_connection_btn').on('click', function() {
        var button = $(this);
        var result = $('#connection_test_result');
        var vercelUrl = getConnectionTestUrl();
        
        if (!vercelUrl) {
            result.html('<div class="notice notice-error inline"><p>' + getConnectionTargetLabel() + ': <?php echo esc_js(__('Veuillez remplir ce champ d’abord.', 'vercel-wp')); ?></p></div>');
            return;
        }
        
        button.prop('disabled', true).text('<?php echo esc_js(__('Test en cours...', 'vercel-wp')); ?>');
        result.html('<p><?php echo esc_js(__('Test de connexion en cours...', 'vercel-wp')); ?></p>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'vercel_wp_preview_test_connection',
                nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>',
                vercel_url: vercelUrl
            },
            success: function(response) {
                if (response.success) {
                    result.html('<div class="notice notice-success inline"><p>✅ ' + (response.data ? response.data.message : '<?php echo esc_js(__('Connexion réussie !', 'vercel-wp')); ?>') + '</p></div>');
                } else {
                    var errorMsg = response.data ? response.data.message : '<?php echo esc_js(__('URL inaccessible', 'vercel-wp')); ?>';
                    result.html('<div class="notice notice-error inline"><p>❌ ' + errorMsg + '</p></div>');
                }
            },
            error: function() {
                result.html('<div class="notice notice-error inline"><p>❌ <?php echo esc_js(__('Erreur lors du test de connexion.', 'vercel-wp')); ?></p></div>');
            },
            complete: function() {
                button.prop('disabled', false).text('<?php echo esc_js(__('Tester la connexion', 'vercel-wp')); ?>');
            }
        });
    });
    
    // Advanced Diagnostics
    $('#test_connection_debug_btn').on('click', function() {
        var button = $(this);
        var result = $('#connection_test_result');
        var vercelUrl = getConnectionTestUrl();
        
        if (!vercelUrl) {
            result.html('<div class="notice notice-error inline"><p>' + getConnectionTargetLabel() + ': <?php echo esc_js(__('Veuillez remplir ce champ d’abord.', 'vercel-wp')); ?></p></div>');
            return;
        }
        
        button.prop('disabled', true).text('<?php echo esc_js(__('Diagnostic en cours...', 'vercel-wp')); ?>');
        result.html('<p><?php echo esc_js(__('Diagnostic avancé en cours...', 'vercel-wp')); ?></p>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'vercel_wp_preview_test_connection_debug',
                nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>',
                vercel_url: vercelUrl
            },
            success: function(response) {
                if (response.success) {
                    result.html('<div class="notice notice-success inline"><p>✅ <?php echo esc_js(__('Diagnostic terminé', 'vercel-wp')); ?></p><pre style="background: #f9f9f9; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px; margin-top: 10px;">' + response.data.debug_info + '</pre></div>');
                } else {
                    result.html('<div class="notice notice-error inline"><p>❌ <?php echo esc_js(__('Erreur lors du diagnostic', 'vercel-wp')); ?></p><pre style="background: #f9f9f9; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px; margin-top: 10px;">' + (response.data ? response.data.debug_info : '<?php echo esc_js(__('Erreur inconnue', 'vercel-wp')); ?>') + '</pre></div>');
                }
            },
            error: function() {
                result.html('<div class="notice notice-error inline"><p>❌ <?php echo esc_js(__('Erreur lors du diagnostic', 'vercel-wp')); ?></p><pre style="background: #f9f9f9; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px; margin-top: 10px;"><?php echo esc_js(__('Erreur de communication avec le serveur', 'vercel-wp')); ?></pre></div>');
            },
            complete: function() {
                button.prop('disabled', false).text('<?php echo esc_js(__('Diagnostic avancé', 'vercel-wp')); ?>');
            }
        });
    });
    
    // URL Preview functionality
    $('#preview-urls').on('click', function() {
        var button = $(this);
        var previewResult = $('#url-preview-result');
        var summaryResult = $('#url-replacement-summary');
        var confirmButton = $('#confirm-replace-urls');
        var cancelButton = $('#cancel-replace-urls');
        var oldUrl = removeTrailingSlash($('#old_url').val());
        var newUrl = removeTrailingSlash($('#new_url').val());
        
        if (!oldUrl || !newUrl) {
            previewResult.removeClass('success error').addClass('error').html('❌ <?php echo esc_js(__('Veuillez saisir les deux URLs', 'vercel-wp')); ?>').show();
            return;
        }
        
        if (oldUrl === newUrl) {
            previewResult.removeClass('success error').addClass('error').html('❌ <?php echo esc_js(__('Les URLs doivent être différentes', 'vercel-wp')); ?>').show();
            return;
        }
        
        button.prop('disabled', true).text('<?php echo esc_js(__('Analyse...', 'vercel-wp')); ?>');
        previewResult.removeClass('success error').addClass('loading').html('<?php echo esc_js(__('Analyse du contenu...', 'vercel-wp')); ?>').show();
        summaryResult.hide();
        confirmButton.hide();
        cancelButton.hide();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'vercel_wp_preview_preview_urls',
                nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>',
                old_url: oldUrl,
                new_url: newUrl
            },
            success: function(response) {
                if (response.success) {
                    var preview = response.data.preview;
                    var message = '🔍 <strong><?php echo esc_js(__('Analyse terminée', 'vercel-wp')); ?></strong><br>';
                    message += '<strong><?php echo esc_js(__('Ancienne URL :', 'vercel-wp')); ?></strong> <code>' + oldUrl + '</code><br>';
                    message += '<strong><?php echo esc_js(__('Nouvelle URL :', 'vercel-wp')); ?></strong> <code>' + newUrl + '</code><br><br>';
                    
                    if (preview.total_count > 0) {
                        message += '<div class="preview-details">';
                        message += '<strong>📊 <?php echo esc_js(__('Occurrences trouvées :', 'vercel-wp')); ?></strong><br>';
                        message += '<div class="preview-counts">';
                        message += '<div class="preview-count-item clickable" data-category="posts"><div class="preview-count-number">' + preview.posts + '</div><div class="preview-count-label"><?php echo esc_js(__('Articles/Pages', 'vercel-wp')); ?></div></div>';
                        message += '<div class="preview-count-item clickable" data-category="postmeta"><div class="preview-count-number">' + preview.postmeta + '</div><div class="preview-count-label"><?php echo esc_js(__('Métadonnées', 'vercel-wp')); ?></div></div>';
                        message += '<div class="preview-count-item clickable" data-category="comments"><div class="preview-count-number">' + preview.comments + '</div><div class="preview-count-label"><?php echo esc_js(__('Commentaires', 'vercel-wp')); ?></div></div>';
                        message += '<div class="preview-count-item clickable" data-category="options"><div class="preview-count-number">' + preview.options + '</div><div class="preview-count-label"><?php echo esc_js(__('Options', 'vercel-wp')); ?></div></div>';
                        message += '<div class="preview-count-item clickable" data-category="widgets"><div class="preview-count-number">' + preview.widgets + '</div><div class="preview-count-label"><?php echo esc_js(__('Widgets', 'vercel-wp')); ?></div></div>';
                        message += '<div class="preview-count-item clickable" data-category="customizer"><div class="preview-count-number">' + preview.customizer + '</div><div class="preview-count-label"><?php echo esc_js(__('Personnalisation', 'vercel-wp')); ?></div></div>';
                        message += '</div>';
                        message += '</div>';
                        
                        message += '<div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px; border-radius: 4px; margin: 10px 0;">';
                        message += '<strong>✅ <?php echo esc_js(__('Prêt pour le remplacement', 'vercel-wp')); ?></strong><br>';
                        message += '<?php echo esc_js(__('Nombre total d\'éléments à modifier :', 'vercel-wp')); ?> <strong>' + preview.total_count + '</strong>';
                        message += '</div>';
                        message += '<div id="category-details" style="margin-top: 15px; display: none;"></div>';
                        
                        confirmButton.data('preview', preview).data('old-url', oldUrl).data('new-url', newUrl).show();
                        cancelButton.show();
                        
                        // Store preview data for details
                        $('#url-preview-result').data('preview', preview);
                    } else {
                        message += '<div style="background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 10px; border-radius: 4px; margin: 10px 0;">';
                        message += '<strong>ℹ️ <?php echo esc_js(__('Aucune occurrence trouvée', 'vercel-wp')); ?></strong><br>';
                        message += '<?php echo esc_js(__('Aucun contenu à mettre à jour.', 'vercel-wp')); ?>';
                        message += '</div>';
                    }
                    
                    previewResult.removeClass('loading error').addClass('success').html(message).show();
                } else {
                    previewResult.removeClass('loading success').addClass('error').html('❌ ' + (response.data ? response.data.message : '<?php echo esc_js(__('Échec de l\'analyse', 'vercel-wp')); ?>')).show();
                }
            },
            error: function() {
                previewResult.removeClass('loading success').addClass('error').html('❌ <?php echo esc_js(__('Erreur lors de l\'analyse', 'vercel-wp')); ?>').show();
            },
            complete: function() {
                button.prop('disabled', false).text('<?php echo esc_js(__('Analyser le contenu', 'vercel-wp')); ?>');
            }
        });
    });
    
    // Show replacement summary
    $('#confirm-replace-urls').on('click', function() {
        var preview = $(this).data('preview');
        var oldUrl = removeTrailingSlash($(this).data('old-url'));
        var newUrl = removeTrailingSlash($(this).data('new-url'));
        var summaryResult = $('#url-replacement-summary');
        
        var summaryMessage = '⚠️ <strong><?php echo esc_js(__('RÉSUMÉ DU REMPLACEMENT', 'vercel-wp')); ?></strong><br><br>';
        
        summaryMessage += '<div class="replacement-summary">';
        summaryMessage += '<h4>📋 <?php echo esc_js(__('Détails de l\'opération', 'vercel-wp')); ?></h4>';
        summaryMessage += '<strong><?php echo esc_js(__('Ancienne URL :', 'vercel-wp')); ?></strong> <code>' + oldUrl + '</code><br>';
        summaryMessage += '<strong><?php echo esc_js(__('Nouvelle URL :', 'vercel-wp')); ?></strong> <code>' + newUrl + '</code><br><br>';
        
        summaryMessage += '<strong>📊 <?php echo esc_js(__('Éléments qui seront modifiés :', 'vercel-wp')); ?></strong><br>';
        summaryMessage += '<div class="replacement-summary-details">';
        summaryMessage += '<div class="replacement-summary-item"><div class="replacement-summary-number">' + preview.posts + '</div><div class="replacement-summary-label"><?php echo esc_js(__('Articles/Pages', 'vercel-wp')); ?></div></div>';
        summaryMessage += '<div class="replacement-summary-item"><div class="replacement-summary-number">' + preview.postmeta + '</div><div class="replacement-summary-label"><?php echo esc_js(__('Métadonnées', 'vercel-wp')); ?></div></div>';
        summaryMessage += '<div class="replacement-summary-item"><div class="replacement-summary-number">' + preview.comments + '</div><div class="replacement-summary-label"><?php echo esc_js(__('Commentaires', 'vercel-wp')); ?></div></div>';
        summaryMessage += '<div class="replacement-summary-item"><div class="replacement-summary-number">' + preview.options + '</div><div class="replacement-summary-label"><?php echo esc_js(__('Options', 'vercel-wp')); ?></div></div>';
        summaryMessage += '<div class="replacement-summary-item"><div class="replacement-summary-number">' + preview.widgets + '</div><div class="replacement-summary-label"><?php echo esc_js(__('Widgets', 'vercel-wp')); ?></div></div>';
        summaryMessage += '<div class="replacement-summary-item"><div class="replacement-summary-number">' + preview.customizer + '</div><div class="replacement-summary-label"><?php echo esc_js(__('Personnalisation', 'vercel-wp')); ?></div></div>';
        summaryMessage += '<div class="replacement-summary-item"><div class="replacement-summary-number">' + preview.theme_mods + '</div><div class="replacement-summary-label"><?php echo esc_js(__('Thème', 'vercel-wp')); ?></div></div>';
        summaryMessage += '</div>';
        
        if (preview.samples && preview.samples.length > 0) {
            summaryMessage += '<div class="replacement-summary-samples"><strong>📝 <?php echo esc_js(__('Exemples de contenu modifié :', 'vercel-wp')); ?></strong>';
            preview.samples.forEach(function(sample) {
                summaryMessage += '<div class="replacement-summary-sample">';
                summaryMessage += '<div class="replacement-summary-sample-title">' + sample.title + '</div>';
                summaryMessage += '<div class="replacement-summary-sample-content">' + sample.content_preview + '</div>';
                summaryMessage += '</div>';
            });
            summaryMessage += '</div>';
        }
        
        summaryMessage += '</div>';
        
        summaryMessage += '<div class="replacement-warning" style="background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 12px; border-radius: 4px; margin: 15px 0; font-weight: 500;">';
        summaryMessage += '<strong>⚠️ <?php echo esc_js(__('Modification de', 'vercel-wp')); ?> ' + preview.total_count + ' <?php echo esc_js(__('éléments', 'vercel-wp')); ?></strong><br>';
        summaryMessage += '<?php echo esc_js(__('Assurez-vous d\'avoir une sauvegarde avant de continuer.', 'vercel-wp')); ?>';
        summaryMessage += '</div>';
        
        summaryMessage += '<div style="text-align: center; margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">';
        summaryMessage += '<div style="margin-bottom: 15px; font-weight: 600; color: #333;"><?php echo esc_js(__('Actions disponibles :', 'vercel-wp')); ?></div>';
        summaryMessage += '<div style="display: flex; gap: 12px; justify-content: center;">';
        summaryMessage += '<button type="button" id="execute-replace-urls" class="button button-primary" style="padding: 6px 12px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; height: 32px; min-width: 120px; border-radius: 4px; vertical-align: middle;">';
        summaryMessage += '<span class="dashicons dashicons-yes-alt" style="margin-right: 6px; font-size: 12px; line-height: 1; vertical-align: middle;display: flex; align-items: center; justify-content: center; width: 12px; height: 12px;"></span>';
        summaryMessage += '<span style="line-height: 1; vertical-align: middle;"><?php echo esc_js(__('CONFIRMER', 'vercel-wp')); ?></span>';
        summaryMessage += '</button>';
        summaryMessage += '<button type="button" id="abort-replace-urls" class="button button-secondary" style="padding: 6px 12px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; height: 32px; min-width: 120px; border-radius: 4px; vertical-align: middle;">';
        summaryMessage += '<span class="dashicons dashicons-no-alt" style="margin-right: 6px; font-size: 12px; line-height: 1; vertical-align: middle;display: flex; align-items: center; justify-content: center; width: 12px; height: 12px;"></span>';
        summaryMessage += '<span style="line-height: 1; vertical-align: middle;"><?php echo esc_js(__('ANNULER', 'vercel-wp')); ?></span>';
        summaryMessage += '</button>';
        summaryMessage += '</div>';
        summaryMessage += '</div>';
        
        summaryResult.html(summaryMessage).show();
        
        // Hide the confirm button and show cancel
        $('#confirm-replace-urls').hide();
        $('#cancel-replace-urls').hide();
    });
    
    // Cancel replacement - Complete functionality from plugin-headless-preview
    $('#cancel-replace-urls, #abort-replace-urls').on('click', function() {
        // Check if we have stored URLs for reversal
        var oldUrl = $('#url-replacement-result').data('old-url');
        var newUrl = $('#url-replacement-result').data('new-url');
        
        if (oldUrl && newUrl) {
            // Perform the reverse replacement directly (no confirmation)
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'vercel_wp_preview_replace_urls',
                    old_url: newUrl, // Reverse the URLs
                    new_url: oldUrl,
                    nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>'
                },
                beforeSend: function() {
                    $('#url-replacement-result').html('<?php echo esc_js(__('Annulation en cours...', 'vercel-wp')); ?>').removeClass('success error').addClass('loading').show();
                },
                success: function(response) {
                    if (response.success) {
                        $('#url-replacement-result').removeClass('loading error').addClass('success').html('✅ <strong><?php echo esc_js(__('Annulation terminée avec succès !', 'vercel-wp')); ?></strong><br><?php echo esc_js(__('Toutes les modifications ont été annulées.', 'vercel-wp')); ?>').show();
                        
                        // Update the production URL field back to the original
                        $('#production_url').val(oldUrl);
                        originalProductionUrl = oldUrl;
                        
                        // Save the production URL to database
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'vercel_wp_preview_save_settings',
                                production_url: oldUrl,
                                nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>'
                            },
                            success: function(saveResponse) {
                                if (saveResponse.success) {
                                    console.log('Production URL reverted to database:', oldUrl);
                                } else {
                                    console.error('Failed to save reverted production URL:', saveResponse.data);
                                }
                            },
                            error: function() {
                                console.error('Error saving reverted production URL to database');
                            }
                        });
                        
                        // Clear the stored URLs
                        $('#url-replacement-result').removeData('old-url').removeData('new-url');
                        
                        // Hide the cancel button
                        $('#cancel-replace-urls').hide();
                    } else {
                        $('#url-replacement-result').removeClass('loading').addClass('error').html('❌ <strong><?php echo esc_js(__('ERREUR :', 'vercel-wp')); ?></strong> ' + (response.data ? response.data.message : '<?php echo esc_js(__('Erreur lors de l\'annulation', 'vercel-wp')); ?>')).show();
                    }
                },
                error: function() {
                    $('#url-replacement-result').removeClass('loading').addClass('error').html('❌ <strong><?php echo esc_js(__('ERREUR :', 'vercel-wp')); ?></strong> <?php echo esc_js(__('Erreur de communication avec le serveur', 'vercel-wp')); ?>').show();
                }
            });
        } else {
            // Normal cancel behavior
            $('#url-replacement-summary').hide();
            $('#confirm-replace-urls').hide();
            $('#cancel-replace-urls').hide();
            $('#url-preview-result').hide();
            $('#url-replacement-result').hide();
            $('#old_url').val('');
            $('#new_url').val('');
            
            // Reset the production URL field to its original value
            $('#production_url').val(originalProductionUrl);
            
            // Save the production URL to database
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'vercel_wp_preview_save_settings',
                    production_url: originalProductionUrl,
                    nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>'
                },
                success: function(saveResponse) {
                    if (saveResponse.success) {
                        console.log('Production URL reset to database:', originalProductionUrl);
                    } else {
                        console.error('Failed to save reset production URL:', saveResponse.data);
                    }
                },
                error: function() {
                    console.error('Error saving reset production URL to database');
                }
            });
        }
    });
    
    // Final execute button handler
    $(document).on('click', '#final-execute', function() {
        var preview = $(this).data('preview');
        var oldUrl = $(this).data('old-url');
        var newUrl = $(this).data('new-url');
        var result = $('#url-replacement-result');
        
        $(this).prop('disabled', true).html('<span class="dashicons dashicons-update" style="font-size: 12px; width: 12px; height: 12px; display: inline-flex; align-items: center; justify-content: center; line-height: 1; vertical-align: middle; animation: spin 1s linear infinite;"></span><span style="line-height: 1; vertical-align: middle;">EXÉCUTION EN COURS...</span>');
        
        // Show progress indicator
        var progressHtml = '<div style="background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 4px; margin: 10px 0;">';
        progressHtml += '<div style="display: flex; align-items: center; margin-bottom: 10px;">';
        progressHtml += '<div class="dashicons dashicons-update" style="margin-right: 8px; font-size: 16px; animation: spin 1s linear infinite;"></div>';
        progressHtml += '<strong>Remplacement en cours...</strong>';
        progressHtml += '</div>';
        progressHtml += '<div style="background: #fff; border: 1px solid #bee5eb; border-radius: 3px; height: 20px; overflow: hidden;">';
        progressHtml += '<div id="progress-bar" style="background: linear-gradient(90deg, #0073aa 0%, #005a87 100%); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: bold;"></div>';
        progressHtml += '</div>';
        progressHtml += '<div id="progress-text" style="margin-top: 8px; font-size: 12px; color: #0c5460;">Initialisation...</div>';
        progressHtml += '</div>';
        
        result.removeClass('success error').addClass('loading').html(progressHtml).show();
        
        // Simulate progress updates (faster for better UX)
        var progressSteps = [
            { percent: 15, text: 'Analyse du contenu...' },
            { percent: 30, text: 'Traitement des posts...' },
            { percent: 45, text: 'Mise à jour des métadonnées...' },
            { percent: 60, text: 'Modification des options...' },
            { percent: 75, text: 'Mise à jour des widgets...' },
            { percent: 90, text: 'Finalisation...' },
            { percent: 100, text: 'Terminé !' }
        ];
        
        var currentStep = 0;
        var progressInterval = setInterval(function() {
            if (currentStep < progressSteps.length) {
                var step = progressSteps[currentStep];
                $('#progress-bar').css('width', step.percent + '%').text(step.percent + '%');
                $('#progress-text').text(step.text);
                currentStep++;
            } else {
                clearInterval(progressInterval);
            }
        }, 200);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'vercel_wp_preview_replace_urls',
                nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>',
                old_url: oldUrl,
                new_url: newUrl,
                preview_data: preview
            },
            success: function(response) {
                clearInterval(progressInterval);
                
                if (response.success) {
                    var message = '✅ <strong><?php echo esc_js(__('Remplacement terminé avec succès !', 'vercel-wp')); ?></strong><br><br>';
                    message += '<strong><?php echo esc_js(__('Ancienne URL :', 'vercel-wp')); ?></strong> <code>' + oldUrl + '</code><br>';
                    message += '<strong><?php echo esc_js(__('Nouvelle URL :', 'vercel-wp')); ?></strong> <code>' + newUrl + '</code><br><br>';
                    
                    if (response.data && response.data.count > 0) {
                        message += '<div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px; border-radius: 4px; margin: 10px 0;">';
                        message += '<strong>📊 Résultat:</strong> ' + response.data.count + ' éléments modifiés avec succès';
                        message += '</div>';
                    }
                    
                    // Store URLs for potential cancellation
                    result.data('old-url', oldUrl);
                    result.data('new-url', newUrl);
                    
                    // Add reverse button
                    message += '<div style="background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 12px; border-radius: 4px; margin: 10px 0; text-align: center;">';
                    message += '<strong>🔄 Revenir en arrière</strong><br>';
                    message += '<small style="display: block; margin: 8px 0;">Vous pouvez annuler cette opération en inversant les URLs</small>';
                    message += '<button type="button" id="reverse-urls" style="background: #f39c12; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 600; margin-top: 8px;">';
                    message += '<span class="dashicons dashicons-undo" style="margin-right: 6px; font-size: 14px;"></span>';
                    message += 'REVENIR EN ARRIÈRE';
                    message += '</button>';
                    message += '</div>';
                    
                    result.removeClass('loading error').addClass('success').html(message).show();
                    
                    // Update the production URL field
                    $('#production_url').val(newUrl);
                    originalProductionUrl = newUrl;
                    
                    // Save the production URL to database
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'vercel_wp_preview_save_settings',
                            production_url: newUrl,
                            nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>'
                        },
                        success: function(saveResponse) {
                            if (saveResponse.success) {
                                console.log('Production URL saved to database:', newUrl);
                            } else {
                                console.error('Failed to save production URL:', saveResponse.data);
                            }
                        },
                        error: function() {
                            console.error('Error saving production URL to database');
                        }
                    });
                    
                    // Force ACF to refresh field values
                    if (typeof acf !== 'undefined') {
                        acf.doAction('refresh');
                        console.log('ACF refresh triggered');
                    }
                    
                    // Hide summary and reset form
                    $('#url-replacement-summary').hide();
                    $('#url-preview-result').hide();
                    $('#confirm-replace-urls').hide();
                    $('#cancel-replace-urls').hide();
                    
                    // Hide the double confirmation section
                    $('#double-confirmation').fadeOut(300);
                } else {
                    result.removeClass('loading success').addClass('error').html('❌ ' + (response.data ? response.data.message : '<?php echo esc_js(__('Échec du remplacement', 'vercel-wp')); ?>')).show();
                }
            },
            error: function() {
                clearInterval(progressInterval);
                result.removeClass('loading success').addClass('error').html('❌ <?php echo esc_js(__('Erreur de communication avec le serveur', 'vercel-wp')); ?>').show();
            },
            complete: function() {
                $('#final-execute').prop('disabled', false).html('<span class="dashicons dashicons-warning" style="font-size: 12px; width: 12px; height: 12px; display: inline-flex; align-items: center; justify-content: center; line-height: 1; vertical-align: middle;"></span><span style="line-height: 1; vertical-align: middle;">OUI, EXÉCUTER</span>');
            }
        });
    });
    
    // Handle reverse button click
    $(document).on('click', '#reverse-urls', function() {
        var result = $('#url-replacement-result');
        var oldUrl = result.data('old-url');
        var newUrl = result.data('new-url');
        
        if (!oldUrl || !newUrl) {
            alert('❌ Erreur: Impossible de récupérer les URLs pour l\'annulation.');
            return;
        }
        
        // No confirmation needed - proceed directly
        
        // Disable button and show loading with better visual feedback
        var button = $(this);
        button.prop('disabled', true).html('<span class="dashicons dashicons-update" style="margin-right: 6px; font-size: 14px; animation: spin 1s linear infinite;"></span>ANNULATION EN COURS...');
        
        // Add CSS for spin animation if not already present
        if (!$('#spin-animation').length) {
            $('head').append('<style id="spin-animation">@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>');
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'vercel_wp_preview_replace_urls',
                old_url: newUrl, // Reverse the URLs
                new_url: oldUrl,
                nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>'
            },
            beforeSend: function() {
                result.html('Annulation en cours...').removeClass('success error').addClass('loading').show();
            },
                success: function(response) {
                    if (response.success) {
                        result.removeClass('loading error').addClass('success').html('✅ <strong>ANNULATION RÉUSSIE:</strong> Les URLs ont été inversées avec succès.').show();
                        
                        // Update the production URL field back to the original
                        $('#production_url').val(oldUrl);
                        originalProductionUrl = oldUrl;
                        
                        // Save the production URL to database
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'vercel_wp_preview_save_settings',
                                production_url: oldUrl,
                                nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>'
                            },
                            success: function(saveResponse) {
                                if (saveResponse.success) {
                                    console.log('Production URL reverted to database:', oldUrl);
                                } else {
                                    console.error('Failed to save reverted production URL:', saveResponse.data);
                                }
                            },
                            error: function() {
                                console.error('Error saving reverted production URL to database');
                            }
                        });
                        
                        // Clear the stored URLs
                        result.removeData('old-url').removeData('new-url');
                        
                        // Hide the reverse button
                        $('#reverse-urls').hide();
                    } else {
                        result.removeClass('loading').addClass('error').html('❌ <strong>ERREUR:</strong> ' + (response.data ? response.data.message : 'Erreur lors de l\'annulation')).show();
                    }
                },
            error: function() {
                result.removeClass('loading').addClass('error').html('❌ <strong>ERREUR:</strong> Erreur de communication avec le serveur').show();
            },
            complete: function() {
                button.prop('disabled', false).html('<span class="dashicons dashicons-undo" style="margin-right: 6px; font-size: 14px;"></span>REVENIR EN ARRIÈRE');
            }
        });
    });
    
    // Handle dynamically created abort button
    $(document).on('click', '#abort-replace-urls', function() {
        // Close the entire URL replacement section
        $('#url-replacement-section').slideUp(300, function() {
            // Clear all form fields
            $('#old_url').val('');
            $('#new_url').val('');
            
            // Hide all result sections
            $('#url-replacement-result').hide();
            $('#url-replacement-summary').hide();
            $('#url-preview-result').hide();
            
            // Hide all buttons
            $('#preview-urls').hide();
            $('#confirm-replace-urls').hide();
            $('#cancel-replace-urls').hide();
            
            // Reset the production URL field to its original value
            $('#production_url').val(originalProductionUrl);
            
            // Save the production URL to database
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'vercel_wp_preview_save_settings',
                    production_url: originalProductionUrl,
                    nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>'
                },
                success: function(saveResponse) {
                    if (saveResponse.success) {
                        console.log('Production URL reset to database:', originalProductionUrl);
                    } else {
                        console.error('Failed to save reset production URL:', saveResponse.data);
                    }
                },
                error: function() {
                    console.error('Error saving reset production URL to database');
                }
            });
            
            // Re-evaluate the URL replacement section visibility
            var currentUrl = $('#production_url').val();
            if (currentUrl !== originalProductionUrl && currentUrl.trim() !== '') {
                $('#url-replacement-section').show();
                $('#old_url').val(removeTrailingSlash(originalProductionUrl));
                $('#new_url').val(removeTrailingSlash(currentUrl));
            } else {
                $('#url-replacement-section').hide();
            }
            
            // Ensure the preview button is visible after section reopens
            setTimeout(function() {
                if ($('#url-replacement-section').is(':visible')) {
                    $('#preview-urls').show();
                }
            }, 100);
        });
    });
    
    // Show double confirmation interface
    function showDoubleConfirmation(preview, oldUrl, newUrl) {
        var summaryResult = $('#url-replacement-summary');
        var currentHtml = summaryResult.html();
        
        // Add double confirmation section
        var confirmationHtml = '<div id="double-confirmation" style="margin: 20px 0; padding: 24px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; box-shadow: 0 2px 8px rgba(255, 193, 7, 0.15);">';
        confirmationHtml += '<div style="text-align: center;">';
        confirmationHtml += '<h3 style="color: #856404; margin: 0 0 20px 0; font-size: 16px; font-weight: 600;">⚠️ CONFIRMATION FINALE</h3>';
        confirmationHtml += '<div style="background: white; padding: 20px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #e9ecef;">';
        confirmationHtml += '<div style="font-weight: 600; margin-bottom: 10px; color: #495057;">Modification de <span style="color: #dc3545; font-size: 18px;">' + preview.total_count + '</span> éléments</div>';
        confirmationHtml += '<div style="margin-bottom: 8px; font-size: 14px; color: #6c757d;"><strong>Ancienne URL:</strong> <code style="background: #f8f9fa; padding: 2px 6px; border-radius: 3px;">' + oldUrl + '</code></div>';
        confirmationHtml += '<div style="font-size: 14px; color: #6c757d;"><strong>Nouvelle URL:</strong> <code style="background: #f8f9fa; padding: 2px 6px; border-radius: 3px;">' + newUrl + '</code></div>';
        confirmationHtml += '</div>';
        confirmationHtml += '<div style="color: #856404; font-weight: 500; margin-bottom: 20px; font-size: 14px;">Êtes-vous ABSOLUMENT SÛR de vouloir continuer ?</div>';
        confirmationHtml += '<div style="background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 12px; border-radius: 4px; margin-bottom: 20px; font-size: 13px; text-align: center;">';
        confirmationHtml += '<span class="dashicons dashicons-undo" style="margin-right: 6px; font-size: 14px; vertical-align: middle;"></span>';
        confirmationHtml += '<strong>Rassurez-vous :</strong> Vous pourrez revenir en arrière à tout moment après l\'exécution grâce au bouton "Revenir en arrière" qui apparaîtra.';
        confirmationHtml += '</div>';
        confirmationHtml += '<div style="display: flex; justify-content: center; align-items: center;">';
        confirmationHtml += '<button type="button" id="final-execute" class="button button-primary" style="padding: 6px 12px; font-size: 12px; font-weight: 600; background: #dc3545; border-color: #dc3545; height: 32px; min-width: 120px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; gap: 4px; vertical-align: middle;">';
        confirmationHtml += '<span class="dashicons dashicons-warning" style="font-size: 12px; width: 12px; height: 12px; display: inline-flex; align-items: center; justify-content: center; line-height: 1; vertical-align: middle;"></span>';
        confirmationHtml += '<span style="line-height: 1; vertical-align: middle;">OUI, EXÉCUTER</span>';
        confirmationHtml += '</button>';
        confirmationHtml += '</div>';
        confirmationHtml += '</div>';
        confirmationHtml += '</div>';
        
        // Replace the buttons section with double confirmation
        summaryResult.html(currentHtml.replace(
            /<div style="text-align: center; margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">[\s\S]*?<\/div>/,
            confirmationHtml
        ));
        
        // Store data for final execution
        $('#final-execute').data('preview', preview).data('old-url', oldUrl).data('new-url', newUrl);
    }

    // Handle clicks on category counts to show details
    $(document).on('click', '.preview-count-item.clickable', function() {
        var category = $(this).data('category');
        var preview = $('#url-preview-result').data('preview');
        var detailsDiv = $('#category-details');
        
        if (!preview || !preview.details || !preview.details[category]) {
            return;
        }
        
        var details = preview.details[category];
        var categoryNames = {
            'posts': '<?php echo esc_js(__('Articles/Pages', 'vercel-wp')); ?>',
            'postmeta': '<?php echo esc_js(__('Métadonnées', 'vercel-wp')); ?>',
            'comments': '<?php echo esc_js(__('Commentaires', 'vercel-wp')); ?>',
            'options': '<?php echo esc_js(__('Options', 'vercel-wp')); ?>',
            'widgets': '<?php echo esc_js(__('Widgets', 'vercel-wp')); ?>',
            'customizer': '<?php echo esc_js(__('Personnalisation', 'vercel-wp')); ?>',
            'theme_mods': '<?php echo esc_js(__('Thème', 'vercel-wp')); ?>'
        };
        
        var detailsHtml = '<div class="category-details-content">';
        detailsHtml += '<h4 style="margin: 0 0 15px 0; color: #333; font-size: 14px; font-weight: 600;">';
        detailsHtml += '📋 <?php echo esc_js(__('Détails', 'vercel-wp')); ?> - ' + categoryNames[category] + ' (' + details.length + ' <?php echo esc_js(__('éléments', 'vercel-wp')); ?>)';
        detailsHtml += '<button type="button" class="button button-small close-details" style="float: right; margin-left: 10px;"><?php echo esc_js(__('Fermer', 'vercel-wp')); ?></button>';
        detailsHtml += '</h4>';
        
        if (details.length === 0) {
            detailsHtml += '<p style="color: #666; font-style: italic;"><?php echo esc_js(__('Aucun élément trouvé dans cette catégorie', 'vercel-wp')); ?>.</p>';
        } else {
            detailsHtml += '<div class="details-list">';
            
            details.forEach(function(item, index) {
                detailsHtml += '<div class="detail-item">';
                
                if (category === 'posts') {
                    detailsHtml += '<div style="font-weight: bold; color: #333; margin-bottom: 5px;">' + item.title + '</div>';
                    detailsHtml += '<div style="font-size: 12px; color: #666; margin-bottom: 8px;">ID: ' + item.id + ' | <?php echo esc_js(__('Type', 'vercel-wp')); ?>: ' + item.type + '</div>';
                    if (item.content_preview) {
                        detailsHtml += '<div style="font-size: 12px; color: #555; background: #f0f0f0; padding: 5px; border-radius: 3px; margin-bottom: 5px;">';
                        detailsHtml += '<strong><?php echo esc_js(__('Contenu', 'vercel-wp')); ?>:</strong> ' + item.content_preview;
                        detailsHtml += '</div>';
                    }
                    if (item.excerpt_preview) {
                        detailsHtml += '<div style="font-size: 12px; color: #555; background: #f0f0f0; padding: 5px; border-radius: 3px;">';
                        detailsHtml += '<strong><?php echo esc_js(__('Extrait', 'vercel-wp')); ?>:</strong> ' + item.excerpt_preview;
                        detailsHtml += '</div>';
                    }
                } else if (category === 'postmeta') {
                    detailsHtml += '<div style="font-weight: bold; color: #333; margin-bottom: 5px;">' + item.post_title + '</div>';
                    detailsHtml += '<div style="font-size: 12px; color: #666; margin-bottom: 8px;">';
                    detailsHtml += 'ID: ' + item.post_id + ' | <?php echo esc_js(__('Type', 'vercel-wp')); ?>: ' + item.post_type + ' | <?php echo esc_js(__('Champ', 'vercel-wp')); ?>: ' + item.meta_key;
                    if (item.is_acf) {
                        detailsHtml += ' <span style="background: #0073aa; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px;">ACF</span>';
                    }
                    detailsHtml += '</div>';
                    
                    if (item.found_urls && item.found_urls.length > 0) {
                        item.found_urls.forEach(function(urlPreview) {
                            detailsHtml += '<div style="font-size: 12px; color: #555; background: #f0f0f0; padding: 5px; border-radius: 3px; margin-bottom: 5px;">';
                            detailsHtml += '<strong><?php echo esc_js(__('URL trouvée', 'vercel-wp')); ?>:</strong> ' + urlPreview;
                            detailsHtml += '</div>';
                        });
                    }
                } else if (category === 'comments') {
                    detailsHtml += '<div style="font-weight: bold; color: #333; margin-bottom: 5px;"><?php echo esc_js(__('Commentaire', 'vercel-wp')); ?> #' + item.id + '</div>';
                    detailsHtml += '<div style="font-size: 12px; color: #666; margin-bottom: 8px;"><?php echo esc_js(__('Auteur', 'vercel-wp')); ?>: ' + item.author + '</div>';
                    if (item.content_preview) {
                        detailsHtml += '<div style="font-size: 12px; color: #555; background: #f0f0f0; padding: 5px; border-radius: 3px;">';
                        detailsHtml += '<strong><?php echo esc_js(__('Contenu', 'vercel-wp')); ?>:</strong> ' + item.content_preview;
                        detailsHtml += '</div>';
                    }
                } else if (category === 'options') {
                    detailsHtml += '<div style="font-weight: bold; color: #333; margin-bottom: 5px;">' + item.name + '</div>';
                    detailsHtml += '<div style="font-size: 12px; color: #666; margin-bottom: 8px;">ID: ' + item.id + '</div>';
                    if (item.value_preview) {
                        detailsHtml += '<div style="font-size: 12px; color: #555; background: #f0f0f0; padding: 5px; border-radius: 3px;">';
                        detailsHtml += '<strong><?php echo esc_js(__('Valeur', 'vercel-wp')); ?>:</strong> ' + item.value_preview;
                        detailsHtml += '</div>';
                    }
                } else if (category === 'widgets') {
                    detailsHtml += '<div style="font-weight: bold; color: #333; margin-bottom: 5px;"><?php echo esc_js(__('Widget', 'vercel-wp')); ?>: ' + item.name + '</div>';
                    detailsHtml += '<div style="font-size: 12px; color: #666; margin-bottom: 8px;"><?php echo esc_js(__('Type', 'vercel-wp')); ?>: ' + item.type + '</div>';
                    if (item.content_preview) {
                        detailsHtml += '<div style="font-size: 12px; color: #555; background: #f0f0f0; padding: 5px; border-radius: 3px;">';
                        detailsHtml += '<strong><?php echo esc_js(__('Contenu', 'vercel-wp')); ?>:</strong> ' + item.content_preview;
                        detailsHtml += '</div>';
                    }
                } else if (category === 'customizer') {
                    detailsHtml += '<div style="font-weight: bold; color: #333; margin-bottom: 5px;"><?php echo esc_js(__('Personnalisation', 'vercel-wp')); ?>: ' + item.name + '</div>';
                    detailsHtml += '<div style="font-size: 12px; color: #666; margin-bottom: 8px;"><?php echo esc_js(__('Section', 'vercel-wp')); ?>: ' + item.section + '</div>';
                    if (item.value_preview) {
                        detailsHtml += '<div style="font-size: 12px; color: #555; background: #f0f0f0; padding: 5px; border-radius: 3px;">';
                        detailsHtml += '<strong><?php echo esc_js(__('Valeur', 'vercel-wp')); ?>:</strong> ' + item.value_preview;
                        detailsHtml += '</div>';
                    }
                } else if (category === 'theme_mods') {
                    detailsHtml += '<div style="font-weight: bold; color: #333; margin-bottom: 5px;"><?php echo esc_js(__('Thème', 'vercel-wp')); ?>: ' + item.name + '</div>';
                    if (item.value_preview) {
                        detailsHtml += '<div style="font-size: 12px; color: #555; background: #f0f0f0; padding: 5px; border-radius: 3px;">';
                        detailsHtml += '<strong><?php echo esc_js(__('Valeur', 'vercel-wp')); ?>:</strong> ' + item.value_preview;
                        detailsHtml += '</div>';
                    }
                }
                
                detailsHtml += '</div>';
            });
            
            detailsHtml += '</div>';
        }
        
        detailsHtml += '</div>';
        
        detailsDiv.html(detailsHtml).show();
    });
    
    // Handle clicks on close details button
    $(document).on('click', '.close-details', function() {
        $('#category-details').hide();
    });

    // Execute replacement
    $(document).on('click', '#execute-replace-urls', function() {
        var preview = $('#confirm-replace-urls').data('preview');
        var oldUrl = removeTrailingSlash($('#confirm-replace-urls').data('old-url'));
        var newUrl = removeTrailingSlash($('#confirm-replace-urls').data('new-url'));
        
        // Show double confirmation interface
        showDoubleConfirmation(preview, oldUrl, newUrl);
        return;
        
        var result = $('#url-replacement-result');
        
        // Show progress indicator
        var progressHtml = '<div style="background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 4px; margin: 10px 0;">';
        progressHtml += '<div style="display: flex; align-items: center; margin-bottom: 10px;">';
        progressHtml += '<div class="dashicons dashicons-update" style="margin-right: 8px; font-size: 16px; animation: spin 1s linear infinite;"></div>';
        progressHtml += '<strong><?php echo esc_js(__('Remplacement en cours...', 'vercel-wp')); ?></strong>';
        progressHtml += '</div>';
        progressHtml += '<div style="background: #fff; border: 1px solid #bee5eb; border-radius: 3px; height: 20px; overflow: hidden;">';
        progressHtml += '<div id="progress-bar" style="background: linear-gradient(90deg, #0073aa 0%, #005a87 100%); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: bold;"></div>';
        progressHtml += '</div>';
        progressHtml += '<div id="progress-text" style="margin-top: 8px; font-size: 12px; color: #0c5460;"><?php echo esc_js(__('Initialisation...', 'vercel-wp')); ?></div>';
        progressHtml += '</div>';
        
        result.removeClass('success error').addClass('loading').html(progressHtml).show();
        
        // Simulate progress updates
        var progressSteps = [
            { percent: 15, text: '<?php echo esc_js(__('Analyse du contenu...', 'vercel-wp')); ?>' },
            { percent: 35, text: '<?php echo esc_js(__('Remplacement des URLs...', 'vercel-wp')); ?>' },
            { percent: 60, text: '<?php echo esc_js(__('Correction des champs ACF...', 'vercel-wp')); ?>' },
            { percent: 85, text: '<?php echo esc_js(__('Vidage des caches...', 'vercel-wp')); ?>' },
            { percent: 100, text: '<?php echo esc_js(__('Finalisation...', 'vercel-wp')); ?>' }
        ];
        
        var currentStep = 0;
        var progressInterval = setInterval(function() {
            if (currentStep < progressSteps.length) {
                var step = progressSteps[currentStep];
                $('#progress-bar').css('width', step.percent + '%').text(step.percent + '%');
                $('#progress-text').text(step.text);
                currentStep++;
            }
        }, 1500);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'vercel_wp_preview_replace_urls',
                nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>',
                old_url: oldUrl,
                new_url: newUrl
            },
            success: function(response) {
                clearInterval(progressInterval);
                
                if (response.success) {
                    var message = '✅ <strong><?php echo esc_js(__('Remplacement terminé avec succès !', 'vercel-wp')); ?></strong><br><br>';
                    message += '<strong><?php echo esc_js(__('Ancienne URL :', 'vercel-wp')); ?></strong> <code>' + oldUrl + '</code><br>';
                    message += '<strong><?php echo esc_js(__('Nouvelle URL :', 'vercel-wp')); ?></strong> <code>' + newUrl + '</code><br><br>';
                    
                    if (response.data && response.data.count > 0) {
                        message += '<div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px; border-radius: 4px; margin: 10px 0;">';
                        message += '<strong>📊 <?php echo esc_js(__('Résultat :', 'vercel-wp')); ?></strong> ' + response.data.count + ' <?php echo esc_js(__('éléments modifiés avec succès', 'vercel-wp')); ?>';
                        message += '</div>';
                        
                        if (response.data.details && Object.keys(response.data.details).length > 0) {
                            message += '<div style="background: #f8f9fa; border: 1px solid #e9ecef; color: #495057; padding: 12px; border-radius: 4px; margin: 10px 0;">';
                            message += '<h5 style="margin: 0 0 10px 0; color: #495057; font-size: 13px; font-weight: 600;">';
                            message += '<span class="dashicons dashicons-list-view" style="margin-right: 6px; font-size: 14px;"></span>';
                            message += '<?php echo esc_js(__('Détails des modifications :', 'vercel-wp')); ?>';
                            message += '</h5>';
                            message += '<div style="max-height: 300px; overflow-y: auto; font-size: 12px; line-height: 1.4;">';
                            
                            Object.keys(response.data.details).forEach(function(table) {
                                var count = response.data.details[table];
                                if (count > 0) {
                                    message += '<div style="margin-bottom: 8px; padding: 6px; background: #fff; border-radius: 3px; border-left: 3px solid #0073aa;">';
                                    message += '<strong>' + table + ':</strong> ' + count + ' <?php echo esc_js(__('éléments', 'vercel-wp')); ?>';
                                    message += '</div>';
                                }
                            });
                            
                            message += '</div>';
                            message += '</div>';
                        }
                    }
                    
                    result.removeClass('loading error').addClass('success').html(message).show();
                    
                    // Store URLs for potential cancellation
                    result.data('old-url', oldUrl);
                    result.data('new-url', newUrl);
                    
                    // Add reverse button
                    message += '<div style="background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 12px; border-radius: 4px; margin: 10px 0; text-align: center;">';
                    message += '<strong>🔄 Revenir en arrière</strong><br>';
                    message += '<small style="display: block; margin: 8px 0;">Vous pouvez annuler cette opération en inversant les URLs</small>';
                    message += '<button type="button" id="reverse-urls" style="background: #f39c12; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 600; margin-top: 8px;">';
                    message += '<span class="dashicons dashicons-undo" style="margin-right: 6px; font-size: 14px;"></span>';
                    message += 'REVENIR EN ARRIÈRE';
                    message += '</button>';
                    message += '</div>';
                    
                    result.removeClass('loading error').addClass('success').html(message).show();
                    
                    // Update the production URL field
                    $('#production_url').val(newUrl);
                    originalProductionUrl = newUrl;
                    
                    // Save the production URL to database
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'vercel_wp_preview_save_settings',
                            production_url: newUrl,
                            nonce: '<?php echo wp_create_nonce('vercel_wp_preview_nonce'); ?>'
                        },
                        success: function(saveResponse) {
                            if (saveResponse.success) {
                                console.log('Production URL saved to database:', newUrl);
                            } else {
                                console.error('Failed to save production URL:', saveResponse.data);
                            }
                        },
                        error: function() {
                            console.error('Error saving production URL to database');
                        }
                    });
                    
                    // Force ACF to refresh field values
                    if (typeof acf !== 'undefined') {
                        acf.doAction('refresh');
                        console.log('ACF refresh triggered');
                    }
                    
                    // Hide summary and reset form
                    $('#url-replacement-summary').hide();
                    $('#url-preview-result').hide();
                    $('#confirm-replace-urls').hide();
                    $('#cancel-replace-urls').hide();
                    
                    // Hide the double confirmation section
                    $('#double-confirmation').fadeOut(300);
                } else {
                    result.removeClass('loading success').addClass('error').html('❌ ' + (response.data ? response.data.message : '<?php echo esc_js(__('Échec du remplacement', 'vercel-wp')); ?>')).show();
                }
            },
            error: function() {
                clearInterval(progressInterval);
                result.removeClass('loading success').addClass('error').html('❌ <?php echo esc_js(__('Erreur lors du remplacement', 'vercel-wp')); ?>').show();
            }
        });
    });
    
    // Abort replacement
    $(document).on('click', '#abort-replace-urls', function() {
        $('#url-replacement-summary').hide();
        $('#url-preview-result').hide();
        $('#confirm-replace-urls').hide();
        $('#cancel-replace-urls').hide();
    });
});
</script>
