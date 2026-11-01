# Calendar -> CakePHP 5 port: work breakdown after the foundation pass

Written 2026-11-01 by the foundation engineer. Companion documents: `calendar-cake5-upgrade-plan.md` (handoff, rules,
findings), `calendar-cake5-foundation-report.md` (what the foundation build does against the baseline, test by test),
`calendar-cake5-libkit/README.md` (the non-Cake library adapters, already adopted under `src/Kit`).

The port lives in the git worktree `C:\Users\mandarlimaye\src\dms\calendar-cake5` (branch `cake5/port`, created from
`1a086bead5`, **nothing committed**). The old app and the e2e harness stay in `C:\Users\mandarlimaye\src\dms\calendar`.

Rules that apply to every slice (same as the handoff, section 2): no production contact; synthetic data only; no commits,
pushes, tags or stashes; no real DMS data in new code/comments/docs (existing literals are carried as they were); no
`rm -rf`; never overwrite the Cake 3 goldens in `calendar-baselines/`; do not edit the harness, fixtures or specs (describe
needed harness changes in your report); preserve behaviour including the bugs of handoff section 5, and mark every
deliberate deviation with a comment and list it in your report.

---

## 1. What the foundation delivered (so you do not redo it)

| Area | State |
| --- | --- |
| Image | `Dockerfile`: `php:8.4-apache-bookworm`, ldap/intl/zip/pdo_mysql, composer 2, targets `base`, `develop` (xdebug), `production` (`composer install --no-dev`, optimized autoload). Same Apache `SetEnv` contract (`.docker/environment.conf`). The tracked `.env` is excluded from the image (`.dockerignore`). |
| Framework | CakePHP **5.4.3**, authentication 4.2, authorization 3.5, migrations 5.2, Crud 7.2, CsvView 5.1, Upload 8.1, bake 3.8 / debug_kit 5.2 / phpunit 11.5 (dev). Bootstrap 3 kept: the BootstrapUI 1.x Form/Paginator helpers are **ported into `src/View`**, BootstrapUI 4/5 is not used. |
| Config | `config/app.default.php` (same env vars), `bootstrap.php`, `routes.php`, `paths.php`. Datasource still `utf8`, UTC; DateTime types write UTC (`bootstrap.php`). `EmailTransport.sparkpost` = kit transport (see slice M). |
| Auth | `App\Identifier\AdIdentifier` (LDAP via kit `LdapDirectory`, StartTLS, groups from each group's `member` attribute, contacts row on first login) behind `Authentication.Form` + `Session`; OIDC via kit `OidcService` (`UsersController::ssoLogin/oidcCallback`, groups fixed on purpose); `App\Policy\RequestPolicy` holds the old `isAuthorized()` rules and is enforced in `AppController::authorizeRequest()`; `UnauthorizedHandler` = old flash + redirect. |
| Pages rendering | `/`, `/events/calendar[/Y/M]`, `/events/embed`, `/events/view/{id}`, `/users/login`, logout, the 404/500 pages, admin index/add/edit pages of the simple CRUD controllers, `/events/all`, `/events/pending`, `/events/submitted`, `/events/attending`, honoraria lists (see the report for exact pass/fail). |
| Models | All Tables/Entities/Behaviors load and the associations are correct. `EventsTable::getSchema()` maps `cost` back to float. Validation is converted mechanically; the custom closures and the two time behaviours are **not reviewed** (slice B). |
| Controllers | `EventsController` is **split into five traits** (`src/Controller/Concern/Events*Trait.php`) so slices own disjoint files. The core file and the small CRUD controllers are ported. The traits and `RegistrationsController`, `HonorariaController`, `FilesController`, `LogsController`, `PagesController` are Cake 3 code with mechanical renames (they parse, they do not work yet). |
| Libraries | `src/Kit` (namespace `Dms\Kit`, copied unchanged from `calendar-cake5-libkit/src`): Braintree 6.40, Twilio 8.13, LdapRecord 3.8.6, openid-connect-php 1.0.2, FeedWriter, SparkPost Cake transport. Adopted so far: `Directory\LdapDirectory`, `Oidc\OidcService`, `Cake\SparkPostTransport` (config only). |
| Tests | PHPUnit 11 runs (9 tests): `RequestPolicyTest`, `OidcGroupsTest`, `SparkPostConfigTest`. The Cake 3 stub tests were moved to `tests/legacy-cake3-stubs/` (dead code, delete when convenient). |
| Harness adapter | `e2e/harness/adapters/cake5/app.php` (mail -> MailHog, session GC off, see gotcha 3). |

---

## 2. Build and test, exact steps (Git Bash on Windows)

```bash
export MSYS_NO_PATHCONV=1
cd /c/Users/mandarlimaye/src/dms/calendar-cake5

# --- fast loop for PHP code (vendor lives in a named volume: the Windows bind mount is far too slow for composer) ---
docker build --target base -t calendar-cake5:base .                 # once
docker volume create calcake5-vendor                                  # once
W=$(pwd -W)
RUN="docker run --rm -v $W:/app -v calcake5-vendor:/app/vendor -w /app calendar-cake5:base"
$RUN composer install --no-scripts                                    # after composer.json/lock changes
$RUN sh -c 'for f in $(find src config -name "*.php" -not -path "config/Migrations/*" -not -path "config/Seeds/*"); do php -l $f | grep -v "^No syntax"; done'
$RUN vendor/bin/phpunit -c phpunit.xml.dist

# --- image under test ---
docker build --target production -t calendar-cake5:dev .             # ~15 s with the dependency layer cached
docker build --target production -t calendar-cake5:<your-slice> .    # use your own tag if you want to keep it

# --- harness (run from the ORIGINAL checkout; your own compose project name!) ---
cd /c/Users/mandarlimaye/src/dms/calendar
export COMPOSE_PROJECT=<yourproject> ADAPTER=cake5 CALENDAR_IMAGE=calendar-cake5:dev \
       BASELINE_ID=cake3-1a086bead5-synthetic-v1
bash e2e/harness/scripts/run.sh --project=smoke --reporter=list
bash e2e/harness/scripts/run.sh --project=readonly --project=crawl --project=visual --reporter=list
SKIP_UP=1 bash e2e/harness/scripts/run.sh --project=readonly -g "event view"   # subset on a running stack
```

* Never `UPDATE=1` (the runner refuses it for `ADAPTER=cake5`). `BASELINE_ID` must stay the Cake 3 one.
* One `run.sh` at a time per compose project (each run resets that project's database). Use a different `COMPOSE_PROJECT`
  from the other agents (`calbase` belongs to the test-writing agent, `calcake5` to the foundation).
* Poking the app by hand: `docker compose -p <proj> ... exec -T app curl -s http://localhost/events/view/1001`;
  errors are in `/var/www/logs/error.log` inside the container (Cake) and `docker logs <proj>-app-1` (Apache/PHP).
  `config/app.php` is created at image build; with no `config/app.php` (unit tests) `config/bootstrap.php` falls back to
  `app.default.php`.
* Crawl/visual diffs: `e2e/test-results-<project>/<test>/…-actual.txt` vs `…-expected.txt`; node snippet to list differing
  routes per role: see the report. Disk: keep an eye on C: (was 20 GB free at the end of the foundation pass).

---

## 3. Gotchas learned in the foundation pass

1. **Global helper functions** (`env()`, `h()`, `__()`) are not autoloaded in Cake 5: `config/bootstrap.php` requires `CAKE . 'functions.php'`. Without it `config/app.default.php` dies with "undefined function env()" (and the adapter too).
2. **Sed/shell and backslashes**: namespaces in PHP edited through the tool shell lose backslashes (`use Cake\Core\...` becomes `CakeCore...`). Edit PHP with a real editor tool or a perl script file using `\x5c`; re-run `php -l` and grep for `use Cake[A-Z]`.
3. **libfaketime vs session GC**: the sidecar is built on Debian 11 (glibc 2.31), the Cake 5 image is Debian 12 (2.36). There PHP's `time()` is faked but **`filemtime()` is not** (verified), so the 1-in-1000 session GC deletes every session file and logs everybody out mid-run (symptom: a burst of visual/crawl failures that show the login page, 1440x900 screenshots). The cake5 adapter turns the GC off for the test stack. Proper fix is a harness change: build `e2e/harness/faketime/Dockerfile` on bookworm. Do not "fix" it in the app.
4. **Immutable dates**: Cake 5 `DateTime` never changes in place. Every `$t->startOfDay()->timezone('UTC')`, `->modify()`, `->addMinutes()`, `->day(1)` etc. in Cake 3 code **must be assigned back**. `Cake\I18n\Time` is now a time-of-day class (`new Time('America/Chicago')` throws): use `Cake\I18n\DateTime`. `FrozenTime`/`FrozenDate` are gone (`DateTime`/`Date`). Chronos 3 `dayOfWeek` is 1 (Mon) to 7 (Sun); Chronos 1 was 0 (Sun) to 6.
5. **Query/ORM**: `->order()`/`->group()` are `orderBy()`/`groupBy()` (already renamed everywhere by sed); `find('list', [...])`/`get($id, [...])` option arrays are named args (`keyField:`, `valueField:`, `contain:`, `fields:`); `newEntity()` without data is `newEmptyEntity()`; `TableRegistry::get` is `$this->fetchTable()`; DECIMAL columns come back as strings (`events.cost` is mapped to float on purpose, only decimal column in the schema); `contain('Rooms')` needs `Events.room_id` in an explicit `select()` (added in the lists).
6. **Request**: `$this->request->data/query/params` and `passedArgs` are gone. Request objects are immutable: code that writes `$this->request->data['x'] = ...` (44 places in `EventsSubmitActionsTrait`) must build a data array and use `withData()`/`withParsedBody()`.
7. **Auth**: `$this->Auth->user()` -> `$this->currentUser()` / `currentUsername()` (AppController helpers); `$this->Auth->allow([...])` -> `$this->Authentication->allowUnauthenticated([...])` in `beforeFilter` after `parent::beforeFilter()`; per-controller `isAuthorized()` is replaced by `RequestPolicy` (add new rules there, with a test). The AppController `isAuthorized()` method now means only "is a Calendar Admin" (the traits call `parent::isAuthorized()`).
8. **Security component** -> `FormProtection` (kept; note the Cake 3 code overwrote its `unlockedActions` for Events, only `exportHonoraria` is unlocked). CSRF is the cookie middleware (`csrfToken` cookie, `_csrfToken` field).
9. **Markup parity**: Cake 3 printed void tags as `<input ... />` and the hidden form-protection block as `<div style="display:none;">`; Cake 5 changed both. `AppView` (Html) and `FormHelper` (Form) restore the Cake 3 templates, and `FormHelper::end()` adds `autocomplete="off"` to the token inputs. `postLink()` forms and `<select>`/date widgets still differ (see crawl diffs).
10. **Flash**: three element styles exist. `flash/{default,error,success,info,warning}.php` = Bootstrap alert with a close button (what `Flash->error()` gave through BootstrapUI); `flash/auth.php` = bare `<div role="alert" class="error">` (what the old AuthComponent produced, used by `UnauthorizedHandler`).
11. **Error pages** are rendered by the framework `ErrorController`, not `AppController`: the header element reads the identity from the session (`Auth`) instead of view vars.
12. **Events/view for a continuation event** (`part_of_id`) now returns a clean 302 (`return $this->redirect()`); Cake 3 also rendered the full page body behind the redirect (crawl shows body 7762 -> 0 bytes for events 1013/1014).
13. **Filtering the home page by room** no longer hides the room name on the matching events (Cake 3 lost the contained room when `matching('Rooms')` was combined with `contain('Rooms')`): `?room=1` lists differ by three lines. Decide: keep the Cake 5 behaviour (owner sign-off) or reproduce the old gap.
14. `composer install --no-scripts` everywhere: the plugin installer's `config/plugins.php` is git-ignored and not relied on (plugins are loaded in `Application::bootstrap()`).
15. `SECURITY_SALT`: `.docker/environment.conf` exports `APP_SECURITY_SALT` but the config has always read `SECURITY_SALT`, so production runs on the public default salt. Carried over unchanged and commented in `app.default.php`; fix deliberately (owner decision).
16. Windows line endings: the checkout has CRLF (`core.autocrlf=true`). The foundation converted every tracked text file in the worktree to LF; keep new files LF or the crawl goldens (which contain template whitespace) will differ.

17. **URL generation no longer inherits the current request's passed arguments.** Cake 3 turned `['controller' => 'Events', 'action' => 'attendance']` into `/events/attendance/1007` on the view page (it reused the current `pass` params); Cake 5 gives `/events/attendance`. Pass ids explicitly (`$event->id`); the same applies to `postLink`/`Form->create(['url' => ...])`/`Html->link` arrays throughout the templates. Likewise unknown array keys are no longer appended to the query string: use `'?' => [...]`.

---

## 4. Slices (each owns a disjoint set of files)

Order: **B and F can start immediately**; M early (others call it); then A, C, D, E, R, S in parallel; X (cron) after M and R; T and Z run throughout. "Tests" = baseline coverage that must stay/turn green; "none" means the baseline has no test and one must be written first (the `write` project, handoff section 7).

Legend for risk: L low, M medium, H high (money, mail, status changes or large untested code).

### Slice B - Models (Tables, Entities, Behaviors)  | risk M | start: now

| Old file (lines) | Cake 5 work |
| --- | --- |
| `Model/Table/EventsTable.php` (561) | Review every `Validator` rule (`dateTime`, `decimal`, `integer`, `inList`; `notEmptyString` replaces `notEmpty` for everything incl. booleans/dates: check `0`/`false` handling), the three custom closures (start-date window uses `Time::isWithinNext($config[3])` with a numeric value: verify the unit; room/tool conflict queries use `$context['data']`), `getTotalSpaces/getFilledSpaces/hasFreeSpaces/hasPaidSpaces/hasOpenSpaces`, `isOwnedBy`, `hasHonorarium`. `RoomDateValidator` import is dead. |
| `RegistrationsTable.php` (183) | validation (`validateUnique` scope, `alphaNumeric`), `refund()` uses `Braintree_*` statics: move to kit `BraintreePayments::voidOrRefund()` (coordinate with slice R, which owns this call site: R edits only `refund()`; B everything else - or give the whole file to R). |
| `ContactsTable` (93), `FilesTable` (87, Upload behaviour config with `{field-value:event_id}`; Upload 8 uses `UploadedFileInterface`), `HonorariaTable` (78), `W9sTable` (85), `Categories/Committees/Configurations/Prerequisites/Rooms/Tools/CalendarSuperConfigurations/CategoriesEvents/EventsTools` tables (52-74 each) | mostly done mechanically; check `buildRules`, `belongsToMany` join table names (`categories_events`, `events_tools`), `hasOne('W9s', dependent)`. `Logs` has no class (auto table); add `LogsTable` if you want `dependent`/validation. |
| `Behavior/FriendlyTimeBehavior` (76), `RelationalTimeBehavior` (95) | convert `Time::createFromFormat`, mutable `addDays/subMinutes` on `$source` to immutable (assign back); `beforeMarshal` signature done. They rewrite posted `event_start/event_end` from `m/d/Y g:i A` Chicago to UTC strings and compute `booking_start/booking_end/attendee_cancellation` from offsets: needs write-path tests. |
| `Entity/Event.php` (129) | `_getCancellationDays/_getSetupTime/_getTeardownTime` built `Time::createFromFormat('Y-m-d H:i:s', $this->event_start)` from a DateTime object (worked through the string cast): rewrite on DateTime objects, check `diffInDays` sign (Chronos 3 is absolute by default). `Contact::_getContactListLabel` done. Other entities are skeletons (done). |

Tests: `readonly` (home, calendar, event-view goldens use every table), `crawl`; model unit tests do not exist (the 3 real PHPUnit methods in the old suite were for the webhook only): write fixtures-based tests in `tests/TestCase/Model`.
Files owned: `src/Model/**`, `tests/Fixture/**`, `tests/TestCase/Model/**`.

### Slice F - View layer parity (helpers, layouts, elements, errors)  | risk M | start: now

Owns `src/View/**`, `templates/layout/**`, `templates/element/**`, `templates/Error/**`, `templates/email/**` is slice M's.
Work: close the remaining crawl body diffs that are framework markup rather than page logic: `postLink()` forms (`<form name="post_..." style="display:none;" ...>` and the `onclick` that submits it), `<select>`/option and date inputs, the `error` template (no `id`), `FormHelper::control` option names, paginator `counter()`, `Html->link` confirm. Template `Pages/home` and `PagesController` (65 lines, skeleton): port to the Cake 5 skeleton or delete.
Decide with the owner whether to extend `crawl/normalize.ts` instead (proposal in the report: the only systematic difference left is HTML5 void-tag style, which is already restored by templates).
Tests: `crawl` (all roles, admin bodies), `visual`.

### Slice A - Admin CRUD controllers and templates  | risk L | after B

| Old files (lines) | Notes |
| --- | --- |
| Controllers: `Categories` 34, `Committees` 32, `Configurations` 32, `Contacts` 62 (custom `view()` using `$event->getSubject()->id`), `Prerequisites` 32, `Rooms` 32, `Tools` 32, `CalendarAdmin` 48 (hard-coded configuration id 7 and super-config id 1), `Logs` 59 (raw `$_GET` filters, `Calendar Super Admins`), `Files` 61 (delete with `request->params['pass'][1]`, Upload behaviour) | already pass through mechanical conversion; verify each action by hand (add/edit/delete round trips are **not** covered by the baseline: write tests). Crud 7 differences: listener/event names unchanged, `Crud.Delete` needs POST. |
| Templates: `Categories|Committees|Prerequisites|Rooms|Tools` (add/edit/index ~15 + 46 lines), `Configurations` (2), `Contacts` (4), `CalendarAdmin/edit` (25), `Logs/index` (67) | `Time->format(..., null, 'America/Chicago')` works through the local TimeHelper; paginate everything with 2147483647 limit (as before). |
| Dead scaffolds: `templates/Honorariums/*` (238), `EventsCategories/*`, `EventsTools/*` (250), `Events/__add_with_intuit.php` (422) | no controller serves them: delete (or confirm with the owner). |

Tests: `readonly/archive-access` (admin indexes), visual `admin-*`, `super-admin-settings`, crawl admin/superAdmin.

### Slice C - Events add / edit / copy / multi-date  | risk H | after B, M

`Concern/EventsSubmitActionsTrait.php` (510 lines: `add`, `edit`, `_formContent`, `_afterCreate`, `_afterUpdate`, `_beforeCreate`, `_beforeUpdate`, `__constructPostForMarshal`, `__constructContinuedEventsForCreate/Update`, `__resetCategorySplits`) + templates `Events/add.php` (434), `Events/edit.php` (529).
Cake 5: replace every `$this->request->data[...] = ...` (44) by building an array and `patchEntity`/`newEntity` options; `newEntity` with `associated` options as named args; `Crud` add/edit `beforeSave`/`afterSave` listeners; the hard-coded address helper `_applyAddress` (core controller) is shared. The AD-group assignment on attendance is slice D, but the contact upload (`W9` file) and `Files` upload (Upload 8) are here. `_afterCreate`/`_afterUpdate` send mail and webhooks: call the mail/webhook interface from slice M.
Tests: **none for writes** (visual `member-add-event`, `admin-event-edit`, crawl `/events/add`, `/events/edit/{1003,1015,1026}` only render the forms). Write W1/W2 first (handoff section 7).

### Slice D - Events moderation, cancel, attendance, AD assignment  | risk H | after B, M

`Concern/EventsModerationActionsTrait.php` (237: `approve`, `reject`, `processRejection`, `cancel`, `attendance`, `assignments`) + `templates/Events/process_rejection.php` (12). `assignments` adds users to AD groups: switch to kit `LdapDirectory::addUserToGroup()` (throws `GroupNotFound`/`DirectoryException`). `cancel` triggers refunds (Braintree, slice R) and SMS (kit `TwilioSmsSender`, one call site) and mail. Authorization rules for these actions already exist in `RequestPolicy` (owner or admin; Honorarium Admins for honorarium events).
Tests: none (W5). Keep `crawl` routes untouched (these are POST/GET-mutating, deny-listed).

### Slice H - Honoraria (lists, CSV export, accept/reject)  | risk M | after B

`Concern/EventsHonorariaActionsTrait.php` (282: `exportHonoraria`, `exportHonorariaCsv`, `pendingHonoraria`, `upcomingHonoraria`, `acceptedHonoraria`, `rejectedHonoraria`), `HonorariaController` (114, plain scaffold using `_serialize`, `newEntity()`: port or confirm it is unreachable), templates `Events/{accepted,pending,rejected,upcoming}_honoraria.php` (49, 71, 42, 8), `Events/export_honoraria.php` (121), `Events/honoraria.php` (86), `Honoraria/*` (169).
Cake 5: CsvView 5 (`viewBuilder()->setClassName('CsvView.Csv')`, option `serialize`, `header`, `extract`); the export action used `$this->set(compact('export', '_serialize'))`; `new Time('America/Chicago')` (a time-of-day class now) crashes `/events/honoraria/upcoming` today.
Tests: visual `honoraria-pending|accepted`; crawl `/events/honoraria/*`, `/honoraria`; CSV export has **no test** (add one).

### Slice L - Feeds, ICS, JSON  | risk M | after B

`Concern/EventsFeedActionsTrait.php` (413: `feed` 220 lines, `ics`, `eventsJson`, `getAddToCalLinks`, `getISO8601Date`, `__icsDate`, `__icsEscapeString`), `templates/Events/feed_contents.php` (61), `layout/rss/default.php` (14). Replace the `FeedIo` block with kit `FeedWriter::toRss/toJson` (golden `/events/feed/rss` matches byte for byte after normalisation per the kit); keep `text/xml` / `application/json` headers and the "atom returns RSS" quirk; `getISO8601Date(FrozenTime)` -> `DateTime`.
Note `getAddToCalLinks` is used by `Events::view` (core): keep its signature.
Tests: crawl `/events/feed/rss`, `/events/ics/{id}`; `/events/view` goldens contain the add-to-calendar links.

### Slice R - Registrations and payments  | risk H | after B, M

`RegistrationsController.php` (360), `RegistrationsTable::refund()`, templates `Registrations/event.php` (125, Braintree Drop-in **v2** script + `var clientToken`), `Registrations/view.php` (99).
Cake 5: kit `BraintreePayments` (client token, `sale(SaleRequest::forRegistration(...))`, `voidOrRefund`): replaces 10+ `Braintree_*` statics and `__configureBraintree`; `isAuthorized()` override must come back in the controller (it sets the `isAdmin` view var as a side effect, see `RequestPolicy::checkRegistrations` comment) and is called explicitly by `cancel()`/`view()`; known bug to preserve: `$$registration` typo (line 160); `Auth->allow(['cancel','event','view'])` -> `allowUnauthenticated`; unauthenticated registrations are identified by `edit_key` in the query string. The harness fake Braintree works with the unmodified SDK 6.40 (kit tests).
Tests: visual `registration-free|paid` (+mobile), crawl `/registrations/event/*`, `/registrations/view/3001`; payments/mail/cancel have **no tests** (W3, W4, W5).

### Slice M - Mail, webhooks (email + webhook components and templates)  | risk H | start early (others depend on it)

`Controller/Component/EmailComponent.php` (531, 14 `send*` methods, hard-coded `setTransport('sparkpost')`, HTML only, `catch Throwable` around sends), `WebhookComponent.php` (145, Guzzle 6 style client, HMAC signature, `dispatchEventPublished`), `templates/email/{html,text}/default.php` (38), `templates/layout/email/*`.
Cake 5: `Cake\Mailer\Email` -> `Cake\Mailer\Mailer` (or `Message`); `setTransport('sparkpost')` -> profile `Email.default` (transport name from `EMAIL_TRANSPORT`, default `sparkpost` = kit `Dms\Kit\Cake\SparkPostTransport`: API key from `SPARKPOST_APIKEY`, `click_tracking=false` enforced, throws `MailException`; keep a `catch (Throwable)` around every send); templates move to `templates/email/html|text/*.php`, layouts to `templates/layout/email/html|text/default.php`; `viewVars` -> `setViewVars`. Keep the component's public method names so slices C, D, R, X call it unchanged; then load it again in `AppController::initialize()` and `EventsController::initialize()` (marked TODO) and re-enable `Webhook`. WebhookComponent: Guzzle 7 client (already in `composer.json`), keep the unit test idea (the old `WebhookComponentTest` is in `tests/legacy-cake3-stubs/`; PHPUnit 11 rewrite).
Harness: mail is captured through the adapter (`sparkpost` -> MailHog SMTP). To exercise the real transport the harness needs the kit's fake SparkPost endpoint (`tests/fakes/fake-sparkpost.mjs`, `GET /__log`, `POST /__reset`) started next to the fake Braintree (same network namespace as `app`), `SPARKPOST_BASE_URL` pointing at it and `SPARKPOST_APIKEY` set in `baseline.env`, and the tests that read MailHog switched to the fake's log: a harness change for the test-writing agent, not part of this port.
Tests: none yet (W2, W3, W6 read mail).

### Slice X - cron, SMS  | risk H | after M and R

`Concern/EventsCronActionsTrait.php` (197: `cron()` 137 lines + `__sendText`). Anonymous `GET /events/cron` sends mail, refunds, SMS and webhooks (never crawl it). SMS: kit `TwilioSmsSender` (`NotConfigured` when `TWILIO_*` are empty, as in the harness). Fixture rows for every branch already exist (ids 1019, 1031-1034). Convert all time arithmetic to immutable (`DateTime`), check `where` comparisons against UTC. The unused `FeedIo`/`LdapRecord` imports in every trait can be removed.
Tests: **none**: write W6 (status transitions, emails, gateway and webhook calls) before changing anything; it is the riskiest path in the app.

### Slice T - Tests and CI  | risk L | throughout

`tests/**` (except the model fixtures, slice B), `phpunit.xml.dist`, `.github/workflows/playwright.yml`, `docs/testing.md` additions. PHPUnit 11 (`Cake\TestSuite\TestCase`, `IntegrationTestTrait`, fixtures `tests/Fixture/*` are Cake 3 style: rewrite). Delete `tests/legacy-cake3-stubs/` once nothing is useful. CI workflow: build with the new Dockerfile; cannot be proven green without a push.

### Slice Z - Docs, ops, cleanup  | risk L | at the end

`CLAUDE.md` (still describes Cake 3), `docs/calendar.md` in the parent working directory, `bin/Installer.php` (Cake 3 composer script, unused), `docker-compose*.yml` (dev still maps `./vendor`), `.docker/prod/*.sh`, production deploy notes (deploy procedure memory `calendar-deploy-procedure`; ship as a side-by-side container behind Traefik, stop - do not remove - the old one), SparkPost API key and `EMAIL_*` documentation, the `SECURITY_SALT` question, rotating the secrets in the tracked `.env`, removing `config/Migrations` seeds that assume Cake 3 only if the owner agrees (they run unchanged with migrations 5, but the schema is frozen: **add no migration that alters it**).

---

## 5. Dependency order (summary)

```
B (models) ----------+--> A (admin CRUD) ----------------------+
F (view parity) -----+--> H (honoraria) -------------------+   |
M (mail/webhook) ----+--> C (add/edit) --+                 |   +--> T (tests, CI) --> Z (docs, cutover)
                     +--> D (moderation) +--> X (cron/SMS) -+   |
                     +--> R (registrations/payments) -----+    |
                     +--> L (feeds) ---------------------------+
```

## 6. Baseline test coverage by area (what turns green when a slice is done)

| Area | readonly | visual | crawl (routes) |
| --- | --- | --- | --- |
| Home list, filters, sort | `home.spec` (list golden, filters, sort) | `home-desktop|mobile` | `/`, `/?type|category|tool|room|sort...` |
| Calendar | `calendar.spec` (month nav, day-view golden) | `calendar-month*` | `/events/calendar[/Y/M[/D]]` |
| Event view (20 variants) | `event-view.spec` (text goldens) | `event-*` (9) | `/events/view/{all ids}` |
| Login, logout, nav per role | `auth-nav.spec` | `login-*` | `/users/login` |
| Archive, pending, my events | `archive-access.spec` | `admin-archive`, `admin-pending`, `member-hosting|attending` | `/events/all|pending|submitted|attending` |
| Admin CRUD pages | `archive-access.spec` (indexes) | `admin-*` | `/categories`... incl. add/edit |
| Add/edit event forms | - | `member-add-event`, `admin-event-edit` | `/events/add`, `/events/edit/*` |
| Registrations | - | `registration-free|paid` | `/registrations/*` |
| Honoraria | - | `honoraria-pending|accepted` | `/events/honoraria/*`, `/honoraria` |
| Super admin | - | `super-admin-settings` | `/calendar-admin/edit` |
| Feeds / ICS | - | - | `/events/feed/rss`, `/events/ics/{id}` |
| cron, mail, webhook, payment, SMS, upload, attendance, AD assignment, CSV export | **no tests** (handoff section 7 write path) | - | - |

## 7. Risks

* **Untested money/mail/status paths** (slices C, D, R, X, M): the baseline cannot catch regressions there. Write the `write` project tests (W1-W6) against the Cake 3 build first; the cake5 build must then pass them.
* **Immutable dates** are the most likely source of silent bugs (wrong timezone in a `where`, a mutated date never assigned back): grep for `->timezone(`, `->modify(`, `->add*(`, `->sub*(`, `->start*(`, `->end*(` in every file you port.
* **`EventsSubmitActionsTrait`** is 510 lines of request-data mutation: highest rewrite cost, no tests today.
* **Braintree Drop-in v2** in the browser is deprecated (separate migration); the harness stub only fakes v2.
* **SparkPost**: the kit transport is unit-tested against a fake endpoint only; confirm the production API key/endpoint (EU vs US `SPARKPOST_BASE_URL`) with the owner before cutover.
* **Production data parity** (Phase P) and the real LDAP/AD attribute casing: the kit and the identifier pass AD attribute names through as LdapRecord returns them (lowercase on the test OpenLDAP; verify with a real AD sample in Phase P).
* **Markup parity** is "equal after the harness normaliser" only where the crawl says so; intentional diffs (list in the report) need owner sign-off.
