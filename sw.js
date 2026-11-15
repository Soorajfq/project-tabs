// Keeps the app's own CSS, JS and icons available. Pages, the API and your files always come from the server.
var CACHE = 'pt-assets-v1';
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});
self.addEventListener('fetch', function (e) {
  var r = e.request;
  if (r.method !== 'GET') return;
  var u = new URL(r.url);
  if (u.origin !== location.origin || u.pathname.indexOf('/assets/') === -1) return;
  e.respondWith(caches.open(CACHE).then(function (c) {
    return fetch(r).then(function (res) { if (res.ok) c.put(r, res.clone()); return res; })
      .catch(function () { return c.match(r); });
  }));
});
