<?php

declare(strict_types=1);

namespace Dms\Kit\Payments;

/**
 * Inputs of the sale in RegistrationsController::add() beforeSave (L120-L147).
 */
final class SaleRequest
{
    public function __construct(
        public readonly string|int|float $amount,
        public readonly string $paymentMethodNonce,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly int|string $eventId,
        public readonly string $eventName,
    ) {
    }

    /**
     * Build from the registration "name" field exactly the way the controller does (see NameSplitter).
     */
    public static function forRegistration(
        string|int|float $amount,
        string $paymentMethodNonce,
        ?string $email,
        ?string $phone,
        string $registrationName,
        int|string $eventId,
        string $eventName,
    ): self {
        [$first, $last] = NameSplitter::split($registrationName);

        return new self($amount, $paymentMethodNonce, $email, $phone, $first, $last, $eventId, $eventName);
    }

    /**
     * The exact array the old code passed to Braintree_Transaction::sale().
     *
     * @return array<string,mixed>
     */
    public function toBraintreeArray(): array
    {
        return [
            'amount' => $this->amount,
            'paymentMethodNonce' => $this->paymentMethodNonce,
            'options' => ['submitForSettlement' => true],
            'customer' => [
                'email' => $this->email,
                'phone' => $this->phone,
                'firstName' => $this->firstName,
                'lastName' => $this->lastName,
            ],
            'customFields' => [
                'event_id' => $this->eventId,
                'event_name' => $this->eventName,
            ],
        ];
    }
}
