<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Concern;

use App\Controller\Concern\EventsModerationActionsTrait;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Dms\Kit\Sms\SmsOutcome;
use Dms\Kit\Sms\TwilioConfig;
use Dms\Kit\Sms\TwilioSmsSender;
use Twilio\AuthStrategy\AuthStrategy;
use Twilio\Http\Client as TwilioHttpClient;
use Twilio\Http\Response;

/**
 * Slice D SMS path (EventsController::cancel -> sendCancellationText -> kit TwilioSmsSender).
 *
 * The trait builds its sender from Configure::read('Twilio.*') with no injection seam, so the
 * Sent path is exercised here through the same sender construction with the kit's fake
 * Twilio\Http\Client (no network), using the exact message cancel() builds. The trait method
 * itself is exercised under the test config (empty Twilio credentials -> NotConfigured, no throw).
 * Synthetic data only.
 */
final class EventsModerationSmsTest extends TestCase
{
    private function fakeHttp(int $status = 201, string $body = '{"sid":"SM00000000000000000000000000000000","status":"queued"}'): object
    {
        return new class ($status, $body) implements TwilioHttpClient {
            /** @var list<array<string,mixed>> */
            public array $requests = [];

            public function __construct(private int $status, private string $body)
            {
            }

            public function request(
                string $method,
                string $url,
                array $params = [],
                array $data = [],
                array $headers = [],
                ?string $user = null,
                ?string $password = null,
                ?int $timeout = null,
                ?AuthStrategy $authStrategy = null,
            ): Response {
                $this->requests[] = compact('method', 'url', 'params', 'data', 'headers', 'user', 'password');

                return new Response($this->status, $this->body);
            }
        };
    }

    public function testCancelMessageIsSentThroughFakeClient(): void
    {
        $http = $this->fakeHttp();
        $sender = TwilioSmsSender::fromConfig(
            new TwilioConfig('AC00000000000000000000000000000000', 'test-token', '+15550100'),
            null,
            $http,
        );

        $outcome = $sender->sendText('5551234567', 'DMS Event Update: Fixture Class has been cancelled.');

        $this->assertSame(SmsOutcome::Sent, $outcome);
        $this->assertCount(1, $http->requests);
        $req = $http->requests[0];
        $this->assertSame('POST', $req['method']);
        $this->assertSame('+15551234567', $req['data']['To']);
        $this->assertSame('+15550100', $req['data']['From']);
        $this->assertSame('DMS Event Update: Fixture Class has been cancelled.', $req['data']['Body']);
    }

    public function testFixtureStyleShortNumbersAreSkippedOnBothSides(): void
    {
        // Fixture phones are 7 digits (555-02xx): Cake 3 __sendText prepends 1 -> 8 digits,
        // != 11, so it silently skips; the kit normaliser agrees (SkippedInvalidNumber).
        $http = $this->fakeHttp();
        $sender = TwilioSmsSender::fromConfig(
            new TwilioConfig('AC00000000000000000000000000000000', 'test-token', '+15550100'),
            null,
            $http,
        );

        $this->assertSame(SmsOutcome::SkippedInvalidNumber, $sender->sendText('555-0203', 'x'));
        $this->assertSame([], $http->requests);
    }

    public function testEmptyCredentialsAreNotConfigured(): void
    {
        $sender = TwilioSmsSender::fromConfig(TwilioConfig::fromEnv([]), null, $this->fakeHttp());

        $this->assertSame(SmsOutcome::NotConfigured, $sender->sendText('5551234567', 'x'));
    }

    public function testTraitSendCancellationTextRunsUnderTestConfig(): void
    {
        Configure::write('Twilio.accountSid', '');
        Configure::write('Twilio.authToken', '');
        Configure::write('Twilio.phone', '');

        $helper = new class {
            use EventsModerationActionsTrait;
        };
        $method = new \ReflectionMethod($helper, 'sendCancellationText');
        $method->setAccessible(true);

        // Returns void, must not throw: empty credentials -> kit NotConfigured path.
        $this->assertNull($method->invoke($helper, '5551234567', 'DMS Event Update: X has been cancelled.'));
    }
}
