<?php
namespace App\Controller\Component;

use App\Model\Entity\Event;
use Cake\Chronos\ChronosInterface;
use Cake\Controller\Component;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\I18n\Time;
use Cake\Log\Log;
use Cake\Routing\Router;
use Throwable;

/**
 * Fires generic, calendar-shaped outbound webhooks to a configurable list of
 * targets (see Configure::read('Webhooks')) when calendar events reach
 * lifecycle milestones (e.g. publish). Best-effort: failures are logged and
 * never thrown, so callers never need to guard against delivery problems.
 */
class WebhookComponent extends Component
{
    public const HEADER_SECRET = 'X-DMS-Webhook-Secret';
    public const HEADER_EVENT = 'X-DMS-Webhook-Event';

    protected $_targets = [];
    protected $_timeout = 3;
    protected $_httpClient = null;

    public function initialize(array $config = [])
    {
        parent::initialize($config);
        $this->_targets = (array) Configure::read('Webhooks.targets');
        $this->_timeout = (int) Configure::read('Webhooks.timeout') ?: 3;
    }

    /**
     * Test/DI seam - inject a fake client instead of a real one.
     * @param Client $client The HTTP client to use for delivery.
     * @return void
     */
    public function setHttpClient(Client $client): void
    {
        $this->_httpClient = $client;
    }

    protected function _getHttpClient(): Client
    {
        if ($this->_httpClient === null) {
            $this->_httpClient = new Client();
        }
        return $this->_httpClient;
    }

    /**
     * Convenience wrapper for the 'event.published' webhook.
     * @param Event $event The event that was just published (status became 'approved').
     * @return void
     */
    public function dispatchEventPublished(Event $event): void
    {
        // Match against ChronosInterface, not the concrete Time class - the
        // app enables immutable ORM datetimes (see config/bootstrap.php),
        // so event_start actually hydrates as FrozenTime, not Time. Time and
        // FrozenTime are siblings (no inheritance relationship), so an
        // `instanceof Time` check silently misses every real event and
        // always sent a null event_start.
        $start = $event->event_start instanceof ChronosInterface
            ? $event->event_start->setTimezone('UTC')->toAtomString()
            : null;

        $this->dispatch('event.published', [
            'id' => $event->id,
            'organizer_username' => $event->created_by,
            'name' => $event->name,
            'event_start' => $start,
            'event_start_timezone' => 'UTC',
            'url' => Router::url(['controller' => 'Events', 'action' => 'view', 'id' => $event->id], true),
        ]);
    }

    /**
     * Generic entry point - future event types (event.full, event.vacancy_opened)
     * reuse this unchanged.
     * @param string $eventType Dot-namespaced event type, e.g. 'event.published'.
     * @param array $data Calendar-shaped event data - no consumer-specific concepts.
     * @return void
     */
    public function dispatch(string $eventType, array $data): void
    {
        if (empty($this->_targets)) {
            return;
        }

        $envelope = [
            'event_type' => $eventType,
            'occurred_at' => (new Time())->toAtomString(),
            'data' => $data,
        ];

        foreach ($this->_targets as $target) {
            $this->_send($target, $eventType, $envelope);
        }
    }

    private function _send(array $target, string $eventType, array $envelope): void
    {
        if (empty($target['url'])) {
            return;
        }

        try {
            $response = $this->_getHttpClient()->post(
                $target['url'],
                json_encode($envelope),
                [
                    'type' => 'json',
                    'timeout' => $this->_timeout,
                    'headers' => [
                        self::HEADER_SECRET => $target['secret'] ?? '',
                        self::HEADER_EVENT => $eventType,
                    ],
                ]
            );

            if (!$response->isOk()) {
                Log::warning(sprintf(
                    'Webhook delivery to %s (%s) failed with status %d for event %s',
                    $target['name'] ?? $target['url'],
                    $target['url'],
                    $response->getStatusCode(),
                    $eventType
                ));
            }
        } catch (Throwable $th) {
            Log::warning(sprintf(
                'Webhook delivery to %s (%s) threw %s: %s for event %s',
                $target['name'] ?? $target['url'],
                $target['url'],
                get_class($th),
                $th->getMessage(),
                $eventType
            ));
        }
    }
}
