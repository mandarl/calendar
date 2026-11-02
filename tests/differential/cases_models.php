<?php
/**
 * Differential cases: slice B models beyond Events validation.
 * - EventsTable helpers (getTotalSpaces/getFilledSpaces/hasFreeSpaces/hasPaidSpaces/hasOpenSpaces/isOwnedBy/hasHonorarium)
 * - Event virtual fields (cancellation_days/setup_time/teardown_time) from seed rows and constructed entities
 * - FriendlyTimeBehavior::convertToFormat + RelationalTimeBehavior::convertToOffset (objects and locale strings)
 * - RegistrationsTable validation (unique scope, alnum, email, inList, booleans) + save() rules cases (rolled back)
 * - Other tables' validation (Contacts/Honoraria/simple tables/W9s/Files file rules)
 * PHP 7.4 compatible (no named args, no union types, no match, no str_contains).
 * Run through run.sh; compares Cake 3 against the Cake 5 build.
 */
require __DIR__ . '/lib.php';

function dt_class()
{
    return DIFF_CAKE5 ? 'Cake\\I18n\\DateTime' : 'Cake\\I18n\\Time';
}

function mk_dt($str, $tz = 'UTC')
{
    $c = dt_class();
    return new $c($str, $tz);
}

function beh($table, $name)
{
    $t = diff_table($table);
    return $t->behaviors()->get($name);
}

$cases = [];

// ---- 1. EventsTable helpers (seed rows; 1026 has the only honorarium) -------------------------------------------
foreach ([1001, 1003, 1004, 1026, 1027, 1031, 9999] as $id) {
    foreach (['getTotalSpaces', 'getFilledSpaces', 'hasFreeSpaces', 'hasPaidSpaces', 'hasOpenSpaces', 'hasHonorarium'] as $m) {
        $cases["helper:$m:$id"] = function () use ($m, $id) {
            $t = diff_table('Events');
            return ['v' => $t->$m($id)];
        };
    }
    foreach (['user1', 'user3', 'nobody', ''] as $u) {
        $cases["helper:isOwnedBy:$id:" . ($u === '' ? 'empty' : $u)] = function () use ($id, $u) {
            $t = diff_table('Events');
            return ['v' => $t->isOwnedBy($id, $u)];
        };
    }
}

// ---- 2. Event virtual fields --------------------------------------------------------------------------------------
foreach ([1001, 1003, 1031, 1027] as $id) {
    $cases["virtual:seed:$id"] = function () use ($id) {
        $e = diff_table('Events')->get($id);
        return ['cancellation_days' => $e->cancellation_days, 'setup_time' => $e->setup_time, 'teardown_time' => $e->teardown_time];
    };
}
// Constructed entities: sign behaviour of diffInDays/diffInMinutes (cancellation before/after start, booking before/after).
$virt = [
    // start, cancellation, booking_start, booking_end
    'cancel-1d' => ['2031-01-25 00:00:00', '2031-01-24 00:00:00', '2031-01-24 23:30:00', '2031-01-25 02:30:00'],
    'cancel-after' => ['2031-01-25 00:00:00', '2031-01-26 00:00:00', '2031-01-25 00:30:00', '2031-01-25 01:00:00'],
    'cancel-same' => ['2031-01-25 00:00:00', '2031-01-25 00:00:00', '2031-01-25 00:00:00', '2031-01-25 00:00:00'],
    'cancel-36h' => ['2031-01-25 12:00:00', '2031-01-24 00:00:00', '2031-01-25 11:00:00', '2031-01-25 13:00:00'],
    'missing-cancel' => ['2031-01-25 00:00:00', null, '2031-01-24 23:30:00', '2031-01-25 02:30:00'],
    'missing-start' => [null, '2031-01-24 00:00:00', '2031-01-24 23:30:00', '2031-01-25 02:30:00'],
];
foreach ($virt as $vn => $v) {
    $cases["virtual:obj:$vn"] = function () use ($v) {
        $e = new \App\Model\Entity\Event([
            'event_start' => $v[0] === null ? null : mk_dt($v[0]),
            'attendee_cancellation' => $v[1] === null ? null : mk_dt($v[1]),
            'booking_start' => $v[2] === null ? null : mk_dt($v[2]),
            'booking_end' => $v[3] === null ? null : mk_dt($v[3]),
        ]);
        return ['cancellation_days' => $e->cancellation_days, 'setup_time' => $e->setup_time, 'teardown_time' => $e->teardown_time];
    };
    $cases["virtual:str:$vn"] = function () use ($v) {
        $e = new \App\Model\Entity\Event([
            'event_start' => $v[0],
            'attendee_cancellation' => $v[1],
            'booking_start' => $v[2],
            'booking_end' => $v[3],
        ]);
        return ['cancellation_days' => $e->cancellation_days, 'setup_time' => $e->setup_time, 'teardown_time' => $e->teardown_time];
    };
}

