<?php
/** Boughs: local installation details and public repository previews. */
if (!defined('ABSPATH')) exit;

function fieldnotes_distribution_repo() { return 'https://github.com/Roliyal/CROlordWebShare'; }

function fieldnotes_component_copy() {
    return array(
        'journal' => array('description' => '文字、影像与日常，各有舒展的位置。', 'features' => array(array('阅读与记录', '文章、分类、搜索与目录，保持清晰的阅读节奏。'), array('独立页面', '地图、市场观察、日常之物与作品在同一套排版中展开。'), array('轻量呈现', '系统字体、响应式图片与按需交互，适应电脑和手机。'))),
        'echoes' => array('description' => '一处有口令保护的对话空间，连接模型、工具与记录。', 'features' => array(array('独立入口', '先输入访问口令，再进入私人对话空间。'), array('模型与工具', '选择模型、接入 MCP 工具，并在对话中处理文件。'), array('继续对话', '保留会话历史，按原来的上下文继续思考。'))),
        'wayfarer' => array('description' => '把地点放回地图，让一段路与一篇游记相遇。', 'features' => array(array('足迹与区域', '已到访区域、地名和坐标，在同一张地图中呈现。'), array('地点索引', '搜索城市或点选地点，地图随选择平滑切换。'), array('关联游记', '从地点打开对应的旅行笔记，继续阅读。'))),
        'comments' => array('description' => '头像、表情与回复，让交流自然地接着发生。', 'features' => array(array('邮箱头像', '匹配邮箱头像，并记住已填写的评论资料。'), array('图片表情', '编辑时的表情与发表后的显示保持一致。'), array('清楚的对话', '回复放在对应留言下方，其余回复收起，主留言按页浏览。'))),
        'keepsakes' => array('description' => '整理长期使用的物品，也保留选择它的细节。', 'features' => array(array('分类收藏', '按随身、出行、工作和生活整理物品。'), array('规格与数量', '读取支持的商品链接，在详情中选择规格和数量。'), array('购买入口', '确认选择后前往商品页面，收藏与购买各有自己的位置。'))),
        'publisher' => array('description' => '写好一篇文章，再为不同平台安排合适的呈现。', 'features' => array(array('发布前预览', '先核对正文、图片顺序和各平台的呈现方式。'), array('按平台选择', '为微博、X 和小红书选择图文或视频，以及同步时间。'), array('任务记录', '查看同步状态、结果与需要核对的任务。'))),
    );
}

function fieldnotes_components() {
    return crolord_request_value(__FUNCTION__, function () {
        $theme = wp_get_theme();
        $copy = fieldnotes_component_copy();
        $components = array(array_merge(array('id' => 'journal', 'name' => $theme->get('Name'), 'kind' => 'WordPress 主题', 'version' => $theme->get('Version'), 'source' => 'theme/crolord', 'active' => true, 'tagline' => ''), $copy['journal']));
        $definitions = array(
            array('echoes', 'Echoes', '对话', 'crolord-chatgpt/crolord-chatgpt.php'),
            array('wayfarer', 'Map', '旅行地图', 'crolord-map/crolord-map.php'),
            array('comments', 'Comment-tools', '评论', 'crolord-comment-tools/crolord-comment-tools.php'),
            array('keepsakes', 'Keepsakes', '日常之物', 'crolord-keepsakes/crolord-keepsakes.php'),
            array('publisher', 'Publisher', '发布', 'crolord-publisher/crolord-publisher.php'),
        );
        $active = (array) get_option('active_plugins', array());
        $network = is_multisite() ? (array) get_site_option('active_sitewide_plugins', array()) : array();
        foreach ($definitions as $definition) {
            list($id, $name, $kind, $file) = $definition;
            $path = WP_PLUGIN_DIR . '/' . $file;
            $installed = is_readable($path);
            $headers = $installed ? get_file_data($path, array('name' => 'Plugin Name', 'version' => 'Version')) : array();
            $components[] = array_merge(array('id' => $id, 'name' => !empty($headers['name']) ? $headers['name'] : $name, 'kind' => $kind, 'version' => $headers['version'] ?? '', 'source' => 'plugins/' . dirname($file), 'installed' => $installed, 'active' => in_array($file, $active, true) || isset($network[$file]), 'tagline' => ''), $copy[$id]);
        }
        return $components;
    });
}

