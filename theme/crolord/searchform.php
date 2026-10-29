<?php $fieldnotes_search_id = wp_unique_id('fieldnotes-search-'); ?>
<form role="search" method="get" class="search-form" action="<?php echo esc_url(home_url('/')); ?>">
    <label class="screen-reader-text" for="<?php echo esc_attr($fieldnotes_search_id); ?>"><?php esc_html_e('搜索文章', 'crolord'); ?></label>
    <input id="<?php echo esc_attr($fieldnotes_search_id); ?>" type="search" class="search-field" placeholder="<?php echo esc_attr(sprintf(__('搜索 %s', 'crolord'), get_bloginfo('name'))); ?>" value="<?php echo esc_attr(get_search_query()); ?>" name="s" required>
    <button class="search-submit" type="submit" aria-label="<?php esc_attr_e('开始搜索', 'crolord'); ?>"><span aria-hidden="true">↗</span></button>
</form>
