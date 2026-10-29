"""Small, bounded document exporter. No interpreter, shell, templates or host-path input."""
import csv
import io
import json
import math
import re
import zipfile
from xml.sax.saxutils import escape

TOOL_NAME = "echoes_create_document"
TOOL = {
    "type": "function", "name": TOOL_NAME, "deferLoading": False,
    "description": "创建并交付真实可下载文件：DOCX、XLSX、TXT、Markdown、CSV、JSON。文档用 content，表格用 rows。只写入本次访客私有文件区，无需用户另行确认。不要用此工具伪造图片、PDF、视频或工具执行结果。文件卡片由前端自动显示，不要编造 sandbox、服务器路径或下载地址。",
    "inputSchema": {"type": "object", "properties": {
        "name": {"type": "string", "description": "文件名，扩展名必须与 format 一致"},
        "format": {"type": "string", "enum": ["docx", "xlsx", "txt", "md", "csv", "json"]},
        "title": {"type": "string"}, "content": {"type": "string", "description": "文档正文；JSON 格式时为有效 JSON 字符串"},
        "rows": {"type": "array", "items": {"type": "array", "items": {"type": ["string", "number", "boolean", "null"]}}, "description": "CSV/XLSX 的行数据，第一行为表头"}
    }, "required": ["name", "format"], "additionalProperties": False}
}


def xml(value):
    text = str(value)
    if any(ord(c) < 32 and c not in "\n\r\t" or 0xd800 <= ord(c) <= 0xdfff for c in text):
        raise ValueError("文档含有无效的控制字符。")
    return escape(text)


def package(entries):
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, "w", zipfile.ZIP_DEFLATED) as archive:
        for name, value in entries.items():
            archive.writestr(name, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' + value)
    return stream.getvalue()


def docx(title, content):
    body = []
    lines = (["# " + title] if title else []) + content.splitlines()
    for line in lines:
        match = re.match(r"^(#{1,3})\s+(.*)$", line)
        properties = '<w:pPr><w:spacing w:after="140"/></w:pPr>'
        if match:
            level, line = len(match[1]), match[2]
            properties = '<w:pPr><w:pStyle w:val="Heading' + str(level) + '"/></w:pPr>'
        body.append('<w:p>' + properties + '<w:r><w:t xml:space="preserve">' + xml(line) + '</w:t></w:r></w:p>')
    namespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
    styles = '<w:styles xmlns:w="' + namespace + '"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="宋体"/><w:sz w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults>'
    for level, size in ((1, 36), (2, 30), (3, 26)):
        styles += '<w:style w:type="paragraph" w:styleId="Heading' + str(level) + '"><w:name w:val="heading ' + str(level) + '"/><w:pPr><w:spacing w:before="240" w:after="160"/><w:outlineLvl w:val="' + str(level-1) + '"/></w:pPr><w:rPr><w:b/><w:sz w:val="' + str(size) + '"/></w:rPr></w:style>'
    return package({
        "[Content_Types].xml": '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
        "_rels/.rels": '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        "word/document.xml": '<w:document xmlns:w="' + namespace + '"><w:body>' + ''.join(body) + '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr></w:body></w:document>',
        "word/styles.xml": styles + '</w:styles>',
        "word/_rels/document.xml.rels": '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>'
    })


def xlsx(rows):
    sheet = []
    for number, row in enumerate(rows, 1):
        cells = []
        for column, value in enumerate(row, 1):
            letters, current = "", column
            while current:
                current, remainder = divmod(current - 1, 26)
                letters = chr(65 + remainder) + letters
            position = letters + str(number)
            if value is None:
                cells.append('<c r="' + position + '"/>')
            elif isinstance(value, bool):
                cells.append('<c r="' + position + '" t="b"><v>' + str(int(value)) + '</v></c>')
            elif isinstance(value, (int, float)):
                cells.append('<c r="' + position + '"><v>' + str(value) + '</v></c>')
            else:
                cells.append('<c r="' + position + '" t="inlineStr"><is><t xml:space="preserve">' + xml(value) + '</t></is></c>')
        sheet.append('<row r="' + str(number) + '">' + ''.join(cells) + '</row>')
    ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
    return package({
        "[Content_Types].xml": '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        "_rels/.rels": '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        "xl/workbook.xml": '<workbook xmlns="' + ns + '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>',
        "xl/_rels/workbook.xml.rels": '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        "xl/worksheets/sheet1.xml": '<worksheet xmlns="' + ns + '"><sheetData>' + ''.join(sheet) + '</sheetData></worksheet>'
    })


def create(arguments):
    if not isinstance(arguments, dict) or len(json.dumps(arguments, ensure_ascii=False).encode()) > 1048576:
        raise ValueError("单份导出内容最多 1 MB，请拆分文档。")
    fmt, name = arguments.get("format"), arguments.get("name")
    content, title, rows = arguments.get("content", ""), arguments.get("title", ""), arguments.get("rows", [])
    if fmt not in {"docx", "xlsx", "txt", "md", "csv", "json"} or not isinstance(name, str) or not name.lower().endswith("." + fmt):
        raise ValueError("导出格式和文件扩展名必须一致。")
    if not isinstance(content, str) or not isinstance(title, str) or len(title) > 500:
        raise ValueError("文档内容格式无效。")
    if fmt in {"xlsx", "csv"}:
        if not isinstance(rows, list) or not rows or len(rows) > 2000 or any(not isinstance(r, list) or len(r) > 128 for r in rows) or sum(len(r) for r in rows) > 20000:
            raise ValueError("表格最多 2000 行、128 列和 20000 个单元格，请拆分。")
        for row in rows:
            for value in row:
                if not isinstance(value, (str, int, float, bool, type(None))) or isinstance(value, float) and not math.isfinite(value) or isinstance(value, str) and len(value) > 32767:
                    raise ValueError("表格单元格类型或长度无效。")
        if fmt == "xlsx":data = xlsx(rows)
        else:
            stream = io.StringIO(newline="")
            csv.writer(stream).writerows(rows)
            data = stream.getvalue().encode("utf-8-sig")
    elif fmt == "docx":
        if not content.strip():raise ValueError("文档正文为空，未创建空白文件。")
        data = docx(title, content)
    else:
        if not content.strip():raise ValueError("文件内容为空。")
        if fmt == "json":json.loads(content)
        data = content.encode("utf-8")
    return name, data
