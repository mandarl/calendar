# Calendar app: PHP2Shell mitigation and PHP/CakePHP upgrade options

Date: 2026-10-31. Scope: the DMS calendar app (CakePHP 3.10.4 on PHP 7.4, `php:7.4-apache` image (mod_php, prefork), Debian 11).
Inputs: five research tracks (CVE facts, CakePHP paths, dependencies, codebase audit, stopgap and infra), plus a coordinator spot-check against primary sources and the repo (section 7).
Placeholders are used for every host, domain and person. "Calendar host" means the single production VM that runs the app.

---

## 1. Decision summary

**Recommendation: do Option A this week, then Option B over about 12 weeks. Keep Option C ready as the fallback.**

- **This week (A):**
  - Harden the PHP and Apache config.
  - Lock the origin down to Cloudflare.
  - Ship a PHP 7.4 image rebuilt from the PHP security team's official backport commit. This is the real fix for CVE-2026-109618 and CVE-2026-109619, and it needs no framework change.
- **Weeks 1 to 12 (B):**
  1. Build the safety net (CI, black-box tests).
  2. Swap the libraries that block PHP 8, while still on 7.4.
  3. Clean up deprecations while still on Cake 3.10.
  4. Upgrade to CakePHP 4.6 while still on PHP 7.4.
  5. Only then change PHP, from 7.4 to 8.4.

  Each step is a separate, rollback-able release. This follows the 2022 lesson: never bundle the framework, the runtime and the infra in one change.
- **Fallback (C):** run Cake 3.10 on PHP 8.4 as a bridge. Triggers:
  - a new PHP CVE lands with no 7.4 backport, or
  - B stalls past the drop-dead date (2027-03-31).

  By then Phase 1 will already have removed C's hard blockers (Braintree and Twilio) and built the black-box tests C depends on.

**Trade-off, stated once:** for about 3 months we accept a PHP 7.4 binary that is patched only for these two CVEs, on an EOL Debian 11 base. In return, the framework migration never becomes an emergency. The exposure in that window is any new PHP or OS CVE, and Decision Point D0 / the C trigger exists to cap it.

---

## 2. Options at a glance

Effort is in person-days (pd) for one engineer who knows the repo. Calendar time assumes about 3 to 4 pd per week of volunteer or part-time capacity.

| | Option | Fixes the 2 CVEs? | Time to protection | Effort | Cash cost | Risk | Verdict |
|---|---|---|---|---|---|---|---|
| **A** | Stopgap: hardening + Cloudflare-only origin + patched-7.4 rebuild | Yes. Config is partial within hours; the rebuild is the full fix within 2 to 5 days | Hours (partial), days (full) | 2 to 4 | $0 | Low to medium | **GO now** |
| **B** | Staged path: 3.10.5, then libs, then deprecation cleanup, then Cake 4.6 on 7.4, then PHP 8.4 | Yes, permanently (supported PHP) | Already covered by A; leaves 7.4 in about 12 weeks | 30 to 50 | $0 | Medium, controlled per stage | **GO, primary** |
| **C** | Cake 3.10 on PHP 8.3/8.4 with `--ignore-platform-req=php` (bridge) | Yes (official PHP release) | 3 to 5 weeks from start (needs Phase 1 first) | 6 to 12 on top of Phase 1 (15 to 27 from scratch) | $0 | Medium-high: unsupported combination, framework deprecations, no PHPUnit on 8.x | **Contingency only** |
| **D** | Paid extended support (Freexian ELTS, TuxCare, HeroDevs/Zend) | Yes, these 2 CVEs plus future 7.4 CVEs while the contract runs | 2 to 6 weeks (procurement plus re-platform) | 2 to 4 plus procurement | Quote-only (unverified); likely four figures per year or more | Low technically; poor value | **NO-GO** (keep Sury as a free backup channel) |
| **E1** | "Big bang": Cake 4.6 + PHP 8.4 in one release under a feature freeze | Yes | 6 to 9 weeks | 25 to 40 | $0 | High: repeats the 2022 bundling | NO-GO (B gives the same end state with smaller blast radii) |
| **E2** | Jump straight to Cake 5.x on PHP 8.4 | Yes | 4 to 6 months | 45 to 70 | $0 | High: Auth rewrite, Mailer, strict types and plugins all at once; no PHP-separable stage | NO-GO now (becomes B Phase 5) |
| **E3** | Retire or replace the app (fold into the Kotlin member portal, or buy SaaS) | Yes (eventually) | 6 months or more | 60 to 150 | Varies | High (scope, data migration, payments) | Strategic question only; not a CVE response |

