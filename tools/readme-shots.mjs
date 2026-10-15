// README screenshots from a local Flarum on the default theme.
//
//   MARQUEE_URL=http://localhost:8099 MARQUEE_USER=admin MARQUEE_PASSWORD=... \
//   NODE_PATH=/path/to/node_modules node tools/readme-shots.mjs
//
// Needs puppeteer-core and Google Chrome. Writes .github/screenshots/.
import puppeteer from 'puppeteer-core';
import { mkdirSync } from 'node:fs';

const BASE = process.env.MARQUEE_URL || 'http://localhost:8099';
const OUT = new URL('../.github/screenshots/', import.meta.url).pathname;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
mkdirSync(OUT, { recursive: true });

const browser = await puppeteer.launch({
  executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'new',
  args: ['--hide-scrollbars', '--force-color-profile=srgb'],
});

const page = await browser.newPage();
await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]);
// The welcome banner is dismissed per browser; start with it hidden.
await page.evaluateOnNewDocument(() => localStorage.setItem('welcomeHidden', 'true'));

const desktop = () => page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });

/** Wait until every image on the page, and every CSS background, has loaded. */
async function settle(extra = 600) {
  await page.waitForNetworkIdle({ idleTime: 500, timeout: 20000 }).catch(() => {});
  await page.evaluate(async () => {
    const urls = [...document.querySelectorAll('*')]
      .map((el) => getComputedStyle(el).backgroundImage)
      .filter((bg) => bg.startsWith('url('))
      .map((bg) => bg.slice(5, -2));
    await Promise.all([
      ...[...document.images].filter((img) => !img.complete).map((img) => new Promise((r) => (img.onload = img.onerror = r))),
      ...urls.map((src) => new Promise((r) => Object.assign(new Image(), { onload: r, onerror: r, src }))),
    ]);
  });
  await sleep(extra);
}

async function go(path) {
  await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => window.app && app.forum, { timeout: 20000 });
  await settle();
}

async function shot(name, clip) {
  await page.screenshot({ path: OUT + name, clip, ...(name.endsWith('.jpg') ? { type: 'jpeg', quality: 85 } : {}) });
  console.log('saved', name);
}

await desktop();

// Logged out: what visitors see.
await go('/');
await shot('discussion-list.png');

await go('/d/13');
await shot('movie-hero.jpg');

await go('/d/12');
await shot('episode-hero.jpg');

await page.click('.MarqueeSeasons-episode.current');
await page.waitForSelector('.MarqueeEpisodeModal');
await settle(900);
await shot('episode-details.jpg');
await page.keyboard.press('Escape');

await go('/d/16');
await shot('game-hero.jpg');

await go('/d/10');
await page.click('.MarqueeSeasons-season');
await page.waitForSelector('.MarqueeSeasons-episodes');
await settle();
await page.evaluate(() => document.querySelector('.MarqueeSeasons').scrollIntoView({ block: 'center' }));
await sleep(400);
await shot('season-browser.jpg');

// Logged in: the composer and the admin page.
await go('/');
await page.evaluate((u, p) => app.session.login({ identification: u, password: p, remember: false }), process.env.MARQUEE_USER, process.env.MARQUEE_PASSWORD);
await go('/');
await page.evaluate(async () => {
  await app.composer.load(() => flarum.reg.asyncModuleImport('core', 'forum/components/DiscussionComposer').then((m) => ({ default: m.default ?? m })), { user: app.session.user }).catch(() => null);
});
// Fall back to the real button if the direct load is unavailable.
if (!(await page.$('.DiscussionComposer-title'))) await page.click('.IndexPage-newDiscussion');
await page.waitForSelector('.DiscussionComposer-title');
await sleep(800);
// Close the tag picker that opens on its own and choose Movies directly.
await page.evaluate(() => {
  app.modal.close();
  app.composer.fields.tags = [app.store.all('tags').find((t) => t.slug() === 'movies')];
  app.composer.show();
  m.redraw.sync();
});
await sleep(600);
await page.click('.DiscussionComposer-title');
await page.keyboard.type('dune', { delay: 60 });
await page.waitForSelector('.Marquee-result', { timeout: 15000 });
await settle(800);
await shot('composer-search.png');

await page.evaluate(() => {
  app.composer.fields.title('');
  app.composer.fields.marquee = null;
  app.composer.hide();
});
await go('/admin#/extension/ernestdefoe-marquee');
await page.waitForSelector('#marquee-tmdb-token');
await settle();
// Never publish real credentials: show placeholders on screen only (nothing is saved).
await page.evaluate(() => {
  document.getElementById('marquee-igdb-id').value = 'your-twitch-client-id';
});
await shot('admin-settings.png');

// Phone.
await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true });
await go('/d/12');
await shot('mobile-episode.jpg');

await browser.close();
