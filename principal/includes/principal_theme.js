(function(){
  'use strict';

  var STORAGE = {
    theme: 'pbi_theme',
    density: 'pbi_density',
    motion: 'pbi_reduce_motion'
  };
  var THEME_CSS = 'includes/principal_theme.css?v=20261011.5';
  var DARK_REPAIRS_CSS = 'includes/principal_dark_repairs.css?v=20261011.5';
  var WORKSPACE_CSS = 'includes/principal_workspace_consistency.css?v=20261011.5';
  var validThemes = ['light', 'dark', 'system'];
  var validDensities = ['comfortable', 'compact'];

  function read(key, allowed, fallback){
    try {
      var value = localStorage.getItem(key);
      return allowed.indexOf(value) !== -1 ? value : fallback;
    } catch (e) { return fallback; }
  }
  function systemPrefersDark(){
    return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  }
  function currentPreference(){ return read(STORAGE.theme, validThemes, 'light'); }
  function effectiveTheme(mode){ return mode === 'system' ? (systemPrefersDark() ? 'dark' : 'light') : mode; }

  function updateThemeButtons(mode){
    document.querySelectorAll('[data-theme-choice]').forEach(function(btn){
      var active = btn.getAttribute('data-theme-choice') === mode;
      btn.classList.toggle('active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    var label = document.getElementById('appearanceCurrentMode');
    if (label) label.textContent = mode.charAt(0).toUpperCase() + mode.slice(1) + (mode === 'system' ? ' (' + effectiveTheme(mode) + ')' : '');
  }

  function applyTheme(mode){
    var preference = validThemes.indexOf(mode) !== -1 ? mode : 'light';
    var resolved = effectiveTheme(preference);
    var dark = resolved === 'dark';
    var body = document.body;
    var html = document.documentElement;
    html.setAttribute('data-principal-theme-mode', preference);
    html.setAttribute('data-principal-effective-theme', resolved);
    // Remove legacy accent customization so the portal keeps its standard branding.
    html.removeAttribute('data-principal-accent');
    html.style.removeProperty('--appearance-accent');
    html.style.removeProperty('--appearance-accent-hover');
    html.style.removeProperty('--appearance-accent-rgb');
    html.style.removeProperty('--appearance-on-accent');
    if (body) {
      body.classList.toggle('light-theme', !dark);
      body.classList.toggle('dark-theme', dark);
    }
    html.classList.toggle('principal-theme-dark', dark);
    html.classList.remove('principal-theme-dark-pending');
    var oldValue = document.getElementById('appearanceVal');
    if (oldValue) oldValue.textContent = preference.charAt(0).toUpperCase() + preference.slice(1);
    updateThemeButtons(preference);
  }

  function applyDensity(density){
    var value = validDensities.indexOf(density) !== -1 ? density : 'comfortable';
    var html = document.documentElement;
    html.setAttribute('data-principal-density', value);
    html.classList.toggle('principal-density-compact', value === 'compact');
    document.querySelectorAll('[data-density-choice]').forEach(function(btn){
      var active = btn.getAttribute('data-density-choice') === value;
      btn.classList.toggle('active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  function osPrefersReducedMotion(){
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }
  function applyReducedMotion(enabled){
    var reduced = !!enabled;
    document.documentElement.setAttribute('data-principal-motion', reduced ? 'reduced' : 'full');
    document.documentElement.classList.toggle('principal-reduced-motion', reduced);
    var button = document.getElementById('appearanceMotionBtn');
    if (button) {
      button.classList.toggle('active', reduced);
      button.setAttribute('aria-pressed', reduced ? 'true' : 'false');
    }
  }

  window.setPrincipalAppearance = function(mode){
    var value = validThemes.indexOf(mode) !== -1 ? mode : 'light';
    try { localStorage.setItem(STORAGE.theme, value); } catch (e) {}
    applyTheme(value);
  };
  window.setPrincipalDensity = function(density){
    var value = validDensities.indexOf(density) !== -1 ? density : 'comfortable';
    try { localStorage.setItem(STORAGE.density, value); } catch (e) {}
    applyDensity(value);
  };
  window.togglePrincipalReducedMotion = function(){
    var current = document.documentElement.getAttribute('data-principal-motion') === 'reduced';
    var next = !current;
    try { localStorage.setItem(STORAGE.motion, next ? 'true' : 'false'); } catch (e) {}
    applyReducedMotion(next);
  };

  function addFinalCascadeStylesheet(){
    if (!document.querySelector('link[data-principal-theme-final="1"]')) {
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = THEME_CSS;
      link.setAttribute('data-principal-theme-final', '1');
      document.head.appendChild(link);
    }
    if (!document.querySelector('link[data-principal-dark-repairs="1"]')) {
      var repairLink = document.createElement('link');
      repairLink.rel = 'stylesheet';
      repairLink.href = DARK_REPAIRS_CSS;
      repairLink.setAttribute('data-principal-dark-repairs', '1');
      (document.body || document.documentElement).appendChild(repairLink);
    }
    if (!document.querySelector('link[data-principal-workspace-consistency="1"]')) {
      var workspaceLink = document.createElement('link');
      workspaceLink.rel = 'stylesheet';
      workspaceLink.href = WORKSPACE_CSS;
      workspaceLink.setAttribute('data-principal-workspace-consistency', '1');
      (document.body || document.documentElement).appendChild(workspaceLink);
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    var theme = currentPreference();
    var density = read(STORAGE.density, validDensities, 'comfortable');
    var reducedMotion;
    try {
      var savedMotion = localStorage.getItem(STORAGE.motion);
      reducedMotion = savedMotion === null ? osPrefersReducedMotion() : savedMotion === 'true';
    } catch (e) { reducedMotion = osPrefersReducedMotion(); }
    applyTheme(theme);
    applyDensity(density);
    applyReducedMotion(reducedMotion);
    addFinalCascadeStylesheet();
    if (window.matchMedia) {
      var media = window.matchMedia('(prefers-color-scheme: dark)');
      var onSystemThemeChange = function(){ if (currentPreference() === 'system') applyTheme('system'); };
      if (media.addEventListener) media.addEventListener('change', onSystemThemeChange);
      else if (media.addListener) media.addListener(onSystemThemeChange);
    }
  });
})();
