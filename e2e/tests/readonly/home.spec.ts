import { test, expect } from '../support/fixtures';
import { CATALOG } from '../../fixtures/synthetic/catalog';
import { EventsIndexPage } from '../pages/events-index.page';

const E = CATALOG.events;

// Approved events that have not ended at the anchor (Wed 2031-01-15 10:00 America/Chicago) are listed.
const listed = [
  'freeOpen', 'freeMembersOnly', 'paidMembersOnly', 'paid45', 'paidAndFree', 'eventbriteOpen', 'eventbriteClosed',
  'registrationClosed', 'ageRestricted', 'requiresPrerequisite', 'fulfillsPrerequisite', 'seriesParent', 'fullEvent',
  'approvalRequired', 'roomToolBooking', 'withFiles', 'unicodeLongHtml', 'dstSpringForward', 'dstFallBack',
  'cronCancelReminder', 'cronStartReminder',
];
// Pending / rejected / cancelled / completed / already-ended events are not.
const notListed = [
  'pendingFresh', 'rejected', 'cancelled', 'completed', 'preCutoff', 'honorariumRequested', 'cronEndedApprovedPaid',
  'cronAutoApprove', 'cronAutoApproveHonoraria',
];

const has = (titles: string[], key: string) => titles.some((t) => t.includes(E[key].name));

test.describe('home (upcoming list)', () => {
  test('lists exactly the approved upcoming events', async ({ page }) => {
    const index = new EventsIndexPage(page);
    await index.goto();
    await expect(index.heading).toBeVisible();
    const titles = await index.eventTitles();
    for (const key of listed) expect(has(titles, key), `${key} should be listed`).toBe(true);
    for (const key of notListed) expect(has(titles, key), `${key} should not be listed`).toBe(false);
  });

  test('shows cost in the heading for paid events', async ({ page }) => {
    const index = new EventsIndexPage(page);
    await index.goto();
    const titles = await index.eventTitles();
    expect(titles.find((t) => t.includes(E.paid45.name))).toContain('$45.00');
    expect(titles.find((t) => t.includes(E.paidMembersOnly.name))).toContain('$5.00');
  });

  test('renders non-ASCII names intact', async ({ page }) => {
    const index = new EventsIndexPage(page);
    await index.goto();
    expect((await index.eventTitles()).join('\n')).toContain('Céramique 日本語 Atelier');
  });

  test('the full ordered list is stable (golden)', async ({ page }) => {
    const index = new EventsIndexPage(page);
    await index.goto();
    expect((await index.eventTitles()).join('\n')).toMatchSnapshot('home-event-titles.txt');
  });

  // Filters: each narrows the list to events carrying that category/type/tool/room.
  const filters: { name: string; query: string; expectKeys: string[] }[] = [
    { name: 'category Fiber Arts', query: 'category=3', expectKeys: ['paidMembersOnly'] },
    { name: 'type Event', query: 'type=2', expectKeys: ['freeMembersOnly', 'dstSpringForward', 'dstFallBack'] },
    { name: 'tool Vinyl Cutter', query: 'tool=4', expectKeys: ['roomToolBooking'] },
    { name: 'tool Sewing Machine', query: 'tool=1', expectKeys: ['paidMembersOnly'] },
    { name: 'room 3D Fabrication Room', query: 'room=1', expectKeys: ['paid45', 'eventbriteClosed', 'roomToolBooking'] },
  ];
  for (const f of filters) {
    test(`filter by ${f.name}`, async ({ page }) => {
      const index = new EventsIndexPage(page);
      await index.goto(f.query);
      const titles = await index.eventTitles();
      for (const key of f.expectKeys) expect(has(titles, key), `${key} expected for ${f.query}`).toBe(true);
      // Everything else on the unfiltered list must be gone.
      for (const key of listed.filter((k) => !f.expectKeys.includes(k) && k !== 'seriesParent')) {
        expect(has(titles, key), `${key} should be filtered out by ${f.query}`).toBe(false);
      }
    });
  }

  test('sort links reverse the order', async ({ page }) => {
    const index = new EventsIndexPage(page);
    await index.goto();
    const asc = await index.eventTitles();
    await index.goto('sort=Events.event_start&direction=desc');
    const desc = await index.eventTitles();
    expect(desc.length).toBe(asc.length);
    expect(desc[0]).toContain(E.dstFallBack.name); // latest event (Nov 2031)
    expect(asc[asc.length - 1]).toContain(E.dstFallBack.name);
  });
});
