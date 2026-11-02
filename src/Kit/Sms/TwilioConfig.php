<?php

declare(strict_types=1);

namespace Dms\Kit\Sms;

/**
 * Old source: config/app.default.php 'Twilio' => [accountSid, authToken, phone] from TWILIO_ACCTSID / TWILIO_AUTHTOKEN /
 * TWILIO_PHONENUM. All three are empty in the e2e harness (baseline.env), so this class does NOT reject empty values;
 * the SDK raises ConfigurationException at request time and TwilioSmsSender logs it.
 */
final class TwilioConfig
{
    public function __construct(
        public readonly string $accountSid,
        public readonly string $authToken,
        public readonly string $fromNumber,
    ) {
    }

    /** @param array<string,string|false|null> $env */
    public static function fromEnv(array $env): self
    {
        return new self(
            (string)($env['TWILIO_ACCTSID'] ?? ''),
            (string)($env['TWILIO_AUTHTOKEN'] ?? ''),
            (string)($env['TWILIO_PHONENUM'] ?? ''),
        );
    }
}
