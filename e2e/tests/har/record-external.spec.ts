import { join } from 'node:path';

// Plain Playwright test on purpose: the shared fixtures abort external requests, and this spec needs them.
import { test, expect } from '@playwright/test';

import { EventsIndexPage } from '../pages/events-index.page';
import { GenericPage } from '../pages/generic.page';

/**
 * Records the external stylesheets/fonts the layout pulls from CDNs (Google Fonts, Font Awesome) into
 * BASELINE_DIR/external-assets.har so every later run replays them offline and renders identically.
 * Needs internet: run with RECORD_HAR=1 (uses the egress runner). Re-record only on purpose.
 */
const EXTERNAL = /fonts\.googleapis\.com|fonts\.gstatic\.com|maxcdn\.bootstrapcdn\.com/;

test('record external assets into a HAR', async ({ page, context }) => {
  const har = join(process.env.BASELINE_DIR ?? '.', 'external-assets.har');
  await context.routeFromHAR(har, { update: true, url: EXTERNAL, updateContent: 'embed', updateMode: 'full' });

  const index = new EventsIndexPage(page);
  const generic = new GenericPage(page);

  await index.goto();
  await expect(index.heading).toBeVisible();
  await page.waitForLoadState('networkidle');

  // The error layout has its own copy of the same <link> tags.
  await generic.open('/this-page-does-not-exist');
  await page.waitForLoadState('networkidle');
});
