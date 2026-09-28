import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import vm from 'node:vm';

const bladePath = resolve(dirname(fileURLToPath(import.meta.url)), '../../../resources/views/bot-logs/index.blade.php');
const blade = readFileSync(bladePath, 'utf8');
const start = blade.indexOf('function queueCardKey');
const end = blade.indexOf('function drawFeed');
assert.ok(start > 0 && end > start, 'live Blade must still expose queueCardKey/drawQueue');
const liveQueueJs = blade.slice(start, end);

class MiniNode {
 constructor(doc, tag, nodeType = 1) {
  this.ownerDocument = doc;
  this.tagName = tag ? String(tag).toUpperCase() : '#DOCUMENT-FRAGMENT';
  this.nodeType = nodeType;
  this.children = [];
  this.parentNode = null;
  this.dataset = {};
  this.className = '';
  this._text = '';
  this.value = '';
  this.selectionStart = 0;
  this.selectionEnd = 0;
  this.hidden = false;
  this.id = '';
  this.type = '';
  this.href = '';
  this.maxLength = 0;
  this.placeholder = '';
  this.disabled = false;
  this.title = '';
  this.attributes = {};
  const self = this;
  this.classList = {
   contains(name) { return self.className.split(/\s+/).filter(Boolean).includes(name); },
   add(name) { if (!this.contains(name)) self.className = [self.className, name].filter(Boolean).join(' '); },
   toggle(name, on) {
    const should = on === undefined ? !this.contains(name) : !!on;
    if (should) this.add(name);
    else self.className = self.className.split(/\s+/).filter((c) => c && c !== name).join(' ');
   },
  };
 }
 get textContent() { return this._text + this.children.map((c) => c.textContent).join(''); }
 set textContent(value) { this._text = String(value ?? ''); this.children = []; }
 append(...nodes) { for (const node of nodes) this.appendChild(node); }
 appendChild(node) {
  if (node.parentNode) node.parentNode._detach(node);
  node.parentNode = this;
  this.children.push(node);
  this.ownerDocument._syncFocus();
  return node;
 }
 _detach(node) {
  this.children = this.children.filter((child) => child !== node);
  node.parentNode = null;
  this.ownerDocument._syncFocus();
 }
 remove() { if (this.parentNode) this.parentNode._detach(this); }
 replaceChildren(...nodes) {
  for (const child of [...this.children]) this._detach(child);
  for (const node of nodes) {
   if (node.nodeType === 11) {
    for (const child of [...node.children]) this.appendChild(child);
   } else {
    this.appendChild(node);
   }
  }
 }
 contains(node) {
  if (node === this) return true;
  return this.children.some((child) => child.contains(node));
 }
 matches(selector) {
  const parts = selector.split(',').map((part) => part.trim());
  return parts.some((part) => this._matchOne(part));
 }
 _matchOne(selector) {
  const attr = selector.match(/^([a-z0-9]*)(?:\.([a-z0-9_-]+))?(?:\[data-card-key\])?$/i);
  if (!attr) return false;
  const wantTag = attr[1];
  const wantClass = attr[2];
  const wantKey = selector.includes('[data-card-key]');
  if (wantTag && this.tagName !== wantTag.toUpperCase()) return false;
  if (wantClass && !this.classList.contains(wantClass)) return false;
  if (wantKey && !this.dataset.cardKey) return false;
  return true;
 }
 querySelectorAll(selector) {
  const found = [];
  const walk = (node) => {
   if (node.matches(selector)) found.push(node);
   for (const child of node.children) walk(child);
  };
  for (const child of this.children) walk(child);
  return found;
 }
 querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
 focus() {
  if (this.ownerDocument.contains(this)) this.ownerDocument.activeElement = this;
 }
 setAttribute(name, value) {
  this.attributes[name] = value;
  if (name === 'id') this.id = value;
 }
 setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; }
 addEventListener() {}
}

class MiniDocument {
 constructor() {
  this.body = new MiniNode(this, 'body');
  this.activeElement = this.body;
 }
 createElement(tag) { return new MiniNode(this, tag, 1); }
 createDocumentFragment() { return new MiniNode(this, '', 11); }
 getElementById(id) {
  let found = null;
  const walk = (node) => {
   if (node.id === id) found = node;
   for (const child of node.children) { if (!found) walk(child); }
  };
  walk(this.body);
  return found;
 }
 contains(node) { return this.body.contains(node); }
 _syncFocus() {
  const active = this.activeElement;
  if (active && active !== this.body && !this.contains(active)) this.activeElement = this.body;
 }
}

function makeHarness() {
 const document = new MiniDocument();
 const filter = document.createElement('select'); filter.id = 'filter'; filter.value = 'pacman';
 const counts = document.createElement('span'); counts.id = 'queueCounts';
 const queue = document.createElement('div'); queue.id = 'queue'; queue.className = 'work-queue';
 document.body.append(filter, counts, queue);
 const ctx = {
  document,
  replyDrafts: new Map(),
  replyEndpoint: '/bot-logs/reply',
  csrf: 'test',
  snapshot: null,
  queue,
  fetch: async () => ({ ok: true, json: async () => ({ accepted: true }) }),
  el(id) { return document.getElementById(id); },
  make(tag, cls, value) {
   const node = document.createElement(tag);
   if (cls) node.className = cls;
   if (value !== undefined) node.textContent = String(value);
   return node;
  },
 };
 vm.createContext(ctx);
 vm.runInContext(liveQueueJs, ctx);
 return ctx;
}

