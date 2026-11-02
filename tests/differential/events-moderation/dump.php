<?php
// Slice D DB dump helper (test-only, runs inside the app container where DB_*
// env vars are already set). Usage: php dump.php <eventIds csv> <regEventIds csv>
// Prints JSON: events (id,status,created_by,rejected_by,rejection_reason) and
// registrations (id,event_id,status,attended,ad_assigned,transaction_id,phone,send_text).
$events = array_filter(explode(',', $argv[1] ?? ''), 'strlen');
$regEvents = array_filter(explode(',', $argv[2] ?? $argv[1] ?? ''), 'strlen');
$pdo = new PDO(
    'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_DATABASE') . ';charset=utf8',
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$out = ['events' => [], 'registrations' => []];
if ($events) {
    $q = implode(',', array_fill(0, count($events), '?'));
    $st = $pdo->prepare("SELECT id,status,created_by,rejected_by,rejection_reason FROM events WHERE id IN ($q) ORDER BY id");
    $st->execute(array_values($events));
    $out['events'] = $st->fetchAll(PDO::FETCH_ASSOC);
}
if ($regEvents) {
    $q = implode(',', array_fill(0, count($regEvents), '?'));
    $st = $pdo->prepare("SELECT id,event_id,status,attended,ad_assigned,transaction_id,phone,send_text FROM registrations WHERE event_id IN ($q) ORDER BY id");
    $st->execute(array_values($regEvents));
    $out['registrations'] = $st->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode($out, JSON_PRETTY_PRINT) . "\n";
