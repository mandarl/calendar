<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Component;

use App\Controller\Component\WebhookComponent;
use App\Model\Entity\Event;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * PHPUnit 11 rewrite of the Cake 3 WebhookComponentTest (tests/legacy-cake3-stubs/).
 * The HTTP layer is a recording Guzzle mock: nothing leaves the process.
 * Route generation mirrors tests/differential/webhook-cake5.php (load the app routes, set fullBaseUrl).
 */
class WebhookComponentTest extends TestCase
{
    /** @var array<int, array> */
    private array $history = [];

    private MockHandler $mock;

    private mixed $savedFullBaseUrl = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->history = [];
        $this->mock = new MockHandler();
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        Log::drop('test_array');
        Log::setConfig('test_array', ['className' => ArrayLog::class, 'levels' => ['error', 'warning']]);

        $this->savedFullBaseUrl = Configure::read('App.fullBaseUrl');
        Configure::write('App.fullBaseUrl', 'http://calendar.example.test');
        $routes = require dirname(__DIR__, 4) . '/config/routes.php';
        $routes(Router::createRouteBuilder('/'));
    }

    protected function tearDown(): void
    {
        Log::drop('test_array');
        Configure::delete('Webhooks');
        if ($this->savedFullBaseUrl === null) {
            Configure::delete('App.fullBaseUrl');
        } else {
            Configure::write('App.fullBaseUrl', $this->savedFullBaseUrl);
        }
        parent::tearDown();
    }

    private function makeComponentWithHistory(): WebhookComponent
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $webhook = new WebhookComponent(new ComponentRegistry(new Controller(new ServerRequest())));
        $webhook->initialize([]);
        $webhook->setHttpClient(new Client(['handler' => $stack, 'http_errors' => false]));

        return $webhook;
    }

    private function event(array $over = []): Event
    {
        return new Event(array_merge([
            'id' => 1234,
            'name' => 'Test Event',
            'created_by' => 'jdoe',
            'event_start' => new DateTime('2026-08-15 18:00:00', 'UTC'),
        ], $over), ['guard' => false]);
    }

    private function logged(): array
    {
        return Log::engine('test_array')->read();
    }

    public function testDispatchEventPublishedPostsEnvelopeToEachTarget(): void
    {
        Configure::write('Webhooks.targets', [
            ['name' => 'member-portal', 'url' => 'https://portal.example/webhooks', 'secret' => 's3cr3t'],
            ['name' => 'second', 'url' => 'https://second.example/hook', 'secret' => 'other'],
        ]);
        $this->mock->append(new Response(200, [], '{"ok":true}'), new Response(201, [], '{"ok":true}'));

        $this->makeComponentWithHistory()->dispatchEventPublished($this->event());

        $this->assertCount(2, $this->history);
        [$first, $second] = $this->history;

        $this->assertSame('POST', $first['request']->getMethod());
        $this->assertSame('https://portal.example/webhooks', (string)$first['request']->getUri());
        $this->assertSame('https://second.example/hook', (string)$second['request']->getUri());
        $this->assertSame('application/json', $first['request']->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $first['request']->getHeaderLine('Accept'));
        $this->assertSame('CakePHP', $first['request']->getHeaderLine('User-Agent'));
        $this->assertSame('s3cr3t', $first['request']->getHeaderLine(WebhookComponent::HEADER_SECRET));
        $this->assertSame('other', $second['request']->getHeaderLine(WebhookComponent::HEADER_SECRET));
        $this->assertSame('event.published', $first['request']->getHeaderLine(WebhookComponent::HEADER_EVENT));
        // Cake 3 never threw on 4xx/5xx and never followed redirects; Guzzle is told the same.
        $this->assertFalse($first['options']['http_errors']);
        $this->assertFalse($first['options']['allow_redirects']);
        $this->assertSame(3, $first['options']['timeout']);

        $body = json_decode((string)$first['request']->getBody(), true);
        $this->assertSame('event.published', $body['event_type']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $body['occurred_at']);
        $this->assertSame(1234, $body['data']['id']);
        $this->assertSame('jdoe', $body['data']['organizer_username']);
        $this->assertSame('Test Event', $body['data']['name']);
        $this->assertSame('2026-08-15T18:00:00+00:00', $body['data']['event_start']);
        $this->assertSame('UTC', $body['data']['event_start_timezone']);
        $this->assertStringContainsString('/events/view', $body['data']['url']);
        $this->assertStringContainsString('1234', $body['data']['url']);
        $this->assertArrayNotHasKey('topic', $body['data']);
        $this->assertArrayNotHasKey('template', $body['data']);
        $this->assertArrayNotHasKey('channel', $body['data']);

        $this->assertSame([], $this->logged(), '2xx deliveries log nothing');
    }

    public function testChicagoStartIsConvertedToUtcAndNullStartStaysNull(): void
    {
        Configure::write('Webhooks.targets', [
            ['url' => 'https://portal.example/webhooks', 'secret' => 's3cr3t'],
        ]);
        $this->mock->append(new Response(200, [], '{}'), new Response(200, [], '{}'));

        $webhook = $this->makeComponentWithHistory();
        $chicago = $this->event(['event_start' => new DateTime('2026-07-15 12:00:00', 'America/Chicago')]);
        $webhook->dispatchEventPublished($chicago);
        $webhook->dispatchEventPublished($this->event(['event_start' => null]));

        $bodies = array_map(
            fn (array $h) => json_decode((string)$h['request']->getBody(), true),
            $this->history
        );
        $this->assertSame('2026-07-15T17:00:00+00:00', $bodies[0]['data']['event_start']);
        $this->assertNull($bodies[1]['data']['event_start']);
    }

    public function testNonOkStatusesAreLoggedAndOkStatusesAreNot(): void
    {
        Configure::write('Webhooks.targets', [
            ['name' => 'sink-500', 'url' => 'https://sink.example/500', 'secret' => 'e'],
            ['name' => 'sink-206', 'url' => 'https://sink.example/206', 'secret' => 'e'],
            ['name' => 'sink-302', 'url' => 'https://sink.example/302', 'secret' => 'e'],
            ['name' => 'sink-204', 'url' => 'https://sink.example/204', 'secret' => 'e'],
        ]);
        $this->mock->append(
            new Response(500, [], '{}'),
            new Response(206, [], '{}'),
            new Response(302, [], '{}'),
            new Response(204, [], '{}')
        );

        // No exception for any status, including the redirect (not followed).
        $this->makeComponentWithHistory()->dispatch('event.published', ['id' => 1]);

        $this->assertCount(4, $this->history);
        $logged = $this->logged();
        $this->assertCount(3, $logged, '500, 206 and 302 log; 204 is in Cake 3 isOk() so it does not');
        $this->assertStringContainsString('status 500', $logged[0]);
        $this->assertStringContainsString('status 206', $logged[1]);
        $this->assertStringContainsString('status 302', $logged[2]);
    }

    public function testDeliveryFailureIsLoggedNotThrown(): void
    {
        Configure::write('Webhooks.targets', [
            ['name' => 'flaky-target', 'url' => 'https://portal.example/webhooks', 'secret' => 's3cr3t'],
        ]);
        $this->mock->append(new ConnectException(
            'connection refused',
            new Request('POST', 'https://portal.example/webhooks')
        ));

        $this->makeComponentWithHistory()->dispatchEventPublished($this->event());

        $logged = $this->logged();
        $this->assertCount(1, $logged);
        $this->assertStringContainsString('threw', $logged[0]);
        $this->assertStringContainsString('flaky-target', $logged[0]);
    }

    public function testEmptySecretSendsNoSecretHeaderButZeroSecretDoes(): void
    {
        Configure::write('Webhooks.targets', [
            ['url' => 'https://sink.example/no-secret'],
            ['url' => 'https://sink.example/zero-secret', 'secret' => '0'],
        ]);
        $this->mock->append(new Response(200, [], '{}'), new Response(200, [], '{}'));

        $this->makeComponentWithHistory()->dispatch('event.published', ['id' => 1]);

        $this->assertFalse($this->history[0]['request']->hasHeader(WebhookComponent::HEADER_SECRET));
        $this->assertSame('0', $this->history[1]['request']->getHeaderLine(WebhookComponent::HEADER_SECRET));
    }

    public function testConfiguredTimeoutIsUsed(): void
    {
        Configure::write('Webhooks.targets', [['url' => 'https://sink.example/hook', 'secret' => 'x']]);
        Configure::write('Webhooks.timeout', 7);
        $this->mock->append(new Response(200, [], '{}'));

        $this->makeComponentWithHistory()->dispatch('event.published', ['id' => 1]);

        $this->assertSame(7, $this->history[0]['options']['timeout']);
    }

    public function testNoTargetsConfiguredIsANoop(): void
    {
        Configure::write('Webhooks.targets', []);
        $this->mock->append(new Response(200, [], '{}'));

        $this->makeComponentWithHistory()->dispatch('event.published', ['id' => 1]);

        $this->assertSame([], $this->history);
    }

    public function testGenericDispatchKeepsTheCallerSuppliedType(): void
    {
        Configure::write('Webhooks.targets', [['url' => 'https://sink.example/hook', 'secret' => 'x']]);
        $this->mock->append(new Response(200, [], '{}'));

        $this->makeComponentWithHistory()->dispatch('event.full', ['id' => 7]);

        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        $this->assertSame('event.full', $body['event_type']);
        $this->assertSame('event.full', $this->history[0]['request']->getHeaderLine(WebhookComponent::HEADER_EVENT));
        $this->assertSame(['id' => 7], $body['data']);
    }
}
