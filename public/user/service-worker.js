const USER_SHELL_CACHE = "ibems-user-shell-v7";
const USER_OFFLINE_URL = "/user/offline.html";
const USER_SHELL_ASSETS = new Set([
    USER_OFFLINE_URL,
    "/assets/css/app.css",
    "/assets/css/modern-ui.css",
    "/assets/css/user-mobile.css",
    "/assets/css/user-portal.css",
    "/assets/css/password-visibility.css",
    "/assets/js/user-mobile.js",
    "/assets/js/user-navigation.js",
    "/assets/images/ibems-logo.png",
    "/assets/images/ibems-app-background.jpg",
    "/assets/images/ibems-user-icon-192.png",
    "/assets/images/ibems-user-icon-512.png"
]);

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches.open(USER_SHELL_CACHE)
            .then((cache) => cache.addAll(Array.from(USER_SHELL_ASSETS)))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys
                .filter((key) => key.startsWith("ibems-user-shell-") && key !== USER_SHELL_CACHE)
                .map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener("fetch", (event) => {
    const request = event.request;
    if (request.method !== "GET") return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    const isUserNavigation = request.mode === "navigate" && url.pathname.startsWith("/user/");
    if (isUserNavigation) {
        event.respondWith(fetch(request).catch(() => caches.match(USER_OFFLINE_URL)));
        return;
    }

    if (!USER_SHELL_ASSETS.has(url.pathname)) return;
    event.respondWith(
        caches.match(request).then((cached) => cached || fetch(request).then((response) => {
            if (!response.ok) return response;
            const copy = response.clone();
            caches.open(USER_SHELL_CACHE).then((cache) => cache.put(request, copy));
            return response;
        }))
    );
});
