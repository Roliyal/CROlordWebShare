(() => {
  'use strict';
  document.querySelectorAll('[data-rlm-copy]').forEach(button => {
    button.addEventListener('click', async () => {
      const field = document.getElementById(button.dataset.rlmCopy);
      if (!field || !field.value) return;
      try {
        await navigator.clipboard.writeText(field.value);
        button.textContent = '已复制';
        setTimeout(() => { button.textContent = '复制'; }, 1600);
      } catch (_) { field.focus(); field.select(); button.textContent = '请手动复制'; }
    });
  });
  const reveal = document.getElementById('rlm-reveal');
  if (reveal) reveal.addEventListener('click', () => {
    const field = document.getElementById('rlm-secret');
    const visible = field.type === 'password';
    field.type = visible ? 'text' : 'password';
    reveal.setAttribute('aria-pressed', String(visible));
    reveal.textContent = visible ? '隐藏密钥' : '显示密钥';
  });
  const test = document.getElementById('rlm-test');
  if (!test) return;
  test.addEventListener('click', async () => {
    const token = document.getElementById('rlm-secret').value.trim();
    const output = document.getElementById('rlm-test-result');
    if (!/^rlm_[a-f0-9]{64}$/.test(token)) { output.textContent = '请先输入完整的 MCP 密钥。'; return; }
    test.disabled = true; output.textContent = '正在验证连接…';
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20000);
    async function rpc(method, params, id, protocol) {
      const headers = {'Content-Type':'application/json','Accept':'application/json, text/event-stream','Authorization':`Bearer ${token}`};
      if (protocol) headers['MCP-Protocol-Version'] = protocol;
      const response = await fetch(test.dataset.url, {method:'POST',credentials:'omit',cache:'no-store',headers,signal:controller.signal,body:JSON.stringify({jsonrpc:'2.0',id,method,params})});
      const body = await response.json().catch(() => null);
      if (!response.ok || !body || body.error) throw new Error(body?.error?.message || `HTTP ${response.status}；请检查证书、密钥和 REST API/CDN 规则。`);
      return body.result;
    }
    try {
      const init = await rpc('initialize',{protocolVersion:'2025-11-25',capabilities:{},clientInfo:{name:'roliyal-admin-check',version:'1.1.0'}},1);
      const tools = await rpc('tools/list',{},2,init.protocolVersion);
      output.textContent = `连接正常 · ${init.serverInfo.name} · ${tools.tools.length} 个可用工具。`;
    } catch (error) { output.textContent = error.name === 'AbortError' ? '检测超时；请检查网站入口与 REST API。' : error.message; }
    finally { clearTimeout(timeout); test.disabled = false; }
  });
})();
