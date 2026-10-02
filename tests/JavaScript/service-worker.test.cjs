const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(require('path').join(__dirname, '../../public/sw.js'), 'utf8');
const origin = 'https://pixelfed.example';
const targetPath = '/p/alice/123';
const target = origin + targetPath;
const tests = [];
const test = (name, run) => tests.push({ name, run });
const plain = value => JSON.parse(JSON.stringify(value));

function worker(clients = {}, showNotification) {
    const handlers = {};
    const displays = [];
    const warnings = [];
    const opens = [];
    const context = vm.createContext({
        URL,
        console: { warn: message => warnings.push(message) },
        self: {
            location: { origin },
            clients: Object.assign({
                matchAll: async options => {
                    assert.deepStrictEqual(plain(options), { type: 'window', includeUncontrolled: true });
                    return [];
                },
                openWindow: async url => { opens.push(url); return null; },
            }, clients),
            registration: {
                showNotification: showNotification || (async (title, options) => {
                    displays.push({ title, options: plain(options) });
                }),
            },
            addEventListener: (name, handler) => { handlers[name] = handler; },
        },
    });
    vm.runInContext(source, context, { filename: 'public/sw.js' });
    return { context, handlers, displays, warnings, opens };
}

function windowClient(url = origin + '/i/web', overrides = {}) {
    const calls = [];
    const client = Object.assign({
        url, frameType: 'top-level', focused: false, visibilityState: 'hidden',
        focus: async () => { calls.push(['focus']); return client; },
        navigate: async value => { calls.push(['navigate', value]); return client; },
    }, overrides);
    return { client, calls };
}

function click(instance, data) {
    let closed = false;
    let work;
    instance.handlers.notificationclick({
        notification: { data, close: () => { closed = true; } },
        waitUntil: promise => {
            assert.strictEqual(closed, true, 'notification closes before waitUntil');
            assert.strictEqual(work, undefined, 'one complete workflow');
            assert.strictEqual(typeof promise.then, 'function');
            work = promise;
        },
    });
    assert.strictEqual(closed, true, 'notification closes immediately');
    assert.ok(work, 'waitUntil receives the workflow');
    return work;
}

async function push(instance, data) {
    let work;
    instance.handlers.push({ data, waitUntil: promise => { work = promise; } });
    assert.ok(work && typeof work.then === 'function');
    await work;
}

const validPaths = [
    '/i/web/post/123',
    '/account/follow-requests',
    '/i/web/profile/1', '/i/web/profile/123456789',
    '/', targetPath, '/p/Alice_123/999', '/p/alice/123?foo=bar',
    '/p/alice/123#comments', '/settings/notifications',
    '/?source=pwa', '/settings/notifications?foo=bar#browser',
    '/p/a/0', '/p/_/001',
    '/p/a/1?padding=' + 'a'.repeat(2048 - '/p/a/1?padding='.length),
];
const invalidPaths = [
    '/i/web/post/', '/i/web/post/abc', '/i/web/post/123/',
    '/i/web/post/123?foo=bar', '/i/web/post/123#fragment', '/i/web/post/123/anything',
    '/i/web/post/../123', '/i/web/post/%31%32%33',
    '//evil.example/i/web/post/123', 'https://evil.example/i/web/post/123',
    '/i\\web\\post\\123',
    '/account/follow-requests/', '/account/follow-requests?foo=bar',
    '/account/follow-requests#requests', '/account/follow-requests/1',
    '/account/follow-requests/../settings', '/account/%66ollow-requests',
    '//evil.example/account/follow-requests', 'https://evil.example/account/follow-requests',
    '/account\\follow-requests',
    '/i/web/profile/', '/i/web/profile/abc', '/i/web/profile/123/',
    '/i/web/profile/1/evil', '/i/web/profile/-1', '/i/web/profile/1.0',
    '/i/web/profile/%31', '/i/web/profile/1?next=https://evil.example',
    '/i/web/profile/1#anything', '/i/web/profile/1/../../evil',
    '/i/web/other/1', '/i/web/profile/@bob', '/i/web/profile/_/1',
    'https://evil.example/', 'http://evil.example/', '//evil.example/',
    'javascript:alert(1)', 'data:text/html,...', 'blob:https://evil.example/...',
    '\\evil.example', '\\\\evil.example', '/\\evil.example',
    '/i/redirect?url=https://evil.example/', '/settings/account', '/users/bob',
    '/p/alice/not-a-number', '/p/a-b/123', '/p/alice/123/', '/p//alice/123',
    '/p/alice/./123', '/p/alice/a/../123', '/a/..//evil.example',
    '/%2fevil.example', '/%5cevil.example', '/p/alice/%2e%2e/123',
    '/%252fevil.example', '/%255cevil.example', '/%252e%252e/',
    '/p/alice/123?x=%41', '/p/alice/123#%00', '/p/alice/123?x=%',
    '/p/álîce/123', '/p/alice/123\u202e', '/p/alice/123\u00a0',
    '/p/alice/123\u200b', '/p/alice/123\u2028',
    ' /p/alice/123', '/p/alice/123 ', '/p/alice/123?foo=two words',
    '/p/alice/123?x=\\evil.example', '/p/alice/123?', '/p/alice/123#',
    '', null, undefined, 123, true, {}, [], new String(targetPath),
    '/p/a/1?padding=' + 'a'.repeat(2049 - '/p/a/1?padding='.length),
];
for (let code = 0; code <= 32; code++) {
    invalidPaths.push('/p/alice/123' + String.fromCharCode(code));
}
invalidPaths.push('/p/alice/123' + String.fromCharCode(127));

