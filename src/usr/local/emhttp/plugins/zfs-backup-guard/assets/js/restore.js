/* ZFS Backup Guard – Wiederherstellen aus Snapshots und abgeschlossenen Versionen */
(function () {
  'use strict';
  const Z = window.ZBG;
  const { esc, fmt, ui, icon } = Z;
  const rs = (Z.state.rs = { mode: 'snapshot', dataset: '', job: '', points: null, point: '', path: '/', listing: null, selected: new Set(), q: '', error: null });
  let request = 0;
  const readableDatasets = () => (Z.state.datasets || []).filter((d) => d.type !== 'volume' && d.mounted && typeof d.mountpoint === 'string' && d.mountpoint.startsWith('/'));
  const versionJobs = () => (Z.state.overview?.jobs || []).map((j) => j.job).filter((j) => j.method === 'rsync' && j.target.mode === 'versioned');
  const sourceName = () => {
    if (rs.mode !== 'version') return rs.dataset;
    const job = versionJobs().find((j) => j.id === rs.job);
    return (job?.name || rs.job) + (job?.target.ssh ? ` · ${job.target.ssh.user}@${job.target.ssh.host}` : '');
  };
  const pointTime = (p) => rs.mode === 'version' ? p.time : p.creation;
  const pointName = (p) => rs.mode === 'version' ? p.name : p.snapshot;
  const join = (name) => (rs.path === '/' ? '' : rs.path) + '/' + name;
  const sourceParams = () => rs.mode === 'version' ? { job: rs.job, version: rs.point } : { dataset: rs.dataset, snapshot: rs.point };

  Z.views.restore = {
    async enter(params) {
      try {
        await Z.loadDatasets();
        if (params.job || params.version) rs.mode = 'version';
        else if (params.dataset || params.snapshot) rs.mode = 'snapshot';
        rs.dataset = params.dataset || rs.dataset || Z.state.overview?.jobs[0]?.job.source.dataset || readableDatasets().find((d) => d.name.includes('/'))?.name || readableDatasets()[0]?.name || '';
        rs.job = params.job || rs.job || versionJobs()[0]?.id || '';
        await pickSource(params.snapshot || params.version);
      } catch (e) {
        rs.error = e.message;
        if (Z.state.view === 'restore') Z.render();
      }
    },
    leave() { request++; },
    render() {
      const choices = rs.mode === 'version' ? versionJobs().map((j) => ({ value: j.id, label: j.name })) : readableDatasets().map((d) => ({ value: d.name, label: d.name }));
      const current = rs.mode === 'version' ? rs.job : rs.dataset;
      return `<div class="zbg-section-h"><h2>Wiederherstellen</h2><div class="toolbar">
        ${ui.seg('rsMode', [{ value: 'snapshot', label: 'Snapshots', icon: 'camera' }, { value: 'version', label: 'Versionen', icon: 'history' }], rs.mode, { act: 'rsMode' })}
        <label class="zbg-selectwrap"><span class="sr-only">${rs.mode === 'version' ? 'Backup-Job' : 'Dataset'}</span><select class="zbg-select" data-input="rsSource">${choices.map((c) => `<option value="${esc(c.value)}"${c.value === current ? ' selected' : ''}>${esc(c.label)}</option>`).join('')}</select></label>
        ${ui.btn('', { act: 'rsReload', icon: 'refresh', title: 'Wiederherstellungsstände neu laden' })}
      </div></div>
      ${rs.mode === 'version' ? `<p class="muted">Abgeschlossene Versionen lokaler und SSH-rsync-Jobs. Remote-Dateien werden über die hinterlegte SSH-Verbindung gelesen und in einen neuen lokalen Ordner kopiert.</p>` : ''}
      ${rs.error ? `<div class="zbg-callout tone-err" role="alert">${icon('alert')}<div>${esc(rs.error)}</div></div>` : ''}
      <section class="rs-time" data-region="rsTime">${timeBar()}</section>
      <div class="rs-main"><section class="zbg-panel rs-browser" data-region="rsBrowser">${browser()}</section><aside class="zbg-panel rs-side" data-region="rsSide">${side()}</aside></div>`;
    },
  };

  async function pickSource(preferred) {
    const ticket = ++request;
    Object.assign(rs, { points: null, point: '', path: '/', listing: null, selected: new Set(), q: '', error: null });
    if (Z.state.view === 'restore') Z.render();
    if (!(rs.mode === 'version' ? rs.job : rs.dataset)) {
      rs.points = [];
      if (Z.state.view === 'restore') Z.render();
      return;
    }
    try {
      const points = await Z.api(rs.mode === 'version' ? 'versions' : 'snapshots', rs.mode === 'version' ? { id: rs.job } : { dataset: rs.dataset });
      if (ticket !== request || Z.state.view !== 'restore') return;
      rs.points = points.slice().sort((a, b) => pointTime(b) - pointTime(a));
      const pick = rs.points.find((p) => pointName(p) === preferred) || rs.points[0];
      if (pick) await openPoint(pointName(pick), '/');
      else Z.render();
    } catch (e) {
      if (ticket !== request) return;
      rs.points = [];
      rs.error = e.message;
      if (Z.state.view === 'restore') Z.render();
    }
  }
  async function openPoint(point, path) {
    if (!point) return;
    const ticket = ++request;
    if (point !== rs.point) rs.selected = new Set();
    Object.assign(rs, { point, path, listing: null, error: null });
    refreshBrowser();
    try {
      const listing = await Z.api(rs.mode === 'version' ? 'browse.version' : 'browse', { ...sourceParams(), path });
      if (ticket !== request || Z.state.view !== 'restore') return;
      rs.listing = listing;
      rs.path = listing.path;
    } catch (e) {
      if (ticket !== request) return;
      rs.listing = { error: e.message, entries: [] };
    }
    if (Z.state.view === 'restore') refreshBrowser();
  }
  function refreshBrowser() {
    if (Z.state.view !== 'restore') return;
    Z.refresh('rsTime');
    Z.refresh('rsBrowser');
    Z.refresh('rsSide');
  }

  function timeBar() {
    if (!rs.points) return ui.skeleton(1);
    if (!rs.points.length) return ui.empty(rs.mode === 'version' ? 'history' : 'camera', rs.mode === 'version' ? 'Keine lokalen Versionen' : 'Keine Snapshots', rs.mode === 'version' ? 'Wähle einen lokalen versionierten Job mit einem erfolgreichen Backup.' : 'Wähle ein gemountetes Dataset mit Snapshots.');
    return `<div class="rs-time-h"><span>${icon('history')}Wiederherstellungsstand</span><small>${rs.points.length} Stände · neueste links</small></div><div class="rs-strip">${rs.points.map((p) => {
      const name = pointName(p);
      return `<div class="rs-day"><small>${esc(fmt.date(pointTime(p), { day: '2-digit', month: 'short', year: 'numeric' }))}</small><button type="button" class="rs-snap${name === rs.point ? ' on' : ''}" data-act="rsPoint" data-point="${esc(name)}" aria-pressed="${name === rs.point}" title="${esc(name)}"><b>${esc(fmt.date(pointTime(p), { hour: '2-digit', minute: '2-digit' }))}</b><span>${rs.mode === 'version' ? 'Version' : p.kind === 'zbg' ? 'Guard' : esc(p.kind || 'Snapshot')}</span></button></div>`;
    }).join('')}</div>`;
  }
  Z.refreshers.rsTime = timeBar;

  function crumbs() {
    let acc = '';
    return `<nav class="crumbs" aria-label="Verzeichnispfad"><button type="button" data-act="rsPath" data-path="/">${icon(rs.mode === 'version' ? 'history' : 'camera')}<span>${esc(rs.point)}</span></button>${rs.path.split('/').filter(Boolean).map((p) => {
      acc += '/' + p;
      return `${icon('right')}<button type="button" data-act="rsPath" data-path="${esc(acc)}">${esc(p)}</button>`;
    }).join('')}</nav>`;
  }
  function browser() {
    if (!rs.point) return rs.points ? ui.empty('folder', 'Kein Stand ausgewählt', 'Wähle oben eine Quelle und einen Wiederherstellungsstand.') : ui.skeleton(6);
    const head = `<div class="rs-bar">${crumbs()}<label class="zbg-search sm">${icon('search')}<input type="search" aria-label="Dateien im aktuellen Ordner filtern" placeholder="In diesem Ordner …" data-input="rsFilter" value="${esc(rs.q)}"></label></div>`;
    const listing = rs.listing;
    if (!listing) return head + ui.skeleton(6);
    if (listing.error) return head + ui.empty('alert', 'Nicht lesbar', listing.error, ui.btn('Erneut laden', { act: 'rsRetryBrowse', icon: 'refresh' }));
    const list = listing.entries.filter((e) => !rs.q || e.name.toLowerCase().includes(rs.q.toLowerCase()));
    const all = list.length > 0 && list.every((e) => rs.selected.has(join(e.name)));
    return `${head}<div class="zbg-table-wrap"><table class="zbg-table files"><thead><tr><th class="cb"><input type="checkbox" data-act="rsAll"${all ? ' checked' : ''} aria-label="Alle sichtbaren Einträge auswählen"></th><th>Name</th><th class="num">Größe</th><th>Geändert</th></tr></thead><tbody>
      ${rs.path !== '/' ? `<tr><td></td><td colspan="3"><button type="button" class="fname dir" data-act="rsPath" data-path="${esc(rs.path.split('/').slice(0, -1).join('/') || '/')}">${icon('left')}Übergeordnetes Verzeichnis</button></td></tr>` : ''}
      ${list.map((e) => {
        const path = join(e.name);
        return `<tr class="${rs.selected.has(path) ? 'sel' : ''}"><td class="cb"><input type="checkbox" data-act="rsSel" data-path="${esc(path)}"${rs.selected.has(path) ? ' checked' : ''} aria-label="${esc(e.name)} auswählen"></td><td>${e.type === 'dir' ? `<button type="button" class="fname dir" data-act="rsPath" data-path="${esc(path)}">${icon('folder')}<span>${esc(e.name)}</span></button>` : `<span class="fname">${icon(e.type === 'link' ? 'link' : 'file')}<span>${esc(e.name)}</span></span>`}</td><td class="num">${e.type === 'dir' ? '' : fmt.bytes(e.size)}</td><td>${esc(fmt.dateFull(e.mtime))}</td></tr>`;
      }).join('') || '<tr><td colspan="4"><p class="muted pad">Keine Einträge</p></td></tr>'}
    </tbody></table></div>${listing.truncated ? '<p class="zbg-foot">Die Liste wurde vom Server begrenzt. Unterordner öffnen, um weitere Dateien auszuwählen.</p>' : ''}`;
  }
  Z.refreshers.rsBrowser = browser;
  function side() {
    const point = rs.points?.find((p) => pointName(p) === rs.point);
    const selection = [...rs.selected];
    return `<h3>${icon('restore')}Auswahl</h3>${selection.length ? `<ul class="rs-sel">${selection.slice(0, 8).map((p) => `<li><span class="mono">${esc(p)}</span>${ui.btn('', { act: 'rsUnsel', icon: 'x', title: 'Aus Auswahl entfernen', data: { path: p } })}</li>`).join('')}${selection.length > 8 ? `<li>+ ${selection.length - 8} weitere</li>` : ''}</ul>${ui.btn(`${selection.length} Elemente wiederherstellen`, { act: 'rsRestore', icon: 'restore', kind: 'primary' })}` : '<p class="muted">Dateien oder Ordner auswählen, um sie in einen neuen Ordner zu kopieren.</p>'}
      ${point ? `<div class="rs-info">${ui.kv([['Quelle', `<span class="mono">${esc(sourceName())}</span>`], [rs.mode === 'version' ? 'Version' : 'Snapshot', `<span class="mono">${esc(rs.point)}</span>`], ['Erstellt', esc(fmt.dateFull(pointTime(point)))], ...(rs.mode === 'version' ? [['Versionspfad', `<span class="mono">${esc(point.path)}</span>`]] : [['Exklusiv', fmt.bytes(point.used)]] )])}</div>` : ''}
      <div class="zbg-callout tone-ok sm">${icon('shield')}<div>Die Quelle bleibt unverändert. Das Ziel ist ein neuer Ordner; vorhandene Zielordner werden abgelehnt. Das Standardziel liegt neben dem Original.</div></div>`;
  }
  Z.refreshers.rsSide = side;

  Z.actions.rsMode = async (el) => {
    rs.mode = el.dataset.value;
    if (!rs.job) rs.job = versionJobs()[0]?.id || '';
    if (!rs.dataset) rs.dataset = readableDatasets()[0]?.name || '';
    await pickSource();
  };
  Z.actions.rsSource = (el) => {
    if (rs.mode === 'version') rs.job = el.value;
    else rs.dataset = el.value;
    return pickSource();
  };
  Z.actions.rsReload = () => pickSource(rs.point);
  Z.actions.rsPoint = (el) => openPoint(el.dataset.point, '/');
  Z.actions.rsPath = (el) => { rs.q = ''; return openPoint(rs.point, el.dataset.path); };
  Z.actions.rsRetryBrowse = () => openPoint(rs.point, rs.path);
  Z.actions.rsFilter = (el) => {
    rs.q = el.value;
    Z.refresh('rsBrowser');
    const input = Z.$('[data-input="rsFilter"]');
    input?.focus();
  };
  Z.actions.rsSel = (el) => {
    el.checked ? rs.selected.add(el.dataset.path) : rs.selected.delete(el.dataset.path);
    el.closest('tr').classList.toggle('sel', el.checked);
    Z.refresh('rsSide');
  };
  Z.actions.rsAll = (el) => {
    (rs.listing?.entries || []).filter((e) => !rs.q || e.name.toLowerCase().includes(rs.q.toLowerCase())).forEach((e) => el.checked ? rs.selected.add(join(e.name)) : rs.selected.delete(join(e.name)));
    refreshBrowser();
  };
  Z.actions.rsUnsel = (el) => { rs.selected.delete(el.dataset.path); refreshBrowser(); };
  Z.actions.rsRestore = () => {
    const paths = [...rs.selected];
    if (!paths.length || !rs.point) return;
    const source = sourceParams();
    const layer = Z.modal({
      title: `${icon('restore')} Wiederherstellen`,
      body: `<p>${paths.length} Elemente aus <b class="mono">${esc(sourceName())} / ${esc(rs.point)}</b>.</p><div class="rs-dest">
        <label class="rs-opt"><input type="radio" name="rsdest" value="beside" checked><div><b>Neben dem Original</b><small>Neuer eindeutiger Ordner restore-&lt;datum&gt;</small></div></label>
        <label class="rs-opt"><input type="radio" name="rsdest" value="custom"><div><b>Anderer Ort</b><input class="zbg-input mono" data-restore-target aria-label="Neuer Zielordner unter /mnt" placeholder="/mnt/user/restore/neuer-ordner"><small>Absoluter Pfad unter /mnt. Der Ordner darf noch nicht existieren.</small></div></label>
      </div><div class="zbg-callout tone-warn">${icon('info')}<div>Die Kopie kann dauern. Dieses Fenster bis zur Antwort offen lassen und nicht erneut starten.</div></div><p class="err" role="alert" data-restore-error></p>`,
      footer: ui.btn('Abbrechen', { act: 'closeLayer' }) + ui.btn('In neuen Ordner kopieren', { act: 'rsDo', icon: 'restore', kind: 'primary' }),
    });
    const input = layer.el.querySelector('[data-restore-target]');
    input.addEventListener('input', () => { layer.el.querySelector('input[value="custom"]').checked = true; });
    let busy = false;
    layer.canClose = () => !busy;
    Z.actions.rsDo = async () => {
      if (busy) return;
      const custom = layer.el.querySelector('input[name="rsdest"]:checked').value === 'custom';
      const target = custom ? input.value.trim() : null;
      const error = layer.el.querySelector('[data-restore-error]');
      if (custom && !target?.startsWith('/mnt/')) { error.textContent = 'Bitte einen neuen absoluten Zielpfad unter /mnt angeben.'; input.focus(); return; }
      busy = true;
      layer.el.querySelectorAll('[data-act="closeLayer"]').forEach((b) => { if (b.tagName === 'BUTTON') b.disabled = true; });
      layer.el.querySelector('.zbg-dialog-body').setAttribute('aria-busy', 'true');
      const button = layer.el.querySelector('[data-act="rsDo"]');
      button.disabled = true;
      error.textContent = '';
      try {
        const result = await Z.api('restore', { ...source, paths, target });
        busy = false;
        Z.closeLayer(layer);
        rs.selected = new Set();
        refreshBrowser();
        Z.modal({ title: 'Wiederherstellung abgeschlossen', body: `<p>${esc(result.count ?? paths.length)} Elemente kopiert. Ziele:</p><ul>${(result.targets || [result.target]).filter(Boolean).map((path) => `<li class="mono">${esc(path)}</li>`).join('')}</ul>`, footer: ui.btn('Schließen', { act: 'closeLayer', kind: 'primary' }) });
      } catch (e) {
        error.textContent = e.message;
      } finally {
        busy = false;
        button.disabled = false;
        layer.el.querySelectorAll('[data-act="closeLayer"]').forEach((b) => { if (b.tagName === 'BUTTON') b.disabled = false; });
        layer.el.querySelector('.zbg-dialog-body').setAttribute('aria-busy', 'false');
      }
    };
  };
})();