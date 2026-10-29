(function () {
  'use strict';
  var storageKey = 'fieldnotes-comment-avatar';
  var caretGuard = '\u200B';

  function plainText(value) { return (value || '').replace(/\u200B/g, ''); }
  function isEmoji(node) { return Boolean(node && node.nodeType === 1 && node.tagName === 'IMG' && node.hasAttribute('data-fct-emoji')); }
  function isBlock(node) { return Boolean(node && node.nodeType === 1 && (node.tagName === 'DIV' || node.tagName === 'P')); }
  function markerFor(slug) { return '[[fct_emoji:' + slug + ']]'; }

  function syncAvatarOptions(form) {
    form.querySelectorAll('.fct-avatar-option').forEach(function (option) {
      var input = option.querySelector('input[type="radio"]');
      option.classList.toggle('is-selected', Boolean(input && input.checked));
    });
  }

  function initCommentIdentity(form) {
    var author = form.querySelector('input[name="author"]');
    var email = form.querySelector('input[name="email"]');
    var profile = form.querySelector('.fct-avatar-profile');
    var toggle = form.querySelector('.fct-identity-edit');
    if (!author || !email || !profile || !toggle) return;
    var authorRow = author.closest('.comment-form-author');
    var emailRow = email.closest('.comment-form-email');
    var status = profile.querySelector('.fct-avatar-status');
    var note = profile.querySelector('.fct-avatar-note');
    var picker = profile.querySelector('.fct-avatar-picker');
    if (!authorRow || !emailRow || !status || !note || !picker) return;
    var messages = window.fctAvatarConfig && window.fctAvatarConfig.messages || {};
    var editing = false;
    var compact = false;
    var frame = 0;
    var selected = profile.getAttribute('data-fct-selected') === '1';
    try {
      var saved = window.localStorage.getItem(storageKey) || '';
      if (saved && picker.querySelector('input[value="' + saved.replace(/[^a-z]/g, '') + '"]')) selected = true;
    } catch (error) { /* Native comment fields still work without storage. */ }
    function validIdentity() { return Boolean(author.value.trim() && author.validity.valid && email.value.trim() && email.validity.valid); }
    function sync() {
      frame = 0;
      var state = profile.getAttribute('data-state');
      var ready = validIdentity() && (state === 'found' || selected && (state === 'missing' || state === 'fallback'));
      var focused = document.activeElement === author || document.activeElement === email;
      var folded = ready && !editing && !focused;
      authorRow.hidden = folded;
      emailRow.hidden = folded;
      form.classList.toggle('fct-identity-compact', folded);
      toggle.hidden = !folded && !editing;
      toggle.textContent = editing ? '完成' : '修改资料';
      toggle.setAttribute('aria-expanded', folded ? 'false' : 'true');
      if (folded) {
        status.textContent = author.value.trim();
        note.textContent = state === 'found' ? '已使用邮箱头像' : '已选择头像';
      } else if (compact) {
        if (messages[state]) status.textContent = messages[state];
        if (messages[state + 'Note']) note.textContent = messages[state + 'Note'];
      }
      compact = folded;
      if (folded && picker.contains(document.activeElement)) toggle.focus({ preventScroll: true });
    }
    function schedule() { if (!frame) frame = window.requestAnimationFrame(sync); }
    toggle.addEventListener('click', function () {
      if (compact) {
        editing = true;
        sync();
        author.focus({ preventScroll: true });
      } else {
        if (!validIdentity()) {
          var invalid = !author.value.trim() || !author.validity.valid ? author : email;
          invalid.focus({ preventScroll: true });
          invalid.reportValidity();
          return;
        }
        editing = false;
        sync();
      }
    });
    author.addEventListener('input', sync);
    email.addEventListener('input', sync);
    form.addEventListener('focusout', schedule);
    form.addEventListener('focusin', sync);
    profile.addEventListener('click', function (event) {
      if (event.target.matches('input[name="fieldnotes_avatar_fallback"]') && !picker.disabled || event.target.closest('.fct-avatar-use-local')) {
        selected = true;
        sync();
      }
    });
    profile.addEventListener('change', function (event) {
      if (event.target.matches('input[name="fieldnotes_avatar_fallback"]') && !picker.disabled) { selected = true; sync(); }
    });
    form.addEventListener('invalid', function (event) {
      if (event.target !== author && event.target !== email) return;
      editing = true;
      sync();
    }, true);
    form.addEventListener('reset', function () { editing = false; schedule(); });
    window.addEventListener('pageshow', schedule);
    if (typeof MutationObserver !== 'undefined') new MutationObserver(sync).observe(profile, { attributes: true, attributeFilter: ['data-state'] });
    sync();
  }


  function initAvatarProfile(form) {
    var profile = form.querySelector('.fct-avatar-profile');
    if (!profile || profile.getAttribute('data-fct-avatar-ready') === 'true') return;
    profile.setAttribute('data-fct-avatar-ready', 'true');
    var email = form.querySelector('input[name="email"]');
    var account = profile.getAttribute('data-fct-account') === '1';
    var choice = profile.querySelector('input[name="fieldnotes_avatar"]');
    var picker = profile.querySelector('.fct-avatar-picker');
    var preview = profile.querySelector('.fct-avatar-preview');
    var status = profile.querySelector('.fct-avatar-status');
    var note = profile.querySelector('.fct-avatar-note');
    var retry = profile.querySelector('.fct-avatar-retry');
    var useLocal = profile.querySelector('.fct-avatar-use-local');
    var override = profile.querySelector('input[name="fieldnotes_avatar_override"]');
    var actions = profile.querySelector('.fct-avatar-actions');
    if (!choice || !picker || !preview || !status || !note) return;
    var config = window.fctAvatarConfig;
    var messages = config && config.messages ? config.messages : {};
    var sequence = 0;
    var debounce;
    var deadline;
    var controller;
    var imageJob = null;
    var lastEmail = null;
    var resolvedEmail = null;
    var originalPreview = preview.getAttribute('data-fct-default') || preview.src;
    picker.disabled = true;

    try {
      var remembered = window.localStorage.getItem(storageKey) || '';
      var rememberedInput = picker.querySelector('input[value="' + remembered.replace(/[^a-z]/g, '') + '"]');
      if (rememberedInput) rememberedInput.checked = true;
    } catch (error) { /* Storage can be disabled. */ }
    syncAvatarOptions(form);

    function currentEmail() { return account ? '@account' : (email ? email.value.trim().toLowerCase() : ''); }
    function validEmail() { return account || Boolean(email && email.value.trim() && email.validity.valid); }
    function fallbackPreview() {
      var checked = picker.querySelector('input:checked');
      if (!checked) return;
      choice.value = checked.value;
      preview.src = checked.parentNode.querySelector('img').src;
    }
    function showState(state, url) {
      profile.setAttribute('data-state', state);
      // The live status announces the completed result, not a busy region that
      // swallows its own completion announcement.
      if (messages[state]) status.textContent = messages[state];
      if (messages[state + 'Note']) note.textContent = messages[state + 'Note'];
      var local = state === 'missing' || state === 'fallback';
      picker.hidden = !local;
      picker.disabled = !local;
      if (retry) retry.hidden = state !== 'unavailable' && state !== 'fallback';
      if (useLocal) useLocal.hidden = state !== 'unavailable';
      if (actions) actions.hidden = state !== 'unavailable' && state !== 'fallback';
      if (override) override.value = state === 'fallback' ? '1' : '0';
      choice.value = 'gravatar';
      if (local) fallbackPreview();
      else preview.src = state === 'found' && url ? url : originalPreview;
    }
    function cancelPending() {
      sequence++;
      window.clearTimeout(debounce);
      window.clearTimeout(deadline);
      if (controller) controller.abort();
      controller = null;
      if (imageJob) imageJob.cancel();
    }
    function loadNativeAvatar(url) {
      return new Promise(function (resolve, reject) {
        var image = new window.Image();
        var finished = false;
        var timer;
        function finish(ok) {
          if (finished) return;
          finished = true;
          window.clearTimeout(timer);
          image.onload = null;
          image.onerror = null;
          imageJob = null;
          if (ok) resolve(url);
          else reject(new Error('native avatar did not load'));
        }
        imageJob = { cancel: function () { finish(false); image.src = ''; } };
        image.onload = function () { finish(image.naturalWidth >= 8 && image.naturalHeight >= 8); };
        image.onerror = function () { finish(false); };
        timer = window.setTimeout(function () { finish(false); image.src = ''; }, 8000);
        image.src = url;
      });
    }
    function lookup(refresh) {
      if (!validEmail()) return;
      cancelPending();
      var request = sequence;
      var address = currentEmail();
      lastEmail = address;
      resolvedEmail = null;
      if (!config || !config.ajaxUrl || typeof window.fetch !== 'function') { showState('unavailable'); return; }
      showState('loading');
      controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
      // Use the current origin even when WordPress is behind a reverse proxy.
      var configuredURL;
      try { configuredURL = new URL(config.ajaxUrl, window.location.href); }
      catch (error) { showState('unavailable'); return; }
      var ajaxURL = configuredURL.pathname + configuredURL.search;
      var nonceRecovered = false;
      function post(data) {
        var options = { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: data };
        if (controller) options.signal = controller.signal;
        return window.fetch(ajaxURL, options);
      }
      function send(probe) {
        if (request !== sequence || address !== currentEmail()) return Promise.reject(new Error('obsolete lookup'));
        var data = new FormData();
        data.append('action', 'fct_avatar_lookup');
        data.append('nonce', config.nonce || '');
        if (!probe) data.append('native_first', '1');
        if (probe && refresh === true) data.append('refresh', '1');
        if (!account) data.append('email', address);
        return post(data).then(function (response) {
          if (request !== sequence || address !== currentEmail()) throw new Error('obsolete lookup');
          if (response.status === 403 && !nonceRecovered) {
            nonceRecovered = true;
            var renew = new FormData();
            renew.append('action', 'fct_avatar_refresh_nonce');
            return post(renew).then(function (fresh) {
              if (!fresh.ok) throw new Error('nonce unavailable');
              return fresh.json();
            }).then(function (fresh) {
              if (!fresh || !fresh.success || !fresh.data || !fresh.data.nonce) throw new Error('nonce unavailable');
              config.nonce = fresh.data.nonce;
              return send(probe);
            });
          }
          if (!response.ok) throw new Error('avatar unavailable');
          return response.json();
        });
      }
      deadline = window.setTimeout(function () {
        if (request !== sequence) return;
        cancelPending();
        resolvedEmail = address;
        showState('unavailable');
      }, 25000);
      function isCurrent() { return request === sequence && address === currentEmail(); }
      function nativeURL(value) {
        if (!value || typeof value !== 'string') return '';
        try {
          var parsed = new URL(value, window.location.href);
          if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') return '';
          // A relay placeholder is not proof that the visitor's avatar exists.
          if (parsed.searchParams.get('action') === 'fct_avatar_image' || /\/assets\/avatars\/avatar-placeholder\.svg$/.test(parsed.pathname)) return '';
          return parsed.href;
        } catch (error) { return ''; }
      }
      function confirmAbsence() {
        if (!isCurrent()) return Promise.reject(new Error('obsolete lookup'));
        return send(true).then(function (result) {
          if (!result || !result.success || !result.data) throw new Error('avatar unavailable');
          // An upstream image can prove absence, but cannot prove that a
          // different browser/CDN path loaded. Keep the native rendering path.
          return { status: result.data.status === 'missing' ? 'missing' : 'unavailable', reason: result.data.reason || 'native_image_unavailable' };
        });
      }
      send(false)
        .then(function (result) {
          if (!isCurrent()) throw new Error('obsolete lookup');
          if (!result || !result.success || !result.data) throw new Error('avatar unavailable');
          if (result.data.status === 'candidate' || result.data.status === 'found') {
            var candidate = nativeURL(result.data.url || '');
            if (!candidate) return confirmAbsence();
            return loadNativeAvatar(candidate).then(function (url) {
              return { status: 'found', url: url };
            }, function () { return confirmAbsence(); });
          }
          return result.data;
        })
        .then(function (result) {
          if (!isCurrent()) return;
          window.clearTimeout(deadline);
          var state = result.status === 'found' || result.status === 'missing' ? result.status : 'unavailable';
          // Unsuccessful lookups are not automatically repeated on every blur.
          resolvedEmail = address;
          profile.setAttribute('data-fct-avatar-result', result.reason || state);
          showState(state, result.url || '');
        })
        .catch(function () {
          if (request !== sequence || address !== currentEmail()) return;
          window.clearTimeout(deadline);
          resolvedEmail = address;
          showState('unavailable');
        });
    }
    function scheduleLookup(immediate) {
      var address = currentEmail();
      if (address === lastEmail && (address === resolvedEmail || profile.getAttribute('data-state') === 'loading')) return;
      cancelPending();
      lastEmail = address;
      resolvedEmail = null;
      showState('idle');
      if (validEmail()) debounce = window.setTimeout(function () { lookup(false); }, immediate ? 0 : 600);
    }
    if (email) {
      email.addEventListener('input', function () { scheduleLookup(false); });
      email.addEventListener('change', function () { scheduleLookup(true); });
      email.addEventListener('blur', function () { scheduleLookup(true); });
      form.addEventListener('focusin', function () {
        // Some browsers fill saved emails without firing an input event.
        if (currentEmail() !== lastEmail) scheduleLookup(true);
      });
    }
    picker.addEventListener('change', function (event) {
      if (!event.target.matches('input[name="fieldnotes_avatar_fallback"]') || picker.disabled) return;
      fallbackPreview();
      syncAvatarOptions(form);
      try { window.localStorage.setItem(storageKey, event.target.value); } catch (error) { /* Storage can be disabled. */ }
    });
    preview.addEventListener('error', function () {
      if (profile.getAttribute('data-state') === 'found') { resolvedEmail = currentEmail(); showState('unavailable'); }
    });
    if (retry) retry.addEventListener('click', function () { lookup(true); });
    if (useLocal) useLocal.addEventListener('click', function () {
      if (profile.getAttribute('data-state') !== 'unavailable') return;
      cancelPending();
      resolvedEmail = currentEmail();
      showState('fallback');
      var selected = picker.querySelector('input:checked');
      if (selected) selected.focus();
    });
    form.addEventListener('reset', function () {
      cancelPending();
      window.setTimeout(function () { lastEmail = null; resolvedEmail = null; syncAvatarOptions(form); scheduleLookup(true); }, 0);
    });
    window.addEventListener('pageshow', function () { scheduleLookup(true); });
    scheduleLookup(true);
  }

  // Defer offscreen groups even after the picker opens; recent large SVGs
  // should not contend with visible faces or the comment editor.
  function loadVisibleEmoji(panel) {
    var images = panel.querySelectorAll('img[data-fct-lazy-src]');
    if (!images.length || panel.fctEmojiImageObserver) return;
    function load(image) {
      image.src = image.dataset.fctLazySrc;
      image.removeAttribute('data-fct-lazy-src');
    }
    if (typeof IntersectionObserver === 'undefined') {
      images.forEach(load);
      return;
    }
    var pending = images.length;
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        load(entry.target);
        observer.unobserve(entry.target);
        pending -= 1;
      });
      if (!pending) { observer.disconnect(); panel.fctEmojiImageObserver = null; }
    }, { root: panel, rootMargin: '64px 0px', threshold: 0 });
    panel.fctEmojiImageObserver = observer;
    images.forEach(function (image) { observer.observe(image); });
  }

  function closePicker(toggle) {
    var panel = document.getElementById(toggle.getAttribute('aria-controls'));
    toggle.setAttribute('aria-expanded', 'false');
    if (panel) panel.hidden = true;
  }

  function positionPicker(toggle) {
    var panel = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!panel || panel.hidden) return;
    var viewport = window.visualViewport;
    var topEdge = viewport ? viewport.offsetTop : 0;
    var leftEdge = viewport ? viewport.offsetLeft : 0;
    var width = viewport ? viewport.width : window.innerWidth;
    var height = viewport ? viewport.height : window.innerHeight;
    panel.style.position = 'fixed';
    panel.style.bottom = 'auto';
    panel.style.maxWidth = Math.max(160, width - 32) + 'px';
    panel.style.maxHeight = Math.max(64, Math.min(280, height - 32)) + 'px';
    panel.style.zIndex = '100';
    var anchor = toggle.getBoundingClientRect();
    var left = Math.max(leftEdge + 16, Math.min(anchor.left, leftEdge + width - panel.offsetWidth - 16));
    var top = anchor.top - panel.offsetHeight - 10;
    if (top < topEdge + 16) top = anchor.bottom + 10;
    top = Math.max(topEdge + 16, Math.min(top, topEdge + height - panel.offsetHeight - 16));
    panel.style.left = Math.round(left) + 'px';
    panel.style.top = Math.round(top) + 'px';
  }

  function createEmojiImage(slug, item) {
    var image = document.createElement('img');
    image.className = 'fct-editor-emoji';
    image.setAttribute('src', item.src);
    image.setAttribute('alt', item.label || '');
    image.setAttribute('width', '24');
    image.setAttribute('height', '24');
    image.setAttribute('data-fct-emoji', slug);
    image.setAttribute('contenteditable', 'false');
    image.setAttribute('draggable', 'false');
    return image;
  }

  function valueFragment(value, emojiMap) {
    var fragment = document.createDocumentFragment();
    var pattern = /\[\[fct_emoji:([a-z0-9-]+)\]\]/g;
    var cursor = 0;
    var match;
    value = plainText(value).replace(/\r\n?/g, '\n');
    while ((match = pattern.exec(value)) !== null) {
      if (match.index > cursor) fragment.appendChild(document.createTextNode(value.slice(cursor, match.index)));
      var item = emojiMap[match[1]];
      if (item) {
        fragment.appendChild(createEmojiImage(match[1], item));
        fragment.appendChild(document.createTextNode(caretGuard));
      } else fragment.appendChild(document.createTextNode(match[0]));
      cursor = pattern.lastIndex;
    }
    if (cursor < value.length) fragment.appendChild(document.createTextNode(value.slice(cursor)));
    return fragment;
  }

  function serializeNode(node) {
    if (node.nodeType === 3) return plainText(node.nodeValue);
    if (node.nodeType !== 1 && node.nodeType !== 11) return '';
    if (isEmoji(node)) return markerFor(node.getAttribute('data-fct-emoji'));
    if (node.tagName === 'BR') return '\n';
    var children = Array.prototype.slice.call(node.childNodes);
    if (isBlock(node) && children.length === 1 && children[0].nodeName === 'BR') return '';
    var chunks = [];
    var inline = '';
    children.forEach(function (child) {
      if (isBlock(child)) {
        if (inline !== '') { chunks.push(inline); inline = ''; }
        chunks.push(serializeNode(child));
      } else inline += serializeNode(child);
    });
    if (inline !== '' || !chunks.length) chunks.push(inline);
    var value = chunks.join('\n');
    var last = children[children.length - 1];
    var previous = children[children.length - 2];
    if (isBlock(node) && last && previous && last.nodeName === 'BR' && previous.nodeName === 'BR') value = value.slice(0, -1);
    return value;
  }

  function restoreSavedValue(editor, source, emojiMap) {
    editor.textContent = '';
    editor.appendChild(valueFragment(source.value || '', emojiMap));
  }

  function syncSource(editor, source) {
    var value = serializeNode(editor);
    var changed = source.value !== value;
    source.value = value;
    editor.classList.toggle('is-empty', value === '');
    if (value.trim()) editor.setAttribute('aria-invalid', 'false');
    if (changed) source.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function currentRange(editor) {
    var selection = window.getSelection();
    if (!selection || !selection.rangeCount) return null;
    var range = selection.getRangeAt(0);
    return editor.contains(range.startContainer) && editor.contains(range.endContainer) ? range : null;
  }

  function saveSelection(editor, state) {
    var range = currentRange(editor);
    if (range) state.range = range.cloneRange();
  }

  function rangeAtCaret(editor, state) {
    var live = currentRange(editor);
    if (live) return live.cloneRange();
    if (state.range && editor.contains(state.range.startContainer) && editor.contains(state.range.endContainer)) return state.range.cloneRange();
    var range = document.createRange();
    range.selectNodeContents(editor);
    range.collapse(false);
    return range;
  }

  function setSelection(range) {
    var selection = window.getSelection();
    if (!selection) return;
    selection.removeAllRanges();
    selection.addRange(range);
  }

  function focusRange(editor, range) {
    try { editor.focus({ preventScroll: true }); } catch (error) { editor.focus(); }
    setSelection(range);
  }

  // Initial restoration has a flat sequence of text and catalogue images.
  function pointAtValueOffset(editor, offset) {
    var logical = 0;
    var children = Array.prototype.slice.call(editor.childNodes);
    for (var i = 0; i < children.length; i++) {
      var node = children[i];
      var size = isEmoji(node) ? markerFor(node.getAttribute('data-fct-emoji')).length : plainText(node.nodeValue).length;
      if (offset <= logical + size) {
        if (isEmoji(node)) {
          if (offset <= logical) return { node: editor, offset: i };
          var after = node.nextSibling;
          var guards = after && after.nodeType === 3 ? after.nodeValue.match(/^\u200B+/) : null;
          return guards ? { node: after, offset: guards[0].length } : { node: editor, offset: i + 1 };
        }
        if (node.nodeType === 3) {
          var raw = node.nodeValue;
          var index = 0;
          var visible = 0;
          while (index < raw.length && visible < offset - logical) {
            if (raw.charAt(index) !== caretGuard) visible++;
            index++;
          }
          while (raw.charAt(index) === caretGuard) index++;
          return { node: node, offset: index };
        }
      }
      logical += size;
    }
    return { node: editor, offset: children.length };
  }

  // This narrowly scoped native-command path preserves browser undo history.
  // Its input is DOM-escaped text plus local, catalogued image elements.
  function nativeEdit(editor, state, command, value) {
    if (state.inCommand || typeof document.execCommand !== 'function') return false;
    if (typeof document.queryCommandSupported === 'function' && !document.queryCommandSupported(command)) return false;
    var before = editor.innerHTML;
    var handled = false;
    state.inCommand = true;
    try { handled = document.execCommand(command, false, value || ''); } catch (error) { /* Range fallback below. */ }
    finally { state.inCommand = false; }
    return handled || editor.innerHTML !== before;
  }

  function insertFragment(editor, source, state, fragment) {
    if (state.composing || !fragment.childNodes.length) return false;
    var range = rangeAtCaret(editor, state);
    // Select an empty browser scaffold instead of appending after its <br>.
    if (serializeNode(editor) === '') range.selectNodeContents(editor);
    focusRange(editor, range);
    var wrapper = document.createElement('div');
    wrapper.appendChild(fragment.cloneNode(true));
    if (!nativeEdit(editor, state, 'insertHTML', wrapper.innerHTML)) {
      range.deleteContents();
      var last = fragment.lastChild;
      range.insertNode(fragment);
      if (last.nodeType === 3) range.setStart(last, last.nodeValue.length);
      else range.setStartAfter(last);
      range.collapse(true);
      setSelection(range);
    }
    saveSelection(editor, state);
    syncSource(editor, source);
    return true;
  }

  function insertEmoji(editor, source, state, slug, emojiMap) {
    if (!emojiMap[slug]) return false;
    return insertFragment(editor, source, state, valueFragment(markerFor(slug), emojiMap));
  }

  function nextInLine(node, backward, editor) {
    while (node && node !== editor) {
      if (isBlock(node)) return null;
      var next = backward ? node.previousSibling : node.nextSibling;
      if (next) return next;
      node = node.parentNode;
    }
    return null;
  }

  function adjacentEmoji(editor, range, backward) {
    if (!range.collapsed) return null;
    var node = range.startContainer;
    var candidate;
    if (node.nodeType === 3) {
      var side = backward ? node.nodeValue.slice(0, range.startOffset) : node.nodeValue.slice(range.startOffset);
      if (plainText(side) !== '') return null;
      candidate = nextInLine(node, backward, editor);
    } else {
      candidate = node.childNodes[backward ? range.startOffset - 1 : range.startOffset];
      if (!candidate) candidate = nextInLine(node, backward, editor);
    }
    while (candidate) {
      if (isEmoji(candidate)) return candidate;
      if (isBlock(candidate) || candidate.nodeName === 'BR') return null;
      if (candidate.nodeType === 3) {
        if (plainText(candidate.nodeValue) !== '') return null;
      } else if (candidate.childNodes.length) {
        candidate = backward ? candidate.lastChild : candidate.firstChild;
        continue;
      }
      candidate = nextInLine(candidate, backward, editor);
    }
    return null;
  }

  function deleteSelection(editor, source, state, range) {
    focusRange(editor, range);
    if (!nativeEdit(editor, state, 'delete', '')) {
      range.deleteContents();
      range.collapse(true);
      setSelection(range);
    }
    saveSelection(editor, state);
    syncSource(editor, source);
  }

  function moveAcrossEmoji(editor, state, backward) {
    var caret = currentRange(editor);
    if (!caret) return false;
    var image = adjacentEmoji(editor, caret, backward);
    if (!image) return false;
    var range = document.createRange();
    if (backward) range.setStartBefore(image);
    else {
      var after = image.nextSibling;
      var guards = after && after.nodeType === 3 ? after.nodeValue.match(/^\u200B+/) : null;
      if (guards) range.setStart(after, guards[0].length);
      else range.setStartAfter(image);
    }
    range.collapse(true);
    setSelection(range);
    saveSelection(editor, state);
    return true;
  }

  function deleteAdjacentEmoji(editor, source, state, backward) {
    if (state.composing || state.inCommand) return false;
    var caret = currentRange(editor);
    if (!caret) return false;
    var image = adjacentEmoji(editor, caret, backward);
    if (!image) return false;
    var range = document.createRange();
    range.selectNode(image);
    var after = image.nextSibling;
    var guards = after && after.nodeType === 3 ? after.nodeValue.match(/^\u200B+/) : null;
    if (guards) range.setEnd(after, guards[0].length);
    deleteSelection(editor, source, state, range);
    return true;
  }

  // Keep below-the-fold avatar probes away from the initial page load.
  // Focus also starts it, including when WordPress moves the reply form.
  function deferAvatarProfile(form) {
    var started = false;
    var observer;
    function start() {
      if (started) return;
      started = true;
      if (observer) observer.disconnect();
      form.removeEventListener('focusin', start);
      try { initAvatarProfile(form); } catch (error) { form.dataset.fctAvatarState = 'unavailable'; }
    }
    form.addEventListener('focusin', start);
    if (typeof IntersectionObserver === 'undefined') { start(); return; }
    observer = new IntersectionObserver(function (entries) {
      if (entries.some(function (entry) { return entry.isIntersecting; })) start();
    }, { rootMargin: '400px 0px' });
    observer.observe(form);
  }

  function fillEmojiPicker(panel, groups, emojiMap) {
    if (panel.getAttribute('data-fct-lazy-picker') !== 'true') return;
    var fragment = document.createDocumentFragment();
    Object.keys(groups).forEach(function (label) {
      var section = document.createElement('section');
      section.className = 'fct-emoji-section';
      var title = document.createElement('h3');
      title.className = 'fct-emoji-section-title';
      title.textContent = label;
      var grid = document.createElement('div');
      grid.className = 'fct-emoji-grid';
      Object.keys(groups[label]).forEach(function (slug) {
        var emoji = emojiMap[slug];
        if (!emoji) return;
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'fct-emoji';
        button.setAttribute('data-fct-emoji', slug);
        button.setAttribute('data-fct-src', emoji.src);
        button.setAttribute('aria-label', emoji.label);
        button.title = emoji.label;
        var image = document.createElement('img');
        image.setAttribute('data-fct-lazy-src', emoji.src);
        image.alt = '';
        image.width = 24;
        image.height = 24;
        image.loading = 'lazy';
        image.decoding = 'async';
        button.appendChild(image);
        grid.appendChild(button);
      });
      section.appendChild(title);
      section.appendChild(grid);
      fragment.appendChild(section);
    });
    panel.appendChild(fragment);
    panel.removeAttribute('data-fct-lazy-picker');
  }

  function initCommentEditor(form) {
    var editor = form.querySelector('.fct-emoji-editor');
    var source = form.querySelector('textarea[data-fct-source="true"]');
    var toggles = form.querySelectorAll('.fct-emoji-toggle');
    if (!editor || !source || !toggles.length) return;
    if (form.getAttribute('data-fct-editor-ready') === 'true') return;
    form.setAttribute('data-fct-editor-ready', 'true');
    var sourceWasFocused = document.activeElement === source;
    var sourceSelection = { start: source.selectionStart || 0, end: source.selectionEnd || 0 };
    var emojiMap = Object.create(null);
    var config = window.fctAvatarConfig || {};
    var groups = config.emojiGroups || {};
    Object.keys(groups).forEach(function (group) {
      Object.keys(groups[group]).forEach(function (slug) {
        var emoji = groups[group][slug];
        if (/^[a-z0-9-]+$/.test(slug) && Array.isArray(emoji) && /^[a-z0-9-]+\.svg$/.test(emoji[1])) {
          emojiMap[slug] = { src: config.emojiBaseUrl + emoji[1], label: emoji[0] };
        }
      });
    });
    // Also accept cached server-rendered pickers during an asset upgrade.
    form.querySelectorAll('.fct-emoji').forEach(function (button) {
      var slug = button.getAttribute('data-fct-emoji');
      if (slug) emojiMap[slug] = { src: button.getAttribute('data-fct-src'), label: button.getAttribute('aria-label') };
    });
    var state = { range: null, composing: false, inCommand: false };
    restoreSavedValue(editor, source, emojiMap);
    editor.hidden = false;
    editor.setAttribute('contenteditable', 'true');
    editor.setAttribute('tabindex', '0');
    editor.removeAttribute('inert');
    form.querySelectorAll('.fct-comment-tools').forEach(function (toolbar) { toolbar.hidden = false; });
    form.classList.add('fct-image-emoji-active');
    var label = form.querySelector('.comment-form-comment > label');
    if (label && editor.id) {
      if (!label.id) label.id = editor.id + '-label';
      label.removeAttribute('for');
      editor.setAttribute('aria-labelledby', label.id);
      label.addEventListener('click', function () { editor.focus(); });
    }
    source.removeAttribute('required');
    source.removeAttribute('aria-required');
    source.setAttribute('aria-hidden', 'true');
    source.setAttribute('tabindex', '-1');
    editor.setAttribute('aria-invalid', 'false');
    syncSource(editor, source);

    function remember() { saveSelection(editor, state); }
    function setComposing(value) {
      state.composing = value;
      form.querySelectorAll('.fct-emoji-toggle,.fct-emoji').forEach(function (button) { button.disabled = value; });
    }
    document.addEventListener('selectionchange', remember);
    ['mouseup', 'pointerup', 'focus'].forEach(function (name) { editor.addEventListener(name, remember); });
    editor.addEventListener('touchend', function () { remember(); window.requestAnimationFrame(remember); }, { passive: true });
    editor.addEventListener('input', function () { remember(); syncSource(editor, source); });
    editor.addEventListener('keyup', function () { remember(); if (!state.composing) syncSource(editor, source); });
    editor.addEventListener('compositionstart', function () { setComposing(true); });
    editor.addEventListener('compositionend', function () {
      setComposing(false);
      remember();
      syncSource(editor, source);
      window.setTimeout(function () { remember(); syncSource(editor, source); }, 0);
    });
    editor.addEventListener('blur', function () {
      remember();
      window.setTimeout(function () { setComposing(false); syncSource(editor, source); }, 0);
    });
    editor.addEventListener('keydown', function (event) {
      if (event.isComposing || state.composing || event.keyCode === 229 || event.ctrlKey || event.altKey || event.metaKey) return;
      if (!event.shiftKey && (event.key === 'ArrowLeft' || event.key === 'ArrowRight') && moveAcrossEmoji(editor, state, event.key === 'ArrowLeft')) { event.preventDefault(); return; }
      if ((event.key === 'Backspace' || event.key === 'Delete') && deleteAdjacentEmoji(editor, source, state, event.key === 'Backspace')) event.preventDefault();
    });
    editor.addEventListener('beforeinput', function (event) {
      if (!event.cancelable || event.isComposing || state.composing || state.inCommand) return;
      if ((event.inputType === 'deleteContentBackward' || event.inputType === 'deleteContentForward') && deleteAdjacentEmoji(editor, source, state, event.inputType === 'deleteContentBackward')) event.preventDefault();
    });
    editor.addEventListener('paste', function (event) {
      if (!event.clipboardData || state.composing) return;
      event.preventDefault();
      var text = event.clipboardData.getData('text/plain');
      if (text) insertFragment(editor, source, state, valueFragment(text, emojiMap));
    });
    editor.addEventListener('drop', function (event) {
      event.preventDefault();
      if (state.composing) return;
      var text = event.dataTransfer ? event.dataTransfer.getData('text/plain') : '';
      if (text) insertFragment(editor, source, state, valueFragment(text, emojiMap));
    });
    ['copy', 'cut'].forEach(function (type) {
      editor.addEventListener(type, function (event) {
        if (!event.clipboardData || state.composing) return;
        var range = currentRange(editor);
        if (!range || range.collapsed) return;
        var value = serializeNode(range.cloneContents());
        event.preventDefault();
        event.clipboardData.setData('text/plain', value);
        var wrapper = document.createElement('div');
        wrapper.appendChild(valueFragment(value, emojiMap));
        event.clipboardData.setData('text/html', wrapper.innerHTML);
        if (type === 'cut') deleteSelection(editor, source, state, range);
      });
    });
    form.addEventListener('submit', function (event) {
      syncSource(editor, source);
      if (state.composing || !source.value.trim()) {
        event.preventDefault();
        if (!state.composing) editor.setAttribute('aria-invalid', 'true');
        editor.focus();
      }
    });
    form.addEventListener('reset', function () {
      window.setTimeout(function () { state.range = null; restoreSavedValue(editor, source, emojiMap); syncSource(editor, source); editor.setAttribute('aria-invalid', 'false'); }, 0);
    });
    window.addEventListener('pageshow', function () {
      if (source.value !== serializeNode(editor)) { state.range = null; restoreSavedValue(editor, source, emojiMap); syncSource(editor, source); }
    });
    form.querySelectorAll('.fct-comment-tools').forEach(function (toolbar) {
      toolbar.addEventListener('pointerdown', remember);
      toolbar.addEventListener('touchstart', remember, { passive: true });
    });
    toggles.forEach(function (toggle) {
      toggle.addEventListener('mousedown', function (event) { remember(); event.preventDefault(); });
      toggle.addEventListener('click', function () {
        if (state.composing) return;
        var panel = document.getElementById(toggle.getAttribute('aria-controls'));
        var open = toggle.getAttribute('aria-expanded') === 'true';
        form.querySelectorAll('.fct-emoji-toggle').forEach(closePicker);
        if (!open && panel) { fillEmojiPicker(panel, groups, emojiMap); panel.hidden = false; toggle.setAttribute('aria-expanded', 'true'); positionPicker(toggle); loadVisibleEmoji(panel); }
      });
    });
    // Delegation covers both legacy markup and the lazily built controls.
    form.addEventListener('mousedown', function (event) {
      if (event.target.closest('.fct-emoji')) event.preventDefault();
    });
    form.addEventListener('click', function (event) {
      var button = event.target.closest('.fct-emoji');
      if (!button || !form.contains(button)) return;
      event.preventDefault();
      if (state.composing) return;
      form.querySelectorAll('.fct-emoji-toggle[aria-expanded="true"]').forEach(closePicker);
      insertEmoji(editor, source, state, button.getAttribute('data-fct-emoji'), emojiMap);
    });
    if (sourceWasFocused) {
      var start = pointAtValueOffset(editor, sourceSelection.start);
      var end = pointAtValueOffset(editor, sourceSelection.end);
      var initialRange = document.createRange();
      initialRange.setStart(start.node, start.offset);
      initialRange.setEnd(end.node, end.offset);
      focusRange(editor, initialRange);
      remember();
    }
  }

  var frame = null;
  function repositionPickers() {
    if (frame !== null) return;
    frame = window.requestAnimationFrame(function () {
      document.querySelectorAll('.fct-emoji-toggle[aria-expanded="true"]').forEach(positionPicker);
      frame = null;
    });
  }

  var booted = false;
  function initialise() {
    if (booted) return;
    booted = true;
    document.querySelectorAll('.comment-form').forEach(function (form) {
      form.classList.add('fct-comment-form');
      var source = form.querySelector('textarea[data-fct-source="true"]');
      var wasRequired = source && source.required;
      try { initCommentEditor(form); } catch (error) {
        // A failed enhancement must never leave an invisible or read-only composer.
        form.classList.remove('fct-image-emoji-active');
        form.removeAttribute('data-fct-editor-ready');
        var editor = form.querySelector('.fct-emoji-editor');
        if (editor) editor.hidden = true;
        form.querySelectorAll('.fct-comment-tools').forEach(function (tools) { tools.hidden = true; });
        if (source) { source.hidden = false; source.disabled = false; source.readOnly = false; source.required = wasRequired; source.removeAttribute('aria-hidden'); source.removeAttribute('tabindex'); }
        var label = form.querySelector('.comment-form-comment > label');
        if (label && source && source.id) label.setAttribute('for', source.id);
      }
      // Reuse WordPress' submit control and hidden fields, including reply IDs.
      var toolbar = form.querySelector('.fct-comment-tools');
      var submit = form.querySelector('.form-submit');
      if (form.classList.contains('fct-image-emoji-active') && toolbar && submit) toolbar.appendChild(submit);
      // Text editing and submission are ready immediately; avatar work is deferred.
      deferAvatarProfile(form);
      try { initCommentIdentity(form); } catch (error) { form.classList.remove('fct-identity-compact'); form.querySelectorAll('.comment-form-author,.comment-form-email').forEach(function (row) { row.hidden = false; }); }
    });
    document.addEventListener('click', function (event) {
      if (event.target.closest('.fct-comment-tools')) return;
      document.querySelectorAll('.fct-emoji-toggle[aria-expanded="true"]').forEach(closePicker);
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') document.querySelectorAll('.fct-emoji-toggle[aria-expanded="true"]').forEach(closePicker);
    });
    window.addEventListener('resize', repositionPickers, { passive: true });
    document.addEventListener('scroll', repositionPickers, { passive: true, capture: true });
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', repositionPickers, { passive: true });
      window.visualViewport.addEventListener('scroll', repositionPickers, { passive: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialise, { once: true });
  else initialise();
}());

(function () {
  'use strict';
  function initialiseInlineComments() {
    if (!window.fetch || !window.FormData || !window.DOMParser) return;
    document.querySelectorAll('form.comment-form').forEach(function (form) {
      if (form.dataset.fctInlineSubmit) return;
      var action = new URL(form.action, window.location.href);
      if (action.origin !== window.location.origin || !/\/wp-comments-post\.php$/.test(action.pathname)) return;
      form.dataset.fctInlineSubmit = '1';
      var source = form.querySelector('textarea[name="comment"]');
      var buttons = Array.from(form.querySelectorAll('input[type="submit"],button[type="submit"]'));
      var labels = buttons.map(function (button) { return button.tagName === 'INPUT' ? button.value : button.textContent; });
      var originalDisabled = buttons.map(function (button) { return button.disabled; });
      var feedback = document.createElement('p');
      feedback.className = 'fct-submit-status';
      feedback.setAttribute('role', 'status');
      feedback.setAttribute('aria-live', 'polite');
      feedback.hidden = true;
      form.appendChild(feedback);
      var busy = false, cooldownUntil = 0, cooldownTimer = 0;
      function setButtons(label, disabled) {
        buttons.forEach(function (button, index) {
          button.disabled = disabled || originalDisabled[index];
          if (button.tagName === 'INPUT') button.value = label || labels[index];
          else button.textContent = label || labels[index];
        });
      }
      function message(text, error) {
        feedback.textContent = text;
        feedback.hidden = !text;
        feedback.classList.toggle('is-error', Boolean(error));
        feedback.setAttribute('role', error ? 'alert' : 'status');
      }
      function cooldown() {
        window.clearInterval(cooldownTimer);
        cooldownUntil = Date.now() + 15000;
        function tick() {
          var seconds = Math.max(0, Math.ceil((cooldownUntil - Date.now()) / 1000));
          setButtons(seconds ? '稍候 ' + seconds + ' 秒' : '', Boolean(seconds));
          if (!seconds) {
            window.clearInterval(cooldownTimer);
            cooldownTimer = 0;
            message('现在可以重新提交，刚才写的内容仍保留在评论框中。', false);
          }
        }
        tick();
        cooldownTimer = window.setInterval(tick, 1000);
      }
      // The image-emoji editor's existing submit listener synchronises its
      // textarea before this listener runs, including the reply parent field.
      form.addEventListener('submit', function (event) {
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (busy || Date.now() < cooldownUntil) return;
        if (!source || !source.value.trim()) return;
        busy = true;
        form.setAttribute('aria-busy', 'true');
        setButtons('正在提交…', true);
        message('', false);
        var payload = new FormData(form);
        payload.set('fct_inline_submit', '1');
        if (!payload.get('redirect_to')) {
          var returnUrl = new URL(window.location.href);
          returnUrl.hash = '';
          returnUrl.searchParams.delete('fct_submitted_comment');
          payload.set('redirect_to', returnUrl.href);
        }
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = controller ? window.setTimeout(function () { controller.abort(); }, 30000) : 0;
        fetch(action.href, {method: 'POST', body: payload, credentials: 'same-origin', redirect: 'follow', signal: controller ? controller.signal : undefined, headers: {'Accept': 'text/html'}}).then(function (response) {
          return response.text().then(function (html) {
            var result = new DOMParser().parseFromString(html, 'text/html');
            var errorElement = result.querySelector('.wp-die-message');
            var destination = new URL(response.url || action.href, window.location.href);
            var commentId = destination.searchParams.get('fct_submitted_comment');
            if (response.ok && !errorElement && destination.origin === window.location.origin && /^[1-9]\d*$/.test(commentId || '')) {
              var focusElement = result.querySelector('#comments[data-fct-focus-url]');
              var focusUrl = focusElement && focusElement.getAttribute('data-fct-focus-url');
              if (focusUrl) {
                var focusedDestination = new URL(focusUrl, window.location.href);
                if (focusedDestination.origin === window.location.origin) destination = focusedDestination;
              }
              destination.searchParams.delete('fct_submitted_comment');
              destination.hash = 'comment-' + commentId;
              source.value = '';
              var editor = form.querySelector('.fct-emoji-editor');
              if (editor) {
                editor.textContent = '';
                editor.classList.add('is-empty');
                editor.setAttribute('aria-invalid', 'false');
              }
              message('', false);
              setButtons('', false);
              try {
                if (destination.pathname === window.location.pathname && destination.search === window.location.search) {
                  window.history.replaceState(window.history.state, '', destination.href);
                  window.location.reload();
                } else window.location.assign(destination.href);
              } catch (error) {
                message('请刷新页面查看留言。', false);
              }
              return;
            }
            var errorText = errorElement ? errorElement.textContent.trim().replace(/\s+/g, ' ') : '';
            var flood = response.status === 429 || /速度太快|posting comments too quickly/i.test(errorText);
            if (flood) {
              message('刚刚提交过一条留言，请稍候再发。你写的内容已保留。', true);
              cooldown();
            } else {
              message((errorText || '暂时未能确认提交结果，请先查看留言后再决定是否重试。') + ' 你写的内容已保留。', true);
            }
          });
        }).catch(function () {
          message('网络暂未返回提交结果，请先查看留言后再决定是否重试。你写的内容已保留。', true);
        }).finally(function () {
          window.clearTimeout(timer);
          busy = false;
          form.removeAttribute('aria-busy');
          if (!cooldownTimer) setButtons('', false);
        });
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialiseInlineComments, {once: true});
  else initialiseInlineComments();
}());

;(function () {
  'use strict';
  function initialiseReplyFolds() {
    var board = document.querySelector('.fn-market-board');
    if (!board || board.dataset.fctFoldReady) return;
    board.dataset.fctFoldReady = '1';
    var url = new URL(window.location.href), changed = false;
    Array.from(url.searchParams.keys()).forEach(function (key) {
      if (key === 'reply_page' || key.indexOf('reply_page[') === 0) {
        url.searchParams.delete(key); changed = true;
      }
    });
    if (changed) window.history.replaceState(window.history.state, '', url.href);
    function revealReplyTarget() {
      var id = window.location.hash.slice(1);
      if (!/^comment-\d+$/.test(id)) return;
      var target = document.getElementById(id);
      if (!target || !board.contains(target)) return;
      var fold = target.closest('.fn-comment-more');
      if (fold && !fold.open) {
        fold.open = true;
        window.requestAnimationFrame(function () { target.scrollIntoView({block: 'center'}); });
      }
    }
    revealReplyTarget();
    window.addEventListener('hashchange', revealReplyTarget);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialiseReplyFolds, {once: true});
  else initialiseReplyFolds();
}());
