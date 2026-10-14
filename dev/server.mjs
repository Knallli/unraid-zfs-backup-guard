// Lokale UI-Vorschau mit Mock-API: node dev/server.mjs [port]
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const plugin = join(root, 'src/usr/local/emhttp/plugins/zfs-backup-guard');
const port = Number(process.argv[2] || process.env.PORT || 5178);
const types = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.json': 'application/json' };

createServer(async (req, res) => {
  const url = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  let file;
  if (url === '/' || url === '/index.html') file = join(root, 'dev/index.html');
  else if (url.startsWith('/assets/')) file = join(plugin, normalize(url));
  else if (url.startsWith('/dev/')) file = join(root, normalize(url));
  if (!file || !file.startsWith(root)) {
    res.writeHead(404).end('not found');
    return;
  }
  try {
    const body = await readFile(file);
    res.writeHead(200, { 'Content-Type': types[extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' }).end(body);
  } catch {
    res.writeHead(404).end('not found');
  }
}).listen(port, '127.0.0.1', () => console.log(`ZBG-Vorschau: http://localhost:${port}`));
