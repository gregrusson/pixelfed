const OFFLINE_VERSION = 1;
const CACHE_NAME = "offline";
const OFFLINE_URL = "/offline.html";

self.addEventListener("push", (event) => {
	let payload = {};
	try {
		const value = event.data?.json();
		if (value && typeof value === "object" && !Array.isArray(value)) {
			payload = value;
		}
	} catch {
		// Missing or malformed data still produces a generic notification.
	}
	event.waitUntil(self.registration.showNotification(
		typeof payload.title === "string" ? payload.title : "Pixelfed",
		{
			body: typeof payload.body === "string" ? payload.body : "You have a new notification",
			data: { notification_type: "test", url: "/" },
		}
	));
});

self.addEventListener("install", (event) => {
	event.waitUntil(
		(async () => {
			const cache = await caches.open(CACHE_NAME);
			await cache.add(new Request(OFFLINE_URL, { cache: "reload" }));
		})()
	);
	self.skipWaiting();
});

self.addEventListener("activate", (event) => {
	event.waitUntil(
		(async () => {
			if ("navigationPreload" in self.registration) {
				await self.registration.navigationPreload.enable();
			}
		})()
	);
	self.clients.claim();
});

self.addEventListener("fetch", (event) => {
	if (event.request.mode === "navigate") {
		event.respondWith(
			(async () => {
				try {
					const preloadResponse = await event.preloadResponse;
					if (preloadResponse) {
						return preloadResponse;
					}
					const networkResponse = await fetch(event.request);
					return networkResponse;
				} catch (error) {
					const cache = await caches.open(CACHE_NAME);
					const cachedResponse = await cache.match(OFFLINE_URL);
					return cachedResponse;
				}
			})()
		);
	}
});
