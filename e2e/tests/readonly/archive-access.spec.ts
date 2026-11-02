import { test, expect } from '../support/fixtures';
import { authFile } from '../data/test-users';
import { CATALOG } from '../../fixtures/synthetic/catalog';
import { ArchivePage } from '../pages/archive.page';
import { GenericPage } from '../pages/generic.page';

test.describe('events archive (admin)', () => {
  test.use({ storageState: authFile('admin') });

  test('lists all events regardless of status, 50 per page', async ({ page }) => {
    const archive = new ArchivePage(page);
    await archive.goto();
    const names = await archive.names();
    expect(names.length).toBeGreaterThan(0);
    expect(names.length).toBeLessThanOrEqual(50);
    // Rejected / cancelled / pending events are visible to admins in the archive.
    const all = names.join('\n');
    expect(all).toContain(CATALOG.events.rejected.name);
    expect(all).toContain(CATALOG.events.cancelled.name);
    expect(all).toContain(CATALOG.events.pendingFresh.name);
  });

  test('date-range filter narrows the list', async ({ page }) => {
    const archive = new ArchivePage(page);
    await archive.goto('start_date=2031-03-01&end_date=2031-03-31');
    const names = (await archive.names()).join('\n');
    expect(names).toContain(CATALOG.events.dstSpringForward.name);
    expect(names).not.toContain(CATALOG.events.freeOpen.name);
  });

  test('archive list (golden)', async ({ page }) => {
    const archive = new ArchivePage(page);
    await archive.goto();
    expect((await archive.names()).join('\n')).toMatchSnapshot('archive-page1.txt');
  });
});

test.describe('access control and error pages', () => {
  test('anonymous visitors are sent to the login form for member-only pages', async ({ page }) => {
    const g = new GenericPage(page);
    for (const path of ['/events/all', '/events/submitted', '/events/attending', '/events/pending', '/categories', '/configurations']) {
      await g.open(path);
      expect(g.pathAndQuery, path).toBe(`/users/login?redirect=${encodeURIComponent(path)}`);
    }
  });

  test('public pages stay public', async ({ page }) => {
    const g = new GenericPage(page);
    for (const path of ['/', '/events/calendar', `/events/view/${CATALOG.events.freeOpen.id}`, `/registrations/event/${CATALOG.events.freeOpen.id}`]) {
      const res = await g.open(path);
      expect(res?.status(), path).toBe(200);
      expect(g.pathAndQuery, path).toBe(path);
    }
  });

  test('unknown URL returns the styled 404 page', async ({ page }) => {
    const g = new GenericPage(page);
    const res = await g.open('/this-page-does-not-exist');
    expect(res?.status()).toBe(404);
    await expect(page).toHaveTitle(/Error/);
  });

  test.describe('plain member on admin pages', () => {
    // Own session: these requests queue "not authorized" flash messages (see authFile in test-users.ts).
    test.use({ storageState: authFile('member', 'denied') });

    test('admin index pages are not available (golden of where each lands)', async ({ page }) => {
      const g = new GenericPage(page);
      const landed: Record<string, { status: number | undefined; at: string }> = {};
      for (const path of ['/events/all', '/events/pending', '/categories', '/committees', '/contacts', '/prerequisites', '/rooms', '/tools', '/configurations']) {
        const res = await g.open(path);
        landed[path] = { status: res?.status(), at: g.pathAndQuery };
      }
      expect(JSON.stringify(landed, null, 2)).toMatchSnapshot('member-admin-pages.json');
    });
  });
});

test.describe('admin index pages render (admin)', () => {
  test.use({ storageState: authFile('admin') });

  const pages: { path: string; minRows: number }[] = [
    { path: '/categories', minRows: 60 },
    { path: '/committees', minRows: 15 },
    { path: '/prerequisites', minRows: 3 },
    { path: '/rooms', minRows: 20 },
    { path: '/tools', minRows: 40 },
    { path: '/configurations', minRows: 6 },
    { path: '/contacts', minRows: 4 },
  ];
  for (const p of pages) {
    test(`${p.path} lists rows`, async ({ page }) => {
      const g = new GenericPage(page);
      const res = await g.open(p.path);
      expect(res?.status()).toBe(200);
      expect(await g.tableRows.count()).toBeGreaterThanOrEqual(p.minRows);
    });
  }

  test('configuration values match the seeded business rules', async ({ page }) => {
    const g = new GenericPage(page);
    await g.open('/configurations');
    const text = await g.text();
    for (const expected of ['Automatic Approval Time', 'Honoraria Approval Time', 'Maximum Booking Lead Time', 'Role Call Cutoff']) {
      expect(text).toContain(expected);
    }
  });
});
