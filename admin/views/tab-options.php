<?php
/**
 * Vercel WP - Options Tab View
 *
 * @package VercelWP
 * @since 2.1.0
 */

defined('ABSPATH') or die('Access denied');

$settings = get_option('vercel_wp_preview_settings', array(
    'auto_refresh' => true,
    'show_button_admin_bar' => true,
    'show_button_editor' => true,
    'disable_theme_page' => true,
    'headless_show_menus_menu' => true,
));

$show_headless_menus_menu = !empty($settings['headless_show_menus_menu']);
$can_access_menus = current_user_can('edit_theme_options');
$can_access_functions = current_user_can('edit_themes');
$functions_admin_url = add_query_arg('file', 'functions.php', admin_url('theme-editor.php'));
$notice_key = isset($_GET['vercel_wp_options_notice']) ? sanitize_key(wp_unslash($_GET['vercel_wp_options_notice'])) : '';
$client_redirect_url = '';

$normalize_custom_templates = static function ($templates) {
    if (!is_array($templates)) {
        return array();
    }

    $normalized = array();

    foreach ($templates as $template_file => $template_data) {
        $template_file = sanitize_file_name((string) $template_file);
        if ($template_file === '') {
            continue;
        }

        if (!is_array($template_data)) {
            $template_data = array();
        }

        $template_name = isset($template_data['name']) ? sanitize_text_field($template_data['name']) : '';
        if ($template_name === '') {
            $template_name = ucfirst(trim(str_replace(array('vercel-wp-template-', 'template-', '.php', '-', '_'), array('', '', '', ' ', ' '), $template_file)));
        }

        $template_slug = isset($template_data['slug']) ? sanitize_title($template_data['slug']) : '';
        if ($template_slug === '') {
            $template_slug = sanitize_title(str_replace(array('vercel-wp-template-', 'template-', '.php'), '', $template_file));
        }

        $template_description = isset($template_data['description']) ? sanitize_text_field($template_data['description']) : '';

        $normalized[$template_file] = array(
            'name' => $template_name,
            'slug' => $template_slug,
            'description' => $template_description,
        );
    }

    uksort($normalized, 'strnatcasecmp');

    return $normalized;
};

$custom_templates = $normalize_custom_templates(get_option('vercel_wp_custom_page_templates', array()));

if (isset($_POST['submit_options']) && isset($_POST['vercel_wp_options_nonce'])) {
    if (!current_user_can('manage_options')) {
        wp_die(__('Permission refusée', 'vercel-wp'));
    }

    if (wp_verify_nonce($_POST['vercel_wp_options_nonce'], 'vercel_wp_options')) {
        $settings['auto_refresh'] = isset($_POST['auto_refresh']);
        $settings['show_button_admin_bar'] = isset($_POST['show_button_admin_bar']);
        $settings['show_button_editor'] = isset($_POST['show_button_editor']);
        $settings['disable_theme_page'] = isset($_POST['disable_theme_page']);
        $settings['headless_show_menus_menu'] = isset($_POST['headless_show_menus_menu']);

        update_option('vercel_wp_preview_settings', $settings);

        $page_slug = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'vercel-wp-options';
        $redirect_url = add_query_arg(
            array(
                'page' => $page_slug,
                'vercel_wp_options_notice' => 'settings_saved',
            ),
            admin_url('admin.php')
        );

        if (!headers_sent()) {
            wp_safe_redirect($redirect_url);
            exit;
        }

        $client_redirect_url = $redirect_url;
        $notice_key = 'settings_saved';
    }
}

