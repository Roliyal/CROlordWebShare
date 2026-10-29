<?php
get_header();
global $wp_query;
$discovery_search = is_search();
$discovery_query = $discovery_search ? get_search_query(false) : '';
$discovery_total = (int) $wp_query->found_posts;
if ($discovery_search) $discovery_title = __('搜索', 'crolord');
elseif (is_author()) $discovery_title = get_the_author_meta('display_name', get_queried_object_id());
elseif (is_day()) $discovery_title = sprintf('%d年%d月%d日', (int) get_query_var('year'), (int) get_query_var('monthnum'), (int) get_query_var('day'));
elseif (is_month()) $discovery_title = sprintf('%d年%d月', (int) get_query_var('year'), (int) get_query_var('monthnum'));
elseif (is_year()) $discovery_title = sprintf('%d年', (int) get_query_var('year'));
elseif (is_tag() || is_tax()) $discovery_title = single_term_title('', false);
elseif (is_post_type_archive()) $discovery_title = post_type_archive_title('', false);
else $discovery_title = __('所有记录', 'crolord');
?>
<main id="main-content" class="page-shell discovery-page">
    <header class="discovery-heading">
        <h1><?php echo esc_html(fieldnotes_clean_text($discovery_title)); ?></h1>
        <form role="search" method="get" class="discovery-search" action="<?php echo esc_url(home_url('/')); ?>">
            <label class="screen-reader-text" for="discovery-query"><?php esc_html_e('搜索文章', 'crolord'); ?></label>
            <input id="discovery-query" type="search" name="s" value="<?php echo esc_attr($discovery_query); ?>" placeholder="<?php esc_attr_e('搜索文字、地点，或一个念头', 'crolord'); ?>" enterkeyhint="search" required>
            <button type="submit" aria-label="<?php esc_attr_e('开始搜索', 'crolord'); ?>"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
        </form>
        <p class="discovery-count"><?php printf(esc_html__('%s 篇记录', 'crolord'), esc_html(number_format_i18n($discovery_total))); ?></p>
    </header>
    <?php if (have_posts()) : $discovery_index = 0; ?>
    <section class="discovery-results" aria-label="<?php echo $discovery_search ? esc_attr__('搜索结果', 'crolord') : esc_attr__('文章列表', 'crolord'); ?>">
        <?php while (have_posts()) : the_post();
            $discovery_locked = post_password_required();
            $discovery_image = !$discovery_locked && has_post_thumbnail() ? get_the_post_thumbnail(get_the_ID(), 'medium', array('loading' => $discovery_index === 0 ? 'eager' : 'lazy', 'decoding' => 'async', 'sizes' => '(max-width: 680px) 80px, 120px', 'alt' => '')) : '';
            $discovery_excerpt = $discovery_locked ? __('这篇记录已加密。', 'crolord') : wp_html_excerpt(fieldnotes_clean_text(get_the_excerpt()), 110, '…');
            $discovery_index++;
        ?>
        <article class="discovery-item">
            <a class="discovery-result" href="<?php the_permalink(); ?>" aria-labelledby="discovery-title-<?php the_ID(); ?>">
                <span class="discovery-media" aria-hidden="true"><?php if ($discovery_image) : echo $discovery_image; else : ?><span class="discovery-number"><?php echo esc_html(str_pad((string) $discovery_index, 2, '0', STR_PAD_LEFT)); ?></span><?php endif; ?></span>
                <div class="discovery-copy">
                    <time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('Y年n月j日')); ?></time>
                    <h2 id="discovery-title-<?php the_ID(); ?>"><?php echo esc_html(get_the_title() ?: __('无题', 'crolord')); ?></h2>
                    <?php if ($discovery_excerpt !== '') : ?><span class="discovery-excerpt"><?php echo esc_html($discovery_excerpt); ?></span><?php endif; ?>
                </div>
                <svg class="discovery-arrow" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
        </article>
        <?php endwhile; ?>
    </section>
    <?php the_posts_pagination(array('mid_size' => 1, 'prev_text' => __('上一页', 'crolord'), 'next_text' => __('下一页', 'crolord'), 'screen_reader_text' => __('记录分页', 'crolord'))); ?>
    <?php else : ?>
    <section class="discovery-empty" aria-labelledby="discovery-empty-title">
        <h2 id="discovery-empty-title"><?php echo $discovery_search ? esc_html__('暂时没有找到。', 'crolord') : esc_html__('这里，还留着一点空白。', 'crolord'); ?></h2>
        <p><?php echo $discovery_search ? esc_html__('试试更短的关键词，或换一种说法。', 'crolord') : esc_html__('新的记录，会在这里慢慢出现。', 'crolord'); ?></p>
    </section>
    <?php endif; ?>
</main>
<?php get_footer(); ?>
