#!/usr/bin/env python3
"""Root-owned loader; systemd executes it as the dedicated service user."""
import json,os,re,sys,tempfile
from pathlib import Path
root=Path(os.environ.get('CROLORD_ECHOES_STATE','/var/lib/crolord-echoes'))/'programs';path=root/'deployment.json';data=json.loads(path.read_text())
if data.get('pending'):
    if data.get('attempts',0)>=2 and data.get('previous'):data.update(failed=data['active'],active=data['previous'],pending=None,attempts=0,error='新组件启动失败，已自动恢复上一版。')
    else:data['attempts']=data.get('attempts',0)+1
    fd,temp=tempfile.mkstemp(prefix='deployment-',dir=root)
    with os.fdopen(fd,'w') as stream:json.dump(data,stream)
    os.chmod(temp,0o600);os.replace(temp,path)
release=data.get('active','')
if not re.fullmatch('[a-f0-9]{64}',release):raise SystemExit('Invalid runtime release')
program=root/release/'echoes-server.py'
if not program.is_file():raise SystemExit('Runtime release is missing')
os.execv(sys.executable,[sys.executable,str(program)])
