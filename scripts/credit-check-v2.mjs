/**
 * TransUnion statutory credit check — Playwright automation (server-side).
 *
 * personalData keys (from Laravel): title, first_name, middle_name, last_name, dob,
 *   phone_number, postcode, house_number, house_name, building_number, address_line_1 (street hint for dropdown).
 * JINX → TransUnion:
 *   title → #IndividualDetails_Title | first_name → Forename | middle_name → MiddleNames | last_name → Surname
 *   dob → Day/Month/Year | phone_number → PhoneNumber | postcode → find-address → #address-dropdown
 *
 * Usage: node scripts/credit-check-v2.mjs <payload.json>
 *
 * Machine-readable stdout (one line each):
 *   CREDIT_CHECK_V2_JSON:{"status":"security_questions","sessionId":"...","questions":[{"id":"...","text":"..."},...]}
 *   CREDIT_CHECK_V2_JSON:{"status":"failed","reason":"identity_verification_failed","message":"..."}
 *   CREDIT_CHECK_V2_JSON:{"success":true,"reportPath":"..."}
 *
 * answers.json format:
 *   { "answers": [ { "id": "<hidden Questions_*__Id value>", "value": "<exact or partial radio label text>" }, { "index": 0, "value": "..." } ] }
 *
 * Prerequisite: npx playwright install chromium
 */

import { chromium } from 'playwright';
import { existsSync } from 'fs';
import { mkdir, readFile, writeFile, unlink } from 'fs/promises';
import { dirname, join } from 'path';
import { randomUUID } from 'crypto';

const ABOUT_URL = 'https://www.transunionstatreport.co.uk/CreditReport/AboutYou';

/** TUNE: bump Chrome minor as stable moves (2026). */
const STEALTH_USER_AGENT =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36';

/** Temp-mail API list/message fetches: 3 attempts max, 20s apart (low monthly quota). */
const TEMP_MAIL_POLL_INTERVAL_MS = 20000;
const TEMP_MAIL_MAX_ATTEMPTS = 3;

const MAX_KBA_ATTEMPTS = 2;

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

/** Human-facing progress line for CRM terminal (no timestamp). */
function logProgress(userMessage) {
  process.stdout.write(`[credit-check-v2] • ${userMessage}\n`);
}

function logStep(description) {
  process.stdout.write(`[credit-check-v2] ${new Date().toISOString()} ${description}\n`);
}

/** Shown in CRM / API; keep in sync with CreditCheckV2Controller::identityVerificationFailedMessage(). */
const IDENTITY_FAILURE_USER_MESSAGE =
  'TransUnion was unable to verify your identity automatically. You can try again later or request by post.';

const IDENTITY_FAILURE_PAYLOAD = {
  status: 'failed',
  reason: 'identity_verification_failed',
  message: IDENTITY_FAILURE_USER_MESSAGE,
};

/**
 * TransUnion terminal failure: div#wizard-step → article#wizard-page[data-ga-event="negative"],
 * title "Negative Id Verification", heading "ID Verification", and the canonical failure paragraphs.
 */
async function isNegativeIdVerificationPage(page) {
  const combined = page.locator('#wizard-step article#wizard-page[data-ga-event="negative"]');
  const negArticle = page.locator('article#wizard-page[data-ga-event="negative"]');

  const combinedVisible = await combined.first().isVisible().catch(() => false);
  const combinedCount = await combined.count().catch(() => 0);
  const articleVisible = await negArticle.first().isVisible().catch(() => false);

  const body = (await page.locator('body').innerText().catch(() => '')).slice(0, 48000);
  const docTitle = (await page.title().catch(() => '')).trim();
  const h1 = (await page.locator('h1').first().innerText().catch(() => '')).trim();
  const blob = `${docTitle}\n${h1}\n${body}`;

  const phraseVerifyReport =
    /Sorry, we haven't been able to verify and validate your identity and can't provide your credit report/i;
  const phraseAutoVerify =
    /Unfortunately we've not been able to automatically verify and validate your identity/i;
  const hasTitleAndHeading =
    /Negative Id Verification/i.test(blob) && /\bID Verification\b/.test(blob);

  const hasFailureCopy = phraseVerifyReport.test(blob) || phraseAutoVerify.test(blob);

  if (combinedVisible && hasFailureCopy) {
    return true;
  }
  if (combinedVisible && hasTitleAndHeading) {
    return true;
  }
  if (combinedCount > 0 && hasFailureCopy) {
    return true;
  }

  if (articleVisible && hasFailureCopy) {
    return true;
  }
  if (articleVisible && hasTitleAndHeading) {
    return true;
  }

  return false;
}

/** If the failure page is shown, emit JSON and return true (caller should stop and let finally close the browser). */
async function exitIfNegativeFailure(page) {
  if (!(await isNegativeIdVerificationPage(page))) {
    return false;
  }
  logProgress('Failed: identity could not be verified online');
  logStep('negative-id: TransUnion negative ID verification / sorry page detected');
  emitJson(IDENTITY_FAILURE_PAYLOAD);
  logStep(`negative-id: ${IDENTITY_FAILURE_USER_MESSAGE}`);
  return true;
}

