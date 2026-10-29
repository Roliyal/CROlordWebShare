# CROlordWebShare

当前 Crolord 主题与配套插件的源码。作者：issac。

| 组件 | 当前版本 | 目录 |
| --- | --- | --- |
| Crolord | `2.4.56` | `theme/crolord/` |
| Comment-tools | `1.8.0` | `plugins/crolord-comment-tools/` |
| Echoes | `1.17.3` | `plugins/crolord-chatgpt/` |
| Keepsakes | `1.2.8` | `plugins/crolord-keepsakes/` |
| Map | `1.6.8` | `plugins/crolord-map/` |
| Publisher | `1.24.6` | `plugins/crolord-publisher/` |
| Roliyal MCP | `1.2.0` | `plugins/roliyal-mcp/` |

## 安装

将 `theme/crolord/` 放入 WordPress 的 `wp-content/themes/`，将 `plugins/` 下需要的插件目录放入 `wp-content/plugins/`，再在后台分别启用。也可以将单个组件目录压缩为 ZIP，通过对应的主题或插件上传入口安装。

WordPress 6.2+，PHP 7.4+。Echoes 的独立运行组件另需 Linux / systemd、Node.js 20+ 与 Python 3.9+；安装与连接说明见各组件的 readme。已部署站点更新同名组件即可，保留数据库、内容与授权设置。

## 维护

仓库只保存当前源码、生产资源和必要许可。可读 CSS/JavaScript 与压缩产物需同步维护；功能变更时更新实际组件版本。第三方资源的授权文件随组件保留。

不提交旧安装包、版本档案、迁移报告、测试缓存、数据库、账号凭据或用户上传文件。
