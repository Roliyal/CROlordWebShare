<?php
/* Template Name: Wayfarer */
if (!defined('ABSPATH')) exit;
get_header(); ?>
<main id="main-content" class="page-shell">
    <?php if (shortcode_exists('fieldnotes_travel_map')) : ?><?php echo do_shortcode('[fieldnotes_travel_map heading_level="1"]'); ?>
    <?php elseif (shortcode_exists('marker_pro_map')) : ?><h1 class="page-title"><?php echo esc_html(fieldnotes_section_heading('map')); ?></h1><?php echo do_shortcode('[marker_pro_map]'); ?>
    <?php else : ?><h1 class="page-title"><?php echo esc_html(fieldnotes_section_heading('map')); ?></h1><p>请启用 Map 插件。</p><?php endif; ?>
    <?php while (have_posts()) : the_post(); if (trim(get_the_content()) && !has_shortcode(get_the_content(), 'fieldnotes_travel_map')) : ?><div class="map-page-content"><?php the_content(); ?></div><?php endif; endwhile; ?>
</main>
<?php get_footer(); ?>