// ---- 3. convertToFormat ---------------------------------------------------------------------------------------------
$tof = [
    'utc-midnight' => mk_dt('2031-01-25 00:00:00'),
    'utc-with-seconds' => mk_dt('2031-01-25 00:00:30'),
    'utc-noon' => mk_dt('2031-01-25 12:00:00'),
    'dst-spring' => mk_dt('2031-03-09 08:00:00'),
    'dst-fall' => mk_dt('2031-11-02 07:30:00'),
    'dst-fall-ambiguous' => mk_dt('2031-11-02 06:30:00'),
    'chicago-tz' => mk_dt('2031-01-25 18:00:00', 'America/Chicago'),
    'locale-string' => '01/24/31, 6:00 PM',
    'locale-string-nbsp' => "01/24/31,\xC2\xA06:00 PM",
    'locale-string-nnbsp' => "01/24/31,\xE2\x80\xAF6:00 PM",
    'locale-midnight' => '01/25/31, 12:00 AM',
    'locale-noon' => '01/25/31, 12:00 PM',
];
foreach ($tof as $tn => $tv) {
    $cases["toFormat:$tn"] = function () use ($tv) {
        return ['v' => beh('Events', 'FriendlyTime')->convertToFormat($tv)];
    };
}

// ---- 4. convertToOffset -----------------------------------------------------------------------------------------------
$too = [
    // field, date, relation (objects, UTC)
    'booking-30-before' => ['booking_start', '2031-01-24 23:30:00', '2031-01-25 00:00:00'],
    'booking-90-before' => ['booking_start', '2031-01-24 22:30:00', '2031-01-25 00:00:00'],
    'booking-25h-before' => ['booking_start', '2031-01-23 23:00:00', '2031-01-25 00:00:00'],
    'booking-after' => ['booking_start', '2031-01-25 00:30:00', '2031-01-25 00:00:00'],
    'booking-end-150-after' => ['booking_end', '2031-01-25 02:30:00', '2031-01-25 00:00:00'],
    'cancel-1d' => ['attendee_cancellation', '2031-01-24 00:00:00', '2031-01-25 00:00:00'],
    'cancel-36h' => ['attendee_cancellation', '2031-01-23 12:00:00', '2031-01-25 00:00:00'],
    'cancel-after' => ['attendee_cancellation', '2031-01-26 00:00:00', '2031-01-25 00:00:00'],
    'cancel-same' => ['attendee_cancellation', '2031-01-25 00:00:00', '2031-01-25 00:00:00'],
];
foreach ($too as $tn => $tv) {
    $cases["toOffset:obj:$tn"] = function () use ($tv) {
        return ['v' => beh('Events', 'RelationalTime')->convertToOffset(mk_dt($tv[1]), mk_dt($tv[2]), $tv[0])];
    };
    $cases["toOffset:str:$tn"] = function () use ($tv) {
        $d = mk_dt($tv[1])->format('m/d/y, g:i A');
        $r = mk_dt($tv[2])->format('m/d/y, g:i A');
        return ['v' => beh('Events', 'RelationalTime')->convertToOffset($d, $r, $tv[0])];
    };
}
$cases['toOffset:null-date'] = function () {
    return ['v' => beh('Events', 'RelationalTime')->convertToOffset(null, mk_dt('2031-01-25 00:00:00'), 'booking_start')];
};
$cases['toOffset:null-relation'] = function () {
    return ['v' => beh('Events', 'RelationalTime')->convertToOffset(mk_dt('2031-01-24 23:30:00'), null, 'booking_start')];
};
$cases['toOffset:unknown-field'] = function () {
    return ['v' => beh('Events', 'RelationalTime')->convertToOffset(mk_dt('2031-01-24 23:30:00'), mk_dt('2031-01-25 00:00:00'), 'bogus')];
};

