<?php
declare(strict_types=1);

/**
 * Runs the PORTED EmailComponent (Cake 5.4 / PHP 8.4) over cases.php with the real libkit SparkPost transport and a
 * capturing PSR-18 client, and writes what would have been POSTed to the SparkPost API (JSON body decoded).
 *
 * usage (run.sh does this in the calendar-cake5:base image with the tree and vendor volume mounted):
 *   php tests/differential/mail-cake5.php /out/mail-cake5.json
 */
chdir(dirname(__DIR__, 2));
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Controller\Component\EmailComponent;
use App\Model\Entity\Contact;
use App\Model\Entity\Event;
use App\Model\Entity\Registration;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\I18n\DateTime;
use Cake\Log\Engine\BaseLog;
use Cake\Log\Log;
use Cake\Mailer\TransportFactory;
use Dms\Kit\Cake\SparkPostTransport;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class CaptureClient implements ClientInterface
{
    /** @var list<array{headers: array, body: array}> */
    public array $requests = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = [
            'method' => $request->getMethod(),
            'uri' => (string)$request->getUri(),
            'authorization' => $request->getHeaderLine('Authorization'),
            'content-type' => $request->getHeaderLine('Content-Type'),
            'body' => json_decode((string)$request->getBody(), true),
        ];

        return new Response(200, ['Content-Type' => 'application/json'], '{"results":{"total_rejected_recipients":0,"total_accepted_recipients":1,"id":"fake-1"}}');
    }
}

require __DIR__ . '/capture-log5.php';

foreach (['error', 'debug'] as $name) {
    Log::drop($name);
}
Log::setConfig('capture', ['className' => \DiffTest\CaptureLog::class, 'levels' => ['error', 'warning']]);

$client = new CaptureClient();
TransportFactory::drop('sparkpost');
TransportFactory::setConfig('sparkpost', [
    'className' => SparkPostTransport::class,
    'apiKey' => 'test-key',
    'client' => $client,
]);

$data = require __DIR__ . '/cases.php';
$out = $argv[1];

$component = new EmailComponent(new ComponentRegistry(new Controller(new Cake\Http\ServerRequest())));

$mkEvent = function (array $e, array $contact): Event {
    return new Event([
        'id' => $e['id'],
        'name' => $e['name'],
        'event_start' => new DateTime($e['event_start'], 'UTC'),
        'created' => new DateTime($e['created'], 'UTC'),
        'rejection_reason' => $e['rejection_reason'],
        'created_by' => $e['created_by'],
        'contact' => new Contact($contact, ['guard' => false]),
    ], ['guard' => false]);
};

$results = [];
foreach ($data['cases'] as $case) {
    $client->requests = [];
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
    $results[$case['name']] = [
        'exception' => $exception,
        'logged' => \DiffTest\CaptureLog::$lines,
        'sent' => array_map(fn ($r) => $r['body'], $client->requests),
        'transport' => array_map(fn ($r) => [$r['method'], $r['uri'], $r['authorization'], $r['content-type']], $client->requests),
    ];
}

file_put_contents($out, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo 'cases: ' . count($results) . ' php ' . PHP_VERSION . ' icu ' . INTL_ICU_VERSION . "\n";
