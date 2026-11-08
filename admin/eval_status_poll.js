/**
 * eval_status_poll.js
 *
 * Include on any dashboard so it reacts to evaluation open/close changes
 * without the user reloading:
 *
 *   <script src="../admin/eval_status_poll.js" defer></script>
 *
 * (adjust the path per folder; the endpoint is resolved next to this file.)
 *
 * Optional attributes on the <script> tag:
 *   data-endpoint="..."   full/relative URL of eval_status.php
 *   data-interval="10000" polling interval in ms (minimum 5000)
 *   data-busy-selector="#evalModal.open"  CSS selector; while anything matches it
 *                         (e.g. an open evaluation modal) the page is never
 *                         auto-refreshed - the banner is shown instead
 *   data-watch="submissions"  ALSO refresh when evaluation submissions change
 *                         (for the EA's Evaluation Tracker / Reports pages;
 *                         admin-side roles only - ignored for everyone else)
 *
 * Behavior
 *  - Polls every 10s (paused while the tab is hidden; checks immediately
 *    when the tab becomes visible again).
 *  - The first response is the baseline. If a later response has a different
 *    token (evaluation opened, closed, forced open/closed, new period...),
 *    the page refreshes itself.
 *  - If the user is in the middle of filling a form, the page is NOT reloaded
 *    (that would wipe their answers). A small banner offers "Refresh now".
 *  - The refresh keeps the current URL (filters/tabs) and scroll position,
 *    and never re-submits a POST form.
 *  - A page can handle the change itself by listening for the
 *    'evalstatuschange' event and calling event.preventDefault().
 */
