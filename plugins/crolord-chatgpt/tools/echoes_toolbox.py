"""Discover large, visitor-selected tool sets without expanding their authorization scope."""
import json
import re

SEARCH = 'echoes_search_tools'
CALL = 'echoes_use_tool'


def definitions(registry):
    entries = {key: value for key, value in registry.items() if not value.get('builtin')}
    if len(entries) <= 64:
        direct = [{'type': 'function', 'name': key, 'description': str(value['tool'].get('description', ''))[:3000] + '\n工具已由站主允许并由访客选中，按用户任务直接调用。',
                   'inputSchema': value['tool']['inputSchema'], 'deferLoading': False} for key, value in entries.items()]
        if len(json.dumps(direct).encode()) <= 80000:return direct
    servers = {}
    for value in entries.values():
        name = str(value['server'].get('name', value['server']['id']))[:80]
        servers[name] = servers.get(name, 0) + 1
    return [
        {'type': 'function', 'name': SEARCH, 'description': '按关键词查找访客已选 MCP 工具，返回真实工具标识、说明和参数 schema。工具较多时，先搜索再调用 echoes_use_tool。可多次查询不同关键词；不代表需要重新获得用户许可。已选服务：' + '；'.join(name + ' (' + str(count) + ')' for name, count in servers.items()),
         'inputSchema': {'type': 'object', 'properties': {'query': {'type': 'string', 'description': '关键词；支持工具英文名、服务名和描述中的词'}, 'offset': {'type': 'integer', 'minimum': 0}}, 'required': ['query'], 'additionalProperties': False}, 'deferLoading': False},
        {'type': 'function', 'name': CALL, 'description': '执行 echoes_search_tools 返回的 tool_id，并按其 inputSchema 提供 arguments。只允许本次访客已选工具；工具的返回文件会自动展示。',
         'inputSchema': {'type': 'object', 'properties': {'tool_id': {'type': 'string'}, 'arguments': {'type': 'object', 'additionalProperties': True}}, 'required': ['tool_id', 'arguments'], 'additionalProperties': False}, 'deferLoading': False}
    ]


def search(registry, arguments):
    query, offset = arguments.get('query'), arguments.get('offset', 0)
    if not isinstance(query, str) or len(query) > 300 or not isinstance(offset, int) or isinstance(offset, bool) or not 0 <= offset <= 16384:
        raise ValueError('工具查询格式无效。')
    query = query.casefold().strip()
    tokens = set(re.findall(r'[a-z0-9]+|[\u3400-\u9fff]{1,8}', query))
    matched = []
    for key, entry in registry.items():
        if entry.get('builtin'):continue
        tool, server = entry['tool'], entry['server']
        name = tool['name'].casefold()
        text = (name + ' ' + str(tool.get('description', '')) + ' ' + str(server.get('name', ''))).casefold()
        score = 10 * bool(query and query in text) + sum(4 if token in name else 1 for token in tokens if token in text)
        if score or not query:matched.append((-score, key, entry))
    matched.sort(key=lambda value: (value[0], value[1]))
    results, size = [], 0
    for _, key, entry in matched[offset:offset + 8]:
        value = {'tool_id': key, 'name': entry['tool']['name'], 'server': str(entry['server'].get('name', entry['server']['id']))[:100], 'description': str(entry['tool'].get('description', ''))[:1500], 'inputSchema': entry['tool']['inputSchema']}
        length = len(json.dumps(value).encode())
        if length > 48000:
            value['inputSchema'] = None
            value['error'] = '此工具单项参数说明过大，请在服务端缩小参数 schema。'
            length = len(json.dumps(value).encode())
        if size + length > 58000:break
        results.append(value);size += length
    return {'tools': results, 'total': len(matched), 'next_offset': offset + len(results) if offset + len(results) < len(matched) else None,
            'hint': '使用返回的 tool_id 调用 echoes_use_tool。没有匹配项时换用工具名中的英文关键词或以空查询分页浏览。'}
