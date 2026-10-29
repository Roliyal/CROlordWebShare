<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap echoes-settings">
    <h1>Echoes</h1>
    <?php settings_errors(); ?>
    
    <section data-echoes-account style="max-width:820px;margin:24px 0;padding:24px;border:1px solid #dedee4;border-radius:12px;background:#fff">
        <h2 style="margin-top:0">ChatGPT 账号</h2>
        <p data-account-status role="status"><?php echo !empty($account['connected'])?esc_html('已登录 '.($account['email']??'ChatGPT').' · '.($account['plan']??'').''):esc_html($account['message']??'尚未登录。点击下面的按钮开始。'); ?></p>
        <button type="button" class="button button-primary" data-account-login>登录 ChatGPT</button>
        <button type="button" class="button" data-account-refresh>检查登录状态</button>
        <button type="button" class="button" data-account-logout <?php if(empty($account['connected']))echo 'hidden'; ?>>退出账号</button>
        <p><a data-account-url target="_blank" rel="noopener noreferrer" hidden>打开 OpenAI 官方登录页</a></p>
        <code data-account-code style="font-size:22px;letter-spacing:.1em" hidden></code>

        
        <?php if(empty($account['connected'])) : ?><details style="margin-top:16px"><summary>首次安装服务器组件</summary><p>服务器需要 Node.js 20+、Python 3.9+。在你现有服务器终端运行一次，无需填写安装地址：</p><pre style="white-space:pre-wrap">sudo bash /var/www/html/wp-content/plugins/crolord-chatgpt/tools/install-server.sh</pre><p>组件只监听本机 17683 端口，无需开放公网端口。安装后回到本页点击登录。</p></details><?php endif; ?>
    </section>
    <?php if ($page) : ?><p><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url(get_permalink($page)); ?>">打开 Echoes</a></p><?php endif; ?>
    <form action="options.php" method="post" data-mcp-form>
    <?php settings_fields(self::GROUP); ?>
    <table class="form-table" role="presentation">
     <tr><th><label for="fnchat-password-setting">页面独立口令</label></th><td><input id="fnchat-password-setting" type="password" autocomplete="new-password" maxlength="512" name="<?php echo esc_attr(self::OPTION); ?>[page_password]"><p class="description">留空沿用原口令，填写新值会使旧访问会话失效。至少 8 个字符。</p></td></tr>
     <tr><th><label for="fnchat-model">默认模型</label></th><td><select id="fnchat-model" name="<?php echo esc_attr(self::OPTION); ?>[model]"><option value="">自动：优先 GPT‑6.1 Sol（账号可用时）</option><?php foreach($account['models']??array() as $model): ?><option value="<?php echo esc_attr($model['id']); ?>" <?php selected($settings['model'],$model['id']); ?>><?php echo esc_html($model['label'].' · '.$model['id']); ?></option><?php endforeach; ?><?php if($settings['model']!==''&&!in_array($settings['model'],array_column($account['models']??array(),'id'),true)): ?><option selected value="<?php echo esc_attr($settings['model']); ?>"><?php echo esc_html($settings['model'].'（当前不可用）'); ?></option><?php endif; ?></select><p class="description">只列出账号实际可用模型；指定模型不可用时提示，不静默切换。</p></td></tr>
     <tr><th><label for="fnchat-default-effort">默认推理强度</label></th><td><select id="fnchat-default-effort" name="<?php echo esc_attr(self::OPTION); ?>[effort]" data-saved="<?php echo esc_attr($settings['effort']); ?>"><option value="">模型默认</option></select><p class="description">high、max 等按模型提供的能力显示，前台可以单独选择。</p></td></tr>
     <tr><th>联网搜索</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[web_search_enabled]" value="1" <?php checked(!empty($settings['web_search_enabled'])); ?>> 允许使用账号支持的官方联网搜索</label></td></tr>
     <tr><th>附件限制</th><td><label>每份 <input type="number" min="1" max="128" name="<?php echo esc_attr(self::OPTION); ?>[max_file_mb]" value="<?php echo (int)$settings['max_file_mb']; ?>"> MB</label> <label>每条最多 <input type="number" min="1" max="12" name="<?php echo esc_attr(self::OPTION); ?>[max_files]" value="<?php echo (int)$settings['max_files']; ?>"> 份</label></td></tr>
     <tr><th>回复文件</th><td><label>每份最多 <input type="number" min="1" max="256" name="<?php echo esc_attr(self::OPTION); ?>[artifact_mb]" value="<?php echo (int)$settings['artifact_mb']; ?>"> MB</label> <label>暂存 <input type="number" min="1" max="30" name="<?php echo esc_attr(self::OPTION); ?>[artifact_days]" value="<?php echo (int)$settings['artifact_days']; ?>"> 天</label><p class="description">文件在设定期限内可预览与下载。</p></td></tr>
     <tr><th><label for="fnchat-instructions">回复偏好</label></th><td><textarea id="fnchat-instructions" class="large-text" rows="4" name="<?php echo esc_attr(self::OPTION); ?>[instructions]"><?php echo esc_textarea($settings['instructions']); ?></textarea></td></tr>
     <tr><th><label for="fnchat-timeout">请求时限</label></th><td><input id="fnchat-timeout" type="number" min="15" max="1800" name="<?php echo esc_attr(self::OPTION); ?>[request_timeout]" value="<?php echo (int)$settings['request_timeout']; ?>"> 秒</td></tr>
     <tr><th>HTTP 测试</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[allow_http_testing]" value="1" <?php checked(!empty($settings['allow_http_testing'])); ?>> 允许现有 HTTP 测试站验证口令与发送文字</label></td></tr>
    </table>
    <h2>附件与 MCP 工具</h2>
    
    
    
    <input type="hidden" data-mcp-json name="<?php echo esc_attr(self::OPTION); ?>[echoes_mcp_json]" value="">
    <div data-mcp-servers><?php $rows=is_array($settings['echoes_mcp']??null)?$settings['echoes_mcp']:array();if(!$rows)$rows=array(array());foreach($rows as $server)include __DIR__.'/settings-mcp-row.php'; ?></div>
    <template data-mcp-template><?php $server=array();include __DIR__.'/settings-mcp-row.php'; ?></template>
    <p><button type="button" class="button" data-mcp-add>添加 MCP 服务</button> <span data-mcp-count></span></p>
    <noscript><p>编辑 MCP 需要启用 JavaScript，否则保留原配置。</p></noscript>
    <?php submit_button('保存设置'); ?></form>
    
</div>
