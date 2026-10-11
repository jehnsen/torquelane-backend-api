<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Crm\CrmContexts;
use App\Actions\Crm\ManageFeedback;
use App\Actions\Crm\ManageLoyalty;
use App\Events\PaymentReceived;
use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * What the CRM does once a payment has been recorded (after its transaction
 * commits, off the request): loyalty points where the organization has them
 * on, and a feedback request for each invoice the payment paid off. Only for
 * organizations with the `crm` module on; both steps are safe to run twice.
 */
final class CrmOnPaymentReceived implements ShouldQueue
{
    public function __construct(
        private readonly CrmContexts $contexts,
        private readonly ManageLoyalty $loyalty,
        private readonly ManageFeedback $feedback,
    ) {}

    public function handle(PaymentReceived $event): void
    {
        if (! in_array($event->organizationId, $this->contexts->organizations(), true)) {
            return;
        }
        $this->contexts->as($event->organizationId, function () use ($event): void {
            $payment = Payment::query()->find($event->documentId);
            if ($payment instanceof Payment) {
                $this->loyalty->earnFor($payment);
                $this->feedback->requestFor($payment);
            }
        });
    }
}
