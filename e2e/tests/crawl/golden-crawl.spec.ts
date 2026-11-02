import { test, expect } from '../support/fixtures';
import { authFile, authRoles, type RoleName } from '../data/test-users';
import { CrawlClient } from '../support/http';
import { allRoutes, assertSafe, bodyRoles } from './routes';
import { hash, normalizeBody } from './normalize';

/**
 * Per role, GET every allowlisted route (no redirects followed) and store:
 *  - summary-<role>.json: status / redirect target / content type / hash of the normalised body, per route
 *  - bodies-<role>.txt (anonymous + admin): the normalised bodies, for readable diffs
 * A rebuilt app is diffed against these goldens (extend normalize.ts, never loosen the routes list).
 */
const roles: (RoleName | 'anonymous')[] = ['anonymous', ...authRoles];

test('crawl allowlist contains only safe routes', () => {
  assertSafe(allRoutes);
  expect(new Set(allRoutes).size).toBe(allRoutes.length);
});

for (const role of roles) {
  test(`crawl as ${role}`, async () => {
    const client = await CrawlClient.create(role === 'anonymous' ? undefined : authFile(role, 'crawl'));
    const summary: Record<string, { status: number; location: string | null; contentType: string | null; bodyHash: string; bytes: number }> = {};
    const bodies: string[] = [];

    for (const route of allRoutes) {
      const r = await client.get(route);
      const body = normalizeBody(r.body);
      summary[route] = {
        status: r.status,
        // Redirect targets embed the host; keep only path + query so the golden is host-independent.
        location: r.location ? r.location.replace(/^https?:\/\/[^/]+/, '') : null,
        contentType: r.contentType,
        bodyHash: hash(body),
        bytes: body.length,
      };
      if ((bodyRoles as readonly string[]).includes(role)) bodies.push(`===== ${route} [${r.status}] =====\n${body}`);
    }
    await client.dispose();

    // Soft: when the summary differs the body comparison below must still run, so its diff shows WHAT changed.
    expect.soft(JSON.stringify(summary, null, 2)).toMatchSnapshot(`summary-${role}.json`);
    if ((bodyRoles as readonly string[]).includes(role)) expect(bodies.join('\n\n')).toMatchSnapshot(`bodies-${role}.txt`);
  });
}
