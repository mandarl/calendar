/**
 * Synthetic dataset for the baseline harness. Deterministic, PII-free, expressed relative to the anchor.
 * Rows have FIXED ids (events 1001+, contacts 2001+, registrations 3001+, files 4001+, honoraria 5001+) so
 * tests import them by name: CATALOG.events.paidMembersOnly.id.
 *
 * Lookup tables (categories, rooms, tools, prerequisites, committees, configurations) come from the app's own
 * seeds, frozen in 00-schema-lookups.sql. Ids used below: categories 1=Class 2=Event 3=Fiber Arts,
 * rooms 1=3D Fabrication Room 3=Common Area 4=Conference Room 5=Creative Arts Studio, prerequisites 4=Woodshop
 * Basics, committees 1=Creative Arts.
 *
 * Text uses placeholders only (example.test / Test Instructor X / 555-01xx). 3-byte Unicode is used where
 * non-ASCII matters because the database is utf8mb3 (4-byte emoji cannot be stored; see docs/testing.md).
 */
import { anchorInstant, daysFromAnchor, onChicago, plusMinutes, sql } from '../anchor';

type Row = Record<string, string | number | null>;

const ANCHOR_D = anchorInstant();
const created = sql(plusMinutes(ANCHOR_D, -60 * 24 * 30)); // 30 days before the anchor
const dt = (d: Date) => sql(d);

// ---------------------------------------------------------------- contacts
export const contacts: Row[] = [
  { id: 2001, name: 'Test Instructor A', ad_username: 'user1', email: 'instructor.a@example.test', phone: '555-0101', w9_on_file: 0, blacklisted: 0 },
  { id: 2002, name: 'Test Instructor B', ad_username: 'user3', email: 'instructor.b@example.test', phone: '555-0102', w9_on_file: 0, blacklisted: 0 },
  { id: 2003, name: 'Test Instructor C', ad_username: 'cal-admin', email: 'instructor.c@example.test', phone: '555-0103', w9_on_file: 0, blacklisted: 0 },
  { id: 2004, name: 'Test Instructor D', ad_username: null, email: 'instructor.d@example.test', phone: '555-0104', w9_on_file: 1, blacklisted: 0 },
].map((c, i) => {
  const t = sql(plusMinutes(ANCHOR_D, -60 * 24 * 30 + i)); // unique per contact
  return { ...c, created: t, modified: t };
});

// ---------------------------------------------------------------- events
interface EventSpec {
  id: number;
  key: string;
  name: string;
  start: Date;
  durationMin?: number;
  cost?: number;
  free?: number;
  paid?: number;
  status?: string;
  /** hours before start that attendees may cancel (default 24) */
  cancelBeforeH?: number;
  extra?: Row;
  categories?: number[];
  tools?: number[];
}

const specs: EventSpec[] = [];
const ev = (s: EventSpec) => { specs.push(s); };

const at = daysFromAnchor;
const LONG_HTML =
  '<p>This description has <strong>bold</strong>, <em>italic</em> and a <a href="https://example.org/info">link</a>.</p>' +
  '<ul><li>First item</li><li>Second item with &amp; entity</li></ul>' +
  '<p>' + 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. '.repeat(12) + '</p>';

// Registration types
ev({ id: 1001, key: 'freeOpen', name: 'Fixture: Free Open Class', start: at(3, '18:00'), free: 10, paid: 0, categories: [1], extra: { room_id: 3, contact_id: 2001, created_by: 'user1' } });
ev({ id: 1002, key: 'freeMembersOnly', name: 'Fixture: Free Members-Only Meetup', start: at(4, '19:00'), free: 12, paid: 0, categories: [2], extra: { members_only: 1, room_id: 4, contact_id: 2002, created_by: 'user3' } });
ev({ id: 1003, key: 'paidMembersOnly', name: 'Fixture: Paid $5 Members-Only Workshop', start: at(5, '18:30'), cost: 5, free: 0, paid: 8, categories: [1, 3], tools: [1, 2], extra: { members_only: 1, room_id: 5, contact_id: 2001, created_by: 'user1', advisories: 'Wear closed-toe shoes. Aprons are provided.' } });
ev({ id: 1004, key: 'paid45', name: 'Fixture: Paid $45 Class', start: at(6, '10:00'), durationMin: 240, cost: 45, free: 0, paid: 6, categories: [1], extra: { members_only: 1, room_id: 1, contact_id: 2002, created_by: 'user3' } });
ev({ id: 1005, key: 'paidAndFree', name: 'Fixture: Paid And Free Seats', start: at(7, '17:00'), cost: 10, free: 5, paid: 5, categories: [1], extra: { room_id: 3, contact_id: 2003, created_by: 'cal-admin' } });
ev({ id: 1006, key: 'eventbriteOpen', name: 'Fixture: Eventbrite Open Class', start: at(8, '18:00'), cost: 30, free: 0, paid: 0, categories: [1], extra: { eventbrite_link: 'https://example.org/eventbrite/open', room_id: 5, contact_id: 2001, created_by: 'user1' } });

