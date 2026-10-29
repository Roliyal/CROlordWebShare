"""Authenticated non-root delivery of the installed plugin's runtime code."""
import ast,base64,hashlib,json,os,re,shutil,tempfile,threading,time
from pathlib import Path

def atomic(path,value):
    fd,name=tempfile.mkstemp(prefix='deployment-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as stream:json.dump(value,stream)
        os.chmod(name,0o600);os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)

class Updates:
    def __init__(self,state):self.root=Path(state)/'programs';self.lock=threading.RLock()
    def available(self):return (self.root/'deployment.json').is_file()
    def stage(self,body):
        with self.lock:return self._stage(body)
    def _stage(self,body):
        if not self.available():raise ValueError('旧服务需要一次迁移以启用自动更新。')
        version,files=body.get('version'),body.get('files')
        if not isinstance(version,str) or not re.fullmatch(r'\d+\.\d+\.\d+',version) or tuple(map(int,version.split('.')))<(1,10,0):raise ValueError('运行包版本无效。')
        if not isinstance(files,dict) or not 5<=len(files)<=300:raise ValueError('运行包文件数量无效。')
        decoded={};total=0
        for name,encoded in files.items():
            if not isinstance(name,str) or not isinstance(encoded,str) or len(encoded)>3000000:raise ValueError('运行包格式无效。')
            if any(p in ('','.','..') or not re.fullmatch('[A-Za-z0-9_.-]+',p) for p in name.split('/')):raise ValueError('运行包路径无效。')
            if not (re.fullmatch(r'echoes[-_][A-Za-z0-9_-]+\.py',name) or name.startswith('vendor/') and name.endswith(('.py','.txt','.typed'))):raise ValueError('运行包文件类型无效。')
            data=base64.b64decode(encoded,validate=True);total+=len(data)
            if total>8000000:raise ValueError('运行包过大。')
            if name.endswith('.py'):ast.parse(data.decode(),feature_version=(3,9))
            decoded[name]=data
        if any(n not in decoded for n in ('echoes-server.py','echoes_files.py','echoes_mcp.py','echoes_update.py','echoes_sessions.py','echoes_events.py','echoes_artifacts.py','echoes_documents.py','echoes_toolbox.py','echoes_clock.py','vendor/pypdf/__init__.py')):raise ValueError('运行包缺少必要模块。')
        tree=ast.parse(decoded['echoes-server.py'].decode());source_version=next((n.value.value for n in tree.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='VERSION' for t in n.targets) and isinstance(n.value,ast.Constant)),None)
        if source_version!=version:raise ValueError('运行包版本不一致。')
        digest=hashlib.sha256(json.dumps({n:hashlib.sha256(d).hexdigest() for n,d in decoded.items()},sort_keys=True,separators=(',',':')).encode()).hexdigest()
        if body.get('digest')!=digest:raise ValueError('运行包校验失败。')
        path=self.root/'deployment.json';old=json.loads(path.read_text())
        if old.get('failed')==digest:raise ValueError('这份运行包上次启动失败，已恢复旧版；请上传修正版插件。')
        if old.get('active')==digest and not old.get('pending'):return {'status':'current','version':version}
        if old.get('pending')==digest:return {'status':'pending','version':version}
        target=self.root/digest
        if target.is_symlink():raise ValueError('运行目录无效。')
        if not target.exists():
            temp=Path(tempfile.mkdtemp(prefix='stage-',dir=self.root))
            try:
                for name,data in decoded.items():
                    item=temp/name;item.parent.mkdir(parents=True,exist_ok=True,mode=0o700);item.write_bytes(data);item.chmod(0o600)
                os.replace(temp,target)
            finally:
                if temp.exists():shutil.rmtree(temp)
        atomic(path,{'active':digest,'previous':old.get('active'),'pending':digest,'attempts':0,'version':version})
        return {'status':'pending','version':version}
    def pending(self):return self.available() and bool(json.loads((self.root/'deployment.json').read_text()).get('pending'))
    def healthy(self):
        if not self.available():return
        path=self.root/'deployment.json';data=json.loads(path.read_text())
        if data.get('pending')==Path(__file__).resolve().parent.name:data.update(pending=None,attempts=0,updated=int(time.time()));atomic(path,data)
