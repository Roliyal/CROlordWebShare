<?php
/** The same saved site menu, available on phone-sized inner pages. */
if (!defined('ABSPATH')) exit;
?>
<details class="crolord-mobile-nav">
    <summary aria-label="<?php esc_attr_e('栏目菜单', 'crolord'); ?>" aria-controls="crolord-mobile-navigation" title="<?php esc_attr_e('栏目', 'crolord'); ?>">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path class="menu-line-first" d="M5 9h14" stroke-linecap="round"/><path class="menu-line-last" d="M5 15h14" stroke-linecap="round"/></svg>
    </summary>
    <nav id="crolord-mobile-navigation" aria-label="<?php esc_attr_e('栏目导航', 'crolord'); ?>">
        <?php wp_nav_menu(array('theme_location'=>'primary','container'=>false,'fallback_cb'=>'fieldnotes_menu_fallback','menu_class'=>'primary-nav crolord-mobile-menu')); ?>
    </nav>
</details>
