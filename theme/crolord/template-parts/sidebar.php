<aside class="sidebar" aria-label="<?php esc_attr_e('博客侧栏', 'crolord'); ?>">
    <?php if (is_active_sidebar('journal-sidebar')) : ?>
        <?php dynamic_sidebar('journal-sidebar'); ?>
    <?php else : ?>
        <section class="sidebar-block sidebar-recent-posts">
            <header class="sidebar-block-heading"><div><h2><?php esc_html_e('最近记录', 'crolord'); ?></h2></div><a class="sidebar-more" href="<?php echo esc_url(fieldnotes_boughs_url('cities')); ?>" aria-label="<?php esc_attr_e('浏览城市漫步', 'crolord'); ?>">↗</a></header>
            <?php $recent_posts = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 5, 'orderby' => 'date', 'order' => 'DESC')); ?>
            <?php if ($recent_posts) : ?><ol class="sidebar-recent-list"><?php foreach ($recent_posts as $index => $recent_post) : ?>
                <li><a href="<?php echo esc_url(get_permalink($recent_post)); ?>"><span class="sidebar-list-index"><?php echo esc_html(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></span><span class="sidebar-list-copy"><strong><?php echo esc_html(get_the_title($recent_post)); ?></strong><time datetime="<?php echo esc_attr(get_the_date('c', $recent_post)); ?>"><?php echo esc_html(get_the_date('Y.m.d', $recent_post)); ?></time></span><span class="sidebar-list-arrow" aria-hidden="true">↗</span></a></li>
            <?php endforeach; ?></ol><?php else : ?><p class="sidebar-empty"><?php esc_html_e('第一篇笔记正在路上。', 'crolord'); ?></p><?php endif; ?>
        </section>

        <section class="sidebar-block sidebar-recent-comments">
            <header class="sidebar-block-heading"><div><h2><?php esc_html_e('读者的话', 'crolord'); ?></h2></div></header>
            <?php $recent_comments = get_comments(array('status' => 'approve', 'type' => 'comment', 'number' => 3, 'orderby' => 'comment_date_gmt', 'order' => 'DESC')); ?>
            <?php if ($recent_comments) : ?><ol class="sidebar-comment-list"><?php foreach ($recent_comments as $recent_comment) : ?>
                <li><div class="sidebar-comment-avatar"><?php echo get_avatar($recent_comment, 34); ?></div><div class="sidebar-comment-copy"><p><strong><?php echo esc_html($recent_comment->comment_author); ?></strong> <?php esc_html_e('写在', 'crolord'); ?> <a href="<?php echo esc_url(get_comment_link($recent_comment)); ?>"><?php echo esc_html(get_the_title($recent_comment->comment_post_ID)); ?></a></p><blockquote><?php echo esc_html(wp_trim_words(wp_strip_all_tags($recent_comment->comment_content), 17, '…')); ?></blockquote><time datetime="<?php echo esc_attr(get_comment_date('c', $recent_comment)); ?>"><?php echo esc_html(get_comment_date('M d', $recent_comment)); ?></time></div></li>
            <?php endforeach; ?></ol><?php else : ?><p class="sidebar-empty"><?php esc_html_e('这里还没有留言。欢迎留下第一句话。', 'crolord'); ?></p><?php endif; ?>
        </section>
    <?php endif; ?>
</aside>
