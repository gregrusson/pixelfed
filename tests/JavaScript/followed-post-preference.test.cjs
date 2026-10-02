const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');
const vue = require('vue-template-compiler');
const acorn = require('acorn');

for (const file of [
    'resources/assets/components/partials/profile/ProfileSidebar.vue',
    'resources/assets/js/components/Profile.vue',
]) {
    const component = vue.parseComponent(fs.readFileSync(file, 'utf8'));
    assert.deepEqual(vue.compile(component.template.content).errors, []);
    const tree = acorn.parse(component.script.content, { ecmaVersion: 'latest', sourceType: 'module' });
    const exported = tree.body.find(node => node.type === 'ExportDefaultDeclaration').declaration;
    const method = exported.properties.find(node => node.key.name === 'methods').value.properties
        .find(node => node.key.name === 'togglePostNotifications').value;

    function fixture(reject = false) {
        const calls = [];
        const alerts = [];
        const toggle = vm.runInNewContext('(function' + component.script.content.slice(method.start, method.end) + ')', {
            axios: { post: async (url, body) => {
                calls.push([url, JSON.parse(JSON.stringify(body))]);
                if (reject) throw new Error('An accepted follow is required');
                return { data: { following: true, notifying: body.notify } };
            } },
            swal: (...args) => alerts.push(args),
        });
        const state = {
            user: { id: '10' }, profile: { id: '20' }, owner: false,
            relationship: { following: true, notifying: false },
            $emit: (name, data) => { assert.equal(name, 'updateRelationship'); state.relationship = data; },
            $bvToast: { toast: (...args) => alerts.push(args) },
        };
        return { calls, alerts, state, toggle };
    }

    test(file + ': toggles an accepted relationship and reflects the server response', async () => {
        const { calls, state, toggle } = fixture();
        await toggle.call(state);
        assert.equal(state.relationship.notifying, true);
        await toggle.call(state);
        assert.equal(state.relationship.notifying, false);
        assert.deepEqual(calls, [
            ['/api/v1/accounts/20/follow', { notify: true, notify_only: true }],
            ['/api/v1/accounts/20/follow', { notify: false, notify_only: true }],
        ]);
    });

    test(file + ': self and pending-only states never call the follow endpoint', async () => {
        const { calls, state, toggle } = fixture();
        state.relationship = { following: false, requested: true };
        await toggle.call(state);
        state.relationship.following = true;
        state.profile.id = state.user.id;
        state.owner = true;
        await toggle.call(state);
        assert.deepEqual(calls, []);
    });

    test(file + ': a rejected stale toggle leaves displayed preference unchanged', async () => {
        const { calls, alerts, state, toggle } = fixture(true);
        await toggle.call(state);
        assert.equal(calls.length, 1);
        assert.equal(state.relationship.notifying, false);
        assert.equal(alerts.length, 1);
    });
}