function waitingCard(caseId, noticeId, job) {
 return {
  bot: 'pacman',
  case_id: caseId,
  notice_id: noticeId,
  issue_key: 'issue-' + noticeId,
  status: 'WAITING_ALEX',
  label: 'WAITING FOR ALEX',
  case_name: 'Case ' + caseId,
  job,
  context: 'Need an answer',
  due_at: '',
  waiting_for_alex: true,
  binary: false,
  open_url: '/lead/' + caseId,
 };
}

function snapshotFor(cards, counts = { need_you: cards.length, working: 0, waiting_client: 0 }) {
 return { full_access: true, work_queue: { cards, counts } };
}

function cardInput(ctx, caseId) {
 const key = vm.runInContext('queueCardKey(' + JSON.stringify({ bot: 'pacman', case_id: caseId, notice_id: caseId === 335 ? 111 : 113 }) + ')', ctx);
 return ctx.queue.querySelectorAll('input[data-card-key]').find((input) => input.dataset.cardKey === key)
  || ctx.queue.querySelectorAll('input[data-card-key]').find((input) => input.dataset.cardKey.includes(':' + caseId + ':'));
}

test('focused empty work-card input stays the same DOM node across poll/render cycles', () => {
 const ctx = makeHarness();
 const first = waitingCard(335, 111, 'What is the partner income?');
 const second = waitingCard(321, 113, 'What is the HMRC PAYE ref?');
 ctx.snapshot = snapshotFor([first, second]);
 vm.runInContext('drawQueue()', ctx);
 const empty = cardInput(ctx, 335);
 assert.ok(empty, 'first paint must create a reply input');
 empty.focus();
 empty.value = '';
 empty.selectionStart = 0;
 empty.selectionEnd = 0;
 assert.equal(ctx.document.activeElement, empty);
 for (let cycle = 1; cycle <= 5; cycle++) {
  ctx.snapshot = snapshotFor([
   { ...first, job: 'What is the partner income? (' + cycle + ')' },
   { ...second, job: 'What is the HMRC PAYE ref? (' + cycle + ')' },
  ], { need_you: 2, working: cycle, waiting_client: 0 });
  vm.runInContext('drawQueue()', ctx);
  assert.equal(ctx.document.activeElement, empty);
  assert.equal(cardInput(ctx, 335), empty);
  assert.equal(empty.value, '');
  assert.equal(empty.selectionStart, 0);
  assert.equal(empty.selectionEnd, 0);
  assert.ok(ctx.queue.contains(empty));
  assert.match(ctx.el('queueCounts').textContent, /WORKING [1-5]/);
 }
});

test('focused dirty input keeps the same node, caret, and selection while sibling cards update', () => {
 const ctx = makeHarness();
 const first = waitingCard(335, 111, 'Partner income?');
 const second = waitingCard(321, 113, 'HMRC PAYE?');
 ctx.snapshot = snapshotFor([first, second]);
 vm.runInContext('drawQueue()', ctx);
 const a = cardInput(ctx, 335);
 const b = cardInput(ctx, 321);
 assert.ok(a && b && a !== b);
 a.value = 'partner earns 1200 maps';
 a.selectionStart = 8;
 a.selectionEnd = 13;
 a.focus();
 assert.equal(ctx.document.activeElement, a);
 for (let cycle = 0; cycle < 4; cycle++) {
  ctx.snapshot = snapshotFor([
   { ...first, label: 'WAITING FOR ALEX' },
   { ...second, job: 'HMRC PAYE updated ' + cycle, status: 'QUEUED', label: 'QUEUED' },
  ]);
  vm.runInContext('drawQueue()', ctx);
  assert.equal(ctx.document.activeElement, a);
  assert.equal(cardInput(ctx, 335), a);
  assert.equal(cardInput(ctx, 321), b);
  assert.equal(a.value, 'partner earns 1200 maps');
  assert.equal(a.selectionStart, 8);
  assert.equal(a.selectionEnd, 13);
 }
 b.value = 'check HMRC only';
 b.selectionStart = 6;
 b.selectionEnd = 10;
 b.focus();
 assert.equal(ctx.document.activeElement, b);
 for (let cycle = 0; cycle < 3; cycle++) {
  ctx.snapshot = snapshotFor([
   { ...first, job: 'Partner income refreshed ' + cycle },
   second,
  ]);
  vm.runInContext('drawQueue()', ctx);
  assert.equal(ctx.document.activeElement, b);
  assert.equal(cardInput(ctx, 321), b);
  assert.equal(cardInput(ctx, 335), a);
  assert.equal(b.value, 'check HMRC only');
  assert.equal(b.selectionStart, 6);
  assert.equal(b.selectionEnd, 10);
  assert.equal(a.value, 'partner earns 1200 maps');
 }
});
