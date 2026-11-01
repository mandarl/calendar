# Slice cards for the Cake 5 port (one per agent; self-contained so a NEW agent can take over)

Every agent reads, in this order: `calendar-cake5-agent-brief.md` (rules, working-copy protocol), then for wave 2 also
`calendar-cake5-agent-brief-wave2.md`, then `calendar-cake5-workbreakdown.md` (gotchas + the slice text), then this card.
Naming convention per slice `<s>`: working copy `calendar-cake5-<s>`, Docker volume `calc5<s>-vendor`, image
`calendar-cake5:<s>`, harness `COMPOSE_PROJECT=calc5<s>`, report `calendar-cake5-<s>-CHANGES.md`.
Implementation agents use model **haiku** (owner decision, memory `feedback-agent-team-use-haiku`). Slices B, M, AHL and T were
started on the default (sonnet) model before that decision; a resumed agent keeps its model.

Status column = state when all agents were stopped by the 5-hour usage limit (2026-11-01). **No slice has finished or been merged.**
The working copy contains the saved partial work; read its diff against `calendar-cake5` (the integration tree) before continuing.

| Slice | Owns (edit only these) | Status at stop |
| --- | --- | --- |
| **B** models | `src/Model/**`, `tests/Fixture/**`, `tests/TestCase/Model/**` | Partial edits in `calendar-cake5-b` (about 4 paths differ). Was editing model files directly. No differential results reported. |
| **F** view parity | `src/View/**`, `templates/layout/**` (not `layout/email`, not `layout/rss`), `templates/element/**`, `templates/Error/**`, `templates/Pages/**`, `src/Controller/PagesController.php` | Had fixed `postLink`, `_method` and CSRF-autocomplete markup. **Was in the middle of fixing a fatal: its `HtmlHelper::link()` override has the wrong first-parameter type (Cake 5 declares `array|string $title`), so the app fatals on every page in `calendar-cake5-f` until fixed.** (A first F attempt was stopped on purpose and restarted on Haiku.) |
| **M** mail + webhooks | `src/Controller/Component/{Email,Webhook}Component.php`, `templates/email/**`, `templates/layout/email/**`, `tests/TestCase/Controller/Component/**` | About 7 paths differ in `calendar-cake5-m`. Was rewriting the differential-test block to classify known differences. Hunks needed in non-owned files (`AppController::initialize`, `EventsController::initialize`, `config/app.default.php` `Email`/`EmailTransport`) are marked `LOCAL-ONLY`. |
| **AHL** admin CRUD + honoraria + feeds | controllers `{Categories,Committees,Configurations,Contacts,Prerequisites,Rooms,Tools,CalendarAdmin,Logs,Files,Honoraria}Controller.php`; `Concern/EventsHonorariaActionsTrait.php`, `Concern/EventsFeedActionsTrait.php`; `templates/{Categories,Committees,Configurations,Contacts,Prerequisites,Rooms,Tools,CalendarAdmin,Logs,Honoraria}/**`, `templates/Events/{accepted,pending,rejected,upcoming}_honoraria.php`, `export_honoraria.php`, `honoraria.php`, `feed_contents.php`, `templates/layout/rss/**`; their tests | About 10 paths differ in `calendar-cake5-ahl`. Was writing the controller test-support class. Dead scaffolds (`templates/Honorariums/*`, `EventsCategories/*`, `EventsTools/*`, `Events/__add_with_intuit.php`) must be listed, not deleted. |
| **C** event add/edit/copy | `Concern/EventsSubmitActionsTrait.php`, `templates/Events/{add,edit}.php`, `tests/TestCase/Controller/EventsSubmit*`, `tests/differential/events-submit/**` | Barely started (1 path differs in `calendar-cake5-c`); its stack was coming up. |
| **D** moderation | `Concern/EventsModerationActionsTrait.php`, `templates/Events/process_rejection.php`, tests, `tests/differential/events-moderation/**` | About 3 paths differ in `calendar-cake5-d`. Was about to load the Email/Webhook components locally (LOCAL-ONLY) in `EventsController::initialize`. |
| **R** registrations + payments | `src/Controller/RegistrationsController.php`, `templates/Registrations/**`, tests, `tests/differential/registrations/**` (the table incl. `refund()` is B's) | About 4 paths differ in `calendar-cake5-r`; production image built (`calendar-cake5:r`); was about to run the registration visual subset. |
| **X** cron + SMS | `Concern/EventsCronActionsTrait.php`, `tests/TestCase/Controller/EventsCron*`, `tests/differential/cron/**` | No code changes yet in `calendar-cake5-x`; had begun setting up the Cake 3 comparison run. |
| **T** write-path tests (Cake 3 build) | works in `calendar-writetests` (copy of the original repo, project `calwt`); never edits the original | About 11 paths differ in `calendar-writetests`; no `write` goldens exist yet (`snapshots/` has only crawl, readonly, visual). Deliverable `calendar-writetests-CHANGES.md` not written. |

## Card details (goal and acceptance per slice)

**B.** Make every Table/Entity/Behavior behave exactly like Cake 3, by measurement: all validation rules (`notEmptyString` semantics for booleans/ints/dates,
`dateTime/decimal/integer/inList`, the custom closures in `EventsTable` incl. the start-date window and room/tool conflict queries,
`RegistrationsTable` unique/alnum rules), `FriendlyTimeBehavior`/`RelationalTimeBehavior` (immutable dates; they turn posted `m/d/Y g:i A`
America/Chicago strings into UTC and compute booking/cancellation fields), `Event` virtual fields (`diffInDays` sign changed in Chronos 3),
`EventsTable` helpers (`getTotalSpaces/getFilledSpaces/hasFreeSpaces/...`, `isOwnedBy`, `hasHonorarium`), `RegistrationsTable` whole file including
`refund()` through the kit `BraintreePayments::voidOrRefund()` (void if `submitted_for_settlement`, else refund; true when nothing to refund), `FilesTable` (Upload 8),
optional `LogsTable`. Proof: differential tests (a table of valid/invalid inputs, DST days 2031-03-09 and 2031-11-02, boundaries, unicode, 0/false/empty) run in the
Cake 3 image and the Cake 5 build and diffed; PHPUnit 11 model tests; harness readonly/crawl/visual.

**F.** Close every crawl/visual difference that is framework markup: `postLink` forms, `<select>`/date widgets, `FormHelper::end()` token blocks, paginator,
flash, error templates, layout nav per role, footer. Never loosen the normaliser or routes; propose normaliser changes in the report only.

**M.** All 14 `EmailComponent::send*` methods and `WebhookComponent` produce identical observable output to Cake 3 (same public method names/signatures; `Mailer`/`Message`;
`catch (Throwable)` around every send; times via `i18nFormat('EEEE MMMM d, h:mma', 'America/Chicago')`; webhook payload, HMAC signature scheme, header and timeout identical).
Transport = kit `Dms\Kit\Cake\SparkPostTransport` (click tracking off). Proof: differential capture of subject/headers/recipients/html/text for each method from both images with the same fixtures
(events 1001/1003/1019/1031/1032, registrations 3001/3003/3012), and webhook body+headers against a local sink; PHPUnit 11 tests; verify a mail reaches MailHog through the cake5 adapter.

**AHL.** A: admin CRUD add/edit/delete round trips (Crud 7; `Contacts::view`, `CalendarAdmin` hard-coded config id 7 / super-config id 1, `Logs` raw `$_GET` filters, `Files::delete`) with PHPUnit integration tests per controller and role.
H: honoraria lists, `exportHonorariaCsv` byte-identical CSV (CsvView 5), `upcomingHonoraria` (fix `new Time('America/Chicago')` -> `Cake\I18n\DateTime`), `HonorariaController` port or prove unreachable.
L: `feed` (RSS/JSON, atom quirk) via kit `FeedWriter` (golden `/events/feed/rss` byte for byte after normalisation), `ics`, `eventsJson`, `getAddToCalLinks` (keep signature: `Events::view` uses it).

**C.** `EventsSubmitActionsTrait` (add, edit, copy via `?copy=<id>`, multi-date series, honorarium request, uploads, contact with `w9_on_file`) and the two form templates. Request is immutable: build a data array and marshal with named-argument options.
Proof: same multipart POSTs to both stacks, compare DB rows (events, categories_events, events_tools, files, honoraria, contacts), mails, webhooks; validation-error rendering.

**D.** `approve`, `reject`, `processRejection`, `cancel` (refunds via `BraintreePayments`, SMS via kit `TwilioSmsSender`, mails), `attendance`, `assignments` (AD groups via kit `LdapDirectory::addUserToGroup()`). Proof: differential per action x role (admin, owner, other member, anonymous) comparing rows, mails, gateway log, webhook log, LDAP membership, redirect + flash.

**R.** `RegistrationsController` (`event`, `view`, `cancel`, `accept`, `reject`, `isAuthorized()` side effect on `isAdmin`), `templates/Registrations/**` (Braintree Drop-in **v2** script + `var clientToken` unchanged). Preserve the `$$registration` typo behaviour (old line 160). Proof: visual/crawl goldens for registration pages + differential tests for free/paid/full/restricted/approval/cancel (void vs refund)/duplicates.

**X.** `cron()` + `__sendText`, in the old order: complete ended approved events (cancel pending regs with refund + mail), auto-approve pending events (no honorarium: Configurations id 1 days; with honorarium: id 2) + `event.published` webhook, cancellation reminders (`cancel_notification`), start reminders (`reminder_notification`). Fixtures exist for each branch (1019, 1031-1034).
Proof: one `GET /events/cron` on a freshly reset Cake 3 stack vs yours (both restarted so "now" = anchor + minutes), compare rows, mails, gateway, webhook, SMS; second call proves idempotence; PHPUnit per branch.

**T.** Write specs on the Cake 3 build (role/label locators only, Page Objects, own namespaced sessions; goldens only NEW files under `snapshots/write/`; never change existing goldens): W1 add event, W2 approve/reject (mail + webhook goldens), W3 free registration, W4 paid registration (fake gateway log), W5 cancel before/after cutoff (void vs refund), W6 `cron()`, W7 honoraria CSV export, W8 attendance + AD assignment, W9 admin CRUD round trips. Finish with 3 consecutive green full-suite runs (the existing 137 tests unchanged) and `calendar-writetests-CHANGES.md` listing files to copy into the original checkout.
