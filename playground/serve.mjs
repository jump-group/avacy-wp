// Serves the published tree from a folder, as CloudFront does: no listing, `/` is index.html.
// The .wasm files are stored brotli-compressed (Content-Encoding: br on S3); here they go out
// decompressed, since browsers do not take br over plain http.
//   node serve.mjs <root> [port]
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript',
  '.css': 'text/css',
  '.json': 'application/json',
  '.wasm': 'application/wasm',
  '.zip': 'application/zip',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
};

export function serve(root, port = 0) {
  const server = http.createServer((req, res) => {
    let p = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    if (p.endsWith('/')) p += 'index.html';
    const f = path.join(root, path.normalize(p));
    if (!f.startsWith(root) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) {
      res.writeHead(403, { 'content-type': 'application/xml' });
      return res.end('<Error><Code>AccessDenied</Code></Error>');
    }
    let body = fs.readFileSync(f);
    if (f.endsWith('.wasm') && body.readUInt32BE(0) !== 0x0061736d) body = zlib.brotliDecompressSync(body);
    res.writeHead(200, { 'content-type': TYPES[path.extname(f)] || 'application/octet-stream', 'cache-control': 'no-cache' });
    res.end(body);
  });
  return new Promise((resolve) => server.listen(port, '127.0.0.1', () => resolve(server)));
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const [root, port = '9500'] = process.argv.slice(2);
  const server = await serve(path.resolve(root), Number(port));
  console.log(`http://127.0.0.1:${server.address().port}/`);
}
