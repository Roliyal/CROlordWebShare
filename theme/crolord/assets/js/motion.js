(function () {
  'use strict';
  var root = document.querySelector('main.boughs-unified');
  if (!root) return;
  var preference = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');
  var reduced = function () { return preference && preference.matches; };
  var basePath = window.location.pathname, opener = null, closing = false, sequence = 0, navigation = null;
  var pages = new Map(), repositories = new Map(), repoPending = new Map();
  function pageKey(url) { return url.pathname + url.search; }
  function rememberPage(url, html) {
    pages.set(pageKey(url), { html: html, at: Date.now() });
    if (pages.size > 8) pages.delete(pages.keys().next().value);
  }
  rememberPage(new URL(window.location.href), root.outerHTML);
  function activeDialog() { return root.querySelector('.fn-work-dialog'); }
  function animate(element, frames, duration) {
    if (reduced() || typeof element.animate !== 'function') return Promise.resolve();
    return element.animate(frames, { duration: duration, easing: 'cubic-bezier(.22,.61,.36,1)' }).finished.catch(function () {});
  }
  function closePreview(immediate) {
    var dialog = activeDialog();
    if (!dialog || !dialog.open || closing) return Promise.resolve();
    closing = true;
    return (immediate ? Promise.resolve() : animate(dialog, [{ opacity: 1, transform: 'translateY(0)' }, { opacity: 0, transform: 'translateY(5px)' }], 130)).then(function () {
      if (typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open');
      document.body.classList.remove('fn-preview-open'); closing = false;
      if (opener && opener.isConnected) opener.focus({ preventScroll: true });
      opener = null;
    });
  }
  function repositoryData(key, ajaxUrl) {
    var saved = repositories.get(key);
    if (saved && Date.now() - saved.at < 15 * 60 * 1000) return Promise.resolve(saved.data);
    if (repoPending.has(key)) return repoPending.get(key);
    var url = new URL(ajaxUrl, window.location.href);
    url.searchParams.set('action', 'fieldnotes_work_repository'); url.searchParams.set('repository', key);
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timeout = controller ? window.setTimeout(function () { controller.abort(); }, 9000) : null;
    var pending = fetch(url.href, { credentials: 'same-origin', signal: controller ? controller.signal : undefined }).then(function (response) {
      if (!response.ok) throw Error('Repository unavailable');
      return response.json();
    }).then(function (result) {
      var data = result && result.success && result.data;
      if (!data || typeof data.tag !== 'string' || typeof data.branch !== 'string' || typeof data.updated !== 'string' || typeof data.sha !== 'string' || !/^[a-f0-9]{40}$/.test(data.sha)) throw Error('Invalid repository data');
      repositories.set(key, { data: data, at: Date.now() }); return data;
    }).finally(function () { if (timeout) window.clearTimeout(timeout); repoPending.delete(key); });
    repoPending.set(key, pending); return pending;
  }
  function refreshRepository(content, dialog) {
    var key = content.dataset.repository;
    if (!key || !dialog.dataset.ajaxUrl) return;
    repositoryData(key, dialog.dataset.ajaxUrl).then(function (data) {
      if (!content.isConnected) return;
      content.querySelector('[data-repo-release]').textContent = data.tag ? data.tag + (data.published ? ' · ' + data.published : '') : '尚未发布版本';
      content.querySelector('[data-repo-branch]').textContent = data.branch;
      content.querySelector('[data-repo-updated]').textContent = data.updated;
      content.querySelector('[data-repo-sha]').textContent = data.sha.slice(0, 7);
    }).catch(function () {
      if (content.isConnected) content.querySelector('.fn-repo-update-status').textContent = '版本更新暂未读取，可在 GitHub 核对。';
    });
  }
  function openPreview(trigger) {
    var details = document.getElementById(trigger.dataset.workOpen), dialog = activeDialog();
    if (!details || !dialog) return false;
    var content = details.querySelector('.fn-work-detail-content');
    if (!content) return false;
    var body = dialog.querySelector('.fn-work-dialog-body');
    body.replaceChildren(content.cloneNode(true));
    var shown = body.firstElementChild;
    opener = trigger; closing = false;
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
    dialog.scrollTop = 0; document.body.classList.add('fn-preview-open');
    dialog.querySelector('.fn-work-close').focus({ preventScroll: true });
    animate(dialog, [{ opacity: 0, transform: 'translateY(7px)' }, { opacity: 1, transform: 'translateY(0)' }], 180);
    refreshRepository(shown, dialog); return true;
  }
  function replacePage(html, url, addHistory, focusLabel) {
    var parsed = new DOMParser().parseFromString(html, 'text/html');
    var next = parsed.querySelector('main.boughs-unified');
    if (!next || !next.querySelector('.boughs-work-filter')) throw Error('Invalid work page');
    root.replaceWith(next); root = next;
    if (addHistory) window.history.pushState({ boughs: true }, '', url.pathname + url.search + url.hash);
    var status = root.querySelector('.fn-work-filter-status');
    if (status) status.textContent = '筛选已更新。';
    if (focusLabel) {
      var links = root.querySelectorAll('[data-work-filter],.boughs-topic-filter a');
      Array.prototype.some.call(links, function (link) { if (link.textContent.replace('×', '').trim() === focusLabel) { link.focus({ preventScroll: true }); return true; } return false; });
    }
    animate(root, [{ opacity: .75 }, { opacity: 1 }], 170);
    if (url.hash === '#practice-notes') {
      var section = root.querySelector('#practice-notes');
      if (section && section.getBoundingClientRect().top < 65) section.scrollIntoView({ behavior: reduced() ? 'auto' : 'smooth', block: 'start' });
    }
  }
  function navigate(url, addHistory, focusLabel) {
    sequence++; var current = sequence;
    if (navigation) navigation.abort();
    navigation = typeof AbortController !== 'undefined' ? new AbortController() : null;
    root.setAttribute('aria-busy', 'true');
    var cached = pages.get(pageKey(url));
    var work = cached && Date.now() - cached.at < 120000 ? Promise.resolve(cached.html) : fetch(url.href, { credentials: 'same-origin', signal: navigation ? navigation.signal : undefined, headers: { 'Accept': 'text/html' } }).then(function (response) { if (!response.ok) throw Error('Navigation failed'); return response.text(); }).then(function (html) { rememberPage(url, html); return html; });
    return closePreview(true).then(function () { return work; }).then(function (html) {
      if (current !== sequence) return;
      replacePage(html, url, addHistory, focusLabel);
    }).catch(function (error) {
      if (current !== sequence || error.name === 'AbortError') return;
      root.removeAttribute('aria-busy'); window.location.assign(url.href);
    }).finally(function () { if (current === sequence) root.removeAttribute('aria-busy'); });
  }
  document.addEventListener('click', function (event) {
    if (!(event.target instanceof Element)) return;
    var trigger = event.target.closest('[data-work-open]');
    if (trigger && root.contains(trigger) && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && event.button === 0) {
      if (openPreview(trigger)) { event.preventDefault(); event.stopPropagation(); } return;
    }
    var dialog = activeDialog();
    if (dialog && dialog.open && (event.target === dialog || event.target.closest('.fn-work-close'))) { event.preventDefault(); closePreview(false); return; }
    var link = event.target.closest('[data-work-filter],.boughs-topic-filter a,.boughs-pagination a');
    if (!link || !root.contains(link) || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || typeof fetch !== 'function') return;
    var url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin || url.pathname !== basePath) return;
    event.preventDefault(); navigate(url, true, link.textContent.replace('×', '').trim());
  }, true);
  document.addEventListener('cancel', function (event) { if (event.target === activeDialog()) { event.preventDefault(); closePreview(false); } }, true);
  window.addEventListener('popstate', function () { var url = new URL(window.location.href); if (url.pathname === basePath) navigate(url, false, ''); });
  window.addEventListener('pagehide', function () { if (navigation) navigation.abort(); document.body.classList.remove('fn-preview-open'); });
}());

