import { existsSync } from 'node:fs';
import { join, resolve } from 'node:path';

import { test as base, type BrowserContext } from '@playwright/test';

const STUB = resolve(__dirname, '../../harness/stubs/braintree-v2-stub.js');
const INTERNAL_HOST = /^(app|app-rt|keycloak|localhost|127\.0\.0\.1)(:\d+)?$/;

/**
 * Makes browser traffic deterministic and offline-safe:
 *  - analytics are blocked,
 *  - the Braintree drop-in v2 script is replaced by a stub,
 *  - external CSS/fonts are replayed from the recorded HAR (BASELINE_DIR/external-assets.har) when it exists,
 *  - any other external request is aborted (the harness has no internet by design).
 * Routes registered later take precedence, so the catch-all goes first.
 */
export async function installExternalRouting(context: BrowserContext): Promise<void> {
  await context.route((url) => !INTERNAL_HOST.test(url.host), (route) => route.abort());

  const har = join(process.env.BASELINE_DIR ?? '.', 'external-assets.har');
  if (existsSync(har)) {
    await context.routeFromHAR(har, { url: /fonts\.googleapis\.com|fonts\.gstatic\.com|maxcdn\.bootstrapcdn\.com/, notFound: 'abort' });
  }
  await context.route('https://js.braintreegateway.com/js/braintree-2.27.0.min.js', (route) =>
    route.fulfill({ path: STUB, contentType: 'application/javascript' }),
  );
  await context.route(/googletagmanager\.com|cloudflareinsights\.com/, (route) => route.abort());
}

export const test = base.extend({
  context: async ({ context }, use) => {
    await installExternalRouting(context);
    await use(context);
  },
});

export { expect } from '@playwright/test';
