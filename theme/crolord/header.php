<?php if (!defined('ABSPATH')) exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#main-content"><?php esc_html_e('跳转到正文', 'crolord'); ?></a>
<div class="crolord-site-identity"><?php crolord_site_logo(); ?></div>
<?php if (fieldnotes_logo_only_header()) : ?>
<div class="article-logo-only" aria-hidden="true"></div>
<?php get_template_part('template-parts/mobile-navigation'); ?>
<?php else : ?>
<header class="site-header gallery-header">
    <div class="header-inner">
        <div class="site-branding">
            <?php if (!is_front_page()) : ?><div class="brand">
                <a class="brand-name" href="<?php echo esc_url(home_url('/')); ?>" rel="home"><?php bloginfo('name'); ?></a>
            </div><?php endif; ?>
        </div>
        <nav class="header-navigation" id="fieldnotes-navigation" aria-label="<?php esc_attr_e('主导航', 'crolord'); ?>">
            <?php wp_nav_menu(array('theme_location' => 'primary', 'container' => false, 'fallback_cb' => 'fieldnotes_menu_fallback', 'menu_class' => 'primary-nav')); ?>
        </nav>
        <div class="header-actions">
            <details class="site-search" id="fieldnotes-site-search">
                <summary class="header-action search-toggle" aria-label="<?php esc_attr_e('搜索文章', 'crolord'); ?>">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="10.75" cy="10.75" r="6.25"/><path d="m15.5 15.5 4.25 4.25" stroke-linecap="round"/></svg>
                    <span class="screen-reader-text"><?php esc_html_e('搜索文章', 'crolord'); ?></span>
                </summary>
                <div class="site-search-panel">
                    <div class="search-panel-heading">
                        <label for="fieldnotes-header-query"><?php esc_html_e('找一篇笔记。', 'crolord'); ?></label>
                        <button class="search-close header-action" type="button" hidden aria-label="<?php esc_attr_e('关闭搜索', 'crolord'); ?>"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17" stroke-linecap="round"/></svg></button>
                    </div>
                    <form role="search" method="get" class="search-form" action="<?php echo esc_url(home_url('/')); ?>">
                        <input id="fieldnotes-header-query" class="search-field" type="search" name="s" value="<?php echo esc_attr(get_search_query()); ?>" placeholder="<?php esc_attr_e('旅行、日常，或一个念头', 'crolord'); ?>" required>
                        <button class="search-submit" type="submit" aria-label="<?php esc_attr_e('开始搜索', 'crolord'); ?>"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                    </form>
                    <p class="search-panel-note"><?php esc_html_e('在写下的日子里，找一点共鸣。', 'crolord'); ?></p>
                </div>
            </details>
            <button class="menu-toggle header-action" type="button" hidden aria-controls="fieldnotes-navigation" aria-expanded="false" aria-label="<?php esc_attr_e('展开或收起导航', 'crolord'); ?>"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path class="menu-line-first" d="M5 9h14" stroke-linecap="round"/><path class="menu-line-last" d="M5 15h14" stroke-linecap="round"/></svg></button>
        </div>
    </div>
</header>
<?php $notice = get_theme_mod('fieldnotes_notice', ''); if ($notice) : ?>
    <div class="notice-bar"><span class="notice-symbol" aria-hidden="true">✳</span><?php echo esc_html($notice); ?><?php $notice_url = get_theme_mod('fieldnotes_notice_url'); if ($notice_url) : ?> <a href="<?php echo esc_url($notice_url); ?>"><?php esc_html_e('了解更多', 'crolord'); ?></a><?php endif; ?></div>
<?php endif; ?>
<?php endif; ?>
