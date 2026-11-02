/**
 * Synthetic LDAP users: the submodule seed users (dms-ad-openldap/03-users.ldif) plus the single-role overlay
 * (e2e/harness/ldap/99.6-test-roles.ldif). All passwords are "password". No real accounts.
 */
const password = 'password';

export const testUsers = {
  member: { username: 'user1', password },
  /** user2 belongs to every admin group (Calendar Admins, Calendar Super Admins, Honorarium Admins, Financial Reporting). */
  admin: { username: 'user2', password },
  calendarAdmin: { username: 'cal-admin', password },
  honorariumAdmin: { username: 'honor-admin', password },
  financialReporting: { username: 'fin-report', password },
  superAdmin: { username: 'super-admin', password },
} as const;

export type RoleName = keyof typeof testUsers;

/** Roles that get a saved login session (e2e/.auth/<role>.json) from the setup project. */
export const authRoles: RoleName[] = ['member', 'admin', 'calendarAdmin', 'honorariumAdmin', 'financialReporting', 'superAdmin'];

/**
 * Saved sessions are namespaced. Server-side sessions carry queued flash messages, so any spec that deliberately
 * provokes failures (crawling admin routes as a member, hitting forbidden pages) must use its OWN login:
 *   (none)   shared by readonly + visual specs that only view pages
 *   'crawl'  the golden crawl (visits every route, so it queues "not authorized" flashes)
 *   'denied' the member-on-admin-pages access test
 * Otherwise a leftover flash shows up in whichever spec renders next with that session (and projects run in parallel).
 */
export type AuthNamespace = 'crawl' | 'denied';
export const AUTH_DIR = process.env.AUTH_DIR ?? '.auth';
export const authFile = (role: RoleName, ns?: AuthNamespace) => `${AUTH_DIR}/${ns ? `${ns}-` : ''}${role}.json`;

/** Extra (namespace, role) sessions created by the setup project, after the shared ones. */
export const namespacedSessions: { ns: AuthNamespace; role: RoleName }[] = [
  ...authRoles.map((role) => ({ ns: 'crawl' as const, role })),
  { ns: 'denied', role: 'member' },
];
