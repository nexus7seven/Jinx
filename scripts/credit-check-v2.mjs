/**
 * TransUnion statutory credit check — Playwright automation (server-side).
 * Usage: node scripts/credit-check-v2.mjs <payload.json>
 *
 * Emits machine-readable lines: CREDIT_CHECK_V2_JSON:{...}
 * Prerequisite: npx playwright install chromium
 */

import { chromium } from 'playwright';
import { existsSync } from 'fs';
import { mkdir, readFile, writeFile } from 'fs/promises';
import { dirname, join } from 'path';
import { randomUUID } from 'crypto';

const ABOUT_URL = 'https://www.transunionstatreport.co.uk/CreditReport/AboutYou';

/** Chrome on Windows — keep in sync with current stable line (~2026). */
const STEALTH_USER_AGENT =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

function logStep(message) {
  process.stdout.write(`[credit-check-v2] ${new Date().toISOString()} ${message}\n`);
}

/**
 * Single-line JSON for orchestrators (PHP, CI, etc.).
 * @param {Record<string, unknown>} obj
 */
function emitJson(obj) {
  process.stdout.write(`CREDIT_CHECK_V2_JSON:${JSON.stringify(obj)}\n`);
}

function stripHtml(html) {
  if (!html || typeof html !== 'string') return '';
  return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

function extractUrlsFromContent(html, text) {
  const combined = `${html || ''}\n${text || ''}`;
  const out = [];
  const re = /https?:\/\/[^\s"'<>\])]+/gi;
  let m;
  while ((m = re.exec(combined))) {
    let u = m[0].replace(/[.,;)\]]+$/g, '');
    out.push(u);
  }
  return out;
}

/** Prefer TransUnion / verification style links. */
function pickVerificationLinks(urls) {
  const scored = urls.map((u) => {
    let score = 0;
    if (/transunion/i.test(u)) score += 5;
    if (/statreport|creditreport|verify|confirmation|email/i.test(u)) score += 3;
    if (/temp-mail|unsubscribe|facebook\.com|twitter\.com/i.test(u)) score -= 10;
    return { u, score };
  });
  scored.sort((a, b) => b.score - a.score);
  const good = scored.filter((x) => x.score > 0).map((x) => x.u);
  if (good.length) return good;
  const fallback = urls.find((u) => !/temp-mail|mailto:|unsubscribe/i.test(u));
  return fallback ? [fallback] : [];
}

async function apiGetJson(url, apiKey) {
  const r = await fetch(url, {
    headers: {
      'X-API-Key': apiKey,
      Accept: 'application/json',
    },
  });

  if (!r.ok) {
    const t = await r.text();
    throw new Error(`Temp-mail GET ${url} failed: ${r.status} ${t}`);
  }

  return r.json();
}

/**
 * Poll inbox until a likely TransUnion verification email appears; return best verification URL.
 */
async function waitForVerificationLink({ baseUrl, apiKey, email, maxAttempts = 90, delayMs = 4000 }) {
  const enc = encodeURIComponent(email);

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    logStep(`temp-mail: polling inbox for verification email (${attempt}/${maxAttempts})`);

    const list = await apiGetJson(`${baseUrl}/v1/emails/${enc}/messages`, apiKey);
    const raw = list.messages ?? list;
    const messages = Array.isArray(raw) ? raw : [];

    messages.sort((a, b) => {
      const ta = new Date(a.created_at || 0).getTime();
      const tb = new Date(b.created_at || 0).getTime();
      return tb - ta;
    });

    for (const msg of messages) {
      const mid = msg.id ?? msg.message_id;
      if (!mid) continue;

      const subj = String(msg.subject || '');

      const full = await apiGetJson(`${baseUrl}/v1/messages/${encodeURIComponent(mid)}`, apiKey);
      const bodyText = String(full.body_text || '');
      const bodyHtml = String(full.body_html || '');
      const blob = `${subj}\n${bodyText}\n${bodyHtml}`;

      const urls = extractUrlsFromContent(bodyHtml, bodyText);
      let candidates = pickVerificationLinks(urls);

      if (candidates.length === 0 && /https?:\/\//i.test(blob)) {
        const loose = urls.filter((u) => !/temp-mail|unsubscribe|mailto:/i.test(u));
        if (loose.length) candidates = [loose[0]];
      }

      if (candidates.length > 0) {
        logStep(`temp-mail: using verification link from message id=${mid} (subject=${subj || '—'})`);
        return {
          url: candidates[0],
          allUrls: candidates,
          messageId: mid,
          subject: full.subject || subj,
        };
      }

      logStep(`temp-mail: message ${mid} had no usable link; trying next / next poll`);
    }

    await sleep(delayMs);
  }

  return null;
}

