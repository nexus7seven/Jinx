const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('resources/views/wip/index.blade.php', 'utf8');
const begin = source.indexOf('        function queuePosition(card) {');
const end = source.indexOf('        function sortStack(stack, ranker) {', begin);
assert.ok(begin >= 0 && end > begin, 'Live workdesk ranking functions must exist');

const now = Date.parse('2026-09-22T16:30:00Z'); // 17:30 UK
class FixedDate extends Date {
    constructor(...args) { super(...(args.length ? args : [now])); }
    static now() { return now; }
}
const ctx = {
    Date: FixedDate,
    Number,
    Math,
    window: { jinxWipCallbackWasActioned: () => false },
};
vm.createContext(ctx);
vm.runInContext(source.slice(begin, end), ctx);
const { callbackRank, priorityRank, compareRanks } = ctx;

function card(id, status, queuePosition, callbackAt = '', sipPrepAt = '') {
    return { dataset: {
        leadId: String(id), wipStatus: status,
        queuePosition: String(queuePosition), callbackAt,
        sipAt: sipPrepAt ? '2026-09-22T17:00:00Z' : '',
        sipPrepAt, sipPrepCompletedAt: '',
    } };
}
function sorted(cards, ranker) {
    return cards.slice().sort((a, b) => compareRanks(a, b, ranker))
        .map(item => Number(item.dataset.leadId));
}

test('due Ready to Refer callback rises to the top of Priority', () => {
    const ordinary = card(1, 'Ready to Refer', 1000);
    const due = card(2, 'Ready to Refer', 30000, '2026-09-22T16:30:00Z');
    assert.deepEqual(sorted([ordinary, due], priorityRank), [2, 1]);
});

test('due SIP prep remains above due callback, and callback above ordinary leads', () => {
    const ordinary = card(1, 'Ready to Refer', 1000);
    const due = card(2, 'Ready to Refer', 30000, '2026-09-22T16:30:00Z');
    const sip = card(3, 'SIP Booked', 2000, '', '2026-09-22T16:29:00Z');
    assert.deepEqual(sorted([ordinary, due, sip], priorityRank), [3, 2, 1]);
});

test('due callbacks in Active, DMP and Other use their callback deadline', () => {
    for (const status of ['Collecting Docs', 'DMP Transfer', 'Lost Contact']) {
        const ordinary = card(1, status, 1000);
        const due = card(2, status, 30000, '2026-09-22T16:30:00Z');
        assert.deepEqual(sorted([ordinary, due], callbackRank), [2, 1]);
    }
    assert.match(source, /sortStack\(document\.getElementById\('wip-dmp-stack'\), callbackRank\)/);
    assert.match(source, /sortStack\(document\.getElementById\('wip-other-stack'\), callbackRank\)/);
});

test('normal position resumes once callback is actioned', () => {
    ctx.window.jinxWipCallbackWasActioned = c => c.dataset.leadId === '2';
    const ordinary = card(1, 'Ready to Refer', 1000);
    const actioned = card(2, 'Ready to Refer', 30000, '2026-09-22T16:30:00Z');
    assert.deepEqual(sorted([actioned, ordinary], priorityRank), [1, 2]);
});
