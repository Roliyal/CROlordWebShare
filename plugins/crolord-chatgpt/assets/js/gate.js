(function () {
  'use strict';
  var roots = document.querySelectorAll('.fnchat');
  var config = window.fieldnotesChatGPT;
  if (!roots.length || !config) return;
  var root = document.querySelector('.chatgpt-page-content .fnchat') || document.querySelector('#main-content .fnchat') || roots[0];
  // Covers direct template/widget output as well as filtered page content.
  Array.prototype.forEach.call(roots, function (node) { if (node !== root) node.remove(); });
  if (root.dataset.fnchatInitialized === '1') return;
  root.dataset.fnchatInitialized = '1';
  var strings = config.strings || {};
  window.addEventListener('pageshow', function (event) { if (event.persisted) window.location.reload(); });

  function sendRequest(action, fields) {
    var body = fields instanceof FormData ? fields : new URLSearchParams();
    body.set('action', action);
    body.set('nonce', config.nonce);
    Object.keys(fields || {}).forEach(function (key) { if (!(fields instanceof FormData)) body.set(key, fields[key]); });
    return fetch(config.ajaxUrl, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: fields instanceof FormData ? {} : { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: fields instanceof FormData ? body : body.toString()
    }).then(function (response) {
      return response.json().catch(function () { throw new Error(strings.networkError); });
    }).then(function (result) {
      if (!result || !result.success) {
        var error = new Error((result && result.data && result.data.message) || strings.networkError);
        error.code = result && result.data && result.data.code;
        error.definite = error.code !== 'account_network';
        throw error;
      }
      return result;
    });
  }

  var unlockForm = root.querySelector('.fnchat-unlock');
  if (unlockForm) {
    var input = unlockForm.querySelector('input[name="password"]');
    var button = unlockForm.querySelector('button[type="submit"]');
    var status = unlockForm.querySelector('.fnchat-form-status');
    var show = unlockForm.querySelector('.fnchat-show-password');
    var unlocking = false;
    if (show) show.addEventListener('click', function () {
      var visible = input.type === 'password';
      input.type = visible ? 'text' : 'password';
      show.setAttribute('aria-pressed', String(visible));
      show.setAttribute('aria-label', visible ? show.dataset.hide : show.dataset.show);
    });
    unlockForm.addEventListener('submit', function (event) {
      event.preventDefault();
      if (unlocking) return;
      if (!input.value.trim()) {
        status.textContent = strings.passwordRequired;
        input.setAttribute('aria-invalid', 'true');
        input.focus();
        return;
      }
      unlocking = true;
      button.disabled = true;
      button.querySelector('span').textContent = strings.working;
      input.removeAttribute('aria-invalid');
      status.textContent = '';
      sendRequest('fieldnotes_chatgpt_unlock', { password: input.value }).then(function () {
        input.value = '';
        window.location.reload();
      }).catch(function (error) {
        status.textContent = error.message || strings.networkError;
        input.setAttribute('aria-invalid', 'true');
        input.focus();
        unlocking = false;
        button.disabled = false;
        button.querySelector('span').textContent = strings.enter || '进入对话';
      });
    });
    return;
  }

}());