function emitJson(obj) {
  process.stdout.write(`CREDIT_CHECK_V2_JSON:${JSON.stringify(obj)}\n`);
}

function stripHtml(html) {
  if (!html || typeof html !== 'string') return '';
  return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

/** OTP / passcode extraction from message body (same role as TempMailService::extractSpecificAuthCode on the lead inbox). */
function extractOtpFromText(text) {
  const s = String(text || '').trim();
  if (!s) return null;
  const patterns = [
    /\*+\s*([0-9]{4,10})\s*\*+/,
    /(?:code|passcode|pin|otp)\s*(?:is|:)?\s*\*?\s*([0-9]{4,10})\s*\*?/i,
    /\b([0-9]{4,8})\b/,
  ];
  for (const re of patterns) {
    const m = s.match(re);
    if (m && (m[1] || m[0])) {
      return String(m[1] || m[0]).replace(/\*/g, '').trim();
    }
  }
  return null;
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

function pickVerificationLinks(urls) {
  const scored = urls.map((u) => {
    let score = 0;
    if (/transunion/i.test(u)) score += 5;
    if (/statreport|creditreport|verify|confirmation|email|activate/i.test(u)) score += 3;
    if (/temp-mail|unsubscribe|facebook\.com|twitter\.com|linkedin\.com/i.test(u)) score -= 10;
    return { u, score };
  });
  scored.sort((a, b) => b.score - a.score);
  const good = scored.filter((x) => x.score > 0).map((x) => x.u);
  if (good.length) return good;
  const fallback = urls.find((u) => !/temp-mail|mailto:|unsubscribe/i.test(u));
  return fallback ? [fallback] : [];
}

function isLikelyTransUnionVerification(blob, subj, from) {
  const header = `${subj} ${from}`.toLowerCase();
  if (/transunion|noreply|statutory|stat report|credit report|email verification|confirm your email/i.test(header)) {
    return true;
  }
  return /transunion|statreport|trans union|verify your email|confirm your email address/i.test(blob);
}

/**
 * temp-mail.io HTTP API — mirrors App\Services\TempMailService:
 * - Headers: X-API-Key, Accept: application/json (same as Laravel Http client).
 * - List messages: GET /v1/emails/{email}/messages (email path-segment encoded).
 * - Full message: GET /v1/messages/{id} (same as getMessage()).
 * Inbox creation runs in PHP via createInboxUsingRandomDomain() (same as TempMailController::generate).
 */
async function apiGetJson(url, apiKey) {
  const r = await fetch(url, {
    headers: { 'X-API-Key': apiKey, Accept: 'application/json' },
  });
  if (!r.ok) {
    const text = await r.text();
    let detail = text;
    try {
      const j = JSON.parse(text);
      detail = j?.error?.detail ?? j?.message ?? text;
    } catch {
      /* keep raw text */
    }
    throw new Error(`Temp mail API error: ${String(detail).trim() || r.statusText || String(r.status)}`);
  }
  return r.json();
}

async function waitForVerificationLink({ baseUrl, apiKey, email, maxAttempts = TEMP_MAIL_MAX_ATTEMPTS }) {
  const enc = encodeURIComponent(email);

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    logStep(
      `temp-mail: verification link poll ${attempt}/${maxAttempts} (interval ${TEMP_MAIL_POLL_INTERVAL_MS}ms)`,
    );

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
      const from = String(msg.from || '');

      const full = await apiGetJson(`${baseUrl}/v1/messages/${encodeURIComponent(mid)}`, apiKey);
      const bodyText = String(full.body_text || '');
      const bodyHtml = String(full.body_html || '');
      const blob = `${subj}\n${bodyText}\n${bodyHtml}`;

      if (!isLikelyTransUnionVerification(blob, subj, from)) {
        continue;
      }

      const urls = extractUrlsFromContent(bodyHtml, bodyText);
      let candidates = pickVerificationLinks(urls);

      if (candidates.length === 0 && /https?:\/\//i.test(blob)) {
        const loose = urls.filter((u) => !/temp-mail|unsubscribe|mailto:/i.test(u));
        if (loose.length) candidates = [loose[0]];
      }

      if (candidates.length > 0) {
        logProgress('Verification email received');
        logStep(`temp-mail: verification URL from message id=${mid}`);
        return {
          url: candidates[0],
          messageId: mid,
          subject: full.subject || subj,
        };
      }
    }

    if (attempt < maxAttempts) {
      await sleep(TEMP_MAIL_POLL_INTERVAL_MS);
    }
  }

  return null;
}

