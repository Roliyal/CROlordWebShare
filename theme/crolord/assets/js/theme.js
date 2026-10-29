(function () {
  'use strict';
  document.documentElement.classList.add('js-ready');
  document.documentElement.classList.add('fn-focus-ready');

  // Typing into a clicked field should not turn on a heavy keyboard outline.
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Tab' && !event.altKey && !event.ctrlKey && !event.metaKey) document.documentElement.classList.add('fn-keyboard-focus');
  }, true);
  function usePointerFocus() { document.documentElement.classList.remove('fn-keyboard-focus'); }
  document.addEventListener('pointerdown', usePointerFocus, true);
  document.addEventListener('mousedown', usePointerFocus, true);
  document.addEventListener('touchstart', usePointerFocus, { capture: true, passive: true });

  var header = document.querySelector('.site-header');
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var headerFrame=0,lastScrolled=null;
  function updateHeader(){if(headerFrame||!header)return;headerFrame=requestAnimationFrame(function(){headerFrame=0;var next=window.scrollY>(document.body.classList.contains('single-post')?12:8);if(next!==lastScrolled){header.classList.toggle('is-scrolled',next);lastScrolled=next;}});}
  updateHeader();
  window.addEventListener('scroll', updateHeader, { passive: true });

  // Native details keeps the single search usable even when JavaScript is off.
  if (header) {
    var navigation = header.querySelector('.header-navigation');
    var menuToggle = header.querySelector('.menu-toggle');
    var siteSearch = header.querySelector('.site-search');
    var compactHeader = window.matchMedia ? window.matchMedia('(max-width: 1080px)') : null;
    function setMenu(open, restoreFocus) {
      if (!navigation || !menuToggle) return;
      var expanded = !!(open && compactHeader && compactHeader.matches);
      navigation.hidden = !!(compactHeader && compactHeader.matches && !expanded);
      menuToggle.setAttribute('aria-expanded', String(expanded));
      header.classList.toggle('menu-is-open', expanded);
      if (restoreFocus) menuToggle.focus();
    }
    function closeSearch(restoreFocus) {
      if (!siteSearch || !siteSearch.open) return;
      siteSearch.open = false;
      if (restoreFocus) siteSearch.querySelector('summary').focus();
    }
    if (navigation && menuToggle && compactHeader) {
      menuToggle.hidden = false;
      setMenu(false);
      menuToggle.addEventListener('click', function () {
        var open = menuToggle.getAttribute('aria-expanded') !== 'true';
        closeSearch(false);
        setMenu(open);
        if (open) {
          var firstLink = navigation.querySelector('a');
          if (firstLink) firstLink.focus();
        }
      });
      navigation.addEventListener('click', function (event) {
        if (event.target.closest('a')) setMenu(false);
      });
      function resetMenu() { setMenu(false); }
      if (compactHeader.addEventListener) compactHeader.addEventListener('change', resetMenu);
      else if (compactHeader.addListener) compactHeader.addListener(resetMenu);
    }
    if (siteSearch) {
      var searchClose = siteSearch.querySelector('.search-close');
      if (searchClose) {
        searchClose.hidden = false;
        searchClose.addEventListener('click', function () { closeSearch(true); });
      }
      siteSearch.addEventListener('toggle', function () {
        if (!siteSearch.open) return;
        setMenu(false);
        window.requestAnimationFrame(function () {
          if (siteSearch.open) siteSearch.querySelector('.search-field').focus({ preventScroll: true });
        });
      });
      siteSearch.addEventListener('focusout', function (event) {
        if (event.relatedTarget && !siteSearch.contains(event.relatedTarget)) closeSearch(false);
      });
    }
    document.addEventListener('click', function (event) {
      if (siteSearch && !siteSearch.contains(event.target)) closeSearch(false);
      if (!header.contains(event.target)) setMenu(false);
    });
    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      if (siteSearch && siteSearch.open) {
        event.preventDefault();
        closeSearch(true);
      } else if (menuToggle && menuToggle.getAttribute('aria-expanded') === 'true') {
        event.preventDefault();
        setMenu(false, true);
      }
    });
  }

  // Native details works before JavaScript; desktop pages keep their logo-only header.
  var phoneMenu = document.querySelector('.crolord-mobile-nav');
  if (phoneMenu) {
    var phoneViewport = window.matchMedia('(max-width: 680px)');
    phoneMenu.addEventListener('click', function (event) {
      if (event.target.closest('a')) phoneMenu.open = false;
    });
    document.addEventListener('click', function (event) {
      if (phoneMenu.open && !phoneMenu.contains(event.target)) phoneMenu.open = false;
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && phoneMenu.open) {
        event.preventDefault();
        phoneMenu.open = false;
        phoneMenu.querySelector('summary').focus({ preventScroll: true });
      }
    });
    function resetPhoneMenu() { phoneMenu.open = false; }
    if (phoneViewport.addEventListener) phoneViewport.addEventListener('change', resetPhoneMenu);
    else if (phoneViewport.addListener) phoneViewport.addListener(resetPhoneMenu);
  }

  // Include announcements, wrapped branding and the admin bar in the first view.
  var home = document.querySelector('.page-home');
  var homeSidebar = home && home.querySelector('.sidebar');
  if (homeSidebar) {
    var phoneSidebar = window.matchMedia('(max-width: 680px)');
    function syncPhoneSidebar() {
      var empty = phoneSidebar.matches && Array.prototype.every.call(homeSidebar.children, function (widget) {
        if (getComputedStyle(widget).display === 'none') return true;
        // A hidden search form can leave an otherwise empty widget wrapper behind.
        return widget.children.length > 0 && Array.prototype.every.call(widget.children, function (child) { return getComputedStyle(child).display === 'none'; }) && !Array.prototype.some.call(widget.childNodes, function (node) { return node.nodeType === 3 && node.textContent.trim(); });
      });
      homeSidebar.classList.toggle('crolord-phone-empty', empty);
    }
    syncPhoneSidebar();
    if (phoneSidebar.addEventListener) phoneSidebar.addEventListener('change', syncPhoneSidebar);
    else if (phoneSidebar.addListener) phoneSidebar.addListener(syncPhoneSidebar);
  }
  var hero = home && home.querySelector('.home-hero');
  if (hero) {
    var homeLayoutFrame;
    function measureHome() {
      if (homeLayoutFrame) window.cancelAnimationFrame(homeLayoutFrame);
      homeLayoutFrame = window.requestAnimationFrame(function () {
        var top = Math.max(0, hero.getBoundingClientRect().top + window.scrollY);
        home.style.setProperty('--home-top', top + 'px');
      });
    }
    measureHome();
    window.addEventListener('resize', measureHome, { passive: true });
    window.addEventListener('load', measureHome, { once: true });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(measureHome);
    if ('ResizeObserver' in window) {
      var homeLayoutObserver = new ResizeObserver(measureHome);
      [header, document.querySelector('.notice-bar'), document.getElementById('wpadminbar')].forEach(function (node) {
        if (node) homeLayoutObserver.observe(node);
      });
    }
  }

  document.addEventListener('click', function (event) {
    var link = event.target.closest('a[href^="#"]');
    if (!link) return;
    var id = link.getAttribute('href').slice(1);
    if (!id) return;
    var target = document.getElementById(decodeURIComponent(id));
    if (!target) return;
    event.preventDefault();
    target.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
  });

  // New articles appear immediately, without hiding content during a reveal pass.
  var button = document.getElementById('fieldnotes-load-more');
  var list = document.getElementById('fieldnotes-post-list');
  if (button && list && window.fieldnotesConfig) {
    button.addEventListener('click', function () {
      var nextPage = Number(list.dataset.page || 1) + 1;
      var maxPages = Number(list.dataset.maxPages || 1);
      if (nextPage > maxPages || button.disabled) return;
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      button.textContent = window.fieldnotesConfig.loadingLabel;
      var data = new FormData();
      data.append('action', 'fieldnotes_load_more');
      data.append('page', String(nextPage));
      data.append('nonce', window.fieldnotesConfig.nonce);
      fetch(window.fieldnotesConfig.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
        .then(function (response) { return response.json(); })
        .then(function (result) {
          if (!result || !result.success) throw new Error('load failed');
          var parsed = new DOMParser().parseFromString(result.data.html, 'text/html');
          Array.prototype.forEach.call(parsed.querySelectorAll('.post-card'), function (card) {
            list.appendChild(card);
          });
          list.dataset.page = String(result.data.page);
          if (!result.data.hasMore) button.remove();
          else {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.textContent = window.fieldnotesConfig.moreLabel;
          }
        })
        .catch(function () {
          button.disabled = false;
          button.removeAttribute('aria-busy');
          button.textContent = window.fieldnotesConfig.moreLabel;
        });
    });
  }
}());
(function(){
  'use strict';
  var preference=window.matchMedia?window.matchMedia('(prefers-reduced-motion: reduce)'):null,reduce=function(){return preference&&preference.matches;};
  document.querySelectorAll('.fn-accordion').forEach(function(details){var summary=details.querySelector('summary'),body=details.querySelector('.fn-accordion-content'),animation=null,closing=false;if(!summary||!body||typeof body.animate!=='function')return;
    summary.addEventListener('click',function(event){if(reduce())return;event.preventDefault();var opening=!details.open||closing,from=details.open?body.getBoundingClientRect().height:0;if(animation)animation.cancel();closing=!opening;details.open=true;body.style.overflow='hidden';var to=opening?body.scrollHeight:0;
      animation=body.animate([{height:from+'px',opacity:opening?.8:1},{height:to+'px',opacity:opening?1:.8}],{duration:180,easing:'cubic-bezier(.22,.61,.36,1)'});
      animation.onfinish=function(){details.open=opening;body.style.overflow='';animation=null;closing=false;};animation.oncancel=function(){body.style.overflow='';};
    });
  });
}());

