<?php

declare(strict_types=1);

namespace Dms\Kit\Cake;

use Cake\Core\Exception\CakeException;
use Cake\Mailer\AbstractTransport;
use Cake\Mailer\Message;
use Dms\Kit\Mail\SparkPostApiTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;

/**
 * Cake 5 mail transport for the SparkPost HTTP API; the replacement for the Cake 3 plugin
 * `SparkPost.SparkPost` (className in config/app.default.php 'EmailTransport' => 'sparkpost').
 *
 *   // config/app.php
 *   'EmailTransport' => [
 *       'sparkpost' => [
 *           'className' => \Dms\Kit\Cake\SparkPostTransport::class,
 *           'apiKey'    => env('SPARKPOST_APIKEY'),
 *           'base_url'  => env('SPARKPOST_BASE_URL', 'https://api.sparkpost.com'),   // fake endpoint in the harness
 *           'timeout'   => 30,                                                       // seconds
 *       ],
 *   ],
 *   // EmailComponent keeps working: (new Mailer())->setTransport('sparkpost') ... ->deliver($html)  inside try/catch (Throwable)
 *
 * Config keys: apiKey (required), base_url, timeout, options (extra transmission options; click_tracking is always false),
 * client (a PSR-18 ClientInterface; mainly for tests, otherwise a Guzzle 7 client with `timeout` is built).
 *
 * Errors: every failure (HTTP status >= 400, network, no accepted recipient) throws Dms\Kit\Mail\MailException, a RuntimeException,
 * so `catch (Throwable)` in EmailComponent::sendEmail() keeps logging instead of failing the request. Missing apiKey throws
 * Cake\Core\Exception\CakeException at send time.
 *
 * Returns Cake's usual ['headers' => string, 'message' => string] plus a 'sparkpost' key with ['id', 'accepted', 'rejected'].
 */
class SparkPostTransport extends AbstractTransport
{
    /** @var array<string,mixed> */
    protected array $_defaultConfig = [
        'apiKey' => null,
        'base_url' => 'https://api.sparkpost.com',
        'timeout' => 30,
        'options' => [],
        'client' => null,
    ];

    private ?SparkPostApiTransport $api = null;

    public function send(Message $message): array
    {
        $this->checkRecipient($message);

        $result = $this->api()->send(MessageMapper::toMailMessage($message));

        return [
            'headers' => $message->getHeadersString(['from', 'sender', 'replyTo', 'readReceipt', 'returnPath', 'to', 'cc', 'subject']),
            'message' => implode("\r\n", $message->getBody()),
            'sparkpost' => $result,
        ];
    }

    private function api(): SparkPostApiTransport
    {
        if ($this->api === null) {
            $apiKey = (string)$this->getConfig('apiKey');
            if ($apiKey === '') {
                throw new CakeException('SparkPost transport: config key `apiKey` is empty (SPARKPOST_APIKEY)');
            }
            $client = $this->getConfig('client');
            if (!$client instanceof ClientInterface) {
                $client = new Client([
                    'timeout' => (float)$this->getConfig('timeout'),
                    'connect_timeout' => min(10.0, (float)$this->getConfig('timeout')),
                    'http_errors' => false,
                ]);
            }
            $factory = new HttpFactory();
            $this->api = new SparkPostApiTransport(
                $apiKey,
                $client,
                $factory,
                $factory,
                (string)$this->getConfig('base_url'),
                (array)$this->getConfig('options'),
            );
        }

        return $this->api;
    }
}
