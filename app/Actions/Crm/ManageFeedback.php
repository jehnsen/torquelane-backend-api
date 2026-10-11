<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Domain\Crm\MessagePurpose;
use App\Domain\Crm\Nps;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\CustomerAccount;
use App\Models\FeedbackRequest;
use App\Models\Invoice;
use App\Models\InvoiceBooking;
use App\Models\InvoiceWorkOrder;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Post-payment feedback. When a payment settles an invoice in full, the
 * customer is asked once for a 0–10 score, by a message that goes through the
 * `Messenger` like any other (so it is consent-checked, and logged as skipped
 * where it may not go). The request hangs on the job the invoice billed and
 * on the customer.
 */
final class ManageFeedback
{
    public function __construct(
        private readonly CrmSettings $settings,
        private readonly Messenger $messenger,
    ) {}

    /**
     * Ask about every invoice this payment has just paid off.
     *
     * @return list<FeedbackRequest>
     */
    public function requestFor(Payment $payment): array
    {
        if (! $this->settings->of($payment->organization_id)->feedback_enabled) {
            return [];
        }
        $template = MessageTemplate::query()->where('purpose', MessagePurpose::Feedback->value)->where('is_active', true)->orderBy('name')->first();
        if (! $template instanceof MessageTemplate) {
            return [];
        }

        $requests = [];
        $invoiceIds = PaymentAllocation::query()->where('payment_id', $payment->id)->pluck('invoice_id')->all();
        foreach (Invoice::query()->whereIn('id', $invoiceIds)->where('status', InvoiceStatus::Paid)->orderBy('id')->get() as $invoice) {
            $key = "feedback:{$invoice->id}";
            if (FeedbackRequest::query()->where('invoice_id', $invoice->id)->exists() || Message::query()->where('dedupe_key', $key)->exists()) {
                continue;
            }
            $request = DB::transaction(function () use ($invoice, $template, $key): ?FeedbackRequest {
                $account = CustomerAccount::query()->findOrFail($invoice->customer_account_id);
                $message = $this->messenger->queue($account, $template, ['invoice' => (string) $invoice->number], 'feedback', $invoice->id, 'invoice', $invoice->id, $key, $invoice->branch_id, 'feedback', false);
                if ($message->token_hash === null) {
                    return null;
                }
                $request = new FeedbackRequest;
                $request->forceFill([
                    'organization_id' => $invoice->organization_id,
                    'branch_id' => $invoice->branch_id,
                    'customer_account_id' => $invoice->customer_account_id,
                    'invoice_id' => $invoice->id,
                    'work_order_id' => InvoiceWorkOrder::query()->where('invoice_id', $invoice->id)->whereNull('released_at')->orderBy('id')->value('work_order_id'),
                    'detailing_booking_id' => InvoiceBooking::query()->where('invoice_id', $invoice->id)->whereNull('released_at')->orderBy('id')->value('booking_id'),
                    'token_hash' => $message->token_hash,
                    'message_id' => $message->id,
                    'requested_at' => CarbonImmutable::now(),
                ])->save();

                return $request;
            });
            if ($request !== null) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /**
     * The organization's score, and the answers behind it, newest first.
     *
     * @return array{summary: array{responses: int, promoters: int, passives: int, detractors: int, nps: int|null, requested: int}, answers: list<FeedbackRequest>}
     */
    public function overview(?string $accountId = null): array
    {
        $query = FeedbackRequest::query()->when($accountId !== null, fn ($q) => $q->where('customer_account_id', $accountId));
        $answers = (clone $query)->whereNotNull('responded_at')->with('customerAccount')->orderByDesc('responded_at')->limit(200)->get();
        $scores = array_values(array_map(fn (mixed $score): int => is_numeric($score) ? (int) $score : 0, (clone $query)->whereNotNull('score')->pluck('score')->all()));

        return ['summary' => Nps::summarize($scores) + ['requested' => (clone $query)->count()], 'answers' => array_values($answers->all())];
    }
}
