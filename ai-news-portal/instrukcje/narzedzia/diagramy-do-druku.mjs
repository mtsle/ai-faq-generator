/**
 * Diagramy Draw.io -> PNG gotowe do druku A4.
 *
 * Po co: schematy sa szerokie (ok. 2:1). Wstawione w kolumne tekstu na A4 maja
 * font ok. 4 px — nieczytelny. Renderujemy je wiec w wysokiej rozdzielczosci
 * i w dokumencie ida na STRONE POZIOMA (`@page`), nie obracane — obrot obrazka
 *
 * URUCHOMIENIE:  node faq-generator/ai-faq-generator/ai-news-portal/instrukcje/narzedzia/diagramy-do-druku.mjs
 * Wynik:         instrukcje/schematy/druk/*.png
 */

import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const SCHEMATY = 'c:/Users/matot/Desktop/strona1/faq-generator/ai-faq-generator/ai-news-portal/instrukcje/schematy';
const DRUK = path.join(SCHEMATY, 'druk');
const MSPW = 'C:/Users/matot/AppData/Local/ms-playwright';
const SZEROKOSC = 2600;   // renderujemy grubo powyzej potrzeb, zeby druk byl ostry

function findChromium() {
  const dirs = fs.readdirSync(MSPW).filter((d) => /^chromium-\d+$/.test(d))
    .sort((a, b) => parseInt(b.split('-')[1], 10) - parseInt(a.split('-')[1], 10));
  for (const d of dirs) for (const s of ['chrome-win64', 'chrome-win']) {
    const e = path.join(MSPW, d, s, 'chrome.exe');
    if (fs.existsSync(e)) return e;
  }
  return null;
}

const { chromium } = await import(
  pathToFileURL('c:/Users/matot/.claude/skills/web-screenshot/node_modules/playwright/index.mjs').href
);

fs.mkdirSync(DRUK, { recursive: true });
const browser = await chromium.launch({ executablePath: findChromium() });
// Okno wyzsze niz najwyzszy schemat: `elementHandle.screenshot()` probuje
// najpierw przewinac element do widoku i przy schemacie wyzszym od okna
// potrafi utknac na „waiting for element to be stable".
const page = await browser.newPage({ viewport: { width: 1600, height: 2400 }, deviceScaleFactor: 2 });

const pliki = fs.readdirSync(SCHEMATY).filter((f) => f.endsWith('.svg'));
const tmp = path.join(DRUK, '_tmp.html');

for (const f of pliki) {
  const nazwa = f.replace(/\.svg$/, '');
  const url = pathToFileURL(path.join(SCHEMATY, f)).href;
  fs.writeFileSync(tmp, `<body style="margin:0;background:#fff">
    <img id="d" src="${url}" style="width:${SZEROKOSC}px;display:block">`);
  await page.goto(pathToFileURL(tmp).href, { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  const img = page.locator('#d');
  const cel = path.join(DRUK, nazwa + '.png');
  await img.screenshot({ path: cel, timeout: 20000 });
  const b = await img.boundingBox();
  console.log(`  ${nazwa.padEnd(30)} ${Math.round(b.width)}x${Math.round(b.height)}`);
}

fs.unlinkSync(tmp);
await browser.close();
console.log(`\nPNG do druku: ${DRUK}`);
