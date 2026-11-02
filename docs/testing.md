# Testing and the end-to-end baseline

This repo has three layers of checks. The first two are small; the third is the safety net for any large change
(for example the planned Cake 5 rebuild).

| Layer | Where | Runs |
| --- | --- | --- |
| PHPUnit unit tests | `tests/TestCase` (only `WebhookComponentTest` is real; stub classes under `src/` are never run) | `composer unit` |
| Coding standard | `src/`, `tests/` | `composer cs` |
| **E2E baseline harness** | `e2e/` | `e2e/harness/scripts/run.sh` (this document) |

## What the harness is

A deterministic, **offline** environment that runs a build of the app (a Docker image) against synthetic data and
records its behaviour:

- functional Playwright tests (`readonly`, later `write`),
- screenshot baselines of every page in the production inventory, desktop and mobile (`visual`),
- a golden crawl: status, redirect, content type and normalised body for every allowlisted GET route, per role (`crawl`).

The same suite can later be pointed at a **different build** (`CALENDAR_IMAGE`, `ADAPTER`) and diffed against the stored
baseline. Live production is only a human reference: its data changes constantly.

### Safety properties (do not weaken)

- The app, database, LDAP and mail live on an `internal` Docker network: **no internet egress**, no host ports.
- All third parties are test doubles: a fake Braintree gateway and webhook sink (`e2e/harness/fakes/server.mjs`),
  MailHog for mail (via the config adapter), a dev OpenLDAP. No real keys exist anywhere in the harness.
- **Nothing here talks to production.** Production data is a separate, separately approved phase (see below).
- Baselines (screenshots, goldens, recorded assets, saved images) are written **outside the repo** to `BASEROOT`
  (default `../calendar-baselines`) because rendered pages carry the app's own branding and links.
- Specs use page objects only (`e2e/PLAYWRIGHT.md`); expectations are by role and text, never by framework markup.

## Architecture

```
e2e/
  harness/
    compose.baseline.yml     stack: db, ldap, mail, faketime-init, app (+fakes sharing its netns), pw runner
    baseline.env             synthetic environment (every var .docker/environment.conf reads)
    adapters/cake3/app.php   config overlay routing the hard-coded 'sparkpost' transport to MailHog
    fakes/server.mjs         fake Braintree gateway (:3000) + webhook sink (:4010), /__log and /__reset
    stubs/braintree-v2-stub.js  browser stub for the drop-in v2 script
    faketime/Dockerfile      libfaketime built on bullseye (works for later PHP images too)
    ldap/                    overlay users (one per admin role) + their group memberships
    scripts/                 env.sh, build-image.sh, run.sh
  fixtures/
    anchor.ts                the fixed "now" and Chicago<->UTC helpers
    synthetic/catalog.ts     named events/registrations with fixed ids; build-sql.ts emits plain SQL
  tests/
    support/                 db/dataset/global-setup (reset), fixtures (external routing), http (crawl client)
    pages/ components/       page objects
    setup/ readonly/ visual/ crawl/ har/ (projects, see playwright.config.ts)
```

### How "now" is pinned

The app runs under **libfaketime** (`LD_PRELOAD`, `FAKETIME=@2031-01-15 16:00:00`): a *ticking* clock that starts at
the anchor (Wed 2031-01-15 10:00 America/Chicago). `Chronos::setTestNow` is **not** used: `new Time('America/Chicago')`
ignores it in Chronos 1.3, and raw `date()`/`strtotime()` calls ignore it too. A future anchor keeps session cookies
valid in the real-clock browser. `run.sh` restarts `app` before each run so "now" is anchor + a few minutes. The browser
clock is pinned to the anchor in the visual specs. OIDC (JWT `exp`/`iat`) needs a real clock, so it gets its own `app-rt`
container (`oidc` compose profile).

### Datasets

`DATASET=synthetic` (default). `tests/support/dataset.ts` defines the interface a later `snapshot` dataset implements.
Fixture rows have fixed ids; dates are relative to the anchor. The database is utf8mb3, so 4-byte emoji cannot be stored.

## Running it

Git Bash on Windows (set `MSYS_NO_PATHCONV=1`; `scripts/env.sh` does), or any POSIX shell with Docker 29+.

```bash
# 1. Build the image under test from a clean, LF-normalised archive of a commit
e2e/harness/scripts/build-image.sh                # REF=HEAD by default; tags calendar-baseline:<sha>

# 2. (once) build the faketime sidecar and record external fonts/CSS for offline replay
docker build -t calbase-faketime:bullseye e2e/harness/faketime
RECORD_HAR=1 e2e/harness/scripts/run.sh --project=har

# 3. Run
e2e/harness/scripts/run.sh --project=smoke --project=readonly --project=visual --project=crawl
UPDATE=1 e2e/harness/scripts/run.sh --project=visual     # regenerate baselines on purpose
SKIP_UP=1 e2e/harness/scripts/run.sh ...                  # stack already up
```

Environment: `CALENDAR_IMAGE`, `ADAPTER` (default `cake3`), `BASELINE_ID` (default `cake3-<sha>-synthetic-v1`),
`ANCHOR`, `BASEROOT`. The frozen schema (`00-schema-lookups.sql`) is produced once from the image's migrations and
seeds and lives in the baseline directory; every build, including a Cake 5 one, must boot on it unchanged.

Rules of thumb:
- Generate and update **visual** baselines only through the container (`run.sh`); the visual project refuses to run
  on non-Linux hosts.
- Never log out a shared `storageState` user in a test: it destroys the server-side session for every other spec.
- Specs that deliberately provoke failures (crawling admin routes as a member, forbidden pages) must use their own
  saved session (`authFile(role, 'crawl' | 'denied')`): the server queues flash messages in the session, and a leftover
  "not authorized" flash would otherwise appear in whichever spec renders next with the same login.