---

## 3. Option detail

### A. Immediate stopgap: hardening, Cloudflare-only origin, patched-7.4 rebuild

**What it does**

1. **Config hardening (hours).** Ship it with the existing thin derived-image workaround and a compose recreate.
   - `session.upload_progress.enabled=0` in php.ini. This is the confirmed 109619 consumer path and also covers CVE-2026-115560. Set it permanently.
   - `expose_php=Off` and `ServerTokens Prod`. The live site currently advertises `X-Powered-By: PHP/7.4.30` (verified).
   - `post_max_size` down from 41M to 16M to 24M (`upload_max_filesize` is 8M).
   - Apache `LimitRequestBody` matching `post_max_size`, plus `LimitRequestFields` and `LimitRequestFieldSize`.
   - `memory_limit=-1` via `php_admin_value`, only together with a compose `mem_limit`. Never use RLIMIT_AS/DATA.
   - Note: `max_multipart_body_parts` does not exist in 7.4 (it was added in 8.0.28 / 8.1.16 / 8.2.3), so the Apache limits do that job here.
   - Add no `php_*` directives to `.htaccess` (`AllowOverride All` is on).
2. **Origin lockdown (hours).** Allow 80/443 on the calendar host only from Cloudflare's published IP ranges.
   - Do it at the network or security-group layer. Docker-published ports bypass ufw/firewalld INPUT rules; if it has to be host-level, use the `DOCKER-USER` chain.
   - Confirm that no DNS record pointing at the origin is grey-clouded.
   - The Let's Encrypt DNS-01 challenge is unaffected.
3. **Patched PHP 7.4 (2 to 5 days).**
   - Rebuild from php-src commit `712e60f9edfd03f0960fc4fc3c2c375f42b90aa2` on branch `PHP-7.4-CVE-2026-109618-109619` (verified to exist). Use the docker-library configure flags, a multi-stage build that purges the build deps, and an OCI label carrying the SHA.
   - Repoint apt at `archive.debian.org`. This also un-breaks the Dockerfile and the red Playwright CI.
   - Pin `install-php-extensions` to a version instead of `latest`.
   - Track 5 already built this image successfully (PHP 7.4.34-dev, all 6 needed extensions load).
   - Cut over side by side behind Traefik. Test on a header canary, flip, and stop the old container without removing it. Tag a rollback image first.

**What it does not fix**
- PHP 7.4 stays EOL: future PHP CVEs probably get no backport ("minimal mitigation for EOL version").
- Debian 11 gets no OS security fixes (OpenSSL, Apache, libxml) unless you buy ELTS.
- CakePHP 3.x has been EOL since 2022.
- The 2026 advisories in phpseclib, Guzzle 6 and phpcs remain until Phase 1.

**Effort and cost:** 2 to 4 pd, $0.

**Risk**
- The backport has no PHP QA release, and `php -v` cannot prove the fix (it reads 7.4.34-dev), so verify by image label.
- `memory_limit=-1` trades exploit reliability for an availability risk; the container cap bounds it.
- Supply chain is php-src git plus archive.debian.org. Pin the SHA, fetch over HTTPS, and mirror the tarball.

**Rollback**
- `docker start` the stopped old container, or recreate from `calendar-app:rollback-YYYYMMDD`.
- No schema change is involved.
- Sessions are lost on every flip, which is already true of every deploy today.

**DMS lessons that apply**
- Fix the build and deploy path without touching code (lesson 3: isolate variables).
- Diff the live tree against git before rebuilding from git. The 2025 "stash from prod" commits show drift, and a full rebuild would silently drop any uncommitted hand edits.
- Pin floating things (lesson 5).
- Don't iterate on the Dockerfile on master (lesson 7). Use a branch with CI building it.

**Prerequisites**
- None for the config step.
- For the rebuild: the live-vs-git diff, plus a smoke checklist covering login (LDAP and OIDC), event view and edit with an upload, a registration and a Braintree sandbox charge (on staging), an email send, iCal/RSS, and the CSV export.