async function dismissCookieBanner(page) {
  logStep('ui: attempting to dismiss cookie / consent banner');
  const candidates = [
    page.getByRole('button', { name: /accept all|i agree|accept|agree/i }),
    page.locator('button:has-text("Accept")'),
    page.locator('[aria-label*="accept" i]'),
  ];

  for (const loc of candidates) {
    try {
      const first = loc.first();
      await first.waitFor({ state: 'visible', timeout: 4000 });
      await first.click({ timeout: 3000 });
      logStep('ui: dismissed cookie/consent control');
      await sleep(600);
      return;
    } catch {
      /* next */
    }
  }
  logStep('ui: no cookie banner clicked (may be absent)');
}

async function fillIfPresent(page, label, filler) {
  try {
    await filler();
    logStep(`form: ${label} — ok`);
  } catch (e) {
    logStep(`form: ${label} — skipped (${e?.message || e})`);
  }
}

function parseDobParts(dobIso) {
  if (!dobIso || typeof dobIso !== 'string') return null;
  const m = dobIso.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!m) return null;
  return { year: parseInt(m[1], 10), month: parseInt(m[2], 10), day: parseInt(m[3], 10) };
}

async function tryPostcodeLookup(page, postcode) {
  if (!postcode) return;
  logStep(`address: trying postcode lookup for "${postcode}"`);

  await fillIfPresent(page, 'postcode (lookup)', async () => {
    const pc = page.getByLabel(/postcode|post code/i).first();
    await pc.fill(String(postcode));
  });

  await fillIfPresent(page, 'click Find address / lookup', async () => {
    const btn = page.getByRole('button', { name: /find address|look up|search address|find/i }).first();
    await btn.click({ timeout: 8000 });
  });

  await sleep(1500);

  await fillIfPresent(page, 'select first address from list', async () => {
    const opt = page.locator('[role="option"], option, li[data-address], .address-result').first();
    await opt.click({ timeout: 6000 });
  });
}

const SECURITY_KEYWORDS =
  /security|question|mother'?s maiden|maiden name|first school|memorable|pet'?s name|born in|town you/i;

async function collectQuestionFields(page) {
  const questions = [];
  const inputs = page.locator('input:visible, textarea:visible');
  const n = await inputs.count();

  for (let i = 0; i < n; i++) {
    const input = inputs.nth(i);
    const type = (await input.getAttribute('type')) || 'text';
    if (type === 'submit' || type === 'button' || type === 'checkbox' || type === 'radio' || type === 'hidden') {
      continue;
    }

    const idAttr = await input.getAttribute('id');
    let labelText = '';

    if (idAttr) {
      const lab = page.locator(`label[for="${cssEscapeForSelector(idAttr)}"]`);
      if ((await lab.count()) > 0) {
        labelText = (await lab.first().innerText()).trim();
      }
    }

    if (!labelText) {
      labelText = await input.evaluate((el) => {
        let n = el.parentElement;
        for (let d = 0; d < 6 && n; d++) {
          const t = n.querySelector('label, legend, .label, span');
          if (t?.innerText?.trim()) return t.innerText.trim();
          n = n.parentElement;
        }
        return '';
      });
    }

    const hay = `${labelText} ${(await input.getAttribute('name')) || ''} ${(await input.getAttribute('placeholder')) || ''}`;
    if (SECURITY_KEYWORDS.test(hay) || (/\?/.test(labelText) && labelText.length > 5)) {
      const fid = idAttr || `sq-field-${i}`;
      questions.push({ id: fid, text: labelText.slice(0, 800) || `Field ${i}` });
    }
  }

  return questions;
}

