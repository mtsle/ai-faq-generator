/**
 * ETAP 8.4 (Instrukcje) — zrzuty do instrukcji klienta wtyczki AI News Portal.
 *
 * Robione na dworek.local (przedszkole) jako REALNY przyklad — klient ma zobaczyc
 * dzialajaca strone, nie makiete.
 *
 * ZERO zapisu do bazy: skrypt tylko oglada. Odwiedziny i tak moga odpalic WP-Cron
 * (GOTCHA 101) — dlatego zrzuty robimy jednym przebiegiem, nie po kawalku.
 *
 * URUCHOMIENIE (Git Bash):
 *   node faq-generator/ai-faq-generator/ai-news-portal/instrukcje/narzedzia/klient-screeny.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

const BASE = 'http://dworek.local';
const ROOT = 'c:/Users/matot/Desktop/strona1/faq-generator/ai-faq-generator/ai-news-portal/instrukcje';
const SHOTS = path.join(ROOT, 'zrzuty');
const COOKIE_PHP = path.join(ROOT, 'narzedzia', 'dworek-cookie.php');
const PHP = 'C:/Users/matot/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe';
const PHP_EXT = 'C:/Users/matot/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/ext';
const PW_ROOTS = [
  'c:/Users/matot/.claude/skills/web-screenshot/node_modules',
  'c:/Users/matot/Desktop/strona1/.claude/skills/web-screenshot/node_modules',
];
const MSPW = 'C:/Users/matot/AppData/Local/ms-playwright';
const VIEW = { width: 1440, height: 900 };
const DPR = 2;

const meta = {};
const bledy = [];
const ok = (m) => console.log('  OK   ' + m);
const fail = (m) => { console.log('  FAIL ' + m); bledy.push(m); };
const fatal = (m) => { console.error('BLAD: ' + m); process.exit(2); };

function findPlaywright() {
  for (const root of PW_ROOTS) for (const pkg of ['playwright', 'playwright-core']) {
    const e = path.join(root, pkg, 'index.mjs');
    if (fs.existsSync(e)) return e;
  }
  return null;
}
function findChromium() {
  if (!fs.existsSync(MSPW)) return null;
  const dirs = fs.readdirSync(MSPW).filter((d) => /^chromium-\d+$/.test(d))
    .sort((a, b) => parseInt(b.split('-')[1], 10) - parseInt(a.split('-')[1], 10));
  for (const d of dirs) for (const s of ['chrome-win64', 'chrome-win']) {
    const e = path.join(MSPW, d, s, 'chrome.exe');
    if (fs.existsSync(e)) return e;
  }
  return null;
}
function pobierzCiasteczka() {
  const out = execFileSync(PHP, ['-d', `extension_dir=${PHP_EXT}`, '-d', 'extension=mysqli',
    '-d', 'extension=mbstring', '-d', 'extension=openssl', COOKIE_PHP], { encoding: 'utf8' });
  const m = out.match(/---AINP-COOKIES-BEGIN---\s*([\s\S]*?)\s*---AINP-COOKIES-END---/);
  if (!m) fatal('pomocnik PHP nie zwrocil ciasteczek');
  return JSON.parse(m[1]);
}

/** Zrzut + wspolrzedne elementow do obrysowania (w pikselach obrazu, czyli razy DPR). */
async function shot(page, nazwa, cele = [], opcje = {}) {
  const plik = path.join(SHOTS, nazwa + '.png');
  const fullPage = !!opcje.fullPage;
  const scroll = fullPage ? await page.evaluate(() => ({ x: scrollX, y: scrollY })) : { x: 0, y: 0 };
  const cele_out = {};
  for (const c of cele) {
    const loc = page.locator(c.sel).first();
    if ((await loc.count()) === 0) { cele_out[c.label] = null; fail(`${nazwa}: brak celu ${c.label} (${c.sel})`); continue; }
    const b = await loc.boundingBox({ timeout: 4000 });
    cele_out[c.label] = b ? {
      x: Math.round((b.x + scroll.x) * DPR), y: Math.round((b.y + scroll.y) * DPR),
      w: Math.round(b.width * DPR), h: Math.round(b.height * DPR),
    } : null;
  }
  await page.screenshot({ path: plik, fullPage });

  /*
   * STRAZNIK: cel lezacy PONIZEJ zrzutu widokowego nie jest bledem Playwrighta
   * — `boundingBox()` zwraca wspolrzedne wzgledem dokumentu, wiec kadrowanie
   * dostaje liczby wskazujace poza obraz i tnie pusty pas. Bez tej kontroli
   * wychodzi to dopiero z gotowego PDF-u.
   */
  const wymiar = await page.evaluate(() => ({ w: innerWidth, h: innerHeight }));
  if (!fullPage) {
    for (const [label, c] of Object.entries(cele_out)) {
      if (c && (c.y + c.h) / DPR > wymiar.h) {
        fail(`${nazwa}: cel ${label} lezy pod krawedzia ekranu — potrzebny fullPage`);
      }
    }
  }

  meta[nazwa] = { plik, cele: cele_out };
  ok(nazwa);
}

