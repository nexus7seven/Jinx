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

const KBA_FIRST_MARKER_NORM =
  'please answer the following questions to verify your identity.';
const KBA_SECOND_MARKER_NORM =
  'unfortunately one or more answers that you provided was incorrect. you now have the opportunity to have a second and final attempt to answer these questions in order to verify your identity.';

function normalizeJourneyText(s) {
  return String(s || '')
    .toLowerCase()
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * DOM fallback when &lt;title&gt; has not updated yet: wizard-step + negative article + failure copy.
 */
async function isNegativeIdVerificationDom(page) {
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

/**
 * Single journey classifier. Negative always checked first (title `Negative Id Verification` wins).
 * @returns {'negative'|'email_auth'|'kba_first'|'kba_second'|'pdf'|'unknown'}
 */
async function detectJourneyState(page) {
  const docTitle = (await page.title().catch(() => '')).trim();
  const titleNorm = normalizeJourneyText(docTitle);

  if (titleNorm.includes('negative id verification')) {
    return 'negative';
  }
  if (await isNegativeIdVerificationDom(page)) {
    return 'negative';
  }

  if (await isPdfOfferVisible(page)) {
    return 'pdf';
  }

  const wizardText = (await page.locator('#wizard-step').first().innerText().catch(() => '')).slice(0, 24000);
  const body = (await page.locator('body').innerText().catch(() => '')).slice(0, 64000);
  const blob = normalizeJourneyText(`${docTitle}\n${wizardText}\n${body}`);

  if (blob.includes(KBA_SECOND_MARKER_NORM)) {
    return 'kba_second';
  }
  if (blob.includes(KBA_FIRST_MARKER_NORM)) {
    return 'kba_first';
  }

  if (titleNorm.includes('email authentication required')) {
    return 'email_auth';
  }
  const hasInputCode = await page.locator('#InputCode').isVisible().catch(() => false);
  if (hasInputCode && /email authentication/i.test(body)) {
    return 'email_auth';
  }

  return 'unknown';
}

/** Temporary debugging: page evidence when journey state is unknown (post-submit tuning). */
async function collectJourneyDebugSnapshot(page) {
  const safeText = async (locator, limit = 2000) => {
    try {
      const txt = await locator.innerText();
      return txt.replace(/\s+/g, ' ').trim().slice(0, limit);
    } catch {
      return '';
    }
  };

  const exists = async (locator) => {
    try {
      return (await locator.count()) > 0;
    } catch {
      return false;
    }
  };

  const getTexts = async (locator, max = 5) => {
    try {
      const nodes = await locator.all();
      const out = [];
      for (let i = 0; i < Math.min(nodes.length, max); i++) {
        const t = await nodes[i].innerText().catch(() => '');
        if (t) out.push(t.replace(/\s+/g, ' ').trim().slice(0, 300));
      }
      return out;
    } catch {
      return [];
    }
  };

  return {
    title: await page.title().catch(() => ''),
    url: page.url(),
    wizardStepExists: await exists(page.locator('#wizard-step')),
    wizardPageExists: await exists(page.locator('#wizard-page')),
    wizardPageGaEvent: await page.locator('#wizard-page').getAttribute('data-ga-event').catch(() => null),
    inputCodeExists: await exists(page.locator('#InputCode')),
    bodySnippet: await safeText(page.locator('body')),
    wizardStepSnippet: await safeText(page.locator('#wizard-step')),
    h1Texts: await getTexts(page.locator('h1')),
    h2Texts: await getTexts(page.locator('h2')),
    boldFontTexts: await getTexts(page.locator('.bold-font')),
    paragraphSnippets: await getTexts(page.locator('p')),
  };
}

function isAboutYouUrl(url) {
  return /CreditReport\/AboutYou/i.test(String(url || ''));
}

/** True when still on the About You step (URL + form markers). */
async function isAboutYouPageStill(page) {
  if (!isAboutYouUrl(page.url())) return false;
  const h1About = await page.getByRole('heading', { name: /about you/i }).first().isVisible().catch(() => false);
  const forename = await page.locator('#IndividualDetails_Forename').isVisible().catch(() => false);
  return h1About || forename;
}

/** Validation / error copy when About You submit did not navigate away (ASP.NET unobtrusive + summaries). */
async function collectAboutYouValidationSnapshot(page) {
  const safeText = async (locator, limit = 1000) => {
    try {
      const txt = await locator.innerText();
      return txt.replace(/\s+/g, ' ').trim().slice(0, limit);
    } catch {
      return '';
    }
  };

  const gatherTexts = async (selector, max = 20) => {
    try {
      const loc = page.locator(selector);
      const n = await loc.count();
      const out = [];
      for (let i = 0; i < Math.min(n, max); i++) {
        const t = await loc.nth(i).innerText().catch(() => '');
        const s = t.replace(/\s+/g, ' ').trim();
        if (s) out.push(s.slice(0, 500));
      }
      return out;
    } catch {
      return [];
    }
  };

  const roleAlert = await gatherTexts('[role="alert"]', 10);
  const summaryBlocks = await gatherTexts(
    '.validation-summary-errors, .validation-summary-valid, [class*="validation-summary"], .alert-danger, .validation-summary',
    5,
  );

  return {
    title: await page.title().catch(() => ''),
    url: page.url(),
    visibleErrorTexts: roleAlert,
    validationSummaryTexts: summaryBlocks,
    fieldValidationTexts: await gatherTexts('.field-validation-error'),
    dataValmsgTexts: await gatherTexts('[data-valmsg-for]'),
    aboutYouHeadingVisible: await page.getByRole('heading', { name: /about you/i }).first().isVisible().catch(() => false),
    postcodeControlVisible: await page.locator('#Address_Postcode').isVisible().catch(() => false),
    addressDropdownVisible: await page.locator('#address-dropdown').isVisible().catch(() => false),
    manualAddressControlsVisible: await page
      .locator('input[id*="AddressLine" i], input[name*="AddressLine" i]')
      .first()
      .isVisible()
      .catch(() => false),
    wizardStepSnippet: await safeText(page.locator('#wizard-step'), 1000),
  };
}

/** Terminal failure: `Negative Id Verification` (title) or negative DOM — emit payload and stop. */
async function exitIfNegativeFailure(page) {
  const st = await detectJourneyState(page);
  if (st !== 'negative') {
    return false;
  }
  logStep('state: negative');
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

/** True when TransUnion / OneTrust cookie UI is likely blocking clicks (banner visible, .show, or dark overlay). */
async function isCookieBannerBlockingInteractions(page) {
  const banner = page.locator('#CookieBanner');
  if ((await banner.count().catch(() => 0)) === 0) {
    return false;
  }
  const first = banner.first();
  const visible = await first.isVisible().catch(() => false);
  const hasShow = await first.evaluate((el) => el.classList.contains('show')).catch(() => false);
  const intercepting = await first
    .evaluate((el) => {
      const s = window.getComputedStyle(el);
      const pe = s.pointerEvents !== 'none';
      const disp = s.display !== 'none';
      const op = parseFloat(s.opacity || '1');
      return pe && disp && op > 0.05 && el.offsetParent !== null;
    })
    .catch(() => false);
  const backdrop = page.locator('.modal-backdrop, .onetrust-pc-dark-filter, #onetrust-consent-sdk').first();
  const backVis = (await backdrop.count().catch(() => 0)) > 0 && (await backdrop.isVisible().catch(() => false));
  return visible || hasShow || (intercepting && visible) || backVis;
}

/**
 * Dismiss TransUnion cookie banner / overlays so #find-address is not covered.
 * @param {string} [reason] — 'initial' | 'before-address-lookup' | 'attempt-N' | 'after-pointer-intercept'
 */
async function ensureCookieBannerDismissed(page, reason) {
  if (reason === 'before-address-lookup') {
    const v = await isCookieBannerBlockingInteractions(page);
    logStep(`cookie banner: visible before address lookup = ${v}`);
  } else if (reason && /^attempt-\d+$/.test(reason)) {
    const n = reason.replace('attempt-', '');
    logStep(`cookie banner: dismissed before address lookup attempt ${n}`);
  } else if (reason === 'after-pointer-intercept') {
    logStep('cookie banner: dismiss after pointer intercept');
  } else if (reason === 'initial') {
    logStep('cookie banner: ensure dismissed (initial navigation)');
  }

  const clickers = [
    page.locator('#btnCookieBannerAgree'),
    page.locator('#btnCookieBannerReject'),
    page.locator('#btnCloseCookieSettings'),
    page.getByRole('button', { name: /accept all|allow all|i agree|accept cookies|accept|agree/i }),
    page.locator('#onetrust-accept-btn-handler'),
    page.locator('button:has-text("Accept")'),
  ];

  for (const loc of clickers) {
    try {
      if ((await loc.count().catch(() => 0)) === 0) continue;
      const btn = loc.first();
      if (await btn.isVisible().catch(() => false)) {
        await btn.click({ timeout: 5000 });
        await sleep(500);
      }
    } catch {
      /* next */
    }
  }

  if (await isCookieBannerBlockingInteractions(page)) {
    await page
      .evaluate(() => {
        const el = document.querySelector('#CookieBanner');
        if (el) {
          el.classList.remove('show');
          el.setAttribute('aria-hidden', 'true');
          el.style.display = 'none';
          el.style.pointerEvents = 'none';
        }
        document.querySelectorAll('.modal-backdrop, .onetrust-pc-dark-filter').forEach((b) => {
          try {
            b.remove();
          } catch {
            /* ignore */
          }
        });
      })
      .catch(() => {});
    logStep('cookie banner: fallback hide applied');
    await sleep(200);
  }

  const finalBlocking = await isCookieBannerBlockingInteractions(page);
  logStep(`cookie banner: final visible state = ${finalBlocking}`);
  return !finalBlocking;
}

async function dismissCookieBanner(page) {
  await ensureCookieBannerDismissed(page, 'initial');
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

/** TUNE: max wait for PAF dropdown after #find-address (polling inside selectAddressDropdownMatchingJinx). */
const ADDRESS_DROPDOWN_WAIT_MS = 12000;

/** TUNE: total time to wait for address API / DOM after clicking #find-address. */
const ADDRESS_LOOKUP_MAX_MS = 22000;
const ADDRESS_LOOKUP_POLL_MS = 450;

/**
 * Real PAF control is the &lt;select&gt; only — plain `#PossibleAddresses_SelectedItemValue` also matches a hidden &lt;input&gt; with the same id.
 */
const POSSIBLE_ADDRESSES_SELECT = 'select#PossibleAddresses_SelectedItemValue';
/** Duplicate id: hidden field that may need to mirror the select value for ASP.NET. */
const POSSIBLE_ADDRESSES_HIDDEN_INPUT = 'input[type="hidden"]#PossibleAddresses_SelectedItemValue';

function isPlaceholderAddressOption(value, text) {
  const v = String(value || '').trim();
  const t = String(text || '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();
  if (!v || v === 'NOT_SELECTED' || /^not_selected$/i.test(v)) return true;
  if (t === 'select…' || t === 'select...' || /^select[….]?$/.test(t)) return true;
  if (/^select\b/.test(t) && t.length < 28) return true;
  return false;
}

/** True when the real address &lt;select&gt; has at least one non-placeholder option. */
async function possibleAddressesSelectHasRealOptions(page) {
  const sel = page.locator(POSSIBLE_ADDRESSES_SELECT);
  if ((await sel.count().catch(() => 0)) === 0) return false;
  const opts = sel.locator('option');
  const n = await opts.count().catch(() => 0);
  for (let i = 0; i < n; i++) {
    const val = (await opts.nth(i).getAttribute('value').catch(() => '')) || '';
    const text = (await opts.nth(i).innerText().catch(() => '')).trim();
    if (!isPlaceholderAddressOption(val, text)) return true;
  }
  return false;
}

async function waitForPossibleAddressesSelectReady(page) {
  const until = Date.now() + ADDRESS_DROPDOWN_WAIT_MS;
  while (Date.now() < until) {
    const sel = page.locator(POSSIBLE_ADDRESSES_SELECT);
    if ((await sel.count().catch(() => 0)) === 0) {
      await sleep(200);
      continue;
    }
    await sel.first().waitFor({ state: 'attached', timeout: 2000 }).catch(() => {});
    if (await possibleAddressesSelectHasRealOptions(page)) {
      return true;
    }
    await sleep(200);
  }
  return false;
}

/** Per-attempt wait for `#PossibleAddresses_SelectedItemValue` to gain real options after a trigger interaction. */
const ADDRESS_LOOKUP_TRIGGER_WAIT_PER_ATTEMPT_MS = 10000;

function addressLookupNetworkUrlMatches(url) {
  return /Address|PossibleAddresses|AboutYou|CreditReport|ajax|Find/i.test(String(url || ''));
}

async function gatherAddressLookupDomDebug(page) {
  const sel = page.locator(POSSIBLE_ADDRESSES_SELECT);
  const possibleAddressesSelectExists = (await sel.count().catch(() => 0)) > 0;
  let optionCount = 0;
  let realOptionCount = 0;
  if (possibleAddressesSelectExists) {
    const opts = sel.locator('option');
    optionCount = await opts.count().catch(() => 0);
    for (let i = 0; i < optionCount; i++) {
      const val = (await opts.nth(i).getAttribute('value').catch(() => '')) || '';
      const text = (await opts.nth(i).innerText().catch(() => '')).trim();
      if (!isPlaceholderAddressOption(val, text)) realOptionCount++;
    }
  }
  const hiddenSelectListInputCount = await page
    .locator('input[id^="PossibleAddresses_SelectList_"]')
    .count()
    .catch(() => 0);
  const validationTexts = await page
    .evaluate(() => {
      const out = [];
      document.querySelectorAll('.field-validation-error, [data-valmsg-for]').forEach((el) => {
        const t = (el.textContent || '').replace(/\s+/g, ' ').trim();
        if (t) out.push(t.slice(0, 400));
      });
      return [...new Set(out)].slice(0, 20);
    })
    .catch(() => []);
  const wrap = page.locator('#address-dropdown');
  let wrapperHtmlSnippet = '';
  let addressDropdownInnerHTMLLength = 0;
  if ((await wrap.count().catch(() => 0)) > 0) {
    const html = (await wrap.first().innerHTML().catch(() => '')) || '';
    addressDropdownInnerHTMLLength = html.length;
    wrapperHtmlSnippet = html.slice(0, 1500);
  }
  return {
    possibleAddressesSelectExists,
    optionCount,
    realOptionCount,
    hiddenSelectListInputCount,
    validationTexts,
    addressDropdownInnerHTMLLength,
    wrapperHtmlSnippet,
  };
}

function safeSerializeTriggerResult(result) {
  if (!result || typeof result !== 'object') return result;
  return {
    success: result.success,
    possibleAddressesSelectExists: result.possibleAddressesSelectExists,
    realOptionCount: result.realOptionCount,
    attempts: result.attempts,
    seenRequests: (result.seenRequests || []).slice(0, 40),
    seenResponses: (result.seenResponses || []).slice(0, 40),
    validationTexts: result.validationTexts,
    addressDropdownInnerHTMLLength: result.addressDropdownInnerHTMLLength,
    wrapperHtmlSnippet: result.wrapperHtmlSnippet
      ? String(result.wrapperHtmlSnippet).slice(0, 2000)
      : result.wrapperHtmlSnippet,
  };
}

/**
 * Prove whether `#find-address` caused network activity and `#PossibleAddresses_SelectedItemValue` to populate.
 */
async function tryTriggerAddressLookup(page) {
  const seenRequests = [];
  const seenResponses = [];
  const maxNet = 60;
  const onRequest = (req) => {
    if (seenRequests.length >= maxNet) return;
    try {
      const url = req.url();
      if (!addressLookupNetworkUrlMatches(url)) return;
      seenRequests.push({ method: req.method(), url: url.slice(0, 900) });
    } catch {
      /* ignore */
    }
  };
  const onResponse = (res) => {
    if (seenResponses.length >= maxNet) return;
    try {
      const url = res.url();
      if (!addressLookupNetworkUrlMatches(url)) return;
      seenResponses.push({ status: res.status(), url: url.slice(0, 900) });
    } catch {
      /* ignore */
    }
  };

  page.on('request', onRequest);
  page.on('response', onResponse);

  const pcLoc = page.locator('#Address_Postcode');
  const findAddr = page.locator('#find-address');
  const attempts = [];

  const pollForPopulatedSelect = async () => {
    const deadline = Date.now() + ADDRESS_LOOKUP_TRIGGER_WAIT_PER_ATTEMPT_MS;
    while (Date.now() < deadline) {
      if (await possibleAddressesSelectHasRealOptions(page)) {
        return true;
      }
      await sleep(300);
    }
    return false;
  };

  const runAttempt = async (label, action) => {
    logStep(`address lookup ${label}`);
    const execAction = async () => {
      await action();
    };
    try {
      await execAction();
    } catch (e) {
      const msg = String(e?.message || e);
      const intercept =
        /intercepts pointer|CookieBanner|cookie|modal|overlay/i.test(msg) ||
        /subtree intercepts pointer/i.test(msg);
      if (intercept) {
        logStep(`address lookup: click intercepted (cookie/modal likely) — ${msg.slice(0, 240)}`);
        await ensureCookieBannerDismissed(page, 'after-pointer-intercept');
        const stillBlocking = await isCookieBannerBlockingInteractions(page);
        logStep(`cookie banner: blocking after dismiss = ${stillBlocking}`);
        try {
          await execAction();
        } catch (e2) {
          const domAfter = await gatherAddressLookupDomDebug(page);
          attempts.push({
            label,
            error: String(e2?.message || e2),
            domAfter,
            retriedAfterCookieDismiss: true,
          });
          return false;
        }
      } else {
        const domAfter = await gatherAddressLookupDomDebug(page);
        attempts.push({ label, error: msg, domAfter });
        return false;
      }
    }
    await sleep(400);
    const ok = await pollForPopulatedSelect();
    const domAfter = await gatherAddressLookupDomDebug(page);
    logStep(
      `address lookup dom after ${label}: select=${domAfter.possibleAddressesSelectExists} options=${domAfter.optionCount} realOptions=${domAfter.realOptionCount} hiddenSelectListInputs=${domAfter.hiddenSelectListInputCount} wrapperHtmlLen=${domAfter.addressDropdownInnerHTMLLength}`,
    );
    attempts.push({ label, ok, domAfter });
    return ok;
  };

  const sequence = [
    [
      'attempt 1: normal click',
      async () => {
        await findAddr.click({ timeout: 15000 });
      },
    ],
    [
      'attempt 2: focus postcode + Enter',
      async () => {
        await pcLoc.focus();
        await page.keyboard.press('Enter');
      },
    ],
    [
      'attempt 3: refocus postcode + normal click find-address',
      async () => {
        await pcLoc.click({ timeout: 10000 });
        await sleep(200);
        await findAddr.click({ timeout: 15000 });
      },
    ],
    [
      'attempt 4: forced click find-address',
      async () => {
        await findAddr.click({ force: true, timeout: 15000 });
      },
    ],
  ];

  let success = false;
  let attemptIndex = 0;
  for (const [label, fn] of sequence) {
    attemptIndex += 1;
    await ensureCookieBannerDismissed(page, `attempt-${attemptIndex}`);
    if (await runAttempt(label, fn)) {
      success = true;
      break;
    }
  }

  page.off('request', onRequest);
  page.off('response', onResponse);

  const finalDom = await gatherAddressLookupDomDebug(page);
  if (!success) {
    success = await possibleAddressesSelectHasRealOptions(page);
  }

  return {
    success,
    possibleAddressesSelectExists: finalDom.possibleAddressesSelectExists,
    realOptionCount: finalDom.realOptionCount,
    attempts,
    seenRequests,
    seenResponses,
    validationTexts: finalDom.validationTexts,
    addressDropdownInnerHTMLLength: finalDom.addressDropdownInnerHTMLLength,
    wrapperHtmlSnippet: finalDom.wrapperHtmlSnippet,
  };
}

/**
 * Temporary debugging: real &lt;select&gt; options (#address-dropdown is only a wrapper).
 */
async function collectAddressDropdownSnapshot(page) {
  const sel = page.locator(POSSIBLE_ADDRESSES_SELECT);
  const selectExists = (await sel.count().catch(() => 0)) > 0;
  const optionsPreview = [];
  if (selectExists) {
    const opts = sel.locator('option');
    const n = await opts.count().catch(() => 0);
    for (let i = 0; i < Math.min(n, 15); i++) {
      const val = (await opts.nth(i).getAttribute('value').catch(() => '')) || '';
      const text = (await opts.nth(i).innerText().catch(() => '')).replace(/\s+/g, ' ').trim().slice(0, 220);
      optionsPreview.push({ i, value: val, text, placeholder: isPlaceholderAddressOption(val, text) });
    }
  }
  const wrap = page.locator('#address-dropdown');
  const wrapperExists = (await wrap.count().catch(() => 0)) > 0;
  let wrapperSnippet = '';
  if (wrapperExists) {
    wrapperSnippet = (await wrap.first().innerHTML().catch(() => '')).slice(0, 1500);
  }
  return {
    possibleAddressesSelectExists: selectExists,
    optionsPreview,
    addressDropdownWrapperExists: wrapperExists,
    addressDropdownInnerHTMLSnippet: wrapperSnippet,
  };
}

function normalizeAddressCandidateText(s) {
  return String(s || '')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Leading house/door number at start of line (e.g. `1 Carolan…` → `1`, `12 Carolan…` → `12`). */
function firstNumericTokenFromAddressLine(text) {
  const t = normalizeAddressCandidateText(text);
  const m = t.match(/^(\d+)/);
  return m ? m[1] : '';
}

/**
 * Debug: everything the page may expose for PAF address lines (wrapper, select, hidden SelectList).
 */
async function collectRawAddressCandidates(page) {
  const wrap = page.locator('#address-dropdown');
  const addressDropdownExists = (await wrap.count().catch(() => 0)) > 0;
  let addressDropdownInnerHTML = '';
  let addressDropdownInnerText = '';
  if (addressDropdownExists) {
    addressDropdownInnerHTML = (await wrap.first().innerHTML().catch(() => '')).slice(0, 5000);
    addressDropdownInnerText = (await wrap.first().innerText().catch(() => '')).slice(0, 5000);
  }

  const sel = page.locator(POSSIBLE_ADDRESSES_SELECT);
  const possibleAddressesSelectExists = (await sel.count().catch(() => 0)) > 0;
  const selectOptions = [];
  if (possibleAddressesSelectExists) {
    const opts = sel.locator('option');
    const n = await opts.count().catch(() => 0);
    for (let i = 0; i < n; i++) {
      const value = (await opts.nth(i).getAttribute('value').catch(() => '')) || '';
      const text = (await opts.nth(i).innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
      selectOptions.push({ value, text });
    }
  }

  const hiddenSelectListPairs = await page.evaluate(() => {
    const pairs = [];
    const textEls = Array.from(
      document.querySelectorAll('input[id^="PossibleAddresses_SelectList_"][id$="__Text"]'),
    );
    for (const tEl of textEls) {
      const base = tEl.id.replace(/__Text$/i, '');
      const vEl = document.getElementById(`${base}__Value`);
      const text = (tEl.value || '').trim();
      const value = vEl ? (vEl.value || '').trim() : '';
      pairs.push({
        text,
        value,
        textId: tEl.id,
        valueId: vEl ? vEl.id : null,
      });
    }
    return pairs;
  });

  const fromEvaluate = await page.evaluate(() => {
    const out = [];
    const add = (s) => {
      const t = (s || '').replace(/\s+/g, ' ').trim();
      if (t.length > 3) out.push(t);
    };
    document.querySelectorAll('input[id^="PossibleAddresses_SelectList_"][id$="__Text"]').forEach((el) =>
      add(el.value),
    );
    document.querySelectorAll('input[id^="PossibleAddresses_SelectList_"][id$="__Value"]').forEach((el) =>
      add(el.value),
    );
    const root = document.querySelector('#address-dropdown');
    if (root) {
      root.querySelectorAll('li, button, a, [role="option"], label, option').forEach((el) => {
        const t = (el.innerText || el.textContent || '').replace(/\s+/g, ' ').trim();
        if (t.length > 5 && t.length < 400) add(t);
      });
    }
    return [...new Set(out)];
  });

  const allCandidateStrings = [];
  const pushUnique = (s) => {
    const n = normalizeAddressCandidateText(s);
    if (!n) return;
    if (!allCandidateStrings.includes(n)) allCandidateStrings.push(n);
  };
  for (const o of selectOptions) {
    pushUnique(o.text);
  }
  for (const p of hiddenSelectListPairs) {
    pushUnique(p.text);
  }
  for (const t of fromEvaluate) {
    pushUnique(t);
  }

  const candidatesWithMeta = [];
  const seen = new Set();
  for (const raw of allCandidateStrings) {
    const normalized = normalizeAddressCandidateText(raw);
    if (seen.has(normalized)) continue;
    seen.add(normalized);
    candidatesWithMeta.push({
      raw,
      normalized,
      firstNumber: firstNumericTokenFromAddressLine(raw),
    });
  }

  return {
    addressDropdownExists,
    addressDropdownInnerHTML,
    addressDropdownInnerText,
    possibleAddressesSelectExists,
    selectOptions,
    hiddenSelectListPairs,
    allCandidateStrings,
    candidatesWithMeta,
  };
}

/**
 * Rich logging for postcode / find-address debugging (before and after click).
 */
async function logAddressLookupDiagnostics(page, phaseLabel) {
  const pc = page.locator('#Address_Postcode');
  const fa = page.locator('#find-address');
  const dd = page.locator('#address-dropdown');
  const addrSel = page.locator(POSSIBLE_ADDRESSES_SELECT);
  let pcVal = '';
  try {
    pcVal = (await pc.inputValue().catch(() => '')).trim();
  } catch {
    pcVal = '';
  }
  const faCount = await fa.count().catch(() => 0);
  const faVis = faCount ? await fa.first().isVisible().catch(() => false) : false;
  const ddCount = await dd.count().catch(() => 0);
  const ddVis = ddCount ? await dd.first().isVisible().catch(() => false) : false;
  const selCount = await addrSel.count().catch(() => 0);
  const selVis = selCount ? await addrSel.first().isVisible().catch(() => false) : false;
  let optionCount = 0;
  let realOptionCount = 0;
  const optionPreview = [];
  if (selCount) {
    const opts = addrSel.locator('option');
    optionCount = await opts.count().catch(() => 0);
    for (let i = 0; i < optionCount; i++) {
      const val = (await opts.nth(i).getAttribute('value').catch(() => '')) || '';
      const text = (await opts.nth(i).innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
      if (i < 6) {
        optionPreview.push({ value: val, text: text.slice(0, 120) });
      }
      if (!isPlaceholderAddressOption(val, text)) realOptionCount++;
    }
  }
  let selectedValueBefore = '';
  if (selCount) {
    selectedValueBefore = (await addrSel.inputValue().catch(() => '')) || '';
  }
  const manualLinkVis = await page
    .getByRole('link', { name: /enter.*address.*manually|can't find|cannot find your address|address not listed/i })
    .first()
    .isVisible()
    .catch(() => false);
  const manualLineVis = await page
    .locator('#Address_AddressLine1, #AddressLine1, input[id*="AddressLine1" i]')
    .first()
    .isVisible()
    .catch(() => false);

  logStep(
    `address: [${phaseLabel}] postcode="${pcVal}" find-address count=${faCount} visible=${faVis} | wrapper #address-dropdown count=${ddCount} visible=${ddVis} | ${POSSIBLE_ADDRESSES_SELECT} count=${selCount} visible=${selVis} options=${optionCount} realOptions=${realOptionCount} selectedValue="${selectedValueBefore}" preview=${JSON.stringify(optionPreview)} | manualLink=${manualLinkVis} manualLine=${manualLineVis}`,
  );
}

/**
 * After #find-address, wait for dropdown population, manual entry, or validation errors.
 * @returns {Promise<{ mode: 'dropdown' | 'manual' | 'error' | 'none', details: Record<string, unknown> }>}
 */
async function waitForAddressLookupState(page) {
  const deadline = Date.now() + ADDRESS_LOOKUP_MAX_MS;
  let lastSnapshot = {};

  while (Date.now() < deadline) {
    const addrSel = page.locator(POSSIBLE_ADDRESSES_SELECT);
    const dd = page.locator('#address-dropdown');

    const validationTexts = await page
      .evaluate(() => {
        const out = [];
        const root =
          document.querySelector('#Address_Postcode')?.closest('form') ||
          document.querySelector('#wizard-step') ||
          document.body;
        root.querySelectorAll('.field-validation-error, span.field-validation-error').forEach((el) => {
          const t = (el.textContent || '').replace(/\s+/g, ' ').trim();
          if (t) out.push(t.slice(0, 400));
        });
        root.querySelectorAll('[data-valmsg-for]').forEach((el) => {
          const t = (el.textContent || '').replace(/\s+/g, ' ').trim();
          if (t) out.push(t.slice(0, 400));
        });
        return [...new Set(out)].slice(0, 12);
      })
      .catch(() => []);

    const summaryErr = await page
      .locator('.validation-summary-errors li, .validation-summary-valid')
      .allTextContents()
      .catch(() => []);
    const errBlob = [...validationTexts, ...summaryErr.map((t) => t.replace(/\s+/g, ' ').trim()).filter(Boolean)];
    const hasHardError =
      errBlob.length > 0 &&
      errBlob.some((t) =>
        /postcode|post code|address|find an address|invalid|not valid|no match|could not find|unable to find|enter a valid/i.test(
          t,
        ),
      );

    const ddCount = await dd.count().catch(() => 0);
    const possibleAddressesCount = await addrSel.count().catch(() => 0);
    let optionCount = 0;
    let realOptionCount = 0;
    let addrSelVisible = false;
    if (possibleAddressesCount) {
      addrSelVisible = await addrSel.first().isVisible().catch(() => false);
      const opts = addrSel.locator('option');
      optionCount = await opts.count().catch(() => 0);
      for (let i = 0; i < optionCount; i++) {
        const val = (await opts.nth(i).getAttribute('value').catch(() => '')) || '';
        const text = (await opts.nth(i).innerText().catch(() => '')).trim();
        if (!isPlaceholderAddressOption(val, text)) realOptionCount++;
      }
    }

    const manualLinkVis = await page
      .getByRole('link', { name: /enter.*address.*manually|can't find|cannot find your address|address not listed/i })
      .first()
      .isVisible()
      .catch(() => false);
    const manualLineVis = await page
      .locator('#Address_AddressLine1, #AddressLine1, input[id*="AddressLine1" i]')
      .first()
      .isVisible()
      .catch(() => false);

    lastSnapshot = {
      errBlob: errBlob.slice(0, 8),
      ddCount,
      possibleAddressesCount,
      addrSelVisible,
      optionCount,
      realOptionCount,
      manualLinkVis,
      manualLineVis,
    };

    if (hasHardError) {
      return { mode: 'error', details: { ...lastSnapshot, messages: errBlob } };
    }

    const dropdownUsable = possibleAddressesCount > 0 && realOptionCount > 0;

    if (dropdownUsable) {
      return {
        mode: 'dropdown',
        details: { ...lastSnapshot, selectPopulated: realOptionCount > 0 },
      };
    }

    if (manualLinkVis || manualLineVis) {
      return { mode: 'manual', details: { ...lastSnapshot } };
    }

    await sleep(ADDRESS_LOOKUP_POLL_MS);
  }

  return { mode: 'none', details: lastSnapshot };
}

/**
 * Native TransUnion control inside #address-dropdown wrapper — populate & select real PAF options.
 * @returns {Promise<{ resolved: boolean }>}
 */
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

  const sel = page.locator(POSSIBLE_ADDRESSES_SELECT);
  const ready = await waitForPossibleAddressesSelectReady(page);
  if (!ready) {
    logStep(`address: ${POSSIBLE_ADDRESSES_SELECT} not ready (no real options within timeout)`);
    const snap = await collectAddressDropdownSnapshot(page);
    logStep(`address-dropdown snapshot: ${JSON.stringify(snap)}`);
    return { resolved: false };
  }

  const selCount = await sel.count().catch(() => 0);
  logStep(`address: ${POSSIBLE_ADDRESSES_SELECT} exists count=${selCount}`);
  const valueBefore = (await sel.inputValue().catch(() => '')) || '';
  logStep(`address: PossibleAddresses selected value before selection: "${valueBefore}"`);

  const opts = sel.locator('option');
  const n = await opts.count();
  logStep(`address: PossibleAddresses option count=${n}`);
  if (n === 0) {
    const snap = await collectAddressDropdownSnapshot(page);
    logStep(`address-dropdown snapshot: ${JSON.stringify(snap)}`);
    return { resolved: false };
  }

  let bestScore = -1;
  let bestText = '';
  let bestValue = '';

  for (let i = 0; i < n; i++) {
    const opt = opts.nth(i);
    const val = (await opt.getAttribute('value').catch(() => '')) || '';
    const text = (await opt.innerText().catch(() => '')).trim();
    if (isPlaceholderAddressOption(val, text)) continue;
    const sc = scoreAddressOptionText(text, hints);
    logStep(`address candidate: "${text.slice(0, 200)}" value="${val}" score=${sc}`);
    if (sc > bestScore) {
      bestScore = sc;
      bestText = text;
      bestValue = val;
    }
  }

  if (!bestValue || bestScore < ADDRESS_STRONG_MATCH_MIN_SCORE) {
    const snap = await collectAddressDropdownSnapshot(page);
    logStep(`address-dropdown snapshot: ${JSON.stringify(snap)}`);
    logStep(
      `address: no candidate meets strong match threshold (bestScore=${bestScore}, min=${ADDRESS_STRONG_MATCH_MIN_SCORE})`,
    );
    return { resolved: false };
  }

  await sel.selectOption({ value: bestValue });

  const valueAfter = (await sel.inputValue().catch(() => '')) || '';
  logStep(`address select value after selection: "${valueAfter}"`);

  let selectedOptionText = bestText;
  try {
    const checked = sel.locator('option:checked');
    if ((await checked.count()) > 0) {
      selectedOptionText = (await checked.first().innerText()).replace(/\s+/g, ' ').trim();
    }
  } catch {
    /* keep bestText */
  }
  logStep(`address select option text after selection: "${selectedOptionText}"`);

  const hidden = page.locator(POSSIBLE_ADDRESSES_HIDDEN_INPUT);
  let hiddenVal = '';
  if ((await hidden.count().catch(() => 0)) > 0) {
    hiddenVal = (await hidden.inputValue().catch(() => '')) || '';
    logStep(`address hidden input value after selection: "${hiddenVal}"`);
    if (!hiddenVal || hiddenVal === 'NOT_SELECTED' || hiddenVal !== valueAfter) {
      await hidden.evaluate(
        (el, v) => {
          el.value = v;
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
        },
        valueAfter,
      );
      hiddenVal = (await hidden.inputValue().catch(() => '')) || '';
      logStep(`address hidden input value after sync: "${hiddenVal}"`);
    }
  }

  if (!valueAfter || valueAfter === 'NOT_SELECTED') {
    const snap = await collectAddressDropdownSnapshot(page);
    logStep(`address-dropdown snapshot: ${JSON.stringify(snap)}`);
    logStep('address: select value did not leave NOT_SELECTED');
    return { resolved: false };
  }

  logStep(`address selected candidate: "${selectedOptionText}" value="${bestValue}"`);
  logProgress(`Address selected: ${selectedOptionText.slice(0, 120)}`);
  return { resolved: true };
}

/**
 * If TransUnion offers manual address entry, open it and fill from Jinx (TUNE selectors).
 * @returns {Promise<boolean>}
 */
async function tryManualAddressEntry(page, personalData) {
  const lineSel =
    '#Address_AddressLine1, #AddressLine1, input[id*="AddressLine1" i], input[name*="AddressLine1" i]';
  let line1 = page.locator(lineSel).first();

  if (!(await line1.isVisible().catch(() => false))) {
    const manualLink = page
      .getByRole('link', { name: /enter.*address.*manually|can't find|cannot find your address|address not listed/i })
      .first();
    if (await manualLink.isVisible().catch(() => false)) {
      await manualLink.click({ timeout: 8000 });
      await sleep(1000);
    }
    line1 = page.locator(lineSel).first();
  }

  if (!(await line1.isVisible().catch(() => false))) {
    return false;
  }

  const street = String(personalData.address_line_1 || '').trim();
  if (!street) {
    logStep('address: manual path visible but address_line_1 empty — cannot resolve');
    return false;
  }

  await line1.fill(street);

  const town = page.locator('#Address_Town, #Town, input[id*="Town" i]').first();
  const townVal = String(personalData.town || personalData.city || '').trim();
  if (townVal && (await town.isVisible().catch(() => false))) {
    await town.fill(townVal);
  }

  const pcField = page.locator('#Address_Postcode').first();
  const pcVal = String(personalData.postcode || '').trim();
  if (pcVal && (await pcField.isVisible().catch(() => false))) {
    await pcField.fill(pcVal);
  }

  logStep('address: manual entry fields filled (TUNE selectors)');
  return true;
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

  await ensureCookieBannerDismissed(page, 'before-address-lookup');

  // JINX MAPPING: postcode → #Address_Postcode
  const postcodeVal = String(personalData.postcode || '').trim();
  await fillIfPresent(page, 'Postcode #Address_Postcode', async () => {
    await page.locator('#Address_Postcode').fill(postcodeVal);
  });
  logStep(`address: postcode value before find-address: "${postcodeVal}"`);
  logStep(`address target house_number from Jinx: "${String(personalData.house_number ?? '').trim()}"`);

  await logAddressLookupDiagnostics(page, 'before find-address click');

  const pcLoc = page.locator('#Address_Postcode');
  await pcLoc.evaluate((el) => el.dispatchEvent(new Event('input', { bubbles: true })));
  await pcLoc.evaluate((el) => el.dispatchEvent(new Event('change', { bubbles: true })));
  await pcLoc.blur().catch(() => {});
  await pcLoc.press('Tab').catch(() => {});
  await sleep(300);

  const triggerResult = await tryTriggerAddressLookup(page);
  logStep(`address lookup trigger result: ${JSON.stringify(safeSerializeTriggerResult(triggerResult))}`);

  if (!triggerResult.success) {
    const rawDump = await collectRawAddressCandidates(page);
    logStep(`address raw dump: ${JSON.stringify(rawDump)}`);
    throw new Error('address_lookup_not_triggered');
  }

  await logAddressLookupDiagnostics(page, 'after address lookup triggered');

  let addressResolved = false;
  try {
    const ddResult = await selectAddressDropdownMatchingJinx(page, personalData);
    if (ddResult.resolved) {
      addressResolved = true;
      logStep('form: Address PossibleAddresses select (match house/building) — ok');
    } else {
      logStep('address: populated select present but selection/scoring failed — trying manual entry if offered');
      if (await tryManualAddressEntry(page, personalData)) {
        addressResolved = true;
        logStep('form: Address manual entry — ok');
      }
    }
  } catch (e) {
    logStep(`address: selection error (${e?.message || e})`);
  }

  if (!addressResolved) {
    const rawDump = await collectRawAddressCandidates(page);
    for (const c of rawDump.candidatesWithMeta) {
      logStep(`address raw candidate: text="${c.normalized}" firstNumber="${c.firstNumber}"`);
    }
    logStep(`address raw dump: ${JSON.stringify(rawDump)}`);
    throw new Error('address_not_resolved_before_submit');
  }

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
  const docTitle = (await page.title().catch(() => '')).trim();
  if (/email authentication required/i.test(docTitle)) {
    return true;
  }
  const hasInputCode = await page.locator('#InputCode').isVisible().catch(() => false);
  const bodyHead = (await page.locator('body').innerText().catch(() => '')).slice(0, 8000);
  return hasInputCode && /email authentication required|email authentication/i.test(bodyHead);
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

    logStep('post-submit: settle loop (5×3s) — detectJourneyState (no temp-mail until Email Authentication Required)');
    for (let i = 0; i < 5; i++) {
      const st = await detectJourneyState(page);
      logStep(`state: ${st}`);
      if (st === 'unknown') {
        const snapshot = await collectJourneyDebugSnapshot(page);
        logStep(`post-submit unknown snapshot: ${JSON.stringify(snapshot)}`);
        if (await isAboutYouPageStill(page)) {
          logStep('post-submit still on about-you form');
          const aboutSnap = await collectAboutYouValidationSnapshot(page);
          logStep(`post-submit about-you validation snapshot: ${JSON.stringify(aboutSnap)}`);
        }
      }
      if (st === 'negative') {
        if (await exitIfNegativeFailure(page)) {
          return;
        }
      }
      if (st === 'email_auth' || st === 'kba_first' || st === 'kba_second' || st === 'pdf') {
        break;
      }
      if (i < 4) {
        await sleep(3000);
      }
    }

    const stPost = await detectJourneyState(page);
    logStep(`post-submit resolved state: ${stPost}`);
    if (stPost === 'negative') {
      if (await exitIfNegativeFailure(page)) {
        return;
      }
    }
    if (stPost === 'unknown') {
      const snapshot = await collectJourneyDebugSnapshot(page);
      logStep(`post-submit final unknown snapshot: ${JSON.stringify(snapshot)}`);

      if (await isAboutYouPageStill(page)) {
        logStep('post-submit still on about-you form');
        const aboutSnap = await collectAboutYouValidationSnapshot(page);
        logStep(`post-submit about-you validation snapshot: ${JSON.stringify(aboutSnap)}`);
      }

      emitJson({ success: false, error: 'unknown_post_submit_state' });
      throw new Error('unknown_post_submit_state');
    }

    if (stPost === 'email_auth') {
      logStep('stage: email authentication / OTP page (temp-mail code retrieval only here)');
      await handleEmailAuthenticationPage(page, baseUrl, apiKey, tempEmail);
      await sleep(3000);
      if (await exitIfNegativeFailure(page)) {
        return;
      }
    } else if (stPost === 'pdf') {
      await sleep(500);
      if (await exitIfNegativeFailure(page)) {
        return;
      }
    } else if (stPost === 'kba_first' || stPost === 'kba_second') {
      logStep(`journey: ${stPost} — no verification-link email polling`);
      await sleep(1500);
      if (await exitIfNegativeFailure(page)) {
        return;
      }
    }

    const answerTimeout = Number(process.env.CREDIT_CHECK_V2_ANSWER_TIMEOUT_MS) || 45 * 60 * 1000;

    if (stPost !== 'pdf') {
      // Wait up to ~60s for KBA or PDF after post-submit routing
      for (let w = 0; w < 20; w++) {
        if (await exitIfNegativeFailure(page)) {
          return;
        }
        const ws = await detectJourneyState(page);
        logStep(`state: ${ws}`);
        if (ws === 'negative') {
          if (await exitIfNegativeFailure(page)) {
            return;
          }
        }
        if (ws === 'pdf' || (await isPdfOfferVisible(page))) {
          logStep('flow: PDF controls already visible');
          break;
        }
        if (ws === 'kba_first' || ws === 'kba_second' || (await isKbaWizardPage(page))) {
          logProgress('Security questions detected…');
          logStep('flow: KBA wizard detected');
          break;
        }
        if (ws === 'email_auth' || (await isEmailAuthenticationPage(page))) {
          logStep('stage: email authentication / OTP page (wait loop)');
          await handleEmailAuthenticationPage(page, baseUrl, apiKey, tempEmail);
          if (await exitIfNegativeFailure(page)) {
            return;
          }
        }
        await sleep(3000);
      }
    } else {
      logStep('flow: PDF/report page already detected — skipping KBA wait loop');
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
