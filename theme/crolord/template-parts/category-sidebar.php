<?php
if (!defined('ABSPATH') || empty($args['term'])) return;
$context_term = $args['term'];
$context_profile = $args['profile'];
$context_children = get_terms(array('taxonomy' => 'category', 'parent' => $context_term->term_id, 'hide_empty' => true));
if (is_wp_error($context_children)) $context_children = array();
$context_related = fieldnotes_related_categories($context_term);
?>
<aside class="topic-context" aria-label="<?php esc_attr_e('分类专题介绍', 'crolord'); ?>">
    <section class="topic-about">
        <h2><?php esc_html_e('关于这个主题', 'crolord'); ?></h2>
        <p><?php echo esc_html($context_profile['intro']); ?></p>
        <ul class="topic-directions" aria-label="<?php esc_attr_e('阅读方向', 'crolord'); ?>"><?php foreach ($context_profile['directions'] as $direction) : ?><li><?php echo esc_html($direction); ?></li><?php endforeach; ?></ul>
    </section>
    <?php if ($context_children) : ?>
    <section class="topic-context-section">
        <h2><?php esc_html_e('再走近一点', 'crolord'); ?></h2>
        <ul class="topic-related"><?php foreach ($context_children as $context_item) : ?><li><a href="<?php echo esc_url(get_category_link($context_item->term_id)); ?>"><span><?php echo esc_html(fieldnotes_category_label($context_item)); ?></span><span class="topic-link-count"><?php echo esc_html(number_format_i18n($context_item->count)); ?></span></a></li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>
    <?php if ($context_related) : ?>
    <section class="topic-context-section">
        <h2><?php esc_html_e('也许也会喜欢', 'crolord'); ?></h2>
        <ul class="topic-related"><?php foreach ($context_related as $context_item) : ?><li><a href="<?php echo esc_url(get_category_link($context_item->term_id)); ?>"><span><?php echo esc_html(fieldnotes_category_label($context_item)); ?></span><span aria-hidden="true">↗</span></a></li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>
</aside>
