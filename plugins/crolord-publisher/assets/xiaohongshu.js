(function () {
    'use strict';
    const root = document.querySelector('.cp-xhs');
    if (!root || !window.CrolordPublisher) return;
    let busy = false;
    let state = {};
    try { const saved = JSON.parse(root.dataset.connection || '{}'); if (saved && typeof saved === 'object' && !Array.isArray(saved)) state = saved; } catch { /* Native disabled attributes remain the fallback. */ }
    const buttons = root.querySelectorAll('.cp-xhs-action');
    const initialAllowed = new Map(Array.from(buttons, button => [button, !button.disabled]));
    const field = root.querySelector('.cp-xhs-cookie');
    const output = root.querySelector('.cp-xhs-result');
    if (!field || !output) return;
    function text(selector, value) {
        const node = root.querySelector(selector);
        if (node && typeof value === 'string') node.textContent = value;
    }
    function controls() {
        for (const button of buttons) {
            const key = 'can_' + button.dataset.operation;
            button.disabled = busy || (Object.prototype.hasOwnProperty.call(state, key) ? !state[key] : !initialAllowed.get(button));
        }
    }
    function update(info) {
        if (!info || typeof info.connected !== 'boolean') return;
        state = {...state, ...info};
        const label = info.connected ? info.name + ' · UID ' + info.uid : info.state === 'unreadable' ? '已保存会话需要更新' : info.state === 'unverified' ? '会话尚未完成账号核验' : '尚未连接小红书';
        text('.cp-xhs-account strong', label);
        text('.cp-xhs-state', info.state_label || (info.enabled ? '自动同步已启用' : '自动同步未启用'));
        text('.cp-xhs-guidance', info.guidance);
        const publication = root.querySelector('.cp-xhs-publication');
        if (publication) {
            text('.cp-xhs-publication', info.publication?.message || '');
            publication.hidden = !info.publication?.message;
        }
        const setup = root.querySelector('.cp-xhs-connection');
        if (setup && !info.connected) setup.open = true;
        text('.cp-xhs-connection summary', info.connected ? '更新本人会话' : '连接小红书 · 第一步');
        const recovery = root.querySelector('.cp-xhs-recovery');
        if (info.problem?.reconnect || info.session?.needs_user) {
            if (setup) setup.open = true;
            if (recovery) recovery.open = true;
        }
    }
    controls();
    root.addEventListener('click', async event => {
        const button = event.target.closest('.cp-xhs-action');
        if (!button || button.disabled || busy) return;
        const operation = button.dataset.operation;
        if (['check', 'enable', 'inspect'].includes(operation) && field.value.trim()) {
            output.textContent = '填写了新的 Cookie，请先点击“验证并连接本人会话”，核对账号后再启用。';
            root.querySelector('.cp-xhs-connection').open = true;
            root.querySelector('[data-operation="connect"]').focus();
            return;
        }
        if (operation === 'connect' && !field.value.trim()) {
            output.textContent = '请先粘贴本人创作中心的 Cookie 值，再点击“验证并连接本人会话”。';
            field.focus();
            return;
        }
        busy = true;
        controls();
        output.textContent = '正在处理…';
        const data = {action: 'crolord_publisher_xhs', nonce: CrolordPublisher.nonce, operation};
        if (operation === 'connect') data.cookie = field.value.trim();
        if (operation === 'limits') {
            data.image_mb = root.querySelector('.cp-xhs-image-limit').value;
            data.video_mb = root.querySelector('.cp-xhs-video-limit').value;
        }
        try {
            const response = await fetch(CrolordPublisher.ajax, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams(data)});
            let result;
            try { result = await response.json(); } catch { throw new Error('后台未返回预期数据，请刷新登录状态后重试。'); }
            update(result.data);
            if (!result.success) throw new Error(result.data?.message || '操作未完成。');
            const info = result.data;
            output.textContent = info.message;
            text('.cp-xhs-report', info.inspection?.report);
            if (operation === 'connect' || operation === 'disconnect') field.value = '';
            document.dispatchEvent(new CustomEvent('crolord-publisher-xhs-changed'));
        } catch (error) { output.textContent = error.message; }
        finally { delete data.cookie; busy = false; controls(); }
    });
})();
