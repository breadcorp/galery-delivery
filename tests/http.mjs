// Run against three local PHP workers sharing ONLY disposable test storage.
// node tests/http.mjs path/to/preview-login.json path/to/test-photo.jpg
import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
const login = JSON.parse(await fs.readFile(process.argv[2], 'utf8'));
const image = await fs.readFile(process.argv[3]);
const ports = (process.env.DOWNLOADS_TEST_PORTS || '8785,8786,8787').split(',').map(Number);
assert(ports.length === 3 && ports.every(port => Number.isInteger(port) && port >= 1024 && port <= 65535));
const bases = ports.map(port => `http://127.0.0.1:${port}/downloads`);
let cookie = '';
async function request(path, options = {}, base = bases[0]) {
  const response = await fetch(base + path, { redirect: 'manual', ...options, headers: { Cookie: cookie, ...options.headers } });
  for (const entry of response.headers.getSetCookie()) cookie = entry.split(';')[0];
  return response;
}
function csrf(html) { const match = html.match(/name="csrf" value="([^"]+)"/); assert(match, 'Missing CSRF'); return match[1]; }
let response = await request('/admin');
const token = csrf(await response.text());
response = await request('/admin/login', { method: 'POST', body: new URLSearchParams({ csrf: token, username: login.username, password: login.password }) });
assert.equal(response.status, 302, 'Admin login');
response = await request('/admin/create');
const adminToken = csrf(await response.text());
response = await request('/admin/create', { method: 'POST', body: new URLSearchParams({ csrf: adminToken, name: 'HTTP regression ' + Date.now(), password: 'TestGallery-2026' }) });
assert.equal(response.status, 302);
const id = response.headers.get('location').split('/').pop();
const route = `/admin/gallery/${id}`;
async function upload(index, uploadId, content = image, name = `test-${index}.jpg`) {
  const body = new FormData(); body.append('csrf', adminToken); body.append('upload_id', uploadId);
  body.append('photos[]', new Blob([content], { type: 'image/jpeg' }), name);
  const r = await request(route + '/upload', { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } }, bases[index % 3]);
  const text = await r.text();
  let data; try { data = JSON.parse(text); } catch (_) { throw new Error('Invalid upload response: ' + text.slice(0, 200)); }
  return { status: r.status, data };
}
const ids = Array.from({ length: 3 }, () => crypto.randomBytes(16).toString('hex'));
const uploads = await Promise.all(ids.map((uploadId, i) => upload(i, uploadId)));
assert(uploads.every(item => item.status === 200 && item.data.ok));
assert.deepEqual(uploads.map(item => item.data.file_count).sort(), [1, 2, 3], 'Concurrent uploads lost metadata');
const retry = await upload(0, ids[0]);
assert.equal(retry.data.file_count, 3, 'Retry duplicated photo');
const invalid = await upload(0, crypto.randomBytes(16).toString('hex'), Buffer.from('not an image'));
assert.equal(invalid.status, 422, 'Invalid JPEG accepted');
response = await request(route);
let html = await response.text();
assert(html.includes('data-upload-queue') && html.includes('upload.js'));
const photo = html.match(new RegExp(id + '/photo/([0-9a-f]+)'))?.[1] || html.match(new RegExp(id + '/preview/([0-9a-f]+)'))?.[1];
assert(photo, 'No uploaded photo in gallery');
const publicPath = `/${id}/photo/${photo}`;
const anonymous = await fetch(bases[0] + publicPath, { redirect: 'manual' });
assert.equal(anonymous.status, 403, 'Anonymous download allowed');
response = await request('/' + id);
const galleryToken = csrf(await response.text());
response = await request(`/${id}/unlock`, { method: 'POST', body: new URLSearchParams({ csrf: galleryToken, password: 'TestGallery-2026' }) });
assert.equal(response.status, 302);
for (const [range, expected, status] of [
  ['', image, 200], ['bytes=0-9', image.subarray(0, 10), 206],
  ['bytes=-17', image.subarray(-17), 206], ['bytes=11-', image.subarray(11), 206],
  [`bytes=${image.length}-`, Buffer.alloc(0), 416],
]) {
  response = await request(publicPath, { headers: range ? { Range: range } : {} });
  assert.equal(response.status, status, range);
  const body = Buffer.from(await response.arrayBuffer());
  assert.deepEqual(body, expected, 'Wrong Range body: ' + range);
  assert.equal(Number(response.headers.get('content-length')), expected.length);
}
response = await request(publicPath, { method: 'HEAD' });
assert.equal(response.status, 200); assert.equal((await response.arrayBuffer()).byteLength, 0);
assert.equal(Number(response.headers.get('content-length')), image.length);
const etag = response.headers.get('etag');
if (process.env.DOWNLOADS_TEST_BACKEND === 'x-accel') assert.match(etag, /^"[0-9a-f]+-[0-9a-f]+"$/, 'Expected Nginx file validator after X-Accel handoff');
response = await request(publicPath, { headers: { Range: 'bytes=10-19', 'If-Range': etag } });
assert.equal(response.status, 206); await response.arrayBuffer();
response = await request(publicPath, { headers: { Range: 'bytes=10-19', 'If-Range': '"stale"' } });
assert.equal(response.status, 200); await response.arrayBuffer();
const zips = await Promise.all(bases.map(base => request(`/${id}/full.zip`, {}, base)));
for (const zip of zips) { assert.equal(zip.status, 200); assert.equal(Buffer.from(await zip.arrayBuffer()).subarray(0, 2).toString(), 'PK'); }
response = await request(route); html = await response.text();
assert(html.includes('<span>Photo downloads</span><strong>2</strong>'), 'HEAD/resume/error requests inflated photo count');
assert(html.includes('<span>ZIP downloads</span><strong>3</strong>'));
response = await request(route + '/password', { method: 'POST', body: new URLSearchParams({ csrf: adminToken, password: 'ChangedGallery-2026' }) });
assert.equal(response.status, 302);
response = await request(publicPath); assert.equal(response.status, 403, 'Password rotation did not revoke session');
for (const path of ['/app/settings.json', '/config.php', '/tests/run.php', '/deploy/dev-router.php', '/.git/config']) {
  response = await request(path); assert.equal(response.status, 403, 'Internal path exposed: ' + path);
}
console.log('OK: HTTP integration on ports ' + ports.join(',') + ': parallel uploads, retry, validation, auth, ranges, HEAD, If-Range, ZIP, counters, rotation, private paths');
console.log('Disposable gallery: ' + id);