/** Poll inbox for OTP / code after email-auth step. */
async function waitForOtpInEmail({ baseUrl, apiKey, email, maxAttempts = TEMP_MAIL_MAX_ATTEMPTS }) {
  const enc = encodeURIComponent(email);

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    logStep(`temp-mail: OTP/code poll ${attempt}/${maxAttempts} (interval ${TEMP_MAIL_POLL_INTERVAL_MS}ms)`);

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

      const full = await apiGetJson(`${baseUrl}/v1/messages/${encodeURIComponent(mid)}`, apiKey);
      const bodyText = String(full.body_text || '');
      const bodyHtml = String(full.body_html || '');
      const blob = `${bodyText}\n${stripHtml(bodyHtml)}`;

      const code = extractOtpFromText(blob);
      if (code && code.length >= 4) {
        logStep(`temp-mail: extracted OTP/code from message ${mid}`);
        return { code, messageId: mid };
      }
    }

    if (attempt < maxAttempts) {
      await sleep(TEMP_MAIL_POLL_INTERVAL_MS);
    }
  }

  return null;
}

async function dismissCookieBanner(page) {
  logStep('cookies: dismiss banner (TUNE: OneTrust / vendor widgets)');
  const candidates = [
    page.getByRole('button', { name: /accept all|allow all|i agree|accept cookies|accept|agree/i }),
    page.locator('#onetrust-accept-btn-handler'),
    page.locator('button:has-text("Accept")'),
  ];

  for (const loc of candidates) {
    try {
      const first = loc.first();
      await first.waitFor({ state: 'visible', timeout: 5000 });
      await first.click({ timeout: 4000 });
      logStep('cookies: dismissed');
      await sleep(600);
      return;
    } catch {
      /* next */
    }
  }
  logStep('cookies: none found');
}

async function fillIfPresent(page, label, filler) {
  try {
    await filler();
    logStep(`form: ${label} — ok`);
  } catch (e) {
    logStep(`form: ${label} — skipped (${e?.message || e})`);
  }
}

/**
 * JINX MAPPING: `dob` from Lead is typically "DD/MM/YYYY"; also accept ISO "YYYY-MM-DD".
 */
function resolveDobPartsFromJinx(personalData) {
  if (personalData.dobDay != null && personalData.dobMonth != null && personalData.dobYear != null) {
    return {
      day: Number(personalData.dobDay),
      month: Number(personalData.dobMonth),
      year: Number(personalData.dobYear),
    };
  }
  if (personalData.dobParts && typeof personalData.dobParts === 'object') {
    const d = personalData.dobParts;
    if (d.day != null && d.month != null && d.year != null) {
      return { day: Number(d.day), month: Number(d.month), year: Number(d.year) };
    }
  }
  const raw = personalData.dob;
  if (!raw || typeof raw !== 'string') return null;
  const trimmed = raw.trim();
  const uk = trimmed.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  if (uk) {
    return {
      day: parseInt(uk[1], 10),
      month: parseInt(uk[2], 10),
      year: parseInt(uk[3], 10),
    };
  }
  const iso = trimmed.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (iso) {
    return {
      year: parseInt(iso[1], 10),
      month: parseInt(iso[2], 10),
      day: parseInt(iso[3], 10),
    };
  }
  return null;
}

/**
 * JINX MAPPING: phone_number → UK-style leading 0 for TransUnion (#IndividualDetails_PhoneNumber).
 * Prepend 0 when the national number starts with 7 or 1 (mobile / some landline) without a leading 0.
 */
function normalizeUkPhoneForTransUnion(personalData) {
  let p = String(personalData.phone_number ?? '')
    .replace(/\s+/g, '')
    .trim();
  if (!p) return '';
  if (p.startsWith('+44')) {
    p = '0' + p.slice(3);
  } else if (p.startsWith('0044')) {
    p = '0' + p.slice(4);
  }
  if (p.startsWith('0')) {
    return p;
  }
  const first = p.charAt(0);
  if (first === '7' || first === '1') {
    return '0' + p;
  }
  return p;
}

/** Street tokens from Jinx address_line_1 after stripping leading house number (TUNE). */
function streetTokensFromAddressLine(addressLine1, houseNumber) {
  let s = String(addressLine1 || '')
    .trim()
    .toLowerCase();
  const hn = String(houseNumber || '').trim().toLowerCase();
  if (hn && (s.startsWith(hn + ' ') || s.startsWith(hn + ',') || s.startsWith(hn + '-'))) {
    s = s.slice(hn.length).replace(/^[\s,:-]+/, '').trim();
  }
  return s.split(/\s+/).filter((w) => w.length > 2 && !/^(flat|unit|apt)$/i.test(w));
}

/**
 * Score a PAF dropdown label: house_number + street (address_line_1) alignment.
 * TUNE: weights if TransUnion changes label format (e.g. comma vs space after number).
 */