// Closed registration (start already passed today, so the cancel cutoff is behind us)
ev({ id: 1007, key: 'eventbriteClosed', name: 'Fixture: Eventbrite Registration Closed', start: at(0, '09:00'), durationMin: 420, cost: 25, free: 0, paid: 0, cancelBeforeH: 0, categories: [1], extra: { eventbrite_link: 'https://example.org/eventbrite/closed', room_id: 1, contact_id: 2002, created_by: 'user3' } });
// NOTE: start times and `created` values must be UNIQUE across events; list pages order by them and a tie would make
// the order (and every golden) depend on the database's arbitrary tie-breaking.
ev({ id: 1008, key: 'registrationClosed', name: 'Fixture: DMS-Paid Registration Closed', start: at(0, '09:30'), durationMin: 420, cost: 10, free: 0, paid: 6, cancelBeforeH: 0, categories: [1], extra: { members_only: 1, room_id: 5, contact_id: 2001, created_by: 'user1' } });

// Restrictions
ev({ id: 1009, key: 'ageRestricted', name: 'Fixture: Age-Restricted Class (18+)', start: at(9, '19:00'), free: 8, paid: 0, categories: [1], extra: { age_restriction: 18, room_id: 3, contact_id: 2003, created_by: 'cal-admin' } });
ev({ id: 1010, key: 'requiresPrerequisite', name: 'Fixture: Requires Woodshop Basics', start: at(10, '18:00'), free: 8, paid: 0, categories: [1], extra: { requires_prerequisite_id: 4, room_id: 4, contact_id: 2001, created_by: 'user1' } });
ev({ id: 1011, key: 'fulfillsPrerequisite', name: 'Fixture: Fulfills Woodshop Basics', start: at(11, '18:00'), free: 8, paid: 0, categories: [1], extra: { fulfills_prerequisite_id: 4, room_id: 4, contact_id: 2001, created_by: 'user1' } });

// Multi-date series (parent + 2 parts)
ev({ id: 1012, key: 'seriesParent', name: 'Fixture: Three-Part Series', start: at(12, '18:00'), free: 10, paid: 0, categories: [1], extra: { multipart_event: 1, room_id: 5, contact_id: 2002, created_by: 'user3' } });
ev({ id: 1013, key: 'seriesPart2', name: 'Fixture: Three-Part Series', start: at(19, '18:00'), free: 10, paid: 0, categories: [1], extra: { part_of_id: 1012, room_id: 5, contact_id: 2002, created_by: 'user3' } });
ev({ id: 1014, key: 'seriesPart3', name: 'Fixture: Three-Part Series', start: at(26, '18:00'), free: 10, paid: 0, categories: [1], extra: { part_of_id: 1012, room_id: 5, contact_id: 2002, created_by: 'user3' } });

// Status variants
ev({ id: 1015, key: 'pendingFresh', name: 'Fixture: Pending Event (fresh)', start: at(13, '18:00'), free: 6, paid: 0, status: 'pending', categories: [1], extra: { room_id: 3, contact_id: 2001, created_by: 'user1', created: dt(plusMinutes(ANCHOR_D, -60 * 24)) } });
ev({ id: 1016, key: 'rejected', name: 'Fixture: Rejected Event', start: at(14, '18:00'), free: 6, paid: 0, status: 'rejected', categories: [1], extra: { room_id: 3, contact_id: 2003, created_by: 'cal-admin', rejected_by: 'cal-admin', rejection_reason: 'Fixture rejection reason.' } });
ev({ id: 1017, key: 'cancelled', name: 'Fixture: Cancelled Event', start: at(15, '18:00'), free: 6, paid: 0, status: 'cancelled', categories: [1], extra: { room_id: 3, contact_id: 2001, created_by: 'user1' } });
ev({ id: 1018, key: 'completed', name: 'Fixture: Completed Event', start: at(-5, '18:00'), free: 6, paid: 0, status: 'completed', categories: [1], extra: { room_id: 3, contact_id: 2001, created_by: 'user1' } });

