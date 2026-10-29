<?php if (post_password_required()) return; $fieldnotes_has_comments = have_comments(); ?>
<section class="comments-area <?php echo $fieldnotes_has_comments ? 'has-comments' : 'no-comments'; ?>" id="comments">
    <?php if ($fieldnotes_has_comments) : ?><h2 class="section-heading"><?php printf(esc_html(_n('%s 条评论', '%s 条评论', get_comments_number(), 'crolord')), number_format_i18n(get_comments_number())); ?></h2><ol class="comment-list"><?php wp_list_comments(array('style' => 'ol', 'short_ping' => true, 'avatar_size' => 38)); ?></ol><?php the_comments_navigation(); endif; ?>
    <?php if (!comments_open() && get_comments_number()) : ?><p><?php esc_html_e('评论已关闭。', 'crolord'); ?></p><?php endif; ?>
    <?php
    $fieldnotes_form_args = array('title_reply' => __('一丝痕迹', 'crolord'));
    comment_form($fieldnotes_form_args);
    ?>
</section>
