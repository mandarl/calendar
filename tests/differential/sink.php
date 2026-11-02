<?php
/**
 * Tiny webhook sink for the differential test (php -S 127.0.0.1:4010 sink.php). PHP 7.4 compatible.
 * Records every request (method, uri, headers, raw body) to $SINK_LOG and answers with the status encoded in the
 * path: /status/<code> (default 200); /slow sleeps 3 seconds first.
 */
$log = getenv('SINK_LOG') ?: '/tmp/sink.jsonl';
$headers = [];
foreach (getallheaders() as $k => $v) {
    $headers[strtolower($k)] = $v;
}
ksort($headers);
file_put_contents($log, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
]) . "\n", FILE_APPEND | LOCK_EX);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/slow') {
    sleep(3);
}
$code = 200;
if (preg_match('#^/status/(\d{3})$#', $path, $m)) {
    $code = (int)$m[1];
}
http_response_code($code);
if ($code >= 300 && $code < 400) {
    header('Location: http://127.0.0.1:4010/status/200');
}
header('Content-Type: application/json');
echo '{"ok":true}';
