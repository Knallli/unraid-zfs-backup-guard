/* ZFS Backup Guard – Wiederherstellen: Zeitreise durch Snapshots */
(function () {
  'use strict';
  const Z = window.ZBG;
  const { esc, fmt, ui, icon } = Z;

  const rs = (Z.state.rs = { dataset: null, snaps: null, snapshot: null, path: '/', listing: null, selected: new Set(), q: '' });

  const pad2 = (n) => String(n).padStart(2, '0');
  /** JJJJMMTT-HHMM in Lokalzeit */
  const localStamp = (ts) => {
    const d = new Date(ts * 1000);
    return `${d.getFullYear()}${pad2(d.getMonth() + 1)}${pad2(d.getDate())}-${pad2(d.getHours())}${pad2(d.getMinutes())}`;
  };
  const FILE_IC = (e) => (e.type === 'dir' ? 'folder' : e.type === 'link' ? 'link' : 'file');

  Z.views.restore = {
    async enter(params) {
      try {
        await Z.loadDatasets();
      } catch (e) {
        return Z.fail(e);
      }
      const ds = params.dataset || rs.dataset || Z.state.overview?.jobs[0]?.job.source.dataset || Z.state.datasets.find((d) => d.mounted && d.name.includes('/'))?.name;
      await pickDataset(ds, params.snapshot);
    },
    render() {
      const dss = (Z.state.datasets || []).filter((d) => d.mounted && d.mountpoint.startsWith('/'));
      return `<div class="zbg-section-h"><h2>Wiederherstellen</h2>
          <div class="toolbar"><label class="zbg-selectwrap">${icon('database')}<select class="zbg-select" data-input="rsDataset">${dss.map((d) => `<option value="${esc(d.name)}"${d.name === rs.dataset ? ' selected' : ''}>${esc(d.name)}</option>`).join('')}</select></label></div></div>
        <section class="rs-time" data-region="rsTime">${timeBar()}</section>
        <div class="rs-main">
          <section class="zbg-panel rs-browser" data-region="rsBrowser">${browser()}</section>
          <aside class="zbg-panel rs-side" data-region="rsSide">${side()}</aside>
        </div>`;
    },
  };

  async function pickDataset(ds, snapshot) {
    if (!ds) return;
    Object.assign(rs, { dataset: ds, snaps: null, snapshot: null, path: '/', listing: null, selected: new Set() });
    if (Z.state.view === 'restore') Z.render();
    try {
      rs.snaps = (await Z.api('snapshots', { dataset: ds })).slice().reverse();
    } catch (e) {
      rs.snaps = [];
      Z.fail(e);
    }
    const pick = (snapshot && rs.snaps.find((s) => s.snapshot === snapshot)) || rs.snaps[0];
    if (pick) await openSnap(pick.snapshot, '/');
    else Z.render();
  }

  async function openSnap(snapshot, path) {
    const changedSnap = snapshot !== rs.snapshot;
    rs.snapshot = snapshot;
    rs.path = path;
    rs.listing = null;
    if (changedSnap) rs.selected = new Set();
    if (Z.state.view !== 'restore') return;
    Z.refresh('rsTime');
    Z.refresh('rsBrowser');
    try {
      rs.listing = await Z.api('browse', { dataset: rs.dataset, snapshot, path });
      rs.path = rs.listing.path;
    } catch (e) {
      rs.listing = { error: e.message, entries: [] };
    }
    Z.refresh('rsBrowser');
    Z.refresh('rsSide');
  }

  /* ---------- Zeitleiste ---------- */
  function timeBar() {
    if (!rs.snaps) return ui.skeleton(1);
    if (!rs.snaps.length) return `<div class="rs-empty">${icon('camera')}Für <b class="mono">${esc(rs.dataset)}</b> gibt es keine Snapshots. Ein Job mit diesem Dataset legt sie an.</div>`;
    const groups = [];
    for (const s of rs.snaps) {
      const day = new Date(s.creation * 1000).toLocaleDateString(Z.cfg.locale, { weekday: 'short', day: '2-digit', month: 'short' });
      if (!groups.length || groups[groups.length - 1].day !== day) groups.push({ day, items: [] });
      groups[groups.length - 1].items.push(s);
    }
    return `<div class="rs-time-h"><span>${icon('history')}Zeitreise</span><small>${rs.snaps.length} Snapshots · neueste links</small></div>
      <div class="rs-strip">${groups
        .map(
          (g) => `<div class="rs-day"><small>${esc(g.day)}</small><div>${g.items
            .map(
              (s) => `<button type="button" class="rs-snap k-${s.kind}${s.snapshot === rs.snapshot ? ' on' : ''}" data-act="rsSnap" data-snap="${esc(s.snapshot)}" title="${esc(s.snapshot)}">
              <b>${new Date(s.creation * 1000).toLocaleTimeString(Z.cfg.locale, { hour: '2-digit', minute: '2-digit' })}</b><span>${s.kind === 'zbg' ? 'Guard' : esc(s.kind)}</span></button>`
            )
            .join('')}</div></div>`
        )
        .join('')}</div>`;
  }
  Z.refreshers.rsTime = () => timeBar();

  /* ---------- Browser ---------- */
  function crumbs() {
    const parts = rs.path.split('/').filter(Boolean);
    let acc = '';
    return `<nav class="crumbs"><button type="button" data-act="rsPath" data-path="/">${icon('camera')}<span>${esc(rs.snapshot)}</span></button>${parts
      .map((p) => {
        acc += '/' + p;
        return `${icon('right')}<button type="button" data-act="rsPath" data-path="${esc(acc)}">${esc(p)}</button>`;
      })
      .join('')}</nav>`;
  }
  function browser() {
    if (!rs.snapshot) return rs.snaps && !rs.snaps.length ? ui.empty('camera', 'Keine Snapshots', 'Wähle ein anderes Dataset.') : ui.skeleton(8);
    const L = rs.listing;
    const head = `<div class="rs-bar">${crumbs()}<label class="zbg-search sm">${icon('search')}<input type="search" placeholder="In diesem Ordner …" data-input="rsFilter" value="${esc(rs.q)}"></label></div>`;
    if (!L) return head + ui.skeleton(8);
    if (L.error) return head + ui.empty('alert', 'Nicht lesbar', L.error);
    const q = rs.q.toLowerCase();
    const list = L.entries.filter((e) => !q || e.name.toLowerCase().includes(q));
    const up = rs.path !== '/' ? `<tr class="up" data-act="rsPath" data-path="${esc(rs.path.split('/').slice(0, -1).join('/') || '/')}"><td></td><td colspan="3">${icon('left')}<span>..</span></td></tr>` : '';
    const allOn = list.length && list.every((e) => rs.selected.has(join(e.name)));
    return `${head}<div class="zbg-table-wrap"><table class="zbg-table files">
      <thead><tr><th class="cb"><input type="checkbox" data-act="rsAll"${allOn ? ' checked' : ''} title="Alle auswählen"></th><th>Name</th><th class="num">Größe</th><th>Geändert</th></tr></thead>
      <tbody>${up}${
        list.length
          ? list
              .map((e) => {
                const p = join(e.name);
                return `<tr class="${rs.selected.has(p) ? 'sel' : ''}">
              <td class="cb"><input type="checkbox" data-act="rsSel" data-path="${esc(p)}"${rs.selected.has(p) ? ' checked' : ''}></td>
              <td>${e.type === 'dir' ? `<button type="button" class="fname dir" data-act="rsPath" data-path="${esc(p)}">${icon('folder')}<span>${esc(e.name)}</span></button>` : `<span class="fname">${icon(FILE_IC(e))}<span>${esc(e.name)}</span></span>`}</td>
              <td class="num">${e.type === 'dir' ? '' : fmt.bytes(e.size)}</td>
              <td><span title="${esc(fmt.dateFull(e.mtime))}">${esc(fmt.date(e.mtime, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }))}</span></td></tr>`;
              })
              .join('')
          : `<tr><td colspan="4"><p class="muted pad">Leerer Ordner</p></td></tr>`
      }</tbody></table></div>${L.truncated ? `<p class="zbg-foot">${icon('info')}Nur die ersten 2000 Einträge werden angezeigt.</p>` : ''}`;
  }
  Z.refreshers.rsBrowser = () => browser();
  const sq = (s) => "'" + String(s).replace(/'/g, "'\\''") + "'";
  const join = (name) => (rs.path === '/' ? '' : rs.path) + '/' + name;

  /* ---------- Seitenleiste ---------- */
  function side() {
    const s = rs.snaps?.find((x) => x.snapshot === rs.snapshot);
    const ds = Z.state.datasets?.find((d) => d.name === rs.dataset);
    const sel = [...rs.selected];
    const stamp = s ? localStamp(s.creation) : '';
    return `<h3>${icon('restore')}Auswahl</h3>
      ${
        sel.length
          ? `<ul class="rs-sel">${sel
              .slice(0, 8)
              .map((p) => `<li><span class="mono">${esc(p)}</span>${ui.btn('', { act: 'rsUnsel', icon: 'x', title: 'Entfernen', data: { path: p } })}</li>`)
              .join('')}${sel.length > 8 ? `<li class="muted">+ ${sel.length - 8} weitere</li>` : ''}</ul>
          ${ui.btn(`${sel.length} ${sel.length === 1 ? 'Element' : 'Elemente'} wiederherstellen`, { act: 'rsRestore', icon: 'restore', kind: 'primary' })}`
          : `<p class="muted">Wähle Dateien oder Ordner aus, um sie aus diesem Snapshot zurückzuholen.</p>`
      }
      ${
        s
          ? `<div class="rs-info">${ui.kv([
              ['Snapshot', `<span class="mono">${esc(s.snapshot)}</span>`],
              ['Erstellt', esc(fmt.dateFull(s.creation))],
              ['Alter', esc(fmt.rel(s.creation))],
              ['Exklusiv', fmt.bytes(s.used)],
              ['Standardziel', `<span class="mono">${esc(ds?.mountpoint || '')}/…/restore-${stamp}/</span>`],
            ])}</div>`
          : ''
      }
      <div class="zbg-callout tone-ok sm">${icon('shield')}<div>Wiederherstellen überschreibt nie etwas. Es wird in einen neuen Ordner <code>restore-&lt;datum&gt;</code> neben dem Original kopiert.</div></div>`;
  }
  Z.refreshers.rsSide = () => side();

  /* ---------- Aktionen ---------- */
  Z.actions.rsDataset = (el) => pickDataset(el.value);
  Z.actions.rsSnap = (el) => openSnap(el.dataset.snap, rs.path);
  Z.actions.rsPath = (el) => {
    rs.q = '';
    openSnap(rs.snapshot, el.dataset.path);
  };
  Z.actions.rsFilter = (el) => {
    rs.q = el.value;
    const pos = el.selectionStart;
    Z.refresh('rsBrowser');
    const inp = Z.$('[data-input="rsFilter"]');
    if (inp) {
      inp.focus();
      inp.setSelectionRange(pos, pos);
    }
  };
  Z.actions.rsSel = (el) => {
    el.checked ? rs.selected.add(el.dataset.path) : rs.selected.delete(el.dataset.path);
    el.closest('tr').classList.toggle('sel', el.checked);
    Z.refresh('rsSide');
  };
  Z.actions.rsAll = (el) => {
    const q = rs.q.toLowerCase();
    (rs.listing?.entries || []).filter((e) => !q || e.name.toLowerCase().includes(q)).forEach((e) => (el.checked ? rs.selected.add(join(e.name)) : rs.selected.delete(join(e.name))));
    Z.refresh('rsBrowser');
    Z.refresh('rsSide');
  };
  Z.actions.rsUnsel = (el) => {
    rs.selected.delete(el.dataset.path);
    Z.refresh('rsBrowser');
    Z.refresh('rsSide');
  };
  Z.actions.rsRestore = () => {
    const sel = [...rs.selected];
    const ds = Z.state.datasets.find((d) => d.name === rs.dataset);
    const s = rs.snaps.find((x) => x.snapshot === rs.snapshot);
    const stamp = localStamp(s.creation);
    const src = `${ds.mountpoint}/.zfs/snapshot/${rs.snapshot}`;
    const cmd = (dest) =>
      sel
        .map((p) => {
          const parent = p.split('/').slice(0, -1).join('/');
          const d = dest === 'beside' ? `${ds.mountpoint}${parent}/restore-${stamp}/` : `${dest.replace(/\/$/, '')}/`;
          return `mkdir -p ${sq(d)} && rsync -aHAX ${sq(src + p)} ${sq(d)}`;
        })
        .join('\n');
    const runner = Z.state.overview?.runnerAvailable;
    const layer = Z.modal({
      title: `${icon('restore')} Wiederherstellen`,
      size: 'md',
      body: `<p>${sel.length} ${sel.length === 1 ? 'Element' : 'Elemente'} aus <b class="mono">${esc(rs.dataset)}@${esc(rs.snapshot)}</b> (${esc(fmt.dateFull(s.creation))}).</p>
        <div class="rs-dest">
          <label class="rs-opt"><input type="radio" name="rsdest" value="beside" checked data-act="rsDest"><div><b>Neben dem Original</b><small class="mono">…/restore-${esc(stamp)}/</small></div></label>
          <label class="rs-opt"><input type="radio" name="rsdest" value="custom" data-act="rsDest"><div><b>Anderer Ort</b><input class="zbg-input mono" id="rs-custom" placeholder="/mnt/user/restore" value="/mnt/user/restore/${esc(rs.dataset.split('/').pop())}-${esc(stamp)}"></div></label>
        </div>
        <h4 class="ed-h4">${icon('terminal')}Entspricht</h4>
        <pre class="zbg-pre small" id="rs-cmd">${esc(cmd('beside'))}</pre>
        ${runner ? '' : `<div class="zbg-callout tone-info">${icon('info')}<div>Das Wiederherstellen per Klick kommt mit Phase 5. Bis dahin kannst du den Befehl oben kopieren und im Terminal ausführen. Er ist rein lesend auf dem Snapshot.</div></div>`}`,
      footer: ui.btn('Befehl kopieren', { act: 'rsCopyCmd', icon: 'copy' }) + ui.btn('Wiederherstellen', { act: 'rsDo', icon: 'restore', kind: 'primary', disabled: !runner, title: runner ? '' : 'Folgt in Phase 5' }),
    });
    const upd = () => {
      const v = layer.el.querySelector('input[name=rsdest]:checked').value;
      layer.el.querySelector('#rs-cmd').textContent = cmd(v === 'beside' ? 'beside' : layer.el.querySelector('#rs-custom').value);
    };
    Z.actions.rsDest = upd;
    layer.el.querySelector('#rs-custom').addEventListener('input', () => {
      layer.el.querySelector('input[value=custom]').checked = true;
      upd();
    });
    Z.actions.rsCopyCmd = () => Z.copy(layer.el.querySelector('#rs-cmd').textContent);
    Z.actions.rsDo = async () => {
      const v = layer.el.querySelector('input[name=rsdest]:checked').value;
      try {
        const r = await Z.api('restore', { dataset: rs.dataset, snapshot: rs.snapshot, paths: sel, target: v === 'beside' ? null : layer.el.querySelector('#rs-custom').value });
        Z.closeLayer(layer);
        Z.toast(`Wiederhergestellt nach ${r.target}`);
        rs.selected = new Set();
        Z.refresh('rsBrowser');
        Z.refresh('rsSide');
      } catch (e) {
        Z.fail(e);
      }
    };
  };
})();
