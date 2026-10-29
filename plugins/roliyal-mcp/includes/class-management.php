<?php
defined('ABSPATH') || exit;

/** Scoped operations use WordPress APIs; no arbitrary options, paths, SQL or PHP execution. */
final class Roliyal_MCP_Management {
    private $credential;
    public function __construct($credential) { $this->credential = $credential ?: array(); }
    private function has($scope) { return Roliyal_MCP_Scopes::allowed($this->credential, $scope); }
    private function object($properties, $required = array()) { return array('type' => 'object', 'properties' => (object) $properties, 'required' => $required, 'additionalProperties' => false); }
    private function text($max = 200, $min = 0) { return array('type' => 'string', 'maxLength' => $max, 'minLength' => $min); }
    private function number($min = 1, $max = PHP_INT_MAX) { return array('type' => 'integer', 'minimum' => $min, 'maximum' => $max); }
    private function choice($values) { return $this->text(200) + array('enum' => $values); }
    private function ids() { return array('type' => 'array', 'items' => $this->number(), 'maxItems' => 50, 'uniqueItems' => true); }
    private function paging() { return array('page' => $this->number(1, 10000), 'per_page' => $this->number(1, 20), 'search' => $this->text()); }
    private function revision_schema() { return $this->text(64, 64) + array('pattern' => '^[a-f0-9]+$'); }
    private function request_schema() { return $this->text(80, 8) + array('pattern' => '^[a-zA-Z0-9_-]+$'); }
    private function content_fields() {
        return array('title' => $this->text(300, 1), 'content' => $this->text(120000), 'excerpt' => $this->text(2000), 'slug' => $this->text(200),
            'status' => $this->choice(array('draft', 'pending', 'publish', 'private', 'future')), 'date_gmt' => $this->text(19, 19),
            'category_ids' => $this->ids(), 'tag_ids' => $this->ids(), 'featured_media' => $this->number(0), 'comment_status' => $this->choice(array('open', 'closed')));
    }
    private function settings_schema() {
        return $this->object(array(
            'blogname' => $this->text(200, 1), 'blogdescription' => $this->text(1000), 'timezone_string' => $this->text(100, 1),
            'date_format' => $this->text(80, 1), 'time_format' => $this->text(80, 1), 'start_of_week' => $this->number(0, 6),
            'posts_per_page' => $this->number(1, 100), 'posts_per_rss' => $this->number(1, 100), 'rss_use_excerpt' => $this->number(0, 1),
            'blog_public' => $this->number(0, 1), 'show_on_front' => $this->choice(array('posts', 'page')), 'page_on_front' => $this->number(0), 'page_for_posts' => $this->number(0),
            'default_comment_status' => $this->choice(array('open', 'closed')), 'default_ping_status' => $this->choice(array('open', 'closed')),
            'comment_moderation' => $this->number(0, 1), 'comment_registration' => $this->number(0, 1), 'require_name_email' => $this->number(0, 1),
            'close_comments_for_old_posts' => $this->number(0, 1), 'close_comments_days_old' => $this->number(1, 3650),
            'permalink_structure' => $this->text(200), 'category_base' => $this->text(100), 'tag_base' => $this->text(100), 'default_category' => $this->number(),
        ));
    }
    public function definitions() {
        $specs = array(); $id = $this->number(); $rev = $this->revision_schema(); $request = $this->request_schema(); $bool = array('type' => 'boolean');
        $add = function ($name, $title, $description, $properties, $required = array(), $write = false, $destructive = false, $external = false, $idempotent = false) use (&$specs) {
            $specs[$name] = array('name' => $name, 'title' => $title, 'description' => $description, 'inputSchema' => $this->object($properties, $required),
                'annotations' => array('readOnlyHint' => !$write, 'destructiveHint' => $destructive, 'idempotentHint' => !$write || $idempotent, 'openWorldHint' => $external));
        };
        if ($this->has('content') || $this->has('delete')) {
            $add('list_editable_content', '管理内容列表', '查询此用户有权管理的文章或页面，包括草稿、已发布、私密及回收站内容。只支持 post/page。', $this->paging() + array('post_type' => $this->choice(array('post', 'page')), 'status' => $this->choice(array('draft', 'pending', 'publish', 'private', 'future', 'trash'))));
            $add('get_editable_content', '读取可编辑内容', '读取文章或页面的 WordPress 源码、分类标签、封面与 revision。后续修改、发布、删除前先读取；truncated=true 时勿用截断正文覆盖。', array('id' => $id), array('id'));
        }
        if ($this->has('content')) {
            $add('create_content', '创建与发布文章或页面', '按用户要求新建文章或页面。默认 draft；status=publish 立即公开，future 需未来的 date_gmt（UTC，YYYY-MM-DD HH:MM:SS）。request_id 防重复；返回实际状态及链接。', $this->content_fields() + array('post_type' => $this->choice(array('post', 'page')), 'request_id' => $request), array('title', 'content', 'request_id'), true, false, true, true);
            $add('update_content', '编辑或发布现有内容', '可直接修改已发布文章或页面，也可将草稿发布。必须提供最新 get_editable_content 的 revision；仅传需要修改的字段。status=publish 会公开内容。', array('id' => $id, 'revision' => $rev) + $this->content_fields(), array('id', 'revision'), true, true, true);
            $add('create_term', '创建分类或标签', '新建 category 分类或 post_tag 标签。需要分类法管理权限；request_id 用于重试去重。', array('taxonomy' => $this->choice(array('category', 'post_tag')), 'name' => $this->text(200, 1), 'slug' => $this->text(), 'description' => $this->text(2000), 'parent' => $this->number(0), 'request_id' => $request), array('taxonomy', 'name', 'request_id'), true, false, false, true);
        }
        if ($this->has('delete')) {
            $add('delete_content', '删除文章或页面', '默认进入回收站。force=true 会永久删除文章/页面及相关数据，无法通过本插件恢复。需最新 revision 与 delete_post 权限。', array('id' => $id, 'revision' => $rev, 'force' => $bool), array('id', 'revision'), true, true, true);
            $add('restore_content', '恢复回收站内容', '恢复已在回收站的文章或页面。使用 WordPress 记录的原状态；若原状态为 publish，恢复会重新公开，需发布权限。', array('id' => $id, 'revision' => $rev), array('id', 'revision'), true, true, true);
        }
        if ($this->has('media')) {
            $add('list_media', '查询媒体库', '分页查询此用户可编辑的媒体，可按 MIME 类型筛选。', $this->paging() + array('mime_type' => $this->text(100)));
            $add('get_media', '读取媒体资料', '读取文件 URL、类型、标题、说明、替代文字和 revision，不返回服务器绝对路径。', array('id' => $id), array('id'));
            $add('upload_media', '上传图片或媒体', '上传 WordPress 允许的图片、音视频或其他文件，最多 10 MiB 且不超过本站上传限制。可用 artifact_id 选择 echoes_list_files 列出的当前访客回复文件，由 Echoes 读取真实字节；不要让模型复写二进制。其他客户端使用 source_url（公开 HTTPS）或 data_base64（原始 Base64），三种来源仅选一项。媒体将获得可访问 URL。request_id 防重复。', array('filename' => $this->text(200, 1), 'source_url' => $this->text(2000, 1), 'data_base64' => $this->text(13981016, 1), 'artifact_id' => $this->text(32, 32) + array('pattern' => '^[a-f0-9]{32}$'), 'title' => $this->text(300), 'caption' => $this->text(2000), 'description' => $this->text(5000), 'alt_text' => $this->text(1000), 'post_id' => $this->number(0), 'request_id' => $request), array('filename', 'request_id'), true, false, true, true);
            $add('update_media', '修改媒体资料', '修改媒体标题、说明、图片替代文字或关联文章，不修改二进制文件。需 get_media 的最新 revision。', array('id' => $id, 'revision' => $rev, 'title' => $this->text(300), 'caption' => $this->text(2000), 'description' => $this->text(5000), 'alt_text' => $this->text(1000), 'post_id' => $this->number(0)), array('id', 'revision'), true, true);
            if ($this->has('delete')) { $add('delete_media', '永久删除媒体', '永久删除媒体库记录、原文件和 WordPress 生成的缩略图，其他文章引用的图片会失效；此操作没有回收站。需最新 get_media revision。', array('id' => $id, 'revision' => $rev), array('id', 'revision'), true, true, true); }
        }
        if ($this->has('extensions')) {
            $add('list_plugins', '已安装插件', '列出本站普通插件、启用状态、版本、缓存中的可更新版本和 revision；不主动联网检查更新。MU 插件不在管理范围。', array());
            $add('manage_plugin', '启停、更新或删除插件', 'plugin 必须是 list_plugins 返回的文件标识。提供其最新 revision。删除前先停用；update 使用 WordPress 已记录的更新源。Roliyal MCP 自身不能在此修改。主题/插件安装启用会运行其代码，只按用户明确要求操作。', array('plugin' => $this->text(250, 1), 'action' => $this->choice(array('activate', 'deactivate', 'update', 'delete')), 'revision' => $rev), array('plugin', 'action', 'revision'), true, true, true);
            $add('install_plugin', '安装插件', '使用 WordPress.org slug 或公开 HTTPS ZIP package_url 二选一。默认仅安装；activate=true 同时启用。需要 WordPress 允许文件修改、直接写入文件系统及安装权限。request_id 防重复安装。', array('slug' => $this->text(100, 1), 'package_url' => $this->text(2000, 1), 'activate' => $bool, 'request_id' => $request), array('request_id'), true, true, true, true);
            $add('list_themes', '已安装主题', '读取已安装主题、当前主题、父主题、缓存中的可更新版本和 revision。', array());
            $add('manage_theme', '切换、更新或删除主题', 'stylesheet 使用 list_themes 返回值，revision 必须是最新值。不能删除当前主题或其父主题；activate 会改变前台展示。', array('stylesheet' => $this->text(200, 1), 'action' => $this->choice(array('activate', 'update', 'delete')), 'revision' => $rev), array('stylesheet', 'action', 'revision'), true, true, true);
            $add('install_theme', '安装主题', '使用 WordPress.org slug 或公开 HTTPS ZIP package_url 二选一。默认仅安装；activate=true 安装后启用。保留现有文件，不覆盖已安装的同目录主题。request_id 防重复。', array('slug' => $this->text(100, 1), 'package_url' => $this->text(2000, 1), 'activate' => $bool, 'request_id' => $request), array('request_id'), true, true, true, true);
        }
        if ($this->has('users')) {
            $add('list_users', '管理用户列表', '分页列出用户 ID、登录名、邮箱、显示名称、角色及 revision。邮箱属于管理数据；不会返回密码、密钥或会话。', $this->paging());
            $add('get_user', '用户资料与可用角色', '读取可编辑用户的资料、revision 与当前管理员允许分配的角色。不返回密码或认证信息。', array('id' => $id), array('id'));
            $fields = array('email' => $this->text(254, 1), 'display_name' => $this->text(200, 1), 'first_name' => $this->text(100), 'last_name' => $this->text(100), 'description' => $this->text(2000), 'role' => $this->text(100, 1), 'password' => $this->text(128, 12));
            $add('create_user', '新建用户', '按明确要求创建 WordPress 用户，默认 subscriber。password 至少 12 字符；不会返回或记录明文，不发送邮件。角色需当前用户具备 promote_users 权限且可分配。request_id 防重复。', array('login' => $this->text(60, 1), 'request_id' => $request) + $fields, array('login', 'email', 'password', 'request_id'), true, false, false, true);
            $add('update_user', '修改用户或角色', '按用户要求修改资料、密码或角色，需 get_user 的最新 revision。不能修改当前 MCP 密钥所属账户的角色/密码/邮箱，也不能降级最后一位管理员。', array('id' => $id, 'revision' => $rev) + $fields, array('id', 'revision'), true, true, true);
            $add('delete_user', '删除用户并转移文章', '永久删除指定用户；必须提供有效的 reassign_to，将其文章保留并转移。不能删除当前连接的账户、超级管理员或最后一位管理员。需最新 get_user revision。', array('id' => $id, 'revision' => $rev, 'reassign_to' => $id), array('id', 'revision', 'reassign_to'), true, true);
        }
        if ($this->has('settings')) {
            $add('get_settings', '读取网站设置', '读取常用设置及 revision，不暴露数据库配置、插件密钥、home/siteurl 等连接关键项。', array());
            $add('update_settings', '修改网站设置', '只修改 values 中提供的常用字段。需要最新 get_settings revision。固定链接更新会软刷新 WordPress 重写规则；Nginx/Apache 仍需已有前端控制器规则。', array('revision' => $rev, 'values' => $this->settings_schema()), array('revision', 'values'), true, true, true);
        }
        return $specs;
    }
    public function call($name, $args) {
        if (!isset($this->definitions()[$name])) { throw new Roliyal_MCP_Input_Error('当前连接无此管理权限。'); }
        return $this->$name($args);
    }
    private function need($cap, ...$args) { if (!current_user_can($cap, ...$args)) { throw new Roliyal_MCP_Tool_Error('WordPress 用户没有执行此操作的权限：' . $cap); } }
    private function result($value, $label) {
        if (is_wp_error($value)) { throw new Roliyal_MCP_Tool_Error($label . '失败（' . sanitize_key($value->get_error_code()) . '）。'); }
        if ($value === false || $value === null || $value === 0) { throw new Roliyal_MCP_Tool_Error($label . '未完成；请检查 WordPress 后台。'); }
        return $value;
    }
    private function hash($value) { return hash('sha256', wp_json_encode($value)); }
    private function expect($actual, $provided) { if (!hash_equals($actual, $provided)) { throw new Roliyal_MCP_Tool_Error('目标已变更，请重新读取最新 revision 后再操作。'); } }
    private function locked($key, $callback) {
        $key = preg_match('/^content:(\d+)$/D', $key, $m) ? 'rlm_edit_' . $m[1] : 'rlm_lock_' . hash('sha256', $key);
        if (!add_option($key, time(), '', false)) { throw new Roliyal_MCP_Tool_Error('目标正在处理中；持续出现时请管理员核对遗留锁与操作结果。'); }
        try { return $callback(); } finally { delete_option($key); }
    }
    private function once($operation, $args, $callback) {
        $key = 'rlm_once_' . hash('sha256', get_current_user_id() . ':' . $operation . ':' . $args['request_id']);
        $fingerprinted = $args; ksort($fingerprinted);
        $fingerprint = $this->hash($fingerprinted); $old = get_option($key);
        if (is_array($old)) {
            if (!hash_equals($old['fingerprint'], $fingerprint)) { throw new Roliyal_MCP_Tool_Error('request_id 已用于另一组参数，请使用新的唯一值。'); }
            if (!isset($old['result'])) { throw new Roliyal_MCP_Tool_Error('上次操作仍在进行或结果不明。请先查询 WordPress 实际结果，勿更换 request_id 盲目重试。'); }
            return $old['result'] + array('duplicate_prevented' => true);
        }
        if (!add_option($key, array('fingerprint' => $fingerprint), '', false)) { throw new Roliyal_MCP_Tool_Error('相同请求正在执行。'); }
        // Keep pending markers on exceptions: lifecycle hooks can fail after a side effect.
        $value = $callback();
        update_option($key, array('fingerprint' => $fingerprint, 'result' => $value), false);
        return $value + array('duplicate_prevented' => false);
    }
    private function content($id) {
        $p = get_post($id);
        if (!$p || !in_array($p->post_type, array('post', 'page'), true) || (!current_user_can('edit_post', $id) && !current_user_can('delete_post', $id))) { throw new Roliyal_MCP_Tool_Error('未找到有权管理的文章或页面。'); }
        return $p;
    }
    private function content_revision($p) {
        return $this->hash(array($p->post_type, $p->post_status, $p->post_title, $p->post_content, $p->post_excerpt, $p->post_name, $p->post_author, $p->post_date_gmt, $p->post_modified_gmt, $p->comment_status, $p->post_password, (int) get_post_thumbnail_id($p->ID), wp_get_post_categories($p->ID), wp_get_post_tags($p->ID, array('fields' => 'ids'))));
    }
    private function content_data($p, $body = true) {
        $data = array('id' => $p->ID, 'type' => $p->post_type, 'title' => $p->post_title, 'status' => $p->post_status, 'slug' => $p->post_name, 'author_id' => (int) $p->post_author,
            'url' => in_array($p->post_status, array('publish', 'private'), true) ? get_permalink($p) : '', 'edit_url' => admin_url('post.php?post=' . $p->ID . '&action=edit'),
            'date_gmt' => $p->post_date_gmt, 'modified_gmt' => $p->post_modified_gmt, 'revision' => $this->content_revision($p));
        if ($body) { $data += array('content' => mb_substr($p->post_content, 0, 120000), 'excerpt' => $p->post_excerpt, 'format' => 'wordpress_source', 'truncated' => mb_strlen($p->post_content) > 120000,
            'category_ids' => wp_get_post_categories($p->ID), 'tag_ids' => wp_get_post_tags($p->ID, array('fields' => 'ids')), 'featured_media' => (int) get_post_thumbnail_id($p->ID), 'comment_status' => $p->comment_status); }
        return $data;
    }
    private function list_editable_content($args) {
        $type = $args['post_type'] ?? 'post'; $type_obj = get_post_type_object($type); $this->need($type_obj->cap->edit_posts);
        $query = array('post_type' => $type, 'post_status' => $args['status'] ?? array('draft', 'pending', 'publish', 'private', 'future'), 'posts_per_page' => $args['per_page'] ?? 10, 'paged' => $args['page'] ?? 1, 's' => $args['search'] ?? '', 'orderby' => array('modified' => 'DESC', 'ID' => 'DESC'));
        if (!current_user_can($type_obj->cap->edit_others_posts)) { $query['author'] = get_current_user_id(); }
        $q = new WP_Query($query); $items = array();
        foreach ($q->posts as $p) { if (current_user_can('edit_post', $p->ID) || current_user_can('delete_post', $p->ID)) { $items[] = $this->content_data($p, false); } }
        return array('items' => $items, 'page' => $query['paged'], 'total' => (int) $q->found_posts, 'total_pages' => (int) $q->max_num_pages);
    }
    private function get_editable_content($args) { return $this->content_data($this->content($args['id'])); }
    private function content_input($args, $type, $old = null) {
        $object = get_post_type_object($type); $this->need($object->cap->edit_posts);
        $status = $args['status'] ?? ($old ? $old->post_status : 'draft');
        if (in_array($status, array('publish', 'private', 'future'), true)) { $this->need($object->cap->publish_posts); }
        if (isset($args['title']) && trim(wp_strip_all_tags($args['title'])) === '') { throw new Roliyal_MCP_Input_Error('标题不能为空。'); }
        if ($old && $old->post_status === 'trash') { throw new Roliyal_MCP_Tool_Error('请先恢复回收站内容再编辑。'); }
        $data = array('post_type' => $type, 'post_status' => $status);
        foreach (array('title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'slug' => 'post_name', 'comment_status' => 'comment_status') as $field => $key) {
            if (isset($args[$field])) { $data[$key] = $field === 'content' ? wp_kses_post($args[$field]) : ($field === 'excerpt' ? sanitize_textarea_field($args[$field]) : ($field === 'slug' ? sanitize_title($args[$field]) : sanitize_text_field($args[$field]))); }
        }
        if (isset($args['date_gmt'])) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $args['date_gmt'], new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $args['date_gmt']) { throw new Roliyal_MCP_Input_Error('date_gmt 必须为有效的 UTC 时间 YYYY-MM-DD HH:MM:SS。'); }
            $data['post_date_gmt'] = $args['date_gmt']; $data['post_date'] = get_date_from_gmt($args['date_gmt']);
        }
        if ($status === 'future') {
            $gmt = $data['post_date_gmt'] ?? ($old ? $old->post_date_gmt : '');
            if (!$gmt || strtotime($gmt . ' UTC') <= time() + 60) { throw new Roliyal_MCP_Input_Error('定时发布需提供至少一分钟以后的 date_gmt。'); }
        } elseif ($status === 'publish' && !isset($args['date_gmt']) && (!$old || !in_array($old->post_status, array('publish', 'private'), true))) {
            $data['post_date'] = current_time('mysql'); $data['post_date_gmt'] = current_time('mysql', true);
        }
        foreach (array('category_ids' => 'category', 'tag_ids' => 'post_tag') as $field => $taxonomy) {
            if (!isset($args[$field])) { continue; }
            if ($type !== 'post') { throw new Roliyal_MCP_Input_Error('页面不支持本文工具的分类或标签参数。'); }
            $this->need(get_taxonomy($taxonomy)->cap->assign_terms);
            foreach ($args[$field] as $term_id) { if (!term_exists($term_id, $taxonomy)) { throw new Roliyal_MCP_Input_Error('分类或标签 ID 不存在。'); } }
            $data['tax_input'][$taxonomy] = array_values(array_unique($args[$field]));
        }
        if (isset($args['featured_media']) && $args['featured_media'] > 0) {
            $attachment = get_post($args['featured_media']);
            if (!$attachment || $attachment->post_type !== 'attachment' || !wp_attachment_is_image($attachment->ID) || !current_user_can('edit_post', $attachment->ID)) { throw new Roliyal_MCP_Tool_Error('封面需为有权使用的图片附件。'); }
        }
        return $data;
    }
    private function set_featured($id, $args) {
        if (!isset($args['featured_media'])) { return; }
        if ($args['featured_media'] === 0) { delete_post_thumbnail($id); }
        else { set_post_thumbnail($id, $args['featured_media']); if ((int) get_post_thumbnail_id($id) !== $args['featured_media']) { throw new Roliyal_MCP_Tool_Error('文章已保存，但封面设置未完成。请重新读取文章确认。'); } }
    }
    private function create_content($args) {
        $type = $args['post_type'] ?? 'post'; $data = $this->content_input($args, $type);
        return $this->once(__FUNCTION__, $args, function () use ($data, $args) {
            $data['post_author'] = get_current_user_id();
            $id = $this->result(wp_insert_post(wp_slash($data), true), '创建内容'); $this->set_featured($id, $args);
            return $this->content_data(get_post($id), false);
        });
    }
    private function update_content($args) {
        if (count(array_diff(array_keys($args), array('id', 'revision'))) === 0) { throw new Roliyal_MCP_Input_Error('至少提供一项要修改的字段。'); }
        return $this->locked('content:' . $args['id'], function () use ($args) {
            clean_post_cache($args['id']); $p = $this->content($args['id']); $this->need('edit_post', $p->ID); $this->expect($this->content_revision($p), $args['revision']);
            $data = $this->content_input($args, $p->post_type, $p); $data['ID'] = $p->ID;
            $this->result(wp_update_post(wp_slash($data), true), '修改内容'); $this->set_featured($p->ID, $args);
            return $this->content_data(get_post($p->ID), false);
        });
    }
    private function create_term($args) {
        $taxonomy = get_taxonomy($args['taxonomy']); $this->need($taxonomy->cap->manage_terms);
        if ($args['taxonomy'] === 'post_tag' && !empty($args['parent'])) { throw new Roliyal_MCP_Input_Error('标签不支持父级。'); }
        if (!empty($args['parent']) && !term_exists($args['parent'], $args['taxonomy'])) { throw new Roliyal_MCP_Input_Error('父级分类不存在。'); }
        if (trim(sanitize_text_field($args['name'])) === '') { throw new Roliyal_MCP_Input_Error('名称不能为空。'); }
        return $this->once(__FUNCTION__, $args, function () use ($args) {
            $term = $this->result(wp_insert_term(sanitize_text_field($args['name']), $args['taxonomy'], array('slug' => sanitize_title($args['slug'] ?? ''), 'description' => sanitize_textarea_field($args['description'] ?? ''), 'parent' => $args['parent'] ?? 0)), '创建分类/标签');
            return array('id' => (int) $term['term_id'], 'taxonomy' => $args['taxonomy']);
        });
    }
    private function delete_content($args) {
        return $this->locked('content:' . $args['id'], function () use ($args) {
            clean_post_cache($args['id']); $p = $this->content($args['id']); $this->need('delete_post', $p->ID); $this->expect($this->content_revision($p), $args['revision']);
            if (!empty($args['force'])) { $this->result(wp_delete_post($p->ID, true), '永久删除'); return array('id' => $p->ID, 'deleted' => true, 'permanent' => true); }
            if (!defined('EMPTY_TRASH_DAYS') || !EMPTY_TRASH_DAYS) { throw new Roliyal_MCP_Tool_Error('本站禁用了回收站；如需永久删除，请明确使用 force=true。'); }
            if ($p->post_status === 'trash') { return $this->content_data($p, false) + array('already_trashed' => true); }
            $this->result(wp_trash_post($p->ID), '移入回收站');
            return $this->content_data(get_post($p->ID), false) + array('permanent' => false);
        });
    }
    private function restore_content($args) {
        return $this->locked('content:' . $args['id'], function () use ($args) {
            clean_post_cache($args['id']); $p = $this->content($args['id']); $this->need('delete_post', $p->ID); $this->need('edit_post', $p->ID); $this->expect($this->content_revision($p), $args['revision']);
            if ($p->post_status !== 'trash') { throw new Roliyal_MCP_Tool_Error('目标不在回收站。'); }
            $status = get_post_meta($p->ID, '_wp_trash_meta_status', true) ?: 'draft';
            if (in_array($status, array('publish', 'private', 'future'), true)) { $this->need(get_post_type_object($p->post_type)->cap->publish_posts); }
            // Core defaults to restoring as a draft; scope this filter to exactly this call/post.
            $restore = function ($new_status, $post_id) use ($p, $status) { return (int) $post_id === $p->ID ? $status : $new_status; };
            add_filter('wp_untrash_post_status', $restore, 10, 2);
            try { $this->result(wp_untrash_post($p->ID), '恢复内容'); } finally { remove_filter('wp_untrash_post_status', $restore, 10); }
            return $this->content_data(get_post($p->ID), false);
        });
    }
    private function media($id) {
        $p = get_post($id);
        if (!$p || $p->post_type !== 'attachment' || !current_user_can('edit_post', $id)) { throw new Roliyal_MCP_Tool_Error('未找到有权管理的媒体。'); }
        return $p;
    }
    private function media_revision($p) { return $this->hash(array($p->ID, $p->post_title, $p->post_content, $p->post_excerpt, $p->post_parent, $p->post_modified_gmt, get_post_meta($p->ID, '_wp_attachment_image_alt', true))); }
    private function media_data($p) {
        $meta = wp_get_attachment_metadata($p->ID);
        return array('id' => $p->ID, 'title' => $p->post_title, 'caption' => $p->post_excerpt, 'description' => $p->post_content, 'alt_text' => get_post_meta($p->ID, '_wp_attachment_image_alt', true),
            'url' => wp_get_attachment_url($p->ID), 'mime_type' => $p->post_mime_type, 'post_id' => (int) $p->post_parent, 'width' => $meta['width'] ?? null, 'height' => $meta['height'] ?? null, 'revision' => $this->media_revision($p));
    }
    private function list_media($args) {
        $q = array('post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => $args['per_page'] ?? 10, 'paged' => $args['page'] ?? 1, 's' => $args['search'] ?? '', 'orderby' => array('date' => 'DESC', 'ID' => 'DESC'));
        if (isset($args['mime_type'])) { $q['post_mime_type'] = sanitize_mime_type($args['mime_type']); }
        if (!current_user_can('edit_others_posts')) { $q['author'] = get_current_user_id(); }
        $query = new WP_Query($q); $items = array(); foreach ($query->posts as $p) { if (current_user_can('edit_post', $p->ID)) { $items[] = $this->media_data($p); } }
        return array('items' => $items, 'page' => $q['paged'], 'total' => (int) $query->found_posts, 'total_pages' => (int) $query->max_num_pages);
    }
    private function get_media($args) { return $this->media_data($this->media($args['id'])); }
    private function public_https($url) {
        $parts = wp_parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443) || !wp_http_validate_url($url)) { throw new Roliyal_MCP_Input_Error('必须提供无账号密码的公开 HTTPS 地址；不接受内网、本地地址或特殊端口。'); }
        $host = trim($parts['host'], '.[]');
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            || strpos($host, '.') === false || preg_match('/^(100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])|169\.254|198\.(18|19))\./', $ip)) { throw new Roliyal_MCP_Input_Error('媒体与安装包地址必须解析为公开互联网地址。'); }
        return $url;
    }
    private function media_parent($id) { if ($id > 0) { $this->content($id); $this->need('edit_post', $id); } }
    private function upload_media($args) {
        if (isset($args['artifact_id'])) { throw new Roliyal_MCP_Input_Error('回复文件需要由 Echoes 文件桥读取后上传；请更新 Echoes，先用 echoes_list_files 选择真实文件 ID。'); }
        if (isset($args['source_url']) === isset($args['data_base64'])) { throw new Roliyal_MCP_Input_Error('source_url 与 data_base64 必须且只能提供一项。'); }
        $this->media_parent($args['post_id'] ?? 0);
        $filename = sanitize_file_name($args['filename']);
        $type = wp_check_filetype($filename, get_allowed_mime_types());
        if (!$filename || !$type['ext'] || !$type['type'] || preg_match('/\.(php[0-9]*|phtml|phar|shtml|cgi|pl|py|sh|htaccess|exe|svg|html?|js)$/i', $filename)) { throw new Roliyal_MCP_Input_Error('文件名或类型不在 WordPress 允许上传的媒体范围内。'); }
        $limit = min(10 * 1024 * 1024, wp_max_upload_size());
        if (isset($args['source_url'])) { $this->public_https($args['source_url']); }
        else { $decoded = base64_decode($args['data_base64'], true); if ($decoded === false || strlen($decoded) === 0 || strlen($decoded) > $limit) { throw new Roliyal_MCP_Input_Error('Base64 无效、文件为空或超过 10 MiB/本站上传限制。'); } }
        return $this->once(__FUNCTION__, $args, function () use ($args, $filename, $limit) {
            require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
            $tmp = wp_tempnam($filename); if (!$tmp) { throw new Roliyal_MCP_Tool_Error('无法创建临时文件。'); }
            try {
                if (isset($args['source_url'])) {
                    // Each redirect remains validated by wp_safe_remote_get. No credentials are forwarded.
                    $response = wp_safe_remote_get($args['source_url'], array('timeout' => 30, 'redirection' => 3, 'stream' => true, 'filename' => $tmp, 'limit_response_size' => $limit + 1, 'sslverify' => true, 'cookies' => array()));
                    $this->result($response, '下载媒体');
                    if (wp_remote_retrieve_response_code($response) !== 200) { throw new Roliyal_MCP_Tool_Error('媒体下载未返回 HTTP 200。'); }
                } else { $bytes = base64_decode($args['data_base64'], true); if (file_put_contents($tmp, $bytes) !== strlen($bytes)) { throw new Roliyal_MCP_Tool_Error('媒体临时写入失败。'); } }
                clearstatcache(true, $tmp); $size = filesize($tmp);
                if (!$size || $size > $limit) { throw new Roliyal_MCP_Tool_Error('媒体为空或超过上传限制。'); }
                $checked = wp_check_filetype_and_ext($tmp, $filename, get_allowed_mime_types());
                if (!$checked['ext'] || !$checked['type'] || (!empty($checked['proper_filename']) && $checked['proper_filename'] !== $filename)) { throw new Roliyal_MCP_Tool_Error('实际文件类型与扩展名不匹配，未上传。'); }
                $id = $this->result(media_handle_sideload(array('name' => $filename, 'tmp_name' => $tmp, 'error' => 0, 'size' => $size), $args['post_id'] ?? 0, null,
                    array('post_title' => sanitize_text_field($args['title'] ?? pathinfo($filename, PATHINFO_FILENAME)), 'post_excerpt' => sanitize_textarea_field($args['caption'] ?? ''), 'post_content' => sanitize_textarea_field($args['description'] ?? ''), 'post_author' => get_current_user_id())), '上传媒体');
                update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($args['alt_text'] ?? ''));
                return $this->media_data(get_post($id));
            } finally { if (file_exists($tmp)) { wp_delete_file($tmp); } }
        });
    }
    private function update_media($args) {
        if (count(array_diff(array_keys($args), array('id', 'revision'))) === 0) { throw new Roliyal_MCP_Input_Error('至少提供一项要修改的字段。'); }
        return $this->locked('media:' . $args['id'], function () use ($args) {
            clean_post_cache($args['id']); $p = $this->media($args['id']); $this->expect($this->media_revision($p), $args['revision']); $this->media_parent($args['post_id'] ?? 0);
            $data = array('ID' => $p->ID);
            foreach (array('title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content', 'post_id' => 'post_parent') as $key => $field) { if (isset($args[$key])) { $data[$field] = $key === 'post_id' ? $args[$key] : sanitize_textarea_field($args[$key]); } }
            $this->result(wp_update_post(wp_slash($data), true), '修改媒体资料');
            if (isset($args['alt_text'])) { update_post_meta($p->ID, '_wp_attachment_image_alt', sanitize_text_field($args['alt_text'])); }
            return $this->media_data(get_post($p->ID));
        });
    }
    private function delete_media($args) {
        return $this->locked('media:' . $args['id'], function () use ($args) {
            clean_post_cache($args['id']); $p = $this->media($args['id']); $this->need('delete_post', $p->ID); $this->expect($this->media_revision($p), $args['revision']);
            $this->result(wp_delete_attachment($p->ID, true), '永久删除媒体');
            return array('id' => $p->ID, 'deleted' => true, 'permanent' => true);
        });
    }
    private function extension_api() {
        if (is_multisite()) { throw new Roliyal_MCP_Tool_Error('此版本的扩展管理仅支持单站点 WordPress；多站点请使用网络后台。'); }
        require_once ABSPATH . 'wp-admin/includes/plugin.php'; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/theme.php';
    }
    private function plugin_items() {
        $this->extension_api(); $updates = get_site_transient('update_plugins'); $items = array();
        foreach (get_plugins() as $file => $plugin) {
            $item = array('plugin' => $file, 'name' => wp_strip_all_tags($plugin['Name']), 'version' => $plugin['Version'], 'active' => is_plugin_active($file), 'update_version' => $updates->response[$file]->new_version ?? null, 'managed_by_mcp' => $file !== plugin_basename(dirname(__DIR__) . '/roliyal-mcp.php'));
            $item['revision'] = $this->hash($item); $items[$file] = $item;
        }
        return $items;
    }
    private function list_plugins($args) { return array('items' => array_values($this->plugin_items()), 'updates_source' => 'wordpress_cached_updates'); }
    private function filesystem($cap) {
        $this->need($cap); if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) { throw new Roliyal_MCP_Tool_Error('WordPress DISALLOW_FILE_MODS 已禁止文件修改。'); }
        if (get_filesystem_method() !== 'direct' || !WP_Filesystem()) { throw new Roliyal_MCP_Tool_Error('服务器不支持无需交互的直接文件写入。请在 WordPress 后台完成文件权限/FTP 配置。'); }
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once __DIR__ . '/class-upgrader-skin.php';
    }
    private function quietly($callback) {
        $level = ob_get_level(); ob_start(function ($buffer) { return ''; });
        try { return $callback(); } finally { while (ob_get_level() > $level) { ob_end_clean(); } }
    }
    private function manage_plugin($args) {
        $this->extension_api(); $items = $this->plugin_items(); $file = $args['plugin'];
        if (!isset($items[$file])) { throw new Roliyal_MCP_Tool_Error('插件标识无效或插件不存在。'); }
        if (!$items[$file]['managed_by_mcp']) { throw new Roliyal_MCP_Tool_Error('请在 WordPress 后台更新或停用 Roliyal MCP 自身，避免中断当前服务。'); }
        return $this->locked('extensions', function () use ($args, $file) {
            $items = $this->plugin_items(); if (!isset($items[$file])) { throw new Roliyal_MCP_Tool_Error('插件已不存在。'); } $this->expect($items[$file]['revision'], $args['revision']);
            $action = $args['action'];
            if ($action === 'activate') { $this->need('activate_plugin', $file); $result = $this->quietly(function () use ($file) { return activate_plugin($file); }); if (is_wp_error($result)) { $this->result($result, '启用插件'); } if (!is_plugin_active($file)) { throw new Roliyal_MCP_Tool_Error('插件未启用。'); } }
            elseif ($action === 'deactivate') { $this->need('deactivate_plugin', $file); $this->quietly(function () use ($file) { deactivate_plugins($file); }); if (is_plugin_active($file)) { throw new Roliyal_MCP_Tool_Error('插件未停用。'); } }
            elseif ($action === 'delete') { if (is_plugin_active($file)) { throw new Roliyal_MCP_Tool_Error('请先停用插件，再删除。'); } $this->filesystem('delete_plugins'); $this->result($this->quietly(function () use ($file) { return delete_plugins(array($file)); }), '删除插件'); }
            else {
                $this->filesystem('update_plugins');
                // Core's single upgrade() deactivates the target. The bulk path, even for
                // one plugin, keeps its active state and uses core maintenance handling.
                $updated = $this->result($this->quietly(function () use ($file) {
                    return (new Plugin_Upgrader(new Roliyal_MCP_Bulk_Skin()))->bulk_upgrade(array($file));
                }), '更新插件');
                $this->result($updated[$file] ?? false, '更新插件');
            }
            wp_clean_plugins_cache(false); $after = $this->plugin_items();
            return array('action' => $action, 'plugin' => $file, 'deleted' => !isset($after[$file]), 'item' => $after[$file] ?? null);
        });
    }
    private function theme_items() {
        $this->extension_api(); $updates = get_site_transient('update_themes'); $items = array();
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $item = array('stylesheet' => $stylesheet, 'name' => wp_strip_all_tags($theme->get('Name')), 'version' => $theme->get('Version'), 'template' => $theme->get_template(), 'active' => get_stylesheet() === $stylesheet, 'update_version' => $updates->response[$stylesheet]['new_version'] ?? null, 'valid' => !$theme->errors());
            $item['revision'] = $this->hash($item); $items[$stylesheet] = $item;
        }
        return $items;
    }
    private function list_themes($args) { return array('items' => array_values($this->theme_items()), 'updates_source' => 'wordpress_cached_updates'); }
    private function activate_theme($stylesheet) {
        $this->need('switch_themes'); $theme = wp_get_theme($stylesheet);
        if (!$theme->exists() || $theme->errors()) { throw new Roliyal_MCP_Tool_Error('主题不存在、文件不完整或缺少父主题。'); }
        if (function_exists('validate_theme_requirements')) { $requirements = validate_theme_requirements($stylesheet); if (is_wp_error($requirements)) { $this->result($requirements, '主题环境检查'); } }
        $this->quietly(function () use ($stylesheet) { switch_theme($stylesheet); });
        if (get_stylesheet() !== $stylesheet) { throw new Roliyal_MCP_Tool_Error('主题未切换。'); }
    }
    private function manage_theme($args) {
        $this->extension_api(); $stylesheet = $args['stylesheet'];
        return $this->locked('extensions', function () use ($args, $stylesheet) {
            $items = $this->theme_items(); if (!isset($items[$stylesheet])) { throw new Roliyal_MCP_Tool_Error('主题标识无效或主题不存在。'); } $this->expect($items[$stylesheet]['revision'], $args['revision']);
            if ($args['action'] === 'activate') { $this->activate_theme($stylesheet); }
            elseif ($args['action'] === 'delete') {
                if (in_array($stylesheet, array(get_stylesheet(), get_template()), true)) { throw new Roliyal_MCP_Tool_Error('不能删除当前主题或其父主题。'); }
                foreach (wp_get_themes() as $child) { if ($child->get_stylesheet() !== $stylesheet && $child->get_template() === $stylesheet) { throw new Roliyal_MCP_Tool_Error('有已安装子主题依赖此主题，请先处理子主题。'); } }
                $this->filesystem('delete_themes'); $this->result($this->quietly(function () use ($stylesheet) { return delete_theme($stylesheet); }), '删除主题');
            } else { $this->filesystem('update_themes'); $this->result($this->quietly(function () use ($stylesheet) { return (new Theme_Upgrader(new Automatic_Upgrader_Skin()))->upgrade($stylesheet); }), '更新主题'); }
            wp_clean_themes_cache(false); $after = $this->theme_items();
            return array('action' => $args['action'], 'stylesheet' => $stylesheet, 'deleted' => !isset($after[$stylesheet]), 'item' => $after[$stylesheet] ?? null);
        });
    }
    private function install_extension($kind, $args) {
        $this->extension_api(); $this->filesystem($kind === 'plugin' ? 'install_plugins' : 'install_themes');
        if (isset($args['slug']) === isset($args['package_url'])) { throw new Roliyal_MCP_Input_Error('slug 与 package_url 必须且只能提供一项。'); }
        if (isset($args['slug']) && (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $args['slug']) || strlen($args['slug']) > 100)) { throw new Roliyal_MCP_Input_Error('WordPress.org slug 格式无效。'); }
        if (!empty($args['activate'])) { $this->need($kind === 'plugin' ? 'activate_plugins' : 'switch_themes'); }
        if (isset($args['package_url'])) { $this->public_https($args['package_url']); }
        return $this->once('install_' . $kind, $args, function () use ($kind, $args) {
            return $this->locked('extensions', function () use ($kind, $args) {
                if (isset($args['slug'])) {
                    require_once ABSPATH . 'wp-admin/includes/' . ($kind === 'plugin' ? 'plugin-install.php' : 'theme-install.php');
                    $info = $kind === 'plugin' ? plugins_api('plugin_information', array('slug' => $args['slug'], 'fields' => array('sections' => false))) : themes_api('theme_information', array('slug' => $args['slug'], 'fields' => array('sections' => false)));
                    $this->result($info, '查询 WordPress.org'); $package = $info->download_link ?? '';
                    if (!$package) { throw new Roliyal_MCP_Tool_Error('没有可用安装包。'); }
                } else { $package = $args['package_url']; }
                $this->public_https($package);
                $upgrader = $kind === 'plugin' ? new Plugin_Upgrader(new Automatic_Upgrader_Skin()) : new Theme_Upgrader(new Automatic_Upgrader_Skin());
                $this->result($this->quietly(function () use ($upgrader, $package) { return $upgrader->install($package); }), '安装' . ($kind === 'plugin' ? '插件' : '主题'));
                if ($kind === 'plugin') {
                    $file = $upgrader->plugin_info(); if (!$file) { throw new Roliyal_MCP_Tool_Error('文件已安装但未识别插件入口，请检查后台。'); }
                    wp_clean_plugins_cache(false);
                    if (!empty($args['activate'])) { $this->need('activate_plugin', $file); $activated = $this->quietly(function () use ($file) { return activate_plugin($file); }); if (is_wp_error($activated)) { $this->result($activated, '启用新插件'); } if (!is_plugin_active($file)) { throw new Roliyal_MCP_Tool_Error('已安装，但启用未完成。'); } }
                    $items = $this->plugin_items(); return array('plugin' => $file, 'item' => $items[$file] ?? null);
                }
                $theme = $upgrader->theme_info(); if (!$theme) { throw new Roliyal_MCP_Tool_Error('主题已安装但未识别，请检查后台。'); }
                wp_clean_themes_cache(false); $stylesheet = $theme->get_stylesheet();
                if (!empty($args['activate'])) { $this->activate_theme($stylesheet); }
                $items = $this->theme_items(); return array('stylesheet' => $stylesheet, 'item' => $items[$stylesheet] ?? null);
            });
        });
    }
    private function install_plugin($args) { return $this->install_extension('plugin', $args); }
    private function install_theme($args) { return $this->install_extension('theme', $args); }
    private function user_api() { if (is_multisite()) { throw new Roliyal_MCP_Tool_Error('此版本用户管理仅支持单站点 WordPress。'); } require_once ABSPATH . 'wp-admin/includes/user.php'; }
    private function user_data($user) {
        $item = array('id' => $user->ID, 'login' => $user->user_login, 'email' => $user->user_email, 'display_name' => $user->display_name, 'first_name' => $user->first_name, 'last_name' => $user->last_name, 'description' => $user->description, 'roles' => array_values($user->roles), 'registered' => $user->user_registered);
        $item['revision'] = $this->hash(array($item, $user->user_pass)); return $item;
    }
    private function editable_user($id) {
        $this->user_api(); $this->need('edit_user', $id); $user = get_user_by('id', $id);
        if (!$user) { throw new Roliyal_MCP_Tool_Error('用户不存在。'); } return $user;
    }
    private function list_users($args) {
        $this->user_api(); $this->need('list_users');
        $q = new WP_User_Query(array('number' => $args['per_page'] ?? 10, 'paged' => $args['page'] ?? 1, 'search' => ($args['search'] ?? '') !== '' ? '*' . $args['search'] . '*' : '', 'search_columns' => array('user_login', 'user_email', 'display_name'), 'orderby' => 'ID', 'order' => 'ASC'));
        $items = array(); foreach ($q->get_results() as $user) { if (current_user_can('edit_user', $user->ID)) { $items[] = $this->user_data($user); } }
        return array('items' => $items, 'page' => $args['page'] ?? 1, 'total' => (int) $q->get_total());
    }
    private function get_user($args) { $user = $this->editable_user($args['id']); return $this->user_data($user) + array('assignable_roles' => array_keys(get_editable_roles())); }
    private function role($role, $id = null) {
        $this->need('promote_users'); $roles = get_editable_roles();
        if (!isset($roles[$role])) { throw new Roliyal_MCP_Tool_Error('当前用户无权分配该角色。'); }
        if ($id) { $this->need('promote_user', $id); }
        return $role;
    }
    private function user_input($args) {
        $data = array();
        foreach (array('email' => 'user_email', 'display_name' => 'display_name', 'first_name' => 'first_name', 'last_name' => 'last_name', 'description' => 'description', 'password' => 'user_pass') as $key => $field) {
            if (isset($args[$key])) { $data[$field] = $key === 'password' ? $args[$key] : sanitize_text_field($args[$key]); }
        }
        if (isset($args['email']) && !is_email($args['email'])) { throw new Roliyal_MCP_Input_Error('邮箱格式无效。'); }
        return $data;
    }
    private function user_api_data($data) {
        // Match WordPress REST user-controller conventions, including password handling.
        return wp_slash($data);
    }
    private function create_user($args) {
        $this->user_api(); $this->need('create_users'); $data = $this->user_input($args);
        $data['role'] = $this->role($args['role'] ?? 'subscriber'); $data['user_login'] = $args['login'];
        if (sanitize_user($args['login'], true) !== $args['login'] || !validate_username($args['login'])) { throw new Roliyal_MCP_Input_Error('登录名必须为合法 WordPress 用户名。'); }
        return $this->once(__FUNCTION__, $args, function () use ($data) { $id = $this->result(wp_insert_user($this->user_api_data($data)), '创建用户'); return $this->user_data(get_user_by('id', $id)); });
    }
    private function last_admin($user, $next_role = null) {
        if (in_array('administrator', $user->roles, true) && $next_role !== 'administrator' && count(get_users(array('role' => 'administrator', 'fields' => 'ID', 'number' => 2))) < 2) { throw new Roliyal_MCP_Tool_Error('不能删除或降级最后一位管理员。'); }
    }
    private function update_user($args) {
        if (count(array_diff(array_keys($args), array('id', 'revision'))) === 0) { throw new Roliyal_MCP_Input_Error('至少提供一项要修改的字段。'); }
        return $this->locked('users', function () use ($args) {
            clean_user_cache($args['id']); $user = $this->editable_user($args['id']); $this->expect($this->user_data($user)['revision'], $args['revision']);
            if ($user->ID === get_current_user_id() && (isset($args['role']) || isset($args['password']) || isset($args['email']))) { throw new Roliyal_MCP_Tool_Error('当前连接账户的邮箱、角色、密码请在 WordPress 后台修改。'); }
            if (is_super_admin($user->ID) && is_multisite()) { throw new Roliyal_MCP_Tool_Error('不能修改网络超级管理员。'); }
            $data = $this->user_input($args); $data['ID'] = $user->ID;
            if (isset($args['role'])) { $data['role'] = $this->role($args['role'], $user->ID); $this->last_admin($user, $data['role']); }
            $this->result(wp_update_user($this->user_api_data($data)), '修改用户');
            return $this->user_data(get_user_by('id', $user->ID));
        });
    }
    private function delete_user($args) {
        return $this->locked('users', function () use ($args) {
            $user = $this->editable_user($args['id']); $this->need('delete_user', $user->ID); $this->expect($this->user_data($user)['revision'], $args['revision']);
            if ($user->ID === get_current_user_id()) { throw new Roliyal_MCP_Tool_Error('不能删除当前 MCP 连接账户。'); } $this->last_admin($user);
            $target = get_user_by('id', $args['reassign_to']);
            if (!$target || $target->ID === $user->ID || !user_can($target, 'edit_posts')) { throw new Roliyal_MCP_Input_Error('reassign_to 必须是另一个具备文章编辑能力的有效用户。'); }
            $this->need('edit_user', $target->ID); $this->result(wp_delete_user($user->ID, $target->ID), '删除用户');
            return array('id' => $user->ID, 'deleted' => true, 'content_reassigned_to' => $target->ID);
        });
    }
    private function settings_values() {
        $out = array();
        foreach ((array) $this->settings_schema()['properties'] as $key => $schema) { $v = get_option($key); $out[$key] = $schema['type'] === 'integer' ? (int) $v : (string) $v; }
        return $out;
    }
    private function get_settings($args) { $v = $this->settings_values(); return array('values' => (object) $v, 'revision' => $this->hash($v)); }
    private function update_settings($args) {
        $values = (array) $args['values']; if (!$values) { throw new Roliyal_MCP_Input_Error('values 至少包含一项设置。'); }
        return $this->locked('settings', function () use ($args, $values) {
            $old = $this->settings_values(); $this->expect($this->hash($old), $args['revision']); $new = array_merge($old, $values);
            if (isset($values['timezone_string']) && !in_array($values['timezone_string'], timezone_identifiers_list(), true) && $values['timezone_string'] !== 'UTC') { throw new Roliyal_MCP_Input_Error('timezone_string 需使用 IANA 时区，例如 Asia/Shanghai。'); }
            foreach (array('page_on_front', 'page_for_posts') as $key) { if ($new[$key] > 0) { $p = get_post($new[$key]); if (!$p || $p->post_type !== 'page' || $p->post_status !== 'publish' || $p->post_password !== '') { throw new Roliyal_MCP_Input_Error('首页/文章页需为已公开且无密码的页面。'); } } }
            if ($new['show_on_front'] === 'page' && (!$new['page_on_front'] || $new['page_on_front'] === $new['page_for_posts'])) { throw new Roliyal_MCP_Input_Error('静态首页需指定页面，且首页与文章页不能相同。'); }
            if (!term_exists($new['default_category'], 'category')) { throw new Roliyal_MCP_Input_Error('默认分类不存在。'); }
            foreach (array('category_base', 'tag_base') as $key) { if (isset($values[$key]) && ($values[$key] !== '' && (!preg_match('~^[a-zA-Z0-9_/-]+$~D', $values[$key]) || strpos($values[$key], '..') !== false))) { throw new Roliyal_MCP_Input_Error('分类/标签前缀只能包含字母、数字、下划线、连字符和路径分隔符。'); } }
            if (isset($values['permalink_structure']) && $values['permalink_structure'] !== '') {
                $structure = $values['permalink_structure']; $tags = array('%year%', '%monthnum%', '%day%', '%hour%', '%minute%', '%second%', '%post_id%', '%postname%', '%category%', '%author%');
                $rest = str_replace($tags, '', $structure);
                if ($structure[0] !== '/' || !preg_match('~^[a-zA-Z0-9_/-]*$~D', $rest) || strpos($rest, '..') !== false || $rest === $structure) { throw new Roliyal_MCP_Input_Error('固定链接结构无效，例如 /%postname%/。'); }
            }
            // Validate every value before the first write. Core hooks can still fail mid-operation.
            foreach ($values as $key => $value) {
                $value = is_string($value) ? sanitize_text_field($value) : $value;
                if (!update_option($key, $value) && (string) get_option($key) !== (string) $value) { throw new Roliyal_MCP_Tool_Error('设置未全部保存，请重新读取确认。'); }
            }
            if (array_intersect(array_keys($values), array('permalink_structure', 'category_base', 'tag_base'))) { flush_rewrite_rules(false); }
            return $this->get_settings(array()) + array('changed' => array_keys($values));
        });
    }
}