(function () {
  'use strict';
  var root = document.querySelector('.about-updates');
  if (!root) return;
  var slides = Array.from(root.querySelectorAll('.about-update'));
  if (slides.length < 2) return;
  var index = 0, target = 0, timer = null, paused = false, visible = true;
  var busy = false, alive = true, generation = 0, active = null, cancelImage = null;
  var manualRequest = false, direction = 1;
  var preference = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');
  var reduced = function () { return preference && preference.matches; };
  var status = root.querySelector('.about-updates-status');

  function wrap(value) { return (value + slides.length) % slides.length; }
  function preload() {
    var image = slides[wrap(index + 1)].querySelector('img');
    if (image) image.loading = 'eager';
  }
  function ready(image) {
    if (!image) return Promise.resolve(false);
    image.loading = 'eager';
    if (image.complete && image.naturalWidth > 0 && typeof image.decode !== 'function') return Promise.resolve(true);
    return new Promise(function (resolve) {
      var done = false;
      var timeout = window.setTimeout(function () { finish(false); }, 6500);
      function finish(ok) {
        if (done) return;
        done = true;
        window.clearTimeout(timeout);
        image.removeEventListener('load', loaded);
        image.removeEventListener('error', failed);
        cancelImage = null;
        resolve(ok);
      }
      function loaded() { finish(image.naturalWidth > 0); }
      function failed() { finish(false); }
      image.addEventListener('load', loaded, { once: true });
      image.addEventListener('error', failed, { once: true });
      cancelImage = function () { finish(false); };
      if (typeof image.decode === 'function') image.decode().then(loaded, function () {
        if (image.complete) finish(image.naturalWidth > 0);
      });
    });
  }
  function commit(next, announce) {
    slides[index].hidden = true;
    slides[index].classList.remove('is-current');
    index = next;
    slides[index].hidden = false;
    slides[index].classList.add('is-current');
    if (announce && status) status.textContent = slides[index].querySelector('h3').textContent;
    preload();
  }
  function transition(next, step) {
    var outgoing = slides[index];
    var incoming = slides[next];
    var host = outgoing.querySelector('.about-update-image');
    var image = incoming.querySelector('img');
    if (reduced() || !host || typeof host.animate !== 'function') return Promise.resolve();
    var layer = document.createElement('span');
    layer.className = 'about-update-transition';
    layer.setAttribute('aria-hidden', 'true');
    var animations = [];
    var easing = 'cubic-bezier(.22,.61,.36,1)';
    for (var piece = 0; piece < 3; piece++) {
      var shard = document.createElement('span');
      shard.className = 'about-update-shard';
      shard.style.clipPath = 'inset(0 ' + ((2 - piece) * 100 / 3) + '% 0 ' + (piece * 100 / 3) + '%)';
      var copy = image.cloneNode(false);
      copy.removeAttribute('id');
      copy.removeAttribute('srcset');
      copy.removeAttribute('sizes');
      copy.src = image.currentSrc || image.src;
      copy.alt = '';
      copy.loading = 'eager';
      copy.draggable = false;
      shard.appendChild(copy);
      layer.appendChild(shard);
      animations.push(shard.animate([
        { opacity: 0, transform: 'translate3d(' + (step * (7 + piece * 2)) + 'px,' + ((piece - 1) * 4) + 'px,0)' },
        { opacity: 1, transform: 'translate3d(0,0,0)' }
      ], { duration: 860, delay: (step > 0 ? piece : 2 - piece) * 55, easing: easing, fill: 'both' }));
    }
    host.appendChild(layer);
    var caption = outgoing.querySelector('.about-update-caption');
    var incomingCaption = incoming.querySelector('.about-update-caption');
    var captionCopy = null;
    if (caption && incomingCaption) {
      Array.from(caption.children).forEach(function (child) {
        animations.push(child.animate([{ opacity: 1, transform: 'translateY(0)' }, { opacity: 0, transform: 'translateY(-3px)' }], { duration: 300, easing: easing, fill: 'both' }));
      });
      captionCopy = incomingCaption.cloneNode(true);
      captionCopy.className = 'about-caption-transition';
      captionCopy.setAttribute('aria-hidden', 'true');
      captionCopy.inert = true;
      caption.appendChild(captionCopy);
      animations.push(captionCopy.animate([{ opacity: 0, transform: 'translateY(5px)' }, { opacity: 1, transform: 'translateY(0)' }], { duration: 680, delay: 160, easing: easing, fill: 'both' }));
    }
    root.classList.add('is-transitioning');
    var operation = {
      animations: animations,
      clean: function () {
        layer.remove();
        if (captionCopy) captionCopy.remove();
        animations.forEach(function (animation) { animation.cancel(); });
        root.classList.remove('is-transitioning');
        if (active === operation) active = null;
      }
    };
    active = operation;
    return Promise.all(animations.map(function (animation) { return animation.finished.catch(function () {}); }));
  }
  async function pump() {
    if (busy || !alive || target === index) return;
    busy = true;
    root.setAttribute('aria-busy', 'true');
    var next = target, stamp = generation, announce = manualRequest, step = direction;
    try {
      var loaded = await ready(slides[next].querySelector('img'));
      if (!alive || stamp !== generation) return;
      if (next !== target) return;
      if (!loaded) {
        target = index;
        if (announce && status) status.textContent = '图片暂未加载，可稍后重试。';
        return;
      }
      await transition(next, step);
      if (alive && stamp === generation) commit(next, announce);
    } finally {
      if (active) active.clean();
      busy = false;
      root.removeAttribute('aria-busy');
      if (alive && target !== index) pump();
    }
  }
  function show(next, manual, step) {
    target = wrap(next);
    manualRequest = !!manual;
    direction = step || 1;
    pump();
  }
  function start() {
    if (timer) window.clearInterval(timer);
    timer = window.setInterval(function () {
      if (!paused && visible && !busy && !document.hidden && !root.contains(document.activeElement) && !reduced()) show(index + 1, false, 1);
    }, 4000);
  }
  function togglePause() {
    paused = !paused;
    root.setAttribute('aria-label', paused ? '城市漫步最新动态，已暂停' : '城市漫步最新动态');
    if (status) status.textContent = paused ? '已暂停自动播放。' : '已继续自动播放。';
  }
  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) { visible = entries[0].isIntersecting; if (visible) preload(); }, { threshold: .2 });
    observer.observe(root);
  } else preload();
  root.addEventListener('keydown', function (event) {
    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
      event.preventDefault();
      var step = event.key === 'ArrowRight' ? 1 : -1;
      show(target + step, true, step);
      start();
    } else if ((event.key === ' ' || event.key === 'Spacebar') && event.target === root) {
      event.preventDefault();
      togglePause();
    }
  });
  var stage = root.querySelector('.about-updates-stage');
  var pointer = null, suppressClick = false, clickTimer = null;
  if (stage) {
    stage.addEventListener('pointerdown', function (event) {
      if (event.pointerType === 'touch' || event.pointerType === 'pen') pointer = { id: event.pointerId, x: event.clientX, y: event.clientY };
    }, { passive: true });
    stage.addEventListener('pointercancel', function () { pointer = null; }, { passive: true });
    stage.addEventListener('pointerup', function (event) {
      if (!pointer || event.pointerId !== pointer.id) return;
      var dx = event.clientX - pointer.x, dy = event.clientY - pointer.y;
      pointer = null;
      if (Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy) * 1.5) return;
      suppressClick = true;
      if (clickTimer) window.clearTimeout(clickTimer);
      clickTimer = window.setTimeout(function () { suppressClick = false; }, 400);
      var step = dx < 0 ? 1 : -1;
      show(target + step, true, step);
      start();
    }, { passive: true });
    stage.addEventListener('click', function (event) {
      if (suppressClick) { event.preventDefault(); event.stopPropagation(); suppressClick = false; }
    }, true);
  }
  if (preference && typeof preference.addEventListener === 'function') preference.addEventListener('change', function () {
    if (reduced() && active) active.animations.forEach(function (animation) { animation.finish(); });
  });
  window.addEventListener('pagehide', function () {
    alive = false;
    generation++;
    if (timer) window.clearInterval(timer);
    if (clickTimer) window.clearTimeout(clickTimer);
    pointer = null;
    suppressClick = false;
    if (cancelImage) cancelImage();
    if (active) active.clean();
  });
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) { alive = true; target = index; start(); }
  });
  start();
}());
