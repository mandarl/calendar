<?php
declare(strict_types=1);

/**
 * Runs the PORTED WebhookComponent (Cake 5.4 / PHP 8.4, Guzzle 7) against the local sink; same inputs as webhook-cake3.php.
 * usage: php tests/differential/webhook-cake5.php /out/webhook-cake5.json     (run-webhook.sh starts the sink first)
 */
chdir(dirname(__DIR__, 2));
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Controller\Component\WebhookComponent;
use App\Model\Entity\Event;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Cake\Log\Engine\BaseLog;
use Cake\Log\Log;
use Cake\Routing\Router;

require __DIR__ . '/capture-log5.php';

foreach (['error', 'debug'] as $name) {
    Log::drop($name);
}
Log::setConfig('capture', ['className' => \DiffTest\CaptureLog::class, 'levels' => ['error', 'warning']]);

Configure::write('App.fullBaseUrl', 'http://calendar.example.test');
$routes = require dirname(__DIR__, 2) . '/config/routes.php';
$routes(Router::createRouteBuilder('/'));
DateTime::setTestNow(new DateTime('2026-11-01 12:00:00', 'UTC'));

$sinkLog = getenv('SINK_LOG') ?: '/tmp/sink.jsonl';
require __DIR__ . '/webhook-cases.php';   // $targets, $timeout, $events

Configure::write('Webhooks.targets', $targets);
Configure::write('Webhooks.timeout', $timeout);
$component = new WebhookComponent(new ComponentRegistry(new Controller(new ServerRequest())));

$readSink = function () use ($sinkLog): array {
    $requests = [];
    foreach (array_filter(explode("\n", (string)@file_get_contents($sinkLog))) as $line) {
        $requests[] = json_decode($line, true);
    }

    return $requests;
};

$results = [];
foreach ($events as $name => $e) {
    @unlink($sinkLog);
    \DiffTest\CaptureLog::$lines = [];
    $start = $e['event_start'];
    $entity = new Event([
        'id' => $e['id'],
        'name' => $e['name'],
        'created_by' => $e['created_by'],
        'event_start' => $start === null ? null : new DateTime($start[0], $start[1]),
    ], ['guard' => false]);
    $exception = null;
    $t0 = microtime(true);
    try {
        $component->dispatchEventPublished($entity);
    } catch (\Throwable $t) {
        $exception = get_class($t) . ': ' . $t->getMessage();
    }
    $elapsed = round(microtime(true) - $t0, 1);
    sleep(3); // let the single-threaded sink finish the /slow request before the next case
    $results[$name] = [
        'exception' => $exception,
        'logged' => \DiffTest\CaptureLog::$lines,
        'requests' => $readSink(),
        'elapsed_s' => $elapsed,
    ];
}

@unlink($sinkLog);
\DiffTest\CaptureLog::$lines = [];
$component->dispatch('event.full', ['id' => 7]);
sleep(3);
$results['dispatch event.full'] = ['exception' => null, 'logged' => \DiffTest\CaptureLog::$lines, 'requests' => $readSink(), 'elapsed_s' => 0];

file_put_contents($argv[1], json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo 'webhook cases: ' . count($results) . ' php ' . PHP_VERSION . "\n";
