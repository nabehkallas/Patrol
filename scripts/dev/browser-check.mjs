// Opens pages of the local app in a headless browser, logged in, in Arabic, at each width, and
// reports what a layout check needs: page overflow (horizontal scroll), each table's own scroll,
// headers that wrap onto two lines, and page errors. Saves a full-page screenshot of each.
//
//   node scripts/dev/browser-check.mjs /pump-counters
//   node scripts/dev/browser-check.mjs "/pump-counters?entry_date=2026-10-16" /cash-box --widths 1366,1920,2560
//   node scripts/dev/browser-check.mjs /shop --out ../shots --locale en
//
// Needs the local server (php artisan serve --port=8001). Logs in as the local test station's
// admin (PATROL_EMAIL / PATROL_PASSWORD to use another account; PATROL_URL for another server).
// The browser is the Playwright headless Chromium already installed (PW_EXE to point elsewhere).
// Exits 1 when a page overflows horizontally or throws an error.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { chromium } from 'playwright-core';

const args = process.argv.slice(2);
const option = (name, fallback) => {
    const i = args.indexOf(`--${name}`);

    return i >= 0 ? args.splice(i, 2)[1] : fallback;
};
const widths = option('widths', '1366').split(',').map(Number);
const out = option('out', path.join(os.tmpdir(), 'patrol-shots'));
const locale = option('locale', 'ar');
// Git Bash turns "/cash-box" into "C:/Program Files/Git/cash-box": take the path back out. A
// path without its leading slash works too.
const pages = (args.length > 0 ? args : ['/']).map((url) => {
    const converted = url.match(/^[A-Za-z]:[\\/].*?[\\/]Git([\\/].*)$/);
    const clean = converted ? converted[1].replace(/\\/g, '/') : url;

    return clean.startsWith('/') ? clean : `/${clean}`;
});
const base = process.env.PATROL_URL ?? 'http://127.0.0.1:8001';
// The local test station's seeded admin -- a local-only account.
const email = process.env.PATROL_EMAIL ?? 'dev@local.test';
const password = process.env.PATROL_PASSWORD ?? 'password';

function findBrowser() {
    if (process.env.PW_EXE) {
        return process.env.PW_EXE;
    }

    const root = path.join(
        process.env.LOCALAPPDATA ?? path.join(os.homedir(), '.cache'),
        'ms-playwright',
    );
    const shells = fs.existsSync(root)
        ? fs
              .readdirSync(root)
              .filter((d) => d.startsWith('chromium_headless_shell-'))
              .sort()
        : [];

    for (const dir of shells.reverse()) {
        for (const candidate of [
            'chrome-headless-shell-win64/chrome-headless-shell.exe',
            'chrome-headless-shell-linux64/chrome-headless-shell',
        ]) {
            const file = path.join(root, dir, candidate);

            if (fs.existsSync(file)) {
                return file;
            }
        }
    }

    throw new Error(`No headless Chromium under ${root}: set PW_EXE.`);
}

try {
    await fetch(`${base}/login`);
} catch {
    console.error(
        `The app isn't running at ${base}: start it with "php artisan serve --port=8001".`,
    );
    process.exit(2);
}

fs.mkdirSync(out, { recursive: true });
const browser = await chromium.launch({ executablePath: findBrowser() });
let failed = false;

for (const width of widths) {
    const context = await browser.newContext({
        viewport: { width, height: 1000 },
    });
    await context.addInitScript((lang) => {
        localStorage.setItem('locale', lang);
        document.cookie = `locale=${lang};path=/`;
    }, locale);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto(`${base}/login`);
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');
    // Logged in once the app has left the login page (moving on earlier sends the first page
    // back to /login).
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), {
        timeout: 15000,
    });

    for (const url of pages) {
        errors.length = 0;
        await page.goto(base + url, { waitUntil: 'networkidle' });
        // Measure once the page has settled: the same size and number of tables twice in a row
        // (the first visit can still be rendering after the network goes quiet).
        let last = '';

        for (let i = 0; i < 20; i++) {
            await page.waitForTimeout(300);
            const now = await page.evaluate(
                () =>
                    `${document.querySelectorAll('table').length}:${document.body.scrollHeight}`,
            );

            if (now === last) {
                break;
            }

            last = now;
        }

        const report = await page.evaluate(() => {
            const lines = (el) => {
                const range = document.createRange();
                range.selectNodeContents(el);

                return new Set(
                    [...range.getClientRects()].map((r) => Math.round(r.top)),
                ).size;
            };

            return {
                overflow: document.documentElement.scrollWidth - innerWidth,
                tables: [...document.querySelectorAll('table')].map((table) => {
                    const box =
                        table.closest('.table-stack, .overflow-x-auto') ??
                        table.parentElement;

                    return {
                        scroll: box.scrollWidth - box.clientWidth,
                        wraps: [...table.querySelectorAll('thead th')]
                            .filter((th) => lines(th) > 1)
                            .map((th) => th.textContent.trim()),
                    };
                }),
            };
        });

        const name = `${url.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home'}-${width}.png`;
        await page.screenshot({ path: path.join(out, name), fullPage: true });

        // scrollWidth leaves out the vertical scrollbar, innerWidth includes it: a page that fits
        // reads about -15, so anything above 0 is a real horizontal scroll.
        const overflows = report.overflow > 0;
        // Ending up elsewhere (the login page, an error) means this page wasn't checked at all.
        const landed = new URL(page.url()).pathname;
        const redirected = landed !== new URL(base + url).pathname;
        failed ||= overflows || errors.length > 0 || redirected;
        console.log(
            `${width}px ${url}${redirected ? ` | NOT CHECKED: ended up on ${landed}` : ''}`,
        );
        console.log(
            `  page: ${overflows ? `OVERFLOWS by ${report.overflow}px` : 'no horizontal scroll'}${errors.length ? ` | ERRORS: ${errors.join(' / ')}` : ''}`,
        );
        report.tables.forEach((table, i) => {
            console.log(
                `  table ${i + 1}: ${table.scroll > 0 ? `scrolls ${table.scroll}px` : 'fits'}${table.wraps.length ? ` | headers on 2+ lines: ${table.wraps.join(', ')}` : ''}`,
            );
        });
        console.log(`  screenshot: ${path.join(out, name)}`);
    }

    await context.close();
}

await browser.close();
process.exit(failed ? 1 : 0);