// ---- 5. Registrations validation ----------------------------------------------------------------------------------------
function reg_base()
{
    return ['event_id' => '1001', 'type' => 'free', 'name' => 'Test Attendee', 'email' => 'new.person@example.test', 'phone' => '', 'ad_username' => 'newuser1', 'send_text' => '0', 'edit_key' => 'abc123XYZ', 'status' => 'confirmed', 'attended' => '0', 'ad_assigned' => '0'];
}
function reg_new(array $mods)
{
    return function () use ($mods) {
        $t = diff_table('Registrations');
        $d = reg_base();
        foreach ($mods as $k => $v) {
            if ($v === "\0unset") {
                unset($d[$k]);
            } else {
                $d[$k] = $v;
            }
        }
        $e = $t->newEntity($d);
        return diff_entity_result($e, ['event_id', 'type', 'name', 'email', 'ad_username', 'send_text', 'edit_key', 'status']);
    };
}
$cases['reg:base'] = reg_new([]);
$cases['reg:base:patch'] = function () {
    $t = diff_table('Registrations');
    $e = $t->patchEntity($t->get(3001), reg_base());
    return diff_entity_result($e, ['event_id', 'type', 'name', 'email', 'ad_username', 'send_text', 'edit_key', 'status']);
};
$regvals = ['unset' => "\0unset", 'null' => null, 'empty' => '', 'space' => ' ', 'zero-str' => '0', 'zero-int' => 0, 'false' => false, 'true' => true, 'abc' => 'abc', 'unicode' => 'Céramique 日本語'];
foreach (['event_id', 'type', 'name', 'phone', 'ad_username', 'send_text', 'edit_key', 'status', 'attended', 'ad_assigned'] as $f) {
    foreach ($regvals as $vn => $v) {
        $cases["reg:field:$f=$vn"] = reg_new([$f => $v]);
    }
}
foreach (['plain' => 'a@b.co', 'plus' => 'a+b@example.test', 'no-at' => 'not-an-email', 'no-domain' => 'a@', 'spaces' => 'a b@c.test', 'upper' => 'A@EXAMPLE.TEST', 'dup-same-event' => 'attendee.3001@example.test', 'dup-other-event-name' => 'attendee.3003@example.test'] as $vn => $v) {
    $cases["reg:email:$vn"] = reg_new(['email' => $v]);
}
$cases['reg:email:dup-other-event'] = reg_new(['event_id' => '1003', 'email' => 'attendee.3001@example.test']);
$cases['reg:email:dup-other-event-ad'] = reg_new(['event_id' => '1003', 'ad_username' => 'user1']);
$cases['reg:ad:dup-same-event'] = reg_new(['ad_username' => 'user1']);
$cases['reg:ad:empty-str'] = reg_new(['ad_username' => '']);
foreach (['free' => 'free', 'paid' => 'paid', 'FREE' => 'FREE', 'x' => 'x', 'empty' => ''] as $vn => $v) {
    $cases["reg:type:$vn"] = reg_new(['type' => $v]);
}
foreach (['confirmed' => 'confirmed', 'cancelled' => 'cancelled', 'pending' => 'pending', 'approved' => 'approved', 'x' => 'x'] as $vn => $v) {
    $cases["reg:status:$vn"] = reg_new(['status' => $v]);
}
foreach (['ok' => 'abc123XYZ', 'hyphen' => 'abc-123', 'space' => 'abc 123', 'underscore' => 'abc_123', 'unicode' => 'café123', 'empty' => '', 'zero' => '0'] as $vn => $v) {
    $cases["reg:editkey:$vn"] = reg_new(['edit_key' => $v]);
}
// save() paths (rolled back): buildRules existsIn + unique on save + a valid save.
$cases['reg:save:valid'] = function () {
    return diff_tx(function () {
        $t = diff_table('Registrations');
        $e = $t->newEntity(reg_base());
        $ok = $t->save($e);
        return ['saved' => (bool)$ok, 'errors' => diff_errors($e)];
    });
};
$cases['reg:save:bad-event'] = function () {
    return diff_tx(function () {
        $t = diff_table('Registrations');
        $d = reg_base();
        $d['event_id'] = '9999';
        $e = $t->newEntity($d);
        $ok = $t->save($e);
        return ['saved' => (bool)$ok, 'errors' => diff_errors($e)];
    });
};
$cases['reg:save:dup-email'] = function () {
    return diff_tx(function () {
        $t = diff_table('Registrations');
        $d = reg_base();
        $d['email'] = 'attendee.3001@example.test';
        $e = $t->newEntity($d);
        $ok = $t->save($e);
        return ['saved' => (bool)$ok, 'errors' => diff_errors($e)];
    });
};