(function () {
  'use strict';
  if (window.__evalStatusPollLoaded) return;
  window.__evalStatusPollLoaded = true;

  var script = document.currentScript;
  var endpoint = script && script.getAttribute('data-endpoint');
  if (!endpoint) {
    var src = (script && script.src) || '';
    endpoint = src ? src.replace(/eval_status_poll\.js(\?.*)?$/, 'eval_status.php') : 'eval_status.php';
  }
  var busySelector = script && script.getAttribute('data-busy-selector');
  var watch = script && script.getAttribute('data-watch');
  if (watch) endpoint += (endpoint.indexOf('?') === -1 ? '?' : '&') + 'watch=' + encodeURIComponent(watch);
  var interval = Math.max(5000, parseInt(script && script.getAttribute('data-interval'), 10) || 10000);

  var baseline = null;      // token from the first successful response
  var timer = null;
  var transitionTimer = null;
  var inFlight = null;
  var stopped = false;
  var dirty = false;        // user has typed/selected something in a form
  var bannerShown = false;

  /* ── Track "user is mid-form" so we never wipe their answers ───────────── */
  function markDirty(e) {
    if (!e.isTrusted) return;
    var t = e.target;
    if (!t || !t.closest) return;
    if (t.closest('[data-poll-ignore]')) return;
    var tag = (t.tagName || '').toLowerCase();
    var type = (t.type || '').toLowerCase();
    var form = t.closest('form');
    // GET forms are filters/search boxes - nothing to lose, so they don't count.
    if (form && String(form.method || 'get').toLowerCase() === 'get') return;
    if (tag === 'textarea' || form || type === 'radio' || type === 'checkbox' || type === 'range') {
      dirty = true;
    }
  }
  document.addEventListener('input', markDirty, true);
  document.addEventListener('change', markDirty, true);

  /* ── Refresh that keeps scroll position and never re-submits a POST ───── */
  var SCROLL_KEY = 'evalPollScroll';
  function safeRefresh() {
    try {
      sessionStorage.setItem(SCROLL_KEY, JSON.stringify({ u: location.href, y: window.scrollY || 0 }));
    } catch (e) { /* storage unavailable - fine */ }
    // replace() reloads via GET, so a page that was the result of a POST
    // does not trigger the browser's "resubmit form?" prompt.
    location.replace(location.href);
  }
  (function restoreScroll() {
    try {
      var raw = sessionStorage.getItem(SCROLL_KEY);
      if (!raw) return;
      sessionStorage.removeItem(SCROLL_KEY);
      var s = JSON.parse(raw);
      if (!s || s.u !== location.href || !s.y) return;
      var go = function () { window.scrollTo(0, s.y); };
      if (document.readyState === 'complete') go();
      else window.addEventListener('load', go);
    } catch (e) { /* ignore */ }
  })();

  /* ── Banner shown instead of reloading when the user is mid-form ───────── */
  function showBanner() {
    if (bannerShown || !document.body) return;
    bannerShown = true;
    var b = document.createElement('div');
    b.setAttribute('role', 'status');
    b.style.cssText = 'position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:2147483000;' +
      'background:#0B1F3A;color:#fff;padding:12px 16px;border-radius:12px;font:600 13px Inter,Segoe UI,Arial,sans-serif;' +
      'box-shadow:0 8px 24px rgba(0,0,0,.25);display:flex;align-items:center;gap:12px;max-width:92vw;';
    var msg = document.createElement('span');
    msg.textContent = 'The evaluation status has changed.';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = 'Refresh now';
    btn.style.cssText = 'background:#2563EB;color:#fff;border:0;border-radius:8px;padding:7px 12px;font:700 12px inherit;cursor:pointer;';
    btn.onclick = safeRefresh;
    b.appendChild(msg);
    b.appendChild(btn);
    document.body.appendChild(b);
  }

  function onChange(prev, next) {
    var ev = new CustomEvent('evalstatuschange', { cancelable: true, detail: { previous: prev, current: next } });
    if (!document.dispatchEvent(ev)) return; // page handled it itself

    var busy = false;
    try { busy = !!(busySelector && document.querySelector(busySelector)); } catch (e) { /* bad selector */ }
    if (dirty || busy) { showBanner(); return; }

    // Loop guard: never auto-reload more than once every 5 seconds.
    try {
      var last = parseInt(sessionStorage.getItem('evalPollReloadAt') || '0', 10);
      if (Date.now() - last < 5000) return;
      sessionStorage.setItem('evalPollReloadAt', String(Date.now()));
    } catch (e) { /* storage unavailable - fine */ }
    safeRefresh();
  }

  // Reload shortly after the exact server-configured open/close boundary.
  // Periodic polling remains as a fallback for settings changed while a page
  // is open or for browsers that throttle timers in the background.
  function scheduleTransition(d) {
    clearTimeout(transitionTimer);
    if (!d || !Number.isFinite(Number(d.next_transition)) || !Number.isFinite(Number(d.server_time_ms))) return;
    var serverOffset = Number(d.server_time_ms) - Date.now();
    var delay = Number(d.next_transition) * 1000 - (Date.now() + serverOffset) + 2000;
    if (delay > 0) transitionTimer = setTimeout(poll, delay);
  }

  /* ── Polling ───────────────────────────────────────────────────────────── */
  function poll() {
    if (stopped || document.hidden) return schedule();
    if (inFlight) inFlight.abort();
    var ctrl = new AbortController();
    inFlight = ctrl;
    var to = setTimeout(function () { ctrl.abort(); }, 8000);

    fetch(endpoint, { credentials: 'same-origin', cache: 'no-store', signal: ctrl.signal })
      .then(function (r) {
        if (r.status === 401) { stopped = true; throw new Error('logged-out'); }
        if (!r.ok) throw new Error('bad-status');
        return r.json();
      })
      .then(function (d) {
        if (!d || !d.token) return;
        scheduleTransition(d);
        if (baseline === null) { baseline = d; return; }
        if (d.token !== baseline.token) {
          var prev = baseline;
          baseline = d;
          onChange(prev, d);
        }
      })
      .catch(function () { /* network/server hiccup: just try again next tick */ })
      .then(function () {
        clearTimeout(to);
        if (inFlight === ctrl) inFlight = null;
        schedule();
      });
  }

  function schedule() {
    clearTimeout(timer);
    if (!stopped) timer = setTimeout(poll, interval);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && !stopped) { clearTimeout(timer); poll(); }
  });

  poll();
})();
