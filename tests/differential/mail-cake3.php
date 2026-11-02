<?php
/**
 * Runs the OLD (Cake 3.10 / PHP 7.4) EmailComponent from the baseline image over cases.php and writes what the
 * SparkPost plugin transport would have POSTed. The plugin file is overlaid by run.sh with a copy whose only change is
 * that the final `$sparkpost->transmissions->post($message)` appends $message to $CAPTURE_FILE instead of calling the API.
 *
 * usage (inside calendar-baseline:1a086bead5): php mail-cake3.php /out/mail-cake3.json
 */
chdir('/var/www');
putenv('SPARKPOST_APIKEY=test-key');
$_ENV['SPARKPOST_APIKEY'] = $_SERVER['SPARKPOST_APIKEY'] = 'test-key';
require '/var/www/vendor/autoload.php';
require '/var/www/config/bootstrap.php';

use App\Controller\Component\EmailComponent;
use App\Model\Entity\Contact;
use App\Model\Entity\Event;
use App\Model\Entity\Registration;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Core\Plugin;
use Cake\I18n\FrozenTime;
use Cake\Log\Log;

error_reporting(E_ALL & ~E_USER_DEPRECATED & ~E_DEPRECATED);
Plugin::load('SparkPost');

require __DIR__ . "/capture-log3.php";
Log::drop('error');
Log::drop('debug');
Log::setConfig('capture', ['className' => \DiffTest\CaptureLog::class, 'levels' => ['error', 'warning']]);

$data = require __DIR__ . '/cases.php';
$captureFile = getenv('CAPTURE_FILE') ?: '/tmp/capture.jsonl';
$out = $argv[1];

$component = new EmailComponent(new ComponentRegistry(new Controller()));

$mkEvent = function (array $e, array $contact) {
    $entity = new Event([
        'id' => $e['id'],
        'name' => $e['name'],
        'event_start' => new FrozenTime($e['event_start'], 'UTC'),
        'created' => new FrozenTime($e['created'], 'UTC'),
        'rejection_reason' => $e['rejection_reason'],
        'created_by' => $e['created_by'],
        'contact' => new Contact($contact, ['guard' => false]),
    ], ['guard' => false]);
    return $entity;
};

$results = [];
foreach ($data['cases'] as $case) {
    @unlink($captureFile);
    \DiffTest\CaptureLog::$lines = [];
    $event = $mkEvent($case['event'], $case['contact']);
    $contact = new Contact($case['contact'], ['guard' => false]);
    $registration = new Registration($case['registration'], ['guard' => false]);
    $exception = null;
    try {
        if ($case['kind'] === 'contact') {
            $component->{$case['method']}($contact, $event);
        } else {
            $component->{$case['method']}($registration, $event);
        }
    } catch (\Throwable $t) {
        $exception = get_class($t) . ': ' . $t->getMessage();
    }
    $sent = [];
    if (is_file($captureFile)) {
        foreach (array_filter(explode("\x1e\n", file_get_contents($captureFile))) as $chunk) {
            $sent[] = json_decode($chunk, true);
        }
    }
    $results[$case['name']] = ['exception' => $exception, 'logged' => \DiffTest\CaptureLog::$lines, 'sent' => $sent];
}

file_put_contents($out, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo 'cases: ' . count($results) . ' php ' . PHP_VERSION . ' icu ' . INTL_ICU_VERSION . "\n";
