# Calendar: PHP CVE mitigation and Cake 5 upgrade - plan and handoff

Written 2026-10-31 for the next agent (or person) picking this up. It records the decisions, what is **done**, what is
**in flight**, and what **remains**. Read sections 1-3 before touching anything. Companion research reports are copied
to `calendar-upgrade-research/` (section 11).

Scope: the `calendar/` app (CakePHP 3.10.4 on PHP 7.4, Docker). It is a separate git checkout; this file lives in the
non-git `dms/` working directory like `keycloak-upgrade-and-theme-plan.md`.

---

## 0. LATEST STATUS (2026-11-01) - READ THIS FIRST

The Cake 3 baseline pass is **done** (sections 3-6). The **Cake 5 port is in progress**: the foundation and the library kit
are done, and nine parallel slice agents were started (B, F, M, AHL, C, D, R, X and T); **all nine were stopped at the same moment
by the 5-hour usage limit (reset 10:40am America/Chicago); none finished and nothing has been merged.** Their partial work is saved on disk.

### 0.1 Where things stand

| Piece | State | Where |
| --- | --- | --- |
| Cake 3 baseline (137 tests, 3 stable runs) | DONE | `calendar-baselines/cake3-1a086bead5-synthetic-v1/`; harness in `calendar/e2e/` (branch `baseline/e2e-harness`, uncommitted) |
| Harness made multi-instance (`COMPOSE_PROJECT`, per-project `.auth-*`/`test-results-*`, atomic dataset regeneration, `UPDATE=1` refused unless `ADAPTER=cake3`) | DONE, regression 137/137 | `calendar/e2e/harness/scripts/{env.sh,run.sh}`, `playwright.config.ts` |
| Library kit (Braintree 6, Twilio 8, LdapRecord 3.8, OIDC, FeedWriter, SparkPost transport, 104 tests) | DONE | `calendar-cake5-libkit/` (README lists every incompatibility) |
| Cake 5 foundation (5.4.3, PHP 8.4, auth, core pages) | DONE: harness result **123 passed / 14 failed**, all failures on unported code | worktree `calendar-cake5` (branch `cake5/port`, **uncommitted**); `calendar-cake5-foundation-report.md`; image `calendar-cake5:dev` |
| Work breakdown (14 slices, build/test steps, 17 gotchas) | DONE | `calendar-cake5-workbreakdown.md` |
| Slice agents B, F, M, AHL (wave 1) and C, D, R, X (wave 2), T (write tests on Cake 3) | **STOPPED mid-work by the usage limit; nothing finished or merged**; no `*-CHANGES.md` exists | per-slice cards: `calendar-cake5-slice-cards.md` (owned files, goal, proof, state at stop) |
| Integration tree `calendar-cake5` | = foundation only; no slice merged | |
| Option A CVE stopgap (patched PHP 7.4 etc.) | **STILL NOT SCHEDULED** by the owner; the CVEs are being exploited | section 1 and 10 |

### 0.2 How the slices run in parallel (Docker is what makes this safe)

The same offline harness that produced the Cake 3 baseline is reused, once per agent, as an isolated copy of the whole stack.
Each slice agent owns a complete, private environment, so agents never share a database, a session, a build or a file:

1. **One working copy per slice.** `calendar-cake5-<s>` is a copy of the integration tree (made with `tar --exclude=./.git`, never a
   shared checkout), so one agent's half-written file cannot break another agent's image build. Agents edit only the files their slice
   owns (cards file); hunks needed in files they do not own are reported, not applied. The lead merges owned files back into
   `calendar-cake5` (not done yet, see 0.4).
2. **One Docker compose project per slice** (`COMPOSE_PROJECT=calc5<s>`; foundation `calcake5`; Cake 3 baseline `calbase`; write-test agent
   `calwt`). Each project is a full private stack from `calendar/e2e/harness/compose.baseline.yml`: its own MariaDB (tmpfs), OpenLDAP,
   MailHog, the app container with the libfaketime clock pinned to 2031-01-15, a fakes sidecar (fake Braintree gateway + webhook sink)
   and a Playwright runner. All of it sits on an `internal` Docker network (**no internet egress, no host ports**), so 10 stacks run side by
   side without port clashes and nothing can reach production or a real payment/SMS/mail provider.
3. **One image and one vendor volume per slice**: `docker build --target production -t calendar-cake5:<s>` from the slice's working copy;
   composer's `vendor/` lives in a named volume `calc5<s>-vendor` (the Windows bind mount is far too slow); the PHP 8.4 `calendar-cake5:base`
   image is the toolbox for `php -l`, composer and PHPUnit (`docker run --rm -v <copy>:/app -v calc5<s>-vendor:/app/vendor ...`).
4. **The harness is run from the original checkout** with `ADAPTER=cake5 CALENDAR_IMAGE=calendar-cake5:<s> COMPOSE_PROJECT=calc5<s>
   BASELINE_ID=cake3-1a086bead5-synthetic-v1`. The `cake5` adapter overlays the app config (mail to MailHog). Per-project outputs
   (`e2e/.auth-<project>`, `test-results-<project>`, `playwright-report-<project>`) keep saved logins and failure artefacts apart.
5. **One shared, read-only truth**: `calendar-baselines/` holds the Cake 3 goldens, the frozen schema and the deterministic synthetic
   dataset. Every slice is compared against the SAME goldens (screenshots, crawl, text). `run.sh` regenerates the dataset SQL atomically and
   only if it changed, and **refuses `UPDATE=1` for any adapter other than `cake3`**, so no agent can overwrite the baseline.
