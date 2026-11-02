<?php

declare(strict_types=1);

namespace Dms\Kit\Payments;

/**
 * What the controllers read from a Braintree result: `$result->success`, `$result->transaction->id`, `$result->message`
 * (RegistrationsController L133-L151, RegistrationsTable::refund L146-L153).
 */
final class PaymentResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $transactionId = null,
        public readonly ?string $message = null,
        public readonly ?string $status = null,
    ) {
    }
}
