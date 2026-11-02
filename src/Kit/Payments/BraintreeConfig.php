<?php

declare(strict_types=1);

namespace Dms\Kit\Payments;

use InvalidArgumentException;

/**
 * Braintree credentials. Old source: config/app.default.php 'Braintree' => [environment, merchantId, publicKey,
 * privateKey] fed from BRAINTREE_ENV / BRAINTREE_MERCHID / BRAINTREE_PUBKEY / BRAINTREE_PRIVKEY.
 *
 * Environment 'development' makes the SDK talk plain HTTP to http://localhost:$GATEWAY_PORT (default 3000); that is how the
 * e2e harness fake gateway is reached. 'sandbox' / 'production' use the real HTTPS endpoints.
 */
final class BraintreeConfig
{
    public function __construct(
        public readonly string $environment,
        public readonly string $merchantId,
        public readonly string $publicKey,
        public readonly string $privateKey,
    ) {
        foreach (['environment', 'merchantId', 'publicKey', 'privateKey'] as $field) {
            if ($this->{$field} === '') {
                throw new InvalidArgumentException("Braintree config value '$field' must not be empty");
            }
        }
    }

    /**
     * @param array<string,string|false|null> $env e.g. $_ENV, getenv(), or values read through Cake's env()
     */
    public static function fromEnv(array $env): self
    {
        return new self(
            (string)($env['BRAINTREE_ENV'] ?? ''),
            (string)($env['BRAINTREE_MERCHID'] ?? ''),
            (string)($env['BRAINTREE_PUBKEY'] ?? ''),
            (string)($env['BRAINTREE_PRIVKEY'] ?? ''),
        );
    }

    /** @return array{environment:string,merchantId:string,publicKey:string,privateKey:string} */
    public function toGatewayArray(): array
    {
        return [
            'environment' => $this->environment,
            'merchantId' => $this->merchantId,
            'publicKey' => $this->publicKey,
            'privateKey' => $this->privateKey,
        ];
    }
}