if (isset($_POST['create_custom_template']) && isset($_POST['vercel_wp_template_nonce'])) {
    if (!current_user_can('manage_options')) {
        wp_die(__('Permission refusée', 'vercel-wp'));
    }

    if (wp_verify_nonce($_POST['vercel_wp_template_nonce'], 'vercel_wp_manage_templates')) {
        $template_name = isset($_POST['template_name']) ? sanitize_text_field(wp_unslash($_POST['template_name'])) : '';

        if ($template_name === '') {
            $notice_key = 'template_missing_name';
        } else {
            $template_slug = sanitize_title($template_name);
            if ($template_slug === '') {
                $notice_key = 'template_invalid_slug';
            } else {
                $template_file = 'template-' . $template_slug . '.php';
                $legacy_template_file = 'vercel-wp-template-' . $template_slug . '.php';
                $theme_templates = wp_get_theme()->get_page_templates(null, 'page');
                $theme_template_files = is_array($theme_templates) ? array_values($theme_templates) : array();

                if (
                    isset($custom_templates[$template_file]) ||
                    isset($custom_templates[$legacy_template_file]) ||
                    in_array($template_file, $theme_template_files, true) ||
                    in_array($legacy_template_file, $theme_template_files, true)
                ) {
                    $notice_key = 'template_slug_exists';
                } else {
                    $custom_templates[$template_file] = array(
                        'name' => $template_name,
                        'slug' => $template_slug,
                        'description' => '',
                    );

                    $custom_templates = $normalize_custom_templates($custom_templates);
                    update_option('vercel_wp_custom_page_templates', $custom_templates);

                    $page_slug = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'vercel-wp-options';
                    $redirect_url = add_query_arg(
                        array(
                            'page' => $page_slug,
                            'vercel_wp_options_notice' => 'template_created',
                        ),
                        admin_url('admin.php')
                    );

                    if (!headers_sent()) {
                        wp_safe_redirect($redirect_url);
                        exit;
                    }

                    $client_redirect_url = $redirect_url;
                    $notice_key = 'template_created';
                }
            }
        }
    } else {
        $notice_key = 'template_nonce_failed';
    }
}

if (isset($_POST['delete_custom_template']) && isset($_POST['vercel_wp_template_nonce'])) {
    if (!current_user_can('manage_options')) {
        wp_die(__('Permission refusée', 'vercel-wp'));
    }

    if (wp_verify_nonce($_POST['vercel_wp_template_nonce'], 'vercel_wp_manage_templates')) {
        $template_file = isset($_POST['template_file']) ? sanitize_file_name(wp_unslash($_POST['template_file'])) : '';

        if ($template_file !== '' && isset($custom_templates[$template_file])) {
            unset($custom_templates[$template_file]);
            update_option('vercel_wp_custom_page_templates', $custom_templates);

            $page_slug = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'vercel-wp-options';
            $redirect_url = add_query_arg(
                array(
                    'page' => $page_slug,
                    'vercel_wp_options_notice' => 'template_deleted',
                ),
                admin_url('admin.php')
            );

            if (!headers_sent()) {
                wp_safe_redirect($redirect_url);
                exit;
            }

            $client_redirect_url = $redirect_url;
            $notice_key = 'template_deleted';
        }
    } else {
        $notice_key = 'template_nonce_failed';
    }
}

$available_templates = wp_get_theme()->get_page_templates(null, 'page');
if (!is_array($available_templates)) {
    $available_templates = array();
}
asort($available_templates, SORT_NATURAL | SORT_FLAG_CASE);

$display_templates = array();
foreach ($available_templates as $template_key => $template_value) {
    $key_is_file = (is_string($template_key) && preg_match('/\.php$/i', $template_key));
    $value_is_file = (is_string($template_value) && preg_match('/\.php$/i', $template_value));

    if ($key_is_file && !$value_is_file) {
        $template_file = (string) $template_key;
        $template_name = (string) $template_value;
    } elseif (!$key_is_file && $value_is_file) {
        $template_file = (string) $template_value;
        $template_name = (string) $template_key;
    } else {
        $template_file = $key_is_file ? (string) $template_key : (string) $template_value;
        $template_name = $key_is_file ? (string) $template_value : (string) $template_key;
    }

    $template_file = sanitize_file_name($template_file);
    if ($template_file === '' || $template_file === 'default') {
        continue;
    }

    $is_plugin_template = isset($custom_templates[$template_file]);

    if ($is_plugin_template && !empty($custom_templates[$template_file]['name'])) {
        $template_name = $custom_templates[$template_file]['name'];
    }

    if ($template_name === '') {
        $template_name = ucfirst(trim(str_replace(array('template-', '.php', '-', '_'), array('', '', ' ', ' '), $template_file)));
    }

    $display_templates[$template_file] = array(
        'name' => $template_name,
        'source' => $is_plugin_template ? 'plugin' : 'theme',
    );
}

foreach ($custom_templates as $template_file => $template_data) {
    if (!isset($display_templates[$template_file])) {
        $display_templates[$template_file] = array(
            'name' => $template_data['name'],
            'source' => 'plugin',
        );
    }
}

ksort($display_templates, SORT_NATURAL | SORT_FLAG_CASE);

?>

<?php if (!empty($client_redirect_url)) : ?>
    <script>
        window.location.replace(<?php echo wp_json_encode($client_redirect_url); ?>);
    </script>
<?php endif; ?>

<?php
$notice_message = '';
$notice_type = 'success';

