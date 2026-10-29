<?php if (!defined('ABSPATH')) exit; ?>
<main id="main-content" class="page-shell boughs-page boughs-room boughs-<?php echo esc_attr($boughs_key); ?><?php echo $boughs_key === 'works' ? ' boughs-unified' : ''; ?>">
    <header class="boughs-heading"><div><h1><?php if ($boughs_key === 'investing') : ?><label for="fn-market-code">从一只股票开始。</label><?php else : ?><?php echo esc_html(fieldnotes_room_title($boughs_key)); ?><?php endif; ?></h1></div><?php if (!in_array($boughs_key, array('works', 'minimalism', 'investing'), true)) : ?><p><?php echo esc_html($boughs_section['intro']); ?></p><?php endif; ?></header>
    <?php include get_template_directory() . '/template-parts/collections/' . ($boughs_key === 'works' ? 'wordpress' : $boughs_key) . '.php'; ?>
</main>