function fieldnotes_open_source_projects() {
    return array(
        array('mark' => 'cloud', 'title' => '云原生实践', 'name' => 'CROlordCloudNative', 'description' => '从镜像构建到部署、治理与观测，一份可以按章节展开的实践记录。', 'topics' => '云原生 · 微服务 · 可观测', 'intro' => '围绕阿里云的容器、Serverless 与微服务，整理从构建到运行的实践文档。可按自己的环境选择章节，并结合配套代码逐步验证。', 'features' => array(array('构建与部署', 'Jenkins、镜像、ACK 与 SAE 的环境和部署步骤。'), array('微服务治理', 'MSE 灰度、限流熔断、安全与上下线实践。'), array('观测与验证', 'Prometheus、ARMS、SLS、Grafana 与性能压测。')), 'directories' => array(array('部署实践', '第2章 阿里云 kubernentes 应用部署'), array('服务治理', '第4章 微服务治理实践与挑战'), array('可观测', '第5章 可观测思考与实践'))),
        array('mark' => 'code', 'title' => '代码与示例', 'name' => 'CROlordCodelibrary', 'description' => '配合场景使用的代码与配置，把文档里的步骤落到具体文件。', 'topics' => 'Go · Java · 场景示例', 'intro' => '云原生实践的配套代码库，包含工具链、应用部署、服务治理与可观测示例。各目录是独立场景，需要按对应说明准备依赖；仓库根目录不提供统一构建入口。', 'features' => array(array('应用与配置', '前后端分离、微服务示例及 Kubernetes 配置。'), array('链路与日志', 'Java、Go 的 HTTP、gRPC、结构化日志与链路透传。'), array('按场景运行', '从章节或 README 选择入口，按自己的环境调整资源。')), 'directories' => array(array('应用构建', 'Chapter2KubernetesApplicationBuild'), array('服务治理', 'Chapter4MicroserviceGovern'), array('日志与链路', 'Chapter6Custom'))),
    );
}

function fieldnotes_work_kind() {
    $kind = isset($_GET['work_kind']) && is_string($_GET['work_kind']) ? sanitize_key(wp_unslash($_GET['work_kind'])) : '';
    return in_array($kind, array('wordpress', 'projects', 'notes'), true) ? $kind : '';
}
function fieldnotes_work_kind_url($kind = '') {
    $url = fieldnotes_section_url('categories');
    return in_array($kind, array('wordpress', 'projects', 'notes'), true) ? add_query_arg('work_kind', $kind, $url) : $url;
}

/** Current interface captures; attachment data supplies native responsive images. */
function fieldnotes_work_capture($id) {
    $captures = array(
        'journal' => array(334, 1348, 926, 50, 146, 1230, 702, '主题首页'),
        'echoes' => array(317, 1363, 936, 155, 300, 1040, 430, '口令入口'),
        'wayfarer' => array(318, 1348, 926, 46, 153, 1245, 740, '足迹地图'),
        'comments' => array(319, 1348, 926, 122, 253, 1104, 465, '评论编辑器'),
        'keepsakes' => array(320, 1348, 926, 103, 178, 1126, 636, '物品收藏'),
        'publisher' => array(321, 1363, 936, 1083, 248, 265, 430, '文章同步面板'),
    );
    if (!isset($captures[$id])) return null;
    list($attachment, $width, $height, $x, $y, $crop_width, $crop_height, $label) = $captures[$id];
    if (!wp_attachment_is_image($attachment)) return null;
    return array('attachment' => $attachment, 'width' => $width, 'height' => $height, 'x' => $x, 'y' => $y, 'crop_width' => $crop_width, 'crop_height' => $crop_height, 'label' => $label, 'date' => '2026.10.29');
}

