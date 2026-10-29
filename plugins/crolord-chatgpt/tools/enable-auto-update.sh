#!/usr/bin/env bash
# One-time migration. Later updates run as crolord-echoes; PHP never uses sudo.
set -Eeuo pipefail
umask 077
export PATH="/opt/crolord-runtime/bin:$PATH"
[[ $EUID -eq 0 ]] || { echo '首次迁移请使用 sudo bash 运行。'; exit 1; }
CROLORD_SOURCE=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
[[ -f /opt/crolord-echoes/echoes-server.py && -f /var/lib/crolord-echoes/bridge.key ]] || { echo '请先安装账号运行组件。'; exit 1; }
CROLORD_PYTHON=$(readlink -f -- "$(command -v python3)")
CROLORD_BACKUP=/opt/crolord-echoes/auto-update-backup-$(date +%Y%m%d-%H%M%S)-$$
install -d -m 0700 "$CROLORD_BACKUP"
CROLORD_DROPIN=/etc/systemd/system/crolord-echoes.service.d/auto-update.conf
[[ ! -f "$CROLORD_DROPIN" ]] || cp -p "$CROLORD_DROPIN" "$CROLORD_BACKUP/auto-update.conf"
[[ ! -f /var/lib/crolord-echoes/programs/deployment.json ]] || cp -pL /var/lib/crolord-echoes/programs/deployment.json "$CROLORD_BACKUP/deployment.json"
rollback() {
 trap - ERR
 systemctl stop crolord-echoes.service || true
 if [[ -f "$CROLORD_BACKUP/auto-update.conf" ]]; then cp -p "$CROLORD_BACKUP/auto-update.conf" "$CROLORD_DROPIN"; else rm -f -- "$CROLORD_DROPIN"; fi
 if [[ -f "$CROLORD_BACKUP/deployment.json" ]]; then
  rm -f /var/lib/crolord-echoes/programs/deployment.restore
  cp -p "$CROLORD_BACKUP/deployment.json" /var/lib/crolord-echoes/programs/deployment.restore
  mv -f /var/lib/crolord-echoes/programs/deployment.restore /var/lib/crolord-echoes/programs/deployment.json
 fi
 systemctl daemon-reload
 systemctl restart crolord-echoes.service || true
 echo "迁移失败，已恢复原启动方式：$CROLORD_BACKUP"
}
trap rollback ERR
systemctl stop crolord-echoes.service
"$CROLORD_PYTHON" - "$CROLORD_SOURCE" <<'PY'
import ast,hashlib,json,os,pwd,shutil,sys,tempfile
from pathlib import Path
source=Path(sys.argv[1]);state=Path('/var/lib/crolord-echoes');root=state/'programs'
if state.is_symlink() or root.is_symlink():raise SystemExit('运行目录不允许符号链接。')
root.mkdir(exist_ok=True,mode=0o700);files={}
for path in source.rglob('*'):
 if not path.is_file() or path.is_symlink() or '__pycache__' in path.parts:continue
 name=path.relative_to(source).as_posix()
 if (name.startswith(('echoes-','echoes_')) and '/' not in name and name.endswith('.py')) or (name.startswith('vendor/') and name.endswith(('.py','.txt','.typed'))):
  data=path.read_bytes()
  if name.endswith('.py'):ast.parse(data.decode(),feature_version=(3,9))
  files[name]=data
assert all(n in files for n in ('echoes-server.py','echoes_files.py','echoes_mcp.py','echoes_update.py','echoes_sessions.py','echoes_events.py','echoes_artifacts.py','echoes_documents.py','echoes_toolbox.py','echoes_clock.py','vendor/pypdf/__init__.py'))
digest=hashlib.sha256(json.dumps({n:hashlib.sha256(d).hexdigest() for n,d in files.items()},sort_keys=True,separators=(',',':')).encode()).hexdigest();release=root/digest
if release.is_symlink():raise SystemExit('运行版本目录不允许符号链接。')
user=pwd.getpwnam('crolord-echoes');temporary=Path(tempfile.mkdtemp(prefix='migrate-',dir=root))
try:
 for name,data in files.items():
  path=temporary/name;path.parent.mkdir(parents=True,exist_ok=True,mode=0o700);path.write_bytes(data);path.chmod(0o600)
 for path in [temporary,*temporary.rglob('*')]:os.chown(path,user.pw_uid,user.pw_gid,follow_symlinks=False)
 if release.exists():shutil.rmtree(release)
 os.replace(temporary,release)
finally:
 if temporary.exists():shutil.rmtree(temporary)
fd,temp=tempfile.mkstemp(prefix='deployment-',dir=root)
with os.fdopen(fd,'w') as stream:json.dump({'active':digest,'pending':None,'attempts':0,'version':'1.16.0'},stream)
os.chmod(temp,0o600);os.chown(temp,user.pw_uid,user.pw_gid,follow_symlinks=False);os.replace(temp,root/'deployment.json');os.chown(root,user.pw_uid,user.pw_gid,follow_symlinks=False)
PY
install -m 0644 "$CROLORD_SOURCE/runtime-loader.py" /opt/crolord-echoes/runtime-loader.py
install -d -m 0755 /etc/systemd/system/crolord-echoes.service.d
cat > "$CROLORD_DROPIN" <<UNIT
[Service]
ExecStart=
ExecStart=$CROLORD_PYTHON /opt/crolord-echoes/runtime-loader.py
UNIT
systemctl daemon-reload
systemctl restart crolord-echoes.service
"$CROLORD_PYTHON" - <<'PY'
import json,time,urllib.request
from pathlib import Path
key=Path('/var/lib/crolord-echoes/bridge.key').read_text().strip()
for attempt in range(12):
 try:
  request=urllib.request.Request('http://127.0.0.1:17683/health',data=b'{}',headers={'Authorization':'Bearer '+key,'Content-Type':'application/json'})
  with urllib.request.urlopen(request,timeout=2) as response:data=json.load(response)
  if data.get('version')=='1.16.0' and data.get('auto_update') is True:break
 except Exception:pass
 time.sleep(.5)
else:raise SystemExit('自动更新健康检查未通过。')
print('自动更新已启用。以后上传插件、进入后台即可更新运行组件，无需重复执行命令。')
PY
trap - ERR
