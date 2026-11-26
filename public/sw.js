let cacheName = "bruno-shell-v59";
let offlinePage = "/assets/offline.html?v=44";
let shell = [
  "/assets/app.css?v=44",
  "/assets/app.js?v=40",
  "/assets/nunito-latin.woff2",
  "/assets/nunito-latin-ext.woff2",
  "/assets/noto-color-emoji-buttons.woff2",
  "/assets/canvas-confetti-1.9.4.js",
  "/assets/icon.svg?v=4",
  "/assets/bruno-pause.svg?v=2",
  offlinePage,
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(cacheName)
      .then((cache) =>
        cache.addAll(
          shell.map((asset) => new Request(asset, { cache: "reload" })),
        ),
      )
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter(
              (key) => key.startsWith("bruno-shell-") && key !== cacheName,
            )
            .map((key) => caches.delete(key)),
        ),
      )
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (event) => {
  let url = new URL(event.request.url);
  if (event.request.method !== "GET" || url.origin !== self.location.origin) {
    return;
  }
  if (event.request.mode === "navigate") {
    event.respondWith(
      fetch(event.request).catch(() =>
        caches.match(offlinePage, { cacheName }),
      ),
    );
    return;
  }
  if (shell.includes(url.pathname + url.search)) {
    event.respondWith(
      fetch(event.request).catch(() =>
        caches.match(event.request, { cacheName }),
      ),
    );
  }
});
