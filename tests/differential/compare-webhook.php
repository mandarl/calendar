<?php
declare(strict_types=1);

/**
 * Compares the requests the Cake 3 and the Cake 5 WebhookComponent sent to the sink, and the warnings they logged.
 * usage: php compare-webhook.php webhook-cake3.json webhook-cake5.json
 *
 * Exact: method, URI, every body byte, every header except `connection`. Allowed and reported: `Connection: close`
 * (Cake 3's curl adapter; Guzzle uses the HTTP/1.1 default), the exception class/text inside a "threw ..." warning
 * (Cake\Http\Exception\HttpException vs GuzzleHttp\Exception\ConnectException), and wall time within 1.5 s.
 */
$old = json_decode((string)file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$new = json_decode((string)file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);

$stripHeaders = function (array $r): array {
    unset($r['headers']['connection']);

    return $r;
};
$logNorm = fn (array $lines) => array_map(fn (string $l) => preg_replace('/ threw .*? for event /s', ' threw <exception> for event ', $l), $lines);

$failures = 0;
$requests = 0;
foreach ($old as $name => $o) {
    $n = $new[$name] ?? null;
    $problems = [];
    if ($n === null) {
        $problems[] = 'missing in cake5';
    } else {
        if (($o['exception'] === null) !== ($n['exception'] === null)) {
            $problems[] = 'exception ' . json_encode([$o['exception'], $n['exception']]);
        }
        if (array_map($stripHeaders, $o['requests']) !== array_map($stripHeaders, $n['requests'])) {
            $problems[] = 'requests differ: ' . json_encode($o['requests']) . ' vs ' . json_encode($n['requests']);
        }
        if ($logNorm($o['logged']) !== $logNorm($n['logged'])) {
            $problems[] = 'logged warnings differ: ' . json_encode($logNorm($o['logged'])) . ' vs ' . json_encode($logNorm($n['logged']));
        }
        if (abs($o['elapsed_s'] - $n['elapsed_s']) > 1.5) {
            $problems[] = 'elapsed ' . $o['elapsed_s'] . ' vs ' . $n['elapsed_s'];
        }
        $requests += count($o['requests']);
    }
    echo ($problems ? 'DIFF ' : 'ok   ') . $name . ' (' . count($o['requests']) . ' requests, ' . count($o['logged']) . " warnings)\n";
    foreach ($problems as $p) {
        echo '  - ' . substr($p, 0, 1500) . "\n";
        $failures++;
    }
}
printf("cases=%d requests compared=%d failures=%d\n", count($old), $requests, $failures);
exit($failures ? 1 : 0);
