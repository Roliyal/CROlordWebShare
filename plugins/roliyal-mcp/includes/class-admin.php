<?php
defined('ABSPATH') || exit;

final class Roliyal_MCP_Admin {
    public static function boot() {
        add_action('admin_menu', function () { add_options_page('Roliyal MCP', 'Roliyal MCP', 'manage_options', 'roliyal-mcp', array(__CLASS__, 'page')); });
        add_action('admin_post_roliyal_mcp_manage', array(__CLASS__, 'manage'));
        add_action('admin_enqueue_scripts', function ($hook) { if ($hook === 'settings_page_roliyal-mcp') { self::assets(); } });
    }
    private static function assets() {
        wp_enqueue_style('roliyal-mcp-admin', plugins_url('assets/admin.css', dirname(__DIR__) . '/roliyal-mcp.php'), array(), ROLIYAL_MCP_VERSION);
        wp_enqueue_script('roliyal-mcp-admin', plugins_url('assets/admin.js', dirname(__DIR__) . '/roliyal-mcp.php'), array(), ROLIYAL_MCP_VERSION, true);
    }
    private static function form_start($operation) {
        echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post">';
        wp_nonce_field('roliyal_mcp_manage');
        echo '<input type="hidden" name="action" value="roliyal_mcp_manage"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
    }
    public static function manage() {
        if (!current_user_can('manage_options')) { wp_die('没有管理权限。', '', array('response' => 403)); }
        check_admin_referer('roliyal_mcp_manage');
        $op = isset($_POST['operation']) && is_string($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $records = Roliyal_MCP_Server::tokens();
        $secret = '';
        if ($op === 'create') {
            $records = array_filter($records, function ($r) { return Roliyal_MCP_Server::token_active($r); });
            if (count($records) >= 8) { wp_die('最多保留 8 个有效连接，请先撤销不再使用的连接。'); }
            $label = isset($_POST['label']) && is_string($_POST['label']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['label'])), 0, 80) : 'Echoes';
            $permanent = isset($_POST['days']) && $_POST['days'] === 'permanent';
            $days = $permanent ? 0 : (isset($_POST['days']) && is_scalar($_POST['days']) ? min(365, max(1, (int) $_POST['days'])) : 90);
            $secret = 'rlm_' . bin2hex(random_bytes(32));
            $id = bin2hex(random_bytes(8));
            $records[$id] = array('label' => $label ?: 'Echoes', 'hash' => hash('sha256', $secret), 'user_id' => get_current_user_id(), 'created' => time(), 'expires' => $permanent ? 0 : time() + $days * DAY_IN_SECONDS, 'permanent' => $permanent, 'drafts' => !empty($_POST['drafts']) && current_user_can('edit_posts'));
            $requested = isset($_POST['scopes']) && is_array($_POST['scopes']) ? wp_unslash($_POST['scopes']) : array();
            $records[$id]['scopes'] = array();
            foreach (Roliyal_MCP_Scopes::catalog() as $scope => $item) { if (in_array($scope, $requested, true) && current_user_can($item[2])) { $records[$id]['scopes'][] = $scope; } }
        } elseif ($op === 'revoke') {
            $id = isset($_POST['token_id']) && is_string($_POST['token_id']) ? sanitize_key(wp_unslash($_POST['token_id'])) : '';
            unset($records[$id]);
        } else { wp_die('不支持的操作。', '', array('response' => 400)); }
        if (!update_option(Roliyal_MCP_Server::TOKENS, $records, false) && get_option(Roliyal_MCP_Server::TOKENS) !== $records) { wp_die('保存失败，请检查 WordPress 数据库。'); }
        if ($op === 'revoke') { wp_safe_redirect(admin_url('options-general.php?page=roliyal-mcp')); exit; }
        nocache_headers(); header('Referrer-Policy: no-referrer');
        self::assets();
        require ABSPATH . 'wp-admin/admin-header.php';
        self::page($secret);
        require ABSPATH . 'wp-admin/admin-footer.php';
        exit;
    }
    public static function page($secret = '') {
        if (!current_user_can('manage_options')) { return; }
        $url = Roliyal_MCP_Server::endpoint();
        $fallback = set_url_scheme(add_query_arg('rest_route', Roliyal_MCP_Server::ROUTE, home_url('/')), 'https');
        $json = wp_json_encode(array('mcpServers' => array('roliyal' => array('url' => $url, 'headers' => array('Authorization' => 'Bearer REPLACE_WITH_MCP_TOKEN')))), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $toml = '[mcp_servers.roliyal]' . "\n" . 'url = "' . str_replace('"', '\\"', $url) . '"' . "\n" . 'http_headers = { "Authorization" = "Bearer REPLACE_WITH_MCP_TOKEN" }';
        ?>
        <div class="wrap rlm-wrap">
            <header class="rlm-header"><span class="rlm-eyebrow">WORDPRESS · MCP</span><h1>Roliyal MCP</h1><p>让 Echoes 与你的创作空间相连。</p></header>
            <?php if (!is_ssl()) : ?><div class="notice notice-warning inline"><p>当前后台尚未被 WordPress 识别为 HTTPS。远程 MCP 会拒绝 HTTP 请求；请先修复 HTTPS 入口及代理协议头。</p></div><?php endif; ?>
            <section class="rlm-card"><div class="rlm-section-head"><h2>连接地址</h2><span class="rlm-pill">Streamable HTTP</span></div>
                <label class="rlm-label" for="rlm-endpoint">MCP URL</label><div class="rlm-copy-row"><input id="rlm-endpoint" class="regular-text code" type="text" readonly value="<?php echo esc_attr($url); ?>"><button class="button" type="button" data-rlm-copy="rlm-endpoint">复制</button></div>
                <p class="description">这是供 MCP 客户端使用的 POST 接口，浏览器直接打开显示 401 或 405 属于正常情况。</p>
                <details><summary>固定链接不可用时的备用地址</summary><p class="rlm-code"><?php echo esc_html($fallback); ?></p></details>
            </section>
            <div class="rlm-grid"><section class="rlm-card"><h2>创建 Echoes 连接</h2>
                <?php self::form_start('create'); ?>
                <label class="rlm-label" for="rlm-label">连接名称</label><input id="rlm-label" name="label" type="text" value="Echoes" maxlength="80" required>
                <label class="rlm-label" for="rlm-days">有效期</label><select id="rlm-days" name="days"><option value="30">30 天</option><option value="90" selected>90 天</option><option value="365">一年</option><option value="permanent">永久</option></select>
                <label class="rlm-check"><input type="checkbox" name="drafts" value="1">允许此连接创建和编辑文章草稿</label>
                <fieldset class="rlm-scopes"><legend>管理权限</legend>
                    <?php foreach (Roliyal_MCP_Scopes::catalog() as $scope => $item) : if (!current_user_can($item[2])) { continue; } ?>
                    <label class="rlm-scope"><input type="checkbox" name="scopes[]" value="<?php echo esc_attr($scope); ?>"><span><strong><?php echo esc_html($item[0]); ?></strong><small><?php echo esc_html($item[1]); ?></small></span></label>
                    <?php endforeach; ?>
                </fieldset>
                
                <button class="button button-primary" type="submit">生成连接密钥</button></form>
            </section>
            <section class="rlm-card"><h2><?php echo $secret !== '' ? '密钥已生成' : '测试连接'; ?></h2>
                <?php if ($secret !== '') : ?><p>完整密钥仅在这次生成后显示。请复制到 Echoes 后台的私密配置中。</p><?php endif; ?>
                <label class="rlm-label" for="rlm-secret">MCP 密钥</label><div class="rlm-copy-row"><input id="rlm-secret" type="password" autocomplete="off" spellcheck="false" value="<?php echo esc_attr($secret); ?>" placeholder="rlm_…"><button class="button" type="button" data-rlm-copy="rlm-secret">复制</button></div>
                <div class="rlm-actions"><button class="button" type="button" id="rlm-reveal" aria-pressed="false">显示密钥</button><button class="button" type="button" id="rlm-test" data-url="<?php echo esc_url($url); ?>">测试此连接</button></div>
                <p id="rlm-test-result" role="status" aria-live="polite"></p>
                <p class="description">已有连接的密钥无法找回；可粘贴已保存的密钥测试，或撤销后重新生成。不要将密钥放进网站页面、GitHub 或 URL。</p>
            </section></div>
            <section class="rlm-card"><h2>Echoes 配置</h2><p>在 Echoes 后台新增自定义 MCP：名称填 <code>roliyal</code>，传输选择 HTTP / Streamable HTTP，URL 使用上面的连接地址。</p>
                <p>如果提供“Token / 密钥”字段，填写原始 <code>rlm_…</code>；如果提供“请求头”字段，填写 <code>Authorization: Bearer rlm_…</code>。</p>
                <details open><summary>JSON 客户端配置</summary><div class="rlm-copy-row"><textarea id="rlm-json" class="code" rows="10" readonly><?php echo esc_textarea($json); ?></textarea><button class="button" type="button" data-rlm-copy="rlm-json">复制</button></div></details>
                <details><summary>Echoes / Codex 使用 TOML 配置时</summary><div class="rlm-copy-row"><textarea id="rlm-toml" class="code" rows="4" readonly><?php echo esc_textarea($toml); ?></textarea><button class="button" type="button" data-rlm-copy="rlm-toml">复制</button></div></details>
                <p class="description">将 REPLACE_WITH_MCP_TOKEN 替换为本站生成的密钥。保存后在 Echoes 中启用此服务器，并开启一段新对话，让模型重新发现工具。</p>
            </section>
            <section class="rlm-card"><h2>有效连接</h2><div class="rlm-table-scroll"><table class="widefat striped"><thead><tr><th>名称</th><th>权限</th><th>创建于</th><th>到期</th><th>操作</th></tr></thead><tbody>
                <?php foreach (Roliyal_MCP_Server::tokens() as $id => $r) : ?><tr><td><?php echo esc_html($r['label']); ?></td><td><?php echo esc_html(Roliyal_MCP_Scopes::labels($r)); ?></td><td><?php echo esc_html(wp_date('Y-m-d', $r['created'])); ?></td><td><?php echo !empty($r['permanent']) && (int) $r['expires'] === 0 ? '永久' : esc_html(wp_date('Y-m-d', $r['expires'])); echo Roliyal_MCP_Server::token_active($r) ? '' : ' · 已过期'; ?></td><td><?php self::form_start('revoke'); ?><input type="hidden" name="token_id" value="<?php echo esc_attr($id); ?>"><button class="button" type="submit">撤销</button></form></td></tr><?php endforeach; ?>
                <?php if (!Roliyal_MCP_Server::tokens()) : ?><tr><td colspan="5">尚未创建连接。</td></tr><?php endif; ?>
            </tbody></table></div></section>
            
        </div>
        <?php
    }
}
