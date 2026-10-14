/* Mock-API für die lokale UI-Vorschau (dev/server.mjs). Daten angelehnt an das echte System. */
(function () {
  'use strict';
  const now = () => Math.floor(Date.now() / 1000);
  const GB = 1024 ** 3;
  const T0 = now();
  const rnd = (a, b) => a + Math.random() * (b - a);
  let seed = 7;
  const srand = () => ((seed = (seed * 16807) % 2147483647) / 2147483647);

  const I = (code, level, msg) => ({ code, level, msg });
  const datasets = [
    ['cache', 740 * GB, '/mnt/cache', 0],
    ['cache/appdata', 182 * GB, '/mnt/cache/appdata', 21 * GB],
    ['cache/appdata/immich', 64 * GB, '/mnt/cache/appdata/immich', 6 * GB],
    ['cache/appdata/postgres', 12 * GB, '/mnt/cache/appdata/postgres', 3 * GB],
    ['cache/appdata/plex', 48 * GB, '/mnt/cache/appdata/plex', 2 * GB],
    ['cache/appdata/plex-transcode', 9 * GB, '/mnt/cache/appdata/plex-transcode', 0],
    ['cache/appdata/old-nextcloud', 6 * GB, '/mnt/cache/appdata/old-nextcloud', 0, { mounted: false, canmount: 'off', issues: [I('unmounted', 'warn', 'Nicht gemountet (canmount=off): rsync überspringt dieses Dataset, zfs send funktioniert')] }],
    ['cache/data', 96 * GB, '/mnt/cache/data', 0],
    ['cache/domains', 120 * GB, '/mnt/cache/domains', 8 * GB],
    ['cache/domains/Github Actions', 38 * GB, '/mnt/cache/domains/Github Actions', 4 * GB, { issues: [I('chars', 'info', 'Enthält Leerzeichen oder Sonderzeichen – wird korrekt behandelt')] }],
    ['cache/domains/win11-disk', 64 * GB, '-', 0, { type: 'volume', issues: [I('volume', 'info', 'Zvol (Blockgerät): keine Dateien, nur per zfs send sicherbar')] }],
    ['cache/fastshare', 22 * GB, '/mnt/cache/fastshare', 0],
    ['cache/logs', 3 * GB, '/mnt/cache/logs', 0],
    ['cache/lxc', 41 * GB, '/mnt/cache/lxc', 5 * GB],
    ['cache/lxc/rootfs-pihole', 2 * GB, '/var/lib/lxc/pihole/rootfs', 0, { issues: [I('detached', 'info', 'Mountpoint außerhalb des Eltern-Datasets (/var/lib/lxc/pihole/rootfs)')] }],
    ['cache/main', 58 * GB, '/mnt/cache/main', 7 * GB],
    ['cache/system', 31 * GB, '/mnt/cache/system', 0],
    ['cache/timemachine', 323 * GB, '/mnt/cache/timemachine', 0],
    ['cache/vault', 4 * GB, '/mnt/cache/vault', 0, { mounted: false, encrypted: true, locked: true, issues: [I('locked', 'warn', 'Verschlüsselt, Schlüssel nicht geladen: nicht gemountet, rsync unmöglich, zfs send nur roh (-w)')] }],
    ['flash', 2.6 * GB, 'none', 0, { mounted: false, issues: [I('unmounted', 'warn', 'Nicht gemountet (mountpoint=none): rsync überspringt dieses Dataset, zfs send funktioniert'), I('bootpool', 'info', 'Liegt im Boot-Pool flash')] }],
    ['flash/boot', 2.5 * GB, '/boot', 0, { canmount: 'noauto', boot: true, issues: [I('boot', 'info', 'Unraid-Boot-Dataset (/boot): Snapshot und Kopie sind ein vollständiges Flash-Backup. Zurückspielen nur manuell.')] }],
  ].map(([name, used, mp, us, o = {}]) => {
    const mounted = o.mounted ?? mp.startsWith('/');
    const type = o.type || 'filesystem';
    const path = type === 'filesystem' && mounted && mp.startsWith('/') ? mp : null;
    return { name, pool: name.split('/')[0], type, used, avail: 172 * GB, refer: used - us, usedSnap: us, mountpoint: mp, path, canmount: o.canmount || 'on', mounted, encrypted: !!o.encrypted || name === 'cache/main', locked: !!o.locked, readonly: false, origin: null, compressratio: +(1 + srand()).toFixed(2), readable: !!path && !o.locked, boot: !!o.boot, bootPool: name.startsWith('flash'), issues: o.issues || [] };
  });

  const pools = [
    { name: 'cache', size: 912 * GB, alloc: 748 * GB, free: 164 * GB, health: 'ONLINE', capacity: 82, fragmentation: 21, boot: false },
    { name: 'flash', size: 15 * GB, alloc: 2.6 * GB, free: 12.4 * GB, health: 'ONLINE', capacity: 17, fragmentation: 3, boot: true },
  ];

  // Zerobyte-Pläne wie auf dem echten System
  const downstream = [
    { tool: 'Zerobyte', name: 'AppData Daily', enabled: true, cron: '00 05 * * *', paths: ['/mnt/user/backup/zfs-test/appdata', '/mnt/user/backup/zfs-test/domains', '/mnt/user/backup/zfs-test/lxc'], retention: { keepDaily: 7, keepWeekly: 5, keepMonthly: 3 }, lastStatus: 'success' },
    { tool: 'Zerobyte', name: 'Cloud-Data', enabled: true, cron: '00 04 * * *', paths: ['/mnt/user/main/seafile-data', '/mnt/user/main/nextcloud'], retention: { keepDaily: 5, keepMonthly: 2 }, lastStatus: 'success' },
    { tool: 'Zerobyte', name: 'Immich', enabled: true, cron: '30 04 * * 1', paths: ['/mnt/user/main/immich'], retention: { keepDaily: 5, keepMonthly: 3 }, lastStatus: 'success' },
  ];
  const within = (root, p) => (p + '/').startsWith(root.replace(/\/$/, '') + '/');
  const downFor = (path) =>
    downstream.flatMap((d) => {
      const pp = d.paths.find((x) => within(x, path) || within(path, x));
      return pp ? [{ ...d, coverage: within(pp, path) ? 'full' : 'partial', matchedPath: pp }] : [];
    });

  function plan(source, recursive, exclude) {
    const root = datasets.find((d) => d.name === source);
    const isEx = (n) => exclude.some((e) => n === e || n.startsWith(e + '/'));
    const members = datasets.filter((d) => d.name === source || (recursive && d.name.startsWith(source + '/')));
    const base = root.readable ? root.path : null;
    const items = members
      .filter((d) => !isEx(d.name))
      .map((d) => {
        const rel = d.name === source ? '' : d.name.slice(source.length + 1);
        const copy = d.readable;
        const targetRel = copy ? (base && within(base, d.path) ? d.path.slice(base.length).replace(/^\//, '') : '.datasets/' + rel) : null;
        const protect = copy ? datasets.filter((o) => o.name !== d.name && o.path && o.path !== d.path && within(d.path, o.path)).map((o) => '/' + o.path.slice(d.path.length).replace(/^\//, '') + '/') : [];
        return { name: d.name, rel, action: copy ? 'copy' : 'snapshot-only', path: d.path, targetRel, protect, used: d.used, issues: d.issues };
      });
    const warnings = [];
    if (root.bootPool) warnings.push({ level: 'info', msg: 'Quelle liegt im Unraid-Boot-Pool. Ideal als Flash-Backup; ein Rollback des laufenden /boot ist nicht vorgesehen.' });
    const so = items.filter((i) => i.action === 'snapshot-only').length;
    if (so) warnings.push({ level: 'warn', msg: `${so} Dataset(s) sind nicht lesbar und werden per rsync nicht kopiert (nur Snapshot)` });
    return { source, items, skipped: members.filter((d) => isEx(d.name)).map((d) => ({ name: d.name, reason: 'excluded' })), warnings };
  }

  const base = {
    enabled: true,
    method: 'rsync',
    schedule: '0 2 * * *',
    snapshots: { keep: { hourly: 0, daily: 7, weekly: 4, monthly: 3, yearly: 0 } },
    versions: { keep: { daily: 14, weekly: 8, monthly: 6 } },
    consistency: { mode: 'crash', containers: [] },
    hooks: { pre: null, post: null },
    notify: 'errors',
    maxAgeHours: 30,
    healthcheckUrl: null,
  };
  const clone = (o) => JSON.parse(JSON.stringify(o));
  const T = (n) => ({ path: '/mnt/user/backup/zfs-test/' + n, mode: 'mirror', dataset: '', ssh: null });
  let jobs = [
    { ...clone(base), id: 'appdata', name: 'Appdata → Array', source: { dataset: 'cache/appdata', recursive: true, exclude: ['cache/appdata/plex-transcode'] }, target: T('appdata'), consistency: { mode: 'pause', containers: ['postgres16', 'mariadb', 'valkey', 'immich_postgres'] } },
    { ...clone(base), id: 'main', name: 'Main → Array', source: { dataset: 'cache/main', recursive: true, exclude: [] }, target: T('main'), schedule: '15 2 * * *' },
    { ...clone(base), id: 'domains', name: 'VMs (domains)', source: { dataset: 'cache/domains', recursive: true, exclude: [] }, target: T('domains'), schedule: '0 3 * * 0' },
    { ...clone(base), id: 'lxc', name: 'LXC → Array', source: { dataset: 'cache/lxc', recursive: true, exclude: [] }, target: T('lxc'), schedule: '30 4 * * *', notify: 'all' },
    { ...clone(base), id: 'boot', name: 'Unraid-Boot (/boot)', enabled: false, source: { dataset: 'flash/boot', recursive: false, exclude: [] }, target: { path: '/mnt/user/backup/zfs/boot', mode: 'versioned', dataset: '', ssh: null }, schedule: '45 1 * * *', snapshots: { keep: { hourly: 0, daily: 14, weekly: 8, monthly: 12, yearly: 2 } } },
  ];

  // Verlauf generieren
  const history = {};
  let hid = 1;
  function genHistory(job, opts) {
    const list = [];
    for (let d = 30; d >= 1; d--) {
      const start = T0 - d * 86400 - (opts.offset || 0);
      if (opts.weekly && d % 7) continue;
      const failed = opts.failLast && d === 1;
      const duration = Math.round(rnd(opts.dur[0], opts.dur[1]) * (d % 9 === 0 ? 2.4 : 1));
      list.push({
        id: 'r' + hid++,
        job: job,
        start,
        end: start + duration,
        duration,
        bytes: Math.round(rnd(opts.bytes[0], opts.bytes[1]) * GB),
        files: Math.round(rnd(200, 9000)),
        result: failed ? 'failed' : d === 12 ? 'dry-run' : 'success',
        snapshot: `zbg_${job}_${new Date(start * 1000).toISOString().slice(0, 10).replace(/-/g, '')}-020000`,
        pruned: d % 7 === 0 ? 2 : 1,
        error: failed ? 'rsync: [receiver] mkstemp "/mnt/user/backup/zfs-test/lxc/…" failed: No space left on device (28)' : null,
        log: failed
          ? ['[03:30:00] Vorprüfung …', '[03:30:01] Pool cache ONLINE', '[03:30:01] Snapshot cache/lxc@zbg_lxc_… erstellt', '[04:31:12] rsync: mkstemp … failed: No space left on device (28)', '[04:31:12] FEHLER: Replikation fehlgeschlagen. Snapshot bleibt erhalten, kein Aufräumen.']
          : ['[02:00:00] Vorprüfung ok (Ziel gemountet, 2,1 TB frei)', '[02:00:01] Container pausiert: postgres16, mariadb, valkey', '[02:00:01] Snapshot erstellt (rekursiv, 6 Datasets)', '[02:00:02] Container fortgesetzt (Ausfall 1,2 s)', '[02:00:02] rsync --link-dest … gestartet', `[02:0${Math.min(9, Math.round(duration / 60))}:00] rsync fertig`, '[02:09:12] Aufräumen: 1 Snapshot gelöscht', '[02:09:12] Fertig ✓'],
      });
    }
    history[job] = list.reverse();
  }
  genHistory('appdata', { dur: [420, 780], bytes: [1.5, 6] });
  genHistory('main', { dur: [120, 300], bytes: [0.2, 1.2], offset: -900 });
  genHistory('domains', { dur: [1500, 2400], bytes: [8, 22], weekly: true, offset: 86400 * 3 });
  genHistory('lxc', { dur: [200, 400], bytes: [0.5, 2], failLast: true, offset: -5400 });
  history.boot = [];

  // Snapshots
  function snapsFor(ds) {
    const out = [];
    const leaf = ds.split('/')[1];
    const job = jobs.find((j) => j.source.dataset === ds || ds.startsWith(j.source.dataset + '/'));
    if (job) {
      const times = [];
      for (let h = 1; h <= 23; h++) if (job.snapshots.keep.hourly) times.push(T0 - h * 3600);
      for (let d = 0; d < 7; d++) times.push(T0 - d * 86400 - 7200);
      for (let w = 1; w <= 4; w++) times.push(T0 - w * 7 * 86400 - 7200);
      for (let m = 1; m <= 3; m++) times.push(T0 - m * 30 * 86400 - 7200);
      [...new Set(times)].sort().forEach((t) => {
        const d = new Date(t * 1000);
        const stamp = `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}-${String(d.getHours()).padStart(2, '0')}${String(d.getMinutes()).padStart(2, '0')}00`;
        out.push({ dataset: ds, snapshot: `zbg_${job.id}_${stamp}`, used: Math.round(rnd(0.05, 3) * GB), refer: Math.round(rnd(30, 60) * GB), creation: t, job: job.id, own: true, kind: 'zbg' });
      });
    }
    if (leaf) {
      out.push({ dataset: ds, snapshot: 'rsync_auto_20261013_020001', used: Math.round(0.8 * GB), refer: 40 * GB, creation: T0 - 86400 - 3000, job: null, own: false, kind: 'userscript' });
      out.push({ dataset: ds, snapshot: 'vor-update-7.4', used: Math.round(4.2 * GB), refer: 38 * GB, creation: T0 - 86400 * 41, job: null, own: false, kind: 'manual' });
      out.push({ dataset: ds, snapshot: 'autosnap_2026-03-01_00:00:01_monthly', used: Math.round(9.1 * GB), refer: 31 * GB, creation: T0 - 86400 * 227, job: null, own: false, kind: 'sanoid' });
    }
    return out.sort((a, b) => a.creation - b.creation);
  }

  const tree = {
    '/': ['config/', 'immich/', 'postgres/', 'nextcloud/', 'vaultwarden/', 'traefik/', 'docker-compose.yml', 'README.md'],
    '/config': ['settings.json', 'secrets.env', 'backup.log'],
    '/nextcloud': ['data/', 'config.php', 'nextcloud.log'],
    '/nextcloud/data': ['Fotos/', 'Dokumente/', 'Steuer 2025.pdf', 'Urlaub Mallorca.zip'],
    '/vaultwarden': ['db.sqlite3', 'db.sqlite3-wal', 'attachments/', 'rsa_key.pem'],
  };
  function browse(p) {
    const items = tree[p] || ['datei-a.txt', 'datei-b.bin', 'unterordner/'];
    return {
      path: p,
      truncated: false,
      entries: items.map((n) => ({ name: n.replace(/\/$/, ''), type: n.endsWith('/') ? 'dir' : 'file', size: n.endsWith('/') ? 4096 : Math.round(rnd(1, 900) * 1024 ** (n.endsWith('.zip') || n.endsWith('.sqlite3') ? 2 : 1)), mtime: T0 - Math.round(rnd(1, 200) * 3600) })).sort((a, b) => (a.type !== 'dir') - (b.type !== 'dir') || a.name.localeCompare(b.name)),
    };
  }

  const containers = [
    ['postgres16', 'postgres:16-alpine', 'running'],
    ['mariadb', 'lscr.io/linuxserver/mariadb', 'running'],
    ['valkey', 'valkey/valkey:8', 'running'],
    ['immich_server', 'ghcr.io/immich-app/immich-server:release', 'running'],
    ['immich_postgres', 'ghcr.io/immich-app/postgres:14-vectorchord', 'running'],
    ['nextcloud', 'lscr.io/linuxserver/nextcloud', 'running'],
    ['plex', 'plexinc/pms-docker', 'running'],
    ['traefik', 'traefik:v3.2', 'running'],
    ['vaultwarden', 'vaultwarden/server', 'running'],
    ['homeassistant', 'ghcr.io/home-assistant/home-assistant', 'exited'],
  ].map(([name, image, state]) => ({ name, image, state, status: state === 'running' ? 'Up 3 days' : 'Exited (0) 2 days ago' }));

  // Laufsimulation
  const runs = {};
  function newRun(job, dryRun) {
    const steps = [
      ['pre', 'Vorprüfung', 'Sperre, Pool, Ziel, Platz'],
      ['pause', 'Container pausieren', job.consistency.containers.join(', ') || '–'],
      ['snap', 'Snapshot erstellen', `@zbg_${job.id}_…`],
      ['resume', 'Container fortsetzen', ''],
      ['copy', dryRun ? 'Replikation (Probelauf)' : 'Replikation', job.method === 'zfs' ? 'zfs send -I' : 'rsync --link-dest'],
      ['prune', 'Aufräumen', 'Retention'],
      ['done', 'Abschluss', 'Verlauf, Benachrichtigung'],
    ].filter((s) => job.consistency.mode !== 'crash' || !['pause', 'resume'].includes(s[0]));
    const dsList = jobs.length && datasets.filter((d) => d.name === job.source.dataset || (job.source.recursive && d.name.startsWith(job.source.dataset + '/') && !job.source.exclude.includes(d.name)));
    const r = { runId: 'run' + Date.now(), job: job.id, jobName: job.name, dryRun, state: 'running', startedAt: now(), tick: 0, pct: 0, rate: 0, eta: null, bytes: 0, files: 0, steps: steps.map(([key, label, detail]) => ({ key, label, detail, state: 'pending' })), datasets: dsList.map((d) => ({ name: d.name, pct: 0, bytes: 0, rate: 0, eta: null, state: 'pending', size: d.used * 0.04 })), log: [{ t: now(), level: 'cmd', msg: `zbg run ${job.id}${dryRun ? ' --dry-run' : ''}` }] };
    runs[r.runId] = r;
    return r;
  }
  function advance(r) {
    if (r.state !== 'running') return;
    r.tick++;
    const log = (msg, level = 'info') => r.log.push({ t: now(), level, msg });
    const cur = r.steps.findIndex((s) => s.state !== 'done');
    if (cur < 0) return;
    const step = r.steps[cur];
    if (step.key === 'copy') {
      step.state = 'active';
      const d = r.datasets.find((x) => x.state !== 'done');
      if (!d) {
        step.state = 'done';
        step.detail = `${(r.bytes / GB).toFixed(1).replace('.', ',')} GB`;
        log('Replikation abgeschlossen', 'ok');
      } else {
        if (d.state === 'pending') log(`rsync ${d.name} → Version ${new Date().toISOString().slice(0, 10)}_020000`, 'cmd');
        d.state = 'active';
        d.rate = rnd(180, 420) * 1024 ** 2;
        const add = Math.max(d.size * rnd(0.18, 0.35), 50 * 1024 ** 2);
        d.bytes = Math.min(d.size, d.bytes + add);
        d.pct = (d.bytes / d.size) * 100;
        d.eta = (d.size - d.bytes) / d.rate;
        r.bytes += add;
        r.files += Math.round(rnd(20, 400));
        r.rate = d.rate;
        if (Math.random() < 0.5) log(`  ${['config/settings.json', 'postgres/base/16384/2619', 'immich/upload/thumbs/a1/b2.webp', 'nextcloud/data/nextcloud.log'][Math.floor(Math.random() * 4)]}`, 'dim');
        if (d.pct >= 100) {
          d.state = 'done';
          log(`✓ ${d.name}: ${(d.bytes / GB).toFixed(2).replace('.', ',')} GB`, 'ok');
        }
      }
    } else {
      step.state = 'done';
      const msgs = { pre: ['Vorprüfung ok: Pool ONLINE, Ziel gemountet, 2,1 TB frei', 'ok'], pause: [`docker pause ${step.detail}`, 'cmd'], snap: [`zfs snapshot -r ${r.job}@zbg_${r.job}_… (12 ms)`, 'cmd'], resume: ['Container fortgesetzt, Ausfall 1,4 s', 'ok'], prune: [r.dryRun ? 'Würde löschen: 1 Snapshot (Probelauf)' : 'Gelöscht: 1 Snapshot, behalten: 14', 'info'], done: ['Fertig ✓', 'ok'] };
      if (msgs[step.key]) log(...msgs[step.key]);
      if (r.steps[cur + 1]) r.steps[cur + 1].state = 'active';
    }
    const doneSteps = r.steps.filter((s) => s.state === 'done').length;
    const dsPct = r.datasets.reduce((s, d) => s + d.pct, 0) / Math.max(r.datasets.length, 1);
    r.pct = Math.min(100, (doneSteps / r.steps.length) * 40 + dsPct * 0.6);
    r.eta = r.datasets.reduce((s, d) => s + (d.state === 'done' ? 0 : (d.size - d.bytes) / (d.rate || 3e8)), 0);
    if (r.steps.every((s) => s.state === 'done')) {
      r.state = 'success';
      r.pct = 100;
      r.endedAt = now();
      history[r.job].unshift({ id: 'r' + hid++, job: r.job, start: r.startedAt, end: now(), duration: now() - r.startedAt, bytes: r.bytes, files: r.files, result: r.dryRun ? 'dry-run' : 'success', snapshot: `zbg_${r.job}_live`, pruned: 1, log: r.log.map((l) => l.msg) });
    }
  }

  function status(job) {
    const runsH = history[job.id] || [];
    const last = runsH[0] || null;
    const lastOk = runsH.find((r) => r.result === 'success') || null;
    const age = lastOk ? now() - lastOk.end : null;
    const state = !job.enabled ? 'disabled' : last && last.result === 'failed' ? 'failed' : !lastOk ? 'never' : age > job.maxAgeHours * 3600 ? 'stale' : 'ok';
    return { state, lastRun: last, lastSuccess: lastOk, ageSeconds: age };
  }

  const delay = (ms) => new Promise((r) => setTimeout(r, ms));
  const err = (m) => {
    throw new Error(m);
  };

  window.ZBG_MOCK = async function (action, p) {
    await delay(action === 'run.status' ? 40 : rnd(120, 380));
    switch (action) {
      case 'overview':
        return {
          version: '2026.10.14',
          phase: 1,
          runnerAvailable: true,
          now: now(),
          pools,
          migration: [{ script: 'zfs snapshot new2093513041', jobs: 4 }],
          jobs: jobs.map((j) => ({ job: j, status: status(j), downstream: j.method === 'rsync' && !j.target.ssh ? downFor(j.target.path) : [], nextRun: j.enabled ? window.ZBG.cron.next(j.schedule) : null, snapshotCount: snapsFor(j.source.dataset).filter((s) => s.own).length, sourceBytes: (datasets.find((d) => d.name === j.source.dataset) || {}).used || 0, runs: (history[j.id] || []).slice(0, 30).reverse().map((r) => ({ start: r.start, duration: r.duration, bytes: r.bytes, result: r.result })) })),
        };
      case 'datasets':
        return { pools, datasets };
      case 'snapshots':
        return snapsFor(p.dataset);
      case 'browse':
        return { dataset: p.dataset, snapshot: p.snapshot, ...browse(p.path || '/') };
      case 'source.plan':
        return plan(p.dataset, p.recursive !== false, p.exclude || []);
      case 'downstream':
        return downstream;
      case 'containers':
        return containers;
      case 'history':
        return Object.values(history).flat().sort((a, b) => b.start - a.start);
      case 'settings.get':
      case 'settings.save':
        return { logDir: '/mnt/cache/system/zfs-backup-guard', globalLock: true, historyLimit: 500, ...(p.settings || {}) };
      case 'target.check': {
        const mounted = p.path.startsWith('/mnt/user/') || p.path.startsWith('/mnt/disk');
        const inside = p.source && p.path.startsWith('/mnt/' + p.source);
        const same = p.path.startsWith('/mnt/cache');
        const down = downFor(p.path);
        return { downstream: down, path: p.path, exists: /zfs(-test)?\/(appdata|main|domains|lxc)$/.test(p.path), mounted, writable: mounted, free: 2.1 * 1024 * GB, fs: 'xfs', checks: [{ ok: mounted, label: mounted ? 'Gemountet (fuse.shfs auf /mnt/user)' : 'Ziel ist nicht gemountet' }, { ok: true, label: 'Share ' + p.path.split('/').slice(0, 4).join('/') + ' vorhanden' }, { ok: mounted, label: mounted ? 'Beschreibbar' : 'Nicht beschreibbar' }, { ok: !inside, label: inside ? 'Ziel liegt in der Quelle!' : 'Ziel liegt außerhalb der Quelle' }, { ok: !same, warn: same, label: same ? 'Gleicher Pool wie die Quelle – schützt nicht vor Pool-Ausfall' : 'Anderes Gerät als die Quelle (Array, XFS)' }, { ok: true, label: 'Freier Platz 2,1 TB (Quelle 182 GB)' }, ...down.map((d) => ({ ok: true, info: true, label: `Wird von ${d.tool}-Plan „${d.name}“ weitergesichert (${d.cron}${d.coverage === 'partial' ? ', nur teilweise' : ''})` }))] };
      }
      case 'jobs.save': {
        const j = p.job;
        if (!j.name) err('Name fehlt');
        j.id = j.id || j.name.toLowerCase().replace(/[^a-z0-9]+/g, '-');
        if (jobs.some((x) => x.id === j.id && x.id !== p.originalId)) err(`Ein Job mit der ID ${j.id} existiert bereits`);
        const i = jobs.findIndex((x) => x.id === p.originalId);
        if (i >= 0) jobs[i] = j;
        else {
          jobs.push(j);
          history[j.id] = [];
        }
        return j;
      }
      case 'jobs.delete':
        jobs = jobs.filter((j) => j.id !== p.id);
        return true;
      case 'jobs.toggle': {
        const j = jobs.find((x) => x.id === p.id);
        j.enabled = p.enabled;
        return j;
      }
      case 'migration.scan':
        return [
          {
            script: 'zfs snapshot new2093513041',
            active: true,
            schedule: '0 2 * * *',
            kind: 'rsync-mirror',
            proposals: ['main', 'appdata', 'domains', 'lxc'].map((n) => {
              const old = '/mnt/user/backup/zfs-test/' + n;
              const d = downFor(old)[0];
              return { ...clone(base), id: n + '-import', name: n[0].toUpperCase() + n.slice(1) + ' → Array', source: { dataset: 'cache/' + n, recursive: true, exclude: [] }, target: d ? { path: old, mode: 'mirror', dataset: '', ssh: null } : { path: '/mnt/user/backup/zfs/' + n, mode: 'versioned', dataset: '', ssh: null }, migration: d ? { oldTarget: old, keepPath: true, downstream: `${d.tool} „${d.name}“ (${d.cron})` } : { oldTarget: old } };
            }),
          },
          { script: 'Autosnapshot', active: false, schedule: null, kind: 'spaceinvader', proposals: [{ ...clone(base), id: 'appdata-2', name: 'Appdata → Array', source: { dataset: 'cache/appdata', recursive: true, exclude: [] }, target: { path: '/mnt/user/backup/zbg/appdata', mode: 'versioned', dataset: '', ssh: null }, migration: { oldReplication: 'rsync' } }] },
        ];
      case 'run.start': {
        const j = jobs.find((x) => x.id === p.id);
        const r = newRun(j, !!p.dryRun);
        return r;
      }
      case 'run.status': {
        const r = runs[p.runId];
        advance(r);
        return JSON.parse(JSON.stringify(r));
      }
      case 'run.cancel': {
        const r = runs[p.runId];
        r.state = 'cancelled';
        r.endedAt = now();
        r.log.push({ t: now(), level: 'warn', msg: 'Abbruch angefordert: SIGTERM, Container werden fortgesetzt' });
        return true;
      }
      case 'restore':
        return { target: '/mnt/cache/appdata/restore-20261014-0200/' };
      case 'cron.next':
        return window.ZBG.cron.nextN(p.expr, 3);
      default:
        err('Unbekannte Aktion: ' + action);
    }
  };
})();
