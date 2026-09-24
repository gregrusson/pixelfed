const SUBSCRIPTION_URL = '/api/v1/push/subscription';

function vapidKeyToBytes(key) {
    const base64 = key.replace(/-/g, '+').replace(/_/g, '/');
    const decoded = atob(base64.padEnd(Math.ceil(base64.length / 4) * 4, '='));
    return Uint8Array.from(decoded, character => character.charCodeAt(0));
}

function supportError() {
    if (!window.isSecureContext) {
        return 'Browser notifications require HTTPS or localhost.';
    }
    if (!('Notification' in window)) {
        return 'This browser does not support notifications.';
    }
    if (!('serviceWorker' in navigator)) {
        return 'This browser does not support service workers.';
    }
    if (!('PushManager' in window)) {
        return 'This browser does not support push notifications.';
    }
    return null;
}

function errorMessage(error) {
    const status = error.response && error.response.status;
    if (status === 409) {
        return 'This browser subscription is already associated with another account on this Pixelfed instance. Sign in to that account to disable it there before enabling it here.';
    }
    if (status === 401 || status === 403) {
        return 'Your session cannot manage browser notifications. Please sign in again and retry.';
    }
    if (status === 503) {
        return 'Browser notifications are not configured on this Pixelfed instance. Please try again later.';
    }
    if (status) {
        return `Pixelfed could not update the subscription (HTTP ${status}). Please retry.`;
    }
    if (error.name === 'NotAllowedError') {
        return 'The browser blocked notifications. Change its permission for this site to enable them.';
    }
    return error.message || 'Could not update browser notifications. Please retry.';
}

export default function initWebPushSettings() {
    const root = document.getElementById('browser-notifications');
    if (!root) {
        return;
    }

    const status = root.querySelector('[data-push-status]');
    const permission = root.querySelector('[data-push-permission]');
    const subscriptionState = root.querySelector('[data-push-subscription]');
    const message = root.querySelector('[data-push-message]');
    const enableButton = root.querySelector('[data-push-enable]');
    const disableButton = root.querySelector('[data-push-disable]');
    let subscription = null;
    let busy = true;
    let notice = null;
    let error = null;
    let serverConfirmed = false;
    let ownershipConflict = false;

    function render() {
        const unsupported = supportError();
        const permissionState = 'Notification' in window ? Notification.permission : 'unavailable';
        permission.textContent = permissionState;
        subscriptionState.textContent = busy ? 'Checking…' : (subscription ? 'Present' : 'None');

        if (busy) {
            status.textContent = 'Checking browser notifications…';
        } else if (unsupported) {
            status.textContent = 'Browser notifications are not supported';
        } else if (permissionState === 'denied') {
            status.textContent = 'Notifications are blocked by the browser';
        } else if (ownershipConflict && subscription) {
            status.textContent = 'This browser subscription belongs to another account';
        } else if (error && subscription) {
            status.textContent = 'A browser subscription exists, but setup needs attention';
        } else if (subscription && serverConfirmed) {
            status.textContent = 'Notifications are enabled on this device';
        } else if (subscription) {
            status.textContent = 'A browser subscription exists. Click Enable to confirm it for this account';
        } else {
            status.textContent = 'Notifications are available but not enabled';
        }

        enableButton.classList.toggle('d-none', busy || !!unsupported || permissionState === 'denied');
        disableButton.classList.toggle('d-none', busy || !subscription || ownershipConflict);
        enableButton.disabled = busy;
        disableButton.disabled = busy;

        const feedback = error || unsupported || notice;
        message.classList.toggle('d-none', !feedback);
        message.classList.toggle('alert-danger', !!error);
        message.classList.toggle('alert-warning', !error && !!unsupported);
        message.classList.toggle('alert-success', !error && !unsupported && !!notice);
        message.textContent = feedback || '';
    }

    async function currentRegistration() {
        const registration = await navigator.serviceWorker.getRegistration('/');
        const worker = registration && (registration.active || registration.waiting || registration.installing);
        if (worker && worker.scriptURL !== new URL('/sw.js', window.location.href).href) {
            throw new Error('A different service worker controls this site. Please reload before managing browser notifications.');
        }
        return registration;
    }

    async function refreshSubscription() {
        const registration = await currentRegistration();
        subscription = registration && registration.pushManager
            ? await registration.pushManager.getSubscription()
            : null;
        if (!subscription) {
            serverConfirmed = false;
            ownershipConflict = false;
        }
    }

    async function run(action) {
        if (busy) {
            return;
        }
        busy = true;
        error = null;
        notice = null;
        render();
        try {
            await action();
        } catch (caught) {
            error = errorMessage(caught);
            if (caught.response && caught.response.status === 409) {
                ownershipConflict = true;
                serverConfirmed = false;
            }
        }
        try {
            await refreshSubscription();
        } catch (caught) {
            error = error || errorMessage(caught);
        }
        busy = false;
        render();
    }

    enableButton.addEventListener('click', () => run(async () => {
        if (supportError()) {
            throw new Error(supportError());
        }
        // Keep the permission request in the explicit click path, before other awaits.
        const granted = Notification.permission === 'granted'
            ? 'granted'
            : await Notification.requestPermission();
        if (granted !== 'granted') {
            throw new Error('Notification permission was not granted.');
        }

        let registration = await currentRegistration();
        if (!registration) {
            // The main app also registers this exact worker and scope on load.
            registration = await navigator.serviceWorker.register('/sw.js');
        }
        if (!registration.active) {
            registration = await Promise.race([
                navigator.serviceWorker.ready,
                new Promise((_, reject) => setTimeout(() => reject(new Error('The service worker did not become ready. Please reload and retry.')), 15000)),
            ]);
        }
        if (!registration.pushManager) {
            throw new Error('Push subscriptions are unavailable in this browser.');
        }

        let existing = await registration.pushManager.getSubscription();
        if (!existing) {
            const response = await window.axios.get('/api/v2/instance');
            const key = response.data && response.data.configuration && response.data.configuration.vapid
                && response.data.configuration.vapid.public_key;
            if (!key || typeof key !== 'string') {
                throw new Error('This Pixelfed instance has no VAPID public key configured.');
            }
            existing = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: vapidKeyToBytes(key),
            });
        }
        await window.axios.post(SUBSCRIPTION_URL, { subscription: existing.toJSON() });
        serverConfirmed = true;
        ownershipConflict = false;
        notice = 'Browser notifications are enabled on this device.';
    }));

    disableButton.addEventListener('click', () => run(async () => {
        const registration = await currentRegistration();
        const existing = registration && registration.pushManager
            ? await registration.pushManager.getSubscription()
            : null;
        if (!existing) {
            notice = 'No browser subscription was found.';
            return;
        }
        await window.axios.delete(SUBSCRIPTION_URL, {
            data: { subscription: { endpoint: existing.endpoint } },
        });
        serverConfirmed = false;
        let removed;
        try {
            removed = await existing.unsubscribe();
        } catch (caught) {
            throw new Error('Pixelfed removed the subscription, but the browser could not unsubscribe. Please retry disabling it.');
        }
        if (!removed) {
            throw new Error('Pixelfed removed the subscription, but the browser could not unsubscribe. Please retry disabling it.');
        }
        notice = 'Browser notifications are disabled on this device.';
    }));

    render();
    if (supportError()) {
        busy = false;
        render();
        return;
    }
    refreshSubscription().catch(caught => {
        error = errorMessage(caught);
    }).finally(() => {
        busy = false;
        render();
    });
}
