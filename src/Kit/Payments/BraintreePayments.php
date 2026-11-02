<?php

declare(strict_types=1);

namespace Dms\Kit\Payments;

use Braintree\Gateway;
use Braintree\Result\Error;
use Braintree\Result\Successful;

/**
 * Replacement for the Braintree 3.x static API used by the calendar.
 *
 *  Old (braintree 3.40, PHP 7.4)                        New (braintree 6.40, PHP 8.4)
 *  Braintree_Configuration::environment()/...           new \Braintree\Gateway([...])   (instance config, no globals)
 *  Braintree_ClientToken::generate()                    $gateway->clientToken()->generate()
 *  Braintree_Transaction::sale([...])                   $gateway->transaction()->sale([...])
 *  Braintree_Transaction::find($id)                     $gateway->transaction()->find($id)
 *  Braintree_Transaction::void($id)                     $gateway->transaction()->void($id)
 *  Braintree_Transaction::refund($id)                   $gateway->transaction()->refund($id)
 *
 * Old call sites: RegistrationsController.php L95-96 (client token), L132-133 (sale), L215-220 (__configureBraintree);
 * RegistrationsTable.php L132-155 (refund(): find L143, void L146, refund L151).
 *
 * Exceptions: the old code never caught Braintree exceptions, so neither does this class. A transaction id the gateway does
 * not know raises \Braintree\Exception\NotFound from find()/void()/refund(); network failures raise
 * \Braintree\Exception\Connection / ServiceUnavailable / GatewayTimeout / RequestTimeout.
 */
final class BraintreePayments
{
    public function __construct(private readonly Gateway $gateway)
    {
    }

    public static function fromConfig(BraintreeConfig $config): self
    {
        return new self(new Gateway($config->toGatewayArray()));
    }

    /** Client token handed to the browser Drop-in (RegistrationsController L96: `$this->set('clientToken', ...)`). */
    public function generateClientToken(): string
    {
        return (string)$this->gateway->clientToken()->generate();
    }

    /** Sale with submitForSettlement=true (RegistrationsController L133). Does not throw on a declined card. */
    public function sale(SaleRequest $request): PaymentResult
    {
        return $this->toResult($this->gateway->transaction()->sale($request->toBraintreeArray()));
    }

    public function find(string $transactionId): TransactionInfo
    {
        $t = $this->gateway->transaction()->find($transactionId);

        return new TransactionInfo(
            (string)$t->id,
            (string)$t->status,
            isset($t->amount) ? (string)$t->amount : null,
            isset($t->type) ? (string)$t->type : null,
        );
    }

    public function void(string $transactionId): PaymentResult
    {
        return $this->toResult($this->gateway->transaction()->void($transactionId));
    }

    /** Full refund (no amount), as the app does. */
    public function refund(string $transactionId): PaymentResult
    {
        return $this->toResult($this->gateway->transaction()->refund($transactionId));
    }

    /**
     * RegistrationsTable::refund() core: void when the original is still `submitted_for_settlement`, otherwise refund.
     * Returns the boolean the old method returned (`$result->success`).
     */
    public function voidOrRefund(string $transactionId): bool
    {
        $transaction = $this->find($transactionId);

        if ($transaction->status === TransactionInfo::STATUS_SUBMITTED_FOR_SETTLEMENT) {
            return $this->void($transactionId)->success;
        }

        return $this->refund($transactionId)->success;
    }

    private function toResult(Successful|Error $result): PaymentResult
    {
        if ($result instanceof Successful) {
            $t = $result->transaction;

            return new PaymentResult(true, (string)$t->id, null, (string)$t->status);
        }

        // Error result: `->message` is what the controller flashes; `->transaction` is only set for processor declines.
        $t = $result->transaction ?? null;

        return new PaymentResult(
            false,
            $t !== null ? (string)$t->id : null,
            isset($result->message) ? (string)$result->message : null,
            $t !== null ? (string)$t->status : null,
        );
    }
}
