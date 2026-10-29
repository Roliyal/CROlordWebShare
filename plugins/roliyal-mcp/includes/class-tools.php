<?php
defined('ABSPATH') || exit;
class Roliyal_MCP_Input_Error extends RuntimeException {}
class Roliyal_MCP_Tool_Error extends RuntimeException {}

final class Roliyal_MCP_Tools {
    private $credential;
    public function __construct($credential) { $this->credential = $credential ?: array(); }
    private function schema($properties = array(), $required = array()) {
        return array('type' => 'object', 'properties' => (object) $properties, 'required' => $required, 'additionalProperties' => false);
    }
    public function definitions() {
        $text = array('type' => 'string', 'maxLength' => 200);
        $id = array('type' => 'integer', 'minimum' => 1);
        $page = array('type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'default' => 1);
        $size = array('type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10);
        $specs = array(
            'site_info' => array('网站信息', '读取本站名称、简介、网站地址、公开内容类型和此连接的能力。', $this->schema()),
            'list_content' => array('文章与页面', '分页列出已公开且未受密码保护的内容，含摘要。post_type 使用 site_info 返回的公开内容类型；不提供草稿或私密文章。', $this->schema(array('post_type' => $text, 'search' => $text, 'category_id' => $id, 'page' => $page, 'per_page' => $size))),
            'get_content' => array('读取正文', '读取已公开文章或页面的纯文本正文。不执行短代码、动态区块或主题插件。长文可用 offset/limit 连续读取，检查 next_offset。', $this->schema(array('id' => $id, 'offset' => array('type' => 'integer', 'minimum' => 0), 'limit' => array('type' => 'integer', 'minimum' => 100, 'maximum' => 20000, 'default' => 12000)), array('id'))),
            'list_terms' => array('分类与标签', '查询公开分类或标签。默认 category，也支持 post_tag 及公开内容类型使用的公开分类法。', $this->schema(array('taxonomy' => $text, 'search' => $text, 'page' => $page, 'per_page' => $size))),
            'list_comments' => array('文章公开评论', '读取指定公开文章已审核通过的公开评论，不返回邮箱、IP、用户账号 ID 或待审留言。', $this->schema(array('post_id' => $id, 'page' => $page, 'per_page' => $size), array('post_id'))),
        );
        if (!empty($this->credential['drafts']) && current_user_can('edit_posts')) {
            $specs['list_drafts'] = array('查询草稿', '查询此 WordPress 用户有权编辑的普通文章草稿，不包含私密文章、密码保护文章或其他内容类型。', $this->schema(array('search' => $text, 'page' => $page, 'per_page' => $size)));
            $specs['get_draft'] = array('读取草稿', '读取有权编辑的普通文章草稿及 revision，用于确认后续修改。', $this->schema(array('id' => $id), array('id')));
            $draft = array('title' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 300), 'content' => array('type' => 'string', 'maxLength' => 120000), 'excerpt' => array('type' => 'string', 'maxLength' => 2000));
            $specs['create_draft'] = array('新建草稿', '只创建普通文章草稿，不公开发布。request_id 为 8–80 位唯一英文/数字/下划线/连字符，用于避免重试产生重复草稿。请在用户明确要求创建草稿时调用。', $this->schema($draft + array('request_id' => array('type' => 'string', 'minLength' => 8, 'maxLength' => 80, 'pattern' => '^[a-zA-Z0-9_-]+$')), array('title', 'content', 'request_id')));
            $specs['update_draft'] = array('修改草稿', '仅修改有权编辑的普通文章草稿。必须提供 get_draft 返回的 revision；已发布内容不会被此工具改写。请在用户明确要求修改草稿时调用。', $this->schema(array('id' => $id, 'revision' => array('type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[a-f0-9]+$')) + $draft, array('id', 'revision')));
        }
        $out = array();
        foreach ($specs as $name => $s) {
            $write = in_array($name, array('create_draft', 'update_draft'), true);
            $out[$name] = array('name' => $name, 'title' => $s[0], 'description' => $s[1], 'inputSchema' => $s[2], 'annotations' => array('readOnlyHint' => !$write, 'destructiveHint' => $name === 'update_draft', 'idempotentHint' => true, 'openWorldHint' => false));
        }
        return $out + (new Roliyal_MCP_Management($this->credential))->definitions();
    }
    public function call($name, $args) {
        $definitions = $this->definitions();
        if (!isset($definitions[$name])) { throw new Roliyal_MCP_Input_Error('未知工具或当前连接没有该工具权限。'); }
        $schema = $definitions[$name]['inputSchema'];
        self::validate((object) $args, $schema, 'arguments');
        $management = new Roliyal_MCP_Management($this->credential);
        if (isset($management->definitions()[$name])) { return $management->call($name, $args); }
        return $this->$name($args);
    }
    public static function validate($value, $schema, $path) {
        $type = $schema['type']; $valid = true;
        if ($type === 'object') {
            if (!is_object($value)) { $valid = false; }
            else {
                $props = (array) $schema['properties'];
                foreach ($schema['required'] ?? array() as $key) { if (!property_exists($value, $key)) { throw new Roliyal_MCP_Input_Error('缺少参数：' . $path . '.' . $key); } }
                foreach (get_object_vars($value) as $key => $item) {
                    if (!isset($props[$key])) { throw new Roliyal_MCP_Input_Error('不支持参数：' . $path . '.' . $key); }
                    self::validate($item, $props[$key], $path . '.' . $key);
                }
            }
        } elseif ($type === 'integer') { $valid = is_int($value) && $value >= ($schema['minimum'] ?? 0) && $value <= ($schema['maximum'] ?? PHP_INT_MAX); }
        elseif ($type === 'boolean') { $valid = is_bool($value); }
        elseif ($type === 'array') {
            $valid = is_array($value) && array_values($value) === $value && count($value) >= ($schema['minItems'] ?? 0) && count($value) <= ($schema['maxItems'] ?? 100);
            if ($valid && !empty($schema['uniqueItems']) && count(array_unique($value, SORT_REGULAR)) !== count($value)) { $valid = false; }
            if ($valid) { foreach ($value as $i => $item) { self::validate($item, $schema['items'], $path . '[' . $i . ']'); } }
        } elseif ($type === 'string') {
            $valid = is_string($value) && mb_strlen($value) <= ($schema['maxLength'] ?? 200) && mb_strlen($value) >= ($schema['minLength'] ?? 0)
                && (!isset($schema['pattern']) || preg_match('~' . $schema['pattern'] . '~D', $value));
        } else { $valid = false; }
        if (!$valid || (isset($schema['enum']) && !in_array($value, $schema['enum'], true))) { throw new Roliyal_MCP_Input_Error('参数格式或范围错误：' . $path); }
    }
    private function types() {
        $out = array();
        foreach (get_post_types(array('public' => true), 'objects') as $name => $type) {
            if ($name !== 'attachment' && is_post_type_viewable($type)) { $out[$name] = $type->labels->singular_name; }
        }
        return $out;
    }
    private function readable($post) {
        return $post instanceof WP_Post && $post->post_status === 'publish' && $post->post_password === '' && isset($this->types()[$post->post_type]);
    }
    private function plain($html) {
        return trim(html_entity_decode(wp_strip_all_tags(strip_shortcodes($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    private function summary($post) {
        $image = get_the_post_thumbnail_url($post, 'large');
        return array('id' => $post->ID, 'type' => $post->post_type, 'status' => $post->post_status, 'title' => $this->plain($post->post_title), 'slug' => $post->post_name,
            'url' => $post->post_status === 'publish' ? get_permalink($post) : '', 'date_gmt' => $post->post_date_gmt, 'modified_gmt' => $post->post_modified_gmt,
            'excerpt' => mb_substr($this->plain($post->post_excerpt !== '' ? $post->post_excerpt : $post->post_content), 0, 800), 'featured_image' => $image ?: null);
    }
    private function pagination($args) { return array($args['page'] ?? 1, $args['per_page'] ?? 10); }
    private function site_info($args) {
        return array('name' => get_bloginfo('name'), 'description' => get_bloginfo('description'), 'url' => home_url('/'), 'timezone' => wp_timezone_string(), 'language' => get_bloginfo('language'),
            'content_types' => $this->types(), 'connection' => array('public_content' => true, 'drafts' => !empty($this->credential['drafts']) && current_user_can('edit_posts'), 'publishing' => Roliyal_MCP_Scopes::allowed($this->credential, 'content') && current_user_can('publish_posts'), 'management_scopes' => Roliyal_MCP_Scopes::active($this->credential)), 'tools' => array_keys($this->definitions()));
    }
    private function list_content($args) {
        $type = $args['post_type'] ?? 'post';
        if (!isset($this->types()[$type])) { throw new Roliyal_MCP_Input_Error('内容类型未公开或不可访问。'); }
        list($page, $size) = $this->pagination($args);
        $query = array('post_type' => $type, 'post_status' => 'publish', 'has_password' => false, 's' => $args['search'] ?? '', 'posts_per_page' => $size, 'paged' => $page, 'orderby' => array('date' => 'DESC', 'ID' => 'DESC'), 'ignore_sticky_posts' => true);
        if (isset($args['category_id'])) { $query['cat'] = $args['category_id']; }
        $q = new WP_Query($query);
        $items = array(); foreach ($q->posts as $post) { if ($this->readable($post)) { $items[] = $this->summary($post); } }
        return array('items' => $items, 'page' => $page, 'total' => (int) $q->found_posts, 'total_pages' => (int) $q->max_num_pages);
    }
    private function get_content($args) {
        $p = get_post($args['id']);
        if (!$this->readable($p)) { throw new Roliyal_MCP_Tool_Error('未找到可公开读取的内容。'); }
        $text = $this->plain($p->post_content);
        $offset = $args['offset'] ?? 0; $limit = $args['limit'] ?? 12000; $total = mb_strlen($text);
        return $this->summary($p) + array('content' => mb_substr($text, $offset, $limit), 'format' => 'plain_text', 'offset' => $offset, 'total_characters' => $total, 'next_offset' => $offset + $limit < $total ? $offset + $limit : null);
    }
    private function list_terms($args) {
        $taxonomy = $args['taxonomy'] ?? 'category';
        $t = get_taxonomy($taxonomy);
        if (!$t || !$t->public || !array_intersect($t->object_type, array_keys($this->types()))) { throw new Roliyal_MCP_Input_Error('该分类法未公开。'); }
        list($page, $size) = $this->pagination($args);
        $q = array('taxonomy' => $taxonomy, 'hide_empty' => true, 'search' => $args['search'] ?? '', 'number' => $size, 'offset' => ($page - 1) * $size, 'orderby' => 'term_id', 'order' => 'ASC');
        $terms = get_terms($q);
        if (is_wp_error($terms)) { throw new Roliyal_MCP_Tool_Error('分类读取失败。'); }
        $items = array(); foreach ($terms as $term) { $items[] = array('id' => $term->term_id, 'name' => $this->plain($term->name), 'slug' => $term->slug, 'description' => $this->plain($term->description), 'count' => $term->count); }
        return array('taxonomy' => $taxonomy, 'items' => $items, 'page' => $page, 'has_more' => count($items) === $size);
    }
    private function list_comments($args) {
        if (!$this->readable(get_post($args['post_id']))) { throw new Roliyal_MCP_Tool_Error('未找到可公开读取的文章。'); }
        list($page, $size) = $this->pagination($args);
        $base = array('post_id' => $args['post_id'], 'status' => 'approve', 'type' => 'comment');
        $comments = get_comments($base + array('number' => $size, 'offset' => ($page - 1) * $size, 'orderby' => 'comment_ID', 'order' => 'DESC'));
        $items = array(); foreach ($comments as $c) { $items[] = array('id' => (int) $c->comment_ID, 'author' => $this->plain($c->comment_author), 'content' => mb_substr($this->plain($c->comment_content), 0, 8000), 'date_gmt' => $c->comment_date_gmt, 'parent' => (int) $c->comment_parent); }
        return array('post_id' => $args['post_id'], 'items' => $items, 'page' => $page, 'total' => (int) get_comments($base + array('count' => true)));
    }
    private function draft($id) {
        $p = get_post($id);
        if (!$p || $p->post_type !== 'post' || $p->post_status !== 'draft' || $p->post_password !== '' || !current_user_can('edit_post', $id)) { throw new Roliyal_MCP_Tool_Error('未找到有权编辑的普通文章草稿。'); }
        return $p;
    }
    private function revision($p) { return hash('sha256', wp_json_encode(array($p->post_modified_gmt, $p->post_title, $p->post_content, $p->post_excerpt))); }
    private function list_drafts($args) {
        list($page, $size) = $this->pagination($args);
        $query = array('post_type' => 'post', 'post_status' => 'draft', 'has_password' => false, 's' => $args['search'] ?? '', 'posts_per_page' => $size, 'paged' => $page, 'orderby' => array('modified' => 'DESC', 'ID' => 'DESC'));
        if (!current_user_can('edit_others_posts')) { $query['author'] = get_current_user_id(); }
        $q = new WP_Query($query);
        $items = array(); foreach ($q->posts as $p) { if (current_user_can('edit_post', $p->ID)) { $items[] = $this->summary($p); } }
        return array('items' => $items, 'page' => $page, 'total' => (int) $q->found_posts, 'total_pages' => (int) $q->max_num_pages);
    }
    private function get_draft($args) {
        $p = $this->draft($args['id']);
        // Draft source is intentionally available only to explicitly authorized editor credentials.
        return $this->summary($p) + array('content' => mb_substr($p->post_content, 0, 120000), 'format' => 'wordpress_source', 'revision' => $this->revision($p), 'truncated' => mb_strlen($p->post_content) > 120000);
    }
    private function create_draft($args) {
        if (trim(wp_strip_all_tags($args['title'])) === '') { throw new Roliyal_MCP_Input_Error('标题不能为空。'); }
        $key = 'rlm_create_' . hash('sha256', get_current_user_id() . ':' . $args['request_id']);
        $fingerprint = hash('sha256', wp_json_encode(array($args['title'], $args['content'], $args['excerpt'] ?? '')));
        $old = get_option($key);
        if (is_array($old)) {
            if (($old['fingerprint'] ?? '') !== $fingerprint) { throw new Roliyal_MCP_Tool_Error('request_id 已用于其他内容，请使用新的唯一值。'); }
            if (empty($old['id'])) { throw new Roliyal_MCP_Tool_Error('同一草稿正在创建或上次结果不明；请先查询草稿，勿更换 request_id 盲目重试。'); }
            $p = $this->draft((int) $old['id']);
            return array('id' => $p->ID, 'status' => 'draft', 'duplicate_prevented' => true, 'revision' => $this->revision($p));
        }
        if (!add_option($key, array('fingerprint' => $fingerprint, 'id' => 0), '', false)) { throw new Roliyal_MCP_Tool_Error('同一 request_id 正在处理中，请稍后重试。'); }
        $id = wp_insert_post(wp_slash(array('post_type' => 'post', 'post_status' => 'draft', 'post_author' => get_current_user_id(), 'post_title' => sanitize_text_field($args['title']), 'post_content' => wp_kses_post($args['content']), 'post_excerpt' => sanitize_textarea_field($args['excerpt'] ?? ''))), true);
        if (is_wp_error($id) || !$id) { delete_option($key); throw new Roliyal_MCP_Tool_Error('WordPress 创建草稿失败。'); }
        update_option($key, array('fingerprint' => $fingerprint, 'id' => $id), false);
        return array('id' => $id, 'status' => 'draft', 'duplicate_prevented' => false, 'revision' => $this->revision(get_post($id)));
    }
    private function update_draft($args) {
        $p = $this->draft($args['id']);
        if (!hash_equals($this->revision($p), $args['revision'])) { throw new Roliyal_MCP_Tool_Error('草稿已变更，请重新读取后再编辑。'); }
        if (!isset($args['title']) && !isset($args['content']) && !isset($args['excerpt'])) { throw new Roliyal_MCP_Input_Error('至少提供 title、content、excerpt 中的一项。'); }
        if (isset($args['title']) && trim(wp_strip_all_tags($args['title'])) === '') { throw new Roliyal_MCP_Input_Error('标题不能为空。'); }
        $lock = 'rlm_edit_' . $p->ID;
        if (!add_option($lock, time(), '', false)) { throw new Roliyal_MCP_Tool_Error('草稿正在编辑，请稍后重试；若持续出现，请管理员检查遗留锁。'); }
        try {
            clean_post_cache($p->ID); $p = $this->draft($p->ID);
            if (!hash_equals($this->revision($p), $args['revision'])) { throw new Roliyal_MCP_Tool_Error('草稿已变更，请重新读取。'); }
            $data = array('ID' => $p->ID, 'post_status' => 'draft');
            if (isset($args['title'])) { $data['post_title'] = sanitize_text_field($args['title']); }
            if (isset($args['content'])) { $data['post_content'] = wp_kses_post($args['content']); }
            if (isset($args['excerpt'])) { $data['post_excerpt'] = sanitize_textarea_field($args['excerpt']); }
            $id = wp_update_post(wp_slash($data), true);
            if (is_wp_error($id) || !$id) { throw new Roliyal_MCP_Tool_Error('WordPress 修改草稿失败。'); }
            return array('id' => $id, 'status' => 'draft', 'revision' => $this->revision(get_post($id)));
        } finally { delete_option($lock); }
    }
}
