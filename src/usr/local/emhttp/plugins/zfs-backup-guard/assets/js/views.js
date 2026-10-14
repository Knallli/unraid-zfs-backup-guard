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
      <div class="bn-text"><b>User-Script-Backups erkannt</b><span>${names} läuft noch per Zeitplan. ${n} ${n === 1 ? 'Job kann' : 'Jobs können'} übernommen werden. Danach gibt es lokale Snapshots mit Aufbewahrung, Container-Konsistenz und Überwachung. Ziele, die Zerobyte weitersichert, bleiben unverändert.</span></div>
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
    const noRun = 'Ausführung folgt in Phase 2';
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
            : ui.btn('Jetzt sichern', { act: 'runJob', icon: 'play', kind: 'primary', data: { id: job.id }, disabled: !canRun || !job.enabled, title: canRun ? '' : noRun }) +
              ui.btn('Probelauf', { act: 'dryRun', icon: 'flask', data: { id: job.id }, disabled: !canRun, title: canRun ? 'Zeigt, was passieren würde – ohne Änderungen' : noRun })
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
    const step = r.steps.find((s) => s.state === 'active') || r.steps[r.steps.length - 1];
    return `<div class="run-strip" data-act="openRun" data-id="${esc(id)}">
      <div class="rs-h"><span>${icon('refresh', 'spin')}${esc(step.label)}${r.dryRun && !/Probelauf/.test(step.label) ? ' · Probelauf' : ''}</span><b>${Math.round(r.pct)} %</b></div>
      ${ui.progress(r.pct, { striped: true })}
      <div class="rs-f"><span>${fmt.rate(r.rate)}</span><span>${r.eta ? 'noch ' + fmt.duration(r.eta) : ''}</span></div>
    </div>`;
  }
  Z.runStrip = runStrip;

  Z.views.overview = {
    render() {
      const ov = Z.state.overview;
      if (!ov) return loadingView();
      const cards = ov.jobs.map((j) => jobCard(j, ov)).join('');
      return `${migrationBanner(ov)}${hero(ov)}
        ${ov.runnerAvailable ? '' : `<div class="zbg-note">${icon('info')}<span><b>Phase 1 (nur lesend).</b> Jobs lassen sich bereits anlegen und prüfen. Die Ausführung, also Snapshot, Kopie und Aufräumen, kommt mit Phase 2.</span></div>`}
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
      Z.state.error = null;
    } catch (e) {
      Z.state.error = e.message;
      Z.fail(e);
    }
    if (Z.state.view === 'overview') Z.render();
    Z.updateHeader();
  };

  /* ---------- Aktionen der Übersicht ---------- */
  const findJob = (id) => Z.state.overview.jobs.find((j) => j.job.id === id);
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
  Z.pollRuns = function () {
    clearTimeout(pollTimer);
    const active = Object.values(Z.state.runs).filter((r) => r.state === 'running');
    if (!active.length) return;
    pollTimer = setTimeout(async () => {
      for (const r of active) {
        try {
          const s = await Z.api('run.status', { runId: r.runId });
          Z.state.runs[r.job] = s;
          if (s.state !== 'running') {
            Z.toast(
              s.state === 'success' ? `„${s.jobName}“ ${s.dryRun ? 'Probelauf' : 'Backup'} erfolgreich` : s.state === 'cancelled' ? `„${s.jobName}“ abgebrochen` : `„${s.jobName}“ fehlgeschlagen`,
              s.state === 'success' ? 'ok' : s.state === 'cancelled' ? 'warn' : 'err'
            );
            Z.loadOverview();
          }
        } catch (e) {
          r.state = 'failed';
        }
        Z.refresh('run-' + r.job);
        Z.refresh('live');
      }
      Z.pollRuns();
    }, 1000);
  };

  async function startRun(id, dryRun) {
    try {
      const s = await Z.api('run.start', { id, dryRun });
      Z.state.runs[id] = s;
      Z.render();
      Z.pollRuns();
      openRun(id);
    } catch (e) {
      Z.fail(e);
    }
  }
  Z.actions.runJob = (el) => startRun(el.dataset.id, false);
  Z.actions.dryRun = (el) => startRun(el.dataset.id, true);
  Z.actions.cancelRun = async (el) => {
    const id = el.dataset.id;
    const ok = await Z.confirm({
      title: 'Lauf abbrechen?',
      text: 'Der Lauf wird sauber beendet, pausierte Container werden wieder gestartet. Der angelegte Snapshot bleibt erhalten.',
      ok: 'Abbrechen',
    });
    if (!ok) return;
    try {
      await Z.api('run.cancel', { runId: Z.state.runs[id].runId });
    } catch (e) {
      Z.fail(e);
    }
  };

  const STEP_IC = { done: 'check', active: 'refresh', pending: 'clock', failed: 'x', skipped: 'right' };
  Z.refreshers.live = (el) => liveBody(el.dataset.id);
  // Regionen „run-<id>“ dynamisch auflösen
  const origRefresh = Z.refresh;
  Z.refresh = function (name) {
    if (name.startsWith('run-') && !Z.refreshers[name]) {
      const id = name.slice(4);
      Z.$$(`[data-region="${name}"]`).forEach((el) => (el.innerHTML = runStrip(id)));
      const card = Z.$(`.zbg-job[data-job="${CSS.escape(id)}"]`);
      if (card && Z.state.runs[id] && Z.state.runs[id].state !== 'running') card.classList.remove('running');
      return;
    }
    origRefresh(name);
  };

  function liveBody(id) {
    const r = Z.state.runs[id];
    if (!r) return '';
    const tone = r.state === 'running' ? 'run' : r.state === 'success' ? 'ok' : r.state === 'cancelled' ? 'warn' : 'err';
    const elapsed = (r.endedAt || Date.now() / 1000) - r.startedAt;
    return `<div class="live-top tone-${tone}">
        <div class="live-pct">${ui.ring([{ value: r.pct, tone }, { value: 100 - r.pct, tone: 'none' }], { size: 92, stroke: 9, center: `<strong>${Math.round(r.pct)}<small>%</small></strong>` })}</div>
        <div class="live-meta">
          ${ui.kv([
            ['Status', ui.badge(r.state === 'running' ? (r.dryRun ? 'Probelauf läuft' : 'Läuft') : r.state === 'success' ? 'Erfolgreich' : r.state === 'cancelled' ? 'Abgebrochen' : 'Fehlgeschlagen', tone)],
            ['Laufzeit', fmt.duration(elapsed)],
            ['Durchsatz', fmt.rate(r.rate)],
            ['Übertragen', `${fmt.bytes(r.bytes)} · ${fmt.num(r.files)} Dateien`],
          ])}
        </div>
      </div>
      <ol class="live-steps">${r.steps
        .map((s) => `<li class="st-${s.state}"><span class="st-ic">${icon(STEP_IC[s.state] || 'clock', s.state === 'active' ? 'spin' : '')}</span><span class="st-l">${esc(s.label)}</span><span class="st-d">${esc(s.detail || '')}</span></li>`)
        .join('')}</ol>
      ${
        r.datasets && r.datasets.length
          ? `<div class="live-ds"><h4>Datasets</h4>${r.datasets
              .map(
                (d) => `<div class="ds-row st-${d.state}"><span class="mono">${esc(d.name)}</span>${ui.progress(d.pct, { striped: d.state === 'active', tone: d.state === 'done' ? 'ok' : 'accent' })}<span class="ds-v">${d.state === 'active' ? `${fmt.rate(d.rate)} · ${d.eta ? 'noch ' + fmt.duration(d.eta) : ''}` : d.state === 'done' ? fmt.bytes(d.bytes) : 'wartet'}</span></div>`
              )
              .join('')}</div>`
          : ''
      }
      <div class="live-log"><div class="log-h"><h4>${icon('terminal')}Log</h4><label class="zbg-check"><input type="checkbox" data-act="toggleFollow"${Z.state.noFollow ? '' : ' checked'}><span>Mitlaufen</span></label></div>
        <pre class="zbg-term">${r.log.map((l) => `<span class="lv-${l.level}"><i>${new Date(l.t * 1000).toLocaleTimeString(Z.cfg.locale)}</i>${esc(l.msg)}</span>`).join('\n')}</pre></div>`;
  }

  function openRun(id) {
    const r = Z.state.runs[id];
    if (!r) return;
    const layer = Z.modal({
      kind: 'sheet',
      size: 'lg',
      title: `${icon('zap')} ${esc(r.jobName)} <span class="muted">${r.dryRun ? '· Probelauf' : '· Live'}</span>`,
      body: `<div data-region="live" data-id="${esc(id)}">${liveBody(id)}</div>`,
      footer: r.state === 'running' ? ui.btn('Lauf abbrechen', { act: 'cancelRun', icon: 'stop', kind: 'danger-ghost', data: { id } }) + ui.btn('Schließen', { act: 'closeLayer', kind: 'primary' }) : ui.btn('Schließen', { act: 'closeLayer', kind: 'primary' }),
    });
    const scroll = () => {
      const t = layer.el.querySelector('.zbg-term');
      if (t && !Z.state.noFollow) t.scrollTop = t.scrollHeight;
    };
    scroll();
    const mo = new MutationObserver(scroll);
    mo.observe(layer.el.querySelector('[data-region="live"]'), { childList: true });
    layer.onClose = () => mo.disconnect();
  }
  Z.actions.openRun = (el) => openRun(el.dataset.id);
  Z.actions.toggleFollow = (el, ev) => {
    Z.state.noFollow = !el.checked;
  };

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

  const snap = (Z.state.snap = { dataset: null, list: null, filter: 'all', q: '', loading: false });

  Z.views.snapshots = {
    async enter(params) {
      try {
        await Z.loadDatasets();
      } catch (e) {
        return Z.fail(e);
      }
      const ds = params.dataset || snap.dataset || (Z.state.overview?.jobs[0]?.job.source.dataset) || Z.state.datasets.find((d) => d.name.includes('/'))?.name;
      selectDataset(ds);
    },
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
    snap.dataset = ds;
    snap.list = null;
    snap.selected = new Set();
    if (Z.state.view === 'snapshots') Z.render();
    try {
      snap.list = await Z.api('snapshots', { dataset: ds });
    } catch (e) {
      snap.list = [];
      Z.fail(e);
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
            ${ui.btn('', { icon: 'copy', title: 'Klonen – folgt in Phase 5', disabled: true })}
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
        <p class="muted mono">${esc(ds.mountpoint)} · ${fmt.bytes(ds.used)} belegt · davon ${fmt.bytes(ds.usedSnap)} durch Snapshots · Kompression ${ds.compressratio}×</p></div>
        <div class="panel-act">${ui.btn('Durchsuchen', { act: 'restoreFrom', icon: 'restore', data: { ds: ds.name } })}</div>
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
  Z.actions.rollbackHint = (el) => {
    const cmd = `zfs rollback -r '${el.dataset.ds}@${el.dataset.snap}'`;
    Z.modal({
      title: `${icon('alert')} Rollback – nur manuell`,
      size: 'md',
      body: `<div class="zbg-callout tone-err">${icon('alert')}<div><b>Achtung: destruktiv.</b> Ein Rollback setzt <b>${esc(el.dataset.ds)}</b> komplett auf den Stand des Snapshots zurück. Alle Änderungen danach gehen verloren, und mit <code>-r</code> werden auch <b>alle neueren Snapshots gelöscht</b>. Container und VMs auf dem Dataset vorher stoppen.</div></div>
        <p>Für einzelne Dateien ist <b>Wiederherstellen</b> der sichere Weg. Wenn du wirklich zurückrollen willst, führe den Befehl im Terminal aus:</p>
        <div class="zbg-code" data-copy="${esc(cmd)}"><span>${esc(cmd)}</span>${ui.btn('', { act: 'copy', icon: 'copy', title: 'Kopieren' })}</div>`,
      footer: ui.btn('Stattdessen Dateien wiederherstellen', { act: 'browseSnapClose', icon: 'restore', kind: 'primary', data: { ds: el.dataset.ds, snap: el.dataset.snap } }),
    });
  };
  Z.actions.browseSnapClose = (el) => {
    Z.closeLayer();
    Z.actions.browseSnap(el);
  };

  /* =================================================================== */
  /* Verlauf                                                              */
  /* =================================================================== */

  const hist = (Z.state.hist = { list: null, job: '', result: '', open: null });
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
          ${ui.seg('histResult', [{ value: '', label: 'Alle' }, { value: 'success', label: 'Erfolgreich' }, { value: 'failed', label: 'Fehler' }, { value: 'dry-run', label: 'Probeläufe' }], hist.result, { act: 'histResult' })}
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
    const job = Z.findJob ? Z.state.overview?.jobs.find((j) => j.job.id === r.job) : null;
    const open = hist.open === r.id;
    return `<tr class="hist-row${open ? ' open' : ''}" data-act="histOpen" data-id="${esc(r.id)}">
        <td>${ui.dot(tone)}</td>
        <td><div class="two"><b>${esc(fmt.when(r.start))}</b><small>${esc(fmt.rel(r.start))}</small></div></td>
        <td>${esc(job ? job.job.name : r.job)}</td>
        <td>${ui.badge(lbl, tone, ic)}</td>
        <td class="num">${fmt.duration(r.duration)}</td>
        <td class="num">${fmt.bytes(r.bytes)}</td>
        <td class="num">${fmt.num(r.files)}</td>
        <td>${icon(open ? 'down' : 'right')}</td>
      </tr>${
        open
          ? `<tr class="hist-detail"><td colspan="8">
          ${r.error ? `<div class="zbg-callout tone-err">${icon('alert')}<div>${esc(r.error)}</div></div>` : ''}
          <div class="hist-meta">${ui.kv([
            ['Snapshot', `<span class="mono">${esc(r.snapshot || '–')}</span>`],
            ['Gelöscht (Retention)', esc(String(r.pruned ?? 0)) + ' Snapshots'],
            ['Ende', esc(fmt.dateFull(r.end))],
          ])}</div>
          <pre class="zbg-term small">${(r.log || ['(kein Log gespeichert)']).map((l) => esc(l)).join('\n')}</pre></td></tr>`
          : ''
      }`;
  }
  Z.actions.histOpen = (el) => {
    hist.open = hist.open === el.dataset.id ? null : el.dataset.id;
    Z.render();
  };
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
          ${['zbg list', 'zbg datasets', 'zbg snapshots cache/appdata', 'zbg import']
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
            ['Phase', `${esc(ov.phase || 1)} von 6`],
            ['Runner', ov.runnerAvailable ? ui.badge('aktiv', 'ok') : ui.badge('folgt in Phase 2', 'idle')],
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
    const layer = Z.modal({ title: `${icon('sparkles')} Aus User Scripts übernehmen`, size: 'lg', body: ui.skeleton(5) });
    let found;
    try {
      found = await Z.api('migration.scan');
    } catch (e) {
      Z.closeLayer(layer);
      return Z.fail(e);
    }
    const existing = new Set((Z.state.overview?.jobs || []).map((j) => j.job.id));
    Z.state.migration = found;
    const body = !found.length
      ? ui.empty('search', 'Nichts gefunden', 'Es wurden keine erkennbaren Backup-Skripte im User-Scripts-Plugin gefunden.')
      : found
          .map(
            (s, si) => `<section class="mig-script">
          <header>${ui.dot(s.active ? 'ok' : 'idle')}<b>${esc(s.script)}</b>${s.active ? ui.badge(Z.cron.describe(s.schedule), 'ok', 'clock') : ui.badge('deaktiviert', 'idle')}<span class="muted">${s.kind === 'rsync-mirror' ? 'rsync-Spiegel (--delete)' : 'SpaceInvaderOne-Skript'}</span></header>
          <div class="mig-jobs">${s.proposals
            .map(
              (p, pi) => `<label class="mig-job${existing.has(p.id) ? ' exists' : ''}">
                <input type="checkbox" data-mig="${si}:${pi}"${existing.has(p.id) || !s.active ? '' : ' checked'}${existing.has(p.id) ? ' disabled' : ''}>
                <div><b>${esc(p.source.dataset)}</b>
                <span class="mono">${p.migration.keepPath ? esc(p.target.path) : `${p.migration.oldTarget ? `<s>${esc(p.migration.oldTarget)}</s> → ` : ''}${esc(p.target.path)}`}</span>
                ${p.migration.keepPath ? `<span class="mig-keep">${icon('cloud')}Pfad bleibt gleich: ${esc(p.migration.downstream)} sichert weiter, nichts umzustellen</span>` : p.migration.oldTarget ? `<span class="mig-keep warn">${icon('info')}Wird bisher von keinem Zerobyte-Plan weitergesichert</span>` : ''}
                <small>${existing.has(p.id) ? 'Bereits als Job vorhanden' : `${p.target.mode === 'mirror' ? 'Spiegel' : 'versioniert'} · ${esc(Z.cron.describe(p.schedule))} · Snapshots ${esc(keepSummary(p))}`}</small></div>
                ${ui.btn('', { act: 'migEdit', icon: 'edit', title: 'Im Assistenten anpassen', data: { ref: `${si}:${pi}` } })}
              </label>`
            )
            .join('')}</div></section>`
          )
          .join('') +
        `<div class="zbg-callout tone-info">${icon('info')}<div><b>Was passiert?</b> Ziele, die Zerobyte bereits weitersichert, behalten ihren Pfad als Spiegel. Neu sind lokale ZFS-Snapshots mit Aufbewahrung, Konsistenz und Überwachung. Alle anderen Ziele bekommen einen versionierten Ordner. Den Zeitplan des User Scripts stellst du danach selbst auf „disabled“. Das Plugin ändert keine fremden Konfigurationen.</div></div>`;
    layer.el.querySelector('.zbg-dialog-body').innerHTML = body;
    const footer = document.createElement('footer');
    footer.innerHTML = found.length ? ui.btn('Abbrechen', { act: 'closeLayer' }) + ui.btn('Ausgewählte Jobs anlegen', { act: 'migImport', kind: 'primary', icon: 'download' }) : ui.btn('Schließen', { act: 'closeLayer' });
    layer.el.querySelector('.zbg-dialog').appendChild(footer);
  };
  const migRef = (ref) => {
    const [si, pi] = ref.split(':').map(Number);
    return JSON.parse(JSON.stringify(Z.state.migration[si].proposals[pi]));
  };
  Z.actions.migEdit = (el) => {
    const p = migRef(el.dataset.ref);
    delete p.migration;
    Z.closeLayer();
    Z.openEditor(p, null);
  };
  Z.actions.migImport = async () => {
    const refs = Z.$$('[data-mig]:checked').map((i) => i.dataset.mig);
    if (!refs.length) return Z.toast('Nichts ausgewählt', 'warn');
    let n = 0;
    for (const r of refs) {
      const p = migRef(r);
      delete p.migration;
      try {
        await Z.api('jobs.save', { job: p });
        n++;
      } catch (e) {
        Z.fail(new Error(`${p.source.dataset}: ${e.message}`));
      }
    }
    Z.closeLayer();
    if (n) Z.toast(`${n} ${n === 1 ? 'Job' : 'Jobs'} angelegt. Bitte jetzt einen Probelauf machen.`);
    Z.loadOverview();
  };
})();
