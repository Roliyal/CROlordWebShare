<?php
/** Local comment rules. Approval remains subject to WordPress and spam plugins. */
if (!defined('ABSPATH')) exit;

function fct_guard_defaults() {
    return array(
        'enabled' => 1,
        'link_limit' => 2,
        'blocked_words' => '',
        // These phrases require human review, including quotations and reports.
        'review_words' => implode("\n", array(
            '你妈死了', '去死吧', '杀你全家', '弄死你', '傻逼', '操你妈',
            'fuck you', 'kill yourself', '博彩开户', '真人荷官', '约炮加微信',
            '高价收购银行卡', '出售身份证', '办假证', '刷单返佣', '色情视频',
        )),
    );
}

function fct_guard_options() {
    $saved = get_option('fct_comment_guard', array());
    return array_merge(fct_guard_defaults(), is_array($saved) ? $saved : array());
}

function fct_guard_sanitize_words($input) {
    if (!is_string($input)) return '';
    $lines = preg_split('/\r\n|\r|\n/', sanitize_textarea_field($input));
    $result = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strlen($line) > 96 || count($result) >= 200) {
            add_settings_error('fct_comment_guard', 'fct_guard_word_limit', __('每份词表最多保存 200 行，每行不超过 96 字节。超出部分未保存。', 'crolord-comment-tools'));
            continue;
        }
        $result[] = $line;
    }
    return implode("\n", array_unique($result));
}

function fct_guard_sanitize($input) {
    $input = is_array($input) ? $input : array();
    $limit = isset($input['link_limit']) && is_scalar($input['link_limit']) ? (int) $input['link_limit'] : 2;
    return array(
        'enabled' => isset($input['enabled']) && is_scalar($input['enabled']) && (string) $input['enabled'] === '1' ? 1 : 0,
        'link_limit' => max(1, min(20, $limit)),
        'blocked_words' => fct_guard_sanitize_words($input['blocked_words'] ?? ''),
        'review_words' => fct_guard_sanitize_words($input['review_words'] ?? ''),
    );
}

function fct_guard_plain_text($text) {
    $text = wp_check_invalid_utf8($text, true);
    for ($i = 0; $i < 2; $i++) $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = wp_strip_all_tags($text);
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
        if (is_string($normalized)) $text = $normalized;
    }
    // Keep full-width matching consistent even without the PHP intl extension.
    static $width_map;
    if (!$width_map) {
        $wide = preg_split('//u', '！＂＃＄％＆＇（）＊＋，－．／０１２３４５６７８９：；＜＝＞？＠ＡＢＣＤＥＦＧＨＩＪＫＬＭＮＯＰＱＲＳＴＵＶＷＸＹＺ［＼］＾＿｀ａｂｃｄｅｆｇｈｉｊｋｌｍｎｏｐｑｒｓｔｕｖｗｘｙｚ｛｜｝～', -1, PREG_SPLIT_NO_EMPTY);
        $width_map = array_combine($wide, array_map('chr', range(33, 126)));
    }
    $text = strtr($text, $width_map);
    return preg_replace('/[\p{Cf}\p{M}]/u', '', $text) ?? '';
}

function fct_guard_word_match($fields, $list) {
    if (!is_string($list) || $list === '') return false;
    foreach (array_slice(explode("\n", $list), 0, 200) as $word) {
        if (strlen($word) > 96) continue;
        $word = fct_guard_plain_text($word);
        $key = preg_replace('/[\p{Z}\p{P}\s]/u', '', $word);
        if (!$key) continue;
        $chars = preg_split('//u', $key, -1, PREG_SPLIT_NO_EMPTY);
        $pattern = implode('[\p{Z}\p{P}\s]*', array_map(function ($char) { return preg_quote($char, '~'); }, $chars));
        // Latin words must not match the middle of an unrelated word.
        if (preg_match('/^[a-z0-9]+$/i', $key)) $pattern = '(?<![\p{L}\p{N}_])' . $pattern . '(?![\p{L}\p{N}_])';
        foreach ($fields as $field) {
            if (preg_match('~' . $pattern . '~iu', $field) === 1) return true;
        }
    }
    return false;
}

function fct_guard_decision($data) {
    $allow = array('action' => 'allow', 'reason' => '');
    $options = fct_guard_options();
    if (empty($options['enabled'])) return $allow;
    if (!empty($data['comment_type']) && $data['comment_type'] !== 'comment') return $allow;
    $fields = array();
    $raw = array();
    foreach (array('comment_author', 'comment_content', 'comment_author_url') as $key) {
        $value = $data[$key] ?? '';
        if (!is_string($value) || strlen($value) > 20000) return array('action' => 'hold', 'reason' => 'length');
        $raw[] = $value;
        $fields[] = fct_guard_plain_text($value);
    }
    if (fct_guard_word_match($fields, $options['blocked_words'])) return array('action' => 'block', 'reason' => 'blocked_word');
    if (fct_guard_word_match($fields, $options['review_words'])) return array('action' => 'hold', 'reason' => 'review_word');
    // Count distinct HTTP(S)/www addresses, not both an anchor's href and label.
    $links = array();
    foreach ($raw as $value) {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('~(?:https?://|www\.)[^\s<>"\x27]+~iu', $value, $matches);
        foreach ($matches[0] as $url) $links[strtolower(rtrim($url, '.,;:!?)]}'))] = true;
    }
    $limit = max(1, min(20, (int) $options['link_limit']));
    if (count($links) >= $limit) return array('action' => 'hold', 'reason' => 'links');
    return $allow;
}

