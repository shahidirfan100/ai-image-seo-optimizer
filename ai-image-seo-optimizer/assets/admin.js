(function(){
  'use strict';
  if (window.AIISOAdminBootstrapped) return;
  window.AIISOAdminBootstrapped = true;

  function qs(sel, root){ return (root || document).querySelector(sel); }
  function qsa(sel, root){ return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function activateSettingsTab(tab, updateHash){
    var nav = qsa('[data-aiiso-tab]');
    var panels = qsa('[data-aiiso-tab-panel]');
    if (!nav.length || !panels.length) return;
    var exists = nav.some(function(el){ return el.getAttribute('data-aiiso-tab') === tab; });
    if (!exists) tab = 'general';
    nav.forEach(function(el){ el.classList.toggle('is-active', el.getAttribute('data-aiiso-tab') === tab); });
    panels.forEach(function(el){ el.classList.toggle('is-active', el.getAttribute('data-aiiso-tab-panel') === tab); });
    if (updateHash && window.history && window.history.replaceState) {
      window.history.replaceState(null, '', '#' + tab);
    }
    try { window.localStorage.setItem('aiisoSettingsTab', tab); } catch(e) {}
  }

  function initSettingsTabs(){
    if (!qs('[data-aiiso-tab]')) return;
    var tab = (window.location.hash || '').replace('#','');
    if (!tab) {
      try { tab = window.localStorage.getItem('aiisoSettingsTab') || 'general'; }
      catch(e) { tab = 'general'; }
    }
    activateSettingsTab(tab, false);
  }

  function syncProviderStrategy(){
    var selected = qs('input[name="provider"]:checked');
    var both = qsa('[data-aiiso-both-settings]');
    var value = selected ? selected.value : '';
    both.forEach(function(el){ el.classList.toggle('is-hidden', value !== 'both'); });
  }

  document.addEventListener('click', function(e){
    var tab = e.target.closest ? e.target.closest('[data-aiiso-tab]') : null;
    if (tab) {
      e.preventDefault();
      activateSettingsTab(tab.getAttribute('data-aiiso-tab') || 'general', true);
    }
  });

  document.addEventListener('change', function(e){
    if (e.target && e.target.matches && e.target.matches('input[name="provider"]')) syncProviderStrategy();
  });

  function boot(){
    try {
      initSettingsTabs();
      syncProviderStrategy();
      document.documentElement.classList.add('aiiso-js-ready');
    } catch(err) {
      window.AIISOAdminBootstrapped = false;
      if (window.console && console.error) console.error('AI Image SEO admin initialization failed:', err);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true});
  else boot();
})();
