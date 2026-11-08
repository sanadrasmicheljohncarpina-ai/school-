/* ============================================================
   PBI Admin — shared Appearance engine (Density / Motion / Accent / Theme)
   Include as a normal blocking <script src="admin_appearance.js"></script>
   near the end of <head> (after theme CSS) on every admin page so saved
   preferences apply before first paint. Pair with admin_appearance.css.
   The controls in settings.php read/write the same localStorage keys via
   window.PBIAppearance so there is one source of truth.
   ============================================================ */
(function () {
  var KEYS = { density: 'pbiDensity', motion: 'pbiReduceMotion', accent: 'pbiAccent', theme: 'pbiTheme' };
  var DEFAULTS = { density: 'compact', motion: false, accent: '#0F9F6E', theme: 'light' };

  function read() {
    return {
      density: localStorage.getItem(KEYS.density) || DEFAULTS.density,
      motion: localStorage.getItem(KEYS.motion) === '1',
      accent: (localStorage.getItem(KEYS.accent) || DEFAULTS.accent).replace(/^#1f9d63$/i, '#0F9F6E'), /* old Green swatch -> brand green */
      theme: localStorage.getItem(KEYS.theme) || DEFAULTS.theme,
    };
  }

  function resolveTheme(pref) {
    if (pref === 'system') {
      try { return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
      catch (e) { return 'light'; }
    }
    return pref;
  }

  /* Highlight the currently active Density and Theme option buttons.
     Matches buttons by data attribute or label text, so it works with the
     existing settings.php markup and re-syncs after every click. */
  function syncSelected() {
    if (!document.body) return;
    var a = read();
    var density = a.density === 'comfortable' ? 'comfortable' : 'compact';
    var theme = (a.theme === 'dark' || a.theme === 'system') ? a.theme : 'light';
    var els = document.querySelectorAll('button, [role="button"]');
    Array.prototype.forEach.call(els, function (b) {
      var cands = [
        (b.textContent || '').trim().toLowerCase(),
        (b.getAttribute('data-density') || '').toLowerCase(),
        (b.getAttribute('data-theme-value') || '').toLowerCase(),
        (b.getAttribute('data-value') || '').toLowerCase()
      ];
      var on = null;
      for (var i = 0; i < cands.length && on === null; i++) {
        var k = cands[i];
        if (k === 'compact' || k === 'comfortable') on = (k === density);
        else if (k === 'light' || k === 'dark' || k === 'system') on = (k === theme);
      }
      if (on === null) return;
      b.classList.toggle('pbi-selected', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function apply() {
    var a = read();
    var html = document.documentElement;
    var density = a.density === 'comfortable' ? 'comfortable' : 'compact';
    html.classList.toggle('density-comfortable', density === 'comfortable');
    html.classList.toggle('density-compact', density === 'compact');
    html.setAttribute('data-density', density);
    html.classList.toggle('reduce-motion', a.motion);
    html.setAttribute('data-theme', resolveTheme(a.theme));
    html.style.setProperty('--accent', a.accent);
    try { syncSelected(); } catch (e) {}
  }

  try {
    apply();
  } catch (e) { /* best-effort: never block page load on this */ }

  try {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
      if (read().theme === 'system') apply();
    });
  } catch (e) {}

  window.addEventListener('storage', function (e) {
    if (e.key === KEYS.density || e.key === KEYS.motion || e.key === KEYS.accent || e.key === KEYS.theme) apply();
  });

  document.addEventListener('DOMContentLoaded', syncSelected);
  document.addEventListener('click', function () {
    setTimeout(syncSelected, 0);
    setTimeout(syncSelected, 150);
  });

  window.PBIAppearance = { syncSelected: syncSelected, KEYS: KEYS, DEFAULTS: DEFAULTS, read: read, resolveTheme: resolveTheme, apply: apply };
})();


/* FINAL DARK SURFACE OBSERVER
   Repaints late-injected/inline near-white surfaces that feature pages may
   create after the shared stylesheet has already loaded. */
(function initDarkSurfaceObserver(){
  var SURFACE='pbi-dark-surface';
  function dark(){ return document.documentElement.getAttribute('data-theme') === 'dark'; }
  function nearWhite(el){
    if (!el || el.nodeType !== 1 || el.classList.contains(SURFACE)) return false;
    var tag=el.tagName;
    if (tag === 'IMG' || tag === 'SVG' || tag === 'PATH' || tag === 'SCRIPT' || tag === 'STYLE') return false;
    var cs;
    try { cs=getComputedStyle(el); } catch(e) { return false; }
    if (cs.display === 'none' || cs.visibility === 'hidden') return false;
    var bg=(cs.backgroundColor||'').match(/rgba?\(([^)]+)\)/i);
    if (!bg) return false;
    var p=bg[1].split(',').map(function(v){return parseFloat(v.trim())||0;});
    if (p.length<3) return false;
    var a=p.length>3?p[3]:1;
    return a>0.92 && p[0]>238 && p[1]>238 && p[2]>238;
  }
  function scan(root){
    if (!dark() || !root) return;
    /* Generate-Report pages are intentionally white print documents. */
    if (document.body && document.body.classList.contains('report-mode')) return;
    var nodes=[];
    if (root.nodeType===1) nodes.push(root);
    try { root.querySelectorAll('*').forEach(function(el){ nodes.push(el); }); } catch(e){}
    nodes.forEach(function(el){
      if (nearWhite(el)) el.classList.add(SURFACE);
    });
  }
  function applySurfaceClass(){
    if (!dark()) return;
    scan(document.body);
  }
  document.addEventListener('DOMContentLoaded', applySurfaceClass, {once:true});
  try{
    new MutationObserver(function(mutations){
      if (!dark()) return;
      mutations.forEach(function(m){
        m.addedNodes && Array.prototype.forEach.call(m.addedNodes,function(n){ if(n.nodeType===1) scan(n); });
      });
    }).observe(document.documentElement,{subtree:true,childList:true});
  }catch(e){}
  try{
    new MutationObserver(function(){
      if (dark()) applySurfaceClass();
      else document.querySelectorAll('.'+SURFACE).forEach(function(el){el.classList.remove(SURFACE);});
    }).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});
  }catch(e){}
})();

