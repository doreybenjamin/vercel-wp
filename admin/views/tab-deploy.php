<?php
/**
 * Vercel WP - Deploy Tab View
 * 
 * from wp-webhook-vercel-deploy
 * 
 * @package VercelWP
 * @since 2.0.0
 */

defined('ABSPATH') or die('Access denied');

// Get the Deploy Admin instance
$deploy_admin = VercelWP_Deploy_Plugin::get_instance()->get_admin_handler();
?>

<div class="vercel-deploy-layout" style="display: flex; gap: 20px; margin-top: 20px; padding-right: 20px; box-sizing: border-box;">
    <!-- Main Content (70%) -->
    <div class="vercel-deploy-main" style="flex: 1; max-width: 70%;">
        <?php $deploy_admin->render_main_page_content(); ?>
        
        <hr style="margin: 40px 0;">
        
        <?php $deploy_admin->render_settings_content(); ?>
    </div>
    
    <!-- Sidebar (30%) -->
    <div class="vercel-deploy-sidebar" style="flex: 0 0 30%; max-width: 30%;">
        <?php $deploy_admin->render_configuration_guide(); ?>
    </div>
</div>

<style>
.vercel-deploy-layout {
    padding-right: 20px;
    box-sizing: border-box;
}

@media (max-width: 1280px) {
    .vercel-deploy-layout {
        flex-direction: column !important;
    }
    .vercel-deploy-main,
    .vercel-deploy-sidebar {
        max-width: 100% !important;
    }
}
</style>