for (const path of validPaths) {
    test('accepts path ' + String(path).slice(0, 70), () => {
        assert.strictEqual(worker().context.sanitizeNotificationPath(path), path);
    });
}
for (const [index, path] of invalidPaths.entries()) {
    test('rejects path case ' + index, () => {
        assert.strictEqual(worker().context.sanitizeNotificationPath(path), '/');
    });
}

test('sanitizes notification types independently of navigation', () => {
    const sanitize = worker().context.sanitizeNotificationType;
    for (const value of ['comment', 'test', 'A_9-type', 'a'.repeat(64)]) {
        assert.strictEqual(sanitize(value), value);
    }
    for (const value of ['', 'a'.repeat(65), 'a b', 'a\n', 'é', '/', null, 1, {}, new String('test')]) {
        assert.strictEqual(sanitize(value), 'unknown');
    }
});

test('push preserves title/body and only sanitized metadata', async () => {
    const instance = worker();
    await push(instance, { json: () => ({
        title: 'New Comment', body: '@bob commented on your post',
        notification_type: 'comment', url: targetPath,
        account_id: '20', status_id: '200', parent_status_id: '100', arbitrary: { secret: true },
    }) });
    assert.deepStrictEqual(instance.displays, [{
        title: 'New Comment', options: {
            body: '@bob commented on your post', data: { notification_type: 'comment', url: targetPath },
        },
    }]);
});

test('follow-request push-to-click opens the local management page', async () => {
    const instance = worker();
    await push(instance, { json: () => ({
        title: 'Follow Request', body: '@bob requested to follow you',
        notification_type: 'follow_request', url: '/account/follow-requests', account_id: '20',
    }) });
    assert.deepStrictEqual(instance.displays[0].options.data, {
        notification_type: 'follow_request', url: '/account/follow-requests',
    });
    await click(instance, instance.displays[0].options.data);
    assert.deepStrictEqual(instance.opens, [origin + '/account/follow-requests']);
});

test('follow-request push-to-click focuses an existing management window', async () => {
    const existing = windowClient(origin + '/account/follow-requests');
    const instance = worker({ matchAll: async () => [existing.client] });
    await push(instance, { json: () => ({
        notification_type: 'follow_request', url: '/account/follow-requests',
    }) });
    await click(instance, instance.displays[0].options.data);
    assert.deepStrictEqual(existing.calls, [['focus']]);
    assert.deepStrictEqual(instance.opens, []);
});

test('follow push-to-click uses only the local numeric profile destination', async () => {
    const instance = worker();
    const url = '/i/web/profile/20';
    await push(instance, { json: () => ({
        title: 'New Follower', body: '@bob@remote.example followed you',
        notification_type: 'follow', account_id: '20', url,
    }) });
    assert.deepStrictEqual(instance.displays[0].options.data, { notification_type: 'follow', url });
    await click(instance, instance.displays[0].options.data);
    assert.deepStrictEqual(instance.opens, [origin + url]);
});

test('push invalid URL/type become root/unknown without changing empty title/body', async () => {
    const instance = worker();
    await push(instance, { json: () => ({ title: '', body: '', url: invalidPaths[9], notification_type: 'bad type' }) });
    assert.deepStrictEqual(instance.displays[0], {
        title: '', options: { body: '', data: { notification_type: 'unknown', url: '/' } },
    });
});

test('missing, malformed, null, array and primitive push data use defaults', async () => {
    for (const data of [undefined, null, { json: () => { throw new SyntaxError('malformed'); } },
        ...[null, [], ['title'], 1, true, 'text', { title: 1, body: [] }].map(value => ({ json: () => value }))]) {
        const instance = worker();
        await push(instance, data);
        assert.deepStrictEqual(instance.displays[0], {
            title: 'Pixelfed', options: {
                body: 'You have a new notification', data: { notification_type: 'unknown', url: '/' },
            },
        });
    }
});

