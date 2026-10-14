/* ZFS Backup Guard – Job-Assistent */
(function () {
  'use strict';
  const Z = window.ZBG;
  const { esc, fmt, ui, icon } = Z;

  const STEPS = [
    { key: 'source', label: 'Quelle', icon: 'database', hint: 'Was wird gesichert?' },
    { key: 'target', label: 'Ziel', icon: 'drive', hint: 'Wohin und wie?' },
    { key: 'schedule', label: 'Zeitplan', icon: 'calendar', hint: 'Wann?' },
    { key: 'retention', label: 'Aufbewahrung', icon: 'layers', hint: 'Wie lange?' },
    { key: 'consistency', label: 'Konsistenz', icon: 'box', hint: 'Container & Hooks' },
    { key: 'notify', label: 'Überwachung', icon: 'bell', hint: 'Benachrichtigungen' },
    { key: 'summary', label: 'Prüfen', icon: 'check', hint: 'Zusammenfassung' },
  ];

  const DEFAULT_JOB = () => ({
    id: '',
    name: '',
    enabled: true,
    source: { dataset: '', recursive: true, exclude: [] },
    method: 'rsync',
    target: { path: '', mode: 'versioned', dataset: '', ssh: null },
    schedule: '0 2 * * *',
    snapshots: { keep: { hourly: 0, daily: 7, weekly: 4, monthly: 3, yearly: 0 } },
    versions: { keep: { daily: 14, weekly: 8, monthly: 6 } },
    consistency: { mode: 'crash', containers: [] },
    hooks: { pre: null, post: null },
    notify: 'errors',
    maxAgeHours: 30,
    healthcheckUrl: null,
  });

  const DB_RE = /postgres|mariadb|mysql|redis|valkey|mongo|influx|timescale|clickhouse|couchdb|immich_postgres/i;

  let ed = null;
  Z.editorState = () => ed;

  Z.openEditor = async function (job, originalId) {
    const base = DEFAULT_JOB();
    const j = job ? deepMerge(base, JSON.parse(JSON.stringify(job))) : base;
    ed = { job: j, originalId, step: 0, visited: new Set([0]), idTouched: !!originalId, check: null, containers: null, ctFilter: '', showJson: false, advanced: !!(j.hooks.pre || j.hooks.post) };
    ed.layer = Z.modal({
      kind: 'sheet',
      size: 'xl',
      cls: 'zbg-editor',
      title: `${icon(originalId ? 'edit' : 'sparkles')} ${originalId ? 'Job bearbeiten' : 'Neuer Backup-Job'} <span class="muted" data-region="edTitle">${esc(j.name)}</span>`,
      body: `<div class="ed-wrap"><nav class="ed-steps" data-region="edSteps">${stepsNav()}</nav><div class="ed-body" data-region="edStep">${ui.skeleton(6)}</div></div>`,
      footer: `<div data-region="edFoot" class="ed-foot">${footer()}</div>`,
      onClose: () => (ed = null),
    });
    try {
      await Z.loadDatasets();
    } catch (e) {
      Z.fail(e);
    }
    renderStep();
    if (j.source.dataset && j.method === 'rsync' && j.target.path) checkTarget();
  };
  Z.actions.newJob = () => Z.openEditor(null, null);
  Z.actions.editJob = (el) => {
    const top = Z.topLayer();
    if (top && top.el.querySelector('.zbg-menu')) Z.closeLayer(top);
    const j = Z.findJob(el.dataset.id);
    if (j) Z.openEditor(j.job, j.job.id);
  };

  function deepMerge(a, b) {
    for (const k of Object.keys(b)) {
      if (b[k] && typeof b[k] === 'object' && !Array.isArray(b[k]) && a[k] && typeof a[k] === 'object') a[k] = deepMerge(a[k], b[k]);
      else a[k] = b[k];
    }
    return a;
  }

  /* ---------- Rahmen ---------- */
  function stepsNav() {
    return STEPS.map((s, i) => {
      const st = i === ed.step ? 'cur' : ed.visited.has(i) ? (stepError(i) ? 'bad' : 'done') : '';
      return `<button type="button" class="ed-step ${st}" data-act="edGo" data-i="${i}">
        <span class="n">${st === 'done' ? icon('check') : st === 'bad' ? icon('alert') : i + 1}</span>
        <span class="t"><b>${s.label}</b><small>${s.hint}</small></span></button>`;
    }).join('');
  }
  function footer() {
    const last = ed.step === STEPS.length - 1;
    const runner = Z.state.overview?.runnerAvailable;
    return `${ed.step > 0 ? ui.btn('Zurück', { act: 'edPrev', icon: 'left' }) : ui.btn('Abbrechen', { act: 'closeLayer' })}
      <span class="grow"></span>
      <span class="ed-progress">${ui.progress(((ed.step + 1) / STEPS.length) * 100)}<small>Schritt ${ed.step + 1} von ${STEPS.length}</small></span>
      <span class="grow"></span>
      ${
        last
          ? (runner ? ui.btn('Speichern & Probelauf', { act: 'edSave', icon: 'flask', data: { dry: 1 } }) : '') + ui.btn(ed.originalId ? 'Änderungen speichern' : 'Job anlegen', { act: 'edSave', icon: 'check', kind: 'primary' })
          : ui.btn('Weiter', { act: 'edNext', icon: 'right', kind: 'primary' })
      }`;
  }
  function renderStep() {
    if (!ed) return;
    const body = Z.$('[data-region="edStep"]', ed.layer.el);
    body.innerHTML = `<div class="ed-anim">${RENDER[STEPS[ed.step].key]()}</div>`;
    body.scrollTop = 0;
    Z.$('[data-region="edSteps"]', ed.layer.el).innerHTML = stepsNav();
    Z.$('[data-region="edFoot"]', ed.layer.el).innerHTML = footer();
    AFTER[STEPS[ed.step].key]?.();
  }
  function renderStepKeepFocus() {
    const f = document.activeElement;
    const bind = f && f.dataset ? f.dataset.bind : null;
    const pos = f && f.selectionStart;
    renderStep();
    if (bind) {
      const el = Z.$(`[data-bind="${bind}"]`, ed.layer.el);
      if (el) {
        el.focus();
        try {
          el.setSelectionRange(pos, pos);
        } catch (e) {}
      }
    }
  }
  function goto(i) {
    ed.step = Z.clamp(i, 0, STEPS.length - 1);
    ed.visited.add(ed.step);
    renderStep();
  }
  Z.actions.edGo = (el) => goto(+el.dataset.i);
  Z.actions.edPrev = () => goto(ed.step - 1);
  Z.actions.edNext = () => {
    const err = stepError(ed.step);
    if (err) {
      ed.visited.add(ed.step);
      Z.$('[data-region="edSteps"]', ed.layer.el).innerHTML = stepsNav();
      return Z.toast(err, 'warn');
    }
    goto(ed.step + 1);
  };

  function stepError(i) {
    const j = ed.job;
    switch (STEPS[i].key) {
      case 'source':
        if (!j.source.dataset) return 'Bitte ein Quell-Dataset wählen';
        if (!j.name.trim()) return 'Bitte einen Namen vergeben';
        if (!/^[a-z0-9][a-z0-9-]{0,39}$/.test(j.id)) return 'ID: nur a–z, 0–9 und „-“';
        return null;
      case 'target':
        if (j.method === 'rsync' && !/^\/mnt\/[^/]+\/.+/.test(j.target.path || '')) return 'Ziel muss unter /mnt/<share>/ liegen';
        if (j.method === 'zfs' && !j.target.dataset) return 'Bitte ein Ziel-Dataset angeben';
        if (j.target.ssh && !j.target.ssh.host) return 'SSH-Host fehlt';
        return null;
      case 'schedule':
        return Z.cron.valid(j.schedule) ? null : 'Ungültiger Cron-Ausdruck';
      default:
        return null;
    }
  }

  /* ---------- Bindings ---------- */
  Z.onBind = function (el) {
    if (!ed || !ed.layer.el.contains(el)) return;
    const path = el.dataset.bind;
    let v = el.type === 'checkbox' ? el.checked : el.type === 'number' || el.type === 'range' ? Number(el.value) : el.value;
    if (el.dataset.nullable !== undefined && v === '') v = null;
    Z.set(ed.job, path, v);
    if (path === 'name') {
      if (!ed.idTouched && !ed.originalId) {
        ed.job.id = slug(v);
        const idEl = Z.$('#ed-id', ed.layer.el);
        if (idEl) idEl.value = ed.job.id;
      }
      Z.refresh('edTitle');
    }
    if (path === 'id') ed.idTouched = true;
    (el.dataset.refresh || '').split(',').filter(Boolean).forEach((r) => Z.refresh(r));
    if (el.dataset.out) {
      const o = Z.$(`#${el.dataset.out}`, ed.layer.el);
      if (o) o.textContent = el.value;
    }
    if (path === 'target.path') debCheck();
  };
  Z.refreshers.edTitle = () => esc(ed?.job.name || '');

  function slug(s) {
    return (
      String(s)
        .toLowerCase()
        .replace(/ä/g, 'ae')
        .replace(/ö/g, 'oe')
        .replace(/ü/g, 'ue')
        .replace(/ß/g, 'ss')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40) || ''
    );
  }

  /* =================================================================== */
  /* Schritte                                                             */
  /* =================================================================== */
  const RENDER = {};
  const AFTER = {};

  const card = (opts) => `<button type="button" class="ed-card${opts.on ? ' on' : ''}${opts.tone ? ' tone-' + opts.tone : ''}" data-act="${opts.act}" data-value="${esc(opts.value)}"${opts.disabled ? ' disabled' : ''}>
      <span class="ec-ic">${icon(opts.icon)}</span>
      <span class="ec-t"><b>${esc(opts.title)}${opts.tag ? ` <em>${esc(opts.tag)}</em>` : ''}</b><small>${opts.text}</small></span>
      <span class="ec-radio"></span></button>`;

  /* ---------- 1. Quelle ---------- */
  RENDER.source = () => {
    const j = ed.job;
    const dss = Z.state.datasets || [];
    const sel = dss.find((d) => d.name === j.source.dataset);
    const children = sel ? dss.filter((d) => d.name.startsWith(sel.name + '/')) : [];
    return `<h2 class="ed-h">${icon('database')}Was soll gesichert werden?</h2>
      <p class="ed-lead">Wähle ein Dataset. Kind-Datasets werden einzeln gesichert und lassen sich gezielt ausschließen.</p>
      <div class="ed-cols">
        <div class="ed-pick">
          <label class="zbg-search">${icon('search')}<input type="search" placeholder="Dataset filtern …" data-input="edDsFilter" value="${esc(ed.dsFilter || '')}"></label>
          <div class="ds-tree big" data-region="edDsTree">${dsPickTree()}</div>
        </div>
        <div class="ed-detail">
          ${
            sel
              ? `<div class="src-card">
              <div class="src-h">${icon(sel.boot ? 'server' : 'database')}<div><b class="mono">${esc(sel.name)}</b><small class="mono">${esc(sel.path || 'mountpoint=' + sel.mountpoint)}</small></div><span class="grow"></span>${Z.dsFlags(sel, 3)}</div>
              <div class="src-stats"><div><span>Belegt</span><b>${fmt.bytes(sel.used)}</b></div><div><span>Snapshots</span><b>${fmt.bytes(sel.usedSnap)}</b></div><div><span>Kompression</span><b>${sel.compressratio}×</b></div><div><span>Verschlüsselt</span><b>${sel.encrypted ? (sel.locked ? 'gesperrt' : 'ja') : 'nein'}</b></div></div>
              ${(sel.issues || []).filter((i) => i.level !== 'info' || i.code === 'boot').map((i) => `<div class="zbg-callout sm tone-${i.level === 'info' ? 'info' : i.level === 'block' ? 'err' : 'warn'}">${icon(i.level === 'info' ? 'info' : 'alert')}<div>${esc(i.msg)}</div></div>`).join('')}
            </div>
            ${
              children.length
                ? `<div class="ed-sub"><div class="sub-h"><h4>Kind-Datasets <small>${children.length}</small></h4>${ui.toggle('source.recursive', j.source.recursive, 'Rekursiv sichern')}</div>
                <div class="child-list${j.source.recursive ? '' : ' off'}">${children
                  .map((c) => {
                    const ex = j.source.exclude.includes(c.name);
                    const byParent = !ex && j.source.exclude.some((e) => c.name.startsWith(e + '/'));
                    const depth = c.name.split('/').length - sel.name.split('/').length;
                    return `<label class="child${byParent ? ' inherited' : ''}" style="--d:${depth - 1}" title="${esc((c.issues || []).map((i) => i.msg).join(' · '))}"><input type="checkbox" data-act="edExclude" data-ds="${esc(c.name)}"${ex || byParent ? '' : ' checked'}${j.source.recursive && !byParent ? '' : ' disabled'}>
                      <span class="mono">${esc(c.name.slice(sel.name.length + 1))}</span>${Z.dsFlags(c, 2)}<span class="sz">${fmt.bytes(c.used)}</span></label>`;
                  })
                  .join('')}</div>
                <small class="muted">Abgewählte Kinder samt ihren Unter-Datasets werden weder gesnapshottet noch kopiert.</small></div>`
                : ''
            }
            <div class="ed-sub plan"><div class="sub-h"><h4>${icon('layers')}So wird verarbeitet</h4><small class="muted">pro Dataset aus dem eigenen Snapshot</small></div><div data-region="edPlan">${planHtml()}</div></div>`
              : `<div class="ed-placeholder">${icon('arrow')}<span>Links ein Dataset auswählen</span></div>`
          }
          <div class="ed-fields">
            <label class="zbg-field"><span>Name des Jobs</span><input class="zbg-input" data-bind="name" value="${esc(j.name)}" placeholder="z. B. Appdata → Array" ${sel ? 'autofocus' : ''}></label>
            <label class="zbg-field"><span>ID</span><input class="zbg-input mono" id="ed-id" data-bind="id" value="${esc(j.id)}" ${ed.originalId ? 'title="Ändern der ID trennt bestehende Snapshots vom Job"' : ''}><small class="muted">Snapshot-Präfix <code>zbg_&lt;id&gt;_</code></small></label>
          </div>
        </div>
      </div>`;
  };
  function dsPickTree() {
    const q = (ed.dsFilter || '').toLowerCase();
    const dss = (Z.state.datasets || []).filter((d) => !q || d.name.toLowerCase().includes(q));
    if (!dss.length) return `<p class="muted pad">Keine Datasets gefunden.</p>`;
    return Z.dsTree(dss, ed.job.source.dataset, 'edPickSource');
  }
  Z.refreshers.edDsTree = () => dsPickTree();
  Z.actions.edDsFilter = (el) => {
    ed.dsFilter = el.value;
    Z.refresh('edDsTree');
  };
  Z.actions.edPickSource = (el) => {
    const j = ed.job;
    const ds = el.dataset.ds;
    j.source.dataset = ds;
    j.source.exclude = [];
    const leaf = ds.split('/').pop();
    if (!j.name.trim() || ed.autoName) {
      j.name = `${leaf.charAt(0).toUpperCase() + leaf.slice(1)} → Array`;
      ed.autoName = true;
      if (!ed.idTouched && !ed.originalId) j.id = slug(leaf);
    }
    if (!j.target.path || ed.autoTarget) {
      j.target.path = `/mnt/user/backup/zfs/${leaf.replace(/\s+/g, '-')}`;
      ed.autoTarget = true;
    }
    renderStep();
    Z.refresh('edTitle');
  };
  Z.actions.edExclude = (el) => {
    const ex = new Set(ed.job.source.exclude);
    el.checked ? ex.delete(el.dataset.ds) : ex.add(el.dataset.ds);
    // Ausschluss gilt für den ganzen Teilbaum: tiefere Einträge sind dann überflüssig
    ed.job.source.exclude = [...ex].filter((e) => ![...ex].some((o) => o !== e && e.startsWith(o + '/')));
    renderStep();
  };

  /* Verarbeitungsplan vom Server (Sonderfälle: nicht gemountet, zvol, abweichende Mountpoints …) */
  async function loadPlan() {
    const j = ed?.job;
    if (!j || !j.source.dataset) return;
    const key = JSON.stringify([j.source.dataset, j.source.recursive, j.source.exclude]);
    if (ed.planKey === key && ed.plan) return Z.refresh('edPlan');
    ed.planKey = key;
    ed.plan = null;
    Z.refresh('edPlan');
    try {
      const p = await Z.api('source.plan', { dataset: j.source.dataset, recursive: j.source.recursive, exclude: j.source.exclude });
      if (ed && ed.planKey === key) ed.plan = p;
    } catch (e) {
      if (ed) ed.plan = { error: e.message };
    }
    Z.refresh('edPlan');
  }
  AFTER.source = () => {
    // Nur die Baumliste scrollen, nicht den ganzen Dialog
    const tree = Z.$('.ds-tree.big', ed.layer.el);
    const on = tree && Z.$('.ds-item.on', tree);
    if (on && (on.offsetTop < tree.scrollTop || on.offsetTop > tree.scrollTop + tree.clientHeight - 30)) tree.scrollTop = on.offsetTop - tree.clientHeight / 2;
    loadPlan();
  };
  function planHtml() {
    const p = ed?.plan;
    if (!ed?.job.source.dataset) return '';
    if (!p) return ui.skeleton(3);
    if (p.error) return `<div class="chk bad">${icon('x')}${esc(p.error)}</div>`;
    const ACT = { copy: ['Kopie', 'ok', 'check'], 'snapshot-only': ['nur Snapshot', 'warn', 'camera'] };
    const rows = p.items
      .map((it) => {
        const [l, t, ic] = ACT[it.action] || ACT['snapshot-only'];
        return `<div class="plan-row"><span class="pl-a tone-${t}">${icon(ic)}${l}</span><span class="mono pl-n">${esc(it.rel || '(Quelle)')}</span>
          <span class="mono pl-t">${it.targetRel !== null ? '→ ./' + esc(it.targetRel) : '—'}</span>
          <span class="pl-f">${it.protect.length ? `<span class="ds-flag tone-info" title="Beim Kopieren ausgeklammert (eigene Datasets/Mounts): ${esc(it.protect.join(', '))}">${it.protect.length} Mount${it.protect.length > 1 ? 's' : ''} geschützt</span>` : ''}${Z.dsFlags(it, 2)}</span></div>`;
      })
      .join('');
    const warn = p.warnings.map((w) => `<div class="chk ${w.level === 'block' ? 'bad' : w.level === 'warn' ? 'warn' : 'muted'}">${icon(w.level === 'info' ? 'info' : 'alert')}${esc(w.msg)}</div>`).join('');
    return `${warn}<div class="plan-list">${rows}</div>${p.skipped.length ? `<small class="muted">Ausgeschlossen: ${p.skipped.map((x) => esc(x.name)).join(', ')}</small>` : ''}`;
  }
  Z.refreshers.edPlan = () => planHtml();
  // Rekursiv-Schalter blendet die Kinderliste aus
  const _onBind = Z.onBind;
  Z.onBind = function (el, ev) {
    _onBind(el, ev);
    if (ed && el.dataset.bind === 'source.recursive') renderStep();
    if (ed && el.dataset.bind === 'name') ed.autoName = false;
    if (ed && el.dataset.bind === 'target.path') ed.autoTarget = false;
  };

  /* ---------- 2. Ziel ---------- */
  RENDER.target = () => {
    const j = ed.job;
    const t = j.target;
    const leaf = (j.source.dataset || 'daten').split('/').pop().replace(/\s+/g, '-');
    const suggestions = [`/mnt/user/backup/zfs/${leaf}`, `/mnt/user/backup/${leaf}`, `/mnt/disk1/backup/${leaf}`];
    const down = ed.check?.downstream?.[0] || null;
    const otherPools = (Z.state.pools || []).map((p) => p.name).filter((p) => p !== (j.source.dataset || '').split('/')[0]);
    return `<h2 class="ed-h">${icon('drive')}Wohin wird gesichert?</h2>
      <p class="ed-lead">${
        down
          ? `Dieses Ziel sichert <b>${esc(down.tool)}</b> bereits außer Haus (Plan „${esc(down.name)}“). Ein <b>Spiegel</b> mit stabilem Pfad genügt, die Historie liegt in ${esc(down.tool)} und in den lokalen Snapshots.`
          : 'Für das XFS-Array ist <b>rsync, versioniert</b> ideal. Für einen zweiten ZFS-Pool oder Server ist <b>zfs send</b> schneller und blockgenau.'
      }</p>
      <div class="ed-cards two">
        ${card({ act: 'edMethod', value: 'rsync', on: j.method === 'rsync', icon: 'folder', title: 'rsync', tag: 'Dateien', text: 'Kopiert aus dem Snapshot in einen Ordner. Funktioniert mit jedem Dateisystem, auch mit dem Array.' })}
        ${card({ act: 'edMethod', value: 'zfs', on: j.method === 'zfs', icon: 'layers', title: 'zfs send', tag: 'Blöcke', text: 'Inkrementelle Replikation auf einen ZFS-Pool, lokal oder per SSH. Fortsetzbar und mit erhaltenen Snapshots.' })}
      </div>
      ${
        j.method === 'rsync'
          ? `<h4 class="ed-h4">Modus</h4>
        <div class="ed-cards two">
          ${
            down
              ? card({ act: 'edMode', value: 'mirror', on: t.mode === 'mirror', icon: 'copy', title: 'Spiegel', tag: `empfohlen mit ${down.tool}`, tone: 'ok', text: `Stabiler Pfad mit dem aktuellen Stand. Die Versionierung übernimmt ${esc(down.tool)} („${esc(down.name)}“, ${esc(fmt.retention(down.retention))}). Ältere Stände gibt es zusätzlich als lokale ZFS-Snapshots.` }) +
                card({ act: 'edMode', value: 'versioned', on: t.mode === 'versioned', icon: 'history', title: 'Versioniert', tag: 'zusätzlich lokal', text: `Versionen auch auf dem Array. ${esc(down.tool)} sichert dann nur <code>current/</code>, die Include-Pfade müssen angepasst werden.` })
              : card({ act: 'edMode', value: 'versioned', on: t.mode === 'versioned', icon: 'history', title: 'Versioniert', tag: 'empfohlen', tone: 'ok', text: 'Jeder Lauf wird ein eigener Ordner. Unveränderte Dateien sind Hardlinks und kosten kaum Platz. Gelöschte oder verschlüsselte Dateien bleiben in älteren Versionen erhalten.' }) +
                card({ act: 'edMode', value: 'mirror', on: t.mode === 'mirror', icon: 'copy', title: 'Spiegel', tag: '--delete', tone: 'warn', text: 'Genau eine Kopie. Löschungen und Ransomware-Schäden landen beim nächsten Lauf auch im Backup, sofern kein nachgelagertes Backup wie Zerobyte die Versionen hält.' })
          }
        </div>
        <label class="zbg-field"><span>Zielordner</span><input class="zbg-input mono big" data-bind="target.path" value="${esc(t.path)}" placeholder="/mnt/user/backup/zfs/${esc(leaf)}" spellcheck="false"></label>
        <div class="chips">${suggestions.map((s) => `<button type="button" class="chip" data-act="edSuggest" data-value="${esc(s)}">${esc(s)}</button>`).join('')}</div>
        ${t.mode === 'versioned' ? `<div class="ed-tree-prev mono"><b>${esc(t.path || '/mnt/…')}/</b><span>├─ current/ <i>⇢ immer der neueste Stand, stabiler Pfad (z. B. für Zerobyte)</i></span><span>└─ versions/</span><span>&nbsp;&nbsp;&nbsp;├─ 2026-10-12_020000/ <i>⇢ Hardlinks auf unveränderte Dateien</i></span><span>&nbsp;&nbsp;&nbsp;└─ 2026-10-13_020000/</span></div>` : ''}
        <div class="ed-checks" data-region="edCheck">${checkHtml()}</div>`
          : `<label class="zbg-field"><span>Ziel-Dataset</span><input class="zbg-input mono big" data-bind="target.dataset" value="${esc(t.dataset)}" placeholder="${esc((otherPools[0] || 'backup') + '/zbg/' + leaf)}" list="ed-ds-list" spellcheck="false"></label>
        <datalist id="ed-ds-list">${(Z.state.datasets || []).filter((d) => !d.name.startsWith(j.source.dataset)).map((d) => `<option value="${esc(d.name)}/${esc(leaf)}">`).join('')}</datalist>
        <div class="zbg-callout tone-info">${icon('info')}<div>Empfang mit <code>zfs receive -s -u</code>: fortsetzbar und nicht automatisch gemountet. Verschlüsselte Quellen werden roh (<code>-w</code>) übertragen. Ein erzwungener Empfang (<code>-F</code>) findet nie statt.</div></div>`
      }
      <div class="ed-sub">
        <div class="sub-h"><h4>${icon('cloud')}Remote per SSH</h4>${`<label class="zbg-switch"><input type="checkbox" data-act="edSsh"${t.ssh ? ' checked' : ''}><span class="track"><span class="thumb"></span></span><span class="lbl">${t.ssh ? 'aktiv' : 'aus'}</span></label>`}</div>
        ${
          t.ssh
            ? `<div class="ed-grid4">
            <label class="zbg-field"><span>Host</span><input class="zbg-input mono" data-bind="target.ssh.host" value="${esc(t.ssh.host)}" placeholder="backup.example.org"></label>
            <label class="zbg-field"><span>Benutzer</span><input class="zbg-input mono" data-bind="target.ssh.user" value="${esc(t.ssh.user)}"></label>
            <label class="zbg-field"><span>Port</span><input class="zbg-input" type="number" data-bind="target.ssh.port" value="${esc(t.ssh.port)}"></label>
            <label class="zbg-field"><span>Schlüsseldatei</span><input class="zbg-input mono" data-bind="target.ssh.key" data-nullable value="${esc(t.ssh.key || '')}" placeholder="/root/.ssh/id_ed25519"></label>
          </div><small class="muted">Der Schlüssel wird nur als Pfad referenziert und nie gespeichert oder angezeigt.</small>`
            : `<small class="muted">Für eine dritte Kopie außer Haus (3-2-1), z. B. auf einen zweiten Server oder eine Hetzner Storage Box.</small>`
        }
      </div>`;
  };
  AFTER.target = () => {
    if (ed.job.method === 'rsync' && ed.job.target.path && !ed.job.target.ssh && ed.check?.path !== ed.job.target.path) checkTarget();
  };
  Z.actions.edMethod = (el) => {
    ed.job.method = el.dataset.value;
    renderStep();
  };
  Z.actions.edMode = (el) => {
    ed.job.target.mode = el.dataset.value;
    renderStep();
  };
  Z.actions.edSuggest = (el) => {
    ed.job.target.path = el.dataset.value;
    ed.autoTarget = false;
    renderStep();
  };
  Z.actions.edSsh = (el) => {
    ed.job.target.ssh = el.checked ? { host: '', user: 'root', port: 22, key: '/root/.ssh/id_ed25519' } : null;
    renderStep();
  };

  async function checkTarget() {
    if (!ed || ed.job.method !== 'rsync') return;
    const path = ed.job.target.path;
    if (!/^\/mnt\/[^/]+\/.+/.test(path)) {
      ed.check = { error: 'Pfad muss unter /mnt/<share>/ liegen' };
      return Z.refresh('edCheck');
    }
    if (ed.job.target.ssh) {
      ed.check = null;
      return Z.refresh('edCheck');
    }
    ed.check = { loading: true };
    Z.refresh('edCheck');
    try {
      ed.check = await Z.api('target.check', { path, source: ed.job.source.dataset });
    } catch (e) {
      ed.check = { error: e.message };
    }
    if (ed && ed.job.target.path === path) {
      const hadDown = Z.$('.ed-card em', ed.layer.el)?.textContent.includes('mit ');
      const hasDown = !!ed.check?.downstream?.length;
      if (STEPS[ed.step].key === 'target' && hadDown !== hasDown) renderStepKeepFocus();
      else Z.refresh('edCheck');
    }
  }
  const debCheck = Z.debounce(checkTarget, 450);
  function checkHtml() {
    const c = ed.check;
    if (ed.job.target.ssh) return `<div class="chk muted">${icon('cloud')}Remote-Ziel: wird beim Probelauf geprüft</div>`;
    if (!c) return '';
    if (c.loading) return `<div class="chk muted">${icon('refresh', 'spin')}Prüfe Ziel …</div>`;
    if (c.error) return `<div class="chk bad">${icon('x')}${esc(c.error)}</div>`;
    return (
      c.checks.map((k) => `<div class="chk ${k.info ? 'info' : k.ok ? 'good' : k.warn ? 'warn' : 'bad'}">${icon(k.info ? 'cloud' : k.ok ? 'check' : k.warn ? 'alert' : 'x')}${esc(k.label)}</div>`).join('') +
      (c.exists ? '' : `<div class="chk muted">${icon('info')}Ordner existiert noch nicht und wird beim ersten Lauf angelegt</div>`)
    );
  }
  Z.refreshers.edCheck = () => checkHtml();

  /* ---------- 3. Zeitplan ---------- */
  const PRESETS = [
    ['0 * * * *', 'Stündlich', 'zap'],
    ['0 */6 * * *', 'Alle 6 Stunden', 'clock'],
    ['0 2 * * *', 'Täglich 02:00', 'calendar'],
    ['30 3 * * *', 'Täglich 03:30', 'calendar'],
    ['0 3 * * 0', 'Sonntags 03:00', 'calendar'],
    ['0 4 1 * *', 'Monatlich am 1.', 'calendar'],
  ];
  RENDER.schedule = () => {
    const j = ed.job;
    return `<h2 class="ed-h">${icon('calendar')}Wann soll gesichert werden?</h2>
      <p class="ed-lead">Ein Snapshot dauert Millisekunden. Die Kopie läuft danach aus dem Snapshot, deshalb sind nächtliche Läufe ideal.</p>
      <div class="preset-grid">${PRESETS.map(([c, l, ic]) => `<button type="button" class="preset${j.schedule === c ? ' on' : ''}" data-act="edPreset" data-value="${c}">${icon(ic)}<b>${l}</b><code>${c}</code></button>`).join('')}</div>
      <div class="cron-box">
        <label class="zbg-field"><span>Cron-Ausdruck <small>Minute · Stunde · Tag · Monat · Wochentag</small></span>
        <input class="zbg-input mono big cron" data-bind="schedule" data-refresh="edCron" value="${esc(j.schedule)}" spellcheck="false"></label>
        <div data-region="edCron">${cronPreview()}</div>
      </div>
      <div class="zbg-callout tone-info">${icon('info')}<div>Der Zeitplan wird als <code>/boot/config/plugins/zfs-backup-guard/zfs-backup-guard.cron</code> registriert. Das ist der Standardweg für Unraid-Plugins (aktiv ab Phase 3).</div></div>`;
  };
  function cronPreview() {
    const s = ed.job.schedule;
    if (!Z.cron.valid(s)) return `<div class="cron-prev bad">${icon('x')}Ungültiger Ausdruck</div>`;
    const next = Z.cron.nextN(s, 4);
    return `<div class="cron-prev"><div class="cp-desc">${icon('check')}<b>${esc(Z.cron.describe(s))}</b></div>
      <div class="cp-next">${next.map((t, i) => `<span class="${i ? '' : 'first'}">${i ? '' : 'Nächster Lauf: '}${esc(fmt.when(t))}</span>`).join('')}</div></div>`;
  }
  Z.refreshers.edCron = () => {
    Z.$$('.preset', ed?.layer.el).forEach((p) => p.classList.toggle('on', p.dataset.value === ed.job.schedule));
    return cronPreview();
  };
  Z.actions.edPreset = (el) => {
    ed.job.schedule = el.dataset.value;
    renderStep();
  };

  /* ---------- 4. Aufbewahrung ---------- */
  const SNAP_MAX = { hourly: 72, daily: 60, weekly: 52, monthly: 36, yearly: 10 };
  const RET_PRESETS = [
    ['Sparsam', { hourly: 0, daily: 7, weekly: 4, monthly: 3, yearly: 0 }],
    ['Ausgewogen', { hourly: 24, daily: 7, weekly: 4, monthly: 3, yearly: 0 }],
    ['Großzügig', { hourly: 24, daily: 14, weekly: 8, monthly: 12, yearly: 3 }],
  ];
  RENDER.retention = () => {
    const j = ed.job;
    const k = j.snapshots.keep;
    const v = j.versions.keep;
    const versioned = j.method === 'rsync' && j.target.mode === 'versioned';
    return `<h2 class="ed-h">${icon('layers')}Wie lange wird aufbewahrt?</h2>
      <p class="ed-lead">Gestaffelte Aufbewahrung: Pro Stunde, Tag, Woche, Monat und Jahr bleibt jeweils der neueste Snapshot erhalten. Aufgeräumt wird nur nach einem erfolgreichen Lauf, und der neueste Snapshot bleibt immer.</p>
      <div class="ret-presets">${RET_PRESETS.map(([l, p]) => `<button type="button" class="chip${JSON.stringify(p) === JSON.stringify(k) ? ' on' : ''}" data-act="edRetPreset" data-value="${esc(JSON.stringify(p))}">${l}</button>`).join('')}</div>
      <div class="ret-wrap">
        <div class="ret-sliders">
          <h4 class="ed-h4">${icon('camera')}Lokale Snapshots <small>auf ${esc((j.source.dataset || 'cache').split('/')[0])}</small></h4>
          ${Z.retention.LEVELS.map((l) => slider(`snapshots.keep.${l}`, Z.retention.LABEL[l], k[l], SNAP_MAX[l], l)).join('')}
        </div>
        <div class="ret-preview" data-region="edRet">${retPreview()}</div>
      </div>
      ${
        versioned
          ? `<div class="ed-sub"><h4 class="ed-h4">${icon('history')}Versionen am Ziel <small>${esc(j.target.path)}</small></h4>
          <div class="ret-sliders inline">${['daily', 'weekly', 'monthly'].map((l) => slider(`versions.keep.${l}`, Z.retention.LABEL[l], v[l], SNAP_MAX[l], l, 'edVer')).join('')}</div>
          <div data-region="edVer">${verPreview()}</div></div>`
          : j.method === 'zfs'
          ? `<div class="zbg-callout tone-info">${icon('info')}<div>Auf dem Ziel gilt dieselbe Staffelung. Der letzte gemeinsame Snapshot bleibt immer erhalten, damit inkrementelle Übertragungen möglich bleiben.</div></div>`
          : `<div class="zbg-callout tone-warn">${icon('alert')}<div>Im Spiegel-Modus gibt es am Ziel nur eine Version. Ältere Stände existieren dann ausschließlich als lokale Snapshots auf dem Pool.</div></div>`
      }`;
  };
  function slider(bind, label, val, max, lvl, refresh = 'edRet') {
    const id = 'o' + bind.replace(/\W/g, '');
    return `<label class="slider lvl-${lvl}"><span class="sl-l"><i></i>${esc(label)}</span>
      <input type="range" min="0" max="${max}" value="${val}" data-bind="${bind}" data-refresh="${refresh}" data-out="${id}" style="--p:${(val / max) * 100}%">
      <output id="${id}">${val}</output></label>`;
  }
  // Füllstand der Slider aktualisieren
  document.addEventListener('input', (ev) => {
    if (ev.target.matches && ev.target.matches('.zbg input[type=range]')) ev.target.style.setProperty('--p', (ev.target.value / ev.target.max) * 100 + '%');
  });
  function retPreview() {
    const j = ed.job;
    if (!Z.cron.valid(j.schedule)) return '';
    const sim = Z.retention.simulate(j.schedule, j.snapshots.keep);
    const now = sim.now;
    const span = Math.max(sim.oldest ? now - sim.oldest : 0, 86400 * 14) * 1.05;
    const x = (t) => 100 - Math.sqrt((now - t) / span) * 100;
    const LV = ['yearly', 'monthly', 'weekly', 'daily', 'hourly', 'newest'];
    const dots = sim.kept
      .map((s) => {
        const top = LV.find((l) => s.reasons.includes(l)) || 'newest';
        return `<i class="lvl-${top}" style="left:${x(s.time).toFixed(2)}%" title="${esc(fmt.dateFull(s.time))} · ${s.reasons.map((r) => Z.retention.LABEL[r] || 'neuester').join(', ')}"></i>`;
      })
      .join('');
    const ticks = [
      [0, 'jetzt'],
      [86400, '1 T'],
      [86400 * 7, '1 W'],
      [86400 * 30, '1 M'],
      [86400 * 91, '3 M'],
      [86400 * 182, '6 M'],
      [86400 * 365, '1 J'],
      [86400 * 730, '2 J'],
    ].filter(([d]) => d <= span);
    const ageDays = sim.oldest ? (now - sim.oldest) / 86400 : 0;
    const age = ageDays >= 60 ? `~${Math.round(ageDays / 30)} Monate` : ageDays >= 14 ? `~${Math.round(ageDays / 7)} Wochen` : ageDays >= 1 ? `~${Math.round(ageDays)} Tage` : `~${Math.round(ageDays * 24)} Stunden`;
    const src = Z.state.datasets?.find((d) => d.name === j.source.dataset);
    const estDelta = src ? src.used * 0.012 : 0; // grobe Annahme ~1,2 % Änderung pro Lauf
    return `<div class="ret-big"><div><b>≈ ${sim.kept.length}</b><span>Snapshots</span></div><div><b>${age}</b><span>ältester</span></div>${src ? `<div><b>~${fmt.bytes(estDelta * Math.min(sim.kept.length, 40))}</b><span>Platz (Schätzung)</span></div>` : ''}</div>
      <div class="ret-tl"><div class="tl-track">${dots}</div><div class="tl-ticks">${ticks.map(([d, l]) => `<span style="left:${x(now - d).toFixed(2)}%">${l}</span>`).join('')}</div></div>
      <div class="ret-legend">${['hourly', 'daily', 'weekly', 'monthly', 'yearly'].map((l) => `<span class="lvl-${l}"><i></i>${Z.retention.LABEL[l]}</span>`).join('')}</div>
      <small class="muted">Simulation nach 2 Jahren Betrieb mit „${esc(Z.cron.describe(j.schedule))}“.${j.snapshots.keep.hourly && !/^\S+ (\*|\*\/\d+)/.test(j.schedule) ? ' <b>Hinweis:</b> Stündliche Stufe wirkt nur bei mehreren Läufen pro Tag.' : ''}</small>`;
  }
  Z.refreshers.edRet = () => {
    Z.$$('.ret-presets .chip', ed.layer.el).forEach((c) => c.classList.toggle('on', c.dataset.value === JSON.stringify(ed.job.snapshots.keep)));
    return retPreview();
  };
  function verPreview() {
    const v = ed.job.versions.keep;
    const sim = Z.retention.simulate(ed.job.schedule, { ...v, hourly: 0, yearly: 0 });
    const ageDays = sim.oldest ? (sim.now - sim.oldest) / 86400 : 0;
    return `<p class="ver-sum">${icon('history')}<span>≈ <b>${sim.kept.length}</b> Versionsordner, die älteste ist ~<b>${ageDays >= 60 ? Math.round(ageDays / 30) + ' Monate' : Math.round(ageDays) + ' Tage'}</b> alt. Hardlinks machen unveränderte Dateien praktisch kostenlos.</span></p>`;
  }
  Z.refreshers.edVer = () => verPreview();
  Z.actions.edRetPreset = (el) => {
    ed.job.snapshots.keep = JSON.parse(el.dataset.value);
    renderStep();
  };

  /* ---------- 5. Konsistenz ---------- */
  RENDER.consistency = () => {
    const j = ed.job;
    const c = j.consistency;
    return `<h2 class="ed-h">${icon('box')}Konsistenz beim Snapshot</h2>
      <p class="ed-lead">Ein Snapshot friert den Zustand atomar ein. Bei laufenden Datenbanken entspricht das einem Stromausfall. Für maximale Sicherheit kannst du Container für <b>wenige Sekunden</b> anhalten. Die Kopie läuft danach aus dem Snapshot.</p>
      <div class="ed-cards three">
        ${card({ act: 'edCons', value: 'crash', on: c.mode === 'crash', icon: 'zap', title: 'Crash-konsistent', tag: 'Standard', text: 'Keine Unterbrechung. Datenbanken reparieren sich beim Start über ihr Journal.' })}
        ${card({ act: 'edCons', value: 'pause', on: c.mode === 'pause', icon: 'pause', title: 'Pausieren', tag: 'empfohlen für DBs', tone: 'ok', text: '<code>docker pause</code> friert die Prozesse während des Snapshots ein, typisch unter 2 s.' })}
        ${card({ act: 'edCons', value: 'stop', on: c.mode === 'stop', icon: 'stop', title: 'Stoppen', tag: 'am sichersten', tone: 'warn', text: '<code>docker stop</code> mit sauberem Shutdown. Der Container ist für Sekunden bis Minuten offline.' })}
      </div>
      ${
        c.mode !== 'crash'
          ? `<div class="ed-sub"><div class="sub-h"><h4>${icon('box')}Container <small>${c.containers.length} ausgewählt</small></h4>
            <div class="row">${ui.btn('Datenbanken wählen', { act: 'edPickDbs', icon: 'database', size: 'sm' })}<label class="zbg-search sm">${icon('search')}<input type="search" placeholder="Filtern …" data-input="edCtFilter" value="${esc(ed.ctFilter)}"></label></div></div>
            <div class="ct-list" data-region="edCt">${ctList()}</div></div>`
          : ''
      }
      ${(Z.state.datasets || []).some((d) => d.name === j.source.dataset && /domains/.test(d.name)) ? `<div class="zbg-callout tone-info">${icon('info')}<div>VMs auf <code>domains</code> werden crash-konsistent gesichert. Für App-Konsistenz die VM vorher per Hook herunterfahren.</div></div>` : ''}
      <details class="ed-adv"${ed.advanced ? ' open' : ''}><summary>${icon('terminal')}Hooks <small>Skripte vor und nach dem Lauf</small></summary>
        <div class="ed-grid2">
          <label class="zbg-field"><span>Vorher (pre)</span><input class="zbg-input mono" data-bind="hooks.pre" data-nullable value="${esc(j.hooks.pre || '')}" placeholder="/boot/config/plugins/zfs-backup-guard/hooks/pre.sh"></label>
          <label class="zbg-field"><span>Nachher (post)</span><input class="zbg-input mono" data-bind="hooks.post" data-nullable value="${esc(j.hooks.post || '')}" placeholder="/boot/config/…/post.sh"></label>
        </div><small class="muted">Erhalten <code>ZBG_JOB</code>, <code>ZBG_SNAPSHOT</code>, <code>ZBG_RESULT</code> als Umgebungsvariablen. Ein Fehler im pre-Hook bricht den Lauf ab.</small>
      </details>`;
  };
  AFTER.consistency = async () => {
    if (ed.job.consistency.mode === 'crash' || ed.containers) return;
    try {
      ed.containers = await Z.api('containers');
    } catch (e) {
      ed.containers = [];
      Z.fail(e);
    }
    Z.refresh('edCt');
  };
  function ctList() {
    if (!ed.containers) return ui.skeleton(4);
    if (!ed.containers.length) return `<p class="muted pad">Keine Container gefunden (läuft Docker?).</p>`;
    const q = ed.ctFilter.toLowerCase();
    const sel = new Set(ed.job.consistency.containers);
    return ed.containers
      .filter((c) => !q || c.name.toLowerCase().includes(q) || c.image.toLowerCase().includes(q))
      .map(
        (c) => `<label class="ct${sel.has(c.name) ? ' on' : ''}"><input type="checkbox" data-act="edCt" data-name="${esc(c.name)}"${sel.has(c.name) ? ' checked' : ''}>
        <span class="ct-ic">${icon(DB_RE.test(c.image + c.name) ? 'database' : 'box')}</span>
        <span class="ct-t"><b>${esc(c.name)}</b><small class="mono">${esc(c.image)}</small></span>
        ${DB_RE.test(c.image + c.name) ? ui.badge('DB', 'accent') : ''}${ui.badge(c.state === 'running' ? 'läuft' : c.state, c.state === 'running' ? 'ok' : 'idle')}</label>`
      )
      .join('');
  }
  Z.refreshers.edCt = () => ctList();
  Z.actions.edCons = (el) => {
    ed.job.consistency.mode = el.dataset.value;
    renderStep();
  };
  Z.actions.edCt = (el) => {
    const s = new Set(ed.job.consistency.containers);
    el.checked ? s.add(el.dataset.name) : s.delete(el.dataset.name);
    ed.job.consistency.containers = [...s];
    el.closest('.ct').classList.toggle('on', el.checked);
    const h = Z.$('.ed-sub .sub-h small', ed.layer.el);
    if (h) h.textContent = `${s.size} ausgewählt`;
  };
  Z.actions.edCtFilter = (el) => {
    ed.ctFilter = el.value;
    Z.refresh('edCt');
  };
  Z.actions.edPickDbs = () => {
    const dbs = (ed.containers || []).filter((c) => DB_RE.test(c.image + c.name)).map((c) => c.name);
    ed.job.consistency.containers = [...new Set([...ed.job.consistency.containers, ...dbs])];
    renderStep();
    Z.toast(`${dbs.length} Datenbank-Container ausgewählt`);
  };

  /* ---------- 6. Überwachung ---------- */
  RENDER.notify = () => {
    const j = ed.job;
    return `<h2 class="ed-h">${icon('bell')}Überwachung</h2>
      <p class="ed-lead">Ein Backup, das still ausfällt, ist kein Backup. Jeder Lauf landet im Verlauf. Zusätzlich gibt es Unraid-Benachrichtigungen und eine Altersüberwachung.</p>
      <h4 class="ed-h4">Unraid-Benachrichtigung</h4>
      ${ui.seg('notify', [{ value: 'all', label: 'Jeder Lauf', icon: 'bell' }, { value: 'errors', label: 'Nur Fehler', icon: 'alert' }, { value: 'none', label: 'Keine', icon: 'x' }], j.notify, { act: 'edNotify' })}
      <div class="ed-grid2 mt">
        <div class="zbg-field"><span>Warnen, wenn das letzte erfolgreiche Backup älter ist als</span>
          <div class="age-row"><input type="range" min="1" max="240" value="${j.maxAgeHours}" data-bind="maxAgeHours" data-out="oAge" data-refresh="edAge" style="--p:${(j.maxAgeHours / 240) * 100}%"><output id="oAge">${j.maxAgeHours}</output><span>Std.</span></div>
          <small class="muted" data-region="edAge">${ageHint()}</small></div>
        <label class="zbg-field"><span>Healthchecks.io-URL <small>(optional)</small></span><input class="zbg-input mono" data-bind="healthcheckUrl" data-nullable value="${esc(j.healthcheckUrl || '')}" placeholder="https://hc-ping.com/…">
          <small class="muted">Wird mit <code>/start</code>, bei Erfolg und mit <code>/fail</code> angepingt.</small></label>
      </div>
      <div class="ed-sub row between"><div><b>Zeitplan aktiv</b><br><small class="muted">Pausierte Jobs laufen nur manuell.</small></div>${ui.toggle('enabled', j.enabled)}</div>`;
  };
  function ageHint() {
    const j = ed.job;
    const n = Z.cron.nextN(j.schedule, 2);
    const interval = n.length === 2 ? (n[1] - n[0]) / 3600 : 24;
    return j.maxAgeHours < interval
      ? `⚠ Kürzer als das Intervall des Zeitplans (${Math.round(interval)} h): Es würde ständig gewarnt.`
      : `Bei „${Z.cron.describe(j.schedule)}“ verträgt das ${Math.max(0, Math.floor(j.maxAgeHours / interval) - 1)} ausgefallene Läufe.`;
  }
  Z.refreshers.edAge = () => ageHint();
  Z.actions.edNotify = (el) => {
    ed.job.notify = el.dataset.value;
    renderStep();
  };

  /* ---------- 7. Zusammenfassung ---------- */
  RENDER.summary = () => {
    const j = ed.job;
    const warns = [];
    const errs = STEPS.map((_, i) => stepError(i)).filter(Boolean);
    if (j.method === 'rsync' && j.target.mode === 'mirror' && !ed.check?.downstream?.length) warns.push('Spiegel-Modus ohne nachgelagertes Backup: Gelöschte Dateien verschwinden auch aus dem Backup.');
    if (ed.check && ed.check.checks) ed.check.checks.filter((c) => !c.ok).forEach((c) => warns.push(c.label));
    if (!Object.values(j.snapshots.keep).some((v) => v > 0)) warns.push('Keine Snapshot-Aufbewahrung: Es bleibt nur der jeweils neueste Snapshot.');
    if (j.notify === 'none' && !j.healthcheckUrl) warns.push('Keine Benachrichtigung: Fehler fallen nur im Verlauf auf.');
    const sim = Z.cron.valid(j.schedule) ? Z.retention.simulate(j.schedule, j.snapshots.keep) : null;
    const down = j.method === 'rsync' && !j.target.ssh ? ed.check?.downstream?.[0] : null;
    const ej = ed.originalId && Z.state.overview ? Z.findJob(ed.originalId) : null;
    const avgDur = ej && ej.runs.length ? ej.runs.reduce((x, r) => x + (r.duration || 0), 0) / ej.runs.length : null;
    const chain = down ? Z.chainCheck(j, down, avgDur) : null;
    if (chain && chain.tone !== 'ok') warns.push(chain.text);
    const row = (ic, title, value, step) => `<div class="sum-row" data-act="edGo" data-i="${step}">${icon(ic)}<div><small>${title}</small><b>${value}</b></div>${icon('edit', 'sum-edit')}</div>`;
    return `<h2 class="ed-h">${icon('check')}Alles bereit?</h2>
      <div class="sum-hero">
        <div class="sum-route">
          <div class="node">${icon('database')}<b class="mono">${esc(j.source.dataset || '—')}</b><small>${j.source.recursive ? 'rekursiv' : 'nur dieses Dataset'}${j.source.exclude.length ? ` · ${j.source.exclude.length} ausgeschlossen` : ''}</small></div>
          <div class="flow"><span>${esc(Z.methodLabel(j))}</span><i></i></div>
          <div class="node">${icon(j.target.ssh ? 'cloud' : j.method === 'zfs' ? 'layers' : 'drive')}<b class="mono">${esc(Z.targetLabel(j) || '—')}</b><small>${j.target.ssh ? 'Remote' : 'Lokal'}</small></div>
        </div>
      </div>
      ${errs.length ? `<div class="zbg-callout tone-err">${icon('x')}<div><b>Noch nicht vollständig:</b><ul>${errs.map((e) => `<li>${esc(e)}</li>`).join('')}</ul></div></div>` : ''}
      ${warns.length ? `<div class="zbg-callout tone-warn">${icon('alert')}<div><b>Hinweise</b><ul>${warns.map((w) => `<li>${esc(w)}</li>`).join('')}</ul></div></div>` : ''}
      <div class="sum-grid">
        ${row('shield', 'Job', `${esc(j.name)} <span class="mono muted">${esc(j.id)}</span>`, 0)}
        ${row('calendar', 'Zeitplan', `${esc(Z.cron.describe(j.schedule))} <span class="mono muted">${esc(j.schedule)}</span>`, 2)}
        ${row('camera', 'Snapshots', `${esc(Z.keepSummary(j))}${sim ? ` <span class="muted">≈ ${sim.kept.length} Stück</span>` : ''}`, 3)}
        ${j.method === 'rsync' && j.target.mode === 'versioned' ? row('history', 'Versionen', `${j.versions.keep.daily} T · ${j.versions.keep.weekly} W · ${j.versions.keep.monthly} M`, 3) : ''}
        ${row('box', 'Konsistenz', j.consistency.mode === 'crash' ? 'Crash-konsistent' : `${j.consistency.mode === 'pause' ? 'Pausieren' : 'Stoppen'}: ${esc(j.consistency.containers.join(', ') || 'keine Container gewählt')}`, 4)}
        ${down ? row('cloud', 'Weitersicherung (3-2-1)', `${esc(down.tool)} „${esc(down.name)}“ · ${esc(Z.cron.describe(down.cron))} <span class="${chain.tone === 'ok' ? 'muted' : 'err'}">${chain.tone === 'ok' ? '✓ Zeitfenster passt' : '⚠ Zeitfenster knapp'}</span>`, 1) : ''}
        ${row('bell', 'Überwachung', `${{ all: 'Jeder Lauf', errors: 'Nur Fehler', none: 'Keine' }[j.notify]} · Warnung nach ${j.maxAgeHours} h${j.healthcheckUrl ? ' · Healthchecks' : ''}`, 5)}
      </div>
      <div class="sum-flow"><h4 class="ed-h4">Ablauf eines Laufs</h4><ol>
        <li>Vorprüfung: Sperre, Pool ONLINE, Ziel gemountet, freier Platz</li>
        ${j.consistency.mode !== 'crash' && j.consistency.containers.length ? `<li>${j.consistency.mode === 'pause' ? 'Pausieren' : 'Stoppen'}: ${esc(j.consistency.containers.join(', '))}</li>` : ''}
        <li>Snapshot <code>${esc(j.source.dataset)}@zbg_${esc(j.id)}_JJJJMMTT-HHMMSS</code>${j.source.recursive ? ' (rekursiv, atomar)' : ''}</li>
        ${j.consistency.mode !== 'crash' && j.consistency.containers.length ? '<li>Container sofort wieder starten</li>' : ''}
        <li>${j.method === 'zfs' ? 'zfs send -I → receive -s -u' : j.target.mode === 'versioned' ? 'rsync --link-dest in neuen Versionsordner' : 'rsync --delete in den Spiegel'}</li>
        <li>Aufräumen nach Aufbewahrungsregeln (nur eigene Snapshots, nur bei Erfolg)</li>
        <li>Verlauf, ${j.notify === 'none' ? '' : 'Benachrichtigung, '}${j.healthcheckUrl ? 'Healthcheck-Ping, ' : ''}fertig</li>
        ${down ? `<li>Danach sichert ${esc(down.tool)} („${esc(down.name)}“, ${esc(Z.cron.describe(down.cron))}) das Ziel außer Haus</li>` : ''}
      </ol></div>
      <details class="ed-adv"><summary>${icon('terminal')}JSON</summary><pre class="zbg-pre">${esc(JSON.stringify(j, null, 2))}</pre></details>`;
  };

  Z.actions.edSave = async (el) => {
    const errs = STEPS.map((_, i) => stepError(i)).filter(Boolean);
    if (errs.length) return Z.toast(errs[0], 'warn');
    const btns = Z.$$('[data-act="edSave"]', ed.layer.el);
    btns.forEach((b) => (b.disabled = true));
    const wasEdit = !!ed.originalId;
    try {
      const saved = await Z.api('jobs.save', { job: ed.job, originalId: ed.originalId });
      const dry = el.dataset.dry;
      Z.closeLayer(ed.layer);
      Z.toast(wasEdit ? 'Job gespeichert' : `Job „${saved.name}“ angelegt`);
      await Z.loadOverview();
      if (dry) Z.actions.dryRun({ dataset: { id: saved.id } });
    } catch (e) {
      Z.fail(e);
      btns.forEach((b) => (b.disabled = false));
    }
  };
})();
