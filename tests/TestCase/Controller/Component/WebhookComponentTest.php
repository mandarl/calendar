<?php
namespace App\Test\TestCase\Controller\Component;

use App\Controller\Component\WebhookComponent;
use App\Model\Entity\Event;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\I18n\FrozenTime;
use Cake\I18n\Time;
use Cake\TestSuite\TestCase;

class WebhookComponentTest extends TestCase
{
    public function tearDown()
    {
        parent::tearDown();
        Configure::delete('Webhooks');
    }

    public function testDispatchEventPublishedPostsToEachConfiguredTarget()
    {
        Configure::write('Webhooks.targets', [
            ['name' => 'member-portal', 'url' => 'https://portal.example/webhooks', 'secret' => 's3cr3t'],
        ]);

        $response = $this->getMockBuilder(Response::class)->getMock();
        $response->method('isOk')->willReturn(true);

        $client = $this->getMockBuilder(Client::class)->setMethods(['post'])->getMock();
        $client->expects($this->once())
            ->method('post')
            ->with(
                'https://portal.example/webhooks',
                $this->callback(function ($body) {
                    $decoded = json_decode($body, true);
                    return $decoded['event_type'] === 'event.published'
                        && $decoded['data']['id'] === 1234
                        && $decoded['data']['organizer_username'] === 'jdoe'
                        && $decoded['data']['name'] === 'Test Event'
                        && $decoded['data']['event_start'] === '2026-08-15T18:00:00+00:00'
                        && !isset($decoded['data']['topic'])
                        && !isset($decoded['data']['template'])
                        && !isset($decoded['data']['channel']);
                }),
                $this->callback(function ($options) {
                    return $options['headers'][WebhookComponent::HEADER_SECRET] === 's3cr3t'
                        && $options['headers'][WebhookComponent::HEADER_EVENT] === 'event.published';
                })
            )
            ->willReturn($response);

        $webhook = $this->_makeComponent();
        $webhook->setHttpClient($client);

        // Config::build('datetime')->useImmutable() (config/bootstrap.php)
        // means every real ORM-hydrated Event has a FrozenTime event_start,
        // never a (mutable) Time - this must be FrozenTime, not Time, or
        // this test won't catch a regression to `instanceof Time`.
        $event = new Event([
            'id' => 1234,
            'name' => 'Test Event',
            'created_by' => 'jdoe',
            'event_start' => new FrozenTime('2026-08-15 18:00:00', 'UTC'),
        ]);

        $webhook->dispatchEventPublished($event);
    }

    public function testDeliveryFailureIsLoggedNotThrown()
    {
        Configure::write('Webhooks.targets', [
            ['name' => 'flaky-target', 'url' => 'https://portal.example/webhooks', 'secret' => 's3cr3t'],
        ]);

        $client = $this->getMockBuilder(Client::class)->setMethods(['post'])->getMock();
        $client->method('post')->willThrowException(new \RuntimeException('connection refused'));

        $webhook = $this->_makeComponent();
        $webhook->setHttpClient($client);

        $event = new Event([
            'id' => 1,
            'name' => 'Test Event',
            'created_by' => 'jdoe',
            'event_start' => new Time('2026-08-15 18:00:00', 'UTC'),
        ]);

        // Should not throw.
        $webhook->dispatchEventPublished($event);
        $this->assertTrue(true);
    }

    public function testNoTargetsConfiguredIsANoop()
    {
        Configure::write('Webhooks.targets', []);

        $client = $this->getMockBuilder(Client::class)->setMethods(['post'])->getMock();
        $client->expects($this->never())->method('post');

        $webhook = $this->_makeComponent();
        $webhook->setHttpClient($client);

        $webhook->dispatch('event.published', ['id' => 1]);
    }

    private function _makeComponent(): WebhookComponent
    {
        $registry = new ComponentRegistry(new Controller());
        $webhook = new WebhookComponent($registry);
        $webhook->initialize([]);
        return $webhook;
    }
}
