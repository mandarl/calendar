# Shared brief for every Cake 5 port agent (read fully before starting)

You are one engineer on a team porting the DMS calendar app (CakePHP 3.10.4 / PHP 7.4) to **CakePHP 5.4 on PHP 8.4**.
Several agents work in parallel on different slices. The foundation is done; you implement ONE slice.

## Read first
1. `C:\Users\mandarlimaye\src\dms\calendar-cake5-workbreakdown.md` - build/test steps, gotchas (17 of them: read ALL), your slice, dependencies.
2. `C:\Users\mandarlimaye\src\dms\calendar-cake5-foundation-report.md` - what the foundation build does against the baseline, test by test.
3. `C:\Users\mandarlimaye\src\dms\calendar-cake5-upgrade-plan.md` - decisions, rules, findings (sections 2, 5, 9b, 13).
4. `C:\Users\mandarlimaye\src\dms\calendar-cake5-libkit\README.md` - tested replacements (Braintree 6, Twilio 8, LdapRecord 3.8, OIDC, FeedWriter, SparkPost transport) already copied under `src/Kit` in the port.
5. The OLD (Cake 3) source of your slice in `C:\Users\mandarlimaye\src\dms\calendar` (read only), and `docs/testing.md` there for how the baseline works.

## Owner decisions that apply (do not revisit)
- Mail = SparkPost API through the kit transport, **click tracking OFF** (already enforced by the transport). Empty password at login = invalid credentials. OIDC groups bug is FIXED in the port. `events.cost` stays a float. Bootstrap 3 look is kept (no redesign). Cake 3 behaviour is preserved, **including its bugs** (plan section 5), unless a decision above says otherwise. Mark every other deliberate deviation with a comment and list it in your report.

## Working-copy protocol (this prevents agents from breaking each other's builds)
- Never edit the integration tree `C:\Users\mandarlimaye\src\dms\calendar-cake5`. It is the merge target (the lead merges slices into it).
- Make your OWN working copy of that tree, WITHOUT its `.git` file (a copied `.git` would corrupt the shared worktree metadata):
  ```bash
  export MSYS_NO_PATHCONV=1
  SRC=/c/Users/mandarlimaye/src/dms/calendar-cake5 ; DST=/c/Users/mandarlimaye/src/dms/calendar-cake5-<slice>
  mkdir -p "$DST" && tar --force-local --exclude=./.git -cf - -C "$SRC" . | tar --force-local -xf - -C "$DST"
  ```
  (the app's vendor directory lives in a Docker volume, not in the tree, so this is small). Work only in `calendar-cake5-<slice>`.
- Use your OWN names so you never collide: Docker volume `calc5<slice>-vendor` (instead of `calcake5-vendor`), image tag `calendar-cake5:<slice>`, and harness `COMPOSE_PROJECT=calc5<slice>`. Never touch containers, volumes or images that belong to someone else (`calbase*`, `calcake5*`, `calc5*` other than yours, `calwt*`). Never run `docker system prune` or any bulk prune.
- Edit ONLY the files your slice owns (listed in your prompt). If you need a change in a file you do not own, do NOT edit it: put the exact hunk (file, old text, new text, why) in your report. If a non-owned file is broken in your copy in a way that blocks you, make the minimum local fix, mark it `// LOCAL-ONLY (not owned)` and list it.
- Run the harness from the ORIGINAL checkout `C:\Users\mandarlimaye\src\dms\calendar` with `ADAPTER=cake5 CALENDAR_IMAGE=calendar-cake5:<slice> BASELINE_ID=cake3-1a086bead5-synthetic-v1 COMPOSE_PROJECT=<yours>` (see the work breakdown section 2). Build the image from YOUR copy: `docker build --target production -t calendar-cake5:<slice> .` in your copy dir. Never `UPDATE=1` (the runner refuses it). Never overwrite anything in `C:\Users\mandarlimaye\src\dms\calendar-baselines\`. Do not edit the harness, fixtures or specs (propose changes in the report).
- Foundation reference numbers (whole port, before your slice): readonly 73/74, visual 40/46, crawl 0/7 roles. Report yours the same way and say which previously failing routes/tests your slice turned green and which differences remain and why.

## Rules (violating one means STOP and report)
1. No contact with production (host, DB, Keycloak, AD, Braintree, Twilio, SparkPost, live site). Everything runs in the harness/Docker. Downloading packages and images is fine.
2. No commits, pushes, tags or stashes.
3. No real DMS data in new code, comments, tests or docs (use example.test, Test Instructor A, 555-01xx). Carry existing literals unchanged.
4. No `rm -rf` or recursive forced deletes (shell or scripts). Overwrite in place, create new directories, or remove specific named files.
5. Do not modify the OLD app repo `C:\Users\mandarlimaye\src\dms\calendar` (except where your prompt says), the `dms-ad-openldap` submodule, or other agents' directories.
6. Do not kill processes you did not start (a Java process holds host ports 8000/8081; the harness binds no host ports).
7. Windows/Git Bash: `MSYS_NO_PATHCONV=1`; native tools need `C:/...` paths (`pwd -W`); `tar --force-local`; never edit PHP namespaces through sed (backslashes get eaten: use the editor tools or a perl script and re-run `php -l`); keep new files LF; do not leave your shell cwd inside an `e2e/test-results*` folder; one `run.sh` at a time per compose project.
8. Immutable dates are the #1 source of silent bugs (gotcha 4): assign results back; grep your files for `->timezone(`, `->modify(`, `->add*(`, `->sub*(`, `->start*(`, `->end*(`.
9. Behaviour must match Cake 3. **Prefer differential tests for logic**: write a small PHP script that exercises the logic with a table of inputs, run it in the Cake 3 image (`calendar-baseline:1a086bead5`, PHP 7.4, vendor is baked in at `/var/www`; use `docker run --rm --network none -v <script>:/tmp/x.php ... php /tmp/x.php`, bootstrapping Cake 3 as needed) and in your Cake 5 build, and compare the JSON output. Put these under `tests/` of your slice so they can be re-run. A claim of parity needs a measured comparison.
10. Disk and CPU are shared: keep Docker disk use modest, report if free space on C: falls below 8 GB, and do not leave large artefacts.
11. Be honest: separate what you ran from what you inferred; never claim a test passes unless you ran it and saw it pass.

## Deliverables and report (under 700 words) - write them as files, then reply with the summary
- Your slice's code changes in your working copy, `php -l` clean, PHPUnit green (`vendor/bin/phpunit` in the base image), plus the unit/differential tests you added.
- `C:\Users\mandarlimaye\src\dms\calendar-cake5-<slice>-CHANGES.md`: (a) the exact list of files you changed/added/deleted, relative to the tree root, so the lead can merge by copying them; (b) hunks you want in files you do not own; (c) deviations from Cake 3 behaviour with reasons; (d) harness results (readonly/visual/crawl: pass/fail counts and which diffs remain); (e) what you did NOT verify; (f) risks and questions for the owner.
- In the chat reply: the summary of the same, in under 700 words.
