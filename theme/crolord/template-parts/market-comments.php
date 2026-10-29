<?php
if (!defined('ABSPATH') || post_password_required()) return;
$comment_query = array(
    'post_id' => get_the_ID(), 'status' => 'approve', 'type' => 'comment',
    'number' => 0, 'orderby' => array('comment_date_gmt' => 'DESC', 'comment_ID' => 'DESC'),
    'order' => 'DESC', 'hierarchical' => false, 'no_found_rows' => true,
);
if (is_user_logged_in()) {
    $comment_query['include_unapproved'] = array(get_current_user_id());
} elseif (function_exists('wp_get_unapproved_comment_author_email')) {
    $unapproved_email = wp_get_unapproved_comment_author_email();
    if ($unapproved_email) $comment_query['include_unapproved'] = array($unapproved_email);
}
$all_comments = get_comments($comment_query);
$comments_by_id = array();
foreach ($all_comments as $item) $comments_by_id[(int) $item->comment_ID] = $item;
$thread_roots = array();
$root_for_comment = static function ($id) use (&$thread_roots, $comments_by_id) {
    if (isset($thread_roots[$id])) return $thread_roots[$id];
    $path = array(); $current = $id;
    while (isset($comments_by_id[$current]) && !isset($path[$current])) {
        $path[$current] = true;
        $parent = (int) $comments_by_id[$current]->comment_parent;
        if (!$parent || !isset($comments_by_id[$parent])) break;
        $current = $parent;
    }
    foreach ($path as $member => $unused) $thread_roots[$member] = $current;
    return $current;
};
$thread_ids = array();
foreach ($all_comments as $item) {
    $root = $root_for_comment((int) $item->comment_ID);
    if (!in_array($root, $thread_ids, true)) $thread_ids[] = $root;
}
$replies_by_root = array();
foreach (array_reverse($all_comments) as $item) {
    $id = (int) $item->comment_ID; $root = $root_for_comment($id);
    if ($id !== $root) $replies_by_root[$root][] = $id;
}
$board_pages = max(1, (int) ceil(count($thread_ids) / 5));
$board_page = isset($_GET['board_page']) && is_scalar($_GET['board_page']) ? min($board_pages, max(1, absint($_GET['board_page']))) : 1;
$query_args = array();
foreach (array('stock', 'period', 'adjust', 'notes_page', 'unapproved', 'moderation-hash') as $key) {
    if (isset($_GET[$key]) && is_scalar($_GET[$key])) $query_args[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
}
$base_url = add_query_arg($query_args, get_permalink(get_the_ID()));
// Keep one reply visible; all other replies belong to a simple local fold.
$comment_location = static function ($id) use ($root_for_comment, $thread_ids) {
    $root = $root_for_comment($id);
    $root_index = array_search($root, $thread_ids, true);
    $args = array('board_page' => (int) floor($root_index / 5) + 1);
    return $args;
};
$comment_url = static function ($id) use ($comment_location, $base_url) {
    return add_query_arg($comment_location($id), $base_url) . '#comment-' . $id;
};
$focus_id = isset($_GET['fct_submitted_comment']) && is_scalar($_GET['fct_submitted_comment']) ? absint($_GET['fct_submitted_comment']) : 0;
$focus_url = '';
$expanded_root = 0;
if ($focus_id && isset($comments_by_id[$focus_id])) {
    $focus_args = $comment_location($focus_id);
    $board_page = $focus_args['board_page'];
    $expanded_root = $root_for_comment($focus_id);
    $focus_url = $comment_url($focus_id);
}
$selected_roots = array();
foreach (array_slice($thread_ids, ($board_page - 1) * 5, 5) as $id) $selected_roots[] = $comments_by_id[$id];
$reply_author_context = static function ($link, $author, $id) use ($comments_by_id, $comment_url) {
    $item = $comments_by_id[(int) $id] ?? null;
    $parent = $item ? (int) $item->comment_parent : 0;
    if (!$parent || !isset($comments_by_id[$parent])) return $link;
    return $link . '<span class="fn-reply-target">回复 <a href="' . esc_url($comment_url($parent)) . '" aria-label="查看被回复的留言">' . esc_html(get_comment_author($parent)) . '</a></span>';
};
$comment_walker = new class($comments_by_id, $replies_by_root, $expanded_root) extends Walker_Comment {
    private $items; private $replies; private $expanded_root;
    public function __construct($items, $replies, $expanded_root) {
        if (method_exists('Walker_Comment', '__construct')) parent::__construct();
        $this->items = $items; $this->replies = $replies; $this->expanded_root = $expanded_root;
    }
    private function single_comment($item, $args) {
        $args['has_children'] = false; $this->has_children = false;
        $out = ''; $this->start_el($out, $item, 0, $args); $this->end_el($out, $item, 0, $args);
        return $out;
    }
    public function paged_walk($elements, $max_depth, $page_num, $per_page, ...$args) {
        $out = ''; $options = $args[0];
        foreach ($elements as $item) {
            $id = (int) $item->comment_ID;
            $replies = $this->replies[$id] ?? array();
            $options['has_children'] = !empty($replies); $this->has_children = !empty($replies);
            $this->start_el($out, $item, 0, $options);
            if ($replies) {
                $out .= '<ol class="fn-comment-replies fn-reply-preview" data-thread="' . esc_attr($id) . '">';
                foreach (array_slice($replies, 0, 1) as $reply) $out .= $this->single_comment($this->items[$reply], $options);
                $out .= '</ol>';
            }
            $remaining = array_slice($replies, 1);
            if ($remaining) {
                $out .= '<details class="fn-comment-more" id="fn-replies-' . esc_attr($id) . '" data-thread="' . esc_attr($id) . '"' . ($this->expanded_root === $id ? ' open' : '') . '><summary><span class="fn-replies-show">更多回复</span><span class="fn-replies-hide">收起回复</span></summary><ol class="fn-comment-replies fn-more-replies">';
                foreach ($remaining as $reply) $out .= $this->single_comment($this->items[$reply], $options);
                $out .= '</ol>';
                $out .= '</details>';
            }
            $this->end_el($out, $item, 0, $options);
        }
        return $out;
    }
};
$has = !empty($selected_roots);
?>
<div id="comments" class="comments-area <?php echo $has ? 'has-comments' : 'no-comments'; ?>"<?php if ($focus_url) : ?> data-fct-focus-url="<?php echo esc_url($focus_url); ?>"<?php endif; ?>>
    <?php if ($has) : ?>
        <?php add_filter('get_comment_author_link', $reply_author_context, 30, 3); ?>
        <ol class="comment-list"><?php wp_list_comments(array('style' => 'ol', 'format' => 'html5', 'short_ping' => true, 'avatar_size' => 40, 'max_depth' => max(3, (int) get_option('thread_comments_depth', 5)), 'walker' => $comment_walker, 'page' => 1, 'per_page' => 5, 'reverse_top_level' => false, 'reverse_children' => false), $selected_roots); ?></ol>
        <?php remove_filter('get_comment_author_link', $reply_author_context, 30); ?>
    <?php endif; ?>
    <?php if ($board_pages > 1) : ?><nav class="fn-comment-pagination" id="fn-comment-pagination" aria-label="留言分页">
        <?php if ($board_page > 1) : ?><a class="prev" href="<?php echo esc_url(add_query_arg('board_page', $board_page - 1, $base_url) . '#comments'); ?>">上一页</a><?php endif; ?>
        <?php if ($board_page < $board_pages) : ?><a class="next" href="<?php echo esc_url(add_query_arg('board_page', $board_page + 1, $base_url) . '#comments'); ?>">下一页</a><?php endif; ?>
    </nav><?php endif; ?>
    <?php comment_form(array('title_reply' => '写下此刻的想法', 'label_submit' => '留下留言', 'comment_notes_before' => '')); ?>
    <?php if (!comments_open()) : ?><p class="boughs-empty">留言暂时收起。已有的文字，仍在这里。</p><?php endif; ?>
</div>