function scoreAddressOptionText(text, hints) {
  const t = String(text || '')
    .toLowerCase()
    .replace(/\s+/g, ' ')
    .trim();
  if (!t || /select|choose|please|enter postcode/i.test(t)) return -1;

  const hn = hints.house_number ? String(hints.house_number).toLowerCase().trim() : '';
  const hname = hints.house_name ? String(hints.house_name).toLowerCase().trim() : '';
  const bn = hints.building_number ? String(hints.building_number).toLowerCase().trim() : '';
  const streetWords = hints.street_words || [];

  let score = 0;

  if (hn) {
    const escaped = hn.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    if (t.startsWith(hn + ' ') || t.startsWith(hn + ',') || t.startsWith(hn + '\t')) {
      score += 100;
    } else if (new RegExp(`^${escaped}\\b`).test(t)) {
      score += 80;
    } else if (t.includes(hn)) {
      score += 35;
      if (new RegExp(`\\b${escaped}\\b`).test(t)) score += 15;
    }
  }

  for (const w of streetWords.slice(0, 6)) {
    if (w && t.includes(w)) score += 8;
  }

  if (hname && hname.length > 1 && t.includes(hname)) score += 14;
  if (bn && bn.length > 0 && t.includes(bn)) score += 14;

  return score;
}

/** TUNE: below this, treat match as weak and use first real option instead. */
const ADDRESS_STRONG_MATCH_MIN_SCORE = 28;

/** TUNE: max wait for PAF dropdown after #find-address. */
const ADDRESS_DROPDOWN_WAIT_MS = 8000;

async function selectAddressDropdownMatchingJinx(page, personalData) {
  logStep(`Using house_number: ${personalData.house_number || 'none'}`);

  const hints = {
    house_number: personalData.house_number ?? '',
    house_name: personalData.house_name ?? '',
    building_number: personalData.building_number ?? '',
    address_line_1: personalData.address_line_1 ?? '',
    street_words: streetTokensFromAddressLine(personalData.address_line_1, personalData.house_number),
  };

  logStep(
    `address: scoring hints — house_name="${hints.house_name || ''}" building="${hints.building_number || ''}" streetWords=${JSON.stringify(hints.street_words)}`,
  );

  const dd = page.locator('#address-dropdown');
  await dd.waitFor({ state: 'visible', timeout: ADDRESS_DROPDOWN_WAIT_MS });

  const tag = await dd.evaluate((el) => el.tagName.toLowerCase());

  const pickFirstUsableOptionIndex = async (getTextAtIndex, length) => {
    for (let i = 0; i < length; i++) {
      const t = (await getTextAtIndex(i)).trim();
      if (t.length > 0 && !/select|choose|please/i.test(t)) return i;
    }
    return 0;
  };

  if (tag === 'select') {
    const opts = dd.locator('option');
    const n = await opts.count();
    let bestIdx = -1;
    let bestScore = -1;
    let bestText = '';

    for (let i = 0; i < n; i++) {
      const t = (await opts.nth(i).innerText()).trim();
      const sc = scoreAddressOptionText(t, hints);
      if (sc > bestScore) {
        bestScore = sc;
        bestIdx = i;
        bestText = t;
      }
    }

    let useIdx = bestIdx;
    let useText = bestText;
    if (bestIdx < 0 || bestScore < ADDRESS_STRONG_MATCH_MIN_SCORE) {
      const fb = await pickFirstUsableOptionIndex((i) => opts.nth(i).innerText(), n);
      useIdx = fb;
      useText = (await opts.nth(fb).innerText()).trim();
      logStep(
        `address: no strong match (bestScore=${bestScore}) — TUNE threshold ADDRESS_STRONG_MATCH_MIN_SCORE=${ADDRESS_STRONG_MATCH_MIN_SCORE}; using first usable option index ${fb}`,
      );
    }

    await dd.selectOption({ index: useIdx });
    logStep(`Selected address: ${useText || '(empty)'}`);
    logProgress(`Address selected: ${(useText || '').slice(0, 120) || 'option'}`);
    return;
  }

  const items = dd.locator('[role="option"], li, a, div[role="option"]');
  const count = await items.count();
  if (count === 0) {
    logStep('address: no list items under #address-dropdown (TUNE: child selectors)');
    return;
  }

  let bestIdx = 0;
  let bestScore = -1;
  let bestText = '';

  for (let i = 0; i < count; i++) {
    const t = (await items.nth(i).innerText()).trim();
    const sc = scoreAddressOptionText(t, hints);
    if (sc > bestScore) {
      bestScore = sc;
      bestIdx = i;
      bestText = t;
    }
  }

  let clickIdx = bestIdx;
  let selectedText = bestText;
  if (bestScore < ADDRESS_STRONG_MATCH_MIN_SCORE) {
    const fb = await pickFirstUsableOptionIndex((i) => items.nth(i).innerText(), count);
    clickIdx = fb;
    selectedText = (await items.nth(fb).innerText()).trim();
    logStep(
      `address: no strong match (bestScore=${bestScore}) — fallback to first usable item index ${fb} (TUNE)`,
    );
  }

  await items.nth(clickIdx).click({ timeout: 10000 });
  logStep(`Selected address: ${selectedText || '(empty)'}`);
  logProgress(`Address selected: ${(selectedText || '').slice(0, 120) || 'option'}`);
}

// --- About You: TransUnion field IDs (TUNE if ASP.NET ids change) — fed by Jinx Lead → personalData ---

