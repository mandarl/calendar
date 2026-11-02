<?php

declare(strict_types=1);

namespace Dms\Kit\Sms;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Twilio\Exceptions\TwilioException;
use Twilio\Http\Client as TwilioHttpClient;
use Twilio\Rest\Client;

/**
 * Replacement for EventsController::__sendText() (src/Controller/EventsController.php L2062-L2089, called from the event-cancel
 * `afterSave` at L1497 and from two commented-out reminder paths at L1612 / L1641).
 *
 *  Old (twilio/sdk 4.12, uses create_function => fatal on PHP 8)       New (twilio/sdk 8.13)
 *  new \Services_Twilio($sid, $token)                                   new \Twilio\Rest\Client($sid, $token)
 *  $client->account->messages->create(['From','To','Body'])             $client->messages->create($to, ['from' => .., 'body' => ..])
 *  catch \Services_Twilio_RestException                                 catch \Twilio\Exceptions\TwilioException (superset)
 *
 * Behaviour reproduced:
 *  - the number is stripped to digits; a leading "1" is prepended when the digits do not already start with 1;
 *  - only an 11 digit result is sent (to "+" . digits); anything else is silently skipped;
 *  - an API error is logged and swallowed (the cancel loop must carry on to the refund);
 *  - NEW: unset credentials are logged and skipped (NotConfigured) instead of reaching the SDK, see README.md, Known incompatibilities.
 * Deliberate difference: the old code only caught Services_Twilio_RestException; the new one catches the SDK's base
 * TwilioException so a transport failure (EnvironmentException) is also
 * logged instead of aborting the cancellation loop half-way through the refunds.
 */
final class TwilioSmsSender
{
    public function __construct(
        private readonly ?Client $client,
        private readonly string $fromNumber,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param TwilioHttpClient|null $http inject a fake in tests; null uses the SDK's cURL client
     */
    public static function fromConfig(TwilioConfig $config, ?LoggerInterface $logger = null, ?TwilioHttpClient $http = null): self
    {
        // Twilio SDK 8 throws a TypeError (not a TwilioException) from Client::messages when the account sid is empty,
        // so an unconfigured client (empty TWILIO_* values, as in the e2e harness) is represented by null.
        $configured = $config->accountSid !== "" && $config->authToken !== "" && $config->fromNumber !== "";

        return new self(
            $configured ? new Client($config->accountSid, $config->authToken, null, null, $http) : null,
            $config->fromNumber,
            $logger ?? new NullLogger(),
        );
    }

    /**
     * Same digit rules as the old private method. Returns the E.164 number that would be texted, or null when the input
     * does not normalise to 11 digits.
     */
    public static function normalizeNumber(?string $to): ?string
    {
        $digits = (string)preg_replace('/\D/', '', (string)$to);
        if (substr($digits, 0, 1) !== '1') {
            $digits = '1' . $digits;
        }

        return strlen($digits) === 11 ? '+' . $digits : null;
    }

    public function sendText(?string $to, string $message): SmsOutcome
    {
        $e164 = self::normalizeNumber($to);
        if ($e164 === null) {
            return SmsOutcome::SkippedInvalidNumber;
        }

        if ($this->client === null) {
            $this->logger->warning("Twilio is not configured (TWILIO_ACCTSID / TWILIO_AUTHTOKEN / TWILIO_PHONENUM); SMS not sent");

            return SmsOutcome::NotConfigured;
        }

        try {
            $this->client->messages->create($e164, [
                'from' => $this->fromNumber,
                'body' => $message,
            ]);
        } catch (TwilioException $e) {
            $this->logger->error('Twilio send failed: ' . $e->getMessage(), ['exception' => $e]);

            return SmsOutcome::Failed;
        }

        return SmsOutcome::Sent;
    }
}
