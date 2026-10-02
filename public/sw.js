const OFFLINE_VERSION = 1;
const CACHE_NAME = "offline";
const OFFLINE_URL = "/offline.html";

function sanitizeNotificationPath(value) {
	if (typeof value !== "string" || value.length < 1 || value.length > 2048
		|| value[0] !== "/" || value[1] === "/"
		|| /[^\x21-\x7e]|[\\%]/.test(value)) {
		return "/";
	}
	try {
		const url = new URL(value, self.location.origin);
		const path = url.pathname;
		const canonical = path + url.search + url.hash;
		if (url.origin !== self.location.origin || url.username || url.password
			|| path.includes("//") || /(?:^|\/)\.{1,2}(?:\/|$)/.test(path)
			|| canonical !== value) {
			return "/";
		}
		// Local redirect routes can leave the origin; only reviewed destinations belong here.
		if (path === "/" || path === "/settings/notifications"
			|| /^\/p\/[A-Za-z0-9_]+\/[0-9]+$/.test(path)) {
			return canonical;
		}
	} catch {
		// Unexpected URL input always falls back to the application root.
	}
	return "/";
}

function sanitizeNotificationType(value) {
	return typeof value === "string" && value.length <= 64 && /^[A-Za-z0-9_-]+$/.test(value)
		? value : "unknown";
}

function isNotificationWindow(client) {
	try {
		return (client.frameType === "top-level" || client.frameType === "auxiliary")
			&& typeof client.url === "string"
			&& new URL(client.url).origin === self.location.origin;
	} catch {
		return false;
	}
}

async function focusNotificationWindow(client) {
	try {
		if (typeof client.focus === "function") {
			await client.focus();
		}
	} catch {
		// A closed window or denied focus must not prevent navigation.
	}
}

async function openNotificationWindow(target) {
	try {
		if (typeof self.clients.openWindow === "function") {
			const client = await self.clients.openWindow(target);
			if (client && isNotificationWindow(client)) {
				await focusNotificationWindow(client);
			}
		}
	} catch {
		// Browser activation rules can prevent opening a window.
	}
}

async function navigateNotification(path) {
	const target = new URL(path, self.location.origin).href;
	let windows = [];
	try {
		windows = (await self.clients.matchAll({
			type: "window", includeUncontrolled: true,
		})).filter(isNotificationWindow);
	} catch {
		// Enumeration failure still permits a best-effort open.
	}
	const client = windows.find(window => window.url === target)
		|| windows.find(window => window.focused)
		|| windows.find(window => window.visibilityState === "visible")
		|| windows[0];
	if (client) {
		await focusNotificationWindow(client);
		if (client.url === target) {
			return;
		}
		try {
			if (typeof client.navigate === "function") {
				const navigated = await client.navigate(target);
				if (navigated && isNotificationWindow(navigated)) {
					await focusNotificationWindow(navigated);
					return;
				}
			}
		} catch {
			// Unsupported, closed, or failed clients fall back to opening the target.
		}
	}
	await openNotificationWindow(target);
}

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
			data: {
				notification_type: sanitizeNotificationType(payload.notification_type),
				url: sanitizeNotificationPath(payload.url),
			},
		}
	).catch(() => {
		console.warn("Pixelfed could not display a push notification.");
	}));
});

self.addEventListener("notificationclick", (event) => {
	event.notification.close();
	event.waitUntil((async () => {
		const path = sanitizeNotificationPath(event.notification.data?.url);
		await navigateNotification(path);
	})().catch(() => {
		console.warn("Pixelfed could not handle a notification click.");
	}));
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