async function fillAboutYouForm(page, personalData, tempEmail) {
  const dobParts = resolveDobPartsFromJinx(personalData);
  logStep(`about-you: Jinx DOB → parts ${dobParts ? JSON.stringify(dobParts) : 'none'}`);

  const title = String(personalData.title || 'Mr').trim() || 'Mr';
  await fillIfPresent(page, 'Title #IndividualDetails_Title', async () => {
    await page.locator('#IndividualDetails_Title').selectOption({ label: title });
  });

  await fillIfPresent(page, 'Forename #IndividualDetails_Forename', async () => {
    await page.locator('#IndividualDetails_Forename').fill(String(personalData.first_name ?? ''));
  });

  await fillIfPresent(page, 'Middle names #IndividualDetails_MiddleNames', async () => {
    await page.locator('#IndividualDetails_MiddleNames').fill(String(personalData.middle_name ?? ''));
  });

  await fillIfPresent(page, 'Surname #IndividualDetails_Surname', async () => {
    await page.locator('#IndividualDetails_Surname').fill(String(personalData.last_name ?? ''));
  });

  if (dobParts) {
    // JINX MAPPING: dob → #IndividualDetails_DateOfBirth_Day|Month|Year
    await fillIfPresent(page, 'DOB Day #IndividualDetails_DateOfBirth_Day', async () => {
      await page.locator('#IndividualDetails_DateOfBirth_Day').fill(String(dobParts.day));
    });
    await fillIfPresent(page, 'DOB Month #IndividualDetails_DateOfBirth_Month', async () => {
      await page.locator('#IndividualDetails_DateOfBirth_Month').fill(String(dobParts.month));
    });
    await fillIfPresent(page, 'DOB Year #IndividualDetails_DateOfBirth_Year', async () => {
      await page.locator('#IndividualDetails_DateOfBirth_Year').fill(String(dobParts.year));
    });
  }

  await fillIfPresent(page, 'Email #IndividualDetails_Email', async () => {
    await page.locator('#IndividualDetails_Email').fill(String(tempEmail));
  });

  const phoneNorm = normalizeUkPhoneForTransUnion(personalData);
  logStep(`about-you: Jinx phone → normalized "${phoneNorm}"`);
  // JINX MAPPING: phone_number → #IndividualDetails_PhoneNumber
  await fillIfPresent(page, 'Phone #IndividualDetails_PhoneNumber', async () => {
    await page.locator('#IndividualDetails_PhoneNumber').fill(phoneNorm);
  });

  // JINX MAPPING: postcode → #Address_Postcode
  await fillIfPresent(page, 'Postcode #Address_Postcode', async () => {
    await page.locator('#Address_Postcode').fill(String(personalData.postcode || '').trim());
  });

  await fillIfPresent(page, 'Find address #find-address', async () => {
    await page.locator('#find-address').click({ timeout: 15000 });
  });

  await sleep(2000);

  await fillIfPresent(page, 'Address #address-dropdown (match house/building)', async () => {
    await selectAddressDropdownMatchingJinx(page, personalData);
  });

  await page.check('#TermsOfUseAndPrivacyNoticeAccepted', { force: true });
  logProgress('Agreed to Terms of Use');
  logStep('form: Terms #TermsOfUseAndPrivacyNoticeAccepted — checked');

  await fillIfPresent(page, 'Submit #submit', async () => {
    const sub = page.locator('#submit');
    if (await sub.count()) {
      await sub.first().click({ timeout: 15000 });
    } else {
      await page.locator('input[name="submit"]').first().click({ timeout: 15000 });
    }
  });
}

async function isEmailAuthenticationPage(page) {
  const hasInputCode = await page.locator('#InputCode').isVisible().catch(() => false);
  const title = (await page.locator('body').innerText().catch(() => '')).slice(0, 8000);
  const textHit = /Email Authentication Required|email authentication/i.test(title);
  return hasInputCode || textHit;
}

async function handleEmailAuthenticationPage(page, baseUrl, apiKey, email) {
  logStep('email-auth: detected code entry (#InputCode or "Email Authentication Required")');
  await sleep(3000);

  const otp = await waitForOtpInEmail({ baseUrl, apiKey, email });
  if (!otp?.code) {
    throw new Error('No OTP/code received from temp-mail for email authentication step');
  }

  await fillIfPresent(page, 'OTP #InputCode', async () => {
    await page.locator('#InputCode').fill(otp.code);
  });

  await fillIfPresent(page, 'email-auth submit (#submit or button)', async () => {
    const sub = page.locator('#submit');
    if (await sub.count()) {
      await sub.first().click({ timeout: 20000 });
    } else {
      await page.getByRole('button', { name: /continue|submit|verify|next/i }).first().click({ timeout: 20000 });
    }
  });
}

async function isKbaWizardPage(page) {
  const kba = await page.locator('#wizard-page[data-ga-event="kba"]').isVisible().catch(() => false);
  const h1 = await page.locator('h1').first().innerText().catch(() => '');
  const idv = /ID Verification/i.test(h1);
  const list = await page.locator('#questionList').isVisible().catch(() => false);
  return kba || idv || list;
}

/**
 * Extract KBA questions: hidden Id value + label.question text per block.
 * TUNE: #questionList structure / wrappers.
 */