const pwEntry = findPlaywright();
if (!pwEntry) fatal('brak modulu playwright');
const { chromium } = await import(pathToFileURL(pwEntry).href);

fs.mkdirSync(SHOTS, { recursive: true });
const sesja = pobierzCiasteczka();
console.log(`administrator #${sesja.user_id} na ${sesja.host}`);

const browser = await chromium.launch({ executablePath: findChromium() });

/* --- A. WIDOK GOSCIA: bez ciasteczek, inaczej WordPress dokłada pasek admina --- */
const gosc = await browser.newContext({ viewport: VIEW, deviceScaleFactor: DPR });
const pg = await gosc.newPage();

await pg.goto(`${BASE}/centrum-wiedzy/`, { waitUntil: 'networkidle' });
await pg.evaluate(() => Promise.all([...document.images].filter((i) => !i.complete)
  .map((i) => new Promise((r) => { i.onload = i.onerror = r; }))));
await shot(pg, '01-centrum-wiedzy-archiwum', [], { fullPage: false });

const pierwsza = pg.locator('a').filter({ hasText: /.+/ });
await shot(pg, '02-centrum-wiedzy-karty', [{ sel: '.ainp-card, article, li', label: 'karta' }]);

// Pierwszy artykul z archiwum — adres bierzemy z DOM-u, zeby zrzut nie zalezal
// od tego, co akurat opublikowal portal.
const linkArtykulu = await pg.evaluate(() => {
  const a = [...document.querySelectorAll('a[href*="/centrum-wiedzy/"]')]
    .find((x) => !/\/kategoria\//.test(x.getAttribute('href')) && x.getAttribute('href') !== location.pathname);
  return a ? a.href : null;
});
if (!linkArtykulu) fail('brak odnosnika do artykulu w archiwum');
else {
  await pg.goto(linkArtykulu, { waitUntil: 'networkidle' });
  await shot(pg, '20-artykul');
}

await pg.goto(`${BASE}/centrum-wiedzy/kategoria/pielegnacja/`, { waitUntil: 'networkidle' });
await shot(pg, '21-kategoria');

await pg.goto(`${BASE}/centrum-wiedzy/?ainp_s=sier%C5%9B%C4%87`, { waitUntil: 'networkidle' });
await shot(pg, '22-wyszukiwanie');

await gosc.close();

/* --- B. KOKPIT: z ciasteczkami administratora --- */
const admin = await browser.newContext({ viewport: VIEW, deviceScaleFactor: DPR });
await admin.addCookies(sesja.cookies);
const pa = await admin.newPage();

await pa.goto(`${BASE}/wp-admin/edit.php?post_type=ainp_article`, { waitUntil: 'networkidle' });
await shot(pa, '03-lista-artykulow', [{ sel: '#wpbody-content .wrap > h1', label: 'naglowek' }]);

// Zakladka „Zobacz wszystko" wybierana ADRESEM — pozycja archiwum jest tylko tam,
// a klikanie zakladki wymagaloby wczesniej rozwiniecia akordeonu.
await pa.goto(`${BASE}/wp-admin/nav-menus.php?ainp_article-tab=all`, { waitUntil: 'networkidle' });
// Metabox typu wpisu to akordeon ZWINIETY domyslnie (a bywa i ukryty w Opcjach ekranu).
const trigger = pa.locator('#add-post-type-ainp_article .accordion-trigger').first();
if (!(await trigger.count())) fatal('04: brak metaboksu „Artykuly" — sprawdz Opcje ekranu');
await trigger.click();
await pa.waitForTimeout(500);
// Pozycja archiwum stoi PIERWSZA w panelu i ma typ `post_type_archive`;
// jej etykieta to `labels.archives` z rejestracji CPT, czyli „Centrum Wiedzy".
const pozycja = pa.locator('#ainp_article-all li').first();
const etykieta = (await pozycja.innerText()).trim();
console.log(`  pozycja archiwum w metaboksie: "${etykieta}"`);
await pa.locator('#add-post-type-ainp_article').scrollIntoViewIfNeeded();
await pa.waitForTimeout(200);
await shot(pa, '04-menu-metabox', [
  { sel: '#add-post-type-ainp_article', label: 'metabox' },
  { sel: '#ainp_article-all li:first-child', label: 'pozycja_archiwum' },
], { fullPage: true });


/* --- C. INSTALACJA (ekrany, ktore klient widzi raz) --- */
await pa.goto(`${BASE}/wp-admin/plugin-install.php?tab=upload`, { waitUntil: 'networkidle' });
await shot(pa, '10-wyslij-wtyczke', [
  { sel: '#pluginzip', label: 'pole_pliku' },
  { sel: '#install-plugin-submit', label: 'przycisk_zainstaluj' },
]);

// Wiersz WLASNIE tej wtyczki, nie calej listy: klient ma rozpoznac jeden wiersz.
await pa.goto(`${BASE}/wp-admin/plugins.php`, { waitUntil: 'networkidle' });
await shot(pa, '11-lista-wtyczek', [
  { sel: 'tr[data-slug="ai-news-portal"], tr#ai-news-portal', label: 'wiersz' },
], { fullPage: true });

/* --- D. PANEL WTYCZKI --- */
await pa.goto(`${BASE}/wp-admin/admin.php?page=ainp-items`, { waitUntil: 'networkidle' });
await shot(pa, '12-panel-materialy', [{ sel: '#wpbody-content .wrap > h1', label: 'naglowek' }], { fullPage: true });

await pa.goto(`${BASE}/wp-admin/admin.php?page=ainp-settings`, { waitUntil: 'networkidle' });
await shot(pa, '13-ustawienia-klucz', [{ sel: '#ainp_key', label: 'pole' }], { fullPage: true });
await shot(pa, '14-ustawienia-zrodla', [{ sel: '#ainp_sources', label: 'pole' }]);
await shot(pa, '15-ustawienia-kategorie', [{ sel: '#ainp_categories', label: 'pole' }]);
await shot(pa, '16-ustawienia-slowa', [{ sel: '#ainp_required_words', label: 'pole' }], { fullPage: true });
await shot(pa, '17-ustawienia-limit', [{ sel: '#ainp_daily_cap', label: 'pole' }], { fullPage: true });

await admin.close();
await browser.close();

fs.writeFileSync(path.join(SHOTS, 'meta.json'), JSON.stringify(meta, null, 2), 'utf8');
console.log(`\nzrzutow: ${Object.keys(meta).length}, meta.json zapisany`);
if (bledy.length) { console.log(`OSTRZEZENIA: ${bledy.length}`); process.exit(1); }
