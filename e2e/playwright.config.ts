import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.BASE_URL ?? 'http://localhost:8000';
// Baselines (screenshots, goldens) live OUTSIDE the repo; see docs/testing.md. Defaults keep plain dev runs working.
const baselineDir = process.env.BASELINE_DIR ?? './test-results/baseline';
const inHarness = !!process.env.DB_HOST;

// The write project resets and mutates the shared database, so it must never overlap the read-only projects. When it is
// selected together with others (or when no project is selected at all) it waits for the selected read-side projects.
// Selected alone (--project=write) it needs only the login setup, so iterating on it does not re-run the whole suite.
const READ_SIDE = ['smoke', 'readonly', 'visual', 'crawl'];
const selectedProjects = process.argv.flatMap((a, i, all) => (a.startsWith('--project=') ? [a.slice('--project='.length)] : a === '--project' ? [all[i + 1]] : []));
const writeWaitsFor = selectedProjects.length === 0 ? READ_SIDE : READ_SIDE.filter((p) => selectedProjects.includes(p));

const desktop = { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 };

export default defineConfig({
  testDir: './tests',
  // Per-harness-project output so concurrent runs against different compose projects never clobber each other.
  outputDir: process.env.PW_OUT_DIR ?? 'test-results',
  globalSetup: inHarness ? './tests/support/global-setup.ts' : undefined,
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: process.env.CI
    ? [['junit', { outputFile: `${process.env.PW_OUT_DIR ?? 'test-results'}/junit.xml` }], ['list']]
    : [['list'], ['html', { open: 'never', outputFolder: process.env.PW_REPORT_DIR ?? 'playwright-report' }]],
  snapshotPathTemplate: `${baselineDir}/snapshots/{projectName}/{testFilePath}/{arg}{ext}`,
  expect: {
    toHaveScreenshot: { animations: 'disabled', caret: 'hide', maxDiffPixelRatio: 0 },
    toMatchSnapshot: { maxDiffPixelRatio: 0 },
  },
  use: {
    baseURL,
    timezoneId: 'America/Chicago',
    locale: 'en-US',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    // UI login per role -> .auth/<role>.json (regenerated every run, never reused across builds).
    { name: 'setup', testMatch: /setup\/.*\.setup\.ts$/, use: desktop },
    // The two original specs.
    { name: 'smoke', testMatch: /tests[\\/](home|login)\.spec\.ts$/, use: desktop },
    // Functional, read-only.
    { name: 'readonly', testMatch: /readonly\/.*\.spec\.ts$/, dependencies: ['setup'], use: desktop },
    // Screenshots (desktop + mobile are chosen per spec). Only runs on Linux so one environment renders baselines.
    { name: 'visual', testMatch: /visual\/.*\.spec\.ts$/, dependencies: ['setup'], workers: 2, use: desktop } as never,
    // Allowlisted GET crawl, stored as goldens.
    { name: 'crawl', testMatch: /crawl\/.*\.spec\.ts$/, dependencies: ['setup'], workers: 1, use: desktop } as never,
    // Writes to the DB; resets per file; serial.
    { name: 'write', testMatch: /write\/.*\.spec\.ts$/, dependencies: ['setup', ...writeWaitsFor], workers: 1, fullyParallel: false, use: desktop } as never,
    // Real-clock twin + Keycloak; only when the `oidc` compose profile is up.
    { name: 'oidc', testMatch: /oidc\/.*\.spec\.ts$/, use: { ...desktop, baseURL: process.env.BASE_URL_RT ?? baseURL } },
    // Records external assets (fonts/CSS CDNs) into BASELINE_DIR/external-assets.har. Needs the egress runner.
    { name: 'har', testMatch: /har\/.*\.spec\.ts$/, use: desktop },
  ],
});
