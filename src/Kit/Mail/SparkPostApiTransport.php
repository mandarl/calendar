<?php

declare(strict_types=1);

namespace Dms\Kit\Mail;

use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * SparkPost HTTP API transport (POST {base_url}/api/v1/transmissions) over any PSR-18 client. Replaces
 * vendor/dallasmakerspace/cakephp-sparkpost-plugin/src/Mailer/Transport/SparkPostTransport.php (97 lines, Cake 3 only; pulls
 * sparkpost/sparkpost 2.3 + php-http/guzzle6-adapter + Guzzle 6 + a stray php-coveralls runtime require).
 *
 * What the OLD plugin sent (read from the source, nothing else was sent):
 *   content.from{name,email}, content.subject, content.html (text when html is empty), content.text, recipients[].address{name,email}
 *   (To only), and a top-level `replyTo` (a key the API does not know; never set by the app).
 *   NO `options` object at all: open_tracking, click_tracking, transactional, sandbox, campaign_id, ip_pool, ... were all left to
 *   the SparkPost defaults/account settings.
 *
 * What this class sends: the same fields, plus
 *   options.click_tracking = false   ALWAYS (owner decision: links must not be rewritten). Nothing else is added to `options`
 *                                    unless you pass $options; open_tracking is deliberately NOT set (unchanged from before).
 * and, only when the message has them: content.reply_to, content.headers (+ CC header), cc/bcc recipients (header_to), the same
 * way the official SparkPost PHP library formats them.
 *
 * Deviations from the plugin, all bug fixes: names are plain UTF-8 (the plugin mime-encoded them, double-encoding non-ASCII names);
 * reply-to is placed in `content.reply_to`; a 2xx with zero accepted recipients is reported as an error.
 *
 * Authentication: `Authorization: <api key>` (no Bearer prefix). Base URL is configurable: US https://api.sparkpost.com (default),
 * EU https://api.eu.sparkpost.com, or a fake endpoint (tests/fakes/fake-sparkpost.mjs).
 * Nothing here has been run against the real service (no credentials, no network); tests use mocks and the fake endpoint.
 */
final class SparkPostApiTransport implements MailTransport
{
    /** @var array<string,mixed> */
    private readonly array $options;

    /**
     * @param array<string,mixed> $options extra transmission `options` (e.g. ['transactional' => true]); `click_tracking` may not be enabled
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly string $endpoint = 'https://api.sparkpost.com',
        array $options = [],
    ) {
        if (($options['click_tracking'] ?? false) !== false) {
            throw new InvalidArgumentException('Link tracking must stay disabled: options.click_tracking cannot be enabled');
        }
        $options['click_tracking'] = false;
        $this->options = $options;
    }

    /** @return array<string,mixed> the JSON body that will be POSTed (public for tests and for logging redacted copies) */
    public function buildPayload(MailMessage $m): array
    {
        $fromEmail = (string)array_key_first($m->from);
        $content = [
            'from' => ['name' => (string)$m->from[$fromEmail], 'email' => $fromEmail],
            'subject' => $m->subject,
            'html' => $m->html === '' ? $m->text : $m->html,
            'text' => $m->text,
        ];
        if ($m->replyTo !== []) {
            $content['reply_to'] = implode(', ', array_map(self::formatAddress(...), array_keys($m->replyTo), $m->replyTo));
        }

        $recipients = [];
        foreach ($m->to as $email => $name) {
            $recipients[] = ['address' => ['name' => $name, 'email' => (string)$email]];
        }

        $headers = $m->headers;
        if ($m->cc !== [] || $m->bcc !== []) {
            // Same encoding as the official library: every Cc/Bcc address becomes an extra recipient whose header_to is the first To
            // address (so each sees the primary recipient in To:); only Cc is advertised in a CC header, Bcc is never named.
            $primaryEmail = (string)array_key_first($m->to);
            $headerTo = self::formatAddress($primaryEmail, $m->to[$primaryEmail]);
            if ($m->cc !== []) {
                $headers['CC'] = implode(',', array_map(self::formatAddress(...), array_keys($m->cc), $m->cc));
            }
            foreach ([...array_keys($m->cc), ...array_keys($m->bcc)] as $email) {
                $recipients[] = ['address' => ['email' => (string)$email, 'header_to' => $headerTo]];
            }
        }
        if ($headers !== []) {
            $content['headers'] = $headers;
        }

        return ['content' => $content, 'recipients' => $recipients, 'options' => $this->options];
    }

    public function send(MailMessage $message): array
    {
        if ($message->from === [] || $message->to === []) {
            throw new MailException('A message needs at least one From and one To address');
        }

        $body = json_encode($this->buildPayload($message), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $request = $this->requests->createRequest('POST', rtrim($this->endpoint, '/') . '/api/v1/transmissions')
            ->withHeader('Authorization', $this->apiKey)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream($body));

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new MailException('SparkPost error (0): ' . ucfirst($e->getMessage()), 0, $e);
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string)$response->getBody(), true);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($decoded) ? ($decoded['errors'][0]['message'] ?? $decoded['errors'][0]['description'] ?? null) : null;
            throw new MailException(sprintf('SparkPost error (%d): %s', $status, ucfirst((string)($detail ?? $response->getReasonPhrase()))));
        }

        $results = is_array($decoded) && is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
        $accepted = isset($results['total_accepted_recipients']) ? (int)$results['total_accepted_recipients'] : null;
        if ($accepted === 0) {
            throw new MailException(sprintf(
                'SparkPost error (%d): no recipient was accepted (%d rejected)',
                $status,
                (int)($results['total_rejected_recipients'] ?? 0),
            ));
        }

        return [
            'id' => isset($results['id']) ? (string)$results['id'] : null,
            'accepted' => $accepted,
            'rejected' => isset($results['total_rejected_recipients']) ? (int)$results['total_rejected_recipients'] : null,
        ];
    }

    /** `"Name" <email>`, or just the email when there is no distinct name (Cake uses the address as the name when none is given). */
    private static function formatAddress(string|int $email, string $name): string
    {
        $email = (string)$email;

        return ($name === '' || $name === $email) ? $email : '"' . str_replace('"', '', $name) . '" <' . $email . '>';
    }
}
