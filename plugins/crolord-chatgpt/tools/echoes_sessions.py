"""Visitor-bound native context references, kept outside the web root for at most 12h."""
import hashlib,json,time
from pathlib import Path
from echoes_files import identity
from echoes_update import atomic

class Sessions:
    def __init__(self,state):self.state=Path(state);self.root=self.state/'contexts'
    def path(self,owner,conversation):
        identity(owner);identity(conversation,32)
        return self.root/(hashlib.sha256((owner+'|'+conversation).encode()).hexdigest()+'.json')
    def get(self,owner,conversation):
        path=self.path(owner,conversation)
        if not path.is_file():return None
        try:record=json.loads(path.read_text())
        except (ValueError,OSError):return None
        if record.get('expires',0)<time.time():self.remove(owner,conversation);return None
        return record
    def put(self,owner,conversation,record):
        self.root.mkdir(parents=True,exist_ok=True,mode=0o700)
        self.cleanup();path=self.path(owner,conversation);old=self.get(owner,conversation)
        if old and old.get("thread")!=record.get("thread"):self.discard(path)
        atomic(path,record)
    def remove(self,owner,conversation):
        path=self.path(owner,conversation)
        if path.exists():self.discard(path)
    def discard(self,path):
        try:
            record=json.loads(path.read_text());rollout=record.get('rollout')
            if isinstance(rollout,str):
                file=Path(rollout).resolve();sessions=(self.state/'.codex/sessions').resolve()
                if sessions in file.parents and file.suffix=='.jsonl':file.unlink(missing_ok=True)
        except (ValueError,OSError):pass
        path.unlink(missing_ok=True)
    def cleanup(self):
        if not self.root.exists():return
        for path in self.root.glob('*.json'):
            try:expired=json.loads(path.read_text()).get('expires',0)<time.time()
            except (ValueError,OSError):expired=True
            if expired:self.discard(path)