function fieldnotes_work_image($work, $large = false, $eager = false) {
    if (empty($work['capture'])) return;
    $capture = $work['capture'];
    $style = sprintf('--shot-width:%.5f%%;--shot-x:%.5f%%;--shot-y:%.5f%%;--shot-ratio:%d / %d;', $capture['width'] / $capture['crop_width'] * 100, -$capture['x'] / $capture['width'] * 100, -$capture['y'] / $capture['height'] * 100, $capture['crop_width'], $capture['crop_height']);
    $attrs = array('class' => 'fn-shot-image', 'alt' => $work['name'] . ' · ' . $capture['label'], 'loading' => $eager ? 'eager' : 'lazy', 'decoding' => 'async', 'sizes' => $large ? '(max-width: 760px) 90vw, 650px' : ($work['id'] === 'journal' ? '(max-width: 580px) 90vw, (max-width: 800px) 90vw, 760px' : ($work['id'] === 'publisher' ? '250px' : '(max-width: 580px) 90vw, (max-width: 800px) 45vw, 650px')));
    if ($eager) $attrs['fetchpriority'] = 'high';
    echo '<span class="fn-shot-frame' . ($capture['crop_height'] > $capture['crop_width'] ? ' is-portrait' : '') . '" style="' . esc_attr($style) . '">';
    echo wp_get_attachment_image($capture['attachment'], $large ? 'full' : 'large', false, $attrs);
    echo '</span>';
}

function fieldnotes_work_items() {
    $items = array();
    $repo = fieldnotes_distribution_repo();
    foreach (fieldnotes_components() as $component) {
        $component['capture'] = fieldnotes_work_capture($component['id']);
        $component['preview'] = $component['capture'] ? wp_get_attachment_image_url($component['capture']['attachment'], 'full') : '';
        $component['group'] = 'wordpress';
        $component['mark'] = $component['id'] === 'journal' ? 'wordpress' : $component['id'];
        $component['source_url'] = $repo . '/tree/main/' . $component['source'];
        $component['source_label'] = '源码';
        $component['release_url'] = $component['id'] === 'journal' ? $repo . '/tree/main/dist' : '';
        $component['intro'] = $component['description'];
        $items[] = $component;
    }
    foreach (fieldnotes_open_source_projects() as $project) {
        $items[] = array_merge($project, array('id' => $project['name'], 'name' => $project['title'], 'repository' => $project['name'], 'group' => 'projects', 'kind' => 'GitHub', 'version' => '', 'preview' => '', 'capture' => null, 'source_url' => 'https://github.com/Roliyal/' . $project['name'], 'source_label' => 'GitHub', 'release_url' => '', 'active' => false));
    }
    return $items;
}

