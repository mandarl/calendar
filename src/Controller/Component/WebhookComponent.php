<?php
declare(strict_types=1);

namespace App\Controller\Component;

use App\Model\Entity\Event;
use Cake\Controller\Component;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\Routing\Router;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Throwable;

/**
 * Fires generic, calendar-shaped outbound webhooks to a configurable list of
 * targets (see Configure::read('Webhooks')) when calendar events reach
 * lifecycle milestones (e.g. publish). Best-effort: failures are logged and
 * never thrown, so callers never need to guard against delivery problems.
 *
 * Cake 5 port: the HTTP client is Guzzle 7 (Cake 3 used Cake\Http\Client). Request shape is unchanged: a POST with the
 * JSON envelope as the body, `Content-Type`/`Accept: application/json`, the shared secret in `X-DMS-Webhook-Secret`
 * (a plain shared-secret header, there is no HMAC signature in the Cake 3 code either) and the event type in
 * `X-DMS-Webhook-Event`.
 */
class WebhookComponent extends Component
{
    public const HEADER_SECRET = 'X-DMS-Webhook-Secret';
    public const HEADER_EVENT = 'X-DMS-Webhook-Event';

    /** Status codes Cake 3's Response::isOk() accepted (200, 201, 202, 203, 204). Anything else is logged as a failure. */
    private const OK_STATUSES = [200, 201, 202, 203, 204];

    protected array $_targets = [];
    protected int $_timeout = 3;
    protected ?ClientInterface $_httpClient = null;

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->_targets = (array)Configure::read('Webhooks.targets');
        $this->_timeout = (int)Configure::read('Webhooks.timeout') ?: 3;
    }

    /**
     * Test/DI seam - inject a fake client instead of a real one.
     *
     * @param \GuzzleHttp\ClientInterface $client The HTTP client to use for delivery.
     * @return void
     */
    public function setHttpClient(ClientInterface $client): void
    {
        $this->_httpClient = $client;
    }

    protected function _getHttpClient(): ClientInterface
    {
        if ($this->_httpClient === null) {
            $this->_httpClient = new Client();
        }

        return $this->_httpClient;
    }

    /**
     * Convenience wrapper for the 'event.published' webhook.
     *
     * @param \App\Model\Entity\Event $event The event that was just published (status became 'approved').
     * @return void
     */
    public function dispatchEventPublished(Event $event): void
    {
        // Cake 3 matched `ChronosInterface` (the ORM hydrated FrozenTime, a sibling of Time, and an `instanceof Time`
        // check silently sent a null event_start for every real event). In Cake 5 the ORM hydrates
        // Cake\I18n\DateTime; any DateTimeInterface is accepted, and the entity's own object is never modified.
        $start = $event->event_start instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($event->event_start)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DATE_ATOM)
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
     *
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
            'occurred_at' => (new DateTime())->toAtomString(),
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
            // Cake 3's client dropped a header whose value was empty, so a target without a secret got no secret header at all
            // (Guzzle would send it empty). It also announced itself as "CakePHP"; kept so the receiver sees the same request.
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'CakePHP',
            ];
            if ((string)($target['secret'] ?? '') !== '') {
                $headers[self::HEADER_SECRET] = $target['secret'];
            }
            $headers[self::HEADER_EVENT] = $eventType;

            $response = $this->_getHttpClient()->request('POST', $target['url'], [
                'body' => json_encode($envelope),
                'headers' => $headers,
                'timeout' => $this->_timeout,
                // Cake 3's client neither threw on 4xx/5xx nor followed redirects: those are judged below.
                'http_errors' => false,
                'allow_redirects' => false,
            ]);

            if (!in_array($response->getStatusCode(), self::OK_STATUSES, true)) {
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
