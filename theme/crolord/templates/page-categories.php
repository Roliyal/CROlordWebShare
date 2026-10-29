<?php
/* Template Name: Boughs */
if (!defined('ABSPATH')) exit;
get_header();
$key = fieldnotes_room_context();
if ($key && $key !== 'works') { fieldnotes_render_room($key); get_footer(); return; }
?>
<main id="main-content" class="page-shell boughs-page boughs-index boughs-unified<?php echo fieldnotes_work_kind() === 'notes' ? ' is-notes' : ''; ?>">
    <header class="boughs-index-heading"><h1><?php echo esc_html(fieldnotes_section_heading('categories')); ?></h1></header>
    <?php while (have_posts()) : the_post(); if (trim(get_the_content())) : ?><div class="boughs-custom-copy"><?php the_content(); ?></div><?php endif; endwhile; ?>
    <?php get_template_part('template-parts/collections/wordpress'); ?>
</main>
<?php get_footer(); ?>
