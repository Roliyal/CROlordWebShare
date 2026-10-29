"""Public app-server progress only; raw reasoning and local execution output are ignored."""
import re
import time
from urllib.parse import urlsplit


def public_url(value):
    if not isinstance(value, str) or len(value) > 4000 or any(ord(c) < 32 for c in value):
        return None
    try:
        parsed = urlsplit(value)
        return value if parsed.scheme in ("https", "http") and parsed.hostname and not parsed.username and not parsed.password else None
    except ValueError:
        return None


class TurnEvents:
    def __init__(self, job=None, lock=None, native_image=None):
        self.job, self.lock = job, lock
        self.started = time.monotonic()
        self.activities, self.messages, self.summaries = {}, {}, {}
        self.sources, self.references = {}, {}
        self.artifacts, self.artifact_notices, self.image_items = {}, [], set()
        self.native_image = native_image
        self.current = None
        self.stage, self.phase = "processing", "处理中"

    def activity(self, identifier, kind, label, detail="", status="running", url=None):
        if len(self.activities) >= 24 and identifier not in self.activities:
            self.activities.pop(next(iter(self.activities)))
        record = {"id": str(identifier)[:160], "kind": kind, "label": str(label)[:180],
                  "detail": str(detail)[:1800], "status": status}
        if public_url(url):
            record["url"] = url
        self.activities[identifier] = record
        self.stage, self.phase = kind, label
        self.publish()

    def source(self, url, title="", reference=None):
        url = public_url(url)
        if not url:
            return
        if url not in self.sources and len(self.sources) < 48:
            self.sources[url] = {"url": url, "title": str(title or urlsplit(url).hostname)[:200], "number": len(self.sources) + 1}
        if url in self.sources and isinstance(reference, str) and re.fullmatch(r"turn\d+(?:search|view|fetch)\d+", reference) and (reference in self.references or len(self.references)<96):
            self.references[reference] = self.sources[url]

    def search_results(self, value):
        # Only explicit URLs/IDs are mapped. Never infer citations from result order.
        pending, count = [value], 0
        while pending and count < 500:
            item = pending.pop(); count += 1
            if isinstance(item, dict):
                ref = item.get("ref_id", item.get("reference_id"))
                self.source(item.get("url"), item.get("title", ""), ref)
                pending.extend(v for v in item.values() if isinstance(v, (list, dict)))
            elif isinstance(item, list):
                pending.extend(item[:100])

    def handle(self, method, params):
        item = params.get("item") or {}
        kind, identifier = item.get("type"), str(item.get("id") or params.get("itemId") or self.current or "message")
        done = method == "item/completed"
        if method in ("item/started", "item/completed") and (kind == "imageGeneration" or item.get("kind") == "image_gen.generation"):
            if done and identifier not in self.image_items:
                self.image_items.add(identifier)
                try:
                    if item.get("failure") or item.get("status") in ("failed", "cancelled", "canceled"):
                        raise ValueError("图片生成未完成，请检查账号图片额度或生成工具状态；没有自动重试。")
                    if not self.native_image:raise ValueError("本次未启用生成文件接收。")
                    artifact = self.native_image(item)
                    self.add_artifacts([artifact])
                    self.activity(identifier, "image", "图片已生成", artifact["name"], "done")
                except (ValueError, OSError) as error:
                    self.add_artifacts([], [str(error)])
                    self.activity(identifier, "image", "图片未交付", str(error), "failed")
            elif not done and identifier not in self.image_items:
                self.activity(identifier, "image", "正在生成图片")
        elif method in ("item/started", "item/completed") and kind == "webSearch":
            action = item.get("action") or {}
            operation, url = action.get("type", "search"), action.get("url")
            queries = action.get("queries") or [action.get("query") or item.get("query") or ""]
            detail = " · ".join(str(q) for q in queries[:4])
            if operation in ("openPage", "findInPage"):
                label = "阅读资料" if operation == "openPage" else "核对资料"
                detail = str(url or action.get("pattern") or "")
                if done:self.source(url)
            else:
                label = "搜索资料"
            if done:self.search_results(item.get("results"))
            self.activity(identifier, "search", label, detail, "done" if done else "running", url)
        elif method == "item/reasoning/summaryTextDelta":
            index = params.get("summaryIndex", 0)
            if not isinstance(index, int) or not 0 <= index < 100:return
            key = identifier + ":" + str(index)
            if key not in self.summaries and len(self.summaries)>=48:self.summaries.pop(next(iter(self.summaries)))
            self.summaries[key] = (self.summaries.get(key, "") + str(params.get("delta", "")))[:1800]
            self.activity(key, "analysis", "分析要点", self.summaries[key])
        elif method == "item/completed" and kind == "reasoning":
            # The public summary is distinct from item.content/raw textDelta.
            for index, summary in enumerate((item.get("summary") or [])[:12]):
                if isinstance(summary, str) and summary.strip():
                    self.activity(identifier + ":" + str(index), "analysis", "分析要点", summary, "done")
        elif method in ("item/started", "item/completed") and kind == "agentMessage":
            self.current = identifier
            record = self.messages.setdefault(identifier, {"text": "", "phase": item.get("phase")})
            if item.get("phase") is not None:record["phase"] = item["phase"]
            if done:record["text"] = item.get("text", "")
            if record["phase"] == "commentary":
                if record["text"]:self.activity(identifier, "update", "进展", record["text"], "done" if done else "running")
            elif record["text"]:
                self.stage, self.phase = "writing", "正在撰写"
            self.publish()
        elif method == "item/agentMessage/delta" and isinstance(params.get("delta"), str):
            self.current = identifier
            record = self.messages.setdefault(identifier, {"text": "", "phase": None})
            record["text"] = (record["text"] + params["delta"]).encode()[:262144].decode(errors="ignore")
            if record["phase"] == "commentary":self.activity(identifier, "update", "进展", record["text"])
            else:
                self.stage, self.phase = "writing", "正在撰写"
                self.publish()
        elif method == "item/started" and kind == "contextCompaction":
            self.activity(identifier, "context", "整理上下文")

    def answer(self):
        return "\n\n".join(v["text"] for v in self.messages.values() if v["phase"] != "commentary" and isinstance(v["text"], str) and v["text"].strip())

    def add_artifacts(self, artifacts, notices=()):
        for artifact in artifacts[:12]:
            if isinstance(artifact, dict) and isinstance(artifact.get("id"), str) and len(self.artifacts) < 12:
                self.artifacts[artifact["id"]] = dict(artifact)
        for notice in notices:
            if isinstance(notice, str) and notice not in self.artifact_notices and len(self.artifact_notices) < 12:
                self.artifact_notices.append(notice[:300])
        self.publish()

    def snapshot(self):
        return {"activity": [dict(v) for v in self.activities.values()], "sources": [dict(v) for v in self.sources.values()],
                "references": {k: dict(v) for k, v in self.references.items()}, "elapsed": int(time.monotonic() - self.started),
                "stage": self.stage, "phase": self.phase, "artifacts": list(self.artifacts.values()), "artifact_notices": list(self.artifact_notices)}

    def publish(self):
        if self.job is None:return
        snapshot = self.snapshot(); snapshot["partial"] = self.answer()
        if self.lock:
            with self.lock:self.job.update(snapshot)
        else:self.job.update(snapshot)

    def finish(self, completed):
        for value in self.activities.values():
            if value["status"] == "running":value["status"] = "done" if completed else "stopped"
        self.stage, self.phase = ("done", "已完成") if completed else ("stopped", "已停止")
        self.publish()
