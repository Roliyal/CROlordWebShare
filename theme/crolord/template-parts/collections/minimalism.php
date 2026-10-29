<?php
if (!defined('ABSPATH')) exit;
$kind = isset($_GET['kind']) && is_string($_GET['kind']) ? sanitize_title(wp_unslash($_GET['kind'])) : 'personal';
if (!in_array($kind, array('personal','gear','workspace','life-style'), true)) $kind = 'personal';
$terms = get_terms(array('taxonomy' => 'fave_kind', 'hide_empty' => false));
if (!is_wp_error($terms)) { $order = array_flip(array('personal','gear','workspace','life-style')); $terms = array_values(array_filter($terms, function($term) use($order){ return isset($order[$term->slug]); })); usort($terms, function($a,$b) use($order){ return $order[$a->slug] <=> $order[$b->slug]; }); }
$labels = array('life-style' => '生活器物', 'personal' => '随身之物', 'workspace' => '工作与阅读', 'gear' => '出行装备');
$fave_page = isset($_GET['faves_page']) && is_scalar($_GET['faves_page']) ? max(1, absint($_GET['faves_page'])) : 1;
$args = array('post_type' => 'journal_fave', 'post_status' => 'publish', 'posts_per_page' => 24, 'paged' => $fave_page, 'orderby' => 'date', 'order' => 'DESC');
if ($kind) $args['tax_query'] = array(array('taxonomy' => 'fave_kind', 'field' => 'slug', 'terms' => $kind));
$items = new WP_Query($args);
$base = fieldnotes_boughs_url('minimalism');
?>
<section class="boughs-keepsakes" aria-label="日常选择">
    <?php $keepsakes_page = fieldnotes_section_page('faves'); if ($keepsakes_page && !post_password_required($keepsakes_page) && trim($keepsakes_page->post_content)) : ?><div class="boughs-custom-copy"><?php echo apply_filters('the_content', $keepsakes_page->post_content); ?></div><?php endif; ?>
    <div class="fn-keepsakes-selection" hidden><p class="fn-keepsakes-selection-caption" role="status"></p><button type="button" class="fn-keepsakes-clear">清空</button><button type="button" class="fn-keepsakes-review">查看已选 <span aria-hidden="true">↗</span></button></div>
    <nav class="boughs-fave-filters" aria-label="日常选择分类"><?php if (!is_wp_error($terms)) foreach ($terms as $item) : ?><a href="<?php echo esc_url(add_query_arg('kind', $item->slug, $base)); ?>"<?php echo $kind === $item->slug ? ' aria-current="page"' : ''; ?>><?php echo esc_html($labels[$item->slug] ?? $item->name); ?></a><?php endforeach; ?></nav>
    <?php if ($items->have_posts()) : ?><div class="faves-grid"><?php while ($items->have_posts()) : $items->the_post(); $kinds = get_the_terms(get_the_ID(), 'fave_kind'); $slug = !is_wp_error($kinds) && !empty($kinds) ? $kinds[0]->slug : ''; $product = fieldnotes_keepsake_product(get_the_ID()); ?>
        <article class="fave-card" data-keepsake="<?php echo esc_attr(wp_json_encode($product)); ?>">
            <div class="fave-card-image"><?php if (has_post_thumbnail()) : the_post_thumbnail('medium_large'); else : ?><img src="<?php echo esc_url(fieldnotes_fave_image(get_the_ID())); ?>" alt="" loading="lazy" width="800" height="600"><?php endif; ?></div>
            <div class="fave-card-copy">
                <div class="fave-card-topline"><p class="fave-card-kind"><?php echo esc_html($labels[$slug] ?? '日常之选'); ?></p><div class="fave-card-actions">
                    <button class="fn-keepsake-like" type="button" data-keepsake-like aria-label="<?php echo esc_attr('喜欢 ' . get_the_title()); ?>" title="<?php esc_attr_e('喜欢', 'crolord'); ?>" aria-pressed="false" hidden><?php echo crolord_article_action_icon('like'); ?></button>
                    <label class="fn-keepsake-choose" title="<?php esc_attr_e('加入已选清单', 'crolord'); ?>" hidden><input class="screen-reader-text" type="checkbox" name="crolord-keepsake" value="<?php echo esc_attr((string) get_the_ID()); ?>"><svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path class="fn-keepsake-add-mark" d="M8.5 12h7M12 8.5v7"/><path class="fn-keepsake-check-mark" d="m8.4 12.1 2.3 2.4 4.9-5"/></svg><span class="screen-reader-text">选择 <?php the_title(); ?></span></label>
                </div></div>
                <h3><?php the_title(); ?></h3>
                <?php if ($kind === 'life-style' && $product['url']) : ?><a class="fn-keepsake-buy" href="<?php echo esc_url($product['url']); ?>" target="_blank" rel="noopener noreferrer">去购买 <span aria-hidden="true">↗</span></a><?php endif; ?>
                <span class="fn-keepsake-like-status screen-reader-text" role="status"></span>
            </div>
        </article>
    <?php endwhile; ?></div><?php echo wp_kses_post(paginate_links(array('base' => add_query_arg('faves_page', '%#%', $kind ? add_query_arg('kind', $kind, $base) : $base), 'format' => '', 'current' => $fave_page, 'total' => $items->max_num_pages, 'prev_text' => '上一页', 'next_text' => '下一页'))); ?>
    <?php else : ?><p class="boughs-empty">慢慢收集，值得长久相伴的小物。</p><?php endif; wp_reset_postdata(); ?>

    <dialog class="fn-keepsakes-dialog" aria-labelledby="fn-keepsakes-dialog-title"><header><div><h2 id="fn-keepsakes-dialog-title">已选之物</h2><p class="fn-keepsakes-dialog-caption">确认所需，再决定去处。</p></div><button type="button" class="fn-keepsakes-close" aria-label="关闭选购清单">×</button></header><div class="fn-keepsakes-dialog-list"></div><p class="fn-keepsakes-dialog-status" role="status" aria-live="polite"></p><footer><a class="fn-keepsakes-authorize" target="_blank" rel="noopener noreferrer" hidden>连接京东账号 ↗</a><button type="button" class="fn-keepsakes-reload">重新读取规格</button><button type="button" class="fn-keepsakes-add" hidden>加入购物车</button><a class="fn-keepsakes-cart" href="https://cart.jd.com/cart.action" target="_blank" rel="noopener noreferrer">查看京东购物车 ↗</a></footer></dialog>

</section>
<?php if ($boughs_term && (int) $boughs_term->count > 0) fieldnotes_boughs_collection_posts($boughs_term, '使用与取舍'); ?>
