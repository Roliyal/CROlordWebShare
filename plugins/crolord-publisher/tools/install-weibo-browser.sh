#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
CROLORD_SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
CROLORD_BROWSER_DIR=$(cd -- "$CROLORD_SCRIPT_DIR/../browser" && pwd -P)
CROLORD_SITE=${1:-https://roliyal.com}
CROLORD_IMAGE=crolord-weibo-browser:1.19.1
CROLORD_CONTAINER=crolord-weibo-browser
CROLORD_VOLUME=crolord-weibo-browser-data
[[ $EUID -eq 0 ]] || { echo '请使用 sudo bash 运行。'; exit 1; }
[[ "$CROLORD_SITE" =~ ^https://[a-zA-Z0-9.-]+(:[0-9]+)?(/[a-zA-Z0-9._~/-]*)?/?$ ]] || { echo '请提供本站 HTTPS 地址。'; exit 1; }
for CROLORD_FILE in Dockerfile package.json package-lock.json seccomp_profile.json worker.cjs driver.cjs core.cjs launcher.cjs check.cjs; do
    [[ -s "$CROLORD_BROWSER_DIR/$CROLORD_FILE" ]] || { echo "运行包缺少 $CROLORD_FILE，尚未安装，请上传完整 Publisher。"; exit 1; }
done
if ! command -v docker >/dev/null; then
    CROLORD_OS_ID=$(awk -F= '$1=="ID"{gsub(/"/,"",$2);print $2}' /etc/os-release)
    CROLORD_OS_VERSION=$(awk -F= '$1=="VERSION_ID"{gsub(/"/,"",$2);print $2}' /etc/os-release)
    if [[ "$CROLORD_OS_ID" == alinux && "$CROLORD_OS_VERSION" == 3* ]] && command -v dnf >/dev/null; then
        echo '首次准备 Docker Engine（Alibaba Cloud Linux 3 官方安装路线）。'
        dnf -y install dnf-plugin-releasever-adapter --repo alinux3-plus
        if [[ ! -f /etc/yum.repos.d/docker-ce.repo ]]; then
            CROLORD_REPO_TMP=$(mktemp)
            trap 'rm -f -- "$CROLORD_REPO_TMP"' EXIT
            if command -v curl >/dev/null; then
                curl --fail --silent --show-error --proto '=https' --connect-timeout 10 --max-time 60 https://mirrors.aliyun.com/docker-ce/linux/centos/docker-ce.repo -o "$CROLORD_REPO_TMP"
            else
                wget --https-only --timeout=30 -O "$CROLORD_REPO_TMP" https://mirrors.aliyun.com/docker-ce/linux/centos/docker-ce.repo
            fi
            install -m 644 "$CROLORD_REPO_TMP" /etc/yum.repos.d/docker-ce.repo
            rm -f -- "$CROLORD_REPO_TMP"
            trap - EXIT
        fi
        dnf -y install docker-ce docker-ce-cli containerd.io docker-buildx-plugin
        systemctl enable --now docker
    else
        echo '尚未安装 Docker Engine。当前系统不自动修改软件源，请按对应系统官方文档安装并启动 Docker 后重试：'
        echo 'https://www.alibabacloud.com/help/en/ecs/user-guide/install-and-use-docker'
        echo '未修改 WordPress 或 Web 服务。'
        exit 1
    fi
fi
docker info >/dev/null 2>&1 || { echo 'Docker 服务未启动，尚未修改网站。'; exit 1; }
CROLORD_EXISTING=0
CROLORD_RUNNING=false
if docker container inspect "$CROLORD_CONTAINER" >/dev/null 2>&1; then
    [[ $(docker inspect --format '{{ index .Config.Labels "com.crolord.component" }}' "$CROLORD_CONTAINER") == weibo-browser ]] || { echo '同名容器不属于本插件，未替换。'; exit 1; }
    CROLORD_EXISTING=1
    CROLORD_RUNNING=$(docker inspect --format '{{.State.Running}}' "$CROLORD_CONTAINER")
    if [[ "$CROLORD_RUNNING" == true ]]; then
        docker exec "$CROLORD_CONTAINER" node -e 'process.exit(require("node:fs").existsSync("/data/busy.json")?1:0)' || { echo '浏览器任务运行中，请先在后台暂停并等任务结束。'; exit 1; }
    fi
fi
install -d -m 700 /etc/crolord-weibo-browser
install -m 600 "$CROLORD_BROWSER_DIR/seccomp_profile.json" /etc/crolord-weibo-browser/seccomp_profile.json
docker build --pull -t "$CROLORD_IMAGE" "$CROLORD_BROWSER_DIR"
docker volume create "$CROLORD_VOLUME" >/dev/null
CROLORD_OPTIONS=(--init --user pwuser --security-opt "seccomp=/etc/crolord-weibo-browser/seccomp_profile.json" --shm-size=512m --memory=2g --cpus=1.5 --pids-limit=256 --cap-drop=ALL --mount "type=volume,src=$CROLORD_VOLUME,dst=/data")
# Test a fresh Chromium, without opening the persistent login profile.
docker run --rm "${CROLORD_OPTIONS[@]}" --entrypoint xvfb-run "$CROLORD_IMAGE" -a node -e 'const p=require("playwright");(async()=>{const b=await p.chromium.launch({headless:false,chromiumSandbox:true});await b.close();console.log("Chromium 沙箱与桌面检查通过。");})().catch(()=>{console.error("当前内核/安全策略不能启动 Chromium 沙箱。未降低沙箱要求，请检查 Docker 与 user namespaces。");process.exit(1);});'
CROLORD_PROBE_MODE=''
[[ "$CROLORD_RUNNING" == true ]] && CROLORD_PROBE_MODE=paused
if docker run --rm "${CROLORD_OPTIONS[@]}" --entrypoint node "$CROLORD_IMAGE" /opt/crolord/check.cjs probe "$CROLORD_SITE" "$CROLORD_PROBE_MODE"; then
    CROLORD_PROBE=0
else
    CROLORD_PROBE=$?
fi
case "$CROLORD_PROBE" in
    0) ;;
    20|23)
        read -r -s -p '输入 Publisher 后台生成的新配对码（输入不回显）：' CROLORD_PAIR_CODE < /dev/tty
        echo
        [[ "$CROLORD_PAIR_CODE" =~ ^[a-f0-9]{32}$ ]] || { echo '配对码格式无效。'; exit 1; }
        CROLORD_PAIR_MODE=''
        [[ "$CROLORD_PROBE" == 23 ]] && CROLORD_PAIR_MODE=repair
        printf '{"site":"%s","code":"%s"}' "$CROLORD_SITE" "$CROLORD_PAIR_CODE" | docker run --rm -i "${CROLORD_OPTIONS[@]}" --entrypoint node "$CROLORD_IMAGE" /opt/crolord/worker.cjs pair "$CROLORD_PAIR_MODE"
        unset CROLORD_PAIR_CODE
        ;;
    *) echo '检查未通过；原配置、登录资料和容器保留，未重新配对。'; exit 1 ;;
