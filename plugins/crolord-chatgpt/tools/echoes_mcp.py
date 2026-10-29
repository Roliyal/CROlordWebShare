"""Bounded Streamable HTTP MCP client; endpoints and tool lists are administrator-owned."""
import http.client
import ipaddress
import json
import secrets
import socket
import ssl
import time
import urllib.parse


class McpError(ValueError):
    pass


class Client:
    def __init__(self, server):
        self.server, self.session, self.protocol = server, None, "2025-03-26"
        self.deadline = time.monotonic() + 20
        self.response_limit = 1048576
        self.url = urllib.parse.urlsplit(server["url"])
        if self.url.scheme != "https" or not self.url.hostname or self.url.username or self.url.password or self.url.fragment:
            raise McpError("MCP 需要有效的 HTTPS 服务地址。")

    def connect(self, parsed):
        if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password or parsed.fragment or any(ord(c) < 32 for c in parsed.geturl()):
            raise McpError("返回文件需要有效的公网 HTTPS 地址。")
        if self.deadline is not None and time.monotonic() >= self.deadline:
            raise McpError("工具响应超时，没有自动重新执行。")
        hostname, port = parsed.hostname, parsed.port or 443
        addresses = list(dict.fromkeys(a[4][0] for a in socket.getaddrinfo(hostname, port, type=socket.SOCK_STREAM)))
        if not addresses or any(not ipaddress.ip_address(a).is_global for a in addresses):
            raise McpError("MCP 服务和返回文件必须使用公网 HTTPS 地址。")
        timeout = min(8, max(.1, self.deadline-time.monotonic())) if self.deadline else 8
        connection = http.client.HTTPSConnection(hostname, port, timeout=timeout)
        raw = socket.create_connection((addresses[0], port), timeout=timeout)
        try:
            connection.sock = ssl.create_default_context().wrap_socket(raw, server_hostname=hostname)
            connection.sock.settimeout(min(300, max(.1, self.deadline-time.monotonic())) if self.deadline else 30)
            return connection
        except Exception:
            raw.close()
            raise

    def read_bytes(self, response, limit):
        data = bytearray()
        declared = response.getheader("Content-Length", "")
        if declared.isdigit() and int(declared) > limit:
            raise McpError("工具返回的文件超过后台大小上限。")
        while True:
            if self.deadline is not None and time.monotonic() >= self.deadline:
                raise McpError("MCP 响应超时，工具结果未确认，没有自动重试。")
            chunk = response.read1(min(65536, limit + 1 - len(data)))
            data.extend(chunk)
            if len(data) > limit:
                raise McpError("MCP 返回内容超过文件上限。")
            if not chunk:
                if declared.isdigit() and int(declared) != len(data):
                    raise McpError("工具返回文件不完整。")
                return bytes(data)

    def download(self, uri, limit):
        """Fetch explicit file results only. Never forward MCP credentials to another origin."""
        parsed = urllib.parse.urlsplit(uri)
        connection = None
        try:
            for hop in range(4):
                connection = self.connect(parsed)
                headers = {"Accept": "*/*", "Accept-Encoding": "identity"}
                origin = lambda u: (u.scheme, u.hostname, u.port or 443)
                if origin(parsed) == origin(self.url) and self.server.get("token"):
                    token = self.server["token"]
                    if not isinstance(token, str) or "\r" in token or "\n" in token:raise McpError("MCP 认证格式无效。")
                    headers["Authorization"] = "Bearer " + token
                path = urllib.parse.urlunsplit(("", "", parsed.path or "/", parsed.query, ""))
                connection.request("GET", path, headers=headers)
                response = connection.getresponse()
                if response.status in (301, 302, 303, 307, 308):
                    target = response.getheader("Location", "")
                    if not target or hop == 3:raise McpError("返回文件重定向过多或无效。")
                    parsed = urllib.parse.urlsplit(urllib.parse.urljoin(parsed.geturl(), target))
                    connection.close()
                    continue
                if response.status != 200:raise McpError("文件服务未返回可读取文件。")
                if response.getheader("Content-Encoding", "identity").lower() not in ("identity", ""):
                    raise McpError("返回文件编码暂不支持。")
                return self.read_bytes(response, limit), response.getheader("Content-Type", "application/octet-stream").split(";", 1)[0].lower()
        except (OSError, ValueError, http.client.HTTPException) as error:
            if isinstance(error, McpError):raise
            raise McpError("返回文件下载未完成，请检查工具服务。") from None
        finally:
            if connection:connection.close()

    def request(self, message):
        if self.deadline is not None and time.monotonic() >= self.deadline:
            raise McpError("MCP 连接检查超时，请检查地址后重试。")
        connection = None
        try:
            connection = self.connect(self.url)
            headers = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream",
                       "MCP-Protocol-Version": self.protocol}
            if self.session:
                headers["Mcp-Session-Id"] = self.session
            if self.server.get("token"):
                token = self.server["token"]
                if "\r" in token or "\n" in token:
                    raise McpError("MCP 认证格式无效。")
                headers["Authorization"] = "Bearer " + token
            path = urllib.parse.urlunsplit(("", "", self.url.path or "/", self.url.query, ""))
            connection.request("POST", path, json.dumps(message).encode(), headers)
            response = connection.getresponse()
            if not 200 <= response.status < 300:
                raise McpError("MCP 服务返回 HTTP " + str(response.status) + "，请在后台检查地址与认证。")
            session = response.getheader("Mcp-Session-Id")
            if session:
                if len(session) > 512 or "\r" in session or "\n" in session:
                    raise McpError("MCP 会话格式无效。")
                self.session = session
            if "id" not in message:
                return {}
            limit = 0
            if "text/event-stream" in response.getheader("Content-Type", "").lower():
                buffer = bytearray()
                while True:
                    chunk = response.read1(65536)
                    limit += len(chunk)
                    if limit > self.response_limit:
                        raise McpError("MCP 返回内容过大。")
                    if self.deadline is not None and time.monotonic() > self.deadline:
                        raise McpError("MCP 响应超时，工具结果未确认，没有自动重试。")
                    if not chunk:
                        break
                    # UTF-8 bytes may be split across network chunks.
                    buffer.extend(chunk)
                    buffer = buffer.replace(b"\r\n", b"\n")
                    while b"\n\n" in buffer:
                        block, buffer = buffer.split(b"\n\n", 1)
                        lines = [line[5:].lstrip() for line in block.split(b"\n") if line.startswith(b"data:")]
                        if not lines:
                            continue
                        item = json.loads(b"\n".join(lines).decode())
                        if not isinstance(item, dict):
                            raise McpError("MCP 响应格式无效。")
                        if item.get("id") == message["id"]:
                            return self.result(item)
                raise McpError("MCP 没有返回完整结果。")
            data = self.read_bytes(response, self.response_limit)
            item = json.loads(data)
            if not isinstance(item, dict):
                raise McpError("MCP 响应格式无效。")
            if item.get("id") != message["id"]:
                raise McpError("MCP 返回的请求标识不匹配。")
            return self.result(item)
        except (OSError, ValueError, http.client.HTTPException) as error:
            if isinstance(error, McpError):
                raise
            raise McpError("MCP 连接或响应异常，请在后台检查服务。") from None
        finally:
            if connection:connection.close()

    @staticmethod
    def result(item):
        if "error" in item or not isinstance(item.get("result"), dict):
            raise McpError("MCP 请求未完成，请检查服务支持的方法与权限。")
        return item["result"]

    def call(self, method, params=None):
        return self.request({"jsonrpc": "2.0", "id": secrets.token_hex(8), "method": method, "params": params or {}})

    def tools(self, discover=False):
        result = self.call("initialize", {"protocolVersion": self.protocol, "capabilities": {},
                                          "clientInfo": {"name": "crolord-echoes", "version": "1.16.0"}})
        protocol = result.get("protocolVersion")
        if protocol not in {"2024-11-05", "2025-03-26", "2025-06-18"}:
            raise McpError("MCP 协议版本暂不兼容。")
        self.protocol = protocol
        self.request({"jsonrpc": "2.0", "method": "notifications/initialized"})
        tools, cursor = [], None
        for page in range(16):
            result = self.call("tools/list", {"cursor": cursor} if cursor else {})
            entries = result.get("tools", [])
            if not isinstance(entries, list) or len(entries) > 4096:
                raise McpError("MCP 工具列表无效或过大。")
            tools.extend(entries)
            if len(tools)>4096:raise McpError("MCP 工具列表过长，请缩小服务范围。")
            cursor = result.get("nextCursor")
            if not cursor:
                break
        if cursor:
            raise McpError("MCP 工具列表过长，请缩小服务范围。")
        self.deadline = None
        allowed = self.server.get("tools", [])
        return [t for t in tools if isinstance(t, dict) and isinstance(t.get("name"), str) and (discover or t.get("name") in allowed) and isinstance(t.get("inputSchema"), dict)]

    def invoke(self, name, arguments, on_files=None, file_limit=67108864):
        if name not in self.server.get("tools", []):
            raise McpError("工具未获站主授权。")
        self.response_limit = min(384 * 1048576, max(1048576, int(file_limit) * 4 // 3 * 3 + 1048576)) if on_files else 1048576
        result = self.call("tools/call", {"name": name, "arguments": arguments})
        artifacts, notices = on_files(result) if on_files and not result.get("isError") else ([], [])
        texts = [c["text"][:64000] for c in result.get("content", [])[:32] if isinstance(c, dict) and c.get("type") == "text" and isinstance(c.get("text"), str)]
        text = "\n".join(texts)[:64000]
        if not text and isinstance(result.get("structuredContent"), dict) and not artifacts:
            def safe(value, depth=0):
                if depth > 5:return "[内容已省略]"
                if isinstance(value, dict):return {k: safe(v, depth+1) for k,v in list(value.items())[:32] if k not in {"blob", "base64", "b64_json", "data"}}
                if isinstance(value, list):return [safe(v, depth+1) for v in value[:32]]
                return value[:2000] if isinstance(value, str) else value
            text = json.dumps(safe(result["structuredContent"]), ensure_ascii=False)
        if artifacts:
            text += "\n已接收真实文件，前端自动显示预览及下载卡片：" + "、".join(a["name"] for a in artifacts) + "。不要编造下载地址。"
        if notices:text += "\n" + "\n".join(notices)
        if not text:
            text = "工具未返回可显示的文字结果。"
        output = {"success": not bool(result.get("isError")), "contentItems": [{"type": "inputText", "text": text[:64000]}]}
        if on_files:output.update(_artifacts=artifacts, _notices=notices)
        return output
