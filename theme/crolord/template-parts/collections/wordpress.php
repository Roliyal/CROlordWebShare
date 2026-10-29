<?php
/** One Boughs page, with accessible native fallbacks for previews and filters. */
if (!defined('ABSPATH')) exit;
$kind = fieldnotes_work_kind();
?>
<nav class="boughs-work-filter" aria-label="作品分类">
    <?php foreach (array('wordpress' => '主题与插件', 'projects' => '开源项目', 'notes' => '最佳实践') as $value => $label) : ?><a data-work-filter href="<?php echo esc_url(fieldnotes_work_kind_url($kind === $value ? '' : $value)); ?>"<?php echo $kind === $value ? ' aria-current="page" aria-label="' . esc_attr('取消筛选：' . $label) . '"' : ''; ?>><span><?php echo esc_html($label); ?></span><?php if ($kind === $value) : ?><span class="fn-filter-remove" aria-hidden="true">×</span><?php endif; ?></a><?php endforeach; ?>
</nav>
<div class="fn-work-filter-status screen-reader-text" role="status" aria-live="polite"></div>
<?php if ($kind !== 'notes') : ?>
<section class="fn-work-gallery" aria-label="作品展示"><div class="fn-tool-gallery">
    <?php $index = 0; foreach (fieldnotes_work_items() as $work) : if ($kind !== '' && $work['group'] !== $kind) continue; get_template_part('template-parts/collection-entry', null, array('work' => $work, 'eager' => $index === 0)); $index++; endforeach; ?>
</div></section>
<noscript><style>.fn-work-inline{display:block}.fn-work-inline>summary{position:static!important;clip:auto;width:auto;height:auto;margin-top:12px;overflow:visible}</style></noscript>
<dialog class="fn-work-dialog" aria-label="作品介绍与预览" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php', 'relative')); ?>"><button type="button" class="fn-work-close" aria-label="关闭预览">×</button><div class="fn-work-dialog-body"></div></dialog>
<?php endif; ?>
<?php if ($kind === '' || $kind === 'notes') fieldnotes_boughs_collection_posts(fieldnotes_boughs_term('works'), '最佳实践', array('practice' => true)); ?>
