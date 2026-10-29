"""Visitor-owned output files. Never expose local paths, provider URLs or raw blobs in polls."""
import base64
import hashlib
import io
import json
import os
from pathlib import Path
import re
import secrets
import stat
import threading
import time
import urllib.parse
import zipfile

from echoes_files import identity, validate_image

DEFAULT_LIMIT = 64 * 1048576
MAX_LIMIT = 256 * 1048576
MAX_FILES = 12
FORMATS = {
    "png": ("image/png", "image"), "jpg": ("image/jpeg", "image"), "jpeg": ("image/jpeg", "image"),
    "webp": ("image/webp", "image"), "gif": ("image/gif", "image"),
    "mp4": ("video/mp4", "video"), "webm": ("video/webm", "video"),
    "mp3": ("audio/mpeg", "audio"), "wav": ("audio/wav", "audio"), "ogg": ("audio/ogg", "audio"),
    "pdf": ("application/pdf", "document"),
    "docx": ("application/vnd.openxmlformats-officedocument.wordprocessingml.document", "document"),
    "xlsx": ("application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "document"),
    "pptx": ("application/vnd.openxmlformats-officedocument.presentationml.presentation", "document"),
    "zip": ("application/zip", "document"), "txt": ("text/plain", "document"),
    "md": ("text/markdown", "document"), "csv": ("text/csv", "document"),
    "tsv": ("text/tab-separated-values", "document"), "json": ("application/json", "document"),
}
MIME_EXT = {mime: ext for ext, (mime, _) in reversed(list(FORMATS.items()))}
MIME_EXT.update({"audio/x-wav": "wav", "text/x-markdown": "md"})


def limits(value):
    if not isinstance(value, int) or isinstance(value, bool):
        return DEFAULT_LIMIT
    return min(MAX_LIMIT, max(1048576, value))


def clean_name(value, fallback="文件"):
    if not isinstance(value, str):
        value = fallback
    value = re.sub(r"[\x00-\x1f\x7f/\\]", "_", value).strip(" .")[:150]
    return value or fallback


def output_type(data, name, mime=""):
    """Match magic and MIME/extension. All documents are served as downloads, never executed."""
    mime = str(mime or "").split(";", 1)[0].lower()
    ext = name.rsplit(".", 1)[-1].lower() if "." in name else MIME_EXT.get(mime, "")
    if ext not in FORMATS:
        raise ValueError("工具返回的文件类型暂不支持；没有伪装成图片或文档。")
    canonical, kind = FORMATS[ext]
    if mime not in ("", "application/octet-stream", "binary/octet-stream", canonical) and MIME_EXT.get(mime) != ext:
        raise ValueError("工具返回的文件类型与文件名不一致。")
    if not data:
        raise ValueError("工具返回了空文件，尚未完成文件交付。")
    if kind == "image":
        signatures = {"png": b"\x89PNG\r\n\x1a\n", "jpg": b"\xff\xd8\xff", "jpeg": b"\xff\xd8\xff", "gif": b"GIF8", "webp": b"RIFF"}
        if not data.startswith(signatures[ext]) or (ext == "webp" and data[8:12] != b"WEBP"):
            raise ValueError("工具返回的图片字节无效或不完整。")
        validate_image(data, ext)
    elif ext == "mp4" and (len(data) < 16 or data[4:8] != b"ftyp"):
        raise ValueError("工具尚未返回有效的 MP4 视频文件。")
    elif ext == "webm" and not data.startswith(b"\x1aE\xdf\xa3"):
        raise ValueError("工具尚未返回有效的 WebM 视频文件。")
    elif ext == "wav" and not (data.startswith(b"RIFF") and data[8:12] == b"WAVE"):
        raise ValueError("工具返回的 WAV 文件无效。")
    elif ext == "ogg" and not data.startswith(b"OggS"):
        raise ValueError("工具返回的音频文件无效。")
    elif ext == "mp3" and not (data.startswith(b"ID3") or len(data) > 1 and data[0] == 255 and data[1] & 224 == 224):
        raise ValueError("工具返回的 MP3 文件无效。")
    elif ext == "pdf" and not (data.startswith(b"%PDF-") and b"%%EOF" in data[-4096:]):
        raise ValueError("工具返回的 PDF 不完整。")
    elif ext in {"docx", "xlsx", "pptx", "zip"}:
        try:
            with zipfile.ZipFile(io.BytesIO(data)) as archive:
                entries = archive.infolist()
                if len(entries) > 10000 or any(".." in Path(e.filename).parts or e.filename.startswith(("/", "\\")) for e in entries):
                    raise ValueError("工具返回的压缩文件结构异常。")
                expected = {"docx": "word/document.xml", "xlsx": "xl/workbook.xml", "pptx": "ppt/presentation.xml"}.get(ext)
                if expected and expected not in archive.namelist():
                    raise ValueError("工具返回的 Office 文件结构不完整。")
        except zipfile.BadZipFile:
            raise ValueError("工具返回的文件不是完整的 Office 文档或 ZIP。") from None
    elif ext in {"txt", "md", "csv", "tsv", "json"}:
        try:
            text = data.decode("utf-8-sig")
        except UnicodeDecodeError:
            raise ValueError("返回的文本文件需要使用 UTF-8 编码。") from None
        if "\x00" in text:
            raise ValueError("返回的文本文件中含有二进制数据。")
        if ext == "json":
            try:
                json.loads(text)
            except ValueError:
                raise ValueError("返回的 JSON 文件格式无效。") from None
    return ext, canonical, kind


class ArtifactStore:
    def __init__(self, state):
        self.root = Path(state) / "artifacts"
        self.lock = threading.RLock()
        self.last_cleanup = 0

    def folder(self, owner):
        folder = self.root / identity(owner)
        if self.root.is_symlink() or folder.is_symlink():
            raise ValueError("文件暂存目录不可用。")
        return folder

    def cleanup(self):
        if time.time() - self.last_cleanup < 300:
            return
        self.last_cleanup = time.time()
        if self.root.is_symlink() or not self.root.is_dir():
            return
        for folder in self.root.iterdir():
            if not folder.is_dir() or folder.is_symlink():
                continue
            for path in folder.glob("*.json"):
                if path.is_symlink():continue
                try:
                    meta = json.loads(path.read_text())
                    if meta.get("expires", 0) <= time.time():
                        (folder / (path.stem + ".blob")).unlink(missing_ok=True)
                        path.unlink(missing_ok=True)
                except (ValueError, OSError):
                    continue
            if not any(folder.iterdir()):
                folder.rmdir()

    @staticmethod
    def public(record):
        return {k: record[k] for k in ("id", "name", "mime", "kind", "size", "sha256", "expires", "source")}

    def put(self, owner, request_id, name, data, mime="", limit=DEFAULT_LIMIT, days=7, source="mcp"):
        identity(owner)
        if not isinstance(request_id, str) or not re.fullmatch(r"[a-f0-9]{32,64}", request_id):
            raise ValueError("文件不属于有效的对话请求。")
        limit = limits(limit)
        if not isinstance(data, bytes) or not 0 < len(data) <= limit:
            raise ValueError("返回文件为空或超过后台设置的大小上限。")
        name = clean_name(name)
        if "." not in name and mime in MIME_EXT:
            name += "." + MIME_EXT[mime]
        _, mime, kind = output_type(data, name, mime)
        digest = hashlib.sha256(data).hexdigest()
        with self.lock:
            self.cleanup()
            folder = self.folder(owner)
            self.root.mkdir(parents=True, exist_ok=True, mode=0o700)
            folder.mkdir(exist_ok=True, mode=0o700)
            records = []
            for path in folder.glob("*.json"):
                if path.is_symlink():continue
                try:
                    records.append(json.loads(path.read_text()))
                except (ValueError, OSError):
                    continue
            for record in records:
                if record.get("request") == request_id and record.get("sha256") == digest and record.get("expires", 0) > time.time():
                    return self.public(record)
            if sum(r.get("request") == request_id for r in records) >= MAX_FILES:
                raise ValueError("本轮返回文件超过 12 份，请拆分请求。")
            if len(records) >= 128 or sum(r.get("size", 0) for r in records) + len(data) > 1073741824:
                raise ValueError("本次访问的文件空间已满，请稍后再试。")
            if sum(p.stat().st_size for p in self.root.glob("*/*.blob") if p.is_file() and not p.is_symlink()) + len(data) > 2147483648:
                raise ValueError("文件暂存空间已满，请稍后再试。")
            identifier = secrets.token_hex(16)
            record = {"id": identifier, "name": name, "mime": mime, "kind": kind, "size": len(data), "sha256": digest,
                      "expires": int(time.time()) + min(30, max(1, int(days))) * 86400,
                      "source": source if source in ("native", "mcp", "document") else "mcp", "request": request_id}
            blob = folder / (identifier + ".blob")
            with blob.open("xb") as stream:
                os.chmod(blob, 0o600)
                stream.write(data)
            metadata = folder / (identifier + ".json")
            try:
                with metadata.open("x", encoding="utf-8") as stream:
                    os.chmod(metadata, 0o600)
                    json.dump(record, stream, ensure_ascii=False)
            except Exception:
                blob.unlink(missing_ok=True)
                metadata.unlink(missing_ok=True)
                raise
            return self.public(record)

    def info(self, owner, identifier):
        identity(identifier, 32)
        folder = self.folder(owner)
        metadata, blob = folder / (identifier + ".json"), folder / (identifier + ".blob")
        if folder.is_symlink() or metadata.is_symlink() or blob.is_symlink() or not metadata.is_file() or not blob.is_file():
            raise ValueError("文件已过期或不属于当前访问。")
        record = json.loads(metadata.read_text())
        if record.get("expires", 0) <= time.time() or record.get("id") != identifier or blob.stat().st_size != record.get("size"):
            raise ValueError("文件已过期或不完整。")
        return self.public(record)

    def chunk(self, owner, identifier, offset, length=1048576):
        record = self.info(owner, identifier)
        if not isinstance(offset, int) or isinstance(offset, bool) or not isinstance(length, int) or not 0 <= offset < record["size"] or not 0 < length <= 1048576:
            raise ValueError("文件读取范围无效。")
        path = self.folder(owner) / (identifier + ".blob")
        flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
        fd = os.open(path, flags)
        with os.fdopen(fd, "rb") as stream:
            if not stat.S_ISREG(os.fstat(stream.fileno()).st_mode):
                raise ValueError("文件读取失败。")
            stream.seek(offset)
            data = stream.read(min(length, record["size"] - offset))
        return {"offset": offset, "size": len(data), "data": base64.b64encode(data).decode(), "sha256": hashlib.sha256(data).hexdigest()}

    def list_files(self, owner, request_id=None, offset=0):
        """Only this visitor's valid output metadata, never paths or file contents."""
        if not isinstance(offset, int) or isinstance(offset, bool) or not 0 <= offset <= 128:
            raise ValueError("文件列表位置无效。")
        folder = self.folder(owner)
        if not folder.is_dir():
            return {"files": [], "next_offset": None}
        records = []
        for path in folder.glob("*.json"):
            if path.is_symlink() or not re.fullmatch(r"[a-f0-9]{32}", path.stem):
                continue
            try:
                record = self.info(owner, path.stem)
                stored = json.loads(path.read_text(encoding="utf-8"))
                record["current_turn"] = stored.get("request") == request_id
                records.append((path.stat().st_mtime_ns, record))
            except (ValueError, OSError, KeyError):
                continue
        records.sort(key=lambda pair: (pair[1]["current_turn"], pair[0]), reverse=True)
        return {"files": [record for _, record in records[offset:offset + 24]],
                "next_offset": offset + 24 if offset + 24 < len(records) else None}

    def upload_bytes(self, owner, identifier, maximum):
        """A bounded private read for an explicitly selected same-site media upload."""
        record = self.info(owner, identifier)
        maximum = min(10485760, max(1, int(maximum)))
        if record["size"] > maximum:
            raise ValueError("这份文件超过 WordPress 的媒体上传上限，请先缩小文件。")
        chunks, digest, offset = [], hashlib.sha256(), 0
        while offset < record["size"]:
            part = self.chunk(owner, identifier, offset, min(1048576, record["size"] - offset))
            raw = base64.b64decode(part["data"], validate=True)
            if not raw or part["offset"] != offset or len(raw) != part["size"] or hashlib.sha256(raw).hexdigest() != part["sha256"]:
                raise ValueError("文件分片未通过完整性检查，未上传。")
            chunks.append(raw); digest.update(raw); offset += len(raw)
        if offset != record["size"] or digest.hexdigest() != record["sha256"]:
            raise ValueError("文件内容已变化，未上传。")
        return record, b"".join(chunks)

    def native_image(self, owner, request_id, item, thread_id, work, codex_home, limit=DEFAULT_LIMIT, days=7):
        """Only an actual completed image-generation item for this bound thread can import local bytes."""
        encoded = item.get("result")
        if isinstance(encoded, str) and encoded:
            if len(encoded) > limits(limit) * 4 // 3 + 8:
                raise ValueError("生成图片超过后台文件上限。")
            try:
                data = base64.b64decode(encoded, validate=True)
            except ValueError:
                raise ValueError("图片生成工具未返回有效图片字节。") from None
        else:
            value = item.get("savedPath")
            if not isinstance(value, str):
                raise ValueError("图片工具已返回文件标识，但未提供可读取的图片；尚未完成图片交付。")
            sanitize = lambda s: re.sub(r"[^A-Za-z0-9_-]", "_", str(s)) or "generated_image"
            filename = sanitize(item.get("id", "")) + ".png"
            path = Path(value)
            allowed = (Path(work) / "generated_images" / filename, Path(codex_home) / "generated_images" / sanitize(thread_id) / filename)
            if not path.is_absolute() or path not in allowed or any(p.is_symlink() for p in (path, *path.parents)):
                raise ValueError("生成图片路径不属于本次会话，未读取服务器其他文件。")
            fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
            with os.fdopen(fd, "rb") as stream:
                if not stat.S_ISREG(os.fstat(stream.fileno()).st_mode) or os.fstat(stream.fileno()).st_size > limits(limit):
                    raise ValueError("生成图片无效或超过后台文件上限。")
                data = stream.read(limits(limit) + 1)
        return self.put(owner, request_id, "生成图片.png", data, "image/png", limit, days, "native")

    def mcp(self, owner, request_id, result, client, limit=DEFAULT_LIMIT, days=7):
        artifacts, notices, seen = [], [], set()

        def save(data, name, mime):
            record = self.put(owner, request_id, name, data, mime, limit, days, "mcp")
            if not any(a["id"] == record["id"] for a in artifacts):
                artifacts.append(record)

        def consume(entry, default_name="返回文件", depth=0):
            if not isinstance(entry, dict) or depth > 2 or len(artifacts) >= MAX_FILES:
                return
            mime = entry.get("mimeType", entry.get("mime_type", ""))
            mime = mime if isinstance(mime, str) else ""
            name = clean_name(entry.get("name", entry.get("filename", default_name)))
            encoded = entry.get("blob", entry.get("data", entry.get("base64", entry.get("b64_json"))))
            if isinstance(encoded, str) and encoded:
                if len(encoded) > limits(limit) * 4 // 3 + 8:
                    raise ValueError("工具返回的文件超过后台大小上限。")
                if encoded.startswith("data:"):
                    match = re.fullmatch(r"data:([^;,]{1,150});base64,(.*)", encoded, re.S)
                    if not match:
                        raise ValueError("工具返回的文件编码不受支持。")
                    mime, encoded = match.groups()
                try:
                    data = base64.b64decode(encoded, validate=True)
                except ValueError:
                    raise ValueError("工具返回的文件编码无效。") from None
                save(data, name, mime)
                return
            if entry.get("type") == "resource" and isinstance(entry.get("resource"), dict):
                consume(entry["resource"], name, depth + 1)
                return
            if isinstance(entry.get("text"), str) and (mime in MIME_EXT or entry.get("uri")):
                save(entry["text"].encode(), name, mime or "text/plain")
                return
            uri = entry.get("uri", entry.get("url", entry.get("download_url", entry.get("image_url"))))
            if not isinstance(uri, str) or len(uri) > 4000 or uri in seen or len(seen) >= 16 or any(ord(c) < 32 for c in uri):
                return
            seen.add(uri)
            if uri.startswith("data:"):
                consume({"data": uri, "name": name}, name, depth + 1)
                return
            parsed = urllib.parse.urlsplit(uri)
            inferred = Path(urllib.parse.unquote(parsed.path)).name
            if inferred and "." not in name:
                name = clean_name(inferred)
            if parsed.scheme == "https":
                data, returned_mime = client.download(uri, limits(limit))
                save(data, name, mime or returned_mime)
            elif parsed.scheme in {"file", "resource", "mcp"}:
                # This is a remote resources/read call; never open a tool-provided host path.
                data = client.call("resources/read", {"uri": uri})
                for resource in (data.get("contents") or [])[:MAX_FILES]:
                    if isinstance(resource, dict) and resource.get("uri", uri) == uri:
                        consume(resource, name, depth + 1)

        for content in (result.get("content") or [])[:32]:
            if not isinstance(content, dict):
                continue
            try:
                if content.get("type") in {"image", "audio", "resource", "resource_link"}:
                    consume(content)
                elif content.get("type") == "text" and isinstance(content.get("text"), str) and len(content["text"]) < 128000:
                    try:
                        structured = json.loads(content["text"])
                    except ValueError:
                        continue
                    if isinstance(structured, dict) and any(k in structured for k in ("mimeType", "mime_type", "filename", "blob", "b64_json", "files", "artifacts", "image_url")):
                        consume_structured(structured, consume)
            except (ValueError, OSError):
                notices.append("工具返回的文件未能完整接收，请核对文件格式、大小和工具连接；未冒充交付成功。")
        structured = result.get("structuredContent")
        if isinstance(structured, dict):
            try:
                consume_structured(structured, consume)
            except (ValueError, OSError):
                notices.append("工具的结构化文件结果未能完整接收。")
        return artifacts, list(dict.fromkeys(notices))


def consume_structured(structured, consume):
    if any(k in structured for k in ("blob", "data", "base64", "b64_json", "mimeType", "mime_type", "filename", "image_url")):
        consume(structured)
    for key in ("files", "artifacts", "attachments", "images", "videos"):
        value = structured.get(key)
        if isinstance(value, list):
            for entry in value[:MAX_FILES]:
                consume(entry)
