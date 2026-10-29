<?php
defined('ABSPATH') || exit;

final class Roliyal_MCP_Scopes {
    public static function catalog() {
        return array(
            'content' => array('文章发布与编辑', '新建文章或页面、直接发布、修改已发布正文、分类标签与封面。', 'edit_posts'),
            'delete' => array('内容删除与恢复', '将文章或页面移入回收站、恢复或永久删除；删除媒体文件。', 'delete_posts'),
            'media' => array('媒体库', '查询、上传图片、音视频及 WordPress 允许的其他文件，修改媒体资料。', 'upload_files'),
            'extensions' => array('主题与插件', '查询、安装、更新、启停插件，切换或删除主题。', 'activate_plugins'),
            'users' => array('用户管理', '查询、新建、修改角色和资料、删除用户并转移文章。', 'list_users'),
            'settings' => array('网站设置', '读取和修改站点标题、简介、时区、首页、评论与固定链接等常用设置。', 'manage_options'),
        );
    }
    public static function allowed($credential, $scope) {
        $catalog = self::catalog();
        return isset($catalog[$scope]) && in_array($scope, $credential['scopes'] ?? array(), true) && current_user_can($catalog[$scope][2]);
    }
    public static function active($credential) {
        $out = array();
        foreach (self::catalog() as $scope => $unused) { if (self::allowed($credential, $scope)) { $out[] = $scope; } }
        return $out;
    }
    public static function labels($credential) {
        $out = array('公开内容');
        if (!empty($credential['drafts'])) { $out[] = '草稿'; }
        foreach (self::catalog() as $scope => $item) { if (in_array($scope, $credential['scopes'] ?? array(), true)) { $out[] = $item[0]; } }
        return implode(' · ', $out);
    }
}
