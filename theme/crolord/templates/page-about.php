<?php
/* Template Name: About */
if (!defined('ABSPATH')) exit;
get_header(); ?>
<main id="main-content" class="page-shell about-unhurried">
<?php while (have_posts()) : the_post(); if (post_password_required()) : ?><h1><?php the_title(); ?></h1><?php the_content(); ?>
<?php else : $updates = crolord_about_updates(); ?>
    <h1 class="screen-reader-text">About</h1>
    <div class="about-unhurried-layout">
        <section class="about-person" aria-label="个人简介">
            <p class="about-person-interests">写代码，观光影；<br>于万物流转间，慢慢寻找答案。</p>
            <nav class="about-social-icons" aria-label="联系与社交"><?php foreach (crolord_about_social_links() as $link) : ?><a href="<?php echo esc_url($link['url']); ?>" aria-label="<?php echo esc_attr($link['label']); ?>"<?php if ($link['external']) : ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?php echo crolord_about_social_icon($link['key']); ?></a><?php endforeach; ?></nav>
        </section>
        <?php if ($updates) : ?><section class="about-updates" aria-label="城市漫步最新动态" aria-roledescription="轮播" tabindex="0" aria-describedby="about-update-instructions">
            <div class="about-updates-stage"><?php foreach ($updates as $index=>$update) : ?><article class="about-update<?php echo $index===0?' is-current':''; ?>"<?php echo $index===0?'':' hidden'; ?> aria-roledescription="幻灯片" aria-label="<?php echo esc_attr(($index+1).' / '.count($updates)); ?>"><a class="about-update-image" href="<?php echo esc_url($update['url']); ?>"><?php if ($update['attachment']) : echo wp_get_attachment_image($update['attachment'],'large',false,array('alt'=>$update['title'],'loading'=>$index===0?'eager':'lazy','decoding'=>'async','sizes'=>'(max-width:760px) 90vw, 540px')); else : ?><img src="<?php echo esc_url($update['image']); ?>" alt="<?php echo esc_attr($update['title']); ?>" width="1024" height="768" srcset="<?php echo esc_attr($update['srcset']); ?>" sizes="(max-width:760px) 90vw, 540px" loading="<?php echo $index===0?'eager':'lazy'; ?>" decoding="async"><?php endif; ?></a><div class="about-update-caption"><h3><a href="<?php echo esc_url($update['url']); ?>"><?php echo esc_html($update['title']); ?></a></h3></div></article><?php endforeach; ?></div>
            <p id="about-update-instructions" class="screen-reader-text">左右方向键切换，空格暂停或继续；手机上可左右滑动。</p>
            <p class="screen-reader-text about-updates-status" aria-live="polite"></p>
        </section><?php endif; ?>
    </div>
<?php endif; endwhile; ?>
</main>
<?php get_footer(); ?>
