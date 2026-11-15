const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('resources/js/app.js', 'utf8').slice(fs.readFileSync('resources/js/app.js', 'utf8').indexOf('function initStoryLens()'));
function fixture(states = ['pending', 'pending'], present = true) {
    const timers = new Map();
    let serial = 0;
    const sections = states.map(state => ({
        dataset: { state }, status: { textContent: state }, list: { children: ['old'], replaceChildren(...items) { this.children = items; } },
        querySelector(selector) { return selector === '[data-section-status]' ? this.status : this.list; },
    }));
    const root = { dataset: { pollUrl: '/stories/id/sections' }, querySelector: selector => sections[selector.includes('timeline') ? 0 : 1] };
    const listeners = {};
    const document = { hidden: false, getElementById: () => present ? root : null, createElement: () => ({textContent: '', append(...nodes) { this.nodes = nodes; this.textContent = nodes.map(node => typeof node === 'string' ? node : node.textContent).join(''); }}), addEventListener: (name, fn) => { listeners[name] = fn; } };
    let response;
    let calls = 0;
    const context = vm.createContext({ document, AbortController, setTimeout: (fn, ms) => { timers.set(++serial, { fn, ms }); return serial; }, clearTimeout: id => timers.delete(id), fetch: async () => { calls++; if (response instanceof Error) throw response; return response; } });
    vm.runInContext(source + '\ninitStoryLens();', context);
    return {
        sections, timers, document, listeners, calls: () => calls,
        reply: value => { response = value; },
        async tick() { const [id, timer] = [...timers][0]; timers.delete(id); await timer.fn(); },
    };
}
function reply(timeline, graph, value = '<img src=x onerror=alert(1)>') {
    return { ok: true, json: async () => ({ sections: { timeline: { state: timeline, data: [{ story_id: 'story', event_id: 'event', aggregate_version: 1, type: 'story.article_added.v1', occurred_at: '2026-11-14T02:51:55+00:00', occurred_at_display: '14/11/2026 09:51', article_title: 'Canonical title', article_id: 'id' }] }, graph: { state: graph, data: [{uuid: 'source', name: value, url: 'https://example.com', article_count: 1}] } } }) };
}
(async () => {
    assert.equal(fixture(undefined, false).timers.size, 0);
    assert.equal(fixture(['ready', 'ready']).timers.size, 0);
    const page = fixture();
    assert.equal([...page.timers.values()][0].ms, 2000);
    page.reply(reply('ready', 'failed'));
    await page.tick();
    assert.equal(page.sections[0].dataset.state, 'ready');
    assert.equal(page.sections[1].dataset.state, 'failed');
    assert.equal([...page.timers.values()][0].ms, 10000);
    const previous = page.sections[0].list.children;
    page.reply(new Error('network'));
    await page.tick();
    assert.equal(page.sections[0].list.children, previous);
    assert.equal([...page.timers.values()][0].ms, 10000);
    page.reply({ ok: false });
    await page.tick();
    assert.equal(page.sections[0].list.children, previous);
    page.reply({ ok: true, json: async () => ({ sections: { timeline: { state: 'ready', data: [] }, graph: { state: 'bogus', data: [] } } }) });
    await page.tick();
    assert.equal(page.sections[0].list.children, previous);
    page.document.hidden = true;
    page.listeners.visibilitychange();
    assert.equal(page.timers.size, 0);
    page.document.hidden = false;
    page.listeners.visibilitychange();
    assert.equal([...page.timers.values()][0].ms, 10000);
    page.reply(reply('ready', 'ready'));
    await page.tick();
    assert.equal(page.sections[1].dataset.state, 'ready');
    assert.equal(page.sections[0].list.children[0].textContent, '14/11/2026 09:51 · Bổ sung bài viết · Canonical title');
    assert.equal(page.sections[0].list.children[0].nodes[0].dateTime, '2026-11-14T02:51:55+00:00');
    assert.equal(page.sections[1].list.children[0].textContent, '<img src=x onerror=alert(1)> · 1 bài viết');
    assert.equal(page.timers.size, 0);
    page.listeners.visibilitychange();
    assert.equal(page.timers.size, 0);
    assert.equal(page.calls(), 5);
    let accepted = 0;
    for (const name of ['timeline', 'graph']) {
        const unused = name === 'timeline' ? ['story_id', 'type', 'article_id'] : ['url'];
        for (const field of unused) {
            for (const value of [null, undefined, 7]) {
                const probe = fixture();
                const payload = await reply('ready', 'ready').json();
                if (value === undefined) delete payload.sections[name].data[0][field];
                else payload.sections[name].data[0][field] = value;
                probe.reply({ ok: true, json: async () => payload });
                await probe.tick();
                assert.deepEqual(probe.sections.map(section => section.dataset.state), ['ready', 'ready'], `${name}/${field} unused field must not block updates`);
                assert.equal(probe.timers.size, 0);
                accepted++;
            }
        }
    }
    let cases = 0;
    for (const name of ['timeline', 'graph']) {
        const valid = (await reply('ready', 'ready').json()).sections[name].data[0];
        const invalidRows = [null, {}, [], 'row'];
        const required = name === 'timeline'
            ? ['event_id', 'aggregate_version', 'occurred_at', 'occurred_at_display', 'article_title']
            : ['uuid', 'name', 'article_count'];
        for (const key of required) {
            const missing = { ...valid }; delete missing[key];
            invalidRows.push(missing, { ...valid, [key]: typeof valid[key] === 'number' ? '1' : 7 });
        }
        const count = name === 'timeline' ? 'aggregate_version' : 'article_count';
        invalidRows.push({ ...valid, [count]: -1 }, { ...valid, [count]: 1.5 }, { ...valid, [count]: Number.MAX_SAFE_INTEGER + 1 });
        for (const state of ['pending', 'ready', 'failed']) {
            for (const invalid of invalidRows) {
                const probe = fixture();
                const payload = await reply('ready', 'ready').json();
                payload.sections[name] = { state, data: [valid, invalid] };
                probe.reply({ ok: true, json: async () => payload });
                await probe.tick();
                assert.deepEqual(probe.sections.map(section => section.dataset.state), ['pending', 'pending'], `${name}/${state} malformed row must retain both states`);
                assert.deepEqual(probe.sections.map(section => section.list.children), [['old'], ['old']]);
                assert.equal([...probe.timers.values()][0].ms, 10000);
                probe.reply(reply('ready', 'ready'));
                await probe.tick();
                assert.equal(probe.timers.size, 0, 'valid retry must recover then stop');
                cases++;
            }
        }
    }
    console.log(`PASS: ${accepted} unused-field cases accept both sections`);
    console.log(`PASS: ${cases} malformed-row cases retain both sections, back off, recover`);
    console.log('PASS: root guard, initial-ready stop, pending/failed cadence, recovery, text insertion, network/HTTP/shape retention, visibility pause/resume, ready stop');
})().catch(error => { console.error(error); process.exitCode = 1; });
