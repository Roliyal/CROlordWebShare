#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
CROLORD_CHECK_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
CROLORD_CHECK_FILE="$CROLORD_CHECK_DIR/../browser/check.cjs"
CROLORD_NAME=crolord-weibo-browser
command -v docker >/dev/null || { echo '未检测到 Docker；未安装或修改任何组件。'; exit 1; }
docker info >/dev/null 2>&1 || { echo 'Docker 不可访问或未启动。未重启。'; exit 1; }
[[ -f "$CROLORD_CHECK_FILE" ]] || { echo '诊断模块缺失，请上传完整 Publisher。'; exit 1; }
docker container inspect "$CROLORD_NAME" >/dev/null 2>&1 || { echo '尚无本插件容器，请先运行 install-weibo-browser.sh。'; exit 1; }
[[ $(docker inspect --format '{{ index .Config.Labels "com.crolord.component" }}' "$CROLORD_NAME") == weibo-browser ]] || { echo '同名容器不属于本插件，未操作。'; exit 1; }
CROLORD_IS_RUNNING=$(docker inspect --format '{{.State.Running}}' "$CROLORD_NAME")
echo 'Publisher 浏览器组件只读诊断：不领取任务、不操作微博、不显示密钥。'
echo "容器运行：$CROLORD_IS_RUNNING"
CROLORD_FAILURE=0
if [[ "$CROLORD_IS_RUNNING" == true ]]; then
    docker exec -i "$CROLORD_NAME" node - probe < "$CROLORD_CHECK_FILE" || CROLORD_FAILURE=1
    docker exec -i "$CROLORD_NAME" node - health < "$CROLORD_CHECK_FILE" || CROLORD_FAILURE=1
else
    CROLORD_CHECK_IMAGE=$(docker inspect --format '{{.Image}}' "$CROLORD_NAME")
    docker image inspect "$CROLORD_CHECK_IMAGE" >/dev/null 2>&1 || { echo '原运行镜像缺失，未拉取或启动原容器。'; exit 1; }
    docker run --rm -i --read-only --cap-drop=ALL --user pwuser --mount type=volume,src=crolord-weibo-browser-data,dst=/data,readonly --entrypoint node "$CROLORD_CHECK_IMAGE" - probe < "$CROLORD_CHECK_FILE" || CROLORD_FAILURE=1
    echo '原容器保持停止；未启动浏览器、登录微博或处理队列。'
    CROLORD_FAILURE=1
fi
exit "$CROLORD_FAILURE"
