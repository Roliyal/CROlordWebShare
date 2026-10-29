"""A scoped WordPress queue alarm; independent of conversations and model calls."""
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class PublisherClock:
    def __init__(self, state):
        self.path = Path(state) / 'publisher-clock.json'
        self.lock = threading.Condition()
        self.config = None
        self.last_ok = 0
        self.failures = 0
        if self.path.is_file() and not self.path.is_symlink():
            try:
                self.config = self.validate(json.loads(self.path.read_text()))
            except (ValueError, OSError):
                pass
        self.thread = threading.Thread(target=self.run, name='publisher-clock', daemon=True)
        self.thread.start()

    @staticmethod
    def validate(body):
        url, key, due = body.get('url'), body.get('key'), body.get('next_due', 0)
        if not isinstance(url, str) or len(url) > 2048 or not isinstance(key, str) or not re.fullmatch('[a-f0-9]{64}', key):
            raise ValueError('Publisher 定时配置无效。')
        parsed = urllib.parse.urlsplit(url)
        if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment or not parsed.path.endswith('/wp-admin/admin-ajax.php'):
            raise ValueError('Publisher 定时地址必须为本站 HTTPS 后台入口。')
        if not isinstance(due, int) or isinstance(due, bool) or not 0 <= due <= 4102444800:
            raise ValueError('Publisher 定时时间无效。')
        return {'url': url, 'key': key, 'next_due': due}

    def configure(self, body):
        config = self.validate(body)
        with self.lock:
            # The authenticated local bridge is the sole writer. Never accept a public callback config.
            self.config = config
            self.failures = 0
            self.path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            fd, name = tempfile.mkstemp(prefix='clock-', dir=self.path.parent)
            try:
                with os.fdopen(fd, 'w') as stream:
                    json.dump(config, stream)
                os.chmod(name, 0o600)
                os.replace(name, self.path)
            finally:
                if os.path.exists(name):
                    os.unlink(name)
            self.lock.notify_all()
            return self.status()

    def status(self):
        return {'enabled': self.config is not None, 'next_due': (self.config or {}).get('next_due', 0), 'last_ok': self.last_ok, 'failures': self.failures}

    @staticmethod
    def callback(config):
        stamp = str(int(time.time()))
        signature = hmac.new(bytes.fromhex(config['key']), ('publisher-clock|' + stamp).encode(), hashlib.sha256).hexdigest()
        body = urllib.parse.urlencode({'action': 'crolord_publisher_clock', 'stamp': stamp, 'signature': signature}).encode()
        request = urllib.request.Request(config['url'], data=body, headers={'Content-Type': 'application/x-www-form-urlencoded', 'User-Agent': 'Roliyal-Publisher/1.24'}, method='POST')
        # Default TLS verification stays enabled; redirects never carry the scoped signature elsewhere.
        with urllib.request.build_opener(NoRedirect()).open(request, timeout=140) as response:
            data = json.loads(response.read(65537))
        if not isinstance(data, dict) or data.get('success') is not True:
            raise ValueError('Publisher callback failed')
        due = data.get('data', {}).get('next_due')
        if not isinstance(due, int) or isinstance(due, bool) or not 0 <= due <= 4102444800:
            raise ValueError('Publisher callback returned invalid due time')
        return due

    def run(self):
        while True:
            with self.lock:
                config = dict(self.config) if self.config else None
                due = config.get('next_due', 0) if config else 0
                if not due:
                    self.lock.wait(300)
                    continue
                delay = due - time.time()
                if delay > 0:
                    self.lock.wait(min(delay, 300))
                    continue
            try:
                next_due = self.callback(config)
                with self.lock:
                    self.last_ok = int(time.time())
                    self.failures = 0
                    if self.config == config:
                        self.config['next_due'] = max(int(time.time()) + 1, next_due) if next_due else 0
            except (ValueError, OSError, urllib.error.URLError):
                with self.lock:
                    self.failures += 1
                    if self.config == config:
                        self.config['next_due'] = int(time.time()) + min(300, 5 * (2 ** min(self.failures, 6)))
