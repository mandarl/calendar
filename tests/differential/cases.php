<?php
/**
 * Shared input table for the Cake 3 vs Cake 5 differential tests (mail + webhook).
 *
 * Must stay PHP 7.4 compatible: the same file is loaded by the Cake 3 baseline image and by the Cake 5 build.
 * All data is synthetic (ids and names follow e2e/fixtures/synthetic/catalog.ts: events 1001/1003/1019/1031/1032,
 * registrations 3001/3003/3012, contact 2001; example.test addresses only). Dates are fixed UTC instants chosen
 * to cross DST edges and midnight/noon so the ICU formatting is exercised.
 */

$contacts = [
    'A' => ['id' => 2001, 'name' => 'Test Instructor A', 'email' => 'instructor.a@example.test'],
    'U' => ['id' => 2002, 'name' => "Test Instruct\u{00F6}r B & <Co>", 'email' => 'instructor.b@example.test'],
    'BAD' => ['id' => 2003, 'name' => 'Test Instructor C', 'email' => 'not-an-address'],
];

$events = [
    // free open class, winter, 6:00pm Chicago
    '1001' => ['id' => 1001, 'name' => 'Fixture: Free Open Class', 'event_start' => '2026-11-04 00:00:00',
        'created' => '2026-10-02 12:30:00', 'contact' => 'A', 'rejection_reason' => null, 'created_by' => 'user1'],
    // dollar sign + members only, half past
    '1003' => ['id' => 1003, 'name' => 'Fixture: Paid $5 Members-Only Workshop', 'event_start' => '2026-11-06 00:30:00',
        'created' => '2026-10-02 23:59:59', 'contact' => 'A', 'rejection_reason' => 'Needs more detail & a <b>room</b> booking.', 'created_by' => 'user1'],
    // summer (CDT), noon Chicago
    '1019' => ['id' => 1019, 'name' => 'Fixture: Cron Ended Approved (paid, has pending reg)', 'event_start' => '2026-07-15 17:00:00',
        'created' => '2026-07-01 00:00:00', 'contact' => 'A', 'rejection_reason' => '', 'created_by' => 'user1'],
    // DST starts: 08:00 UTC is 3:00am CDT; midnight created
    '1031' => ['id' => 1031, 'name' => 'Fixture: Cron Cancel Reminder Due', 'event_start' => '2026-03-08 08:00:00',
        'created' => '2026-03-08 05:59:00', 'contact' => 'U', 'rejection_reason' => "Line one\nLine two", 'created_by' => 'user3'],
    // long, non-ASCII name: subject truncation at 60 bytes cuts inside a multibyte character
    '1032' => ['id' => 1032, 'name' => "Caf\u{00E9} \u{00DC}ber \u{65E5}\u{672C}\u{8A9E} Fixture: Cron Start Reminder Due With A Very Long Name",
        'event_start' => '2026-12-31 05:45:00', 'created' => '2026-12-31 06:00:00', 'contact' => 'A', 'rejection_reason' => null, 'created_by' => 'user1'],
    // DST ends, contact with an unusable address (must be swallowed by the catch, no mail, no exception)
    // one very long unbroken-by-newline reason (1300 chars): exercises the RFC 998-char line wrapping of the html part
    '1050' => ['id' => 1050, 'name' => 'Fixture: Long Reason', 'event_start' => '2026-06-01 15:00:00',
        'created' => '2026-05-01 12:00:00', 'contact' => 'A', 'created_by' => 'user1',
        'rejection_reason' => trim(str_repeat('word ', 260))],
    '1040' => ['id' => 1040, 'name' => 'Fixture: Bad Contact Address', 'event_start' => '2026-11-01 06:30:00',
        'created' => '2026-10-01 12:00:00', 'contact' => 'BAD', 'rejection_reason' => 'x', 'created_by' => 'user1'],
];

$registrations = [
    '3001' => ['id' => 3001, 'name' => 'Test Attendee 01', 'email' => 'attendee.3001@example.test', 'edit_key' => 'edit-key-3001'],
    '3003' => ['id' => 3003, 'name' => 'Test Attendee 03', 'email' => 'attendee.3003@example.test', 'edit_key' => 'edit-key-3003'],
    '3012' => ['id' => 3012, 'name' => "O'Brien \"Q\" <x> & Sons \u{00E9}", 'email' => 'attendee.3012@example.test', 'edit_key' => 'a&b=c d'],
    'BAD' => ['id' => 3099, 'name' => 'Test Attendee 99', 'email' => 'nope', 'edit_key' => 'edit-key-3099'],
];

// method => [kind] where kind is 'contact' (Contact, Event) or 'registration' (Registration, Event)
$methods = [
    'sendEventRejected' => 'contact',
    'sendEventSubmitted' => 'contact',
    'sendEventApproved' => 'contact',
    'sendEventCancelled' => 'registration',
    'sendEventStarting' => 'registration',
    'sendUnapprovedRegistrationCancelled' => 'registration',
    'sendCancellationReminder' => 'registration',
    'sendRegistrationConfirmation' => 'registration',
    'sendRegistrationPending' => 'registration',
    'sendRegistrationRequested' => 'registration',
    'sendRegistrationToInstructor' => 'registration',
    'sendCancellationToInstructor' => 'registration',
    'sendRegistrationApproved' => 'registration',
    'sendRegistrationRejected' => 'registration',
    'sendRegistrationCancelled' => 'registration',
];

// event key => registration key / contact key used with it
$pairs = [
    ['1001', '3001', 'A'],
    ['1050', '3003', 'A'],
    ['1003', '3003', 'A'],
    ['1019', '3012', 'U'],
    ['1031', '3001', 'U'],
    ['1032', '3003', 'A'],
    ['1040', '3001', 'BAD'],
    ['1001', 'BAD', 'A'],
];

$cases = [];
foreach ($methods as $method => $kind) {
    foreach ($pairs as $p) {
        list($ek, $rk, $ck) = $p;
        $cases[] = [
            'name' => $method . ' ev' . $ek . ' reg' . $rk . ' ct' . $ck,
            'method' => $method,
            'kind' => $kind,
            'event' => $events[$ek],
            'registration' => $registrations[$rk],
            'contact' => $contacts[$ck],
        ];
    }
}

return ['cases' => $cases, 'events' => $events, 'registrations' => $registrations, 'contacts' => $contacts];
