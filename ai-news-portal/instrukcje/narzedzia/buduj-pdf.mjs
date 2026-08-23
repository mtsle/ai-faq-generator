/**
 * Konwerter HTML -> PDF (Chromium przez Playwright) dla wtyczki AI News Portal.
 *
 * ROZNICA WOBEC WTYCZKI 1: numeru wersji i licencji NIE ma w zrodle HTML.
 * Skrypt czyta je z naglowka `ai-news-portal.php` i podstawia za znaczniki
 * `{{WERSJA}}` / `{{LICENCJA}}`. Przy wtyczce 1 wersja byla wpisana recznie
 * w kazdym z pieciu dokumentow i po wydaniu v1.0.0 wszystkie piec PDF-ow
 * klamalo, ze to 0.33.0 — tego bledu nie powtarzamy.
 *
 * Uzycie:  node zasoby/skrypty/instrukcje/buduj-pdf.mjs <plik.html> <plik.pdf> ["Tytul stopki"]
 *
 * Stopka zawiera numer strony ("Strona X z Y") — WordPress-owy odbiorca ma sie
 * nie pogubic w wydruku.
 */

import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [, , wejscie, wyjscie, tytulStopki = ''] = process.argv;
if (!wejscie || !wyjscie) {
  console.error('Uzycie: node buduj-pdf.mjs <plik.html> <plik.pdf> ["Tytul stopki"]');
  process.exit(2);
}
if (!fs.existsSync(wejscie)) { console.error('BLAD: brak pliku ' + wejscie); process.exit(2); }

const PLUGIN = 'c:/Users/matot/Desktop/strona1/faq-generator/ai-faq-generator/ai-news-portal/ai-news-portal.php';

/** Czyta pole z naglowka wtyczki. Brak pola = blad, nie cicha pusta wartosc. */
function zNaglowka(pole) {
  const naglowek = fs.readFileSync(PLUGIN, 'utf8').slice(0, 4096);
  const m = naglowek.match(new RegExp(String.raw`^\s*\*\s*${pole}:\s*(.+?)\s*$`, 'm'));
  if (!m) { console.error(`BLAD: brak pola "${pole}" w naglowku ${PLUGIN}`); process.exit(2); }
  return m[1];
}

const PODSTAWIENIA = {
  '{{WERSJA}}': zNaglowka('Version'),
  '{{LICENCJA}}': zNaglowka('License'),
};

// Plik do renderowania lezy OBOK zrodla, inaczej `styl.css` i sciezki `../../`
// do zrzutow przestaja sie zgadzac.
let zrodlo = fs.readFileSync(wejscie, 'utf8');
for (const [znacznik, wartosc] of Object.entries(PODSTAWIENIA)) {
  if (zrodlo.includes(znacznik)) zrodlo = zrodlo.split(znacznik).join(wartosc);
}
const zostaly = zrodlo.match(/\{\{[A-Z_]+\}\}/g);
if (zostaly) { console.error('BLAD: niepodstawione znaczniki: ' + [...new Set(zostaly)].join(', ')); process.exit(2); }
const doRenderu = wejscie.replace(/\.html$/, '.__render.html');
fs.writeFileSync(doRenderu, zrodlo, 'utf8');
console.log(`podstawiono: wersja ${PODSTAWIENIA['{{WERSJA}}']}, licencja ${PODSTAWIENIA['{{LICENCJA}}']}`);

const PW_ROOTS = [
  'c:/Users/matot/.claude/skills/web-screenshot/node_modules',
  'c:/Users/matot/Desktop/strona1/.claude/skills/web-screenshot/node_modules',
];
const MSPW = 'C:/Users/matot/AppData/Local/ms-playwright';

function findPlaywright() {
  for (const root of PW_ROOTS) for (const pkg of ['playwright', 'playwright-core']) {
    const e = path.join(root, pkg, 'index.mjs');
    if (fs.existsSync(e)) return e;
  }
  return null;
}
function findChromium() {
  const dirs = fs.readdirSync(MSPW).filter((d) => /^chromium-\d+$/.test(d))
    .sort((a, b) => parseInt(b.split('-')[1], 10) - parseInt(a.split('-')[1], 10));
  for (const d of dirs) for (const s of ['chrome-win64', 'chrome-win']) {
    const e = path.join(MSPW, d, s, 'chrome.exe');
    if (fs.existsSync(e)) return e;
  }
  return null;
}

const pwEntry = findPlaywright();
if (!pwEntry) { console.error('BLAD: brak modulu playwright'); process.exit(2); }
const { chromium } = await import(pathToFileURL(pwEntry).href);

const browser = await chromium.launch({ executablePath: findChromium() });
const page = await browser.newPage();

const braki = [];
page.on('requestfailed', (r) => braki.push(r.url()));

await page.goto(pathToFileURL(path.resolve(doRenderu)).href, { waitUntil: 'networkidle' });
// Obrazy musza byc naprawde wczytane, inaczej PDF ma puste ramki.
await page.evaluate(() => Promise.all(
  [...document.images].filter((i) => !i.complete).map((i) => new Promise((res) => { i.onload = i.onerror = res; }))
));

const zepsuteObrazki = await page.evaluate(() =>
  [...document.images].filter((i) => !i.naturalWidth).map((i) => i.getAttribute('src'))
);

fs.mkdirSync(path.dirname(path.resolve(wyjscie)), { recursive: true });
await page.pdf({
  path: path.resolve(wyjscie),
  format: 'A4',
  printBackground: true,
  displayHeaderFooter: true,
  headerTemplate: '<div></div>',
  footerTemplate: `
    <div style="width:100%;font-size:8pt;color:#5b6472;padding:0 16mm;
                font-family:'Segoe UI',Arial,sans-serif;display:flex;justify-content:space-between;">
      <span>${tytulStopki.replace(/</g, '')}</span>
      <span>Strona <span class="pageNumber"></span> z <span class="totalPages"></span></span>
    </div>`,
  margin: { top: '18mm', bottom: '20mm', left: '16mm', right: '16mm' },
});

await browser.close();
fs.unlinkSync(doRenderu);

const rozmiar = fs.statSync(path.resolve(wyjscie)).size;
console.log(`PDF: ${wyjscie}  (${(rozmiar / 1024).toFixed(1)} KB)`);
if (zepsuteObrazki.length) {
  console.log('UWAGA — obrazki, ktore sie nie wczytaly:');
  zepsuteObrazki.forEach((s) => console.log('  ' + s));
  process.exit(1);
}
if (braki.length) { console.log('nieudane zadania: ' + braki.length); braki.slice(0, 5).forEach((u) => console.log('  ' + u)); }
console.log('wszystkie obrazki wczytane poprawnie');