esac
# Recheck just before replacing a running instance. No new claims while the site is paused.
if [[ "$CROLORD_RUNNING" == true ]]; then
    docker run --rm "${CROLORD_OPTIONS[@]}" --entrypoint node "$CROLORD_IMAGE" /opt/crolord/check.cjs probe "$CROLORD_SITE" paused
    docker exec "$CROLORD_CONTAINER" node -e 'process.exit(require("node:fs").existsSync("/data/busy.json")?1:0)' || { echo '任务尚未结束，未停止组件。'; exit 1; }
fi
CROLORD_PREVIOUS=''
if [[ "$CROLORD_EXISTING" == 1 ]]; then
    [[ "$CROLORD_RUNNING" != true ]] || docker stop --time 30 "$CROLORD_CONTAINER" >/dev/null
    CROLORD_PREVIOUS="${CROLORD_CONTAINER}-previous-$(date +%s)-$$"
    docker rename "$CROLORD_CONTAINER" "$CROLORD_PREVIOUS"
fi
CROLORD_INSTANCE=$(cat /proc/sys/kernel/random/uuid)
if ! docker run -d --restart unless-stopped --name "$CROLORD_CONTAINER" --label com.crolord.component=weibo-browser --env "CROLORD_BROWSER_INSTANCE_ID=$CROLORD_INSTANCE" "${CROLORD_OPTIONS[@]}" "$CROLORD_IMAGE" >/dev/null; then
    if [[ -n "$CROLORD_PREVIOUS" ]] && ! docker container inspect "$CROLORD_CONTAINER" >/dev/null 2>&1; then
        docker rename "$CROLORD_PREVIOUS" "$CROLORD_CONTAINER"
        [[ "$CROLORD_RUNNING" != true ]] || docker start "$CROLORD_CONTAINER" >/dev/null
        echo '新容器未创建，已恢复原容器和先前运行状态。'
    else
        echo '新组件未启动。保留现有容器与资料，请运行只读诊断。'
        [[ -z "$CROLORD_PREVIOUS" ]] || echo "先前容器：$CROLORD_PREVIOUS"
    fi
    exit 1
fi
CROLORD_READY=0
for CROLORD_TRY in {1..15}; do
    if docker exec -i "$CROLORD_CONTAINER" node - health < "$CROLORD_BROWSER_DIR/check.cjs" >/dev/null 2>&1; then
        CROLORD_READY=1
        break
    fi
    sleep 3
done
if [[ "$CROLORD_READY" != 1 ]]; then
    echo '容器已创建，但尚未通过本次启动的 WordPress 认证心跳，未报告安装成功。'
    [[ -z "$CROLORD_PREVIOUS" ]] || echo "先前容器保留为：$CROLORD_PREVIOUS（停止状态）"
    echo "请执行：sudo bash $CROLORD_SCRIPT_DIR/check-weibo-browser.sh"
    exit 1
fi
[[ -z "$CROLORD_PREVIOUS" ]] || docker rm "$CROLORD_PREVIOUS" >/dev/null
echo '验证通过：浏览器组件已连接 WordPress。回到 Publisher → 微博 → 打开微博登录。'
echo '以后上传插件，组件在空闲时自动同步代码，最长约十分钟。'
echo '保留现有浏览器资料与任务日志；没有新增入站端口。'