test('showNotification rejection is contained and reported locally', async () => {
    const instance = worker({}, async () => { throw new Error('denied'); });
    await push(instance);
    assert.strictEqual(instance.warnings.length, 1);
});

test('exact target wins over focused/visible clients and never reloads', async () => {
    const focused = windowClient(undefined, { focused: true });
    const exact = windowClient(target);
    const instance = worker({ matchAll: async () => [focused.client, exact.client] });
    await click(instance, { url: targetPath });
    assert.deepStrictEqual(exact.calls, [['focus']]);
    assert.deepStrictEqual(focused.calls, []);
    assert.deepStrictEqual(instance.opens, []);
});

for (const preference of ['focused', 'visible', 'matchAll order']) {
    test('selection prefers ' + preference + ' and navigates only one window', async () => {
        const first = windowClient(undefined, preference === 'focused' ? { visibilityState: 'visible' } : {});
        const chosen = windowClient(undefined, preference === 'focused' ? { focused: true }
            : preference === 'visible' ? { visibilityState: 'visible' } : {});
        const windows = preference === 'matchAll order' ? [chosen.client, first.client] : [first.client, chosen.client];
        const instance = worker({ matchAll: async () => windows });
        await click(instance, { url: targetPath });
        assert.deepStrictEqual(chosen.calls, [['focus'], ['navigate', target], ['focus']]);
        assert.deepStrictEqual(first.calls, []);
        assert.deepStrictEqual(instance.opens, []);
    });
}

test('navigate result receives final focus', async () => {
    const result = windowClient(target);
    const initial = windowClient(undefined, { navigate: async url => {
        assert.strictEqual(url, target);
        return result.client;
    } });
    await click(worker({ matchAll: async () => [initial.client] }), { url: targetPath });
    assert.deepStrictEqual(initial.calls, [['focus']]);
    assert.deepStrictEqual(result.calls, [['focus']]);
});

test('only same-origin top-level/auxiliary clients are eligible', async () => {
    const excluded = [
        windowClient(target, { frameType: 'nested' }),
        windowClient('https://evil.example/', { focused: true }),
        windowClient('not a URL'), windowClient(target, { frameType: 'none' }),
        windowClient(target, { frameType: undefined }),
    ];
    const auxiliary = windowClient(undefined, { frameType: 'auxiliary' });
    const instance = worker({ matchAll: async () => [...excluded.map(value => value.client), auxiliary.client] });
    await click(instance, { url: targetPath });
    assert.deepStrictEqual(auxiliary.calls, [['focus'], ['navigate', target], ['focus']]);
    excluded.forEach(value => assert.deepStrictEqual(value.calls, []));
});

test('no usable window opens the target; opened result is focused', async () => {
    const opened = windowClient(target);
    let openedUrl;
    const instance = worker({
        matchAll: async () => [windowClient(target, { frameType: 'nested' }).client],
        openWindow: async url => { openedUrl = url; return opened.client; },
    });
    await click(instance, { url: targetPath });
    assert.strictEqual(openedUrl, target);
    assert.deepStrictEqual(opened.calls, [['focus']]);
});

for (const matchAll of [() => { throw new Error('sync'); }, async () => { throw new Error('async'); }]) {
    test('matchAll failure falls back to openWindow', async () => {
        const instance = worker({ matchAll });
        await click(instance, { url: targetPath });
        assert.deepStrictEqual(instance.opens, [target]);
    });
}

for (const navigate of [undefined, () => { throw new Error('sync'); },
    async () => { throw new Error('async'); }, async () => null]) {
    test('unavailable/failed/null navigation opens target', async () => {
        const existing = windowClient(undefined, { navigate });
        const instance = worker({ matchAll: async () => [existing.client] });
        await click(instance, { url: targetPath });
        assert.deepStrictEqual(instance.opens, [target]);
        assert.deepStrictEqual(existing.calls, [['focus']]);
    });
}

for (const focus of [undefined, () => { throw new Error('sync'); }, async () => { throw new Error('async'); }]) {
    test('unavailable/failed focus does not suppress navigation', async () => {
        const existing = windowClient(undefined, { focus });
        const instance = worker({ matchAll: async () => [existing.client] });
        await click(instance, { url: targetPath });
        assert.deepStrictEqual(existing.calls, [['navigate', target]]);
        assert.deepStrictEqual(instance.opens, []);
    });
    test('exact target with unavailable/failed focus settles without navigation', async () => {
        const existing = windowClient(target, { focus });
        const instance = worker({ matchAll: async () => [existing.client] });
        await click(instance, { url: targetPath });
        assert.deepStrictEqual(existing.calls, []);
        assert.deepStrictEqual(instance.opens, []);
    });
}

