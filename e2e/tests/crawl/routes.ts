import { CATALOG } from '../../fixtures/synthetic/catalog';

/**
 * ALLOWLIST of routes the golden crawl may GET. Nothing here may change state or send anything.
 * Never add: /events/cron, /users/logout, or any delete / cancel / approve / reject / accept / attendance /
 * assignments / process-rejection path, and never a POST. `assertSafe` enforces it.
 */
const E = CATALOG.events;

export const staticRoutes: string[] = [
  '/', '/?type=1', '/?type=2', '/?category=3', '/?tool=4', '/?room=1',
  '/?sort=Events.event_start&direction=desc', '/?sort=Events.created&direction=asc',
  '/events/calendar', '/events/calendar/2030/12', '/events/calendar/2031/2', '/events/calendar/2031/3', '/events/calendar/2031/11',
  '/events/calendar/2031/1/15',
  '/events/embed',
  '/events/all', '/events/all?start_date=2031-03-01&end_date=2031-03-31',
  '/events/submitted', '/events/attending', '/events/pending',
  '/events/honoraria/pending', '/events/honoraria/accepted', '/events/honoraria/rejected', '/events/honoraria/upcoming',
  '/events/feed/rss',
  '/events/add',
  '/categories', '/committees', '/contacts', '/prerequisites', '/rooms', '/tools', '/configurations',
  '/categories/add', '/committees/add', '/prerequisites/add', '/rooms/add', '/tools/add', '/contacts/add',
  '/categories/edit/1', '/committees/edit/1', '/prerequisites/edit/1', '/rooms/edit/1', '/tools/edit/1', '/configurations/edit/1',
  '/contacts/view/2001',
  '/calendar-admin/edit',
  '/honoraria',
  '/users/login',
  '/this-page-does-not-exist',
];

const everyEvent = Object.values(E).map((e) => e.id);
export const dynamicRoutes: string[] = [
  ...everyEvent.map((id) => `/events/view/${id}`),
  ...[E.freeOpen, E.paidMembersOnly, E.withFiles, E.seriesParent].map((e) => `/events/ics/${e.id}`),
  ...[E.paidMembersOnly, E.pendingFresh, E.honorariumRequested].map((e) => `/events/edit/${e.id}`),
  ...[E.freeOpen, E.paidMembersOnly, E.fullEvent, E.registrationClosed, E.eventbriteOpen, E.approvalRequired].map((e) => `/registrations/event/${e.id}`),
  `/registrations/view/${CATALOG.registrations.freeConfirmed}`,
];

export const allRoutes: string[] = [...staticRoutes, ...dynamicRoutes];

const FORBIDDEN = /\/(cron|logout|delete|cancel|approve|reject|accept|attendance|assignments|process-rejection)(\/|\?|$)/i;

export function assertSafe(routes: string[]): void {
  const bad = routes.filter((r) => FORBIDDEN.test(r));
  if (bad.length) throw new Error(`crawl allowlist contains state-changing routes: ${bad.join(', ')}`);
}

/** Roles that crawl. Full normalised bodies are stored for these; every role gets a status/redirect/hash summary. */
export const bodyRoles = ['anonymous', 'admin'] as const;
