/* ZFS Backup Guard – Start, Kopfzeile, Navigation */
(function () {
  'use strict';
  const Z = window.ZBG;
  const { esc, icon, ui } = Z;

  const TABS = [
    ['overview', 'Übersicht', 'grid'],
    ['snapshots', 'Snapshots', 'camera'],
    ['restore', 'Wiederherstellen', 'restore'],
    ['history', 'Verlauf', 'history'],
    ['settings', 'Einstellungen', 'sliders'],
  ];

  function shell() {
    return `<div class="zbg-app">
      <header class="zbg-top">
        <div class="brand">
          <div class="zbg-logo">${icon('shield')}</div>
          <div><b>ZFS Backup Guard</b><small data-region="hdrSub">Snapshots · Replikation · Wiederherstellung</small></div>
        </div>
        <nav class="zbg-tabs" role="tablist">${TABS.map(([v, l, ic]) => `<button type="button" role="tab" class="zbg-tab" data-view="${v}" data-act="tab">${icon(ic)}<span>${l}</span></button>`).join('')}</nav>
        <div class="top-act" data-region="hdrAct"></div>
      </header>
      <main id="zbg-main" class="zbg-main"></main>
    </div>`;
  }

  Z.refreshers.hdrAct = () => {
    const ov = Z.state.overview;
    if (!ov) return '';
    const bad = ov.jobs.filter((j) => ['failed', 'stale'].includes(j.status.state)).length;
    const running = Object.values(Z.state.runs).filter((r) => r.state === 'running').length;
    const pill = running
      ? `<span class="zbg-pill tone-run">${icon('refresh', 'spin')}${running} läuft</span>`
      : bad
      ? `<span class="zbg-pill tone-err">${icon('alert')}${bad} Problem${bad > 1 ? 'e' : ''}</span>`
      : ov.jobs.some((j) => j.status.state === 'ok')
      ? `<span class="zbg-pill tone-ok">${icon('check')}Geschützte Jobs vorhanden</span>`
      : ov.jobs.length ? `<span class="zbg-pill tone-idle">${icon('shield')}Noch kein aktiver Schutz bestätigt</span>` : '';
    return `${pill}${ui.btn('', { act: 'reloadAll', icon: 'refresh', title: 'Aktualisieren' })}${window.ZBG_CONFIG?.csrf === 'dev' ? ui.btn('', { act: 'toggleTheme', icon: 'sliders', title: 'Hell/Dunkel (nur Vorschau)' }) : ''}`;
  };
  Z.updateHeader = () => Z.refresh('hdrAct');

  Z.actions.tab = (el) => Z.go(el.dataset.view);
  Z.actions.reloadAll = async () => {
    Z.state.datasets = null;
    await Z.loadOverview();
    if (Z.state.view !== 'overview') Z.go(Z.state.view, Z.state.params);
  };
  Z.actions.toggleTheme = () => {
    const r = Z.root();
    r.dataset.theme = r.dataset.theme === 'dark' ? 'light' : 'dark';
    try {
      localStorage.setItem('zbg-theme', r.dataset.theme);
    } catch (e) {}
    Z.updateHeader();
  };

  let overviewTimer = null;
  Z.scheduleOverviewRefresh = () => {
    clearTimeout(overviewTimer);
    overviewTimer = null;
    if (document.hidden || Z.state.view !== 'overview') return;
    overviewTimer = setTimeout(async () => {
      overviewTimer = null;
      if (!document.hidden && Z.state.view === 'overview' && !Z.topLayer()) await Z.loadOverview();
      else Z.scheduleOverviewRefresh();
    }, 60000);
  };
  document.addEventListener('visibilitychange', () => Z.scheduleOverviewRefresh());
  window.addEventListener('pagehide', () => clearTimeout(overviewTimer));
  function boot() {
    const root = Z.root();
    if (!root) return;
    try {
      const t = localStorage.getItem('zbg-theme');
      if (t) root.dataset.theme = t;
    } catch (e) {}
    root.innerHTML = shell();
    Z.bindEvents(root);
    const [view, q] = location.hash.replace('#', '').split('/');
    const initial = TABS.some((t) => t[0] === view) ? view : 'overview';
    Z.loadOverview().then(() => {
      if (initial !== 'overview') Z.go(initial, q ? { dataset: decodeURIComponent(q) } : {});
    });
    Z.state.view = initial === 'overview' ? 'overview' : initial;
    Z.render();
    Z.scheduleOverviewRefresh();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
