<?php if (!empty($args['work']) && is_array($args['work'])) : $work = $args['work']; $eager = !empty($args['eager']); $project = $work['group'] === 'projects'; $info_id = 'work-info-' . sanitize_html_class($work['id']); $repo_data = $project ? fieldnotes_work_repository_cached($work['repository']) : null; ?>
<article class="fn-tool-card<?php echo $project ? ' is-project' : ' is-capture'; ?>" id="work-<?php echo esc_attr($work['id']); ?>">
    <?php if ($work['capture']) : ?><figure class="fn-tool-stage"><a class="fn-work-open" data-work-open="<?php echo esc_attr($info_id); ?>" href="#<?php echo esc_attr($info_id); ?>" aria-haspopup="dialog" aria-label="<?php echo esc_attr('了解 ' . $work['name']); ?>"><?php fieldnotes_work_image($work, false, $eager); ?></a></figure><?php endif; ?>
    <div class="fn-tool-copy">
        <div class="fn-tool-heading"><h2><a data-work-open="<?php echo esc_attr($info_id); ?>" href="#<?php echo esc_attr($info_id); ?>" aria-haspopup="dialog"><?php echo esc_html($work['name']); ?><span aria-hidden="true">↗</span></a></h2></div>
        <p class="fn-tool-description"><?php echo esc_html($work['description']); ?></p>
        <?php if ($project) : ?><ul class="fn-repo-outline" aria-label="<?php echo esc_attr($work['name'] . ' 内容'); ?>"><?php foreach ($work['features'] as $feature) : ?><li><?php echo esc_html($feature[0]); ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
    <details class="fn-work-inline" id="<?php echo esc_attr($info_id); ?>"><summary class="screen-reader-text" data-work-open="<?php echo esc_attr($info_id); ?>"><?php echo $project ? '项目介绍' : '功能与预览'; ?> <span aria-hidden="true">↗</span></summary>
        <div class="fn-work-detail-content"<?php if ($project) : ?> data-repository="<?php echo esc_attr($work['repository']); ?>"<?php endif; ?>>
            <header class="fn-work-detail-heading"><h2><?php echo esc_html($work['name']); ?></h2><p><?php echo esc_html($work['intro']); ?></p></header>
            <div class="fn-work-detail-layout<?php echo $project ? ' is-repository' : ''; ?>">
                <?php if (!$project && $work['capture']) : ?><figure class="fn-work-detail-media"><?php fieldnotes_work_image($work, true); ?><figcaption><?php echo esc_html($work['capture']['label'] . ' · ' . $work['capture']['date']); ?></figcaption></figure><?php endif; ?>
                <div class="fn-work-feature-copy"><h3><?php echo $project ? '可以从这里开始' : '可用功能'; ?></h3><dl class="fn-work-features"><?php foreach ($work['features'] as $feature) : ?><div><dt><?php echo esc_html($feature[0]); ?></dt><dd><?php echo esc_html($feature[1]); ?></dd></div><?php endforeach; ?></dl></div>
                <?php if ($project) : ?><aside class="fn-repo-version" aria-label="仓库版本"><h3>版本与更新</h3><dl class="fn-work-metadata"><div><dt>发布版本</dt><dd data-repo-release><?php echo esc_html($repo_data['tag'] !== '' ? $repo_data['tag'] . ' · ' . $repo_data['published'] : '尚未发布版本'); ?></dd></div><div><dt>分支</dt><dd data-repo-branch><?php echo esc_html($repo_data['branch']); ?></dd></div><div><dt>最近更新</dt><dd data-repo-updated><?php echo esc_html($repo_data['updated']); ?></dd></div><div><dt>提交</dt><dd data-repo-sha><?php echo esc_html(substr($repo_data['sha'], 0, 7)); ?></dd></div></dl><p class="fn-repo-update-status" role="status" aria-live="polite"></p></aside><?php endif; ?>
            </div>
            <footer class="fn-work-detail-footer"><?php if (!$project) : ?><span>当前版本 <?php echo esc_html($work['version'] !== '' ? 'v' . $work['version'] : '未安装'); ?></span><?php endif; ?><nav aria-label="<?php echo esc_attr($work['name'] . ' 相关链接'); ?>"><a href="<?php echo esc_url($work['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo $project ? '在 GitHub 查看' : '查看源码'; ?> ↗</a><?php if ($project) : ?><a href="<?php echo esc_url($work['source_url'] . '/blob/main/README.md'); ?>" target="_blank" rel="noopener noreferrer">阅读 README ↗</a><?php elseif ($work['release_url']) : ?><a href="<?php echo esc_url($work['release_url']); ?>" target="_blank" rel="noopener noreferrer">发行包 ↗</a><?php endif; ?></nav></footer>
        </div>
    </details>
</article>
<?php return; endif; ?>
<?php
$featured = !empty($args['featured']);
$practice = !empty($args['practice']);
$sample = (bool) get_post_meta(get_the_ID(), '_fieldnotes_sample_key', true);
$image_sizes = $featured ? '(max-width: 580px) calc(100vw - 44px), (max-width: 800px) calc(100vw - 56px), (max-width: 1200px) calc(100vw - 96px), 1104px' : '(max-width: 580px) calc(100vw - 44px), (max-width: 800px) 45vw, 540px';
?>
<article class="boughs-entry<?php echo $featured ? ' is-featured' : ''; ?><?php echo $practice ? ' is-practice' : ''; ?>">
    <?php if ($practice) : ?>
    <div class="boughs-practice-copy"><div class="boughs-practice-meta"><time class="boughs-practice-date" datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('Y.m.d')); ?></time><?php if ($sample) : ?><span class="fn-sample-label">示例实践</span><?php endif; ?></div><h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3><p><?php echo esc_html(post_password_required() ? '这篇文字有口令保护。' : wp_trim_words(get_the_excerpt(), 65, '…')); ?></p><?php $topics = fieldnotes_practice_post_topics(get_the_ID()); if ($topics) : ?><ul class="boughs-entry-tags" aria-label="文章标签"><?php foreach ($topics as $topic) : ?><li><a href="<?php echo esc_url($topic['url']); ?>"><?php echo esc_html($topic['label']); ?></a></li><?php endforeach; ?></ul><?php endif; ?></div>
    <?php else : ?>
    <a class="boughs-entry-link" href="<?php the_permalink(); ?>">
        <figure><?php if (has_post_thumbnail()) : the_post_thumbnail('large', array('loading' => $featured ? 'eager' : 'lazy', 'fetchpriority' => $featured ? 'high' : 'low', 'decoding' => 'async', 'sizes' => $image_sizes)); else : ?><img src="<?php echo esc_url(fieldnotes_demo_image(get_the_ID())); ?>" alt="" width="900" height="600" loading="<?php echo $featured ? 'eager' : 'lazy'; ?>" fetchpriority="<?php echo $featured ? 'high' : 'low'; ?>" decoding="async" srcset="<?php echo esc_attr(fieldnotes_demo_image_srcset(get_the_ID())); ?>" sizes="<?php echo esc_attr($image_sizes); ?>"><?php endif; ?></figure>
        <div class="boughs-entry-copy"><time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('Y.m.d')); ?></time><?php if ($sample) : ?><span class="fn-sample-label">示例实践</span><?php endif; ?><h3><?php the_title(); ?></h3><p><?php echo esc_html(post_password_required() ? '这篇文字有口令保护。' : wp_trim_words(get_the_excerpt(), 54, '…')); ?></p><span class="boughs-entry-arrow" aria-hidden="true">↗</span></div>
    </a>
    <?php endif; ?>
</article>