async function extractKbaQuestions(page) {
  const data = await page.evaluate(() => {
    const list = document.querySelector('#questionList');
    if (!list) return [];

    const hiddens = list.querySelectorAll('input[id^="Questions_"][name$="Id"]');
    const out = [];

    hiddens.forEach((hid, index) => {
      const idVal = (hid.value || '').trim() || hid.getAttribute('id') || '';
      let container = hid.closest('div, fieldset, li, section, article') || list;
      let labelEl = container.querySelector('label.question');
      if (!labelEl) {
        labelEl = hid.parentElement?.querySelector('label.question');
      }
      if (!labelEl) {
        let n = hid.parentElement;
        for (let d = 0; d < 8 && n; d++) {
          const l = n.querySelector('label.question');
          if (l) {
            labelEl = l;
            break;
          }
          n = n.parentElement;
        }
      }
      const text = (labelEl?.innerText || '').replace(/\s+/g, ' ').trim();
      out.push({ id: idVal, text: text || `Question ${index + 1}`, index });
    });

    return out;
  });

  return data;
}

/**
 * Select radio under #questionList matching question id or index; value = option label text.
 */
async function applyKbaRadioAnswers(page, answers, extractedQuestions) {
  if (!Array.isArray(answers)) {
    throw new Error('answers.json must contain answers[]');
  }

  logStep(`kba: applying ${answers.length} radio answer(s)`);

  for (const ans of answers) {
    const value = ans.value ?? ans.answer ?? '';
    if (!value) continue;

    let qIndex = -1;
    if (ans.index != null && ans.index !== '') {
      qIndex = Number(ans.index);
    } else if (ans.id != null && ans.id !== '') {
      const idStr = String(ans.id);
      qIndex = extractedQuestions.findIndex((q) => q.id === idStr || q.id === ans.id);
    }

    if (qIndex < 0 || qIndex >= extractedQuestions.length) {
      logStep(`kba: could not resolve question for answer id=${ans.id} index=${ans.index} — skip`);
      continue;
    }

    const matched = await page.evaluate(
      ({ qIndex, value: want }) => {
        const list = document.querySelector('#questionList');
        if (!list) return { ok: false, reason: 'no questionList' };

        const hiddens = list.querySelectorAll('input[id^="Questions_"][name$="Id"]');
        const hid = hiddens[qIndex];
        if (!hid) return { ok: false, reason: 'no hidden id at index' };

        let block = hid.closest('div, fieldset, li, section') || list;
        const radios = block.querySelectorAll('label.checkboxContainer input[type="radio"], .checkboxContainer input[type="radio"]');

        const wantNorm = String(want).toLowerCase().trim();

        for (const radio of radios) {
          let lab = radio.closest('label');
          if (!lab) {
            lab = radio.parentElement?.querySelector('label') || null;
          }
          const t = (lab?.innerText || '').replace(/\s+/g, ' ').trim().toLowerCase();
          if (!t) continue;
          if (t === wantNorm || t.includes(wantNorm) || wantNorm.includes(t)) {
            radio.checked = true;
            radio.dispatchEvent(new Event('input', { bubbles: true }));
            radio.dispatchEvent(new Event('change', { bubbles: true }));
            radio.click();
            return { ok: true, matched: t };
          }
        }

        return { ok: false, reason: 'no radio label match', radios: radios.length };
      },
      { qIndex, value: String(value) },
    );

    if (matched.ok) {
      logStep(`kba: question ${qIndex} selected option matching "${value}" (${matched.matched || ''})`);
    } else {
      logStep(`kba: question ${qIndex} failed to match radio for "${value}" — ${JSON.stringify(matched)}`);
    }
  }
}

async function clickKbaContinue(page) {
  await fillIfPresent(page, 'KBA continue/submit (#submit or role=button)', async () => {
    const sub = page.locator('#submit');
    if (await sub.count()) {
      await sub.first().click({ timeout: 25000 });
    } else {
      await page.getByRole('button', { name: /continue|submit|next|confirm/i }).first().click({ timeout: 25000 });
    }
  });
}

async function isWrongKbaAnswersPage(page) {
  const body = (await page.locator('body').innerText().catch(() => '')).slice(0, 12000);
  return (
    /one or more answers.*incorrect|answers?.*incorrect|not match|try again|incorrect answer/i.test(body) ||
    /unable to verify|could not verify/i.test(body)
  );
}

async function waitForAnswersFile(sessionDir, timeoutMs) {
  const answersPath = join(sessionDir, 'answers.json');
  const start = Date.now();
  logStep(`handoff: waiting for ${answersPath} (timeout ${Math.round(timeoutMs / 1000)}s)`);

  while (Date.now() - start < timeoutMs) {
    if (existsSync(answersPath)) {
      const raw = await readFile(answersPath, 'utf8');
      logStep('handoff: answers.json read');
      return JSON.parse(raw);
    }
    await sleep(2000);
  }

  throw new Error(`Timeout waiting for ${answersPath}`);
}