6. **The old app stays runnable as a reference oracle**: the image `calendar-baseline:1a086bead5` (PHP 7.4, Cake 3, archived as
   `image.tar.gz` in the baseline dir) is started in a second harness project (`ADAPTER=cake3`) so wave-2 agents can do
   **differential testing**: send the same request to the Cake 3 stack and their Cake 5 stack and compare DB rows, mails (MailHog), the
   fake gateway log, the webhook log and LDAP membership. That, not code reading, is the evidence of parity for the untested money/mail/status paths.
7. **Resource limits**: Docker Desktop had 31 GB RAM / 12 CPUs; free disk was ~17 GB at the stop. Ten stacks plus builds was fine on
   resources but **nine concurrent agents exhausted the 5-hour usage limit**: next time run at most 3-4 agents at once.

### 0.3 Environment left behind (clean up or reuse)

At the stop, containers were still running for the projects `calbase`, `calcake5`, `calc5ahl`, `calc5b`, `calc5c`, `calc5f`, `calc5r`, `calc5x`,
`calwt` (and slice D has a working copy/volume but no stack). Reuse them (the harness `run.sh` does `up -d` and re-anchors the clock) or stop one with
`COMPOSE_PROJECT=<p> CALENDAR_IMAGE=calendar-cake5:<s> ADAPTER=cake5 bash -c '. e2e/harness/scripts/env.sh; $C down'` from `calendar/`
(plain `down`; do not prune volumes or touch other projects). Named volumes `calc5*`/`calcake5*`/`calwt*`/`libkit*` and images `calendar-cake5:*`
are cheap to keep. Working copies: `calendar-cake5-{b,f,m,ahl,c,d,r,x}`, `calendar-writetests`, plus `calendar-cake5-vendor-partial` (foundation leftover, unused).

### 0.4 What to do next (in order)

1. **After the limit resets, resume the stopped agents** (use `ListAgents`, then `SendMessage` to each by name: "continue from where you stopped; re-read your slice card and your working copy diff first"), or start NEW agents from the slice cards. Briefs: `calendar-cake5-agent-brief.md`, `-wave2.md`; per-slice cards: `calendar-cake5-slice-cards.md`. **Model policy: implementation agents on Haiku** (owner decision; memory `feedback-agent-team-use-haiku`). **Start at most 3-4 at a time**; suggested order: M + B + T first (others depend on them), then AHL + F, then C/D/R, then X.
2. **Slice F first fix**: its working copy fatals on every page until the `HtmlHelper::link()` override signature is `array|string $title` (see cards).
3. **Merge protocol** (not started): when a slice writes its `*-CHANGES.md`, copy the files listed there from the slice's working copy into `calendar-cake5` (`cp`, never `rm -rf`), apply the reported hunks for non-owned files by hand (typical conflicts: `AppController::initialize`, `EventsController::initialize`, `config/app.default.php` Email profile), rebuild `calendar-cake5:dev`, and run the full harness (smoke, readonly, visual, crawl; later `write`) with `COMPOSE_PROJECT=calcake5`. Recommended order: B, M, then F, AHL, then C, D, R, X. Slice T's tests are copied from `calendar-writetests` into the original `calendar/e2e/` (then re-run the Cake 3 gate: 137 + the new write tests, 3 consecutive green runs).
4. **Opus tech-lead review** of the merged result before declaring any slice done (the owner asked for Opus on review/coordination roles).
5. **Definition of "port ready for cutover"**: harness readonly/visual/crawl equal to Cake 3 except explicitly signed-off deviations; the `write` project green on Cake 5; `cron()` differential identical; staged side-by-side cutover behind Traefik with the old container stopped, not removed.

### 0.5 Decisions made and open items

Decided (owner): Cake 5 directly (E2); mail = SparkPost API with click tracking OFF (kit transport; official `sparkpost/sparkpost` package not usable beside Cake 5 without forcing `psr/http-message` ^1.1 app-wide); empty password = invalid credentials; OIDC groups bug FIXED; Bootstrap 3 look kept; `events.cost` stays float; implementation agents on Haiku; no `rm -rf`.
Open for the owner: (a) **schedule the Option A CVE stopgap** (time-critical); (b) `?room=` filter shows the room name in Cake 5 (Cake 3 lost it) - keep or reproduce the old gap; (c) `SECURITY_SALT`: production reads a variable the config never uses, so it runs on the public default salt - fix deliberately?; (d) SparkPost EU vs US endpoint and API key; whether the old behaviour left **open tracking** on (the old code set no options); (e) check/rotate any secrets in the tracked `.env`; (f) the foundation's 14 failing tests (1 readonly, 6 visual, the crawl routes) are unported code (registrations, add/edit, feeds, honoraria), not regressions: they are the slices' work list (see the foundation report).

---

## 1. Why this exists and what the owner decided