function fct_guard_approval($approved, $data) {
    // Never revive spam/trash, replace an existing error or force a pending
    // comment to pass. WordPress's manual-review settings remain authoritative.
    if (!in_array($approved, array(0, 1, '0', '1'), true)) return $approved;
    $decision = fct_guard_decision($data);
    if ($decision['action'] === 'block') {
        return new WP_Error('fct_comment_blocked', __('留言包含站点禁止的内容，请调整后再试。', 'crolord-comment-tools'), 403);
    }
    return $decision['action'] === 'hold' ? 0 : $approved;
}
add_filter('pre_comment_approved', 'fct_guard_approval', PHP_INT_MAX, 2);

function fct_guard_save_reason($comment_id, $approved, $data) {
    if ((string) $approved !== '0') return;
    $decision = fct_guard_decision($data);
    if ($decision['action'] === 'hold') add_comment_meta($comment_id, '_fct_review_reason', $decision['reason'], true);
}
add_action('comment_post', 'fct_guard_save_reason', 30, 3);

function fct_guard_row_actions($actions, $comment) {
    if (!current_user_can('moderate_comments') || !($comment instanceof WP_Comment)) return $actions;
    $reason = get_comment_meta($comment->comment_ID, '_fct_review_reason', true);
    $labels = array(
        'review_word' => __('待审原因：命中词表', 'crolord-comment-tools'),
        'links' => __('待审原因：链接较多', 'crolord-comment-tools'),
        'length' => __('待审原因：内容长度或格式需确认', 'crolord-comment-tools'),
    );
    if (isset($labels[$reason])) $actions['fct_review_reason'] = '<span>' . esc_html($labels[$reason]) . '</span>';
    return $actions;
}
add_filter('comment_row_actions', 'fct_guard_row_actions', 10, 2);

function fct_guard_settings_intro() {
    echo '<p>' . esc_html__('正常留言沿用 WordPress 审批；命中待审规则的留言先不公开，禁止词直接拒绝提交。所有检查在本站完成，不向外部服务发送评论。', 'crolord-comment-tools') . '</p>';
    echo '<p>' . esc_html__('要让新访客的正常留言自动通过，请在本页取消「评论必须经人工批准」和「评论者必须曾有评论通过审核」，然后保存。已有反垃圾插件和 WordPress 其他规则继续生效。', 'crolord-comment-tools') . '</p>';
    echo '<p class="description">' . esc_html__('词表只能识别已配置的表达，可能误判引用，也可能漏掉谐音。请定期查看待审队列；本功能不自动删除旧评论，也不自动放行已有待审评论。', 'crolord-comment-tools') . '</p>';
}

function fct_guard_settings_field($args) {
    $options = fct_guard_options();
    $key = $args['key'];
    $name = 'fct_comment_guard[' . $key . ']';
    $id = 'fct_guard_' . $key;
    if ($key === 'enabled') {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="0"><label><input id="' . esc_attr($id) . '" type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked(!empty($options[$key]), true, false) . '> ' . esc_html__('启用本地留言规则检查', 'crolord-comment-tools') . '</label>';
    } elseif ($key === 'link_limit') {
        echo '<input id="' . esc_attr($id) . '" type="number" class="small-text" name="' . esc_attr($name) . '" min="1" max="20" value="' . esc_attr($options[$key]) . '">';
        echo '<p class="description">' . esc_html__('包含这么多个或更多不同的 HTTP(S)/www 地址时进入待审，默认 2。WordPress 自身的链接限制仍然有效。', 'crolord-comment-tools') . '</p>';
    } else {
        echo '<textarea id="' . esc_attr($id) . '" class="large-text" name="' . esc_attr($name) . '" rows="7" maxlength="20000">' . esc_textarea($options[$key]) . '</textarea>';
        $help = $key === 'blocked_words' ? __('每行一个。匹配昵称、正文和网站地址后拒绝提交。默认留空；只有确定不允许提交的表达才放在这里。', 'crolord-comment-tools') : __('每行一个。命中后暂不公开，交给你审核。内置少量辱骂、色情推广与广告短语，可以修改或删除；不会判断所有不良语义。', 'crolord-comment-tools');
        echo '<p class="description">' . esc_html($help) . '</p>';
        echo '<p class="description">' . esc_html__('忽略大小写、全角字母、零宽字符与词中穿插的空格和标点。最多 200 行，每行不超过 96 字节；不支持正则表达式，避免使用过短的单字。', 'crolord-comment-tools') . '</p>';
    }
}

function fct_guard_register_settings() {
    register_setting('discussion', 'fct_comment_guard', array('type' => 'array', 'sanitize_callback' => 'fct_guard_sanitize', 'default' => fct_guard_defaults(), 'show_in_rest' => false));
    add_settings_section('fct_comment_guard', __('Fieldnotes · 留言审核', 'crolord-comment-tools'), 'fct_guard_settings_intro', 'discussion');
    $fields = array('enabled' => __('留言检查', 'crolord-comment-tools'), 'review_words' => __('待审词', 'crolord-comment-tools'), 'blocked_words' => __('禁止词', 'crolord-comment-tools'), 'link_limit' => __('链接待审阈值', 'crolord-comment-tools'));
    foreach ($fields as $key => $label) add_settings_field('fct_guard_' . $key, $label, 'fct_guard_settings_field', 'discussion', 'fct_comment_guard', array('key' => $key, 'label_for' => 'fct_guard_' . $key));
}
add_action('admin_init', 'fct_guard_register_settings');

function fct_guard_settings_link($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('options-discussion.php#fct_guard_enabled')) . '">' . esc_html__('留言审核设置', 'crolord-comment-tools') . '</a>');
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(FCT_FILE), 'fct_guard_settings_link');