async function removeAnswersFile(sessionDir) {
  const answersPath = join(sessionDir, 'answers.json');
  try {
    if (existsSync(answersPath)) {
      await unlink(answersPath);
      logStep(`handoff: removed ${answersPath} for retry`);
    }
  } catch (e) {
    logStep(`handoff: could not remove answers.json (${e?.message || e})`);
  }
}

async function downloadReportPdf(page, reportPath) {
  logStep(`pdf: saving to ${reportPath}`);
  await mkdir(dirname(reportPath), { recursive: true });

  const tryClick = async (locatorFn, label) => {
    try {
      logStep(`pdf: ${label} — wait for download`);
      const [download] = await Promise.all([page.waitForEvent('download', { timeout: 240000 }), locatorFn()]);
      return download;
    } catch (e) {
      logStep(`pdf: ${label} failed (${e?.message || e})`);
      return null;
    }
  };

  let download =
    (await tryClick(() => page.locator('#SaveAsPdf').first().click({ timeout: 20000 }), '#SaveAsPdf')) ||
    (await tryClick(
      () => page.getByRole('button', { name: /save as pdf|download.*pdf/i }).first().click({ timeout: 20000 }),
      'Save as PDF button',
    )) ||
    (await tryClick(
      () => page.getByRole('link', { name: /save as pdf|download.*pdf/i }).first().click({ timeout: 20000 }),
      'Save as PDF link',
    )) ||
    (await tryClick(
      () => page.locator('[data-url*="download-pdf" i], a[href*="download-pdf" i]').first().click({ timeout: 20000 }),
      '[data-url/href*="download-pdf"]',
    ));

  if (download) {
    await download.saveAs(reportPath);
    logStep(`pdf: download saved → ${reportPath}`);
    return true;
  }

  if (process.env.CREDIT_CHECK_V2_ALLOW_PRINT_PDF === '1') {
    logStep('pdf: CREDIT_CHECK_V2_ALLOW_PRINT_PDF=1 print fallback');
    try {
      await page.pdf({ path: reportPath, format: 'A4', printBackground: true });
      return true;
    } catch (e) {
      logStep(`pdf: print failed (${e?.message || e})`);
    }
  }

  return false;
}

