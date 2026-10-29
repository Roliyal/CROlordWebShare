<?php
if (!defined('ABSPATH') || empty($args['author'])) return;
$card_author = $args['author'];
?>
<aside class="reading-author-strip" aria-label="<?php esc_attr_e('关于作者', 'crolord'); ?>">
    <?php echo fieldnotes_author_portrait($card_author, 48); ?>
    <div class="reading-author-copy"><p class="reading-author-name"><?php echo esc_html($card_author['name']); ?></p><p class="reading-author-bio"><?php echo esc_html($card_author['bio']); ?></p></div>
    <a class="reading-author-more" href="<?php echo esc_url($card_author['url']); ?>" rel="author" aria-label="<?php echo esc_attr(sprintf(__('阅读 %s 的其他文章', 'crolord'), $card_author['name'])); ?>"><?php esc_html_e('更多文字', 'crolord'); ?><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M6 18 18 6M6 6h12v12" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
</aside>
