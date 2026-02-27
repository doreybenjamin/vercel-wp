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
?>

<?php if (!empty($client_redirect_url)) : ?>
    <script>
        window.location.replace(<?php echo wp_json_encode($client_redirect_url); ?>);
    </script>
<?php endif; ?>

<?php if ($notice_key === 'settings_saved') : ?>
    <div class="notice notice-success is-dismissible">
        <p><?php _e('Options enregistrées avec succès !', 'vercel-wp'); ?></p>
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
    </div>

</div>

<style>
    .vercel-options-layout {
        display: flex;
        gap: 20px;
        margin-top: 20px;
    }
    .vercel-options-main {
        flex: 1;
        max-width: 70%;
    }
    .vercel-options-sidebar {
        flex: 0 0 30%;
        max-width: 30%;
    }
    .vercel-options-card,
    .vercel-options-widget {
        background: #fff;
        border: 1px solid #dcdcde;
        border-radius: 8px;
        padding: 18px;
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
    @media (max-width: 1280px) {
        .vercel-options-layout {
            flex-direction: column;
        }
        .vercel-options-main,
        .vercel-options-sidebar {
            max-width: 100%;
        }
    }
</style>
