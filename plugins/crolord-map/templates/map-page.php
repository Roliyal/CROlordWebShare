<?php
/** Fieldnotes Journal atlas layout, supplied by the map plugin. */
if (!defined('ABSPATH')) exit;
get_header(); ?>
<main id="main-content" class="page-shell fntm-page-shell">
    <?php while (have_posts()) : the_post(); ?>
        <?php if (has_shortcode(get_the_content(), 'fieldnotes_travel_map')) : ?>
            <h1 class="fntm-sr-only"><?php the_title(); ?></h1>
            <div class="fntm-page-content"><?php the_content(); ?></div>
        <?php else : ?>
            <?php echo do_shortcode('[fieldnotes_travel_map heading_level="1"]'); ?>
            <?php if (trim(get_the_content())) : ?><div class="fntm-page-extra"><?php the_content(); ?></div><?php endif; ?>
        <?php endif; ?>
    <?php endwhile; ?>
</main>
<?php get_footer(); ?>
