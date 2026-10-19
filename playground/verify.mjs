// What the Google auditors check, on the published site (or a local serve.mjs):
// banner layout, Consent Mode before and after the choice, WP Consent API, hosts on the network.
//   node verify.mjs <url> [chromium|firefox|webkit] [accept|reject]
import * as pw from 'playwright';

const [url, browserName = 'chromium', action = 'accept'] = process.argv.slice(2);
const browser = await pw[browserName].launch();
const context = await browser.newContext();
const page = await context.newPage();

const hosts = new Set();
const ga = [];
const tags = new Set();
context.on('request', (r) => {
  const u = new URL(r.url());
  if (u.protocol.startsWith('http')) hosts.add(u.host);
  if (/\/g\/collect/.test(u.pathname)) ga.push({ t: Date.now() - t0, gcs: u.searchParams.get('gcs'), tid: u.searchParams.get('tid') });
  const m = r.url().match(/googletagmanager\.com\/gtag\/js\?id=([A-Z0-9-]+)/);
  if (m) tags.add(m[1]);
});
const errors = [];
page.on('pageerror', (e) => errors.push(String(e).slice(0, 200)));

const t0 = Date.now();
await page.goto(url, { waitUntil: 'domcontentloaded' });
// The WordPress iframe sits inside remote.html (Firefox reports it as about:blank).
const wpFrame = () => page.frames().find((f) => /remote\.html/.test(f.url()))?.childFrames()[0];
const accept = 'avacy-banner [data-testid="banner-accept-all"] button';
const reject = 'avacy-banner [data-testid="banner-reject-all"] button';
let wp;
let tBanner = null;
for (const deadline = Date.now() + 180000; Date.now() < deadline; await page.waitForTimeout(250)) {
  wp = wpFrame();
  if (wp && (await wp.locator(accept).first().isVisible().catch(() => false))) {
    tBanner = Date.now() - t0;
    break;
  }
}
const out = { url, browser: browserName, action, bannerMs: tBanner };
if (!tBanner) {
  out.error = 'banner not visible';
} else {
  await page.waitForTimeout(2500);
  out.layout = await wp.evaluate(() => {
    const b = document.querySelector('avacy-banner');
    const env = document.querySelector('[data-cmp-env]');
    return { cmpEnv: env ? env.getAttribute('data-cmp-env') : null, forced: window.__AVACY_FORCE_STANDARD_LAYOUT__ === true, text: (b?.innerText || '').slice(0, 80) };
  });
  out.consentBefore = await wp.evaluate(() => window.wp_has_consent('marketing'));
  out.gaBefore = ga.map((g) => g.gcs);
  const n = ga.length;
  await wp.locator(action === 'accept' ? accept : reject).first().click();
  await page.waitForTimeout(4000);
  out.consentAfter = await wp.evaluate(() => window.wp_has_consent('marketing'));
  await wp.evaluate(() => location.reload());
  await page.waitForTimeout(5000);
  out.gaAfter = ga.slice(n).map((g) => g.gcs);
  out.gaCookies = (await context.cookies()).map((c) => c.name).filter((x) => /^_ga/.test(x));
}
out.tags = [...tags];
out.hosts = [...hosts].sort();
out.pageErrors = errors;
console.log(JSON.stringify(out, null, 2));
await browser.close();
