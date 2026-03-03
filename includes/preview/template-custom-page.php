<?php
/**
 * Generic renderer for plugin-defined custom page templates.
 *
 * @package VercelWP
 */

defined('ABSPATH') or die('Access denied');

$fallback_template = locate_template(array('page.php', 'singular.php', 'index.php'), false, false);
if (!empty($fallback_template) && file_exists($fallback_template)) {
    include $fallback_template;
    return;
}

get_header();
?>
<main id="primary" class="site-main">
    <?php while (have_posts()) : the_post(); ?>
        <?php the_content(); ?>
    <?php endwhile; ?>
</main>
<?php
get_footer();
