// Differential driver for slice C: same requests to Cake 3 and Cake 5 stacks,
// comparing HTTP status, redirect target, flash text, DB rows, mail, webhooks.
// Run on home-hp-desktop with node:
//   DMS=/c/Users/mandarlimaye/src/dms PROJ3=calc5c3 PROJ5=calc5c node diff.mjs [case...]
// DB password is read from e2e/harness/baseline.env at runtime, never printed.
// Both stacks must already be up; this script resets DB, mail and fakes per case.
// Normalisations: DB and mail stamps at the faked anchor plus elapsed minutes
// become <now>; the submission mail clock time becomes <anchor-time>; webhook
// occurred_at becomes <now>; edit_key tokens become <key>.
import { execFileSync, spawnSync } from "node:child_process";
import { writeFileSync, readFileSync } from "node:fs";
const DMS = process.env.DMS || "/c/Users/mandarlimaye/src/dms";
const PROJ3 = process.env.PROJ3 || "calc5c3";
const PROJ5 = process.env.PROJ5 || "calc5c";
const BASE = DMS + "/calendar-baselines/cake3-1a086bead5-synthetic-v1";
const SQL1 = BASE + "/00-schema-lookups.sql";
const SQL2 = BASE + "/10-synthetic.sql";
const OUTDIR = DMS + "/calendar-cake5-c/tests/differential/events-submit/out";
const Q = String.fromCharCode(39);
function sh(cmd) {
  return execFileSync("bash", ["-c", cmd], { encoding: "utf8", maxBuffer: 50 * 1024 * 1024 });
}
function dbPassword() {
  const env = readFileSync(DMS + "/calendar/e2e/harness/baseline.env", "utf8");
  return env.split(/\r?\n/).find((l) => l.startsWith("DB_PASSWORD=")).split("=")[1].trim();
}
function resetStack(proj) {
  const pw = dbPassword();
  const r = spawnSync("bash", ["-c", `cat "${SQL1}" "${SQL2}" | docker exec -i ${proj}-db-1 mysql -u calendar -p${Q}${pw}${Q} dms_calendar`], { encoding: "utf8" });
  if (r.status !== 0) throw new Error("db reset failed for " + proj + ": " + r.stderr);
  sh(`docker exec ${proj}-app-1 curl -s -X DELETE http://mail:8025/api/v1/messages > /dev/null`);
  sh(`docker exec ${proj}-app-1 curl -s -X POST http://localhost:3000/__reset > /dev/null`);
  sh(`docker exec ${proj}-app-1 rm -f /tmp/diff-jar.txt /tmp/diff-resp.html /tmp/diff-get.html`);
}
function hiddenInputs(html) {
  const out = {};
  const re = /<input[^>]*type="hidden"[^>]*>/gi;
  let m;
  while ((m = re.exec(html)) !== null) {
    const tag = m[0];
    const nm = tag.match(/name="([^"]+)"/);
    const vl = tag.match(/value="([^"]*)"/);
    if (nm) out[nm[1]] = vl ? vl[1].replace(/&amp;/g, "&").replace(/&#039;/g, Q) : "";
  }
  return out;
}
function appCurl(proj, args) {
  return sh(`docker exec ${proj}-app-1 curl -s -b /tmp/diff-jar.txt -c /tmp/diff-jar.txt ${args}`);
}
function login(proj, user) {
  const html = appCurl(proj, `http://localhost/users/login`);
  const h = hiddenInputs(html);
  const fields = { ...h, username: user, password: "password" };
  const form = Object.entries(fields).map(([k, v]) => `--data-urlencode ${Q}${k}=${v}${Q}`).join(" ");
  return appCurl(proj, `-o /tmp/diff-login.html -w "%{http_code} %{redirect_url}" http://localhost/users/login ${form}`).trim();
}
function getPage(proj, path) {
  const st = appCurl(proj, `-o /tmp/diff-get.html -w "%{http_code} %{redirect_url}" http://localhost${path}`);
  const body = sh(`docker exec ${proj}-app-1 cat /tmp/diff-get.html`);
  return { statusLine: st.trim(), body };
}
function flashOf(body) {
  const out = [];
  const re = /<div[^>]*class="[^"]*(alert|message|error|success)[^"]*"[^>]*>([\s\S]*?)<\/div>/gi;
  let m;
  while ((m = re.exec(body)) !== null) {
    const t = m[2].replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();
    if (t) out.push(t);
  }
  return out;
}
function toBracket(name) {
  const parts = name.split(".");
  if (parts.length === 1) return name;
  return parts[0] + parts.slice(1).map((p) => `[${p}]`).join("");
}
function formFields(html) {
  // All submittable fields of the rendered form, in document order, as the
  // browser would send them (hidden + visible defaults + checked boxes/radios
  // + selected options). File inputs are skipped; case fields override later.
  const out = [];
  const inputRe = /<input[^>]*>/gi;
  let m;
  while ((m = inputRe.exec(html)) !== null) {
    const tag = m[0];
    const nm = tag.match(/name="([^"]+)"/);
    if (!nm) continue;
    const type = (tag.match(/type="([^"]+)"/) || ["", "text"])[1];
    const vl = tag.match(/value="([^"]*)"/);
    const value = vl ? vl[1].replace(/&amp;/g, "&").replace(/&#039;/g, Q) : "";
    if (type === "submit" || type === "file" || type === "button") continue;
    if (type === "checkbox" || type === "radio") {
      if (/checked/.test(tag)) out.push([nm[1], value]);
    } else {
      out.push([nm[1], value]);
    }
  }
  const selectRe = /<select[^>]*name="([^"]+)"[^>]*>([\s\S]*?)<\/select>/gi;
  while ((m = selectRe.exec(html)) !== null) {
    const name = m[1];
    const opts = [...m[2].matchAll(/<option[^>]*value="([^"]*)"[^>]*>/gi)];
    const sel = [...m[2].matchAll(/<option[^>]*value="([^"]*)"[^>]*selected[^>]*>/gi)];
    if (sel.length) { for (const s of sel) out.push([name, s[1]]); }
    else if (opts.length && !/multiple/.test(m[0])) out.push([name, opts[0][1]]);
  }
  const areaRe = /<textarea[^>]*name="([^"]+)"[^>]*>([\s\S]*?)<\/textarea>/gi;
  while ((m = areaRe.exec(html)) !== null) out.push([m[1], m[2]]);
  return out;
}
function mainFormHtml(html, path) {
  // The edit page carries a second (cancel) form whose _Token is bound to its
  // own URL; Cake 5 validates the token against the POST URL, so the main
  // form (action == POST path, ignoring any query string) must be used alone.
  const want = path.split("?")[0];
  const re = /<form[^>]*action="([^"]+)"[^>]*>([\s\S]*?)<\/form>/gi;
  let m;
  while ((m = re.exec(html)) !== null) {
    if (m[1].split("?")[0] === want) return m[2];
  }
  return html;
}
function submit(proj, path, fields, files) {
  const formHtml = mainFormHtml(appCurl(proj, `http://localhost${path}`), path);
  const parts = [];
  for (const [k, v] of formFields(formHtml)) parts.push(`-F ${Q}${k}=${v}${Q}`);
  // Browsers send every file input as a (possibly empty) file part; omitting
  // them makes Security black-hole the request. Empty parts for all file
  // inputs except any the case itself uploads.
  const uploaded = new Set((files || []).map((f) => toBracket(f.field)));
  const fileRe = /<input[^>]*type="file"[^>]*name="([^"]+)"[^>]*>/gi;
  let fm;
  while ((fm = fileRe.exec(formHtml)) !== null) {
    if (!uploaded.has(fm[1])) parts.push(`-F ${Q}${fm[1]}=@/dev/null;type=application/octet-stream;filename=${Q}`);
  }
  for (const [k, v] of Object.entries(fields)) {
    if (v === undefined || v === null) continue;
    parts.push(`-F ${Q}${toBracket(k)}=${String(v)}${Q}`);
  }
  for (const f of files || []) {
    sh(`docker exec ${proj}-app-1 sh -c ${Q}printf %s "${f.content}" > /tmp/diff-upload.txt${Q}`);
    parts.push(`-F ${Q}${toBracket(f.field)}=@/tmp/diff-upload.txt;type=${f.type};filename=${f.filename}${Q}`);
  }
  const st = appCurl(proj, `-o /tmp/diff-resp.html -w "%{http_code} %{redirect_url}" http://localhost${path} ${parts.join(" ")}`);
  let body = sh(`docker exec ${proj}-app-1 cat /tmp/diff-resp.html`);
  let statusLine = st.trim();
  const loc = statusLine.split(" ").slice(1).join(" ").trim();
  if (statusLine.startsWith("302") && loc) {
    const target = loc.replace(/^http:\/\/localhost/, "");
    const pg = getPage(proj, target);
    body = pg.body;
    statusLine = statusLine + " -> " + pg.statusLine;
  }
  return { statusLine, flash: flashOf(body), bodyHead: body.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim().slice(0, 1000) };
}
function normTime(s) {
  return s.replace(/2031-01-15 1[6-9]:\d\d:\d\d/g, "<now>").replace(/2031-01-16 0[0-2]:\d\d:\d\d/g, "<now>");
}
function dump(proj, table) {
  const pw = dbPassword();
  const q = `SELECT * FROM \`${table}\` ORDER BY 1`;
  const out = sh(`docker exec ${proj}-db-1 mysql -u calendar -p${Q}${pw}${Q} -N -e ${Q}${q}${Q} dms_calendar`);
  return normTime(out);
}
function mail(proj) {
  const raw = sh(`docker exec ${proj}-app-1 curl -s "http://mail:8025/api/v2/messages?limit=500"`);
  const j = JSON.parse(raw);
  return (j.items || []).reverse().map((m) => ({
    from: `${m.From.Mailbox}@${m.From.Domain}`,
    to: (m.To || []).map((t) => `${t.Mailbox}@${t.Domain}`),
    subject: (m.Content.Headers.Subject || [""])[0],
    body: (m.Content.Body || "").replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim().replace(/Submission Time: [A-Za-z]+ [A-Za-z]+ \d+, \d{1,2}:\d\d[AP]M/g, "Submission Time: <anchor-time>").replace(/edit_key=[0-9a-f]{32}/g, "edit_key=<key>"),
  }));
}
function webhooks(proj) {
  const raw = sh(`docker exec ${proj}-app-1 curl -s http://localhost:3000/__log`);
  const j = JSON.parse(raw);
  return j.filter((e) => e.service === "webhook").map((e) => ({ method: e.method, path: e.path, headers: e.headers, body: e.body && typeof e.body === "object" ? { ...e.body, occurred_at: "<now>" } : e.body }));
}
function uploadFiles(proj) {
  try {
    return sh(`docker exec ${proj}-app-1 sh -c ${Q}ls -R /var/www/webroot/files | head -n 100${Q}`);
  } catch (e) { return "ls failed: " + e.message; }
}
const COMMON = {
  name: "Diff Test Class",
  "categories._ids.0": "1",
  short_description: "A class added by the slice C differential.",
  event_start: "02/10/2031 6:00 PM",
  event_end: "02/10/2031 8:00 PM",
  long_description: "Long description for the differential class.",
  sponsored: "0",
  type: "free",
  cost: "0",
  free_spaces: "10",
  paid_spaces: "0",
  attendee_cancellation: "0",
  extend_registration: "0",
  members_only: "0",
  attendees_require_approval: "0",
  age_restriction: "0",
  room_id: "3",
  booking_start: "30",
  booking_end: "30",
  notifyInstructorRegistrations: "0",
  notifyInstructorCancellations: "0",
  request_honorarium: "0",
  class_number: "1",
  status: "pending",
};
const CASES = {
  addFree: { path: "/events/add", user: "user1", fields: { ...COMMON } },
  addFormDefaults: { path: "/events/add", user: "user1", fields: {} },
  addMultipart: { path: "/events/add", user: "user1", fields: { ...COMMON, name: "Diff Test Series", multipart_event: "1", event_start_2: "02/17/2031 6:00 PM", event_end_2: "02/17/2031 8:00 PM" } },
  addSponsoredNew: { path: "/events/add", user: "user1", fields: { ...COMMON, name: "Diff Sponsored New", sponsored: "1", "contact.name": "Diff Instructor", "contact.email": "diff.instructor@example.test", "contact.phone": "555-0199" } },
  addWithFile: { path: "/events/add", user: "user1", fields: { ...COMMON, name: "Diff Class With File" }, files: [{ field: "files.0.file", type: "text/plain", filename: "diff-handout.txt", content: "Differential handout contents." }] },
  addSponsoredExisting: { path: "/events/add", user: "user1", fields: { ...COMMON, name: "Diff Sponsored Existing", sponsored: "1", contact_id: "2004" } },
  addHonorarium: { path: "/events/add", user: "user1", fields: { ...COMMON, name: "Diff Honorarium Class", request_honorarium: "1", "honorarium.committee_id": "1", "honorarium.pay_contact": "1", "honorarium.paid": "0" } },
  addEndBeforeStart: { path: "/events/add", user: "user1", fields: { ...COMMON, event_end: "02/10/2031 5:00 PM" } },
  addHonorariumTooSoon: { path: "/events/add", user: "user1", fields: { ...COMMON, name: "Diff Honorarium Soon", event_start: "01/20/2031 6:00 PM", event_end: "01/20/2031 8:00 PM", request_honorarium: "1", "honorarium.committee_id": "1", "honorarium.pay_contact": "1", "honorarium.paid": "0" } },
  addRoomConflict: { path: "/events/add", user: "user1", fields: { ...COMMON, name: "Diff Room Conflict", event_start: "01/18/2031 7:00 PM", event_end: "01/18/2031 9:00 PM", room_id: "3" } },
  editOwnerTitle: { path: "/events/edit/1001", user: "user1", fields: { name: "Fixture: Free Open Class (diff edited)" } },
  editAdmin: { path: "/events/edit/1003", user: "user2", fields: { name: "Fixture: Paid Workshop (diff edited)", room_id: "3" } },
  editSeriesAdmin: { path: "/events/edit/1012", user: "user2", fields: { name: "Fixture: Three-Part Series", event_start: "01/27/2031 6:00 PM", event_end: "01/27/2031 8:00 PM", event_start_2: "02/03/2031 6:00 PM", event_end_2: "02/03/2031 8:00 PM", event_start_3: "02/10/2031 6:00 PM", event_end_3: "02/10/2031 8:00 PM" } },
  copyOwned: { path: "/events/add?copy=1001", user: "user1", fields: { ...COMMON, name: "Fixture: Free Open Class" } },
  copyWithFiles: { path: "/events/add?copy=1028", user: "user1", fields: { ...COMMON, name: "Fixture: Event With Files" } },
  getCopyUnowned: { getOnly: true, path: "/events/add?copy=1002", user: "user1" },
  getEditContinuation: { getOnly: true, path: "/events/edit/1013", user: "user2" },
  getEditCancelled: { getOnly: true, path: "/events/edit/1017", user: "user2" },
  getAddAnonymous: { getOnly: true, path: "/events/add", user: null },
  getAddMember: { getOnly: true, path: "/events/add", user: "user1" },
  getEditAdmin1003: { getOnly: true, path: "/events/edit/1003", user: "user2" },
};
const TABLES = ["events", "categories_events", "events_tools", "files", "honoraria", "contacts"];
function runOne(proj, c) {
  resetStack(proj);
  let loginLine = "(anonymous)";
  if (c.user) loginLine = login(proj, c.user);
  if (c.getOnly) {
    const pg = getPage(proj, c.path);
    const norm = pg.body.replace(/name="_csrfToken"[^>]*value="[^"]*"/g, `name="_csrfToken" value="<token>"`).replace(/name="_Token\[fields\]"[^>]*value="[^"]*"/g, `name="_Token[fields]" value="<token>"`).replace(/name="_Token\[unlocked\]"[^>]*value="[^"]*"/g, `name="_Token[unlocked]" value="<token>"`).replace(/name="_Token\[debug\]"[^>]*value="[^"]*"/g, `name="_Token[debug]" value="<token>"`);
    const text = pg.body.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();
    const full = norm.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();
    return { login: loginLine, statusLine: pg.statusLine, flash: flashOf(pg.body), bodyTextHead: text.slice(0, 800), bodyTextFull: full, tables: {}, mail: mail(proj), webhooks: webhooks(proj) };
  }
  const r = submit(proj, c.path, c.fields, c.files);
  const tables = {};
  for (const t of TABLES) tables[t] = dump(proj, t);
  return { login: loginLine, statusLine: r.statusLine, flash: r.flash, bodyHead: r.bodyHead, tables, mail: mail(proj), webhooks: webhooks(proj), filesListing: uploadFiles(proj) };
}
const wanted = process.argv.slice(2);
const names = wanted.length ? wanted : Object.keys(CASES);
sh(`mkdir -p "${OUTDIR}"`);
for (const name of names) {
  const c = CASES[name];
  if (!c) { console.log("unknown case " + name); continue; }
  console.log("=== " + name + " (cake3) ===");
  const a = runOne(PROJ3, c);
  console.log("=== " + name + " (cake5) ===");
  const b = runOne(PROJ5, c);
  writeFileSync(`${OUTDIR}/${name}.cake3.json`, JSON.stringify(a, null, 2) + "\n");
  writeFileSync(`${OUTDIR}/${name}.cake5.json`, JSON.stringify(b, null, 2) + "\n");
  const sameStatus = a.statusLine === b.statusLine;
  const sameFlash = JSON.stringify(a.flash) === JSON.stringify(b.flash);
  const sameMail = JSON.stringify(a.mail) === JSON.stringify(b.mail);
  const sameHooks = JSON.stringify(a.webhooks) === JSON.stringify(b.webhooks);
  const sameTables = JSON.stringify(a.tables) === JSON.stringify(b.tables);
  console.log(`${name}: status ${sameStatus ? "SAME" : "DIFF"} flash ${sameFlash ? "SAME" : "DIFF"} mail ${sameMail ? "SAME" : "DIFF"} webhooks ${sameHooks ? "SAME" : "DIFF"} tables ${sameTables ? "SAME" : "DIFF"}`);
  if (!sameStatus) console.log("  cake3 status: " + a.statusLine + " | cake5 status: " + b.statusLine);
  if (!sameFlash) console.log("  cake3 flash: " + JSON.stringify(a.flash) + " | cake5 flash: " + JSON.stringify(b.flash));
  if (!sameMail) console.log("  cake3 mail: " + JSON.stringify(a.mail).slice(0, 1000) + " | cake5 mail: " + JSON.stringify(b.mail).slice(0, 1000));
  if (!sameTables) {
    for (const t of TABLES) {
      if (JSON.stringify((a.tables || {})[t]) !== JSON.stringify((b.tables || {})[t])) console.log("  table " + t + " DIFF");
    }
  }
  if (JSON.stringify(a.bodyTextHead || "") !== JSON.stringify(b.bodyTextHead || "")) console.log("  GET body head DIFF");
  if (JSON.stringify(a.bodyTextFull || "") !== JSON.stringify(b.bodyTextFull || "")) console.log("  GET body full DIFF");
}