// Legacy / edge dates
ev({ id: 1020, key: 'preCutoff', name: 'Fixture: Pre-2017 Event', start: onChicago('2016-06-15', '18:00'), free: 6, paid: 0, status: 'completed', categories: [2], extra: { room_id: 3, contact_id: 2001, created_by: 'user1' } });
ev({ id: 1021, key: 'dstSpringForward', name: 'Fixture: DST Spring-Forward Day', start: onChicago('2031-03-09', '09:00'), durationMin: 120, free: 6, paid: 0, categories: [2], extra: { room_id: 3, contact_id: 2001, created_by: 'user1' } });
ev({ id: 1022, key: 'dstFallBack', name: 'Fixture: DST Fall-Back Day', start: onChicago('2031-11-02', '08:00'), durationMin: 120, free: 6, paid: 0, categories: [2], extra: { room_id: 3, contact_id: 2001, created_by: 'user1' } });

// Capacity / approval
ev({ id: 1024, key: 'fullEvent', name: 'Fixture: Full Event', start: at(16, '18:00'), free: 2, paid: 0, categories: [1], extra: { room_id: 3, contact_id: 2002, created_by: 'user3' } });
ev({ id: 1025, key: 'approvalRequired', name: 'Fixture: Attendees Require Approval', start: at(17, '18:00'), free: 6, paid: 0, categories: [1], extra: { attendees_require_approval: 1, room_id: 3, contact_id: 2001, created_by: 'user1' } });

// Honorarium, bookings, files, text
ev({ id: 1026, key: 'honorariumRequested', name: 'Fixture: Honorarium Requested', start: at(18, '18:00'), free: 6, paid: 0, status: 'pending', categories: [1], extra: { room_id: 3, contact_id: 2004, created_by: 'user1', created: dt(plusMinutes(ANCHOR_D, -60 * 24)) } });
ev({ id: 1027, key: 'roomToolBooking', name: 'Fixture: Room And Tool Booking', start: at(20, '18:00'), free: 6, paid: 0, categories: [1], tools: [3, 4, 5], extra: { room_id: 1, contact_id: 2001, created_by: 'user1', booking_start: dt(at(20, '17:00')), booking_end: dt(at(20, '21:00')) } });
ev({ id: 1028, key: 'withFiles', name: 'Fixture: Event With Files', start: at(21, '18:00'), free: 6, paid: 0, categories: [1], extra: { room_id: 3, contact_id: 2001, created_by: 'user1' } });
ev({ id: 1029, key: 'unicodeLongHtml', name: 'Fixture: Céramique 日本語 Atelier', start: at(22, '18:00'), free: 6, paid: 0, categories: [1], extra: { room_id: 3, contact_id: 2001, created_by: 'user1', long_description: LONG_HTML, short_description: 'Unicode name and long HTML description (3-byte characters only: utf8mb3).' } });

// cron() states (anchor = 10:00 Chicago; Automatic Approval Time = 3 days, Honoraria Approval Time = 3 days)
ev({ id: 1019, key: 'cronEndedApprovedPaid', name: 'Fixture: Cron Ended Approved (paid, has pending reg)', start: at(-3, '18:00'), cost: 5, free: 0, paid: 4, status: 'approved', categories: [1], extra: { room_id: 3, contact_id: 2001, created_by: 'user1' } });
ev({ id: 1031, key: 'cronCancelReminder', name: 'Fixture: Cron Cancel Reminder Due', start: at(2, '09:00'), free: 6, paid: 0, status: 'approved', cancelBeforeH: 6, categories: [1], extra: { room_id: 3, contact_id: 2001, created_by: 'user1' } });
ev({ id: 1032, key: 'cronStartReminder', name: 'Fixture: Cron Start Reminder Due', start: plusMinutes(ANCHOR_D, 6 * 60), free: 6, paid: 0, status: 'approved', cancelBeforeH: 48, categories: [1], extra: { room_id: 3, contact_id: 2001, created_by: 'user1', cancel_notification: 1 } });
ev({ id: 1033, key: 'cronAutoApprove', name: 'Fixture: Cron Auto-Approve (pending > 3 days)', start: at(23, '18:00'), free: 6, paid: 0, status: 'pending', categories: [1], extra: { room_id: 3, contact_id: 2001, created_by: 'user1', created: dt(plusMinutes(ANCHOR_D, -60 * 24 * 5)) } });
ev({ id: 1034, key: 'cronAutoApproveHonoraria', name: 'Fixture: Cron Auto-Approve Honoraria (pending > 3 days)', start: at(24, '18:00'), free: 6, paid: 0, status: 'pending', categories: [1], extra: { room_id: 3, contact_id: 2004, created_by: 'user1', created: dt(plusMinutes(ANCHOR_D, -60 * 24 * 4)) } });

