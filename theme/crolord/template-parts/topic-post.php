<?php
if (!defined('ABSPATH')) exit;
$topic_featured = !empty($args['featured']);
$topic_locked = post_password_required();
?>
<article <?php post_class('topic-post' . ($topic_featured ? ' topic-post-featured' : '')); ?>>
    <?php if (!$topic_locked) : ?>
    <a class="topic-post-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
        <?php if (has_post_thumbnail()) : the_post_thumbnail($topic_featured ? 'large' : 'fieldnotes-card', array('loading' => $topic_featured ? 'eager' : 'lazy')); else : ?><img src="<?php echo esc_url(fieldnotes_demo_image(get_the_ID())); ?>" alt="" width="1400" height="1000" decoding="async" srcset="<?php echo esc_attr(fieldnotes_demo_image_srcset(get_the_ID())); ?>" sizes="<?php echo $topic_featured ? '(max-width: 780px) calc(100vw - 48px), (max-width: 1200px) 60vw, 720px' : '(max-width: 780px) calc(100vw - 48px), (max-width: 1200px) 50vw, 520px'; ?>" loading="<?php echo $topic_featured ? 'eager' : 'lazy'; ?>"><?php endif; ?>
    </a>
    <?php endif; ?>
    <div class="topic-post-copy">
        <div class="topic-post-meta"><time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('Y.m.d')); ?></time><?php $topic_minutes = fieldnotes_reading_minutes(); if ($topic_minutes) : ?><span><?php printf(esc_html__('约 %s 分钟', 'crolord'), esc_html(number_format_i18n($topic_minutes))); ?></span><?php endif; ?></div>
        <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
        <?php if (!$topic_locked) : ?><p><?php echo esc_html(wp_trim_words(fieldnotes_clean_text(get_the_excerpt()), $topic_featured ? 56 : 36, '…')); ?></p><?php else : ?><p><?php esc_html_e('这是一篇需要密码的记录。', 'crolord'); ?></p><?php endif; ?>
        <?php if ($topic_featured) : ?><a class="editorial-text-link" href="<?php the_permalink(); ?>"><?php esc_html_e('展开阅读', 'crolord'); ?><span aria-hidden="true">↗</span></a><?php endif; ?>
    </div>
</article>