/** Two public repositories only. No remote work occurs while rendering Boughs. */
function fieldnotes_work_repository_snapshot($key) {
    $snapshots = array(
        'CROlordCloudNative' => array('branch' => 'main', 'tag' => 'toc', 'published' => '2023-03-01', 'sha' => '23e58396eebc34f54e3c00c2f95a01e3741a3131', 'updated' => '2026-01-22'),
        'CROlordCodelibrary' => array('branch' => 'main', 'tag' => '', 'published' => '', 'sha' => 'c16a42455a3d41234c5e9362d698ae44665484b0', 'updated' => '2026-10-20'),
    );
    return $snapshots[$key] ?? null;
}
function fieldnotes_work_repository_cached($key) {
    $snapshot = fieldnotes_work_repository_snapshot($key);
    if (!$snapshot) return null;
    $cached = get_transient('fn_work_repo_v1_' . $key);
    return is_array($cached) ? array_merge($snapshot, array_intersect_key($cached, $snapshot)) : $snapshot;
}
function fieldnotes_work_repository_ajax() {
    $key = isset($_GET['repository']) && is_string($_GET['repository']) ? sanitize_text_field(wp_unslash($_GET['repository'])) : '';
    $data = fieldnotes_work_repository_cached($key);
    if (!$data) wp_send_json_error(array('message' => '未找到这个项目。'), 404);
    $fresh_key = 'fn_work_repo_fresh_v1_' . $key;
    if (get_transient($fresh_key)) wp_send_json_success($data);
    set_transient($fresh_key, 1, MINUTE_IN_SECONDS);
    $base = 'https://api.github.com/repos/Roliyal/' . rawurlencode($key);
    $options = array('timeout' => 3, 'redirection' => 0, 'limit_response_size' => 65536, 'headers' => array('Accept' => 'application/vnd.github+json', 'User-Agent' => 'Crolord-Boughs'));
    $complete = true;
    foreach (array('commits' => '/commits?per_page=1', 'releases' => '/releases?per_page=1') as $kind => $path) {
        $response = wp_safe_remote_get($base . $path, $options);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { $complete = false; continue; }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json)) { $complete = false; continue; }
        if ($kind === 'commits' && !empty($json[0]['sha']) && preg_match('/^[a-f0-9]{40}$/', $json[0]['sha'])) {
            $date = substr((string) ($json[0]['commit']['committer']['date'] ?? ''), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $data['sha'] = $json[0]['sha']; $data['updated'] = $date; }
        } elseif ($kind === 'releases') {
            if (!$json) { $data['tag'] = ''; $data['published'] = ''; }
            elseif (isset($json[0]['tag_name'], $json[0]['published_at'])) {
                $tag = sanitize_text_field($json[0]['tag_name']);
                $date = substr((string) $json[0]['published_at'], 0, 10);
                if (strlen($tag) <= 100 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $data['tag'] = $tag; $data['published'] = $date; }
            }
        }
    }
    set_transient('fn_work_repo_v1_' . $key, $data, 7 * DAY_IN_SECONDS);
    set_transient($fresh_key, 1, $complete ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS);
    wp_send_json_success($data);
}
add_action('wp_ajax_fieldnotes_work_repository', 'fieldnotes_work_repository_ajax');
add_action('wp_ajax_nopriv_fieldnotes_work_repository', 'fieldnotes_work_repository_ajax');

function fieldnotes_work_mark($key, $size = 48) {
    $marks = array(
        'wordpress' => '<rect x="8" y="10" width="32" height="28" rx="4"/><path d="M8 18h32M18 18v20M13 14h.1M17 14h.1"/>',
        'cloud' => '<path d="M14 32h21a7 7 0 0 0 1-14 11 11 0 0 0-21-2 8 8 0 0 0-1 16Z"/><path d="M24 32v8M15 40h18M24 20v6M20 23l4 3 4-3"/>',
        'code' => '<path d="M18 11h-3c-2 0-3 2-3 4v5c0 2-2 4-4 4 2 0 4 2 4 4v5c0 2 1 4 3 4h3M30 11h3c2 0 3 2 3 4v5c0 2 2 4 4 4-2 0-4 2-4 4v5c0 2-1 4-3 4h-3M27 17l-6 14"/>',
        'echoes' => '<path d="M14 11h20a6 6 0 0 1 6 6v13a6 6 0 0 1-6 6H22l-10 6v-7a6 6 0 0 1-4-5V17a6 6 0 0 1 6-6Z"/><path d="M16 21h16M16 27h10"/>',
        'wayfarer' => '<path d="m7 13 11-5 12 5 11-5v27l-11 5-12-5-11 5V13ZM18 8v27M30 13v27"/><circle cx="24" cy="23" r="3"/>',
        'comments' => '<path d="M11 10h20a5 5 0 0 1 5 5v12a5 5 0 0 1-5 5H19l-8 5v-6a5 5 0 0 1-5-5V15a5 5 0 0 1 5-5Z"/><path d="M22 38h9l8 4v-7a5 5 0 0 0 3-5V20M14 20h.1M21 20h.1M28 20h.1"/>',
        'keepsakes' => '<path d="M14 8h20a3 3 0 0 1 3 3v30L24 33l-13 8V11a3 3 0 0 1 3-3Z"/><path d="M18 18h12M18 24h9"/>',
        'publisher' => '<path d="m6 22 36-15-12 35-7-13-17-7ZM23 29 42 7M15 34l-5 5M20 39l-2 3M10 29l-4 2"/>',
    );
    $size = max(16, min(160, absint($size)));
    return '<svg viewBox="0 0 48 48" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($marks[$key] ?? $marks['code']) . '</svg>';
}

