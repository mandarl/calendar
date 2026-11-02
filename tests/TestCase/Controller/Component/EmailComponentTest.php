<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Component;

use App\Controller\Component\EmailComponent;
use App\Model\Entity\Contact;
use App\Model\Entity\Event;
use App\Model\Entity\Registration;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\Mailer\Mailer;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Dms\Kit\Cake\SparkPostTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Every EmailComponent::send* method, through the real Mailer and the real libkit SparkPost transport, with the HTTP
 * layer replaced by a recording Guzzle mock (nothing leaves the process).
 *
 * The golden file golden/mail-cake3-payloads.json holds what the Cake 3 component (baseline image, PHP 7.4, old plugin
 * transport) produced for the same inputs; tests/differential/ regenerates and compares the full table, this test keeps
 * a slice of it in the normal PHPUnit run. Inputs come from tests/differential/cases.php (synthetic data only).
 */
class EmailComponentTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private MockHandler $mock;

    private EmailComponent $email;

    private array $savedTransport = [];

    private array $cases = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedTransport = TransportFactory::getConfig('sparkpost');
        $this->mock = new MockHandler();
        $this->history = [];
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        TransportFactory::drop('sparkpost');
        TransportFactory::setConfig('sparkpost', [
            'className' => SparkPostTransport::class,
            'apiKey' => 'test-key',
            'client' => new Client(['handler' => $stack, 'http_errors' => false]),
        ]);

        Log::drop('test_array');
        Log::setConfig('test_array', ['className' => ArrayLog::class, 'levels' => ['error', 'warning']]);

        $this->email = new EmailComponent(new ComponentRegistry(new Controller(new ServerRequest())));
        $this->cases = require dirname(__DIR__, 3) . '/differential/cases.php';
    }

    protected function tearDown(): void
    {
        TransportFactory::drop('sparkpost');
        TransportFactory::setConfig('sparkpost', $this->savedTransport);
        Log::drop('test_array');
        parent::tearDown();
    }

    private function accept(int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->mock->append(new Response(200, [], '{"results":{"total_rejected_recipients":0,"total_accepted_recipients":1,"id":"fake-1"}}'));
        }
    }

    /** @return list<array> decoded JSON bodies POSTed to the (mock) API */
    private function sent(): array
    {
        return array_map(fn (array $h) => json_decode((string)$h['request']->getBody(), true), $this->history);
    }

    private function event(array $e, array $contact): Event
    {
        return new Event([
            'id' => $e['id'],
            'name' => $e['name'],
            'event_start' => new DateTime($e['event_start'], 'UTC'),
            'created' => new DateTime($e['created'], 'UTC'),
            'rejection_reason' => $e['rejection_reason'],
            'created_by' => $e['created_by'],
            'contact' => new Contact($contact, ['guard' => false]),
        ], ['guard' => false]);
    }

    /** Calls $method the way the controllers do (Contact for the contact-kind methods, Registration otherwise). */
    private function call(string $method, string $evKey, string $regKey, string $ctKey): void
    {
        $event = $this->event($this->cases['events'][$evKey], $this->cases['contacts'][$ctKey]);
        // cases.php carries no kind map; the provider below does ('contact' vs 'registration'/'instructor').
        $kinds = array_column(self::methodProvider(), 1, 0);
        if (($kinds[$method] ?? 'registration') === 'contact') {
            $this->email->{$method}(new Contact($this->cases['contacts'][$ctKey], ['guard' => false]), $event);
        } else {
            $this->email->{$method}(new Registration($this->cases['registrations'][$regKey], ['guard' => false]), $event);
        }
    }

    /** @return array<string, array{string, string}> method => [kind, subject prefix] */
    public static function methodProvider(): array
    {
        return [
            'sendEventRejected' => ['sendEventRejected', 'contact', 'DMS Event Rejection: '],
            'sendEventSubmitted' => ['sendEventSubmitted', 'contact', 'DMS Event Submission: '],
            'sendEventApproved' => ['sendEventApproved', 'contact', 'DMS Event Approval: '],
            'sendEventCancelled' => ['sendEventCancelled', 'registration', 'DMS Event Update: '],
            'sendEventStarting' => ['sendEventStarting', 'registration', 'DMS Reminder: '],
            'sendUnapprovedRegistrationCancelled' => ['sendUnapprovedRegistrationCancelled', 'registration', 'DMS Events: '],
            'sendCancellationReminder' => ['sendCancellationReminder', 'registration', 'DMS Reminder: '],
            'sendRegistrationConfirmation' => ['sendRegistrationConfirmation', 'registration', 'DMS Event Confirmation: '],
            'sendRegistrationPending' => ['sendRegistrationPending', 'registration', 'DMS Event Pending: '],
            'sendRegistrationRequested' => ['sendRegistrationRequested', 'instructor', 'DMS Attendance Request: '],
            'sendRegistrationToInstructor' => ['sendRegistrationToInstructor', 'instructor', 'DMS New Registration: '],
            'sendCancellationToInstructor' => ['sendCancellationToInstructor', 'instructor', 'DMS Cancelled Registration: '],
            'sendRegistrationApproved' => ['sendRegistrationApproved', 'registration', 'DMS Event Update: Attendance approved for '],
            'sendRegistrationRejected' => ['sendRegistrationRejected', 'registration', 'DMS Event Update: Attendance Rejected for '],
            'sendRegistrationCancelled' => ['sendRegistrationCancelled', 'registration', 'DMS Event Cancellation: '],
        ];
    }

    #[DataProvider('methodProvider')]
    public function testEachMethodSendsOneHtmlMailToTheRightPerson(string $method, string $who, string $subjectPrefix): void
    {
        $this->accept();
        $this->call($method, '1001', '3001', 'A');

        $sent = $this->sent();
        $this->assertCount(1, $sent, "$method must send exactly one transmission");
        $mail = $sent[0];

        $this->assertSame(['name' => 'Dallas Makerspace', 'email' => 'admin@dallasmakerspace.org'], $mail['content']['from']);
        $this->assertStringStartsWith($subjectPrefix, $mail['content']['subject']);
        $this->assertSame('', $mail['content']['text'], 'HTML only: the text part stays empty');
        $this->assertStringContainsString('<!doctype html>', $mail['content']['html']);
        $this->assertStringContainsString('Fixture: Free Open Class', $mail['content']['html']);
        $this->assertSame(['click_tracking' => false], $mail['options'], 'owner decision: link tracking off');
        $this->assertArrayNotHasKey('reply_to', $mail['content']);

        // To only, one recipient: the contact for the contact-kind and instructor-kind mails, else the attendee
        $this->assertCount(1, $mail['recipients']);
        $expected = $who === 'registration'
            ? ['name' => 'Test Attendee 01', 'email' => 'attendee.3001@example.test']
            : ['name' => 'Test Instructor A', 'email' => 'instructor.a@example.test'];
        $this->assertSame($expected, $mail['recipients'][0]['address']);

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v1/transmissions', $request->getUri()->getPath());
        $this->assertSame('test-key', $request->getHeaderLine('Authorization'));
    }

    public function testFiveEventMethodsFormatTheStartInChicagoTime(): void
    {
        // winter (CST): 2026-11-04 00:00 UTC is Tuesday evening in Chicago
        $this->accept();
        $this->call('sendEventApproved', '1001', '3001', 'A');
        $this->assertStringContainsString('<b>Time: </b>Tuesday November 3, 6:00PM', $this->sent()[0]['content']['html']);

        // summer (CDT): 17:00 UTC is noon
        $this->history = [];
        $this->accept();
        $this->call('sendEventApproved', '1019', '3001', 'A');
        $this->assertStringContainsString('<b>Time: </b>Wednesday July 15, 12:00PM', $this->sent()[0]['content']['html']);

        // DST edge: 08:00 UTC on the spring-forward day is 3:00 AM CDT
        $this->history = [];
        $this->accept();
        $this->call('sendEventApproved', '1031', '3001', 'U');
        $this->assertStringContainsString('<b>Time: </b>Sunday March 8, 3:00AM', $this->sent()[0]['content']['html']);
    }

    public function testSubmissionMailUsesTheCreatedTimestamp(): void
    {
        $this->accept();
        $this->call('sendEventSubmitted', '1001', '3001', 'A');
        $this->assertStringContainsString('<b>Submission Time: </b>Friday October 2, 7:30AM', $this->sent()[0]['content']['html']);
    }

    public function testLinksAreTheHardCodedPublicCalendarUrls(): void
    {
        $this->accept(2);
        $this->call('sendEventApproved', '1001', '3001', 'A');
        $this->call('sendRegistrationConfirmation', '1001', '3012', 'A');
        [$approved, $confirmed] = $this->sent();

        $this->assertStringContainsString('href="https://calendar.dallasmakerspace.org/events/view/1001"', $approved['content']['html']);
        // the edit key is interpolated raw, exactly as in Cake 3
        $this->assertStringContainsString(
            'href="https://calendar.dallasmakerspace.org/registrations/view/3012?edit_key=a&b=c d"',
            $confirmed['content']['html']
        );
    }

    public function testRejectionWithoutReasonSaysSo(): void
    {
        $this->accept();
        $this->call('sendEventRejected', '1001', '3001', 'A');
        $this->assertStringContainsString('<b>Reason: </b>No additional information given.', $this->sent()[0]['content']['html']);
    }

    public function testSubjectIsCutAtSixtyBytes(): void
    {
        $this->accept();
        $this->call('sendCancellationReminder', '1019', '3001', 'A');
        $subject = $this->sent()[0]['content']['subject'];
        $this->assertSame(60, strlen($subject));
        $this->assertStringEndsWith('...', $subject);
        $this->assertSame('DMS Reminder: Fixture: Cron Ended Approved (paid, has pen...', $subject);
    }

    public function testAnUnusableAddressIsLoggedAndNothingIsSentOrThrown(): void
    {
        $this->call('sendEventApproved', '1040', '3001', 'BAD');
        $this->call('sendRegistrationConfirmation', '1001', 'BAD', 'A');

        $this->assertSame([], $this->sent());
        $logged = Log::engine('test_array')->read();
        $this->assertCount(2, $logged);
        $this->assertStringContainsString('Invalid email set for `to`', $logged[0]);
    }

    public function testATransportFailureIsLoggedNotThrown(): void
    {
        $this->mock->append(new Response(403, [], '{"errors":[{"message":"Forbidden"}]}'));
        $this->call('sendEventApproved', '1001', '3001', 'A');

        $logged = Log::engine('test_array')->read();
        $this->assertCount(1, $logged);
        $this->assertStringContainsString('SparkPost error (403): Forbidden', $logged[0]);
    }

    public function testAnEmptyApiKeyIsLoggedNotThrown(): void
    {
        TransportFactory::drop('sparkpost');
        TransportFactory::setConfig('sparkpost', ['className' => SparkPostTransport::class, 'apiKey' => '']);

        $this->call('sendEventApproved', '1001', '3001', 'A');

        $this->assertStringContainsString('apiKey', Log::engine('test_array')->read()[0]);
    }

    /**
     * Same inputs as the Cake 3 run: the payload matches the golden Cake 3 output byte for byte, except the two
     * documented, deliberate differences (Cake 5 trims the final newline of the html part; non-ASCII recipient names
     * are no longer MIME-encoded inside the JSON payload).
     */
    public function testPayloadsMatchTheCake3Golden(): void
    {
        $golden = json_decode((string)file_get_contents(__DIR__ . '/golden/mail-cake3-payloads.json'), true, 512, JSON_THROW_ON_ERROR);
        $checked = 0;
        foreach ($this->cases['cases'] as $case) {
            if (!isset($golden[$case['name']])) {
                continue;
            }
            $this->history = [];
            $this->accept();
            $event = $this->event($case['event'], $case['contact']);
            $arg = $case['kind'] === 'contact'
                ? new Contact($case['contact'], ['guard' => false])
                : new Registration($case['registration'], ['guard' => false]);
            $this->email->{$case['method']}($arg, $event);

            $expected = $golden[$case['name']];
            $actual = array_map(fn (array $m) => ['content' => $m['content'], 'recipients' => $m['recipients']], $this->sent());
            foreach ($expected as &$mail) {
                // the copyright year is generated with date('Y'); the golden was captured in 2026
                $mail['content']['html'] = str_replace('&copy; 2026 ', '&copy; ' . date('Y') . ' ', $mail['content']['html']);
                $mail['content']['html'] = rtrim($mail['content']['html'], "\n");
                foreach ($mail['recipients'] as &$recipient) {
                    $recipient['address']['name'] = mb_decode_mimeheader($recipient['address']['name']);
                }
                unset($recipient);
            }
            unset($mail);
            $this->assertSame($expected, $actual, $case['name']);
            $checked++;
        }
        $this->assertSame(count($golden), $checked, 'every golden entry must have been exercised');
    }

    public function testTheStockEmailTemplatesAndLayoutsRender(): void
    {
        // templates/email/{html,text}/default.php and templates/layout/email/{html,text}/default.php are the stock Cake
        // files; EmailComponent builds its bodies inline and does not use them, but they must stay valid Cake 5 templates.
        $this->accept();
        $mailer = new Mailer();
        $mailer->setFrom(['admin@dallasmakerspace.org' => 'Dallas Makerspace'])
            ->setTo('someone@example.test')
            ->setSubject('Template check')
            ->setEmailFormat('both')
            ->setViewVars(['content' => "first line\nsecond line"])
            ->viewBuilder()->setTemplate('default')->setLayout('default');
        $mailer->deliver();

        $content = $this->sent()[0]['content'];
        $this->assertStringContainsString('<p> first line</p>', $content['html']);
        $this->assertStringContainsString('<p> second line</p>', $content['html']);
        $this->assertStringContainsString("first line\nsecond line", $content['text']);
    }
}
