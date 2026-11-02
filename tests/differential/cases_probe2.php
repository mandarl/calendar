<?php
require __DIR__ . '/lib.php';
diff_set_now('2031-01-15 16:00:00');
function pr_base()
{
    return ['name' => 'Test Class', 'short_description' => 'A short description.', 'long_description' => '<p>Long</p>', 'advisories' => '', 'event_start' => '01/25/2031 6:00 PM', 'event_end' => '01/25/2031 8:00 PM', 'booking_start' => '30', 'booking_end' => '30', 'attendee_cancellation' => '1', 'cost' => '0', 'eventbrite_link' => '', 'free_spaces' => '10', 'paid_spaces' => '0', 'members_only' => '0', 'age_restriction' => '0', 'attendees_require_approval' => '0', 'extend_registration' => '0', 'class_number' => '1', 'sponsored' => '0', 'status' => 'pending', 'room_id' => '3', 'created_by' => 'user1', 'contact_id' => '2001', 'request_honorarium' => '0', 'categories' => ['_ids' => ['1']], 'tools' => ['_ids' => []]];
}
$cases = [];
foreach (['name', 'short_description', 'created_by', 'room_id'] as $f) {
    $cases['emptyarr:' . $f] = function () use ($f) {
        $d = pr_base();
        $d[$f] = [];
        $t = diff_table('Events');
        return diff_entity_result($t->newEntity($d), [$f]);
    };
    $cases['validator:' . $f] = function () use ($f) {
        $t = diff_table('Events');
        $v = $t->getValidator('default');
        return ['errors' => $v->validate([$f => []])];
    };
}
diff_main($cases);