**Go/no-go**
- GO if the canary passes the smoke checklist, `php -m` lists ldap, intl, zip, pdo_mysql, openssl and sodium, and the image label equals the pinned SHA.
- NO-GO if any integration fails. In that case stay on the config-hardened image and switch to the Sury bullseye `7.4.33-31` packages (Option D-lite) as the alternate patched binary.

### B. Staged migration: Cake 3.10.5, then 4.6 on 7.4, then PHP 8.4 (primary)

**Stages.** Each one is a separate release with its own rollback tag.

| Phase | Content | PHP | Effort | Exit gate |
|---|---|---|---|---|
| 1. Safety net + library swaps | CI green (Playwright, plus a PHPUnit/phpcs job). Black-box characterization tests: an HTTP golden master over all GET routes per role, the authorization matrix (anonymous, member, 4 admin groups), form POST contracts, a frozen-time snapshot of `cron()`, and golden files for the CSV/iCal/RSS exports. Library swaps: Braintree 3.40 to 6.40 (`Braintree_X` to `Braintree\X`, 2 files), Twilio 4.12 to 8.13 (1 function), phpseclib 3.0.57 or later, LdapRecord 2.20.5, openid-connect-php 1.0.2, phpcs 3.13.6 moved to dev, delete phpdotenv, CakePHP 3.10.5. | 7.4 (patched) | 9 to 15 | Tests green on the baseline; Braintree sandbox sale/void/refund pass; SMS send to a test number passes |
| 2. Deprecation cleanup on 3.10 | `errorLevel=E_ALL`. Fix `request->data`/`params`/`query` (56 hits), `passedArgs`, `Form->input` to `control` (193), `allowEmpty`/`notEmpty` (71), `_serialize`, `TableRegistry::get` (27), `newEntity()` with no args, `errors()`, `Type::build`, `config()`. Inline the SparkPost transport into `src/Mailer/Transport` (or switch to SMTP relay). Delete dead templates. | 7.4 | 5 to 8 | Zero deprecations logged in a full test run; golden masters unchanged |
| 3. CakePHP 4.6 on PHP 7.4 | Run `cakephp/upgrade` (needs PHP 8: run it in a throwaway container against a copy). File renames to `templates/`. `EventInterface` signatures. Crud 6, CsvView 4, Upload 7 (check that existing `files.dir` rows still resolve), Migrations 3.x, DebugKit 4, Bake 2, PHPUnit 9.6. **Keep** AuthComponent, Email and SecurityComponent (deprecated but working in 4.x). **Keep Bootstrap 3 markup** (see note). | 7.4 | 12 to 20 | Golden masters equal after normalization; auth matrix identical; 2-week soak with no P1 |
| 4. PHP 8.4 | Move to `php:8.4-apache-trixie` (verified to build). Remove the `session.sid_length` / `sid_bits_per_character` ini lines (they are deprecated in 8.4 and emit pre-request diagnostics, which is the 109619 trigger class). xdebug 3.4. Fix the null-to-internal-function sites (about 33) and the dynamic properties (about 30). | 8.4 | 2 to 4 | Same tests green on 8.4; no new warnings in logs during the canary |
| 5. Later (2027) | Auth to cakephp/authentication + authorization, Email to Mailer, Braintree JS v2 Drop-in to v3 (unsupported around Sept/Oct 2027), then evaluate Cake 5.x before 6.0 ends 4.6 support | 8.4 | 10 to 20 | Separate decision |

**Fixes:** the CVEs, permanently, with a supported PHP through 2028-12-31 (8.4) and a supported framework (4.6). It also clears the dependency advisories.

**Does not fix:** AuthComponent and Mailer debt (Phase 5), or the Bootstrap 3 UI.

**Time to protection:** A already provides protection. B removes the EOL runtime in about 12 weeks.

**Total effort:** 30 to 50 pd for Phases 1 to 4. Cost $0.

**Risk:** medium, but bounded per release.

**Rollback:** previous image tag per phase. There is no schema change in Phases 1 to 4. If Migrations 3 rewrites the phinxlog, take a dump first.

**Lessons that apply:** this is the direct answer to the 2022 fix train.
- 3.6 to 3.10 was bundled with a server migration and containerization. Mail was broken for about 4 months, AD groups broke, and upload paths broke. B isolates each variable.
- Phase 1 gives the integrations that historically broke (email, AD groups, uploads) explicit tests.
- Don't merge the dependabot phpunit-8 branch on Cake 3.

