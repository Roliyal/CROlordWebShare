<?php if (!defined('ABSPATH')) exit; ?>
<div class="boughs-city-nav"><a class="boughs-text-link" href="<?php echo esc_url(fieldnotes_section_url('map')); ?>">去地图看看 <span aria-hidden="true">↗</span></a></div>
<?php fieldnotes_boughs_collection_posts($boughs_term, ''); ?>
