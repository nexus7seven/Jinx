const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('resources/views/wip/index.blade.php', 'utf8');
const start = source.indexOf('        function nextActionCandidate(');
const end = source.indexOf('        function callbackRank(', start);
assert.ok(start >= 0 && end > start, 'Countdown helpers are present');
const now = Date.parse('2026-09-24T10:00:00Z');
class FixedDate extends Date {
    constructor(...args) { super(...(args.length ? args : [now])); }
    static now() { return now; }
}
const ctx = { Date: FixedDate, Number, Math, window: { jinxWipCallbackWasActioned: c => c.dataset.actioned === '1' } };
vm.createContext(ctx);
vm.runInContext(source.slice(start, end), ctx);
function card(id, status, callback, sip, done = false, actioned = false) {
    return { dataset: {
        leadId: String(id), wipStatus: status, callbackAt: callback, sipPrepAt: sip,
        sipPrepCompletedAt: done ? '2026-09-24T09:45:00Z' : '', actioned: actioned ? '1' : '0',
    }, querySelector: () => ({ innerText: 'Lead ' + id }) };
}
test('counts SIP prep time rather than the later SIP appointment and includes all lanes', () => {
    const cards = [
        card(1, 'DMP Transfer', '', '2026-09-24T10:30:00Z'),
        card(2, 'SIP Booked', '', '2026-09-24T10:12:00Z'),
        card(3, 'Collecting Docs', '2026-09-24T10:20:00Z', ''),
    ];
    const a = ctx.nextActionCandidate(cards);
    assert.equal(a.type, 'sip');
    assert.equal(a.leadId, 2);
});
test('earlier callback beats later SIP prep, including a priority-column callback', () => {
    const cards = [
        card(1, 'SIP Booked', '', '2026-09-24T10:20:00Z'),
        card(2, 'Ready to Refer', '2026-09-24T10:08:00Z', ''),
    ];
    const a = ctx.nextActionCandidate(cards);
    assert.equal(a.type, 'callback');
    assert.equal(a.leadId, 2);
});
test('completed SIP prep and actioned callback are excluded', () => {
    const cards = [
        card(1, 'SIP Booked', '', '2026-09-24T09:50:00Z', true),
        card(2, 'Collecting Docs', '2026-09-24T09:55:00Z', '', false, true),
        card(3, 'Collecting Docs', '2026-09-24T10:20:00Z', ''),
    ];
    assert.equal(ctx.nextActionCandidate(cards).leadId, 3);
    assert.equal(ctx.nextActionCandidate(cards.slice(0,2)), null);
});
test('clock displays remaining seconds and overdue, and red threshold is inclusive', () => {
    assert.equal(ctx.nextActionClock(5*60000), '05:00');
    assert.equal(ctx.nextActionClock(15*60000), '15:00');
    assert.equal(ctx.nextActionClock(60*60000+1000), '1:00:01');
    assert.equal(ctx.nextActionClock(-61000), 'OVERDUE 01:01');
    assert.match(source, /if \(ms <= 5 \* 60000\) nextAction\.classList\.add\('is-red'\)/);
    assert.match(source, /else if \(ms <= 15 \* 60000\) nextAction\.classList\.add\('is-amber'\)/);
    assert.match(source, /updateNextActionCountdown\(\);/);
    assert.match(source, /TIME_TICK_MS = 1000/);
    assert.match(source, /id="wip-next-action"/);
});
