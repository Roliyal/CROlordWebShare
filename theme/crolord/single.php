<?php get_header(); ?>
<main id="main-content" class="page-shell reading-page">
    <?php while (have_posts()) : the_post();
        $reading_locked = post_password_required();
        $reading_author = fieldnotes_article_author(get_post_field('post_author', get_the_ID()));
        $reading_minutes = fieldnotes_reading_minutes();
        $reading_contents = !$reading_locked && fieldnotes_reading_contents_enabled(get_the_ID());
    ?>
    <article <?php post_class('entry reading-entry'); ?> itemscope itemtype="https://schema.org/BlogPosting">
        <header class="entry-header reading-header">
            <h1 class="entry-title" itemprop="headline"><?php the_title(); ?></h1>
            <?php if (!$reading_locked && has_excerpt()) : ?><p class="reading-lead"><?php echo esc_html(fieldnotes_clean_text(get_the_excerpt())); ?></p><?php endif; ?>
            <div class="reading-byline">
                <span class="screen-reader-text" itemprop="author" itemscope itemtype="https://schema.org/Person"><span itemprop="name"><?php echo esc_html($reading_author['name']); ?></span></span>
                <time itemprop="datePublished" datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('Y年n月j日')); ?></time>
                <?php if ($reading_minutes) : ?><span class="reading-duration"><?php printf(esc_html__('约 %s 分钟阅读', 'crolord'), esc_html(number_format_i18n($reading_minutes))); ?></span><?php endif; ?>
            </div>
        </header>
        <?php if (!$reading_locked) : ?>
        <div class="entry-featured reading-cover">
            <?php if (has_post_thumbnail()) : the_post_thumbnail('large', array('itemprop' => 'image', 'loading' => 'eager', 'fetchpriority' => 'high', 'sizes' => '(max-width: 680px) calc(100vw - 40px), (max-width: 900px) calc(100vw - 80px), 820px')); else : ?>
                <img src="<?php echo esc_url(fieldnotes_demo_image(get_the_ID())); ?>" alt="" itemprop="image" loading="eager" fetchpriority="high" width="1400" height="1000">
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="reading-story">
                <?php if ($reading_contents) : ?>
                <details class="reading-contents" data-reading-contents hidden>
                    <summary><svg viewBox="0 0 20 20" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M7 5h9M7 10h9M7 15h9M3 5h.1M3 10h.1M3 15h.1" stroke-linecap="round"/></svg><span><?php esc_html_e('目录', 'crolord'); ?></span><span class="screen-reader-text" data-reading-current></span><svg class="reading-contents-chevron" viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="m6 8 4 4 4-4" stroke-linecap="round" stroke-linejoin="round"/></svg></summary>
                    <nav aria-label="<?php esc_attr_e('本文目录', 'crolord'); ?>"><ol></ol></nav>
                </details>
                <?php endif; ?>
                <div class="entry-content" itemprop="articleBody"<?php if ($reading_contents) echo ' data-reading-document'; ?>><?php the_content(); wp_link_pages(); ?></div>
                <?php crolord_article_actions(get_the_ID()); ?>
                <?php if (!$reading_locked) :
                    $reading_previous = get_previous_post(true, '', 'category');
                    $reading_next = get_next_post(true, '', 'category');
                    if ($reading_previous || $reading_next) : ?>
                    <nav class="reading-next" aria-label="<?php esc_attr_e('继续阅读同主题文章', 'crolord'); ?>">
                        <h2><?php esc_html_e('接着读。', 'crolord'); ?></h2>
                        <div class="reading-next-grid">
                            <?php foreach (array($reading_previous, $reading_next) as $reading_neighbour) : if (!$reading_neighbour) continue; ?>
                                <a href="<?php echo esc_url(get_permalink($reading_neighbour)); ?>"><span><?php echo esc_html(get_the_title($reading_neighbour)); ?></span><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
                            <?php endforeach; ?>
                        </div>
                    </nav>
                    <?php endif;
                endif; ?>
        </div>
    </article>
    <?php if (comments_open() || get_comments_number()) comments_template(); endwhile; ?>
</main>
<?php get_footer(); ?>