async function isPdfOfferVisible(page) {
  const save = await page.locator('#SaveAsPdf').isVisible().catch(() => false);
  const link = await page.getByRole('link', { name: /save as pdf/i }).first().isVisible().catch(() => false);
  return save || link;
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
  const tempEmail = payload.tempEmail || payload.tempMail;
  const tempMailApi = payload.tempMailApi || {};
  const baseUrl = tempMailApi.baseUrl;
  const apiKey = tempMailApi.apiKey;

  const sessionId = payload.sessionId || randomUUID();
  const sessionDir =
    payload.sessionDir || join(process.cwd(), 'storage', 'app', 'credit-check-v2', 'sessions', sessionId);
  const reportPath = payload.reportPath;

  if (!tempEmail || !baseUrl || !apiKey || !reportPath) {
    logStep('fatal: missing tempEmail, tempMailApi, or reportPath');
    process.exit(1);
  }

  await mkdir(sessionDir, { recursive: true });

  const headless = process.env.PLAYWRIGHT_HEADLESS !== '0';
  logProgress('Launching browser…');
  logStep(`launch: headless=${headless}, stealth Chromium, 1920×1080, en-GB, Europe/London`);

  const browser = await chromium.launch({
    headless,
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
    extraHTTPHeaders: { 'Accept-Language': 'en-GB,en;q=0.9' },
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
    if (await exitIfNegativeFailure(page)) {
      return;
    }

    logProgress('Filling personal details…');
    logStep('stage: About You form (exact field IDs)');
    await fillAboutYouForm(page, personalData, tempEmail);

    let skipVerificationLinkPoll = false;
    logStep('post-submit: settle loop (5×3s) — check negative / email-auth / KBA / PDF before temp-mail poll');
    for (let i = 0; i < 5; i++) {
      if (await exitIfNegativeFailure(page)) {
        return;
      }
      if (await isEmailAuthenticationPage(page)) {
        skipVerificationLinkPoll = true;
        logStep('post-submit settle: email authentication page — skipping temp-mail verification-link poll');
        break;
      }
      if (await isKbaWizardPage(page)) {
        skipVerificationLinkPoll = true;
        logProgress('Security questions detected…');
        logStep('post-submit settle: KBA wizard — skipping temp-mail verification-link poll');
        break;
      }
      if (await isPdfOfferVisible(page)) {
        skipVerificationLinkPoll = true;
        logStep('post-submit settle: PDF controls — skipping temp-mail verification-link poll');
        break;
      }
      if (i < 4) {
        await sleep(3000);
      }
    }

    if (await exitIfNegativeFailure(page)) {
      return;
    }

    if (!skipVerificationLinkPoll) {
      logProgress('Waiting for verification email…');
      logStep('stage: poll temp-mail for verification link');
      const verification = await waitForVerificationLink({ baseUrl, apiKey, email: tempEmail });
      if (!verification?.url) {
        emitJson({ success: false, error: 'verification_link_not_found' });
        throw new Error('Verification link not found');
      }

      logProgress('Opening verification link…');
      logStep('stage: open verification link');
      await page.goto(verification.url, { waitUntil: 'domcontentloaded', timeout: 120000 });
      await sleep(2500);
      if (await exitIfNegativeFailure(page)) {
        return;
      }
    } else {
      await sleep(1500);
      if (await exitIfNegativeFailure(page)) {
        return;
      }
    }

    if (await isEmailAuthenticationPage(page)) {
      logStep('stage: email authentication / OTP page');
      await handleEmailAuthenticationPage(page, baseUrl, apiKey, tempEmail);
      await sleep(3000);
      if (await exitIfNegativeFailure(page)) {
        return;
      }
    }

    const answerTimeout = Number(process.env.CREDIT_CHECK_V2_ANSWER_TIMEOUT_MS) || 45 * 60 * 1000;

    // Wait up to ~60s for KBA or PDF after verification / OTP
    for (let w = 0; w < 20; w++) {
      if (await exitIfNegativeFailure(page)) {
        return;
      }
      if (await isPdfOfferVisible(page)) {
        logStep('flow: PDF controls already visible');
        break;
      }
      if (await isKbaWizardPage(page)) {
        logProgress('Security questions detected…');
        logStep('flow: KBA wizard detected');
        break;
      }
      if (await isEmailAuthenticationPage(page)) {
        await handleEmailAuthenticationPage(page, baseUrl, apiKey, tempEmail);
        if (await exitIfNegativeFailure(page)) {
          return;
        }
      }
      await sleep(3000);
    }

    for (let attempt = 1; attempt <= MAX_KBA_ATTEMPTS; attempt++) {
      if (await exitIfNegativeFailure(page)) {
        return;
      }
      if (await isPdfOfferVisible(page)) {
        logStep('flow: skipping KBA — PDF ready');
        break;
      }

      if (!(await isKbaWizardPage(page))) {
        logStep('flow: KBA wizard not visible — retrying (TUNE: intermediate step)');
        let seen = false;
        for (let r = 0; r < 5; r++) {
          await sleep(3000);
          if (await exitIfNegativeFailure(page)) {
            return;
          }
          if (await isPdfOfferVisible(page)) {
            seen = true;
            break;
          }
          if (await isKbaWizardPage(page)) {
            logProgress('Security questions detected…');
            seen = true;
            break;
          }
        }
        if (await isPdfOfferVisible(page)) {
          break;
        }
        if (!seen) {
          throw new Error('Expected KBA (#wizard-page[data-ga-event="kba"] / #questionList) or PDF controls');
        }
      }

      logProgress('Security questions detected…');
      logStep(`stage: KBA / ID Verification (round ${attempt}/${MAX_KBA_ATTEMPTS})`);
      const extracted = await extractKbaQuestions(page);
      if (extracted.length === 0) {
        logStep('kba: no questions extracted — check #questionList (TUNE)');
      }

      const questionsForEmit = extracted.map((q) => ({ id: String(q.id), text: String(q.text) }));
      const out = { status: 'security_questions', sessionId, questions: questionsForEmit };

      await writeFile(join(sessionDir, 'security-questions.json'), JSON.stringify(out, null, 2), 'utf8');
      await writeFile(
        join(sessionDir, 'session-status.json'),
        JSON.stringify({ phase: 'awaiting_answers', sessionId, attempt, at: new Date().toISOString() }, null, 2),
        'utf8',
      );

      emitJson(out);
      logStep(`handoff: CREDIT_CHECK_V2_JSON security_questions (${questionsForEmit.length} question(s))`);

      if (attempt > 1) {
        await removeAnswersFile(sessionDir);
      }

      const answersPayload = await waitForAnswersFile(sessionDir, answerTimeout);
      await applyKbaRadioAnswers(page, answersPayload.answers || [], extracted);
      await clickKbaContinue(page);
      await sleep(2000);
      if (await exitIfNegativeFailure(page)) {
        return;
      }
      await sleep(2000);
      if (await exitIfNegativeFailure(page)) {
        return;
      }

      if (await isWrongKbaAnswersPage(page)) {
        logStep(`kba: incorrect answers message detected (round ${attempt})`);
        if (attempt >= MAX_KBA_ATTEMPTS) {
          throw new Error('KBA: incorrect answers after maximum attempts');
        }
        await removeAnswersFile(sessionDir);
        continue;
      }

      logStep('kba: no incorrect-message banner — assuming proceed to report');
      break;
    }

    await sleep(2000);
    if (await exitIfNegativeFailure(page)) {
      return;
    }
    logProgress('Downloading PDF report…');
    logStep('stage: download PDF report');
    const ok = await downloadReportPdf(page, reportPath);
    if (!ok) {
      emitJson({ success: false, error: 'pdf_not_saved', reportPath });
      throw new Error('PDF download failed');
    }

    const final = { success: true, reportPath };
    emitJson(final);
    logProgress('Credit check completed — PDF saved');
    logStep(`complete: ${JSON.stringify(final)}`);
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