// ---- 6. Other tables -------------------------------------------------------------------------------------------------------
function tab_new($table, array $base, array $mods)
{
    return function () use ($table, $base, $mods) {
        $t = diff_table($table);
        $d = $base;
        foreach ($mods as $k => $v) {
            if ($v === "\0unset") {
                unset($d[$k]);
            } else {
                $d[$k] = $v;
            }
        }
        $e = $t->newEntity($d);
        return ['errors' => diff_errors($e)];
    };
}
$contacts = ['name' => 'Test Instructor A', 'email' => 'instructor.a@example.test', 'phone' => '555-0100', 'w9_on_file' => '1', 'blacklisted' => '0'];
$cases['contacts:base'] = tab_new('Contacts', $contacts, []);
foreach (['name' => '', 'email' => 'bad', 'email-dup-save' => 'x'] as $f => $v) {
    if ($f === 'email-dup-save') {
        continue;
    }
    $cases["contacts:$f"] = tab_new('Contacts', $contacts, [$f => $v]);
}
foreach (['w9:false' => ['w9_on_file' => false], 'w9:zero' => ['w9_on_file' => '0'], 'w9:x' => ['w9_on_file' => 'x'], 'w9:empty' => ['w9_on_file' => ''], 'w9:2' => ['w9_on_file' => '2'], 'black:x' => ['blacklisted' => 'x'], 'black:empty' => ['blacklisted' => ''], 'ad:empty' => ['ad_username' => '']] as $vn => $mods) {
    $cases["contacts:$vn"] = tab_new('Contacts', $contacts, $mods);
}
$cases['contacts:save:dup-email'] = function () {
    return diff_tx(function () {
        $t = diff_table('Contacts');
        $e = $t->newEntity(['name' => 'Someone Else', 'email' => 'instructor.a@example.test', 'phone' => '555-0101', 'w9_on_file' => '0']);
        // seed a contact with that email first, inside the same rolled-back tx
        $first = $t->newEntity(['name' => 'Test Instructor A', 'email' => 'instructor.a@example.test', 'phone' => '555-0100', 'w9_on_file' => '1']);
        $t->save($first);
        $ok = $t->save($e);
        return ['saved' => (bool)$ok, 'errors' => diff_errors($e)];
    });
};
$hon = ['pay_contact' => '1', 'paid' => '50.00', 'event_id' => '1001', 'committee_id' => '1'];
$cases['honoraria:base'] = tab_new('Honoraria', $hon, []);
foreach (['pay:false' => ['pay_contact' => false], 'pay:x' => ['pay_contact' => 'x'], 'pay:empty' => ['pay_contact' => ''], 'paid:abc' => ['paid' => 'abc'], 'paid:empty' => ['paid' => ''], 'paid:exp' => ['paid' => '1e2'], 'paid:neg' => ['paid' => '-5'], 'paid:int' => ['paid' => '5']] as $vn => $mods) {
    $cases["honoraria:$vn"] = tab_new('Honoraria', $hon, $mods);
}
$cases['simple:categories:base'] = tab_new('Categories', ['name' => 'New Category'], []);
$cases['simple:categories:empty'] = tab_new('Categories', ['name' => ''], []);
$cases['simple:categories:unset'] = tab_new('Categories', ['name' => 'x'], ['name' => "\0unset"]);
$cases['simple:committees:empty'] = tab_new('Committees', ['name' => ''], []);
$cases['simple:tools:empty'] = tab_new('Tools', ['name' => ''], []);
$cases['simple:config:value-str'] = tab_new('Configurations', ['name' => 'x', 'value' => 'abc'], []);
$cases['simple:config:value-dec'] = tab_new('Configurations', ['name' => 'x', 'value' => '1.5'], []);
$cases['simple:config:value-empty'] = tab_new('Configurations', ['name' => 'x', 'value' => ''], []);
$cases['simple:prereq:empty-ad'] = tab_new('Prerequisites', ['name' => 'x', 'ad_group' => ''], []);
$cases['simple:prereq:empty-name'] = tab_new('Prerequisites', ['name' => '', 'ad_group' => 'g'], []);
$cases['simple:rooms:exclusive-str'] = tab_new('Rooms', ['name' => 'x', 'exclusive' => '1'], []);
$cases['simple:rooms:exclusive-bool'] = tab_new('Rooms', ['name' => 'x', 'exclusive' => true], []);
$cases['simple:rooms:exclusive-x'] = tab_new('Rooms', ['name' => 'x', 'exclusive' => 'x'], []);
$cases['simple:rooms:exclusive-empty'] = tab_new('Rooms', ['name' => 'x', 'exclusive' => ''], []);
$cases['simple:superconfig:empty'] = tab_new('CalendarSuperConfigurations', ['description' => ''], []);
$cases['simple:cats-events:base'] = tab_new('CategoriesEvents', ['category_id' => '1', 'event_id' => '1001'], []);
$cases['simple:events-tools:base'] = tab_new('EventsTools', ['event_id' => '1001', 'tool_id' => '1'], []);
$cases['w9s:file-null'] = tab_new('W9s', ['contact_id' => '2001', 'file' => null], []);
$cases['w9s:file-str'] = tab_new('W9s', ['contact_id' => '2001', 'file' => 'x'], []);
$cases['files:file-null'] = tab_new('Files', ['event_id' => '1001', 'file' => null], []);
$cases['files:file-str'] = tab_new('Files', ['event_id' => '1001', 'file' => 'x'], []);
$cases['files:private-x'] = tab_new('Files', ['event_id' => '1001', 'private' => 'x'], []);
$cases['files:private-bool'] = tab_new('Files', ['event_id' => '1001', 'private' => true], []);

diff_main($cases);