if ($notice_key === 'settings_saved') {
    $notice_message = __('Options enregistrées avec succès !', 'vercel-wp');
} elseif ($notice_key === 'template_created') {
    $notice_message = __('Template créé avec succès.', 'vercel-wp');
} elseif ($notice_key === 'template_deleted') {
    $notice_message = __('Template supprimé avec succès.', 'vercel-wp');
} elseif ($notice_key === 'template_missing_name') {
    $notice_type = 'error';
    $notice_message = __('Le nom du template est requis.', 'vercel-wp');
} elseif ($notice_key === 'template_invalid_slug') {
    $notice_type = 'error';
    $notice_message = __('Impossible de générer un identifiant valide pour ce template.', 'vercel-wp');
} elseif ($notice_key === 'template_slug_exists') {
    $notice_type = 'error';
    $notice_message = __('Un template avec cet identifiant existe déjà.', 'vercel-wp');
} elseif ($notice_key === 'template_nonce_failed') {
    $notice_type = 'error';
    $notice_message = __('Erreur de sécurité, merci de réessayer.', 'vercel-wp');
}
?>

<?php if (!empty($notice_message)) : ?>
    <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible">
        <p><?php echo esc_html($notice_message); ?></p>
    </div>
<?php endif; ?>

<div class="vercel-options-layout">
    <div class="vercel-options-main">
        <h2><?php _e('Options globales', 'vercel-wp'); ?></h2>
        <p><?php _e('Paramètres d’affichage admin et comportements headless WordPress.', 'vercel-wp'); ?></p>

        <form method="post" action="">
            <?php wp_nonce_field('vercel_wp_options', 'vercel_wp_options_nonce'); ?>

            <div class="vercel-options-card">
                <h3><?php _e('Options d’affichage', 'vercel-wp'); ?></h3>
                <fieldset>
                    <label>
                        <input type="checkbox" name="show_button_admin_bar" value="1" <?php checked(!empty($settings['show_button_admin_bar']), true); ?> />
                        <?php _e('Afficher le bouton de preview dans la barre d’administration', 'vercel-wp'); ?>
                    </label>
                    <label>
                        <input type="checkbox" name="show_button_editor" value="1" <?php checked(!empty($settings['show_button_editor']), true); ?> />
                        <?php _e('Afficher les boutons de preview dans l’éditeur', 'vercel-wp'); ?>
                    </label>
                    <label>
                        <input type="checkbox" name="auto_refresh" value="1" <?php checked(!empty($settings['auto_refresh']), true); ?> />
                        <?php _e('Activer l’actualisation automatique de la preview', 'vercel-wp'); ?>
                    </label>
                </fieldset>
            </div>

            <div class="vercel-options-card">
                <h3><?php _e('Options headless', 'vercel-wp'); ?></h3>
                <fieldset>
                    <label>
                        <input type="checkbox" name="disable_theme_page" value="1" <?php checked(!empty($settings['disable_theme_page']), true); ?> />
                        <?php _e('Désactiver la page des thèmes WordPress (configuration headless)', 'vercel-wp'); ?>
                    </label>
                    <p class="description">
                        <?php _e('Quand activé, la page Apparence → Thèmes sera masquée et redirigée.', 'vercel-wp'); ?>
                    </p>

                    <label>
                        <input type="checkbox" name="headless_show_menus_menu" value="1" <?php checked($show_headless_menus_menu, true); ?> />
                        <?php _e('Afficher "Menus" dans la barre latérale admin', 'vercel-wp'); ?>
                    </label>
                </fieldset>

                <div class="vercel-options-quick-links">
                    <strong><?php _e('Accès rapides', 'vercel-wp'); ?></strong>
                    <p class="description">
                        <?php _e('Menus s’affiche dans la barre latérale si activé ci-dessus. Le bouton ci-dessous ouvre functions.php.', 'vercel-wp'); ?>
                    </p>
                    <div class="vercel-options-link-row">
                        <?php if ($can_access_functions) : ?>
                            <a class="button button-secondary" href="<?php echo esc_url($functions_admin_url); ?>">
                                <?php _e('Ouvrir functions.php', 'vercel-wp'); ?>
                            </a>
                        <?php endif; ?>
                    </div>

                    <?php if ($show_headless_menus_menu && $can_access_menus) : ?>
                        <p class="description vercel-options-inline-note">
                            <?php _e('L’entrée "Menus" est disponible dans la barre latérale admin.', 'vercel-wp'); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <?php submit_button(__('Enregistrer les options', 'vercel-wp'), 'primary', 'submit_options'); ?>
        </form>

        <form method="post" action="">
            <?php wp_nonce_field('vercel_wp_manage_templates', 'vercel_wp_template_nonce'); ?>

            <div class="vercel-options-card vercel-template-create-card">
                <h3><?php _e('Créer un template de page', 'vercel-wp'); ?></h3>
                <p class="description">
                    <?php _e('Ce template apparaîtra ensuite dans "Attributs de page > Modèle" lors de l’édition d’une page.', 'vercel-wp'); ?>
                </p>

                <div class="vercel-template-form-grid">
                    <div class="vercel-template-field">
                        <label for="template_name"><?php _e('Nom du template', 'vercel-wp'); ?></label>
                        <input type="text" id="template_name" name="template_name" class="regular-text" required />
                    </div>
                </div>

                <div class="vercel-template-actions">
                    <?php submit_button(__('Créer le template', 'vercel-wp'), 'secondary', 'create_custom_template', false); ?>
                </div>
            </div>
        </form>

        <div class="vercel-options-card">
            <h3><?php _e('Liste des templates disponibles', 'vercel-wp'); ?></h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php _e('Nom', 'vercel-wp'); ?></th>
                        <th><?php _e('Identifiant fichier', 'vercel-wp'); ?></th>
                        <th><?php _e('Source', 'vercel-wp'); ?></th>
                        <th class="vercel-template-action-col"><?php _e('Action', 'vercel-wp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php _e('Template WordPress par défaut', 'vercel-wp'); ?></strong></td>
                        <td><code>default</code></td>
                        <td><?php _e('WordPress', 'vercel-wp'); ?></td>
                        <td>—</td>
                    </tr>
                    <?php if (!empty($display_templates)) : ?>
                        <?php foreach ($display_templates as $template_file => $template_item) : ?>
                            <?php $is_plugin_template = ($template_item['source'] === 'plugin'); ?>
                            <tr>
                                <td><strong><?php echo esc_html($template_item['name']); ?></strong></td>
                                <td><code><?php echo esc_html($template_file); ?></code></td>
                                <td><?php echo $is_plugin_template ? esc_html__('Plugin Vercel WP', 'vercel-wp') : esc_html__('Thème actif', 'vercel-wp'); ?></td>
                                <td class="vercel-template-action-cell">
                                    <?php if ($is_plugin_template) : ?>
                                        <form method="post" action="" class="vercel-template-delete-form" onsubmit="return confirm('<?php echo esc_js(__('Confirmer la suppression de ce template ?', 'vercel-wp')); ?>');">
                                            <?php wp_nonce_field('vercel_wp_manage_templates', 'vercel_wp_template_nonce'); ?>
                                            <input type="hidden" name="template_file" value="<?php echo esc_attr($template_file); ?>" />
                                            <button type="submit" name="delete_custom_template" class="button button-link-delete"><?php _e('Supprimer', 'vercel-wp'); ?></button>
                                        </form>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="4"><?php _e('Aucun template personnalisé détecté.', 'vercel-wp'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
    .vercel-options-layout {
        display: flex;
        gap: 20px;
        margin-top: 20px;
        padding-right: 20px;
        box-sizing: border-box;
    }
    .vercel-options-main {
        flex: 1;
        max-width: 100%;
    }
    .vercel-options-card,
    .vercel-options-widget {
        background: #fff;
        border: 1px solid #dcdcde;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
        margin-bottom: 16px;
    }
    .vercel-options-card h3,
    .vercel-options-widget h3 {
        margin: 0 0 12px;
    }
    .vercel-options-card fieldset {
        display: grid;
        gap: 10px;
        margin: 0;
    }
    .vercel-options-quick-links {
        margin-top: 14px;
        padding: 12px;
        background: #f6f7f7;
        border: 1px solid #e2e4e7;
        border-radius: 6px;
    }
    .vercel-options-link-row {
        margin-top: 8px;
    }
    .vercel-options-inline-note {
        margin: 8px 0 0;
    }

    .vercel-template-create-card .description {
        margin-bottom: 16px;
    }
    .vercel-template-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(220px, 1fr));
        gap: 14px;
    }
    .vercel-template-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .vercel-template-field label {
        font-weight: 600;
    }
    .vercel-template-field .regular-text {
        width: 100%;
        max-width: 100%;
    }
    .vercel-template-actions {
        margin-top: 14px;
    }
    .vercel-template-actions .button {
        min-width: 180px;
    }
    .vercel-template-action-col {
        width: 120px;
    }
    .vercel-template-action-cell {
        white-space: nowrap;
    }
    .vercel-template-delete-form {
        margin: 0;
    }
    @media (max-width: 960px) {
        .vercel-template-form-grid {
            grid-template-columns: 1fr;
        }
    }
</style>
