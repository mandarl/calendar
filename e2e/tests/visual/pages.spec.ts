import { test, expect } from '../support/fixtures';
import { authFile, type RoleName } from '../data/test-users';
import { ANCHOR } from '../../fixtures/anchor';
import { CATALOG } from '../../fixtures/synthetic/catalog';
import { GenericPage } from '../pages/generic.page';

/**
 * Screenshot baselines of every page in the production inventory (see production-baseline-and-e2e-plan.md),
 * rendered from the deterministic synthetic dataset. Run ONLY inside the Linux Playwright image so one
 * environment produces the baselines; a rebuilt app is diffed against them (no --update-snapshots).
 */
test.skip(process.platform !== 'linux', 'visual baselines are generated in the Linux Playwright container only');

const E = CATALOG.events;
type Viewport = 'desktop' | 'mobile';
const SIZES: Record<Viewport, { width: number; height: number }> = {
  desktop: { width: 1440, height: 900 },
  mobile: { width: 390, height: 844 },
};

interface Shot {
  name: string;
  path: string;
  role?: RoleName; // default: anonymous
  viewports?: Viewport[]; // default: desktop only
  /** Selectors of text that legitimately varies (rendered "now" etc.). */
  mask?: string[];
}

const both: Viewport[] = ['desktop', 'mobile'];

const shots: Shot[] = [
  // Public
  { name: 'login', path: '/users/login', viewports: both },
  { name: 'home', path: '/', viewports: both },
  { name: 'calendar-month', path: '/events/calendar', viewports: both },
  { name: 'calendar-month-feb', path: '/events/calendar/2031/2' },
  { name: 'event-free-open', path: `/events/view/${E.freeOpen.id}` },
  { name: 'event-paid-members-only', path: `/events/view/${E.paidMembersOnly.id}`, viewports: both },
  { name: 'event-paid-45', path: `/events/view/${E.paid45.id}` },
  { name: 'event-eventbrite-open', path: `/events/view/${E.eventbriteOpen.id}` },
  { name: 'event-eventbrite-closed', path: `/events/view/${E.eventbriteClosed.id}` },
  { name: 'event-registration-closed', path: `/events/view/${E.registrationClosed.id}` },
  { name: 'event-full', path: `/events/view/${E.fullEvent.id}` },
  { name: 'event-unicode-long-html', path: `/events/view/${E.unicodeLongHtml.id}` },
  { name: 'event-with-files', path: `/events/view/${E.withFiles.id}` },
  { name: 'registration-paid', path: `/registrations/event/${E.paidMembersOnly.id}`, role: 'member', viewports: both },
  { name: 'registration-free', path: `/registrations/event/${E.freeOpen.id}`, role: 'member' },
  // The error page prints the server time; mask it (the faked clock ticks).
  { name: 'error-404', path: '/this-page-does-not-exist', mask: ['text=/\\d{1,2}:\\d{2}:\\d{2}\\s*UTC/'] },
  // Member
  { name: 'member-hosting', path: '/events/submitted', role: 'member' },
  { name: 'member-attending', path: '/events/attending', role: 'member' },
  { name: 'member-add-event', path: '/events/add', role: 'member', viewports: both },
  // Admin
  { name: 'admin-event-view-paid', path: `/events/view/${E.paidMembersOnly.id}`, role: 'admin' },
  { name: 'admin-event-edit', path: `/events/edit/${E.paidMembersOnly.id}`, role: 'admin' },
  { name: 'admin-pending', path: '/events/pending', role: 'admin' },
  { name: 'admin-archive', path: '/events/all', role: 'admin' },
  { name: 'admin-categories', path: '/categories', role: 'admin' },
  { name: 'admin-categories-edit', path: '/categories/edit/1', role: 'admin' },
  { name: 'admin-committees', path: '/committees', role: 'admin' },
  { name: 'admin-committees-edit', path: '/committees/edit/1', role: 'admin' },
  { name: 'admin-contacts', path: '/contacts', role: 'admin' },
  { name: 'admin-prerequisites', path: '/prerequisites', role: 'admin' },
  { name: 'admin-prerequisites-edit', path: '/prerequisites/edit/1', role: 'admin' },
  { name: 'admin-rooms', path: '/rooms', role: 'admin' },
  { name: 'admin-rooms-edit', path: '/rooms/edit/1', role: 'admin' },
  { name: 'admin-tools', path: '/tools', role: 'admin' },
  { name: 'admin-tools-edit', path: '/tools/edit/1', role: 'admin' },
  { name: 'admin-configurations', path: '/configurations', role: 'admin' },
  { name: 'admin-configurations-edit', path: '/configurations/edit/1', role: 'admin' },
  { name: 'admin-contacts-add', path: '/contacts/add', role: 'admin' },
  { name: 'honoraria-pending', path: '/events/honoraria/pending', role: 'honorariumAdmin' },
  { name: 'honoraria-accepted', path: '/events/honoraria/accepted', role: 'honorariumAdmin' },
  { name: 'super-admin-settings', path: '/calendar-admin/edit', role: 'superAdmin' },
];

for (const shot of shots) {
  for (const vp of shot.viewports ?? ['desktop']) {
    test.describe(`${shot.name} @${vp}`, () => {
      if (shot.role) test.use({ storageState: authFile(shot.role) });
      test.use({ viewport: SIZES[vp] });

      test('matches baseline', async ({ page }) => {
        const g = new GenericPage(page);
        await g.fixClock(new Date(ANCHOR));
        await g.open(shot.path);
        await g.settle();
        await expect(page).toHaveScreenshot(`${shot.name}-${vp}.png`, { fullPage: true, mask: g.masks(shot.mask) });
      });
    });
  }
}