function cssEscapeForSelector(id) {
  return id.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
}

async function detectSecurityQuestionsScreen(page) {
  logStep('security: scanning page for knowledge-based questions');
  const bodyText = (await page.locator('body').innerText()).slice(0, 12000);
  const heading = (await page.locator('h1, h2, legend').first().innerText().catch(() => '')) || '';
  const keywordHit =
    SECURITY_KEYWORDS.test(bodyText) && /question|answer|verify your identity|knowledge/i.test(bodyText);

  const fields = await collectQuestionFields(page);
  let questions = fields.length ? fields : [];

  if (keywordHit && questions.length === 0) {
    questions = [{ id: 'manual-review', text: heading || "Security questions (use answers.json with matching id or text)" }];
  }

  const detected =
    keywordHit || fields.length > 0 || /security questions|knowledge.?based/i.test(bodyText + heading);

  if (detected && questions.length === 0) {
    questions = [{ id: 'manual-review', text: heading || 'Security step (inspect page)' }];
  }

  return { detected, questions };
}

async function waitForAnswersFile(sessionDir, timeoutMs) {
  const answersPath = join(sessionDir, 'answers.json');
  const start = Date.now();
  logStep(`security: waiting for answers file: ${answersPath} (timeout ${Math.round(timeoutMs / 1000)}s)`);

  while (Date.now() - start < timeoutMs) {
    if (existsSync(answersPath)) {
      const raw = await readFile(answersPath, 'utf8');
      logStep('security: received answers.json');
      return JSON.parse(raw);
    }
    await sleep(2000);
  }

  throw new Error(`Timeout waiting for answers at ${answersPath}`);
}

async function applySecurityAnswers(page, payload) {
  const answers = payload.answers;
  if (!Array.isArray(answers)) {
    throw new Error('answers.json must contain an "answers" array');
  }

  for (const a of answers) {
    const value = a.value ?? a.answer ?? '';
    if (a.id && String(a.id).length && a.id !== 'manual-review') {
      const id = String(a.id);
      await fillIfPresent(page, `security answer by id "${id}"`, async () => {
        const loc = page.locator(`[id="${id.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"]`);
        await loc.first().fill(String(value), { timeout: 15000 });
      });
    } else if (a.text) {
      const snippet = String(a.text).slice(0, 80);
      await fillIfPresent(page, `security answer by label containing "${snippet}"`, async () => {
        await page.getByLabel(new RegExp(snippet.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i')).fill(String(value), {
          timeout: 15000,
        });
      });
    }
  }
}

async function tryDownloadWithClick(page, clickFn, label) {
  try {
    logStep(`pdf: ${label} — waiting for download`);
    const [download] = await Promise.all([page.waitForEvent('download', { timeout: 180000 }), clickFn()]);
    return download;
  } catch (e) {
    logStep(`pdf: ${label} — no download (${e?.message || e})`);
    return null;
  }
}

async function tryDownloadReportPdf(page, reportPath) {
  logStep('pdf: preparing report output directory');
  await mkdir(dirname(reportPath), { recursive: true });

  let download =
    (await tryDownloadWithClick(
      page,
      () => page.getByRole('button', { name: /download.*pdf|save.*pdf|download report|download credit|export pdf/i }).first().click({ timeout: 20000 }),
      'primary download button',
    )) ||
    (await tryDownloadWithClick(
      page,
      () => page.getByRole('link', { name: /download|\.pdf|credit report/i }).first().click({ timeout: 20000 }),
      'download link',
    ));

  if (download) {
    await download.saveAs(reportPath);
    logStep(`pdf: saved download to ${reportPath}`);
    return true;
  }

  logStep('pdf: no download event; trying page.pdf() print fallback');
  try {
    await page.pdf({ path: reportPath, format: 'A4', printBackground: true });
    logStep(`pdf: wrote print snapshot to ${reportPath}`);
    return true;
  } catch (e) {
    logStep(`pdf: print fallback failed (${e?.message || e})`);
    return false;
  }
}

