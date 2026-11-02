<?php

declare(strict_types=1);

namespace Dms\Kit\Payments;

/** Result of Braintree `Transaction::find()`: the app only reads `->status`. */
final class TransactionInfo
{
    public const STATUS_SUBMITTED_FOR_SETTLEMENT = 'submitted_for_settlement';

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly ?string $amount = null,
        public readonly ?string $type = null,
    ) {
    }
}
