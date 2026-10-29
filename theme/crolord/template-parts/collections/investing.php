<?php
if (!defined('ABSPATH')) exit;
$symbol_input = isset($_GET['stock']) && is_string($_GET['stock']) ? wp_unslash($_GET['stock']) : '';
$symbol = fieldnotes_market_symbol($symbol_input !== '' ? $symbol_input : 'SH000001');
if (is_wp_error($symbol)) $symbol = fieldnotes_market_symbol('SH000001');
$notes_page = 1;
$notes = fieldnotes_market_notes_data(null, 1);
?>
<section class="fn-market<?php echo $symbol ? ' has-stock' : ''; ?>" data-symbol="<?php echo esc_attr($symbol ? $symbol['canonical'] : ''); ?>" data-notes-page="<?php echo esc_attr($notes_page); ?>" aria-label="股票分时、历史 K 线与观察记录">
    <form class="fn-market-search" method="get" action="<?php echo esc_url(fieldnotes_boughs_url('investing')); ?>">
        <?php $market_url = wp_parse_url(fieldnotes_boughs_url('investing')); parse_str($market_url['query'] ?? '', $market_query); foreach ($market_query as $key => $value) : if (is_scalar($value) && !in_array($key, array('stock', 'period', 'adjust', 'notes_page'), true)) : ?><input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>"><?php endif; endforeach; ?>
        <div class="fn-market-search-wrap"><div class="fn-market-search-field"><input id="fn-market-code" name="stock" value="<?php echo esc_attr($symbol ? $symbol['canonical'] : ''); ?>" placeholder="股票名称或代码，如 贵州茅台" maxlength="32" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="fn-market-matches" aria-describedby="fn-market-search-hint"><button type="submit">展开行情 <span aria-hidden="true">↗</span></button></div><div class="fn-market-matches" id="fn-market-matches" role="listbox" aria-label="匹配的股票" hidden></div></div>
        <p id="fn-market-search-hint" role="status">沪深北 A 股、上证指数 · 名称、简拼或代码</p>
    </form>
    <div class="fn-market-panel">
        <header class="fn-market-panel-heading">
            <div><h2 class="fn-market-stock-title"><?php echo esc_html($symbol['canonical'] === 'SH000001' ? '上证指数 · SH000001' : $symbol['canonical']); ?></h2><p class="fn-market-stock-subtitle">分时行情</p></div>
            <div class="fn-market-periods" role="group" aria-label="行情视图"><button type="button" data-period="minute" aria-pressed="true">分时</button><button type="button" data-period="day" aria-pressed="false">日</button><button type="button" data-period="week" aria-pressed="false">周</button><button type="button" data-period="month" aria-pressed="false">月</button></div>
        </header>
        <div class="fn-market-live" hidden><div><strong class="fn-market-live-price"></strong><span class="fn-market-live-change"></span></div><p class="fn-market-live-time"></p></div>
        <div class="fn-market-auto"><label for="fn-market-interval">自动刷新<select id="fn-market-interval"><option value="2">每 2 秒</option><option value="5" selected>每 5 秒</option><option value="10">每 10 秒</option><option value="30">每 30 秒</option><option value="0">暂停</option></select></label><span class="fn-market-auto-state" role="status">选择股票后开始</span></div>
        <div class="fn-market-toolbar">
            <div class="fn-market-ma-legend"><span class="ma5">MA5</span><span class="ma10">MA10</span><span class="ma20">MA20</span></div>
            <div><label class="screen-reader-text" for="fn-market-adjust">复权方式</label><select id="fn-market-adjust"><option value="qfq">前复权</option><option value="none">不复权</option></select><button class="fn-market-zoom-out" type="button" aria-label="显示更多 K 线">−</button><button class="fn-market-zoom-in" type="button" aria-label="放大 K 线">＋</button><button class="fn-market-zoom-reset" type="button">重置</button><button class="fn-market-refresh" type="button" aria-label="刷新当前股票行情">刷新</button></div>
        </div>
        <details class="fn-market-indicator-chooser"><summary>技术指标 <span aria-hidden="true">＋</span></summary><div class="fn-market-indicator-options"></div><div class="fn-market-indicator-actions"><button type="button" class="fn-market-all-indicators">展开全部副图</button><button type="button" class="fn-market-reset-indicators">恢复默认</button></div><p class="fn-market-indicator-hint">日、周、月 K 线可使用技术指标；完整周期不足时，数值暂留空。</p></details>
        <div class="fn-market-chart" tabindex="0" role="group" aria-label="股票 K 线图，使用左右方向键查看数据">
            <svg class="fn-market-svg" viewBox="0 0 1000 500" role="img" aria-label="等待查询股票历史行情"></svg>
            <div class="fn-market-chart-empty"><span aria-hidden="true">／</span><p>输入名称或代码，展开一段价格记录。</p></div>
            <div class="fn-market-tooltip" hidden></div>
        </div>
        <p class="fn-market-status" role="status" aria-live="polite"></p>
        <div class="fn-market-provenance"><span class="fn-market-source">历史行情 · 可能存在延迟</span><button class="fn-market-retry" type="button" hidden>重新读取</button></div>
        <div class="fn-market-indicators"></div>
        <details class="fn-market-indicator-values" hidden><summary>查看指标数值与公式</summary><div class="fn-market-indicator-table"></div><div class="fn-market-indicator-formulas"></div></details>
        <details class="fn-market-data-table" hidden><summary>查看近期数据</summary><div><table><thead><tr><th>日期</th><th>开盘</th><th>收盘</th><th>最高</th><th>最低</th><th>成交量（手）</th></tr></thead><tbody></tbody></table></div></details>
        <noscript><p class="fn-market-status">启用 JavaScript 后可显示交互 K 线。下方文章仍可直接阅读。</p></noscript>
    </div>
    <section class="fn-market-observations" aria-labelledby="fn-market-notes-title">
        <div class="boughs-section-heading"><div><h2 id="fn-market-notes-title">市场手记</h2></div><?php if (current_user_can('edit_posts')) : ?><a class="boughs-text-link" href="#fn-market-write">写一篇手记 ↗</a><?php endif; ?></div>
        <div class="fn-market-note-list" aria-live="polite">
            <?php foreach ($notes['posts'] as $note) : ?><article class="fn-market-note"><a class="fn-market-note-link" href="<?php echo esc_url($note['url']); ?>"><time datetime="<?php echo esc_attr($note['iso_date']); ?>"><?php echo esc_html($note['date']); ?></time><div><?php if (!empty($note['sample'])) : ?><span class="fn-sample-label">示例笔记</span><?php endif; ?><h3><?php echo esc_html($note['title']); ?></h3><p><?php echo esc_html($note['excerpt']); ?></p></div><span aria-hidden="true">↗</span></a></article><?php endforeach; ?>
            <?php if (!$notes['posts']) : ?><div class="fn-market-empty"><p>暂无市场手记。</p></div><?php endif; ?>
        </div>
        <?php if ($notes['has_more']) : ?><details class="fn-market-more-notes"><summary><span class="fn-market-more-closed">更多手记</span><span class="fn-market-more-open">收起手记</span></summary><div class="fn-market-older-list" aria-live="polite"></div><p class="fn-market-notes-status" role="status" hidden></p><button class="fn-market-notes-more" type="button" hidden>继续展开</button></details><?php endif; ?>
    </section>
    <section class="fn-market-board" aria-labelledby="fn-market-board-title">
        <div class="boughs-section-heading"><div><h2 id="fn-market-board-title">把看见的变化，写成可以交流的想法。</h2></div></div>
        <?php comments_template('/template-parts/market-comments.php'); ?>
    </section>
    <?php if (current_user_can('edit_posts')) : ?>
    <details class="fn-market-write" id="fn-market-write">
        <summary>写一篇手记 <span aria-hidden="true">＋</span></summary>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
            <input type="hidden" name="action" value="fieldnotes_market_note"><?php wp_nonce_field('fieldnotes_market_note'); ?>
            <div class="fn-market-write-top"><label>股票代码<input name="stock" value="<?php echo esc_attr($symbol ? $symbol['canonical'] : ''); ?>" placeholder="例如 600519" maxlength="16" required></label><label>记录标题<input name="note_title" placeholder="为这次观察，写一个标题" maxlength="160" required></label></div>
            <label>观察与依据<textarea name="note_content" rows="7" maxlength="24000" placeholder="看到了什么？判断的依据是什么？哪些变化值得下一次回看？" required></textarea></label>
            <div class="fn-market-write-bottom"><p>保存为 WordPress 草稿，进入编辑器继续完善与发布。</p><button type="submit">保存草稿，继续编辑 ↗</button></div>
        </form>
    </details>
    <?php endif; ?>
</section>
