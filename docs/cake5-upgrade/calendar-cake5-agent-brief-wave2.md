# Wave 2 addendum (read AFTER `calendar-cake5-agent-brief.md`; both apply)

Wave 2 slices are the money / mail / status paths: **C** event add/edit/copy, **D** moderation/cancel/attendance/AD assignment,
**R** registrations and payments, **X** cron and SMS. They are the highest-risk code in the app and the baseline has NO read-path
golden for what they do, so evidence has to come from comparison with the Cake 3 build and from tests you write. You are a
smaller, faster model: compensate with method, not speed.

## Method (mandatory)
1. **Read the whole old code of your slice first** (the Cake 3 trait/controller + its templates) and write down, in your CHANGES
   file, a numbered list of its observable behaviours (inputs -> DB writes, mails, gateway calls, SMS, webhooks, redirects, flash
   messages, authorization outcomes). Port against that list, one behaviour at a time; tick each off with the evidence (test name).
2. **Small steps**: change one method, `php -l`, run the differential/unit test for it, then the next. Never port a whole 500-line
   file blind. Re-read gotchas 4, 5, 6, 7 of the work breakdown before editing (immutable dates, query/ORM named args, immutable
   request, Auth -> Authentication) and grep your files for the patterns listed in brief rule 8.
3. **Differential testing against Cake 3 is the proof** (brief rule 9): drive the same input through the old image
   (`calendar-baseline:1a086bead5`) and your build and compare the OBSERVABLE results: DB rows after the action (dump the touched
   tables ordered by id, normalise ids/timestamps), mails (MailHog), fake gateway log (`GET /__log` on the app's :3000), webhook sink
   log, HTTP status + redirect target + flash text. The fixture ids you need (events 1001-1034, registrations 3001-3017, fake
   transaction ids `fx-sfs-*` = void, others = refund) are in `C:\Users\mandarlimaye\src\dms\calendar\e2e\fixtures\synthetic\catalog.ts`.
   Run the old and new builds as two harness projects (`ADAPTER=cake3` with image `calendar-baseline:1a086bead5`, and `ADAPTER=cake5`
   with yours) and drive requests with a small Node/curl script (log in through `/users/login`; the harness users are
   user1..user3, cal-admin, honor-admin, fin-report, super-admin, user2=all admin groups; password `password`; remember
   CSRF token cookie + `_csrfToken` field, and the `Security` form-protection fields).
4. **Never claim parity you did not measure**; list unverified behaviours explicitly in your CHANGES file.
5. If you are stuck on a subtle semantic for more than a couple of attempts, STOP and report it precisely (what you tried, the
   observed difference) instead of guessing; the lead decides whether to escalate.

## Dependencies on other slices (running in parallel; do not wait idle)
- Slice **B** (models) and slice **M** (mail/webhook components) are still being finished by other agents. Start from the
  integration tree as it is now. The components keep their public method names (`EmailComponent::send*`, `WebhookComponent::*`);
  call them exactly as the old code did. When `C:\Users\mandarlimaye\src\dms\calendar-cake5-m-CHANGES.md` exists, copy the files it
  lists as owned by M from `C:\Users\mandarlimaye\src\dms\calendar-cake5-m\` into your working copy (read-only source; copy, do not
  edit there), and the same for `calendar-cake5-b-CHANGES.md` / `calendar-cake5-b` (models). Check for these files before each
  major test run. Until then, when you need a component or model fix to proceed, make the smallest local fix marked
  `// LOCAL-ONLY (not owned)` and list it.
- The write-path tests for the Cake 3 build (slice T) are being written in `C:\Users\mandarlimaye\src\dms\calendar-writetests`.
  When `C:\Users\mandarlimaye\src\dms\calendar-writetests-CHANGES.md` exists they are finished: run the `write` project against YOUR
  build from that directory (`cd /c/Users/mandarlimaye/src/dms/calendar-writetests` and the same env vars as in the brief with
  `ADAPTER=cake5 CALENDAR_IMAGE=calendar-cake5:<slice> COMPOSE_PROJECT=<yours>`), and treat each failing test in your area as your
  work list. Do not run it while that file does not exist (specs are half-written).

## Extra rules
- Money: never contact a real gateway; the fake gateway is the only one. Mail: only MailHog. SMS: Twilio credentials are empty in the
  harness (`NotConfigured`), so test the SMS code path with the kit's fake HTTP client.
- `GET /events/cron` is anonymous and sends mail/refunds/webhooks: call it ONLY in your own harness project.
- Files you own are listed in your prompt; everything else follows the brief's "do not edit, report the hunk" rule.
