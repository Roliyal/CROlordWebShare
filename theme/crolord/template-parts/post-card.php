<article <?php post_class('post-card'); ?>>
    <div class="post-card-main">
        <h2 class="post-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
        <p class="post-excerpt"><?php echo esc_html(wp_trim_words(get_the_excerpt(), 32, '…')); ?></p>
        <div class="post-meta">
            <?php $cats = get_the_category(); if ($cats) : ?><a href="<?php echo esc_url(get_category_link($cats[0]->term_id)); ?>"><?php echo esc_html(fieldnotes_category_label($cats[0])); ?></a><span class="dot"></span><?php endif; ?>
            <time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('M d, Y')); ?></time>
        </div>
    </div>
    <a class="post-visual" href="<?php the_permalink(); ?>" aria-label="<?php echo esc_attr(get_the_title()); ?>">
        <?php if (has_post_thumbnail()) : the_post_thumbnail('fieldnotes-card', array_merge(array('class' => 'post-thumb', 'decoding' => 'async'), is_front_page() ? array('loading' => 'lazy', 'fetchpriority' => 'low') : array())); else : ?><img class="post-thumb post-thumb-demo" src="<?php echo esc_url(fieldnotes_card_demo_image(get_the_ID())); ?>" alt="" loading="lazy" decoding="async" width="520" height="390"><?php endif; ?>
    </a>
</article>