**Trigger.** Two PHP core CVEs, **CVE-2026-109618** and **CVE-2026-109619** (published 2026-10-21, CVSS 4.0 = 9.2,
unauthenticated RCE, on CISA's Known Exploited list since 2026-10-26, Apache `mod_php` is the highest-risk setup).
Fixed in PHP 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12. PHP 7.4 (what the app runs, `php:7.4-apache` on Debian 11) is EOL; the
PHP project published an unreleased **source-only backport branch for 7.4** (`PHP-7.4-CVE-2026-109618-109619`,
commit `712e60f9edfd03f0960fc4fc3c2c375f42b90aa2`). Treat 7.4 as vulnerable. Debian 11 support ended 2026-08-31.

**Hard constraint that drives everything.** CakePHP 3.10 declares `php >=5.6,<8.0` and is EOL (last tag 3.10.5, no
maintained fork). **Getting to a patched PHP means leaving CakePHP 3.** Details: `calendar-upgrade-research/`.

**Owner decision (2026-10-31): option E2 - jump straight to CakePHP 5 first.** "If that fails we will explore other
options." Do not re-litigate this. The earlier staged recommendation (3.10.5 -> 4.6 on 7.4 -> PHP 8.4) and the other
options (A stopgap, C Cake 3 on PHP 8, D paid support, E1 big-bang Cake 4, E3 replace the app) are documented in
`calendar-upgrade-research/calendar-php-upgrade-options.md` and remain the fallback menu.

**Validation strategy chosen by the owner:** (1) screenshot parity against production behaviour and (2) a Playwright
E2E layer. Both need a trustworthy **baseline captured from the current app** in a controlled environment. That is
what has been built so far (sections 3-4). The same harness is meant to be pointed at the Cake 5 build later.

**Owner note:** eventually the rebuild must be compared on **real production data**. That is Phase P (section 7),
separately approved, never part of the current pass.

**Still open and time-critical (owner decision, not done):** the stopgap "Option A" - harden PHP/Apache, restrict the
origin to Cloudflare, and rebuild PHP 7.4 from the official backport branch. E2 takes months; the CVEs are being
exploited now. The owner has not asked for Option A to be executed. The Dockerfile apt fix made in this pass
(section 4) is a prerequisite for it. **Ask the owner whether to schedule it.**

Traffic path (confirmed by the owner): Cloudflare -> Traefik container -> calendar container (Traefik on the calendar
host, not the OpenResty edge). Deploy procedure and its gotchas are in memory `calendar-deploy-procedure`.

---

## 2. Rules (violating one means STOP and ask)

From the owner and the reviewed plan:

1. **No contact with production**: no connection to the production host, DB, Keycloak, AD, Braintree, Twilio,
   SparkPost or the live site - not even read-only - without explicit confirmation in chat for that step.
2. **No production row data (PII) on this machine** in this phase. Synthetic data only.
3. **No commits, pushes, tags or stashes** unless the owner asks. Work sits uncommitted on the local branch
   `baseline/e2e-harness`.
4. **No real DMS data** (hostnames, IPs, domains, names, emails, phones) in code, comments, fixtures, tests or docs
   inside the repo. Use `example.test`/`example.org`, `Test Instructor A`, `555-01xx`.
5. **Do not change application code** (`src/`, `config/` except the harness overlay, `webroot/`, templates) to make
   a baseline test pass. The baseline characterises current behaviour, bugs included. If a test cannot pass without an
   app change, mark it `test.fail()` with a `file:line` comment and log it.
6. **Do not modify the `dms-ad-openldap` submodule** (separate repo). Add overlays under `e2e/harness/`.
7. **Do not stop or kill processes you did not start.** A Java process on this machine holds host ports 8000 and 8081.
   The harness binds no host ports.
8. **No `rm -rf`** (owner request, saved in memory `feedback-avoid-rm-rf`). Overwrite in place (`--update-snapshots`),
   use uniquely named directories, `mv` aside, or remove specific named files.
9. **Specs use page objects only** (`e2e/PLAYWRIGHT.md`): no `page.goto`/`page.locator` in spec files.
10. **Shell:** Git Bash with `MSYS_NO_PATHCONV=1` (done by `e2e/harness/scripts/env.sh`). New `.sh/.mjs/.ldif/.sql/.php`
    files must be LF (`core.autocrlf=true` here).
11. Pushback is given once and concisely; once the owner decides, execute (memory
    `feedback-weigh-tradeoffs-then-proceed`).

---

## 3. Current state (snapshot at time of writing)

| Item | Value |
| --- | --- |
| Repo | `calendar/` (own git checkout), branch **`baseline/e2e-harness`**, base commit `1a086bead5` |
| Working tree | ~27 changed/new paths, **all uncommitted** (nothing was committed) |
| Image under test | `calendar-baseline:1a086bead5` (built from a clean LF `git archive` of HEAD + the working-tree Dockerfile fix) |
| Baseline ID | `cake3-1a086bead5-synthetic-v1` |
| Baseline root (`BASEROOT`) | `dms/calendar-baselines/` - outside every git repo; synthetic artefacts only |
| Stack | Docker Compose project `calbase` (`db`, `ldap`, `mail`, `faketime-init`, `app`, `fakes`), **currently running** |
| Disk | C: had ~16 GB free at start; Docker was ~4 GB. The plan's stop limit is 15 GB of Docker use for this work |
| Leftover Docker images | `php8test:*`, `base-8.*`, `track4-*`, `php74-patched-test` from research agents; safe to remove with `docker image rm` when needed (not done) |

### RESULT (2026-10-31, supersedes the "in-flight" text below): stability gate PASSED

After the final re-bless, three consecutive full runs of `--project=smoke --project=readonly --project=visual
--project=crawl` each reported **137 passed, 0 failed, exit 0, no retries** (about 2 minutes each). Safety scan clean,
nothing committed (HEAD still `1a086bead5`, no new stashes; 6 pre-existing stashes from 2024-2026 are not mine),
image archived, manifests written. Baseline on disk: 256 MB total (visual 3.9 MB / 46 PNGs, crawl 3.3 MB / 9 files,
readonly text goldens 127 KB / 32 files, the rest is the image archive).
The "in-flight job" text that follows is kept only for history.

### In-flight job (HISTORICAL - superseded by the RESULT above)

A background command (task `bapf9h1k6`; an earlier attempt was stopped on purpose, see Gotchas 14) runs: one deliberate
**re-bless** (`UPDATE=1`) of all golden families, then **three consecutive stability runs**, each with
`--project=smoke --project=readonly --project=visual --project=crawl`.
Logs (ANSI-coloured; strip with `sed -E 's/\x1b\[[0-9;]*m//g'`):

```
calendar-baselines/_run-rebless2.log   _run-stable1.log   _run-stable2.log   _run-stable3.log
```
(The `_run-stable*.log` files are overwritten by the current attempt; the first attempt's `stable1` had 6 failures, all
caused by the shared-session flash leak described in Gotchas 14, since fixed.)

When it ends, `grep -E "✘|passed|failed|flaky|exit=" <log>` each one. **Definition of done needs all three stable runs
to be fully green with zero retries.** If the job is not running any more and the logs are missing, re-run it
(section 8). The rebless was needed because the dataset changed (unique timestamps, see "Gotchas" 9).

---

## 4. What has been done

### Phase R - research and decision (complete)
Five parallel research tracks plus an Opus coordinator produced the options document; a separate Opus tech-lead review
produced the reviewed baseline plan (v2). All in `calendar-upgrade-research/`.

### Phase 0 - buildable image (complete, verified)
- `Dockerfile` (`base` stage) now repoints apt's security line at `archive.debian.org` before `apt update`. Debian 11's
  security mirror 404s, which had broken every image build and the GitHub Actions Playwright workflow (failing since
  2026-10-04). Nothing else in the Dockerfile was touched (no pinning of the extension installer or xdebug).
- `e2e/harness/scripts/build-image.sh` builds the **production and develop** targets from
  `git -c core.autocrlf=false archive <ref>` into a fresh timestamped context dir (avoids baking Windows CRLF into the image;
  the Dockerfile itself is read from the working tree).
- Verified: both targets build; `ldap intl zip pdo_mysql openssl` load; `apachectl -t` Syntax OK; no CRLF in the image;
  `phpunit` gives `OK (3 tests, 3 assertions)` (needs `phpunit.xml.dist` mounted - `.dockerignore` excludes `*.dist`).

### Phase 1 - harness (complete, verified)
- **Clock pinned with libfaketime**, not `Chronos::setTestNow` (which provably does not pin `new Time('America/Chicago')`
  in Chronos 1.3). `FAKETIME=@2031-01-15 16:00:00` (a *ticking* clock starting at Wed 2031-01-15 10:00 Chicago).
  Verified: PHP CLI, Apache-served 404 page time, calendar renders January 2031, ticks at real speed, `uniqid()` works.
- **Offline stack**: `e2e/harness/compose.baseline.yml`, `backend` network is `internal: true` (verified: outbound request
  fails), no host ports, no fixed container names. MariaDB 10.6 (pinned by digest), OpenLDAP 1.5.0, MailHog, app, fakes.
- **Third parties are doubles**: fake Braintree gateway + webhook sink (`e2e/harness/fakes/server.mjs`, shares the app's
  network namespace), browser stub for the Braintree drop-in v2 script, mail routed to MailHog by a config overlay
  (`e2e/harness/adapters/cake3/app.php`; the app hard-codes a `sparkpost` transport). Twilio is unreachable by design.
- **LDAP overlay**: four single-role users (`cal-admin`, `honor-admin`, `fin-report`, `super-admin`, password `password`)
  plus group `member` entries, split across `99.6-test-roles.ldif` (adds) and `99.7-test-role-groups.ldif` (modifies).
- **Frozen schema**: `00-schema-lookups.sql` generated once from the image's own migrations + seeds (18 tables; table
  list matches the repo's `schema-dump-default.lock`, no drift). Every build, including Cake 5, must boot on it unchanged.
- External fonts/CSS (Google Fonts, Font Awesome) are replayed from a recorded **HAR** (`external-assets.har`, in the
  baseline dir); analytics are aborted.

### Phase 2 - synthetic dataset (complete)
`e2e/fixtures/`: `anchor.ts` (Chicago<->UTC helpers), `synthetic/catalog.ts` (32 events with fixed ids 1001+, 17
registrations, 4 contacts, files, honoraria, covering every registration type, restriction, status, DST dates,
unicode name, and every `cron()` state), `synthetic/build-sql.ts` (deterministic plain SQL). `run.sh` regenerates the
SQL on every run. DB reset between runs is done by `tests/support/global-setup.ts` (drops all tables, reloads schema +
dataset, resets files, mail, fakes).

### Phase 3 - baseline suite (mostly complete; see section 3 for the in-flight verification)
Playwright projects (`e2e/playwright.config.ts`): `setup` (serial UI login per role -> `.auth/<role>.json`), `smoke`
(the 2 original specs), `readonly` (74 tests incl. nav per role, home + filters + sort, calendar, every event-view
variant with text goldens, archive, access control, 404, admin index pages), `visual` (46 screenshot baselines,
desktop + mobile, from the Linux Playwright container only), `crawl` (allowlisted GET crawl for 7 roles: summary JSON per
role + normalised bodies for anonymous and admin), `har` (records external assets; needs the egress runner), `write`
and `oidc` (**placeholders only - no specs yet**).
Page objects: `login`, `events-index`, `calendar`, `event-view`, `archive`, `generic`, and `components/nav`.
Goldens live in `calendar-baselines/<BASELINE_ID>/snapshots/...` (outside the repo).

### Phase 4 - docs and safety (partly done)
- `calendar/docs/testing.md` written (architecture, how to run, parity workflow, characterised bugs, known gaps).
- `calendar/CLAUDE.md` corrected (templates are `.ctp` not Twig; UI is Bootstrap 3.3.6; test layout) and an E2E pointer added.
- `dms/docs/calendar.md` has a pointer line to the new testing doc.
- `.gitignore` additions: `/e2e/.auth/`, `/e2e/harness/*.local.env`, `/e2e/fixtures/generated/`.
- Safety scan (plan step 4.4) was run once and was clean (only loopback/wildcard addresses and the dev stack's existing
  `dummy-secret-for-dev-mode`). **Re-run it before declaring done.**

---

## 5. Findings worth knowing (do not "fix" in the baseline)

Current behaviour, recorded as goldens/assertions:
- The calendar **day view** (`/events/calendar/Y/M/D`) renders a template that does not exist (`EventsController.php:668`).
- `RegistrationsController.php:160` has a `$$registration` typo on the "no free spaces" path.
- **OIDC users always get no groups** (`OpenIDConnectService::getGroupsFromUserInfo` never returns), so SSO users never see admin menus.
- Eventbrite events show the Register button next to the third-party link; a required prerequisite is not shown on the
  event page; event descriptions are HTML-escaped and shown literally; failed login says "Invalid username or password, try again."
- Each admin group unlocks exactly one menu (Calendar Admins -> Admin, Honorarium Admins -> Honoraria, Financial
  Reporting -> Financials, Calendar Super Admins -> Super Calendar Admin).
- `GET /events/cron` is **anonymous** and sends mail, refunds and fires webhooks (`EventsController.php:53`). Never crawl it.
- Every page view by a logged-in user writes a `logs` row; the app creates a `contacts` row on a user's first login.
- Production facts (from read-only browsing): `/contacts` has ~8,300 rows on one unpaginated page; home HTML ~900 KB;
  RSS ~880 KB; Braintree **drop-in v2** (`braintree-2.27.0.min.js`) is loaded on registration pages and is being deprecated
  by Braintree - a separate migration from the server SDK.

Security observations to **verify** (not verified):
- The repo **tracks a `.env`** containing production-style variable names (and it is baked into the image). The values were
  deliberately not inspected. Someone should check whether real secrets are in git history and rotate if so.
- `composer install` in the production image installs dev tools (phpunit, debug_kit, twig 1.x...); `--no-dev` would remove them.
- Installed `phpseclib` 3.0.43, Guzzle 6.5.8 / psr7 1.9.1 and `php_codesniffer` 3.7.1 are in affected ranges for 2026 advisories.

---

## 6. What remains - finish the baseline pass

Ordered; each has an acceptance criterion.

1. ~~**Verify the stability gate**~~ **DONE** (see RESULT in section 3): 3 x 137 passed. If anything is changed later (dataset,
   page objects that affect screenshots, normaliser), re-bless the affected family once on purpose and re-run the gate.
   A failure that only appears on some runs means nondeterminism: find the *cause* (usual suspects: ties in ordering,
   random ids, "now" in output, shared-session flash, scroll position of widgets) and fix it in the fixture, masks or
   `crawl/normalize.ts`; never loosen assertions or add retries to make it green.
2. ~~**Save the old build**~~ **DONE** (2026-10-31): `calendar-baselines/<BASELINE_ID>/image.tar.gz`, 260 MB, `gzip -t` OK,
   carries the `org.opencontainers.image.revision` label. Restore with `gunzip -c image.tar.gz | docker load`.
   (If the image is rebuilt from a different commit the archive must be re-saved.)
3. ~~**Manifest additions**~~ **DONE**: `calendar-baselines/<BASELINE_ID>/manifest.image.json` (image id, git revision,
   Dockerfile diff hash, compose image ids). `manifest.json` (schema/dataset hashes, anchor) is written by global-setup each run.
4. ~~**Re-run the safety scan**~~ **DONE** on the tree as of the gate (clean). Re-run it (section 12) after any further edits. Any hit = STOP.
5. **Final report to the owner**: coverage matrix (each production-inventory page/route/feature -> the test or the reason
   it is not covered), the characterised bugs (section 5), known gaps, suite duration, baseline size, `git status` proving
   nothing was committed, and the decisions needed (section 10).
6. Tidy: leave the stack running only if the owner wants; stop it with
   `docker compose -p calbase --env-file e2e/harness/baseline.env -f e2e/harness/compose.baseline.yml down` (no volume
   deletion needed; the DB uses tmpfs).

## 7. What remains - deferred pieces of the baseline (Tier B and later)

Deferred, each needs its own pass (details in the reviewed plan v2, section 3.5-3.8):
- **Write-path tests (`write` project)**, run only against the seeded dev stack, DB reset per spec file:
  W1 add event (validation + happy path + public file upload; lands `pending`); W2 admin approves (DB status, mail golden,
  webhook payload golden); W3 free registration (mail, space count); W4 paid registration through the stub (fake gateway
  recorded the sale with cost and `customFields`; `transaction_id` set); W5 cancel before/after the cutoff (void vs refund
  recorded); **W6 `cron()` characterisation** (the riskiest path: status transitions, emails, gateway and webhook calls;
  fixture rows for every branch already exist in the catalog - ids 1019, 1031-1034). Keep `/events/cron` out of the crawl.
- **OIDC smoke test** (`oidc` project; needs the `oidc` compose profile: `app-rt` on a real clock + Keycloak 23.0.7 with the
  submodule's realm import). Also characterise "no groups for OIDC users".
- **CI**: update `.github/workflows/playwright.yml` to build with `build-image.sh` and run the functional projects. It cannot
  be proven green without a push; prove the same script locally and say so. Visual/crawl stay local unless the owner
  approves committing synthetic baselines.
- Edit/copy event, multi-date create flow, attendance + AD-group assignment write test, honoraria accept/reject + CSV
  export, admin CRUD add/edit/delete round trips, a full per-action authorisation matrix, Twilio side-effect test,
  Braintree **sandbox tier** (needs owner-supplied sandbox credentials), `synthetic-scale` dataset and size/time budgets
  (contacts 8k+, home 900 KB), phpcs/phpunit CI jobs, pinning `install-php-extensions`/xdebug.

## 8. What remains - production-data parity (Phase P; its own written approval; every step is a GATE)

Do not start without explicit owner approval covering data handling, including how screenshots of real data are stored.
- **P0 (read-only, owner/DBA):** state the production DB engine/version and charset (the harness assumes MariaDB 10.6,
  utf8mb3); optionally a schema-only dump (`--no-data`) to detect drift from the frozen schema; optionally diff the live
  container tree against git (history shows past uncommitted production edits, commits `3c339ab`/`44d0c53`).
- **P1 snapshot:** read-only provider snapshot restored into an **isolated** instance (or a read replica). Otherwise
  `mariadb-dump --single-transaction --quick --skip-lock-tables` under a dedicated SELECT-only account (creating it is a
  production change). **Never point either build at the live database** - the app writes on page views and in `cron()`.
- **P2 location/retention:** keep it off this laptop unless the owner allows; encrypted volume, named people only, fixed
  retention (suggest 14 days), deletion log. Real-data screenshots/crawls are never committed or uploaded as CI artifacts
  and are stored under a separate root (not `calendar-baselines/`).
- **P3 masking:** deterministic pseudonymisation of name/email/phone/`ad_username`/IP and nulling tax (W9) data by default;
  both builds must see byte-identical data. Unmasked only with explicit approval for named screens.
- **P4 kill switches (same as the current harness):** no egress, fake gateway, mail to MailHog, dev LDAP/Keycloak only,
  webhook sink, empty Twilio credentials, no scheduler, `cron` denylisted, restore the snapshot fresh before each build's run.
- **P5 run:** `DATASET=snapshot` (implement the `Dataset` interface in `tests/support/dataset.ts`), `ANCHOR` = snapshot
  timestamp, run old image then new image on the same restored copy and diff screenshots + crawl; `@synthetic`-tagged tests skip.

## 9. What remains - the actual upgrade to CakePHP 5 (after the baseline is signed off)

The owner chose to go directly to Cake 5 (est. 45-70 person-days, 4-6 months in the options document). **STARTED: see section 0 for
the current state** (foundation and library kit done; slice work in flight, nothing merged).
Facts from the research (full detail in `calendar-upgrade-research/track2-*.md`, `track3-*.md`, `track4-*.md`):

**Targets:** CakePHP 5.x on **PHP 8.4** (supported to end of 2028; 8.3 is the minimum sensible; 8.2 EOL 2026-12-31).
Cake 5.2 needs PHP >=8.1, 5.3/5.4 need >=8.2. The official upgrade tool (`cakephp/upgrade`, Rector-based) needs PHP 8
but Cake 3 refuses PHP 8, so run it from a throwaway PHP 8 container against a *copy*. Even though E2 skips the 4.x
release as a shipping target, the 3->4 breaking changes must still be applied before 4->5 ones.

**Biggest work items (counts from the audit):**
- `AuthComponent` + custom `AdAuthenticate` (39 uses) and OIDC -> Authentication/Authorization plugins (Auth is removed in 5).
- Templates: 77 `.ctp` files move to `templates/*.php`; `FormHelper::input` 193 uses in 28 files; BootstrapUI plugin versions
  target Bootstrap 5 while the app is **Bootstrap 3.3.6** - decide whether to keep the Bootstrap 3 look (own CSS) or redesign.
- Validation `allowEmpty/notEmpty` (71), `request->data/params/query` (56 incl. 44 in `EventsController`), `TableRegistry::get`
  (27), `Time` (~50, immutable dates), `Cake\Event\Event` type hints (16, fatal on 4+), Crud callbacks (69), `Email` -> `Mailer`,
  Security/CSRF component pairing, `Type::build`, `_serialize`, `passedArgs` (10).
- `EventsController` is 2,089 lines; `cron()` handles money, mail and status in one 137-line method.
- Plugins with a Cake 4/5 line: migrations (4.x/5.x), bake (3.x), debug_kit (5.x), BootstrapUI (5.x), Crud (7.x), CsvView (5.x),
  cakephp-upload (8.x). **`dallasmakerspace/cakephp-sparkpost-plugin` v1.0.6 is Cake-3 only** (97-line transport; port it
  to `AbstractTransport` or inline it, or use SparkPost's SMTP relay).
- Libraries: Braintree 3 -> 6 (about 23 lines in 2 files; PHP 8 *fatal* in v3 `Digest.php:53`), Twilio 4 -> 8.13
  (1 call site; v4 uses `create_function`, a fatal in PHP 8; used live in the event-cancel path), LdapRecord 2 -> 3/4
  (15 lines), feed-io (abandoned; 1 controller), openid-connect-php -> 1.0.2, mobiledetect widen, phpdotenv unused (delete),
  PHPUnit 6 -> 9/10 (only 3 of 103 test methods are real), Guzzle 6 -> 7 together with the SparkPost adapter chain.
- Browser side: Braintree drop-in v2 -> v3/Drop-in (separate; the harness stub will need a v3 variant).
- Latent bugs to carry or fix deliberately: day view, `$$registration` typo, OIDC null groups, `_applyAddress` hard-coded room ids.

**Lessons from the 2022 Cake 3.10 upgrade (history in `track4`):** it was bundled with a move to Docker and a new
production server; fixes kept landing for seven months; production later had uncommitted hand edits. **Do not bundle the
framework upgrade with a host/proxy change.** Reconcile the live container tree with git before building.

**Suggested shape (adapt):**
1. Land the baseline (sections 6-7). 2. New `cake5` branch on a fresh skeleton, port config/bootstrap/routes, plugins and
auth first (login must work), 3. controllers/models/templates module by module, running the harness's `readonly` + `crawl`
against `ADAPTER=cake5` continuously, 4. `visual` parity last (intentional diffs get explicit sign-off; extend
`crawl/normalize.ts` for framework markup instead of loosening routes), 5. write-path + `cron()` golden tests must pass before
any real deployment, 6. staged cutover behind Traefik (side-by-side container, header/hostname canary, **stop - do not
remove - the old container** to avoid the removal hang documented in `calendar-deploy-procedure`), 7. keep the retained
tagged old image for rollback; DB migrations must be reversible or additive.

**Fallback trigger (owner's words: "if that fails we will explore other options"):** define "fails" before starting,
e.g. a time-box with a mid-point checkpoint at which login + event list + registration render correctly on Cake 5. The
fallbacks are Option A (patched 7.4, only the CVEs) plus the staged 3.10.5 -> 4.6 path, or Option C (Cake 3.10 on PHP 8.4
with Braintree/Twilio upgraded first). Option A should be in place long before any of this.

---

## 9b. Library kit and owner decisions (2026-10-31)

`calendar-cake5-libkit/` (standalone, 84 tests on PHP 8.4) holds tested replacements: Braintree 6.40 (works against the
unmodified harness fake), Twilio 8.13 (wrapper returns NotConfigured on empty creds), LdapRecord **^3.8** (do NOT use 4.x:
`use_tls` silently becomes ldaps:// there), openid-connect-php 1.0.2 behind `OidcService`, a DOM `FeedWriter` that matches
the RSS golden byte for byte, SMTP mail. Its README lists every incompatibility (ids B-1...) with the exact fix.

Owner decisions on it:
1. **Mail: use SparkPost (API/package), with link tracking OFF** (`options.click_tracking = false` on every transmission).
   This REVERSES an earlier same-day decision to use plain SMTP. The kit's agent is extending `calendar-cake5-libkit/` with a
   Cake 5 `AbstractTransport` for SparkPost (the official `sparkpost/sparkpost` 2.3.0 fataled with `psr/http-message` 2; the kit
   evaluates whether it can work and otherwise keeps a small PSR-18 client), plus a fake SparkPost endpoint so the harness can
   later capture mail through the real transport. Until then adapters/cake5 routes the `sparkpost` transport to MailHog over
   SMTP (test double only, same as adapters/cake3).
   **Result (kit, 104 tests):** `Dms\Kit\Cake\SparkPostTransport` (Cake `AbstractTransport`) over a small PSR-18 client
   (`Mail\SparkPostApiTransport`), `options.click_tracking=false` on every send (tested; enabling it via config throws).
   The official `sparkpost/sparkpost` 2.3.0 package was deliberately NOT used: it only installs next to Cake 5.4 if
   `psr/http-message` is forced to ^1.1 app-wide, needs the abandoned `php-http/message-factory`, and has had no release
   since 2021 (evidence in `calendar-cake5-libkit/tools/evidence/`). It can be swapped in behind `MailTransport` if the
   owner insists. The old plugin sent no `options` at all, so `open_tracking`/`click_tracking` followed SparkPost's account
   defaults (probably on): link rewriting is now off; open tracking is unchanged (unset). Fake endpoint:
   `tests/fakes/fake-sparkpost.mjs` (`/__log`, `/__reset`); wiring it into the harness is later work.
2. **Empty password at login = invalid credentials** (the old app returned a 500). Keep.
3. **Fix the OIDC groups bug** in the Cake 5 port (`fixGroupsBug=true`): OIDC users now get groups and admin menus. This is a
   deliberate deviation from Cake 3 behaviour; there is no OIDC golden to update.

## 10. Decisions and questions for the owner

1. **Option A stopgap (patched PHP 7.4 + Cloudflare-only origin + hardening): schedule it?** Actively exploited CVEs; E2 is months away.
2. Commit the synthetic baselines (and the harness) to git, and when? (Currently nothing is committed; baselines live outside the repo.)
3. Braintree sandbox credentials for the optional sandbox tier?
4. Phase P approval: who takes the snapshot, where it may live, masking policy, retention.
5. Is the tracked `.env` in the calendar repo a real-secret leak? (Not inspected.) Rotate if so.
6. Keep the Bootstrap 3 look in the rebuild, or accept a UI change?
7. Define the E2 "fails" checkpoint (time-box and criteria).

---

## 11. Where things are

**Repo (`calendar/`, uncommitted, branch `baseline/e2e-harness`):**
```
Dockerfile                                  apt security-mirror fix (only app-affecting change)
.gitignore, CLAUDE.md, docs/testing.md      ignore rules, corrected docs, testing guide
e2e/package.json, package-lock.json         adds mysql2 3.24.6, tsx 4.23.15, @types/node 24.19.2 (exact pins)
e2e/playwright.config.ts                    projects: setup, smoke, readonly, visual, crawl, write*, oidc*, har  (*placeholders)
e2e/harness/
  compose.baseline.yml, baseline.env        offline stack + synthetic environment
  adapters/cake3/app.php                    mail overlay (add adapters/cake5/ for the new build)
  fakes/server.mjs                          fake Braintree gateway + webhook sink
  stubs/braintree-v2-stub.js                browser stub for the drop-in v2
  faketime/Dockerfile                       libfaketime built on bullseye
  ldap/99.6-test-roles.ldif, 99.7-test-role-groups.ldif
  scripts/env.sh, build-image.sh, run.sh
e2e/fixtures/anchor.ts, synthetic/catalog.ts, synthetic/build-sql.ts
e2e/tests/
  support/ db.ts io.ts dataset.ts global-setup.ts fixtures.ts http.ts
  pages/ components/                        page objects (login, events-index, calendar, event-view, archive, generic, nav)
  setup/ readonly/ visual/ crawl/ har/      specs; crawl/{routes,normalize,golden-crawl.spec}.ts
  data/test-users.ts                        6 role users (+ anonymous)
```
**Outside the repo:** `dms/calendar-baselines/<BASELINE_ID>/` holds `00-schema-lookups.sql`, `10-synthetic.sql`,
`external-assets.har`, `manifest.json`, `snapshots/{readonly,visual,crawl}/...`; `_run-*.log` are run logs;
`_build/` holds build contexts (small, safe to leave).

**Research and planning artefacts** (copied for durability to `dms/calendar-upgrade-research/`; the originals sit in a
session-scoped temp scratchpad that may disappear): `calendar-php-upgrade-options.md` (options + recommendation),
`track1`-`track5` reports (CVEs/PHP, CakePHP paths, dependencies, codebase audit + history, stopgaps/infra),
`plan-baseline-v2-REVIEWED.md` (the executable baseline plan this work follows), `production-baseline-and-e2e-plan.md`
(page inventory, form field inventories, proposed 19 E2E tests, config values), `calendar-php-upgrade-roadmap.mmd`.
The production **screenshots** (member-facing and admin) were *not* copied: they contain real names and your own contact
details. They are in the session scratchpad only; recapture if needed (GET-only, never submit forms or click
Register / Mark Attended / Assign AD Group / Delete).

## 12. How to resume (commands, Git Bash, from `calendar/`)

```bash
. e2e/harness/scripts/env.sh                      # exports REPO, BASEROOT, CALENDAR_IMAGE, BASELINE_ID, ANCHOR; defines $C

e2e/harness/scripts/build-image.sh                # (re)build the image under test from HEAD
docker build -t calbase-faketime:bullseye e2e/harness/faketime        # once
RECORD_HAR=1 e2e/harness/scripts/run.sh --project=har                 # once, needs internet; records external assets

# full run (restarts app + fakes first so "now" re-anchors)
e2e/harness/scripts/run.sh --project=smoke --project=readonly --project=visual --project=crawl --reporter=list
UPDATE=1 e2e/harness/scripts/run.sh --project=crawl                   # re-bless one family ON PURPOSE
SKIP_UP=1 e2e/harness/scripts/run.sh --project=readonly -g "nav as"   # stack already up, subset

$C ps -a                                          # compose status
docker exec calbase-app-1 curl -s http://localhost/events/calendar    # poke the app from inside
```
Never run two `run.sh` invocations at once: each resets the shared database.

**Safety scan (plan step 4.4)** - run on the final tree; any hit means stop:
```bash
PAT='dallasmakerspace|\b\d{1,3}(\.\d{1,3}){3}\b|@(?!example\.(test|org)\b|dms\.local\b)[a-z0-9-]+\.[a-z]'
git diff -U0 | grep -nP "^\+(?!\+\+).*($PAT)"
git ls-files --others --exclude-standard -z -- . ':!docs/rfcs' ':!bash.exe.stackdump' | xargs -0 -r grep -nIP "$PAT"
# expected benign hits only: 127.0.0.1 / 0.0.0.0, and `dummy-secret-for-dev-mode` from the dev stack
```

## 13. Gotchas learned the hard way

1. **Git Bash + Windows tools:** with `MSYS_NO_PATHCONV=1`, Windows `git.exe`/`docker`/`tar` need `C:/...` paths. Use `pwd -W`; `tar` needs
   `--force-local` (it reads `C:` as a remote host). Never redirect SQL/binary with PowerShell `>`.
2. **Do not leave your shell's cwd inside `e2e/test-results/...`**: Windows blocks Playwright from clearing its own output dir
   (`EACCES ... rmdir`) and every run fails instantly with no test output.
3. **libfaketime `@` semantics:** every *new process* starts at the anchor and ticks from there (long-lived Apache ticks from
   its start). `docker exec php -r date` always shows the anchor. Restart `app` (then `fakes`, which shares its network
   namespace) before each suite run - `run.sh` does.
4. **LDAP bootstrap timing and file rules:** the loader treats a file as all-adds *or* all-modifies (so users and group
   modifications are separate files), and the seed groups load before the overlay - wait until all 9 users exist before testing.
   The app resolves roles via each **group's `member` attribute**, not the user's `memberOf`.
5. **Logging out a shared `storageState` user destroys the session for every spec** that uses it. The logout test does its own login.
6. **Auto-created contacts:** the first login of a user creates a `contacts` row; parallel logins race for ids. The `setup`
   project is serial and in fixed order on purpose.
7. **Ties are nondeterministic:** list pages order by `event_start` / `created`; identical values make order (and goldens) flip
   between runs. All fixture timestamps are unique; keep it that way when adding rows.
8. **Normalise, don't loosen:** `FormHelper::postLink` form names are random (`post_<id>`), ICS `DTSTAMP` and the RSS channel
   `pubDate` are "now", CSRF/`_Token`/`edit_key`/Braintree client token vary per request. All handled in `crawl/normalize.ts` (pure).
9. **Re-bless after any dataset change** (readonly text goldens, visual, crawl all depend on it), then run the 3-run stability gate.
10. Spec rules: the production 404 page prints the server time (masked in screenshots); `My Account`/`Popular Links` are `<a data-toggle="dropdown">`,
    not buttons; the calendar prev/next arrows are icon-only (need the Font Awesome HAR to be clickable); event cells carry the
    name in `title`, with the detail link in a popover.
11. **Database is utf8mb3**: 4-byte emoji cannot be stored (a characterisation, not a fixture bug). The production charset is unconfirmed.
12. mailhog, osixia/openldap and Keycloak images are pinned in the harness; the dev compose uses `:latest`. Do not "fix" the harness to match.
14. **Flash messages leak through shared sessions.** The server keeps queued flash messages ("You are not authorized to
    access that location.") in the session. A spec that deliberately provokes failures (the crawl visiting admin routes as a
    member, the member-on-admin-pages test) leaves them queued, and whichever spec renders a page next with the SAME saved
    session shows them - nondeterministically, because projects run in parallel. Fix in place: `authFile(role, ns)` gives
    the crawl (`'crawl'`) and the denied-access test (`'denied'`) their own logins (created serially by the `setup`
    project). Any new spec that provokes failures needs its own namespace; any spec that only views pages uses the shared one.
13. A Java process holds host ports 8000/8081; the dev `docker-compose.yml` (ports 80/443/389/636/8000/8080/8081/8888) will collide with it.
    The harness deliberately avoids all of those.
