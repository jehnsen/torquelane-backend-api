<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\Audit\AuditTrail;
use App\Domain\Receivables\CreditPosition;
use App\Domain\Shared\WebFormat;
use App\Models\CustomerAccount;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Log;

/**
 * The credit-limit check on new work: an account whose open invoices (less
 * its unallocated credit) already exceed its limit still gets the work (the
 * limit WARNS, it never blocks), and the override is logged: an audit row on
 * the new order, in the caller's transaction, plus a log line.
 */
final class CreditLimit
{
    public function __construct(
        private readonly BillingQueries $billing,
        private readonly AuditTrail $audit,
    ) {}

    /** The position if the account is over its limit (override recorded on $order), else null. */
    public function checkNewWork(CustomerAccount $account, WorkOrder $order): ?CreditPosition
    {
        $position = $this->billing->creditPosition($account);
        if (! $position->isOverLimit()) {
            return null;
        }

        $details = [
            'credit_limit_cents' => $position->creditLimitCents,
            'outstanding_cents' => $position->outstandingCents,
            'credit_cents' => $position->creditCents,
            'exposure_cents' => $position->exposureCents(),
        ];
        $this->audit->record($order, 'credit_limit_override', null, $details);
        Log::warning('billing.credit_limit_override', ['customer_account_id' => $account->id, 'work_order_id' => $order->id] + $details);

        return $position;
    }

    /**
     * What the API answers alongside the new order.
     *
     * @return array{code: string, message: string, details: array<string, int|null>}
     */
    public static function warning(CreditPosition $position): array
    {
        return [
            'code' => 'credit_limit_exceeded',
            'message' => sprintf(
                'This account owes %s against a credit limit of %s. The work was raised; the override is logged.',
                WebFormat::pesos($position->exposureCents()),
                WebFormat::pesos((int) $position->creditLimitCents),
            ),
            'details' => [
                'credit_limit_cents' => $position->creditLimitCents,
                'outstanding_cents' => $position->outstandingCents,
                'credit_cents' => $position->creditCents,
                'available_credit_cents' => $position->availableCents(),
            ],
        ];
    }
}
