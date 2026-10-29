<?php if(!defined('ABSPATH'))exit; ?>
<fieldset data-mcp-server style="max-width:820px;margin:16px 0;padding:18px;border:1px solid #dedee4;border-radius:8px;background:#fff"><legend>MCP 服务</legend>
<p><label>标识 <input data-mcp-field="id" value="<?php echo esc_attr($server['id']??''); ?>" maxlength="40" placeholder="例如 docs"></label> <label>显示名称 <input data-mcp-field="name" value="<?php echo esc_attr($server['name']??''); ?>"></label></p>
<p><label>服务地址 <input type="url" class="large-text" data-mcp-field="url" value="<?php echo esc_attr($server['url']??''); ?>" placeholder="https://服务域名/mcp"></label></p>
<p><label>允许的工具名 <textarea rows="3" class="large-text" data-mcp-field="tools" placeholder="search, fetch"><?php echo esc_textarea(implode(', ',is_array($server['tools']??null)?$server['tools']:array())); ?></textarea></label></p>
<p><label>Bearer Token <input type="password" data-mcp-field="token" autocomplete="new-password" placeholder="<?php echo empty($server['secret'])?'无需认证可留空':'已保存，留空沿用'; ?>"></label> <label><input type="checkbox" data-mcp-field="clear_token"> 清除已存令牌</label></p>
<p><button type="button" class="button" data-mcp-discover>读取工具列表</button> <button type="button" class="button" data-mcp-remove>移除此服务</button></p><div data-mcp-result role="status" style="max-height:360px;overflow:auto"></div>
<p class="description">可手动添加准确工具名，逗号或换行分隔；令牌加密保存，不发到前台。移除服务后需保存设置。</p></fieldset>
