/* WatchRex UI — tiny, dependency-free. */
(function () {
  'use strict';
  var root = document.documentElement;

  // Theme
  try { var saved = localStorage.getItem('wr-theme'); if (saved) root.dataset.theme = saved; } catch (e) {}
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-theme-toggle]');
    if (!t) return;
    var dark = root.dataset.theme ? root.dataset.theme === 'dark' : !matchMedia('(prefers-color-scheme: light)').matches;
    root.dataset.theme = dark ? 'light' : 'dark';
    try { localStorage.setItem('wr-theme', root.dataset.theme); } catch (e) {}
  });

  // Mobile navigation
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-menu]')) { document.body.classList.toggle('nav-open'); return; }
    if (document.body.classList.contains('nav-open') && !e.target.closest('.sidebar')) document.body.classList.remove('nav-open');
  });

  // Confirmation for destructive forms
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !confirm(msg)) e.preventDefault();
  });

  // Copy to clipboard
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b) return;
    var el = document.querySelector(b.getAttribute('data-copy'));
    var text = el ? (el.value || el.textContent).trim() : b.getAttribute('data-copy-text');
    navigator.clipboard && navigator.clipboard.writeText(text).then(function () {
      var old = b.textContent; b.textContent = '✓'; setTimeout(function () { b.textContent = old; }, 1200);
    });
  });

  // Tabs
  document.querySelectorAll('[data-tabs]').forEach(function (box) {
    var buttons = box.querySelectorAll('[data-tab]');
    function show(name) {
      buttons.forEach(function (b) { b.classList.toggle('active', b.dataset.tab === name); });
      box.querySelectorAll('[data-tab-panel]').forEach(function (p) { p.classList.toggle('active', p.dataset.tabPanel === name); });
    }
    buttons.forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.tab); history.replaceState(null, '', '#' + b.dataset.tab); }); });
    var initial = location.hash.slice(1);
    show(box.querySelector('[data-tab="' + initial + '"]') ? initial : buttons[0] && buttons[0].dataset.tab);
  });

  // Monitor form: show fields relevant to the selected type
  var typeInputs = document.querySelectorAll('input[name="type"]');
  function syncType() {
    var sel = document.querySelector('input[name="type"]:checked');
    if (!sel) return;
    document.querySelectorAll('[data-show-for]').forEach(function (el) {
      var on = el.getAttribute('data-show-for').split(' ').indexOf(sel.value) !== -1;
      el.classList.toggle('hidden', !on);
      el.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = !on; });
    });
    var port = document.querySelector('input[name="port"]');
    if (port && sel.dataset.port) port.placeholder = sel.dataset.port;
  }
  typeInputs.forEach(function (i) { i.addEventListener('change', syncType); });
  syncType();

  // Live refresh (partial HTML swap, pauses when tab is hidden)
  document.querySelectorAll('[data-live]').forEach(function (el) {
    var every = (parseInt(el.getAttribute('data-live-every'), 10) || 30) * 1000;
    setInterval(function () {
      if (document.hidden) return;
      fetch(el.getAttribute('data-live') + location.search, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : null; })
        .then(function (html) { if (html !== null) el.innerHTML = html; })
        .catch(function () {});
    }, every);
  });

  // Full-page refresh for status pages
  var reload = document.querySelector('meta[name="wr-reload"]');
  if (reload) setInterval(function () { if (!document.hidden) location.reload(); }, parseInt(reload.content, 10) * 1000);

  // Auto-submit filter selects
  document.querySelectorAll('[data-autosubmit]').forEach(function (s) { s.addEventListener('change', function () { s.form.submit(); }); });
})();