**Why PHP 8.4 and not 8.3:**
- 8.3 security support ends 2027-12-31; 8.4's ends 2028-12-31.
- 8.4 needs only a 2-line ini change, and both images were verified to build.
- 8.2 ends 2026-12-31 and 8.1 is EOL, so skip both. (Track 2's suggested 8.1 hop is obsolete.)

**Bootstrap note:**
- The UI is **Bootstrap 3.3.6** (verified in `webroot`), not 4 as the app's CLAUDE.md says.
- The Cake-4 BootstrapUI line in Track 2 targets Bootstrap 5, which would be a visual rewrite of 77 templates.
- Instead, decouple: either drop the BootstrapUI helpers and give `FormHelper` Bootstrap 3 templates, or pin a Cake-4 BootstrapUI version that still emits BS3/BS4-compatible markup. The BootstrapUI-to-Bootstrap version mapping is unverified.
- Do any visual refresh as its own project.

**Prerequisites:**
- Phase 1 exit gate.
- A named owner with about 3 to 4 pd per week.
- A staging copy with sanitized data.

**Go/no-go:**
- At D1 (end of Phase 1): an owner is committed through Phase 4, and the characterization suite is green. Otherwise go to C.
- Before Phase 3's cutover: golden-master diff is clean, and the auth matrix is byte-identical.

### C. Cake 3.10 on PHP 8.3/8.4 via `--ignore-platform-req=php` (bridge)

**Honest assessment: viable as a bridge, only after Phase 1 of B.**

**Evidence it can work**
- Track 5 installed with the flag on PHP 8.1 and 8.3 against MariaDB.
- Migrations and seeds ran, `bin/cake routes` works, and the login and events pages rendered from real DB queries.
- The app's own code lints clean on 8.1 and 8.5 (Track 4).
- About 90 `E_DEPRECATED` notices per few requests come from the framework (Iterator/ArrayAccess return types). Prod's `error_reporting` already hides them.

**What breaks**
- **Twilio 4.12.1: certain fatal.**
  - `create_function()` at `Services/Twilio/Resource.php:74,93` (verified) runs during resource construction.
  - It sits on the event-cancel SMS path (`EventsController::__sendText`).
  - Fix: Twilio 8.13, which also runs on 7.4. That is Phase 1 work.
- **Braintree 3.40.0: compile fatal, but on a narrower path than the reports imply.**
  - `Digest.php:53-54` uses `$str{$i}` (verified), which is a parse fatal on 8.0 and later.
  - Braintree 3.x autoloads per class (PSR-0/4), and `Digest` is referenced only by `WebhookNotificationGateway`, `WebhookTestingGateway` and `TransparentRedirectGateway` (verified). The app uses none of them (verified: no `Digest`/webhook usage in `src/`).
  - So the sale, void and refund path probably does not hit that fatal. The rest of 3.40 is still untested on 8.x and EOL.
  - Fix: Braintree 6.40, which runs on 7.3 and later. That is Phase 1 work.
- **PHPUnit 6.5.14 cannot run on PHP 8.1 or later** (compile fatal). The regression net on 8.x must therefore be black-box (Playwright or HTTP golden master), which Phase 1 is designed to produce.
- **Declared caps** (laminas-diactoros 1.8 `^7.0`, laminas-zendframework-bridge `<8.2`, twig-view `^7.0`) are bypassed by the flag. Runtime behavior on 8.4 is untested; only 8.1 and 8.3 were smoke-tested.
- **Untested on 8.x:** LDAP/AD login, OIDC, SparkPost, uploads, honoraria, feeds, CSV.

**Fixes:** the CVEs with an official PHP release, plus OS currency (a supported Debian base).

**Does not fix:** dead framework (no worse than today's security posture, since 3.x has had no security fixes since 2022). Deprecations will become errors in PHP 9. You own every compatibility bug.

**Effort:** 6 to 12 pd on top of Phase 1 (Dockerfile on 8.x, ini cleanup, integration QA, deprecation triage). Cost $0.

**Time to protection:** 3 to 5 weeks if started cold. About 1 to 2 weeks if Phase 1 is already done.

**Rollback:** the 7.4-patched image tag. No schema change (Track 5 confirmed migrations are unchanged).

**Lesson:** the 2022 pattern of reactive fixes is the main risk. Do not attempt C before the black-box suite exists.

**Go/no-go**
- GO only if (a) a new PHP CVE has no 7.4 backport, or the patched 7.4 proves unstable, or B misses 2027-03-31, **and** (b) Phase 1 is complete.
- NO-GO as the primary path, because the work ends on an unsupported combination and still requires B afterwards.

### D. Paid extended support

**Channels**
- **Freexian Debian ELTS:** priced per company, quote-only, LTS sponsorship recommended first. Track 5 cites a php7.4 fix `7.4.33-1+deb11u14` (ELA-1853-1) from a secondary source; unverified.
- **TuxCare ELS for PHP, HeroDevs NES (Zend builds), ZendPHP:** quote-only.

**Catch:** all of them ship Debian or RPM packages, not docker-library builds. Using them means re-platforming the image to `debian:11` + apt php7.4 + libapache2-mod-php7.4 (1 to 2 pd), plus procurement (2 to 6 weeks).

**Fixes:** these CVEs plus future 7.4 CVEs while the contract runs. ELTS also gives OS updates.

**Does not fix:** dead framework; it only postpones B.

**Verdict:** NO-GO. Poor value for a volunteer org running one small app, and slower than A.

**Free variant (D-lite):** Sury's `packages.sury.org` bullseye repo reportedly ships patched `7.4.33-31`. It needs the same re-platform, it is a single-maintainer repo, and there is no commitment to future fixes. Keep it as A's backup binary, not as a plan.

**Go/no-go:** reconsider only if DMS separately wants Debian-wide ELTS for other Debian 11 hosts.

### E1. Big bang: Cake 4.6 + PHP 8.4 in one release with a feature freeze

Faster on paper (6 to 9 weeks, 25 to 40 pd), because it skips the separate Phase 2 and Phase 4 releases. But it combines:
- framework,
- runtime,
- 7 plugin majors,
- the SparkPost port,
- 2 payment/SMS SDK rewrites.

That is exactly the 2022 shape that produced months of production bugs, and a regression could come from any of them. It also blocks all feature work during the freeze.

**Verdict:** NO-GO. B reaches the same state, and A already buys the time.

### E2. Direct to CakePHP 5.x

There is no PHP-7.4-compatible intermediate stage, so the CVE pressure would force the whole thing at once. The upgrade tooling expects you to come from the latest 4.x, and AuthComponent, Email, SecurityComponent/RequestHandler, `TableRegistry` and `Cake\Filesystem` are all removed at once. Effort is 45 to 70 pd.

**Verdict:** NO-GO now. It is B Phase 5 (2027), and 4.6 is supported until 6.0.

### E3. Retire or replace

Options are to fold calendar features into the Kotlin member portal (already the actively developed app with Keycloak SSO), or move to a SaaS events platform. Both involve payments, honoraria workflows, AD group sync and data migration: 60 to 150 pd or more.

**Verdict:** a legitimate long-term question for the board, but not a CVE response. Revisit at D3, after Phase 4.

---

## 4. Recommended path and timeline

Week 0 starts Monday 2026-11-02.

| When | Phase | Decision point |
|---|---|---|
| Day 0 to 1 | A1 config hardening + A2 origin lockdown | |
| Day 1 to 5 | A3 patched-7.4 rebuild, canary, cutover | **D0** (day 5): patched build in prod? If not, use the Sury binary (D-lite) and keep the hardening |
| Weeks 1 to 3 | B Phase 1: CI, characterization tests, library swaps (several small releases) | **D1** (end of week 3): owner committed for Phases 2 to 4 and suite green? Yes: continue B. No: start C on PHP 8.4 |
| Weeks 3 to 5 | B Phase 2: deprecation cleanup on 3.10 | |
| Weeks 5 to 10 | B Phase 3: Cake 4.6 on 7.4, then a 2-week soak | **D2** (week 10): golden masters and auth matrix clean, no P1 in soak? Then proceed to PHP |
| Weeks 10 to 12 | B Phase 4: PHP 8.4 (trixie) | **D3** (week 12): retire the 7.4 image; schedule Phase 5 and the Braintree JS v3 work; revisit E3 |
| Any time | Trigger | A new PHP CVE with no 7.4 backport, or the patched build misbehaves: jump to C immediately (if Phase 1 is done), otherwise do Phase 1's Twilio/Braintree swaps first (2 to 3 pd) |
| 2027-03-31 | Drop-dead | Not on PHP 8.x by this date: execute C regardless |

### First 5 concrete actions

1. **Today: config hardening (A1).**
   - Ship a derived image with the ini changes from Option A.
   - Add compose `mem_limit` (recreate only). Use the zero-hang cutover procedure, and tag `rollback-YYYYMMDD` first.
   - Verify that `X-Powered-By` is gone and that an 8 MB upload still works.
2. **Today: origin lockdown (A2).**
   - Find where the calendar host's firewall lives (cloud security group vs. host). This is unverified.
   - Allow 80/443 only from the Cloudflare IP ranges.
   - Confirm every DNS record for the origin is proxied (orange cloud).
   - Check that the Cloudflare managed rule for PHP2Shell is enabled on DMS's plan.
3. **Day 1: baseline drift check.**
   - Diff the code inside the running container against the deploy fork's `master`.
   - Commit any drift before any from-source rebuild, because the rebuild would silently drop it.
4. **Days 1 to 3: Dockerfile branch (A3), built by CI.**
   - archive.debian.org apt sources.
   - Multi-stage compile of php-src `712e60f9edfd03f0960fc4fc3c2c375f42b90aa2` (pinned SHA, OCI label, mirrored tarball).
   - Pinned `install-php-extensions`.
   - This also turns the Playwright workflow green again.
5. **Days 3 to 5: canary and cutover.**
   - Run `app-next` behind a Traefik header-canary router, and run the smoke checklist (LDAP + OIDC login, upload, Braintree sandbox on staging, email, iCal/RSS, CSV).
   - Flip, and `docker stop` (do not `rm`) the old container.
   - Record the image SHA label as proof of the fix.
   - Then open the Phase 1 tickets.

---

## 5. Reconciliation of conflicts between reports

| Topic | Conflict | Resolution |
|---|---|---|
| Proxy topology | The original premise put calendar behind OpenResty | **Settled (user-confirmed): Cloudflare to Traefik container to Calendar container.** The repo agrees: `dms-onprem-nginx` has no calendar vhost; the only calendar rule (staging) was deliberately dropped. `docker-compose.prod.yml` runs its own Traefik publishing 80/443 with Let's Encrypt via Cloudflare DNS-01, and the live response carries `Server: cloudflare`. Blue/green is Traefik-based; nothing to change in OpenResty. Do not move calendar behind OpenResty during this work (isolation). |
| Braintree on PHP 8 | Tracks 3/4: fatal. Track 5: "fine on paper", untested | The `Digest.php` curly-offset fatal is real, but it is only autoloaded by the webhook and transparent-redirect gateways, which the app does not use. Treat 3.40 as unsafe on 8.x anyway and upgrade to 6.40 in Phase 1. |
| Twilio on PHP 8 | Track 5: "fine on paper" | Wrong. `create_function` (verified) means a certain fatal on the SMS path. |
| Templates | App CLAUDE.md: Twig. Track 5 smoke notes: "Twig" | `.ctp` PHP templates (77 files). Twig is only a dev-dependency transitive of Bake 1.x. |
| UI framework | App CLAUDE.md and Track 2: Bootstrap 4 | **Bootstrap 3.3.6** (verified). This changes the BootstrapUI plan (section 3B note). |
| PHP target | Track 2: 8.1 then 8.2/8.3. Track 5: prefer 8.3 | **8.4.** 8.1 is EOL and 8.2 is EOL in 2 months; 8.4 runs a year longer than 8.3 at the cost of a 2-line ini fix. |
| `composer install --no-dev` | Track 3: do it now. Track 5: may break rendering | `Application.php` loads Bake and DebugKit unconditionally. Do `--no-dev` in Phase 1 only together with guarded plugin loading. Not in A. |
| CakePHP 4.4/4.5 min PHP | Track 2: 7.4. Wiki: 7.2 | Irrelevant: both are out of security support since 2026-09-09; the target is 4.6. |
| Option C effort | Track 5: 3 to 8 days | Understated: it assumes Braintree and Twilio already work. Realistic: 6 to 12 pd after Phase 1. |

---

## 6. Facts the recommendation rests on

- CakePHP 3.10.4 requires `php >=5.6.0,<8.0.0` (verified in `vendor/cakephp/cakephp/composer.json:31`). Any PHP 8 step is either a framework major or a platform-requirement override.
- CakePHP 4.6.5 requires `php >=7.4.0,<9`, with PHPUnit `^8.5 || ^9.3` (verified on the 4.6.5 tag). The CakePHP wiki version map lists 4.6 as PHP 7.4 to latest ("PHP 8.5 confirmed"), with security support until 6.0. 4.4 and 4.5 ended on 2026-09-09.
- The php.net 2026-10-21 advisory:
  - fixed releases 8.5.12 / 8.4.27 / 8.3.36 / 8.2.35;
  - "mod_php" is highest risk, with exploitation before app code runs;
  - "minimal source-backports for PHP 7.4, PHP 8.0, and PHP 8.1" exist;
  - config mitigations "do not make an installation safe" (verified).
- The 7.4 backport branch exists. Its top commit is `712e60f9edfd03f0960fc4fc3c2c375f42b90aa2` "Avoid state corruption via bailout" (arnaud-lb, iluuu1994, 2026-10-18). The previous commit dates from 2022 (verified).
- The live site sends `X-Powered-By: PHP/7.4.30` behind Cloudflare (verified). Production is therefore also missing the 7.4.31 to 7.4.33 fixes; the rebuild picks those up too.
- The prod ini has `expose_php = On`, `post_max_size = 41M`, `upload_max_filesize = 8M`, `memory_limit = 128M`, and no `session.upload_progress` setting (so it defaults to On) (verified).

---

## 7. Coordinator verification log

| Claim | Method | Result |
|---|---|---|
| Cake 3.10.4 PHP cap | Read `vendor/cakephp/cakephp/composer.json` | Confirmed `>=5.6.0,<8.0.0` |
| Cake 4.6 supports 7.4 to 8.5 | 4.6.5 composer.json + CakePHP wiki version map | Confirmed (`>=7.4.0,<9`; wiki "8.5 confirmed") |
| PHP 7.4 backport branch/commit | WebFetch of php-src commits page | Confirmed |
| php.net advisory wording | WebFetch php.net/archive/2026.php | Confirmed |
| Calendar behind Traefik + Cloudflare, not OpenResty | Grep of `dms-onprem-nginx` + docs, prod compose, live response headers; then confirmed by the user | Confirmed and settled |
| Twilio `create_function` | Grep of vendor | Confirmed (lines 74, 93) |
| Braintree curly offsets + reachability | Read `Digest.php`, grep of vendor and `src/` | Confirmed fatal; not on the app's code path |
| Bootstrap version | Grep of `webroot` | 3.3.6 |
| Freexian pricing model | WebFetch of the ELTS FAQ | Per company, no public prices; Debian 11 php7.4 ELA not confirmed |

---

## 8. Not verified (treat as assumptions)

1. That the patched 7.4 binary actually resists the exploit. No PoC was run, and the 17-file diff was not reviewed line by line.
2. Where the calendar host's firewall lives (cloud security group vs. host), and whether any grey-clouded record exposes the origin.
3. Whether Cloudflare's managed PHP2Shell rule is active on DMS's plan, and whether custom rules can inspect bodies (secondary report only).
4. Freexian ELTS php7.4 ELA-1853-1, the Sury `7.4.33-31` revision, and TuxCare/HeroDevs/Zend pricing (secondary sources only).
5. Cake 3.10 on PHP 8.x for LDAP, OIDC, Braintree, SparkPost, uploads, feeds and CSV. Smoke-tested only for core pages on 8.1 and 8.3; never on 8.4.
6. The BootstrapUI version-to-Bootstrap-major mapping for Cake 4.
7. The Braintree JS v2 Drop-in deprecation and unsupported dates (sources disagree slightly).
8. The 2026 phpseclib/Guzzle/phpcs advisory IDs (from Track 3; not re-checked).
9. Whether the live container tree matches git (requires host access; action 3).
10. That PHP 7.4 is affected at all. This is inferred from upstream publishing a 7.4 backport; the CVE records list only supported branches.
11. All person-day estimates are judgment, not measurement.
