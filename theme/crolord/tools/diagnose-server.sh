#!/usr/bin/env bash
# Read-only origin/public comparison. No credentials, public PHP probe or service changes.
set -Eeuo pipefail
umask 077
CROLORD_ROOT="${CROLORD_WP_ROOT:-/var/www/html}"
CROLORD_HOST='roliyal.com'
[[ -f "$CROLORD_ROOT/wp-load.php" ]] || { echo '未找到 /var/www/html/wp-load.php，可用 CROLORD_WP_ROOT 指定现有根目录。'; exit 1; }
command -v curl >/dev/null || { echo '缺少 curl，尚未执行网络探测。'; exit 1; }
CROLORD_TMP=$(mktemp -d)
trap 'rm -rf -- "$CROLORD_TMP"' EXIT
CROLORD_CTL=''
for CROLORD_TOOL in apachectl apache2ctl; do
 if command -v "$CROLORD_TOOL" >/dev/null 2>&1; then CROLORD_CTL=$(command -v "$CROLORD_TOOL"); break; fi
done
echo 'crolord 只读性能自检：不修改配置、不重启、不读取账号文件或访问日志。'
date -u '+UTC %Y-%m-%d %H:%M:%S'
for CROLORD_HEADER in "$CROLORD_ROOT/wp-content/themes/crolord/style.css" "$CROLORD_ROOT/wp-content/plugins/crolord-chatgpt/crolord-chatgpt.php"; do
 if [[ -r "$CROLORD_HEADER" ]]; then
  printf '%s ' "${CROLORD_HEADER#"$CROLORD_ROOT"/}"
  awk '$1=="Version:"{print $2;exit}$1=="*"&&$2=="Version:"{print $3;exit}' "$CROLORD_HEADER"
 fi
done
if [[ -r /proc/loadavg ]]; then printf '负载：'; cat /proc/loadavg; fi
if command -v free >/dev/null; then free -m; fi
df -P "$CROLORD_ROOT"
if command -v ps >/dev/null; then ps -eo comm=,pcpu=,pmem= --sort=-pcpu | head -n 10 || true; fi
if command -v systemctl >/dev/null; then
 for CROLORD_UNIT in httpd.service apache2.service php-fpm.service nginx.service; do
  if systemctl show "$CROLORD_UNIT" -p LoadState 2>/dev/null | awk '$0=="LoadState=loaded"{ok=1}END{exit !ok}'; then
   systemctl show "$CROLORD_UNIT" -p Id -p ActiveState -p SubState -p ExecMainStatus --no-pager || true
  fi
 done
fi
if [[ -n "$CROLORD_CTL" ]]; then
 echo 'Apache 配置校验：'
 "$CROLORD_CTL" -t || true
 echo 'Apache 相关模块：'
 "$CROLORD_CTL" -M 2>/dev/null | awk '/(php|proxy_fcgi|mpm_|headers|deflate|filter|expires|cache)_?/{print}' || true
fi
CROLORD_PORTS=()
if command -v ss >/dev/null; then
 echo '本机 Web 监听：'
 ss -H -lntp 2>/dev/null | awk '/httpd|apache2|nginx|:80 |:443 |:8080 /{print}' || true
 mapfile -t CROLORD_PORTS < <(ss -H -lntp 2>/dev/null | awk '/httpd|apache2/{n=split($4,a,":");if(a[n] ~ /^[0-9]+$/)print a[n]}' | sort -nu | head -n 3)
fi
crolord_probe() {
 local crolord_label="$1" crolord_url="$2"; shift 2
 echo "$crolord_label"
 if curl --silent --show-error --compressed --connect-timeout 4 --max-time 12 \
  -D "$CROLORD_TMP/headers" -o /dev/null \
  -w 'HTTP=%{http_code} DNS=%{time_namelookup}s TCP=%{time_connect}s TLS=%{time_appconnect}s 首字节=%{time_starttransfer}s 总计=%{time_total}s 字节=%{size_download}\n' \
  "$@" "$crolord_url"; then
  awk 'tolower($0) ~ /^(http\/|server:|cache-control:|content-encoding:|age:|x-cache:|req-cost-time:|x-envoy-upstream-service-time:)/ {gsub(/\r/,"");print}' "$CROLORD_TMP/headers"
 else echo '该地址探测未完成；继续检查其他层，不自动修改或重试。'; fi
}
if [[ ${#CROLORD_PORTS[@]} -eq 0 ]]; then
 echo '未发现 Apache 的监听端口。若以非 root 运行，ss 可能看不到进程；也可能使用其他 Web 服务。'
fi
for CROLORD_PORT in "${CROLORD_PORTS[@]}"; do
 [[ "$CROLORD_PORT" =~ ^[0-9]+$ && "$CROLORD_PORT" -gt 0 && "$CROLORD_PORT" -le 65535 ]] || continue
 CROLORD_ARGS=()
 if [[ "$CROLORD_PORT" == 443 || "$CROLORD_PORT" == 8443 ]]; then
  CROLORD_BASE="https://$CROLORD_HOST:$CROLORD_PORT"
  CROLORD_ARGS=(--noproxy '*' --resolve "$CROLORD_HOST:$CROLORD_PORT:127.0.0.1")
 else
  CROLORD_BASE="http://127.0.0.1:$CROLORD_PORT"
  CROLORD_ARGS=(--noproxy '*' -H "Host: $CROLORD_HOST" -H 'X-Forwarded-Proto: https')
 fi
 crolord_probe "本机 Apache :$CROLORD_PORT · 静态文件" "$CROLORD_BASE/wp-content/themes/crolord/assets/css/home.min.css" "${CROLORD_ARGS[@]}"
 crolord_probe "本机 Apache :$CROLORD_PORT · 首页 PHP" "$CROLORD_BASE/" "${CROLORD_ARGS[@]}"
 crolord_probe "本机 Apache :$CROLORD_PORT · Echoes 口令页" "$CROLORD_BASE/echoes/" "${CROLORD_ARGS[@]}"
done
crolord_probe '公网 HTTPS · 静态文件' "https://$CROLORD_HOST/wp-content/themes/crolord/assets/css/home.min.css"
crolord_probe '公网 HTTPS · 首页 PHP' "https://$CROLORD_HOST/"
crolord_probe '公网 HTTPS · Echoes 口令页' "https://$CROLORD_HOST/echoes/"
echo '判断：本机与公网静态都慢，先查服务和资源；仅 PHP 慢，查 WordPress/数据库/网页 PHP OPcache；本机快而公网慢，查域名、TLS、CDN/代理与回源。'
echo '3xx 是重定向，本脚本不跟随；仅绑定指定内网地址的服务可能不接受回环探测，需对照监听信息。'
echo '本脚本不验证网页 PHP 的实际 php.ini/OPcache，不会用 CLI 的配置冒充网页配置。'
echo '若出现 502/503 或连接失败，先确认 Web 服务及代理回源健康；静态缓存脚本无法修复这类连接故障。'
