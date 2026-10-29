/* Local reactions and click-to-load WeChat QR. Sharing anchors work without an SDK. */
(function () {
    'use strict';
    var root = document.querySelector('[data-article-actions]');
    var config = window.crolordArticleConfig;
    if (!root || !config) return;
    var like = root.querySelector('[data-like]');
    var likeStatus = root.querySelector('[data-like-status]');
    var state = null, pendingState = null, voting = false, qrScript = null;
    var dialog = root.querySelector('.crolord-share-dialog');
    var wechat = root.querySelector('[data-wechat]');
    var qr = root.querySelector('[data-share-qr]');
    var shareStatus = root.querySelector('[data-share-status]');
    var shareUrl = root.dataset.shareUrl;

    function request(method, values) {
        var data = new URLSearchParams(Object.assign({action:'crolord_like', post_id:root.dataset.postId}, values || {}));
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timer = controller ? window.setTimeout(function () { controller.abort(); }, 15000) : null;
        return fetch(config.ajaxUrl + (method === 'GET' ? '?' + data.toString() : ''), {
            method:method, credentials:'same-origin', cache:'no-store',
            headers:method === 'POST' ? {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'} : {},
            body:method === 'POST' ? data.toString() : undefined,
            signal:controller ? controller.signal : undefined
        }).then(function (response) {
            return response.json().then(function (result) {
                if (!response.ok || !result.success) {
                    var error = new Error(result.data && result.data.message || config.error);
                    error.status = response.status;
                    throw error;
                }
                if (!result.data || !Number.isInteger(result.data.count) || result.data.count < 0 || typeof result.data.liked !== 'boolean' || typeof result.data.nonce !== 'string') throw new Error(config.error);
                return result.data;
            });
        }).finally(function () { if (timer) window.clearTimeout(timer); });
    }

    function renderState(value) {
        state = value;
        like.setAttribute('aria-pressed', String(value.liked));
        like.setAttribute('aria-label', value.liked ? config.liked : config.like);
        like.title = value.liked ? config.liked : config.like;
        root.querySelector('.crolord-like-wrap').classList.remove('has-error');
    }

    function ensureState(refresh) {
        if (pendingState) return pendingState;
        if (state && !refresh) return Promise.resolve(state);
        pendingState = request('GET').then(function (value) { renderState(value); return value; }).finally(function () { pendingState = null; });
        return pendingState;
    }

    like.addEventListener('click', function () {
        if (voting) return;
        voting = true;
        like.disabled = true;
        like.setAttribute('aria-busy', 'true');
        likeStatus.textContent = '';
        ensureState().then(function (value) {
            if (value.liked) return value;
            return request('POST', {nonce:value.nonce}).catch(function (error) {
                if (error.status !== 403) throw error;
                return ensureState(true).then(function (current) { return request('POST', {nonce:current.nonce}); });
            });
        }).then(function (value) {
            renderState(value);
            likeStatus.textContent = config.thanks;
        }).catch(function (error) {
            root.querySelector('.crolord-like-wrap').classList.add('has-error');
            likeStatus.textContent = error.name === 'AbortError' ? config.error : error.message || config.error;
        }).finally(function () {
            voting = false;
            like.disabled = false;
            like.removeAttribute('aria-busy');
        });
    });

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            if (entries.some(function (entry) { return entry.isIntersecting; })) {
                observer.disconnect();
                ensureState().catch(function () {});
            }
        });
        observer.observe(root);
    }

    function loadQr() {
        if (window.qrcodegen) return Promise.resolve(window.qrcodegen);
        if (qrScript) return qrScript;
        qrScript = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            var timer = window.setTimeout(fail, 15000);
            function fail() { window.clearTimeout(timer); script.remove(); reject(new Error(config.qrError)); }
            script.src = config.qrUrl;
            script.onload = function () { window.clearTimeout(timer); if (window.qrcodegen) resolve(window.qrcodegen); else fail(); };
            script.onerror = fail;
            document.head.appendChild(script);
        }).catch(function (error) { qrScript = null; throw error; });
        return qrScript;
    }

    function renderQr(library) {
        var code = library.QrCode.encodeText(shareUrl, library.QrCode.Ecc.MEDIUM);
        var size = code.size + 8, parts = [];
        for (var y = 0; y < code.size; y++) for (var x = 0; x < code.size; x++) if (code.getModule(x, y)) parts.push('M' + (x + 4) + ' ' + (y + 4) + 'h1v1h-1z');
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 ' + size + ' ' + size);
        svg.setAttribute('shape-rendering', 'crispEdges');
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', '文章链接二维码');
        var background = document.createElementNS(svg.namespaceURI, 'rect');
        background.setAttribute('width', String(size)); background.setAttribute('height', String(size)); background.setAttribute('fill', '#fff');
        var path = document.createElementNS(svg.namespaceURI, 'path');
        path.setAttribute('d', parts.join('')); path.setAttribute('fill', '#3a3a3e');
        svg.append(background, path);
        qr.replaceChildren(svg);
    }

    function closeDialog() {
        if (typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open');
        wechat.setAttribute('aria-expanded', 'false');
        wechat.focus({preventScroll:true});
    }

    wechat.addEventListener('click', function () {
        shareStatus.textContent = '';
        if (!dialog.open) {
            if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
        }
        wechat.setAttribute('aria-expanded', 'true');
        dialog.querySelector('h2').focus({preventScroll:true});
        if (qr.querySelector('svg')) return;
        qr.setAttribute('aria-busy', 'true');
        loadQr().then(renderQr).catch(function () { shareStatus.textContent = config.qrError; }).finally(function () { qr.setAttribute('aria-busy', 'false'); });
    });
    root.querySelector('[data-share-close]').addEventListener('click', closeDialog);
    dialog.addEventListener('cancel', function (event) { event.preventDefault(); closeDialog(); });
    dialog.addEventListener('click', function (event) {
        if (event.target !== dialog) return;
        var box = dialog.getBoundingClientRect();
        if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) closeDialog();
    });
    dialog.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { event.preventDefault(); closeDialog(); }
        if (event.key !== 'Tab') return;
        var nodes = Array.from(dialog.querySelectorAll('button,input,[href]')).filter(function (node) { return !node.disabled && node.getClientRects().length; });
        var first = nodes[0], last = nodes[nodes.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });

    function fallbackCopy() {
        var input = root.querySelector('.crolord-share-url');
        input.focus({preventScroll:true}); input.select(); input.setSelectionRange(0, input.value.length);
        try { return document.execCommand('copy'); } catch (error) { return false; }
    }
    root.querySelector('[data-copy-link]').addEventListener('click', function () {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(shareUrl).then(function () { shareStatus.textContent = config.copied; }).catch(function () { shareStatus.textContent = fallbackCopy() ? config.copied : config.copyHint; });
        } else shareStatus.textContent = fallbackCopy() ? config.copied : config.copyHint;
    });
}());