- Never add `/events/cron`, logout, delete, cancel, approve, reject, accept, attendance or assignments routes to the
  crawl allowlist (`tests/crawl/routes.ts` enforces it).
- `dependencies: ['setup']` re-logs every role at the start of each run; sessions are never reused across builds.

## Parity workflow for a rebuilt app (for example Cake 5)

1. Build the new image; add `e2e/harness/adapters/cake5/` (config overlay that sends mail to MailHog).
2. Boot it on the same frozen schema and dataset, same anchor: `ADAPTER=cake5 CALENDAR_IMAGE=<new> BASELINE_ID=<old>`.
3. Run `--project=visual --project=crawl --project=readonly` **without** `UPDATE`.
4. Differences are either regressions (fix) or intentional (extend `crawl/normalize.ts` for framework-specific
   markup such as CSRF field names, then re-bless deliberately).
5. Page objects locate by role and label only so they survive a markup change.

## Coverage matrix (production page inventory -> tests)

R = `readonly` functional, V = `visual` screenshot (desktop, +mobile where noted), C = golden `crawl` (7 roles).

| Page / route | R | V | C | Notes |
| --- | :-: | :-: | :-: | --- |
| `/users/login` (+ failure message, logout) | yes | yes (+mobile) | yes | OIDC/SSO login not covered (deferred) |
| `/` home list, filters (type/category/tool/room), sort | yes | yes (+mobile) | yes | |
| `/events/calendar`, month nav, day view | yes | yes (+mobile, Feb) | yes | day view is a characterised bug |
| `/events/view/{id}` every registration/visibility variant | yes (text goldens) | yes (9 variants, paid +mobile) | yes (every event) | admin view + attendee tabs covered |
| `/registrations/event/{id}` free / paid | - | yes (paid +mobile) | yes | submitting a registration is a deferred write test |
| `/events/add` | - | yes (+mobile) | yes | submit/validation/upload is a deferred write test |
| `/events/edit/{id}` | - | yes | yes | |
| `/events/pending`, `/submitted`, `/attending`, `/all` | yes (`/all`) | yes | yes | |
| Admin indexes: categories, committees, contacts, prerequisites, rooms, tools, configurations | yes | yes | yes | add/edit/delete round trips deferred |
| Admin edit forms (categories, committees, prerequisites, rooms, tools, configurations), contacts add | - | yes | yes | |
| Honoraria pages (pending/accepted/rejected/upcoming), financial export | - | yes (pending, accepted) | yes | accept/reject/CSV export are deferred POSTs |
| Super admin settings (`/calendar-admin/edit`) | - | yes | yes | |
| Navigation per role (anonymous, member, admin + 4 single-role admins) | yes | - | yes | one extra menu per admin group |
| Access control (anonymous redirects, member on admin pages, 404) | yes | yes (404) | yes | |
| `/events/feed/rss`, `/events/ics/{id}` | - | - | yes | normalised "now" stamps |
| `cron()`, webhook, mail, payments, cancellation, attendance, AD assignment | - | - | - | **deferred write-path tests**; fixture rows for every `cron()` branch already exist |
| `/contacts` at production scale (8k+ rows), 900 KB home, 880 KB RSS | - | - | - | needs the `synthetic-scale` dataset / Phase P |

## Characterised current behaviour (do not "fix" in the baseline)

Recorded as goldens or explicit assertions; fixing any of these is a separate, deliberate change.

- The calendar **day view** (`/events/calendar/Y/M/D`) renders a template that does not exist
  (`EventsController.php:668`, `render('index_daily')`).
- `RegistrationsController.php:160` has a `$$registration` typo on the "no free spaces" path.
- OIDC logins always get **no groups**: `OpenIDConnectService::getGroupsFromUserInfo` never returns its result,
  so SSO users do not see admin menus.
- Event pages show the **Register button for Eventbrite events** next to the third-party link.
- A **required prerequisite is not shown** on the event page.
- Event descriptions are **HTML-escaped** and shown literally.
- A failed login says: "Invalid username or password, try again."
- Each admin group unlocks exactly one menu: Calendar Admins -> Admin, Honorarium Admins -> Honoraria,
  Financial Reporting -> Financials, Calendar Super Admins -> Super Calendar Admin.

## Known gaps (tracked, not hidden)

- **Not yet covered:** write paths (add/edit/copy event, approve, registration free/paid/cancel, attendance and AD
  assignment, honoraria accept/reject, `cron()`), the OIDC smoke test, admin CRUD round trips.
- **Twilio** is not exercised (all fixture registrations have `send_text=0`; egress is blocked by design).
- **Braintree** runs against a fake gateway and a stubbed drop-in; a sandbox tier needs owner-supplied credentials.
- **Scale**: production has ~8,300 contacts on an unpaginated page, a ~900 KB home page and an ~880 KB RSS feed.
  The synthetic set is small. A `synthetic-scale` dataset and size/time budgets are deferred.
- **Production parity** (real data) is a later phase that needs its own written approval covering data handling:
  restore a read-only snapshot into an isolated environment (never point a build at the live database), mask names,
  emails and phones, keep it off shared storage, set a retention and deletion log, and keep every side-effect kill
  switch (no egress, fake gateway, mail to MailHog, dev LDAP/Keycloak only, webhook sink, no `cron`).
- The Docker `base` stage needed one change to build at all: Debian 11's security mirror 404s, so `apt` is pointed at
  `archive.debian.org`. Other pins (extension installer, xdebug) are intentionally untouched.