test('every malicious stored URL is revalidated before navigate or openWindow', async () => {
    for (const url of invalidPaths) {
        const existing = windowClient();
        const reuse = worker({ matchAll: async () => [existing.client] });
        await click(reuse, { url, notification_type: 'comment' });
        assert.deepStrictEqual(existing.calls, [['focus'], ['navigate', origin + '/'], ['focus']]);
        const open = worker();
        await click(open, { url, notification_type: 'comment' });
        assert.deepStrictEqual(open.opens, [origin + '/']);
    }
});

test('old notifications and malformed/missing data use root', async () => {
    for (const data of [undefined, null, {}, [], 1, 'malformed', { notification_type: 'test', url: '/' }]) {
        const instance = worker();
        await click(instance, data);
        assert.deepStrictEqual(instance.opens, [origin + '/']);
    }
});

test('local external redirect route is explicitly rejected on push and click', async () => {
    const instance = worker();
    const url = '/i/redirect?url=https://evil.example/';
    assert.strictEqual(instance.context.sanitizeNotificationPath(url), '/');
    await push(instance, { json: () => ({ url, notification_type: 'comment' }) });
    assert.strictEqual(instance.displays[0].options.data.url, '/');
    await click(instance, { url, notification_type: 'test' });
    assert.deepStrictEqual(instance.opens, [origin + '/']);
});

test('push-to-click preserves safe query/hash and ignores notification type for navigation', async () => {
    const instance = worker();
    const url = targetPath + '?foo=bar#comments';
    await push(instance, { json: () => ({ url, notification_type: 'invalid type' }) });
    assert.deepStrictEqual(instance.displays[0].options.data, { url, notification_type: 'unknown' });
    await click(instance, instance.displays[0].options.data);
    assert.deepStrictEqual(instance.opens, [origin + url]);
});

for (const openWindow of [undefined, () => { throw new Error('sync'); },
    async () => { throw new Error('async'); }, async () => null]) {
    test('unavailable/failed/null openWindow settles', async () => {
        await click(worker({ openWindow }), { url: targetPath });
    });
}

test('combined match/focus/navigate/open failures all settle', async () => {
    const existing = windowClient(undefined, {
        focus: async () => { throw new Error('focus'); },
        navigate: async () => { throw new Error('navigate'); },
    });
    const failOpen = async () => { throw new Error('open'); };
    await click(worker({ matchAll: async () => [existing.client], openWindow: failOpen }), { url: targetPath });
    await click(worker({ matchAll: async () => { throw new Error('match'); }, openWindow: failOpen }), { url: targetPath });
});

test('waitUntil covers pending navigation and final focus', async () => {
    let releaseNavigate;
    let releaseFocus;
    const navigation = new Promise(resolve => { releaseNavigate = resolve; });
    const finalFocus = new Promise(resolve => { releaseFocus = resolve; });
    const result = windowClient(target, { focus: () => finalFocus });
    const existing = windowClient(undefined, { navigate: () => navigation });
    let settled = false;
    const work = click(worker({ matchAll: async () => [existing.client] }), { url: targetPath });
    work.then(() => { settled = true; });
    await new Promise(resolve => setImmediate(resolve));
    assert.strictEqual(settled, false);
    releaseNavigate(result.client);
    await new Promise(resolve => setImmediate(resolve));
    assert.strictEqual(settled, false);
    releaseFocus(result.client);
    await work;
    assert.strictEqual(settled, true);
});

test('waitUntil covers pending openWindow', async () => {
    let release;
    let settled = false;
    const opened = new Promise(resolve => { release = resolve; });
    const work = click(worker({ openWindow: () => opened }), { url: targetPath });
    work.then(() => { settled = true; });
    await new Promise(resolve => setImmediate(resolve));
    assert.strictEqual(settled, false);
    release(null);
    await work;
    assert.strictEqual(settled, true);
});

(async () => {
    const unhandled = [];
    const onUnhandled = error => unhandled.push(error);
    process.on('unhandledRejection', onUnhandled);
    try {
        for (const { name, run } of tests) {
            try { await run(); } catch (error) {
                error.message = name + ': ' + error.message;
                throw error;
            }
        }
        await new Promise(resolve => setImmediate(resolve));
        assert.deepStrictEqual(unhandled, [], 'no unhandled rejection');
        console.log('Passed ' + tests.length + ' service-worker tests.');
    } finally {
        process.removeListener('unhandledRejection', onUnhandled);
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
