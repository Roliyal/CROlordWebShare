(function () {
    'use strict';
    function fallback(event) {
        var image = event.target;
        if (image.matches && image.matches('.reading-author-portrait img')) image.remove();
    }
    document.addEventListener('error', fallback, true);
    document.querySelectorAll('.reading-author-portrait img').forEach(function (image) {
        if (image.complete && image.naturalWidth === 0) image.remove();
    });

    var menu = document.querySelector('[data-reading-contents]');
    var content = document.querySelector('[data-reading-document]');
    if (!menu || !content) return;
    var headings = Array.from(content.querySelectorAll('h2,h3')).filter(function (heading) {
        return heading.textContent.trim() && !heading.closest('blockquote,pre,figure,details,[hidden]');
    });
    if (headings.length < 2) return;
    var summary = menu.querySelector('summary');
    var label = menu.querySelector('[data-reading-current]');
    var list = menu.querySelector('ol');
    if (!summary || !label || !list) return;
    var used = new Set(Array.from(document.querySelectorAll('[id]'), function (node) { return node.id; }));
    var links = [];
    var fragment = document.createDocumentFragment();
    headings.forEach(function (heading, index) {
        var id = heading.id;
        if (!id || document.getElementById(id) !== heading) {
            var suffix = index + 1;
            do { id = 'reading-section-' + suffix++; } while (used.has(id));
            heading.id = id;
            used.add(id);
        }
        heading.classList.add('reading-anchor');
        var item = document.createElement('li');
        if (heading.tagName === 'H3') item.className = 'reading-contents-subitem';
        var link = document.createElement('a');
        link.href = '#' + encodeURIComponent(id);
        link.textContent = heading.textContent.trim();
        item.appendChild(link);
        fragment.appendChild(item);
        links.push(link);
    });
    list.appendChild(fragment);
    menu.hidden = false;
    var positions = [];
    var offset = 64;
    var active = -1;
    var scrollFrame = 0;
    var measureFrame = 0;
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    var wide = window.matchMedia('(min-width: 1200px)');
    menu.open = wide.matches;
    function select(index) {
        if (index === active) return;
        if (active >= 0) links[active].removeAttribute('aria-current');
        active = index;
        links[index].setAttribute('aria-current', 'location');
        label.textContent = links[index].textContent;
    }
    function update() {
        scrollFrame = 0;
        if (!positions.length) return;
        var y = window.scrollY + Math.max(offset + 2, window.innerHeight * .25);
        var low = 0;
        var high = positions.length - 1;
        while (low < high) {
            var mid = Math.ceil((low + high) / 2);
            if (positions[mid] <= y) low = mid;
            else high = mid - 1;
        }
        select(low);
    }
    function measure() {
        measureFrame = 0;
        var y = window.scrollY;
        positions = headings.map(function (heading) { return heading.getBoundingClientRect().top + y; });
        offset = parseFloat(window.getComputedStyle(headings[0]).scrollMarginTop) || 64;
        update();
    }
    function requestMeasure() {
        if (!measureFrame) measureFrame = window.requestAnimationFrame(measure);
    }
    function close() { menu.open = false; }
    menu.addEventListener('click', function (event) {
        var link = event.target.closest('a');
        var index = links.indexOf(link);
        if (index < 0 || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (!wide.matches) close();
        var heading = headings[index];
        heading.setAttribute('tabindex', '-1');
        heading.focus({ preventScroll: true });
        if (window.location.hash !== link.hash) window.history.pushState(null, '', link.hash);
        heading.scrollIntoView({ behavior: reduced.matches ? 'auto' : 'smooth', block: 'start' });
        select(index);
    });
    menu.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && menu.open) {
            event.preventDefault();
            close();
            summary.focus({ preventScroll: true });
        }
    });
    menu.addEventListener('focusout', function (event) {
        if (!wide.matches && menu.open && event.relatedTarget && !menu.contains(event.relatedTarget)) close();
    });
    document.addEventListener('click', function (event) {
        if (!wide.matches && menu.open && !menu.contains(event.target)) close();
    });
    window.addEventListener('scroll', function () {
        if (!wide.matches && menu.open) close();
        if (!scrollFrame) scrollFrame = window.requestAnimationFrame(update);
    }, { passive: true });
    window.addEventListener('resize', requestMeasure, { passive: true });
    function changeLayout() { menu.open = wide.matches; requestMeasure(); }
    if (wide.addEventListener) wide.addEventListener('change', changeLayout);
    else if (wide.addListener) wide.addListener(changeLayout);
    window.addEventListener('pageshow', requestMeasure);
    if ('ResizeObserver' in window) new ResizeObserver(requestMeasure).observe(content);
    else content.querySelectorAll('img').forEach(function (image) {
        if (!image.complete) image.addEventListener('load', requestMeasure, { once: true });
    });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(requestMeasure);
    function restoreFragment() {
        var id;
        try { id = decodeURIComponent(window.location.hash.slice(1)); } catch (error) { return; }
        var index = headings.findIndex(function (heading) { return heading.id === id; });
        if (index < 0) return;
        if (!wide.matches) close();
        headings[index].scrollIntoView({ behavior: 'auto', block: 'start' });
        select(index);
    }
    window.addEventListener('hashchange', restoreFragment);
    measure();
    if (window.location.hash) window.requestAnimationFrame(restoreFragment);
}());
