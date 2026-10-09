<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\InvoiceIssued;
use App\Events\InvoiceVoided;
use App\Events\PaymentReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Takes every billing event off the request, onto the queue. Today it records
 * the event in the log; an e-invoicing submission (EIS) integration replaces
 * or joins it here, without touching the actions that raise the events.
 */
final class PublishBillingEvent implements ShouldQueue
{
    public function handle(InvoiceIssued|InvoiceVoided|PaymentReceived $event): void
    {
        Log::info('billing.event', [
            'event' => $event->name(),
            'organization_id' => $event->organizationId,
            'document_id' => $event->documentId,
            'number' => $event->number,
        ]);
    }
}