async function run() {
  const payloadPath = process.argv[2];
  if (!payloadPath) {
    console.error('Usage: node scripts/credit-check-v2.mjs <payload.json>');
    process.exit(1);
  }

  const raw = await readFile(payloadPath, 'utf8');
  const payload = JSON.parse(raw);

  const creditCheckUrl = payload.creditCheckUrl || ABOUT_URL;
  const personalData = payload.personalData || {};
  const tempMail = payload.tempMail;
  const tempMailApi = payload.tempMailApi || {};
  const baseUrl = tempMailApi.baseUrl;
  const apiKey = tempMailApi.apiKey;

  const sessionId = payload.sessionId || randomUUID();
  const sessionDir = payload.sessionDir || join(process.cwd(), 'storage', 'app', 'credit-check-v2', 'sessions', sessionId);
  const reportPath = payload.reportPath;

  if (!tempMail || !baseUrl || !apiKey || !reportPath) {
    console.error('Invalid payload: need tempMail, tempMailApi, reportPath');
    process.exit(1);
  }

  await mkdir(sessionDir, { recursive: true });

  const headless = process.env.PLAYWRIGHT_HEADLESS !== '0';

  logStep(`launch: Chromium headless=${headless}, viewport=1920x1080, stealth args enabled`);
  logStep(`launch: userAgent=${STEALTH_USER_AGENT}`);

  const browser = await chromium.launch({
    headless,
    channel: undefined,
    ignoreDefaultArgs: ['--enable-automation'],
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-blink-features=AutomationControlled',
      '--disable-features=IsolateOrigins,site-per-process',
      '--disable-infobars',
      '--window-size=1920,1080',
      '--lang=en-GB',
    ],
  });

  const context = await browser.newContext({
    viewport: { width: 1920, height: 1080 },
    screen: { width: 1920, height: 1080 },
    userAgent: STEALTH_USER_AGENT,
    locale: 'en-GB',
    timezoneId: 'Europe/London',
    colorScheme: 'light',
    extraHTTPHeaders: {
      'Accept-Language': 'en-GB,en;q=0.9',
    },
  });

  await context.addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
    Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
    window.chrome = { runtime: {} };
  });

  const page = await context.newPage();

  try {
    logStep(`navigate: ${creditCheckUrl}`);
    await page.goto(creditCheckUrl, { waitUntil: 'domcontentloaded', timeout: 120000 });

    await dismissCookieBanner(page);

    const dobParts = personalData.dobParts || parseDobParts(personalData.dob);

    await fillIfPresent(page, 'title Mr', async () => {
      await page.getByLabel(/title/i).first().selectOption({ label: 'Mr' });
    });

    await fillIfPresent(page, 'forename', async () => {
      await page.getByRole('textbox', { name: /forename|first name/i }).first().fill(String(personalData.firstName || ''));
    });

    await fillIfPresent(page, 'surname', async () => {
      await page.getByRole('textbox', { name: /surname|last name/i }).first().fill(String(personalData.lastName || ''));
    });

    if (dobParts) {
      await fillIfPresent(page, 'DOB day', async () => {
        await page.getByLabel(/^day$/i).first().fill(String(dobParts.day));
      });
      await fillIfPresent(page, 'DOB month', async () => {
        await page.getByLabel(/^month$/i).first().fill(String(dobParts.month));
      });
      await fillIfPresent(page, 'DOB year', async () => {
        await page.getByLabel(/year/i).first().fill(String(dobParts.year));
      });
    }

    await fillIfPresent(page, 'email', async () => {
      await page.getByLabel(/email/i).first().fill(String(tempMail));
    });

    await fillIfPresent(page, 'phone', async () => {
      await page.getByLabel(/mobile|phone|telephone/i).first().fill(String(personalData.phone || ''));
    });

    await fillIfPresent(page, 'building / house number', async () => {
      await page.getByLabel(/building number|house number|abode number/i).first().fill(String(personalData.houseNumber || ''));
    });

    if (personalData.buildingName) {
      await fillIfPresent(page, 'building name', async () => {
        await page.getByLabel(/building name/i).first().fill(String(personalData.buildingName));
      });
    }

    await tryPostcodeLookup(page, personalData.postcode);

    await fillIfPresent(page, 'address line 1', async () => {
      await page.getByLabel(/address line 1|address 1/i).first().fill(String(personalData.addressLine1 || ''));
    });

    if (personalData.addressLine2) {
      await fillIfPresent(page, 'address line 2', async () => {
        await page.getByLabel(/address line 2|address 2/i).first().fill(String(personalData.addressLine2));
      });
    }

    if (personalData.town) {
      await fillIfPresent(page, 'town / city', async () => {
        await page.getByLabel(/town|city/i).first().fill(String(personalData.town));
      });
    }

    await fillIfPresent(page, 'terms checkbox', async () => {
      const cb = page.getByRole('checkbox', { name: /terms|privacy|agree/i }).first();
      await cb.check({ force: true });
    });

    logStep('submit: triggering email verification (continue / submit)');
    await fillIfPresent(page, 'continue', async () => {
      await page.getByRole('button', { name: /continue|next|submit|proceed/i }).first().click();
    });

    logStep('email: polling temp-mail.io for TransUnion verification message');
    const verification = await waitForVerificationLink({ baseUrl, apiKey, email: tempMail });

    if (!verification?.url) {
      emitJson({ success: false, error: 'verification_link_not_found' });
      throw new Error('No verification link received from temp-mail within timeout');
    }

    logStep(`email: opening verification URL in same browser context`);
    await page.goto(verification.url, { waitUntil: 'domcontentloaded', timeout: 120000 });
    await sleep(2000);

    const sec = await detectSecurityQuestionsScreen(page);

    if (sec.detected) {
      const out = {
        status: 'security_questions',
        sessionId,
        questions: sec.questions,
      };

      await writeFile(join(sessionDir, 'security-questions.json'), JSON.stringify(out, null, 2), 'utf8');
      emitJson(out);
      logStep(`security: paused automation — wrote ${join(sessionDir, 'security-questions.json')}`);
      logStep('security: supply answers via POST /leads/{lead}/credit-check-v2/sessions/{sessionId}/answers or drop answers.json into sessionDir');

      const waitMs = Number(process.env.CREDIT_CHECK_V2_ANSWER_TIMEOUT_MS) || 45 * 60 * 1000;
      const answersPayload = await waitForAnswersFile(sessionDir, waitMs);

      await applySecurityAnswers(page, answersPayload);

      await fillIfPresent(page, 'post-security continue', async () => {
        await page.getByRole('button', { name: /continue|next|submit|confirm/i }).first().click();
      });
    } else {
      logStep('security: no knowledge-based step detected by heuristics; continuing');
    }

    await sleep(2000);

    const pdfOk = await tryDownloadReportPdf(page, reportPath);

    if (!pdfOk) {
      emitJson({ success: false, error: 'pdf_not_saved', reportPath });
      throw new Error('Could not save PDF');
    }

    const final = { success: true, reportPath };
    emitJson(final);
    logStep(`done: ${JSON.stringify(final)}`);
  } finally {
    await browser.close();
    logStep('browser: closed');
  }
}

run().catch((err) => {
  logStep(`fatal: ${err?.stack || err}`);
  emitJson({ success: false, error: String(err?.message || err) });
  process.exit(1);
});