function buildEvent(s: EventSpec): Row {
  const end = plusMinutes(s.start, s.durationMin ?? 120);
  const cancelBefore = plusMinutes(s.start, -(s.cancelBeforeH ?? 24) * 60);
  const base: Row = {
    id: s.id,
    name: s.name,
    short_description: `Short description for ${s.key}.`,
    long_description: `<p>Long description for ${s.key}.</p>`,
    advisories: null,
    event_start: dt(s.start),
    event_end: dt(end),
    booking_start: dt(plusMinutes(s.start, -30)),
    booking_end: dt(plusMinutes(end, 30)),
    cost: (s.cost ?? 0).toFixed(2),
    eventbrite_link: null,
    free_spaces: s.free ?? 0,
    paid_spaces: s.paid ?? 0,
    members_only: 0,
    age_restriction: 0,
    attendees_require_approval: 0,
    attendee_cancellation: dt(cancelBefore),
    extend_registration: 0,
    class_number: 0,
    sponsored: 0,
    status: s.status ?? 'approved',
    room_id: 3,
    contact_id: 2001,
    fulfills_prerequisite_id: null,
    requires_prerequisite_id: null,
    part_of_id: null,
    copy_of_id: null,
    rejected_by: null,
    rejection_reason: null,
    created_by: 'user1',
    cancel_notification: 0,
    reminder_notification: 0,
    // Unique per event (one minute apart by id) so "Created Recently" ordering is deterministic.
    created: dt(plusMinutes(ANCHOR_D, -60 * 24 * 30 + (s.id - 1000))),
    modified: dt(plusMinutes(ANCHOR_D, -60 * 24 * 30 + (s.id - 1000))),
    notifyInstructorRegistrations: 0,
    notifyInstructorCancellations: 0,
  };
  const merged = { ...base, ...(s.extra ?? {}) } as Row;
  if (!('modified' in (s.extra ?? {}))) merged.modified = merged.created;
  delete (merged as Record<string, unknown>).multipart_event; // not a column; series membership is part_of_id
  return merged;
}

export const events: Row[] = specs.map(buildEvent);
export const categoriesEvents: Row[] = specs.flatMap((s) => (s.categories ?? []).map((c) => ({ category_id: c, event_id: s.id })));
export const eventsTools: Row[] = specs.flatMap((s) => (s.tools ?? []).map((t) => ({ tool_id: t, event_id: s.id })));

