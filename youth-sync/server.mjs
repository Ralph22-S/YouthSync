import http from 'node:http';
import https from 'node:https';
import fs from 'node:fs';
import path from 'node:path';

const port = Number(process.env.PORT || 8080);
const upstream = new URL(process.env.API_UPSTREAM || 'https://youthsync-production.up.railway.app');
const dist = path.resolve('dist');
const client = upstream.protocol === 'https:' ? https : http;

const types = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.json': 'application/json',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
};

const contentType = (file) => types[path.extname(file).toLowerCase()] || 'application/octet-stream';

const sendFile = (res, file, missingIsSpa) => {
  fs.readFile(file, (error, data) => {
    if (error) {
      if (!missingIsSpa) {
        res.writeHead(404);
        res.end('Not found');
        return;
      }
      fs.readFile(path.join(dist, 'index.html'), (spaError, html) => {
        if (spaError) {
          res.writeHead(404);
          res.end('Not found');
          return;
        }
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(html);
      });
      return;
    }
    res.writeHead(200, { 'Content-Type': contentType(file), 'Cache-Control': file.includes(`${path.sep}assets${path.sep}`) ? 'public, max-age=31536000' : 'no-cache' });
    res.end(data);
  });
};

const proxyApi = (req, res, url) => {
  const targetPath = url.pathname.replace(/^\/api/, '') || '/';
  const headers = { ...req.headers, host: upstream.host };
  const proxyReq = client.request({
    protocol: upstream.protocol,
    hostname: upstream.hostname,
    port: upstream.port || (upstream.protocol === 'https:' ? 443 : 80),
    method: req.method,
    path: `${targetPath}${url.search}`,
    headers,
  }, (proxyRes) => {
    const responseHeaders = { ...proxyRes.headers };
    const cookies = proxyRes.headers['set-cookie'];
    if (Array.isArray(cookies)) {
      responseHeaders['set-cookie'] = cookies.map((cookie) => cookie.replace(/;\s*Domain=[^;]*/i, ''));
    }
    res.writeHead(proxyRes.statusCode || 502, responseHeaders);
    proxyRes.pipe(res);
  });
  proxyReq.on('error', () => {
    if (!res.headersSent) res.writeHead(502, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ success: false, error: { code: 'UPSTREAM', message: 'The YouthSync API is unavailable.' } }));
  });
  req.pipe(proxyReq);
};

const server = http.createServer((req, res) => {
  const url = new URL(req.url || '/', 'http://localhost');
  if (url.pathname === '/api' || url.pathname.startsWith('/api/')) {
    proxyApi(req, res, url);
    return;
  }

  const requested = path.normalize(decodeURIComponent(url.pathname)).replace(/^(\.\.[/\\])+/, '');
  const file = path.join(dist, requested);
  if (!file.startsWith(dist)) {
    res.writeHead(400);
    res.end('Bad request');
    return;
  }
  fs.stat(file, (error, stat) => {
    if (!error && stat.isDirectory()) {
      sendFile(res, path.join(file, 'index.html'), true);
      return;
    }
    sendFile(res, file, true);
  });
});

server.listen(port, '0.0.0.0');
