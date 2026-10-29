#!/usr/bin/env bash
set -Eeuo pipefail
export PATH="/opt/crolord-runtime/bin:$PATH"
umask 077
[[ $EUID -eq 0 ]] || { echo '请以 root / sudo 运行。'; exit 1; }
for CROLORD_TOOL in node npm python3 systemctl getent useradd install; do command -v "$CROLORD_TOOL" >/dev/null || { echo "缺少 $CROLORD_TOOL，请先安装 Node.js 20+、Python 3.9+ 与 systemd。"; exit 1; }; done
node -e 'if(Number(process.versions.node.split(".")[0])<20)process.exit(1)' || { echo '需要 Node.js 20+。'; exit 1; }
python3 -c 'import sys;sys.exit(sys.version_info < (3,9))' || { echo '需要 Python 3.9+。'; exit 1; }
CROLORD_PYTHON=$(readlink -f -- "$(command -v python3)")
CROLORD_NODE=$(readlink -f -- "$(command -v node)")
case "$CROLORD_PYTHON:$CROLORD_NODE" in
  /root/*|/home/*|*:/root/*|*:/home/*) echo '运行环境位于个人目录，后台服务无法读取。请先将 Node.js / Python 安装到 /usr 或 /opt。'; exit 1 ;;
esac
CROLORD_SOURCE=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
[[ -f "$CROLORD_SOURCE/echoes-server.py" ]] || { echo '账号运行文件缺失。'; exit 1; }
CROLORD_WEB_GROUP="${1:-}"
if [[ -z "$CROLORD_WEB_GROUP" ]]; then
  if getent group apache >/dev/null; then CROLORD_WEB_GROUP=apache
  elif getent group www-data >/dev/null; then CROLORD_WEB_GROUP=www-data
  else echo '未识别 PHP 用户组，请把 PHP 服务用户组作为第一个参数传入。'; exit 1; fi
fi
getent group "$CROLORD_WEB_GROUP" >/dev/null || { echo 'PHP 服务用户组不存在。'; exit 1; }
getent passwd crolord-echoes >/dev/null || useradd --system --home-dir /var/lib/crolord-echoes --shell /usr/sbin/nologin crolord-echoes
install -d -m 0750 -o crolord-echoes -g "$CROLORD_WEB_GROUP" /var/lib/crolord-echoes
install -d -m 0700 -o crolord-echoes -g crolord-echoes /var/lib/crolord-echoes/work /var/lib/crolord-echoes/.codex
install -d -m 0755 /opt/crolord-echoes /opt/crolord-echoes/runtime
install -m 0644 "$CROLORD_SOURCE/echoes-server.py" /opt/crolord-echoes/echoes-server.py
install -m 0644 "$CROLORD_SOURCE/echoes_files.py" "$CROLORD_SOURCE/echoes_mcp.py" "$CROLORD_SOURCE/echoes_update.py" "$CROLORD_SOURCE/echoes_sessions.py" "$CROLORD_SOURCE/echoes_events.py" "$CROLORD_SOURCE/echoes_artifacts.py" "$CROLORD_SOURCE/echoes_documents.py" "$CROLORD_SOURCE/echoes_toolbox.py" "$CROLORD_SOURCE/echoes_clock.py" /opt/crolord-echoes/
cp -R -- "$CROLORD_SOURCE/vendor" /opt/crolord-echoes/
chmod -R a+rX /opt/crolord-echoes/vendor
if [[ ! -f /var/lib/crolord-echoes/bridge.key ]]; then
  python3 -c 'import secrets;print(secrets.token_hex(32))' > /var/lib/crolord-echoes/bridge.key
fi
chown crolord-echoes:"$CROLORD_WEB_GROUP" /var/lib/crolord-echoes/bridge.key
chmod 0640 /var/lib/crolord-echoes/bridge.key
CROLORD_CODEX_RELEASE=0.163.0 # Protocol validated for this release; no implicit CLI upgrades.
[[ "$CROLORD_CODEX_RELEASE" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo '无法取得官方稳定版 Codex 版本。'; exit 1; }
npm install --omit=dev --prefix /opt/crolord-echoes/runtime --registry=https://registry.npmjs.org "@openai/codex@$CROLORD_CODEX_RELEASE"
chmod -R a+rX /opt/crolord-echoes/runtime
/opt/crolord-echoes/runtime/node_modules/.bin/codex --version
CROLORD_NODE_DIR=$(dirname -- "$CROLORD_NODE")
cat > /etc/systemd/system/crolord-echoes.service <<UNIT
[Unit]
Description=crolord Echoes ChatGPT account runtime
After=network-online.target
Wants=network-online.target
[Service]
Type=simple
User=crolord-echoes
WorkingDirectory=/var/lib/crolord-echoes/work
ExecStart=$CROLORD_PYTHON /opt/crolord-echoes/echoes-server.py
Environment=PATH=$CROLORD_NODE_DIR:/usr/local/bin:/usr/bin:/bin
Restart=on-failure
RestartSec=5
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=/var/lib/crolord-echoes
InaccessiblePaths=-/var/www -/www -/root -/etc/ssh -/etc/ssl/private
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now crolord-echoes.service
systemctl restart crolord-echoes.service
systemctl is-active crolord-echoes.service
echo '组件已启动。回到 WordPress：设置 → Echoes 对话设置 → 登录 ChatGPT。无需安装地址和 API Key。'
echo '若 PHP 无法连接，请检查本机 SELinux 对 PHP 出站与 bridge.key 的读取策略；不要关闭 SELinux。'

bash "$CROLORD_SOURCE/enable-auto-update.sh"