/* Copy visible text cleanly; layout space is never clipboard content. */
(function () {
  'use strict';
  document.addEventListener('copy', function (event) {
    if (event.defaultPrevented || !event.clipboardData) return;
    var focused = document.activeElement;
    if (focused && (focused.matches('input, textarea') || focused.isContentEditable)) return;
    var selection = window.getSelection();
    if (!selection || selection.isCollapsed || !selection.rangeCount) return;
    var ancestor = selection.getRangeAt(0).commonAncestorContainer;
    var element = ancestor.nodeType === 1 ? ancestor : ancestor.parentElement;
    if (element && element.closest('pre, code, [contenteditable="true"]')) return;
    var text = selection.toString()
      .replace(/[\u200B\uFEFF]/g, '')
      .replace(/\u00A0/g, ' ')
      .replace(/[ \t]+$/gm, '')
      .replace(/\n(?:[ \t]*\n){2,}/g, '\n\n')
      .trim();
    if (!text) return;
    event.clipboardData.setData('text/plain', text);
    event.preventDefault();
  });
}());

/* Prepare a small set of real section documents before the next native click. */
(function () {
  'use strict';
  var config = window.fieldnotesNavigation;
  if (!config || !Array.isArray(config.urls)) return;
  var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
  if (connection && (connection.saveData || /^(slow-)?2g$/.test(connection.effectiveType || ''))) return;
  var urls = [], current = new URL(location.href), ready = false, expired = false, rule = null, expiryTimer = 0, generation = 0, resumeAssets = null;
  config.urls.slice(0, 6).forEach(function (value) {
    try {
      var url = new URL(value, location.href);
      if (url.origin !== current.origin || url.username || url.password || url.hash || url.pathname === current.pathname || urls.indexOf(url.href) !== -1) return;
      urls.push(url.href);
    } catch (error) { /* A malformed hint must never affect a real link. */ }
  });
  function supportsRules() {
    return typeof HTMLScriptElement.supports === 'function' && HTMLScriptElement.supports('speculationrules');
  }
  function supportsPrefetch() {
    try { var link = document.createElement('link'); return !!(link.relList && link.relList.supports && link.relList.supports('prefetch')); }
    catch (error) { return false; }
  }
  function cancel() {
    expired = true;
    generation++; resumeAssets = null;
    window.clearTimeout(expiryTimer); expiryTimer = 0;
    if (rule) rule.remove();
    document.querySelectorAll('link[data-crolord-section-prefetch]').forEach(function (link) { link.remove(); });
  }
  function warmStyle() {
    var queue = [], active = 0, run = generation;
    (config.styles || [config.coreStyle]).slice(0, 10).forEach(function (value) {
      try {
        var url = new URL(value, location.href);
        if (url.origin !== current.origin || url.username || url.password || url.hash || !/\/assets\/.*\.min\.css$/.test(url.pathname)) return;
        var present = Array.prototype.some.call(document.querySelectorAll('link[href]'), function (link) {
          return link.href === url.href && /^(stylesheet|preload)$/.test(link.rel);
        });
        if (!present && queue.indexOf(url.href) === -1) queue.push(url.href);
      } catch (error) { /* Optional, public static resource only. */ }
    });
    function pump() {
      if (run !== generation || expired || document.visibilityState === 'hidden') return;
      while (active < 2 && queue.length) {
        var url = queue.shift();
        if (typeof fetch !== 'function') return;
        active++;
        fetch(url, { credentials: 'same-origin', cache: 'force-cache', priority: 'low' })
          .then(function (response) { return response.ok && response.arrayBuffer ? response.arrayBuffer() : null; })
          .then(done, done);
      }
    }
    function done() { active--; pump(); }
    resumeAssets = pump;
    pump();
  }
  function prepare() {
    if (ready || expired || document.visibilityState === 'hidden') return;
    ready = true;
    warmStyle();
    if (urls.length && supportsRules()) {
      rule = document.createElement('script'); rule.type = 'speculationrules';
      rule.setAttribute('data-crolord-section-prefetch', '');
      rule.textContent = JSON.stringify({ prefetch: [{ source: 'list', urls: urls, eagerness: 'immediate' }] });
      document.head.appendChild(rule);
    } else if (supportsPrefetch()) {
      urls.forEach(function (url) {
        var link = document.createElement('link'); link.rel = 'prefetch'; link.href = url;
        link.setAttribute('data-crolord-section-prefetch', ''); document.head.appendChild(link);
      });
    }
    // Keep the head start short; no durable HTML cache, prerender or session copy.
    expiryTimer = window.setTimeout(cancel, 30000);
  }
  var scheduled = false;
  function schedule() {
    if (scheduled || expired) return;
    scheduled = true;
    if (typeof window.requestIdleCallback === 'function') window.requestIdleCallback(prepare, { timeout: 1500 });
    else window.setTimeout(prepare, 200);
  }
  if (document.readyState === 'complete') schedule();
  else window.addEventListener('load', schedule, { once: true });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && resumeAssets) resumeAssets();
    if (document.visibilityState === 'visible' && !ready && !expired) { scheduled = false; schedule(); }
  });
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) return;
    cancel();
    ready = false; expired = false; scheduled = false; rule = null;
    schedule();
  });
  document.addEventListener('submit', cancel, true);
  function intent(event) {
    var link = event.target.closest && event.target.closest('a[href]');
    if (link && urls.indexOf(link.href) !== -1 && (!connection || connection.effectiveType !== '3g')) prepare();
  }
  document.addEventListener('pointerover', intent, { passive: true });
  document.addEventListener('touchstart', intent, { passive: true });
  document.addEventListener('focusin', intent);
}());
