// Tour de Guinée 224 — garde l'application dans le téléphone pour qu'elle s'ouvre sans réseau.
// Les données (api.php) ne passent pas par ici : l'application les garde elle-même.
const CACHE = "tdg224-v8";
const SHELL = ["./", "index.html", "demo.js", "logo.png", "logo2.jpg", "manifest.webmanifest", "icon.svg", "icon-180.png", "icon-192.png", "icon-512.png"];

self.addEventListener("install", e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener("activate", e => {
  e.waitUntil(caches.keys().then(k => Promise.all(k.filter(x => x !== CACHE).map(x => caches.delete(x)))).then(() => self.clients.claim()));
});

// Réseau d'abord (pour avoir la dernière version) ; si le réseau ne répond pas en 4 secondes ou est coupé, copie du téléphone.
function networkFirst(req) {
  return new Promise(resolve => {
    let done = false;
    const fromCache = () => caches.match(req, { ignoreSearch: true }).then(m => m || caches.match("index.html"));
    const timer = setTimeout(() => fromCache().then(m => { if (m && !done) { done = true; resolve(m); } }), 4000);
    fetch(req).then(res => {
      if (res.ok) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(req, copy)); }
      if (!done) { done = true; clearTimeout(timer); resolve(res); }
    }).catch(() => fromCache().then(m => { if (!done) { done = true; clearTimeout(timer); resolve(m || Response.error()); } }));
  });
}

self.addEventListener("fetch", e => {
  const req = e.request, url = new URL(req.url);
  if (req.method !== "GET") return;
  if (url.origin === location.origin) {
    if (url.pathname.endsWith("/api.php")) return;
    e.respondWith(networkFirst(req));
  } else if (url.hostname === "fonts.googleapis.com" || url.hostname === "fonts.gstatic.com") {
    e.respondWith(caches.match(req).then(m => m || fetch(req).then(res => {
      const copy = res.clone(); caches.open(CACHE).then(c => c.put(req, copy)); return res;
    })));
  }
});
