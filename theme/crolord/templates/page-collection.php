<?php
/* Template Name: Independent collection */
if (!defined('ABSPATH')) exit;
get_header();
$key = fieldnotes_room_context();
if ($key) fieldnotes_render_room($key);
else { ?><main id="main-content" class="page-shell"><h1><?php the_title(); ?></h1><?php while (have_posts()) : the_post(); the_content(); endwhile; ?></main><?php }
get_footer();
