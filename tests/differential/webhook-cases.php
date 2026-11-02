<?php
/** Shared webhook inputs (PHP 7.4 compatible). Targets cover ok / non-ok statuses / redirect / refused / timeout / odd entries. */
$timeout = 1;
$targets = [
    ['name' => 'sink-ok', 'url' => 'http://127.0.0.1:4010/ok', 'secret' => 's3cr3t'],
    ['name' => 'sink-201', 'url' => 'http://127.0.0.1:4010/status/201', 'secret' => 'other'],
    ['name' => 'sink-500', 'url' => 'http://127.0.0.1:4010/status/500?x=1', 'secret' => 'e'],
    ['name' => 'zero-secret', 'url' => 'http://127.0.0.1:4010/zero', 'secret' => '0'],
    ['name' => 'sink-404', 'url' => 'http://127.0.0.1:4010/status/404'],
    ['name' => 'sink-302', 'url' => 'http://127.0.0.1:4010/status/302', 'secret' => 'r'],
    ['url' => 'http://127.0.0.1:4010/status/206'],
    ['name' => 'refused', 'url' => 'http://127.0.0.1:4011/nothing-listens-here', 'secret' => 'x'],
    ['name' => 'slow', 'url' => 'http://127.0.0.1:4010/slow', 'secret' => 'x'],
    ['name' => 'no-url', 'secret' => 'x'],
    ['name' => 'empty-url', 'url' => '', 'secret' => 'x'],
];
$events = [
    'utc start' => ['id' => 1001, 'name' => 'Fixture: Free Open Class', 'created_by' => 'user1', 'event_start' => ['2026-11-04 00:00:00', 'UTC']],
    'chicago start object' => ['id' => 1003, 'name' => "Caf\u{00E9} & \"quotes\" / slash", 'created_by' => 'user3', 'event_start' => ['2026-07-15 12:00:00', 'America/Chicago']],
    'no start' => ['id' => 1019, 'name' => 'Fixture: No Start', 'created_by' => null, 'event_start' => null],
];
