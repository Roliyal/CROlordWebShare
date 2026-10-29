<?php get_header(); ?>
<main id="main-content" class="page-shell page-home">
    <?php
    $latest = new WP_Query(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 8, 'ignore_sticky_posts' => true, 'meta_query' => array(array('key' => '_fieldnotes_sample_key', 'compare' => 'NOT EXISTS')), 'orderby' => array('date' => 'DESC', 'ID' => 'DESC')));
    ?>
    <section class="home-hero" aria-labelledby="home-title">
        <div class="home-hero-copy">
            <p class="home-kicker"><span><?php esc_html_e('文字、投资、影像，与日常。', 'crolord'); ?></span></p>
            <h1 id="home-title">把日常写下来，<br><em>让远方慢一点。</em></h1>
            <div class="home-hero-actions">
                <a class="home-primary-link" href="#latest-notes"><?php esc_html_e('开始阅读', 'crolord'); ?><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
            </div>
        </div>
        <figure class="home-hero-visual">
            <?php $hero_image=crolord_home_hero_image(); ?>
            <img src="<?php echo esc_url($hero_image['src']); ?>" alt="雨后的京都街巷与远处的塔影" width="1536" height="1024" fetchpriority="high" loading="eager" decoding="async" srcset="<?php echo esc_attr($hero_image['srcset']); ?>" sizes="<?php echo esc_attr($hero_image['sizes']); ?>">
            <figcaption class="home-hero-caption"><span>行记 · 一</span><span><?php esc_html_e('京都 · 雨后', 'crolord'); ?></span></figcaption>
            <span class="home-hero-index" aria-hidden="true">01 <i></i> 04</span>
        </figure>
    </section>

    <section class="home-journal" id="latest-notes" aria-labelledby="latest-title">
        <header class="home-section-head">
            <div>
                <h2 id="latest-title"><?php esc_html_e('日常与远方，慢慢显影。', 'crolord'); ?></h2>
            </div>
        </header>
        <div class="columns">
            <section class="home-post-column">
                <h3 class="screen-reader-text"><?php esc_html_e('最新文章', 'crolord'); ?></h3>
                <?php if ($latest->have_posts()) : ?><div class="post-list" id="fieldnotes-post-list" data-page="1" data-max-pages="<?php echo esc_attr($latest->max_num_pages); ?>"><?php while ($latest->have_posts()) : $latest->the_post(); get_template_part('template-parts/post-card'); endwhile; ?></div>
                    <?php if ($latest->max_num_pages > 1) : ?><nav class="pagination" aria-label="<?php esc_attr_e('文章分页', 'crolord'); ?>"><span><button class="load-more" id="fieldnotes-load-more" type="button"><?php esc_html_e('加载更多文章', 'crolord'); ?><span aria-hidden="true">↓</span></button></span></nav><?php endif; ?>
                <?php else : ?><p class="empty-state"><?php esc_html_e('博客已准备就绪。发布第一篇文章后，首页会显示文章列表。', 'crolord'); ?></p><?php endif; wp_reset_postdata(); ?>
            </section>
            <?php get_template_part('template-parts/sidebar'); ?>
        </div>
    </section>
</main>
<?php get_footer(); ?>