// ---------------------------------------------------------------- registrations
// Transaction ids: `-sfs-` => fake gateway reports submitted_for_settlement (cancel => void); others => settled (refund).
let regId = 3000;
const reg = (event_id: number, name: string, status: string, o: Partial<Row> = {}): Row => {
  regId += 1;
  const n = String(regId);
  return {
    id: regId, event_id, type: 'free', name, email: `attendee.${n}@example.test`, phone: `555-02${n.slice(-2)}`,
    ad_username: null, send_text: 0, edit_key: `edit-key-${n}`, status, attended: 0, ad_assigned: 0, transaction_id: null,
    // unique per registration (one minute apart) so attendee lists never rely on tie-breaking
    created: dt(plusMinutes(ANCHOR_D, -60 * 24 + (regId - 3000))), modified: dt(plusMinutes(ANCHOR_D, -60 * 24 + (regId - 3000))), ...o,
  };
};
export const registrations: Row[] = [
  reg(1001, 'Test Attendee 01', 'confirmed', { ad_username: 'user1' }),
  reg(1001, 'Test Attendee 02', 'cancelled'),
  reg(1003, 'Test Attendee 03', 'confirmed', { type: 'paid', transaction_id: 'fx-settled-0001', ad_username: 'user3' }),
  reg(1003, 'Test Attendee 04', 'confirmed', { type: 'paid', transaction_id: 'fx-sfs-0001' }),
  reg(1003, 'Test Attendee 05', 'cancelled', { type: 'paid', transaction_id: 'fx-settled-0002' }),
  reg(1004, 'Test Attendee 06', 'confirmed', { type: 'paid', transaction_id: 'fx-settled-0003' }),
  reg(1024, 'Test Attendee 07', 'confirmed'),
  reg(1024, 'Test Attendee 08', 'confirmed'),
  reg(1025, 'Test Attendee 09', 'pending'),
  reg(1025, 'Test Attendee 10', 'confirmed'),
  reg(1025, 'Test Attendee 11', 'rejected'),
  reg(1019, 'Test Attendee 12', 'pending', { type: 'paid', transaction_id: 'fx-sfs-0002' }),
  reg(1019, 'Test Attendee 13', 'confirmed', { type: 'paid', transaction_id: 'fx-settled-0004' }),
  reg(1018, 'Test Attendee 14', 'confirmed', { attended: 1 }),
  reg(1031, 'Test Attendee 15', 'confirmed'),
  reg(1031, 'Test Attendee 16', 'pending'),
  reg(1032, 'Test Attendee 17', 'confirmed'),
];

// ---------------------------------------------------------------- files, honoraria
const fileTs = (i: number) => sql(plusMinutes(ANCHOR_D, -60 * 24 * 30 + i)); // unique per file
export const files: Row[] = [
  { id: 4001, file: 'public-image.png', dir: 'webroot/files/1028/', type: 'image/png', event_id: 1028, private: 0, created: fileTs(0), modified: fileTs(0) },
  { id: 4002, file: 'private-notes.pdf', dir: 'webroot/files/1028/', type: 'application/pdf', event_id: 1028, private: 1, created: fileTs(1), modified: fileTs(1) },
];
export const honoraria: Row[] = [
  { id: 5001, event_id: 1026, pay_contact: 2004, committee_id: 1, paid: null, created, modified: created },
  { id: 5002, event_id: 1034, pay_contact: 2004, committee_id: 1, paid: null, created, modified: created },
];

// ---------------------------------------------------------------- named handles for tests
const handle = (key: string) => {
  const s = specs.find((x) => x.key === key)!;
  return { id: s.id, name: s.name };
};
export const CATALOG = {
  events: Object.fromEntries(specs.map((s) => [s.key, handle(s.key)])) as Record<string, { id: number; name: string }>,
  contacts: { instructorA: 2001, instructorB: 2002, instructorC: 2003, instructorD: 2004 },
  registrations: { freeConfirmed: 3001, paidConfirmedSettled: 3003, paidConfirmedSfs: 3004, approvalPending: 3009 },
  files: { publicImage: 4001, privateNotes: 4002 },
  lookups: { categoryClass: 1, categoryEvent: 2, categoryFiberArts: 3, roomCommonArea: 3, prerequisiteWoodshop: 4 },
  names: { instructorA: 'Test Instructor A' },
} as const;

/** Tiny valid fixture files (base64), written under /files/<event_id>/ by the reset helper. */
export const FIXTURE_FILES: { eventId: number; name: string; base64: string }[] = [
  { eventId: 1028, name: 'public-image.png', base64: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' },
  { eventId: 1028, name: 'private-notes.pdf', base64: 'JVBERi0xLjQKMSAwIG9iajw8L1R5cGUvQ2F0YWxvZy9QYWdlcyAyIDAgUj4+ZW5kb2JqCjIgMCBvYmo8PC9UeXBlL1BhZ2VzL0tpZHNbMyAwIFJdL0NvdW50IDE+PmVuZG9iagozIDAgb2JqPDwvVHlwZS9QYWdlL01lZGlhQm94WzAgMCAyMDAgMjAwXS9QYXJlbnQgMiAwIFI+PmVuZG9iagp0cmFpbGVyPDwvUm9vdCAxIDAgUj4+CiUlRU9G' },
];
