/* ZFS Backup Guard – Ansichten: Übersicht, Live-Lauf, Snapshots, Verlauf, Einstellungen */
(function () {
  'use strict';
  const Z = window.ZBG;
  const { esc, fmt, ui, icon } = Z;

  /* =================================================================== */
  /* Übersicht                                                            */
  /* =================================================================== */

  const jobState = (j) => (Z.state.runs[j.job.id] && Z.state.runs[j.job.id].state === 'running' ? 'running' : j.status.state);

  function methodLabel(job) {
    if (job.method === 'zfs') return job.target.ssh ? 'zfs send · SSH' : 'zfs send · lokal';
    return (job.target.mode === 'versioned' ? 'rsync · versioniert' : 'rsync · Spiegel') + (job.target.ssh ? ' · SSH' : '');
  }
  function targetLabel(job) {
    const t = job.method === 'zfs' ? job.target.dataset : job.target.path;
    return job.target.ssh ? `${job.target.ssh.user}@${job.target.ssh.host}:${t}` : t;
  }
  function keepSummary(job) {
    const k = job.snapshots.keep;
    const parts = [];
    if (k.hourly) parts.push(`${k.hourly} h`);
    if (k.daily) parts.push(`${k.daily} T`);
    if (k.weekly) parts.push(`${k.weekly} W`);
    if (k.monthly) parts.push(`${k.monthly} M`);
    if (k.yearly) parts.push(`${k.yearly} J`);
    return parts.join(' · ') || 'nur neuester';
  }
  Z.methodLabel = methodLabel;
  /** Passt das Zeitfenster zwischen diesem Job und dem nachgelagerten Backup (z. B. Zerobyte)? */
  Z.chainCheck = function (job, d, avg) {
    if (!d.enabled) return { tone: 'warn', text: `${d.tool}-Plan „${d.name}“ ist deaktiviert` };
    if (d.coverage === 'partial') return { tone: 'warn', text: `${d.tool}-Plan „${d.name}“ sichert nur einen Teil des Ziels (${d.matchedPath})` };
    const t1 = Z.cron.next(job.schedule);
    const t2 = t1 && Z.cron.next(d.cron, t1 - 1);
    if (!t1 || !t2) return { tone: 'warn', text: 'Zeitplan nicht auswertbar' };
    const gap = t2 - t1;
    const dur = avg || 1800;
    const need = dur * 1.5 + 600;
    if (gap < need)
      return { tone: 'warn', text: `${d.tool} startet ${fmt.duration(gap)} nach dem Backup, ein Lauf dauert aber Ø ${fmt.duration(dur)}. Das Zeitfenster ist zu knapp, ${d.tool} könnte einen halbfertigen Stand sichern.` };
    return { tone: 'ok', text: `${d.tool}-Plan „${d.name}“ (${Z.cron.describe(d.cron)}) startet ${fmt.duration(gap)} nach dem Backup. Das Zeitfenster passt (Ø Laufzeit ${fmt.duration(dur)}).` };
  };
  Z.targetLabel = targetLabel;
  Z.keepSummary = keepSummary;

  function hero(ov) {
    const jobs = ov.jobs;
    const counts = { ok: 0, warn: 0, err: 0, idle: 0, run: 0 };
    jobs.forEach((j) => counts[Z.STATUS[jobState(j)].tone]++);
    const active = jobs.filter((j) => j.job.enabled);
    const problems = counts.err + counts.warn;
    let title, sub, tone;
    if (!jobs.length) {
      [title, tone, sub] = ['Noch keine Jobs', 'idle', 'Lege deinen ersten Backup-Job an oder übernimm die vorhandenen User Scripts.'];
    } else if (counts.err) {
      [title, tone] = [`${counts.err} ${counts.err === 1 ? 'Job ist' : 'Jobs sind'} fehlgeschlagen`, 'err'];
    } else if (counts.warn) {
      [title, tone] = [`${counts.warn} ${counts.warn === 1 ? 'Backup ist' : 'Backups sind'} überfällig`, 'warn'];
    } else if (counts.run) {
      [title, tone] = ['Backup läuft …', 'run'];
    } else if (counts.ok) {
      [title, tone] = ['Alles geschützt', 'ok'];
    } else {
      [title, tone] = ['Bereit', 'idle'];
    }
    const lastOk = Math.max(0, ...jobs.map((j) => j.status.lastSuccess?.end || 0));
    const next = jobs.map((j) => j.nextRun).filter(Boolean).sort()[0];
    if (!sub) sub = `Letztes erfolgreiches Backup ${lastOk ? fmt.rel(lastOk) : 'noch keins'} · Nächster Lauf ${next ? fmt.when(next) : '–'}`;

    const segs = [
      { value: counts.ok, tone: 'ok' },
      { value: counts.run, tone: 'run' },
      { value: counts.warn, tone: 'warn' },
      { value: counts.err, tone: 'err' },
      { value: counts.idle, tone: 'idle' },
    ];
    const center = `<strong>${active.length ? Math.round((counts.ok / Math.max(active.length, 1)) * 100) : 0}<small>%</small></strong><span>geschützt</span>`;
    const pools = (ov.pools || [])
      .map(
        (p) => `<div class="pool">
          <div class="pool-h"><span>${icon(p.boot ? 'server' : 'database')}${esc(p.name)}${p.boot ? ' <span class="ds-flag tone-accent" title="Unraid bootet von diesem Pool">Boot</span>' : ''}</span><span class="${p.health === 'ONLINE' ? 'ok' : 'err'}">${esc(p.health)}</span></div>
          ${ui.meter(p.alloc, p.size)}
          <div class="pool-f"><span>${fmt.bytes(p.alloc)} von ${fmt.bytes(p.size)}</span><b>${fmt.pct(p.capacity)}</b></div>
        </div>`
      )
      .join('');
    const snapCount = jobs.reduce((s, j) => s + (j.snapshotCount || 0), 0);
    const protectedBytes = jobs.reduce((s, j) => s + (j.sourceBytes || 0), 0);

    return `<section class="zbg-hero tone-${tone}">
      <div class="hero-aura"></div>
      <div class="hero-main">
        ${ui.ring(segs, { center, size: 148, stroke: 13 })}
        <div class="hero-text">
          <div class="hero-kicker">${icon(Z.STATUS[tone === 'err' ? 'failed' : tone === 'warn' ? 'stale' : tone === 'run' ? 'running' : 'ok'].icon)} Systemstatus</div>
          <h1>${esc(title)}</h1>
          <p>${esc(sub)}</p>
          <div class="hero-chips">
            ${ui.badge(`${counts.ok} geschützt`, 'ok')}
            ${counts.run ? ui.badge(`${counts.run} läuft`, 'run') : ''}
            ${counts.warn ? ui.badge(`${counts.warn} überfällig`, 'warn') : ''}
            ${counts.err ? ui.badge(`${counts.err} Fehler`, 'err') : ''}
            ${counts.idle ? ui.badge(`${counts.idle} inaktiv`, 'idle') : ''}
          </div>
        </div>
      </div>
      <div class="hero-side">
        <div class="hero-stats">
          <div class="stat"><span>${icon('layers')}Jobs</span><b>${active.length}<small>/${jobs.length}</small></b></div>
          <div class="stat"><span>${icon('camera')}Snapshots</span><b>${snapCount ? fmt.num(snapCount) : '–'}</b></div>
          <div class="stat"><span>${icon('shield')}Geschützt</span><b>${protectedBytes ? fmt.bytes(protectedBytes) : '–'}</b></div>
        </div>
        <div class="hero-pools">${pools}</div>
      </div>
    </section>`;
  }

  function migrationBanner(ov) {
    if (!ov.migration || !ov.migration.length || Z.state.hideMigration) return '';
    const n = ov.migration.reduce((s, m) => s + m.jobs, 0);
    const names = ov.migration.map((m) => `„${esc(m.script)}“`).join(', ');
    return `<section class="zbg-banner">
      <div class="bn-ic">${icon('sparkles')}</div>
      <div class="bn-text"><b>User-Script-Backups erkannt</b><span>${names}: ${n} ${n === 1 ? 'Job kann' : 'Jobs können'} geprüft übernommen werden. Importierte Jobs bleiben zunächst pausiert. Bestehende User-Script-Zeitpläne werden nicht verändert.</span></div>
      <div class="bn-act">${ui.btn('Übernehmen', { act: 'openMigration', kind: 'primary', icon: 'download' })}${ui.btn('', { act: 'hideMigration', icon: 'x', title: 'Ausblenden' })}</div>
    </section>`;
  }

  function jobCard(j, ov) {
    const job = j.job;
    const st = jobState(j);
    const meta = Z.STATUS[st];
    const run = Z.state.runs[job.id];
    const avg = j.runs.length ? j.runs.reduce((s, r) => s + (r.duration || 0), 0) / j.runs.length : null;
    const last = j.status.lastSuccess;
    const canRun = ov.runnerAvailable;
    const noRun = 'Runner ist nicht verfügbar';
    const cls = run && run.state === 'running' ? 'running' : '';
    return `<article class="zbg-job tone-${meta.tone} ${cls}" data-job="${esc(job.id)}" style="--i:${ov.jobs.indexOf(j)}">
      <header>
        <div class="job-ic">${icon(meta.icon, st === 'running' ? 'spin' : '')}</div>
        <div class="job-title">
          <h3>${esc(job.name)}</h3>
          <div class="job-sub">${ui.badge(meta.label, meta.tone)}<span class="mono">${esc(methodLabel(job))}</span></div>
        </div>
        <div class="job-menu">
          ${ui.btn('', { act: 'editJob', icon: 'edit', title: 'Bearbeiten', data: { id: job.id } })}
          ${ui.btn('', { act: 'jobMenu', icon: 'more', title: 'Weitere Aktionen', data: { id: job.id } })}
        </div>
      </header>
      <div class="job-route" title="${esc(job.source.dataset)} → ${esc(targetLabel(job))}">
        <span class="node">${icon('database')}<span>${esc(job.source.dataset)}</span>${job.source.recursive ? '<em>+ Kinder</em>' : ''}</span>
        <span class="flow"><i></i>${icon('arrow')}</span>
        <span class="node">${icon(job.target.ssh ? 'cloud' : job.method === 'zfs' ? 'layers' : 'drive')}<span>${esc(targetLabel(job))}</span></span>
        ${
          j.downstream && !j.downstream.length && job.method === 'rsync' && !job.target.ssh
            ? `<span class="flow"></span><span class="node ds-down tone-idle" title="Kein Zerobyte-Plan erfasst dieses Ziel – keine Kopie außer Haus (3-2-1)">${icon('cloud')}<span>keine Weitersicherung erkannt</span></span>`
            : ''
        }
        ${(j.downstream || [])
          .slice(0, 1)
          .map((d) => {
            const chk = Z.chainCheck(job, d, avg);
            return `<span class="flow"></span><span class="node ds-down tone-${chk.tone}" title="${esc(chk.text)}">${icon('cloud')}<span>${esc(d.tool)} „${esc(d.name)}“ · ${esc(Z.cron.describe(d.cron).replace(/^Täglich um /, 'tägl. '))}${chk.tone === 'ok' ? '' : ' ⚠'}</span></span>`;
          })
          .join('')}
      </div>
      <div class="job-stats">
        <div><span>Letztes Backup</span><b title="${esc(fmt.dateFull(last?.end))}">${last ? esc(fmt.rel(last.end)) : '—'}</b></div>
        <div><span>Nächster Lauf</span><b title="${esc(Z.cron.describe(job.schedule))}">${job.enabled ? esc(fmt.when(j.nextRun)) : 'pausiert'}</b></div>
        <div><span>Ø Dauer</span><b>${avg ? fmt.duration(avg) : '—'}</b></div>
        <div><span>Aufbewahrung</span><b>${esc(keepSummary(job))}</b></div>
      </div>
      <div data-region="run-${esc(job.id)}" class="job-live">${runStrip(job.id)}</div>
      <div class="job-spark">
        <div class="spark-h"><span>Letzte ${Math.min(j.runs.length, 30) || ''} Läufe</span><span>${j.runs.length ? fmt.bytes(j.runs[j.runs.length - 1].bytes) + ' zuletzt' : ''}</span></div>
        ${ui.sparkline(j.runs)}
      </div>
      <footer>
        ${
          run && run.state === 'running'
            ? ui.btn('Live ansehen', { act: 'openRun', icon: 'eye', kind: 'primary', data: { id: job.id } }) + ui.btn('Abbrechen', { act: 'cancelRun', icon: 'stop', kind: 'danger-ghost', data: { id: job.id } })
            : ui.btn('Jetzt sichern', { act: 'runJob', icon: 'play', kind: 'primary', data: { id: job.id }, disabled: !canRun, title: canRun ? 'Einmal manuell sichern, auch bei pausiertem Zeitplan' : noRun }) +
              ui.btn('Probelauf', { act: 'dryRun', icon: 'flask', data: { id: job.id }, disabled: !canRun, title: canRun ? 'Zeigt den Plan – ohne Änderungen' : noRun }) +
              (job.method === 'rsync' ? ui.btn('Mit Prüfung', { act: 'verifyRun', icon: 'shieldCheck', data: { id: job.id }, disabled: !canRun, title: 'Backup ausführen und anschließend Prüfsummen vergleichen' }) : '')
        }
        <span class="grow"></span>
        ${ui.btn('', { act: 'toggleJob', icon: job.enabled ? 'pause' : 'play', title: job.enabled ? 'Zeitplan pausieren' : 'Zeitplan fortsetzen', data: { id: job.id } })}
        ${ui.btn('', { act: 'jobHistory', icon: 'history', title: 'Verlauf', data: { id: job.id } })}
        ${ui.btn('', { act: 'jobSnapshots', icon: 'camera', title: 'Snapshots', data: { ds: job.source.dataset } })}
      </footer>
    </article>`;
  }

  function runStrip(id) {
    const r = Z.state.runs[id];
    if (!r || r.state !== 'running') return '';
    const pct = typeof r.progress?.percent === 'number' ? Z.clamp(r.progress.percent, 0, 100) : null;
    return `<button type="button" class="run-strip" data-act="openRun" data-id="${esc(id)}">
      <div class="rs-h"><span>${icon('refresh', 'spin')}${esc(r.step || 'Läuft')}${r.dryRun ? ' · Probelauf' : ''}</span><b>${pct === null ? '' : Math.round(pct) + ' %'}</b></div>
      ${pct === null ? '' : ui.progress(pct, { striped: true })}
      <div class="rs-f"><span class="mono">${esc(r.dataset || '')}</span><span>${esc(r.progress?.rate || '')}${r.progress?.eta ? ' · ' + esc(r.progress.eta) : ''} · ${fmt.bytes(r.bytes)}</span></div>
    </button>`;
  }
  Z.runStrip = runStrip;

  Z.views.overview = {
    render() {
      const ov = Z.state.overview;
      if (!ov) return loadingView();
      const cards = ov.jobs.map((j) => jobCard(j, ov)).join('');
      return `${migrationBanner(ov)}${hero(ov)}
        ${ov.runnerAvailable ? '' : `<div class="zbg-callout tone-err">${icon('alert')}<div>Der Runner ist nicht verfügbar. Prüfe die Installation und das Server-Log.</div></div>`}
        <div class="zbg-section-h"><h2>Backup-Jobs</h2><div>${ui.btn('Neuer Job', { act: 'newJob', icon: 'plus', kind: 'primary' })}</div></div>
        ${
          ov.jobs.length
            ? `<div class="zbg-jobs">${cards}<button type="button" class="zbg-job add" data-act="newJob"><span>${icon('plus')}</span><b>Job hinzufügen</b><small>Dataset auswählen, Ziel festlegen, fertig</small></button></div>`
            : ui.empty('shield', 'Noch nichts geschützt', 'Lege einen Job an: Quelle wählen, Ziel festlegen, Zeitplan und Aufbewahrung bestimmen.', `<div class="row">${ui.btn('Ersten Job anlegen', { act: 'newJob', icon: 'plus', kind: 'primary' })}${ov.migration?.length ? ui.btn('Aus User Scripts übernehmen', { act: 'openMigration', icon: 'download' }) : ''}</div>`)
        }`;
    },
  };

  function loadingView() {
    if (Z.state.error) return ui.empty('alert', 'Laden fehlgeschlagen', Z.state.error, ui.btn('Erneut versuchen', { act: 'reload', icon: 'refresh', kind: 'primary' }));
    return `<div class="zbg-hero skel"><div class="zbg-spinner"></div></div><div class="zbg-jobs">${'<div class="zbg-job skel"></div>'.repeat(3)}</div>`;
  }

  Z.loadOverview = async function () {
    try {
      Z.state.overview = await Z.api('overview');
      Z.state.runs = Z.state.overview.runs || {};
      Z.pollRuns();
      Z.state.error = null;
    } catch (e) {
      Z.state.error = e.message;
      Z.fail(e);
    }
    if (Z.state.view === 'overview') Z.render();
    Z.updateHeader();
    Z.scheduleOverviewRefresh?.();
  };

  /* ---------- Aktionen der Übersicht ---------- */
  const findJob = (id) => Z.state.overview?.jobs.find((j) => j.job.id === id);
  Z.findJob = findJob;

  Z.actions.reload = () => Z.loadOverview();
  Z.actions.hideMigration = () => {
    Z.state.hideMigration = true;
    Z.render();
  };
  Z.actions.toggleJob = async (el) => {
    const j = findJob(el.dataset.id);
    try {
      await Z.api('jobs.toggle', { id: j.job.id, enabled: !j.job.enabled });
      Z.toast(j.job.enabled ? `„${j.job.name}“ pausiert` : `„${j.job.name}“ wieder aktiv`);
      Z.loadOverview();
    } catch (e) {
      Z.fail(e);
    }
  };
  Z.actions.jobHistory = (el) => Z.go('history', { job: el.dataset.id });
  Z.actions.jobSnapshots = (el) => Z.go('snapshots', { dataset: el.dataset.ds });
  Z.actions.jobMenu = (el) => {
    const j = findJob(el.dataset.id);
    const job = j.job;
    Z.modal({
      title: esc(job.name),
      size: 'sm',
      body: `<div class="zbg-menu">
        <button data-act="editJob" data-id="${esc(job.id)}">${icon('edit')}<span><b>Bearbeiten</b><small>Assistent öffnen</small></span></button>
        <button data-act="dupJob" data-id="${esc(job.id)}">${icon('copy')}<span><b>Duplizieren</b><small>Als Vorlage für einen neuen Job</small></span></button>
        <button data-act="showJson" data-id="${esc(job.id)}">${icon('terminal')}<span><b>Konfiguration anzeigen</b><small>JSON und CLI-Befehle</small></span></button>
        <button data-act="restoreFrom" data-ds="${esc(job.source.dataset)}">${icon('restore')}<span><b>Wiederherstellen</b><small>Dateien aus Snapshots holen</small></span></button>
        <button class="danger" data-act="deleteJob" data-id="${esc(job.id)}">${icon('trash')}<span><b>Löschen</b><small>Snapshots und Backups bleiben erhalten</small></span></button>
      </div>`,
    });
  };
  Z.actions.dupJob = (el) => {
    Z.closeLayer();
    const j = JSON.parse(JSON.stringify(findJob(el.dataset.id).job));
    j.id = '';
    j.name += ' (Kopie)';
    Z.openEditor(j, null);
  };
  Z.actions.restoreFrom = (el) => {
    Z.closeLayer();
    Z.go('restore', { dataset: el.dataset.ds });
  };
  Z.actions.showJson = (el) => {
    Z.closeLayer();
    const job = findJob(el.dataset.id).job;
    const cli = `/usr/local/emhttp/plugins/zfs-backup-guard/bin/zbg run ${job.id} --dry-run`;
    Z.modal({
      title: `Konfiguration · ${esc(job.name)}`,
      size: 'lg',
      body: `<div class="zbg-code" data-copy="${esc(cli)}"><span>${esc(cli)}</span>${ui.btn('', { act: 'copy', icon: 'copy', title: 'Kopieren' })}</div>
        <pre class="zbg-pre">${esc(JSON.stringify(job, null, 2))}</pre>`,
    });
  };
  Z.actions.deleteJob = async (el) => {
    Z.closeLayer();
    const job = findJob(el.dataset.id).job;
    const ok = await Z.confirm({
      title: 'Job löschen?',
      text: `„<b>${esc(job.name)}</b>“ wird entfernt. Vorhandene Snapshots und Backups am Ziel bleiben unangetastet und können manuell gelöscht werden.`,
      ok: 'Job löschen',
    });
    if (!ok) return;
    try {
      await Z.api('jobs.delete', { id: job.id });
      Z.toast('Job gelöscht');
      Z.loadOverview();
    } catch (e) {
      Z.fail(e);
    }
  };

  /* =================================================================== */
  /* Live-Lauf                                                            */
  /* =================================================================== */

  let pollTimer = null;
  let pollBusy = false;
  let pollErrors = 0;
  let pollStopped = false;
  let liveLayer = null;
  let liveId = null;
  let liveLog = [];
  const startingJobs = new Set();
  const logText = (lines) => (Array.isArray(lines) ? lines : []).slice(-2000).map((l) => typeof l === 'string' ? l : `${l.t ? fmt.dateFull(l.t) + ' ' : ''}${l.msg ?? l.message ?? JSON.stringify(l)}`).join('\n');
  const needsPoll = () => !document.hidden && !pollStopped && Object.values(Z.state.runs).some((r) => r.state === 'running') && (Z.state.view === 'overview' || liveLayer !== null);
  Z.stopPolling = () => {
    clearTimeout(pollTimer);
    pollTimer = null;
  };
  Z.pollRuns = function () {
    Z.stopPolling();
    if (pollBusy || !needsPoll()) return;
    pollTimer = setTimeout(pollOnce, 1500);
  };
  async function loadRunLog(id) {
    const r = Z.state.runs[id];
    if (!r) return;
    const lines = await Z.api('run.log', { id, run: r.runId });
    if (liveId === id && Z.state.runs[id]?.runId === r.runId) liveLog = lines;
  }
  async function pollOnce() {
    pollTimer = null;
    if (!needsPoll()) return;
    pollBusy = true;
    let finished = false;
    try {
      const states = await Z.api('run.status');
      for (const [id, s] of Object.entries(states || {})) {
        const previous = Z.state.runs[id];
        Z.state.runs[id] = s;
        if (previous?.state === 'running' && s.state !== 'running') {
          const name = findJob(id)?.job.name || id;
          Z.toast(`„${name}“: ${s.state === 'success' ? (s.dryRun ? 'Probelauf erfolgreich' : 'Backup erfolgreich') : s.state === 'cancelled' ? 'abgebrochen' : 'fehlgeschlagen'}`, s.state === 'success' ? 'ok' : s.state === 'cancelled' ? 'warn' : 'err');
          finished = true;
        }
      }
      if (liveId) await loadRunLog(liveId);
      pollErrors = 0;
      if (liveLayer) updateLive();
      if (Z.state.view === 'overview') Z.render();
      Z.updateHeader();
      if (finished) await Z.loadOverview();
    } catch (e) {
      pollErrors++;
      if (liveLayer) {
        const error = liveLayer.el.querySelector('[data-live-error]');
        error.textContent = `Status nicht erreichbar: ${e.message}`;
      }
      if (pollErrors >= 3) {
        pollStopped = true;
        Z.fail(new Error(`Live-Aktualisierung angehalten: ${e.message}. Bitte erneut aktualisieren.`));
      }
    } finally {
      pollBusy = false;
      Z.pollRuns();
    }
  }
  document.addEventListener('visibilitychange', () => document.hidden ? Z.stopPolling() : Z.pollRuns());
  window.addEventListener('pagehide', () => {
    pollStopped = true;
    Z.stopPolling();
  });
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) { pollStopped = false; Z.pollRuns(); }
  });
  Z.actions.retryRun = async () => {
    pollStopped = false;
    pollErrors = 0;
    const states = await Z.api('run.status');
    Z.state.runs = states || {};
    if (liveId) await loadRunLog(liveId);
    updateLive();
    if (Z.state.view === 'overview') Z.render();
    Z.pollRuns();
  };

  async function startRun(id, dryRun, verify = false) {
    if (startingJobs.has(id)) return;
    startingJobs.add(id);
    try {
      const s = await Z.api('run.start', { id, dryRun, verify });
      Z.state.runs[id] = s;
      pollStopped = false;
      pollErrors = 0;
      Z.render();
      await openRun(id);
      Z.pollRuns();
    } catch (e) {
      Z.fail(e);
    } finally {
      startingJobs.delete(id);
    }
  }
  Z.actions.runJob = (el) => startRun(el.dataset.id, false);
  Z.actions.dryRun = (el) => startRun(el.dataset.id, true);
  Z.actions.verifyRun = (el) => startRun(el.dataset.id, false, true);
  Z.actions.cancelRun = async (el) => {
    const id = el.dataset.id;
    if (Z.state.runs[id]?.state !== 'running') return;
    const ok = await Z.confirm({
      title: 'Lauf abbrechen?',
      text: 'Der Lauf wird beendet und angehaltene Container werden wiederhergestellt. Der angelegte Snapshot bleibt erhalten. Bitte bis zum endgültigen Status warten.',
      ok: 'Lauf abbrechen',
    });
    if (!ok) return;
    await Z.api('run.cancel', { id });
    Z.toast('Abbruch angefordert; Aufräumen abwarten.', 'warn');
    Z.pollRuns();
  };

  function liveBody(id) {
    const r = Z.state.runs[id];
    if (!r) return ui.empty('alert', 'Kein Laufstatus', 'Aktualisieren, um den Status erneut abzurufen.');
    const tone = r.state === 'running' ? 'run' : r.state === 'success' ? 'ok' : r.state === 'cancelled' ? 'warn' : 'err';
    const pct = typeof r.progress?.percent === 'number' ? Z.clamp(r.progress.percent, 0, 100) : null;
    return `<div class="live-top tone-${tone}">
        <div class="live-meta">${ui.kv([
          ['Status', ui.badge(r.state === 'running' ? (r.dryRun ? 'Probelauf läuft' : 'Läuft') : r.state === 'success' ? (r.dryRun ? 'Probelauf erfolgreich' : 'Erfolgreich') : r.state === 'cancelled' ? 'Abgebrochen' : 'Fehlgeschlagen', tone)],
          ['Schritt', esc(r.step || '–')],
          ['Dataset', `<span class="mono">${esc(r.dataset || '–')}</span>`],
          ['Durchsatz / ETA', esc([r.progress?.rate, r.progress?.eta].filter(Boolean).join(' · ') || '–')],
          ['Start', esc(fmt.dateFull(r.start))],
          ['Laufzeit', fmt.duration(r.duration ?? ((r.end || Date.now() / 1000) - r.start))],
          ['Übertragen', `${fmt.bytes(r.bytes)} · ${fmt.num(r.files)} Dateien`],
          ['Lauf-ID', `<span class="mono">${esc(r.runId || '–')}</span>`],
        ])}</div>
      </div>
      ${pct === null ? '<p class="muted">Für diesen Schritt ist kein prozentualer Fortschritt verfügbar.</p>' : ui.progress(pct, { tone, striped: r.state === 'running', label: Math.round(pct) + ' %' })}
      ${r.error ? `<div class="zbg-callout tone-err">${icon('alert')}<div>${esc(r.error)}</div></div>` : ''}
      <div class="live-log"><div class="log-h"><h4>${icon('terminal')}Log</h4><label class="zbg-check"><input type="checkbox" data-act="toggleFollow"${Z.state.noFollow ? '' : ' checked'}><span>Mitlaufen</span></label></div>
        <pre class="zbg-term" tabindex="0" aria-label="Laufprotokoll">${esc(logText(liveLog) || 'Noch keine Log-Ausgabe.')}</pre></div>`;
  }
  function updateLive() {
    if (!liveLayer) return;
    const body = liveLayer.el.querySelector('[data-region="live"]');
    body.innerHTML = liveBody(liveId);
    const cancel = liveLayer.el.querySelector('[data-act="cancelRun"]');
    if (cancel) cancel.disabled = Z.state.runs[liveId]?.state !== 'running';
    const term = body.querySelector('.zbg-term');
    if (term && !Z.state.noFollow) term.scrollTop = term.scrollHeight;
  }
  async function openRun(id) {
    if (liveLayer) Z.closeLayer(liveLayer);
    liveId = id;
    liveLog = [];
    const r = Z.state.runs[id];
    liveLayer = Z.modal({
      kind: 'sheet',
      size: 'lg',
      title: `${icon('zap')} ${esc(findJob(id)?.job.name || id)} <span class="muted">${r?.dryRun ? '· Probelauf' : '· Laufstatus'}</span>`,
      body: `<p class="err" role="status" data-live-error></p><div data-region="live" data-id="${esc(id)}">${liveBody(id)}</div>`,
      footer: ui.btn('Aktualisieren', { act: 'retryRun', icon: 'refresh' }) + ui.btn('Lauf abbrechen', { act: 'cancelRun', icon: 'stop', kind: 'danger-ghost', data: { id }, disabled: r?.state !== 'running' }) + ui.btn('Schließen', { act: 'closeLayer', kind: 'primary' }),
      onClose: () => {
        liveLayer = null;
        liveId = null;
        Z.pollRuns();
      },
    });
    const layer = liveLayer;
    try {
      Z.state.runs[id] = await Z.api('run.status', { id });
      await loadRunLog(id);
      if (liveLayer === layer) updateLive();
    } catch (e) {
      if (liveLayer === layer) layer.el.querySelector('[data-live-error]').textContent = e.message;
    }
    Z.pollRuns();
  }
  Z.actions.openRun = (el) => openRun(el.dataset.id);
  Z.actions.toggleFollow = (el) => { Z.state.noFollow = !el.checked; };

  /* =================================================================== */
  /* Snapshots                                                            */
  /* =================================================================== */

  const SNAP_KIND = { zbg: ['Backup Guard', 'accent'], sanoid: ['sanoid', 'info'], syncoid: ['syncoid', 'info'], userscript: ['User Script', 'warn'], manual: ['manuell', 'neutral'] };

  Z.loadDatasets = async function (force) {
    if (Z.state.datasets && !force) return Z.state.datasets;
    const r = await Z.api('datasets');
    Z.state.datasets = r.datasets;
    Z.state.pools = r.pools;
    return r.datasets;
  };

  const snap = (Z.state.snap = { dataset: null, list: null, filter: 'all', q: '', error: null });
  let snapshotRequest = 0;

  Z.views.snapshots = {
    async enter(params) {
      const ticket = ++snapshotRequest;
      try {
        await Z.loadDatasets();
        if (ticket !== snapshotRequest || Z.state.view !== 'snapshots') return;
      } catch (e) {
        return Z.fail(e);
      }
      const ds = params.dataset || snap.dataset || (Z.state.overview?.jobs[0]?.job.source.dataset) || Z.state.datasets.find((d) => d.name.includes('/'))?.name;
      selectDataset(ds);
    },
    leave() { snapshotRequest++; },
    render() {
      const dss = Z.state.datasets;
      if (!dss) return `<div class="zbg-split"><aside class="zbg-side">${ui.skeleton(8)}</aside><section class="zbg-panel">${ui.skeleton(6)}</section></div>`;
      return `<div class="zbg-split">
        <aside class="zbg-side">
          <div class="side-h"><h3>Datasets</h3>${ui.btn('', { act: 'reloadDatasets', icon: 'refresh', title: 'Aktualisieren' })}</div>
          <div class="ds-tree">${dsTree(dss, snap.dataset, 'pickSnapDs')}</div>
        </aside>
        <section class="zbg-panel" data-region="snaplist">${snapList()}</section>
      </div>`;
    },
  };

  const DS_FLAG = {
    boot: ['Boot', 'accent', 'Unraid-Boot (/boot)'],
    volume: ['zvol', 'info', 'Zvol – nur zfs send'],
    locked: ['gesperrt', 'warn', 'Schlüssel nicht geladen'],
    unmounted: ['nicht gemountet', 'warn', 'rsync überspringt dieses Dataset'],
    detached: ['eigener Mountpoint', 'info', 'Mountpoint weicht ab'],
    clone: ['Klon', 'info', 'Klon eines Snapshots'],
    snaplimit: ['Limit', 'warn', 'snapshot_limit gesetzt'],
    namelen: ['Name zu lang', 'err', 'Snapshot-Name würde 255 Zeichen überschreiten'],
  };
  Z.dsFlags = (d, max = 2) =>
    (d.issues || [])
      .filter((i) => DS_FLAG[i.code])
      .slice(0, max)
      .map((i) => `<span class="ds-flag tone-${DS_FLAG[i.code][1]}" title="${esc(i.msg)}">${esc(DS_FLAG[i.code][0])}</span>`)
      .join('');

  function dsTree(dss, selected, act) {
    return dss
      .map((d) => {
        const depth = d.name.split('/').length - 1;
        const leaf = d.name.split('/').pop();
        const ic = d.boot ? 'server' : d.type === 'volume' ? 'drive' : depth ? 'folder' : 'database';
        const dim = !d.readable && d.type !== 'volume' ? ' dim' : '';
        return `<button type="button" class="ds-item${d.name === selected ? ' on' : ''}${dim}" style="--d:${depth}" data-act="${act}" data-ds="${esc(d.name)}" title="${esc(d.name)} · ${esc(d.path || d.mountpoint)}${(d.issues || []).map((i) => '&#10;• ' + esc(i.msg)).join('')}">
          ${icon(ic)}<span class="nm">${esc(leaf)}</span>${Z.dsFlags(d, 1)}${d.encrypted ? `<span class="lock" title="verschlüsselt">🔒</span>` : ''}<span class="sz">${fmt.bytes(d.used)}</span></button>`;
      })
      .join('');
  }
  Z.dsTree = dsTree;

  async function selectDataset(ds) {
    if (!ds) return;
    const ticket = ++snapshotRequest;
    snap.dataset = ds;
    snap.list = null;
    snap.error = null;
    if (Z.state.view === 'snapshots') Z.render();
    try {
      const list = await Z.api('snapshots', { dataset: ds });
      if (ticket !== snapshotRequest) return;
      snap.list = list;
    } catch (e) {
      if (ticket !== snapshotRequest) return;
      snap.error = e.message;
      snap.list = [];
    }
    if (Z.state.view === 'snapshots') Z.refresh('snaplist');
  }
  Z.actions.pickSnapDs = (el) => {
    Z.$$('.ds-item').forEach((b) => b.classList.toggle('on', b === el));
    selectDataset(el.dataset.ds);
  };
  Z.actions.reloadDatasets = async () => {
    await Z.loadDatasets(true);
    Z.render();
    selectDataset(snap.dataset);
  };
  Z.actions.snapFilter = (el) => {
    snap.filter = el.dataset.value;
    Z.refresh('snaplist');
  };
  Z.actions.snapSearch = (el) => {
    snap.q = el.value;
    Z.$('[data-region="snaprows"]') && (Z.$('[data-region="snaprows"]').innerHTML = snapRows());
  };
  Z.refreshers.snaplist = () => snapList();

  function filteredSnaps() {
    return (snap.list || [])
      .filter((s) => snap.filter === 'all' || (snap.filter === 'zbg' ? s.kind === 'zbg' : s.kind !== 'zbg'))
      .filter((s) => !snap.q || s.snapshot.toLowerCase().includes(snap.q.toLowerCase()))
      .slice()
      .reverse();
  }

  function snapRows() {
    const list = filteredSnaps();
    if (!list.length) return `<tr><td colspan="6">${ui.empty('camera', 'Keine Snapshots', snap.list && snap.list.length ? 'Kein Snapshot passt zum Filter.' : 'Für dieses Dataset gibt es noch keine Snapshots.')}</td></tr>`;
    const maxUsed = Math.max(...list.map((s) => s.used), 1);
    return list
      .map((s) => {
        const [lbl, tone] = SNAP_KIND[s.kind] || SNAP_KIND.manual;
        return `<tr>
          <td><div class="snap-n"><span class="mono">${esc(s.snapshot)}</span>${s.job ? `<small>Job ${esc(s.job)}</small>` : ''}</div></td>
          <td>${ui.badge(lbl, tone)}</td>
          <td title="${esc(fmt.dateFull(s.creation))}"><div class="two"><b>${esc(fmt.rel(s.creation))}</b><small>${esc(fmt.date(s.creation))}</small></div></td>
          <td><div class="usebar"><i style="width:${Math.max(2, (s.used / maxUsed) * 100)}%"></i></div><small>${fmt.bytes(s.used)}</small></td>
          <td class="num">${fmt.bytes(s.refer)}</td>
          <td class="acts">
            ${ui.btn('', { act: 'browseSnap', icon: 'search', title: 'Durchsuchen', data: { ds: s.dataset, snap: s.snapshot } })}
            ${ui.btn('', { act: 'rollbackHint', icon: 'restore', title: 'Rollback-Befehl anzeigen', data: { ds: s.dataset, snap: s.snapshot } })}
            ${ui.btn('', { act: 'cloneSnap', icon: 'copy', title: 'Temporären Klon erstellen', data: { ds: s.dataset, snap: s.snapshot } })}
            ${s.own ? ui.btn('', { act: 'destroySnap', icon: 'trash', title: 'Snapshot löschen', kind: 'danger-ghost', data: { ds: s.dataset, snap: s.snapshot } }) : ''}
          </td></tr>`;
      })
      .join('');
  }

  function snapList() {
    const ds = Z.state.datasets?.find((d) => d.name === snap.dataset);
    if (!ds) return ui.empty('camera', 'Dataset wählen', 'Links ein Dataset auswählen.');
    const list = snap.list;
    const own = list ? list.filter((s) => s.kind === 'zbg').length : 0;
    const head = `<div class="panel-h">
        <div><h2>${icon('database')}${esc(ds.name)}</h2>
        <p class="muted mono">${esc(ds.mountpoint)} · ${fmt.bytes(ds.used)} belegt · davon ${fmt.bytes(ds.usedSnap)} durch Snapshots · Kompression ${esc(ds.compressratio)}×</p></div>
        <div class="panel-act">${ui.btn('Temporäre Klone', { act: 'listClones', icon: 'copy' })}${ui.btn('Durchsuchen', { act: 'restoreFrom', icon: 'restore', data: { ds: ds.name } })}</div>
      </div>`;
    if (!list) return head + ui.skeleton(6);
    const total = list.reduce((s, x) => s + x.used, 0);
    return `${head}
      <div class="snap-stats">
        <div><span>Snapshots</span><b>${list.length}</b></div>
        <div><span>Eigene</span><b>${own}</b></div>
        <div><span>Fremde</span><b>${list.length - own}</b></div>
        <div><span>Exklusiv belegt</span><b>${fmt.bytes(total)}</b></div>
        <div><span>Ältester</span><b>${list[0] ? esc(fmt.rel(list[0].creation)) : '–'}</b></div>
      </div>
      ${snapTimeline(list)}
      <div class="toolbar">
        ${ui.seg('snapFilter', [{ value: 'all', label: 'Alle' }, { value: 'zbg', label: 'Eigene' }, { value: 'other', label: 'Fremde' }], snap.filter, { act: 'snapFilter' })}
        <label class="zbg-search">${icon('search')}<input type="search" placeholder="Snapshot suchen …" value="${esc(snap.q)}" data-input="snapSearch"></label>
      </div>
      <div class="zbg-table-wrap"><table class="zbg-table">
        <thead><tr><th>Snapshot</th><th>Herkunft</th><th>Erstellt</th><th>Exklusiv</th><th class="num">Referenziert</th><th></th></tr></thead>
        <tbody data-region="snaprows">${snapRows()}</tbody>
      </table></div>
      <p class="zbg-foot">${icon('shield')} Backup Guard löscht ausschließlich eigene Snapshots (Präfix <code>zbg_</code> <i>und</i> Property <code>zbg:job</code>). Fremde Snapshots bleiben immer unberührt.</p>`;
  }

  /** Zeitstrahl der Snapshots (Wurzel-Skala: Neues bekommt mehr Platz) */
  function snapTimeline(list) {
    if (!list.length) return '';
    const now = Date.now() / 1000;
    const span = Math.max(now - list[0].creation, 86400 * 7);
    const x = (t) => 100 - Math.sqrt((now - t) / span) * 100;
    const ticks = [
      [0, 'jetzt'],
      [86400, '1 T'],
      [86400 * 7, '1 W'],
      [86400 * 30, '1 M'],
      [86400 * 90, '3 M'],
      [86400 * 182, '6 M'],
      [86400 * 365, '1 J'],
    ].filter(([d]) => d <= span);
    const dots = list
      .map((s) => `<i class="k-${s.kind}" style="left:${x(s.creation).toFixed(2)}%" title="${esc(s.snapshot)} · ${esc(fmt.dateFull(s.creation))}"></i>`)
      .join('');
    return `<div class="zbg-timeline"><div class="tl-track">${dots}</div><div class="tl-ticks">${ticks.map(([d, l]) => `<span style="left:${x(now - d).toFixed(2)}%">${l}</span>`).join('')}</div></div>`;
  }
  Z.snapTimeline = snapTimeline;

  Z.actions.browseSnap = (el) => Z.go('restore', { dataset: el.dataset.ds, snapshot: el.dataset.snap });
  Z.actions.rollbackHint = async (el) => {
    const result = await Z.api('snapshots.rollback-command', { dataset: el.dataset.ds, snapshot: el.dataset.snap });
    Z.modal({
      title: `${icon('alert')} Rollback – nur manuell`,
      size: 'md',
      body: `<div class="zbg-callout tone-err">${icon('alert')}<div><b>Achtung: destruktiv.</b> ${esc(result.warning)}</div></div>
        <p>Container und VMs vorher stoppen. Für einzelne Dateien ist <b>Wiederherstellen</b> der sichere Weg. Das Plugin führt keinen Rollback aus.</p>
        <div class="zbg-code" data-copy="${esc(result.command)}"><span>${esc(result.command)}</span>${ui.btn('', { act: 'copy', icon: 'copy', title: 'Kopieren' })}</div>`,
      footer: ui.btn('Stattdessen Dateien wiederherstellen', { act: 'browseSnapClose', icon: 'restore', kind: 'primary', data: { ds: el.dataset.ds, snap: el.dataset.snap } }),
    });
  };
  Z.actions.browseSnapClose = (el) => {
    Z.closeLayer();
    Z.actions.browseSnap(el);
  };
  Z.actions.destroySnap = async (el) => {
    const dataset = el.dataset.ds;
    const snapshot = el.dataset.snap;
    if (!await Z.confirm({ title: 'Snapshot löschen?', text: `<b class="mono">${esc(dataset)}@${esc(snapshot)}</b> wird endgültig gelöscht. Backups im Ziel bleiben erhalten.`, ok: 'Löschen', icon: 'trash' })) return;
    el.disabled = true;
    try {
      await Z.api('snapshots.destroy', { dataset, snapshot });
      snap.list = (snap.list || []).filter((s) => !(s.dataset === dataset && s.snapshot === snapshot));
      Z.state.overview = null;
      Z.refresh('snaplist');
      Z.toast('Snapshot gelöscht');
    } finally {
      el.disabled = false;
    }
  };
  Z.actions.cloneSnap = async (el) => {
    const dataset = el.dataset.ds;
    const snapshot = el.dataset.snap;
    if (!await Z.confirm({ title: 'Temporären Klon erstellen?', text: `<b class="mono">${esc(dataset)}@${esc(snapshot)}</b> wird als separates Dataset gemountet. Änderungen am Klon verändern den Snapshot nicht. Den Klon anschließend über „Temporäre Klone“ entfernen.`, ok: 'Klon erstellen', tone: 'primary', icon: 'copy' })) return;
    el.disabled = true;
    try {
      const clone = await Z.api('snapshots.clone', { dataset, snapshot });
      Z.state.datasets = null;
      Z.modal({
        title: 'Klon erstellt',
        body: ui.kv([['Dataset', `<span class="mono">${esc(clone.dataset)}</span>`], ['Mountpoint', `<span class="mono">${esc(clone.mountpoint)}</span>`], ['Ursprung', `<span class="mono">${esc(clone.origin)}</span>`]]) + '<p>Das Dataset kann am Mountpoint durchsucht werden. Beim Entfernen gehen alle Änderungen im Klon verloren.</p>',
        footer: ui.btn('Durchsuchen', { act: 'browseClone', icon: 'search', kind: 'primary', data: { ds: clone.dataset } }) + ui.btn('Klone verwalten', { act: 'listClones', icon: 'copy' }),
      });
    } finally {
      el.disabled = false;
    }
  };
  Z.actions.listClones = async () => {
    const layer = Z.modal({ title: `${icon('copy')} Temporäre Klone`, size: 'lg', body: ui.skeleton(4) });
    try {
      const clones = await Z.api('clones.list');
      layer.el.querySelector('.zbg-dialog-body').innerHTML = clones.length ? `<p class="muted">Nur von Backup Guard erstellte temporäre Klone. Entfernen löscht alle Änderungen im Klon, nicht den Ursprung.</p><div class="zbg-table-wrap"><table class="zbg-table"><thead><tr><th>Dataset / Ursprung</th><th>Mountpoint</th><th></th></tr></thead><tbody>${clones.map((c) => `<tr><td><b class="mono">${esc(c.dataset)}</b><br><small class="mono">${esc(c.origin)}</small></td><td class="mono">${esc(c.mountpoint)}</td><td>${ui.btn('Durchsuchen', { act: 'browseClone', icon: 'search', data: { ds: c.dataset } })}${ui.btn('Entfernen', { act: 'removeClone', icon: 'trash', kind: 'danger-ghost', data: { ds: c.dataset } })}</td></tr>`).join('')}</tbody></table></div>` : ui.empty('copy', 'Keine temporären Klone', 'In der Snapshot-Tabelle lässt sich ein Klon anlegen.');
    } catch (e) {
      layer.el.querySelector('.zbg-dialog-body').innerHTML = ui.empty('alert', 'Klone nicht lesbar', e.message);
    }
  };
  Z.actions.removeClone = async (el) => {
    const dataset = el.dataset.ds;
    if (!await Z.confirm({ title: 'Klon entfernen?', text: `Das temporäre Dataset <b class="mono">${esc(dataset)}</b> wird gelöscht. Alle Änderungen im Klon gehen verloren. Der ursprüngliche Snapshot bleibt erhalten.`, ok: 'Klon entfernen' })) return;
    el.disabled = true;
    try {
      await Z.api('clones.remove', { dataset });
      el.closest('tr').remove();
      Z.state.datasets = null;
      Z.toast('Temporärer Klon entfernt');
    } finally {
      el.disabled = false;
    }
  };
  Z.actions.browseClone = async (el) => {
    const dataset = el.dataset.ds;
    let sequence = 0;
    let closed = false;
    const layer = Z.modal({ title: `${icon('copy')} ${esc(dataset)}`, size: 'lg', body: ui.skeleton(6), onClose: () => { closed = true; sequence++; } });
    const body = layer.el.querySelector('.zbg-dialog-body');
    async function open(path) {
      const ticket = ++sequence;
      body.innerHTML = ui.skeleton(6);
      try {
        const listing = await Z.api('browse.clone', { dataset, path });
        if (closed || sequence !== ticket) return;
        let acc = '';
        const crumbs = `<nav class="crumbs" aria-label="Klonverzeichnis"><button type="button" data-clone-path="/">${icon('folder')}/</button>${listing.path.split('/').filter(Boolean).map((name) => { acc += '/' + name; return `${icon('right')}<button type="button" data-clone-path="${esc(acc)}">${esc(name)}</button>`; }).join('')}</nav>`;
        body.innerHTML = `<p class="muted">Lesender Browser für den temporären Klon. Änderungen erfolgen nur außerhalb dieser Ansicht.</p>${crumbs}<div class="zbg-table-wrap"><table class="zbg-table files"><thead><tr><th>Name</th><th>Größe</th><th>Geändert</th></tr></thead><tbody>${listing.path !== '/' ? `<tr><td colspan="3"><button type="button" class="fname dir" data-clone-path="${esc(listing.path.split('/').slice(0, -1).join('/') || '/')}">${icon('left')}Übergeordnetes Verzeichnis</button></td></tr>` : ''}${listing.entries.map((entry) => {
          const p = (listing.path === '/' ? '' : listing.path) + '/' + entry.name;
          return `<tr><td>${entry.type === 'dir' ? `<button type="button" class="fname dir" data-clone-path="${esc(p)}">${icon('folder')}<span>${esc(entry.name)}</span></button>` : `<span class="fname">${icon(entry.type === 'link' ? 'link' : 'file')}<span>${esc(entry.name)}</span></span>`}</td><td>${entry.type === 'dir' ? '–' : fmt.bytes(entry.size)}</td><td>${esc(fmt.dateFull(entry.mtime))}</td></tr>`;
        }).join('') || '<tr><td colspan="3">Leerer Ordner</td></tr>'}</tbody></table></div>${listing.truncated ? '<p class="muted">Eintragsliste vom Server begrenzt.</p>' : ''}`;
      } catch (e) {
        if (closed || sequence !== ticket) return;
        body.innerHTML = ui.empty('alert', 'Klon nicht lesbar', e.message) + `<button type="button" class="zbg-btn" data-clone-path="${esc(path)}">Erneut laden</button>`;
      }
    }
    body.addEventListener('click', (event) => {
      const button = event.target.closest('[data-clone-path]');
      if (button) { event.preventDefault(); open(button.dataset.clonePath); }
    });
    await open('/');
  };

  /* =================================================================== */
  /* Verlauf                                                              */
  /* =================================================================== */

  const hist = (Z.state.hist = { list: null, job: '', result: '', open: null, logs: {}, errors: {} });
  Z.views.history = {
    async enter(params) {
      if (params.job !== undefined) hist.job = params.job;
      hist.list = null;
      Z.render();
      try {
        hist.list = await Z.api('history', {});
      } catch (e) {
        hist.list = [];
        Z.fail(e);
      }
      if (Z.state.view === 'history') Z.render();
    },
    render() {
      const jobs = Z.state.overview?.jobs || [];
      const list = (hist.list || []).filter((r) => (!hist.job || r.job === hist.job) && (!hist.result || r.result === hist.result));
      const all = hist.list || [];
      const ok = all.filter((r) => r.result === 'success').length;
      const failed = all.filter((r) => r.result === 'failed').length;
      const bytes = all.reduce((s, r) => s + (r.result === 'success' ? r.bytes || 0 : 0), 0);
      return `<div class="zbg-section-h"><h2>Verlauf</h2><div class="toolbar">
          <select class="zbg-select" data-input="histJob"><option value="">Alle Jobs</option>${jobs.map((j) => `<option value="${esc(j.job.id)}"${hist.job === j.job.id ? ' selected' : ''}>${esc(j.job.name)}</option>`).join('')}</select>
          ${ui.seg('histResult', [{ value: '', label: 'Alle' }, { value: 'success', label: 'Erfolgreich' }, { value: 'failed', label: 'Fehler' }, { value: 'dry-run', label: 'Probeläufe' }, { value: 'cancelled', label: 'Abgebrochen' }], hist.result, { act: 'histResult' })}
        </div></div>
        <div class="snap-stats wide">
          <div><span>Läufe</span><b>${all.length}</b></div>
          <div><span>Erfolgsquote</span><b>${all.length ? Math.round((ok / Math.max(ok + failed, 1)) * 100) + ' %' : '–'}</b></div>
          <div><span>Fehler</span><b class="${failed ? 'err' : ''}">${failed}</b></div>
          <div><span>Übertragen</span><b>${fmt.bytes(bytes)}</b></div>
        </div>
        ${
          !hist.list
            ? ui.skeleton(8)
            : !list.length
            ? ui.empty('history', 'Noch kein Verlauf', 'Sobald Jobs laufen, erscheinen hier alle Läufe mit Dauer, Datenmenge und Log.')
            : `<div class="zbg-table-wrap"><table class="zbg-table hist">
              <thead><tr><th></th><th>Start</th><th>Job</th><th>Ergebnis</th><th class="num">Dauer</th><th class="num">Übertragen</th><th class="num">Dateien</th><th></th></tr></thead>
              <tbody>${list.map(histRow).join('')}</tbody></table></div>`
        }`;
    },
  };
  const RESULT = { success: ['Erfolgreich', 'ok', 'check'], failed: ['Fehlgeschlagen', 'err', 'x'], 'dry-run': ['Probelauf', 'info', 'flask'], cancelled: ['Abgebrochen', 'warn', 'stop'] };
  function histRow(r) {
    const [lbl, tone, ic] = RESULT[r.result] || ['Unbekannt', 'neutral', 'info'];
    const job = Z.state.overview?.jobs.find((j) => j.job.id === r.job);
    const key = `${r.job}:${r.id || r.runId}`;
    const open = hist.open === key;
    return `<tr class="hist-row${open ? ' open' : ''}">
        <td>${ui.dot(tone)}</td>
        <td><div class="two"><b>${esc(fmt.when(r.start))}</b><small>${esc(fmt.rel(r.start))}</small></div></td>
        <td>${esc(job ? job.job.name : r.job)}</td>
        <td>${ui.badge(lbl, tone, ic)}</td>
        <td class="num">${fmt.duration(r.duration)}</td>
        <td class="num">${fmt.bytes(r.bytes)}</td>
        <td class="num">${fmt.num(r.files)}</td>
        <td>${ui.btn('', { act: 'histOpen', icon: open ? 'down' : 'right', title: open ? 'Details schließen' : 'Details und Log öffnen', data: { id: key } })}</td>
      </tr>${
        open
          ? `<tr class="hist-detail"><td colspan="8">
          ${r.error ? `<div class="zbg-callout tone-err">${icon('alert')}<div>${esc(r.error)}</div></div>` : ''}
          <div class="hist-meta">${ui.kv([
            ['Snapshot', `<span class="mono">${esc(r.snapshot || '–')}</span>`],
            ['Gelöscht (Retention)', esc(String(r.pruned ?? 0)) + ' Snapshots'],
            ['Ende', esc(fmt.dateFull(r.end))],
          ])}</div>
          ${checksumDetail(r)}
          ${datasetDetail(r)}
          ${hist.errors[key] ? `<p class="err" role="alert">${esc(hist.errors[key])}</p>${ui.btn('Log erneut laden', { act: 'histReload', icon: 'refresh', data: { id: key } })}` : ''}
          <pre class="zbg-term small" tabindex="0" aria-label="Laufprotokoll">${esc(hist.logs[key] === undefined ? 'Log wird geladen …' : logText(hist.logs[key]) || 'Keine Log-Ausgabe gespeichert.')}</pre></td></tr>`
          : ''
      }`;
  }
  function checksumDetail(r) {
    const check = r.verification;
    if (!check?.checked) return `<p class="muted">${r.verify ? 'Prüfung angefordert; kein abgeschlossenes Ergebnis gespeichert.' : 'Keine abgeschlossene Prüfsummenprüfung für diesen Lauf.'}</p>`;
    return `<div class="zbg-callout tone-${check.mismatches ? 'err' : 'ok'}">${icon(check.mismatches ? 'alert' : 'shieldCheck')}<div>Prüfsummenvergleich abgeschlossen: <b>${fmt.num(check.mismatches)} Abweichungen</b>.</div></div>`;
  }
  function datasetDetail(r) {
    const datasets = Array.isArray(r.datasets) ? r.datasets : [];
    if (!datasets.length) return '';
    return `<h4>Datasets</h4><div class="zbg-table-wrap"><table class="zbg-table"><thead><tr><th>Dataset</th><th>Aktion</th><th>Bytes</th><th>Dateien</th><th>Prüfsummen</th></tr></thead><tbody>${datasets.map((d) => `<tr><td class="mono">${esc(d.dataset)}</td><td>${esc(d.action || 'copy')}</td><td>${fmt.bytes(d.bytes)}</td><td>${d.files == null ? '–' : fmt.num(d.files)}</td><td>${d.verification?.checked ? fmt.num(d.verification.mismatches) + ' Abweichungen' : 'nicht geprüft'}</td></tr>`).join('')}</tbody></table></div>`;
  }
  async function historyLog(key) {
    const r = hist.list?.find((row) => `${row.job}:${row.id || row.runId}` === key);
    if (!r) return;
    delete hist.errors[key];
    try {
      hist.logs[key] = await Z.api('history.log', { id: r.job, run: r.id || r.runId });
    } catch (e) {
      hist.errors[key] = e.message;
      hist.logs[key] = [];
    }
    if (Z.state.view === 'history') Z.render();
  }
  Z.actions.histOpen = async (el) => {
    const key = el.dataset.id;
    hist.open = hist.open === key ? null : key;
    Z.render();
    if (hist.open && hist.logs[key] === undefined) await historyLog(key);
  };
  Z.actions.histReload = (el) => historyLog(el.dataset.id);
  Z.actions.histJob = (el) => {
    hist.job = el.value;
    Z.render();
  };
  Z.actions.histResult = (el) => {
    hist.result = el.dataset.value;
    Z.render();
  };

  /* =================================================================== */
  /* Einstellungen                                                        */
  /* =================================================================== */

  const set = (Z.state.set = { data: null });
  Z.views.settings = {
    async enter() {
      try {
        set.data = await Z.api('settings.get');
      } catch (e) {
        Z.fail(e);
      }
      if (Z.state.view === 'settings') Z.render();
    },
    render() {
      const s = set.data;
      if (!s) return ui.skeleton(6);
      const ov = Z.state.overview || {};
      return `<div class="zbg-section-h"><h2>Einstellungen</h2></div>
      <div class="zbg-grid2">
        <section class="zbg-card">
          <h3>${icon('archive')}Speicherorte</h3>
          <label class="zbg-field"><span>Log- und Verlaufsverzeichnis</span>
            <input class="zbg-input mono" id="set-logDir" value="${esc(s.logDir)}">
            <small>Nicht auf dem Flash, um Schreiblast zu vermeiden. Falls nicht erreichbar: <code>/var/log/zfs-backup-guard</code></small></label>
          <label class="zbg-field"><span>Verlaufseinträge pro Job</span>
            <input class="zbg-input" id="set-historyLimit" type="number" min="50" max="5000" value="${esc(s.historyLimit)}"></label>
          <label class="zbg-field row"><input type="checkbox" id="set-globalLock"${s.globalLock ? ' checked' : ''}><span>Nur ein I/O-intensiver Lauf gleichzeitig (globale Sperre)</span></label>
          <div class="row end">${ui.btn('Speichern', { act: 'saveSettings', kind: 'primary', icon: 'check' })}</div>
        </section>
        <section class="zbg-card">
          <h3>${icon('terminal')}Kommandozeile</h3>
          <p class="muted">Das CLI liegt unter <code>/usr/local/emhttp/plugins/zfs-backup-guard/bin/zbg</code>.</p>
          ${['zbg list', 'zbg datasets', 'zbg snapshots cache/appdata', 'zbg run JOB-ID --dry-run', 'zbg run JOB-ID --verify', 'zbg check-age']
            .map((c) => `<div class="zbg-code" data-copy="${esc(c)}"><span>${esc(c)}</span>${ui.btn('', { act: 'copy', icon: 'copy', title: 'Kopieren' })}</div>`)
            .join('')}
        </section>
        <section class="zbg-card">
          <h3>${icon('download')}Migration</h3>
          <p class="muted">Erkennt Backup-Skripte aus dem User-Scripts-Plugin und schlägt passende Jobs vor. Fremde Konfigurationen werden nur gelesen.</p>
          <div class="row">${ui.btn('User Scripts scannen', { act: 'openMigration', icon: 'search' })}</div>
        </section>
        <section class="zbg-card about">
          <h3>${icon('shield')}Über</h3>
          ${ui.kv([
            ['Version', esc(ov.version || '–')],
            ['Runner', ov.runnerAvailable ? ui.badge('aktiv', 'ok') : ui.badge('nicht verfügbar', 'err')],
            ['Theme', esc(Z.cfg.unraidTheme || Z.cfg.theme)],
          ])}
        </section>
      </div>`;
    },
  };
  Z.actions.saveSettings = async () => {
    try {
      set.data = await Z.api('settings.save', {
        settings: {
          logDir: Z.$('#set-logDir').value,
          historyLimit: +Z.$('#set-historyLimit').value,
          globalLock: Z.$('#set-globalLock').checked,
        },
      });
      Z.toast('Einstellungen gespeichert');
    } catch (e) {
      Z.fail(e);
    }
  };

  /* =================================================================== */
  /* Migration                                                            */
  /* =================================================================== */

  Z.actions.openMigration = async () => {
    const layer = Z.modal({ title: `${icon('sparkles')} Aus User Scripts übernehmen`, size: 'lg', body: ui.skeleton(5), footer: ui.btn('Schließen', { act: 'closeLayer' }) });
    try {
      const found = await Z.api('migration.scan');
      const existing = new Set((Z.state.overview?.jobs || []).map((j) => j.job.id));
      layer.el.querySelector('.zbg-dialog-body').innerHTML = !found.length
        ? ui.empty('search', 'Nichts gefunden', 'Es wurden keine erkennbaren Backup-Skripte gefunden.')
        : `<div class="zbg-callout tone-warn">${icon('alert')}<div><b>Vor dem Aktivieren:</b> Den alten User-Script-Zeitplan manuell deaktivieren, Ziele und Konsistenz prüfen, einen Probelauf durchführen und den ersten Restore testen. Das Plugin ändert keine fremden Zeitpläne. Importierte Jobs bleiben pausiert.</div></div>` +
          found.map((s) => {
            const collision = s.proposals.some((p) => existing.has(p.id));
            return `<section class="mig-script">
              <header><b>${esc(s.script)}</b>${s.active ? ui.badge(s.schedule ? Z.cron.describe(s.schedule) : 'aktiv', 'warn', 'clock') : ui.badge('deaktiviert', 'idle')}</header>
              <div class="mig-jobs">${s.proposals.map((p) => `<div class="mig-job"><div><b>${esc(p.source.dataset)}</b><span class="mono">${esc(p.target.path || p.target.dataset)}</span><small>${esc(p.target.mode === 'mirror' ? 'Spiegel' : 'versioniert')} · ${esc(Z.cron.describe(p.schedule))} · Snapshots ${esc(keepSummary(p))}</small>${p.migration?.oldTarget ? `<small>Altes Ziel: <span class="mono">${esc(p.migration.oldTarget)}</span></small>` : ''}${p.migration?.keepPath ? `<small>Der bisherige Spiegelpfad wird weiterverwendet. Bestehende Daten vor dem ersten Lauf prüfen.</small>` : '<small>Neuer Versionsordner; der alte Spiegel bleibt unangetastet.</small>'}${existing.has(p.id) ? '<small class="err">Job-ID bereits vorhanden – keine bestehenden Jobs überschreiben.</small>' : ''}</div></div>`).join('')}</div>
              ${collision ? '<p class="err">Vor dem Import die kollidierende Job-ID im Editor umbenennen oder einen nicht mehr benötigten Job entfernen.</p>' : `<label class="zbg-check"><input type="checkbox" data-input="migReview"><span>Ich habe alle vorgeschlagenen Quellen, Ziele und Zeitpläne geprüft.</span></label>${ui.btn('Dieses Skript geprüft importieren', { act: 'migImport', kind: 'primary', icon: 'download', data: { script: s.script }, disabled: true })}`}
              <p role="status" data-mig-result></p>
            </section>`;
          }).join('');
    } catch (e) {
      layer.el.querySelector('.zbg-dialog-body').innerHTML = ui.empty('alert', 'Migration fehlgeschlagen', e.message);
    }
  };
  Z.actions.migReview = (el) => {
    el.closest('.mig-script').querySelector('[data-act="migImport"]').disabled = !el.checked;
  };
  Z.actions.migImport = async (el) => {
    const section = el.closest('.mig-script');
    const output = section.querySelector('[data-mig-result]');
    el.disabled = true;
    try {
      const jobs = await Z.api('migration.import', { script: el.dataset.script });
      output.textContent = `${jobs.length} Jobs importiert und pausiert. Jetzt im Job-Editor prüfen, Probelauf ausführen und vor dem Aktivieren den alten User-Script-Zeitplan deaktivieren.`;
      section.querySelector('[data-input="migReview"]').disabled = true;
      await Z.loadOverview();
    } catch (e) {
      output.textContent = `Import fehlgeschlagen: ${e.message}`;
      el.disabled = false;
    }
  };
})();
