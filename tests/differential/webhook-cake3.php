<?php
/**
 * Runs the OLD WebhookComponent (Cake 3.10 / PHP 7.4, baseline image) against the local sink.
 * usage: php webhook-cake3.php /out/webhook-cake3.json     (run-webhook.sh starts the sink first)
 */
chdir('/var/www');
require '/var/www/vendor/autoload.php';
require '/var/www/config/bootstrap.php';
error_reporting(E_ALL & ~E_USER_DEPRECATED & ~E_DEPRECATED);

use App\Controller\Component\WebhookComponent;
use App\Model\Entity\Event;
use Cake\Chronos\Chronos;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\I18n\FrozenTime;
use Cake\Log\Log;

require __DIR__ . "/capture-log3.php";
Log::drop('error');
Log::drop('debug');
Log::setConfig('capture', ['className' => \DiffTest\CaptureLog::class, 'levels' => ['error', 'warning']]);

Configure::write('App.fullBaseUrl', 'http://calendar.example.test');
require '/var/www/config/routes.php';
Chronos::setTestNow(new Chronos('2026-11-01 12:00:00', 'UTC'));

$sinkLog = getenv('SINK_LOG') ?: '/tmp/sink.jsonl';
require __DIR__ . '/webhook-cases.php';   // $targets, $timeout, $events

Configure::write('Webhooks.targets', $targets);
Configure::write('Webhooks.timeout', $timeout);
$component = new WebhookComponent(new ComponentRegistry(new Controller()));

$results = [];
foreach ($events as $name => $e) {
    @unlink($sinkLog);
    \DiffTest\CaptureLog::$lines = [];
    $start = $e['event_start'];
    $entity = new Event(['id' => $e['id'], 'name' => $e['name'], 'created_by' => $e['created_by'],
        'event_start' => $start === null ? null : new FrozenTime($start[0], $start[1])], ['guard' => false]);
    $exception = null;
    $t0 = microtime(true);
    try {
        $component->dispatchEventPublished($entity);
    } catch (\Throwable $t) {
        $exception = get_class($t) . ': ' . $t->getMessage();
    }
    $elapsed = microtime(true) - $t0;
    sleep(3); // let the single-threaded sink finish the /slow request before the next case
    $requests = [];
    if (is_file($sinkLog)) {
        foreach (array_filter(explode("\n", file_get_contents($sinkLog))) as $line) {
            $requests[] = json_decode($line, true);
        }
    }
    $results[$name] = ['exception' => $exception, 'logged' => \DiffTest\CaptureLog::$lines, 'requests' => $requests, 'elapsed_s' => round($elapsed, 1)];
}

// generic dispatch with an arbitrary type
@unlink($sinkLog);
\DiffTest\CaptureLog::$lines = [];
$component->dispatch('event.full', ['id' => 7]);
sleep(3);
$requests = [];
foreach (array_filter(explode("\n", (string)@file_get_contents($sinkLog))) as $line) {
    $requests[] = json_decode($line, true);
}
$results['dispatch event.full'] = ['exception' => null, 'logged' => \DiffTest\CaptureLog::$lines, 'requests' => $requests, 'elapsed_s' => 0];

file_put_contents($argv[1], json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo 'webhook cases: ' . count($results) . ' php ' . PHP_VERSION . "\n";
