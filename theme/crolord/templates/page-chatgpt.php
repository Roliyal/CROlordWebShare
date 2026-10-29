<?php
/**
 * Template Name: Echoes 
 * Description: Password-protected shared chat page supplied by crolord Echoes.
 */
if (!defined('ABSPATH')) { exit; }
get_header();
?>
<main id="main-content" class="page-shell conversation-page">
    <div class="chatgpt-page">
        <?php while (have_posts()) : the_post(); ?>
            <h1 class="screen-reader-text"><?php the_title(); ?></h1>
            <div class="chatgpt-page-content">
                <?php the_content(); ?>
            </div>
        <?php endwhile; ?>
    </div>
</main>
<?php get_footer(); ?>
