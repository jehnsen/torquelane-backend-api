<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Domain\Crm\ConsentChannel;
use App\Domain\Crm\MessageChannel;
use App\Domain\Crm\Nps;
use App\Domain\Shared\Calendar;
use App\Models\Branch;
use App\Models\Consent;
use App\Models\CrmTask;
use App\Models\CustomerAccount;
use App\Models\DetailingSetting;
use App\Models\FeedbackRequest;
use App\Models\Message;
use App\Models\MessageSuppression;
use App\Models\Organization;
use App\Models\Vehicle;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the links inside a message open, with no sign-in: the token in the
 * link (160 bits; only its SHA-256 is stored) is the credential, and it
 * reaches that one message's own subject and nothing else.
 *
 *  - a reminder's booking link: what is due, and either the branch's online
 *    booking page or a booking request that lands on the staff's task list;
 *  - a feedback link: one 0–10 answer, once;
 *  - the unsubscribe link: the address is suppressed and consent for that
 *    purpose is withdrawn on the ledger.
 */
final class PublicLinks
{
    public function __construct(private readonly TenantManager $tenancy) {}

    /**
     * @return array<string, mixed>
     */
    public function reminder(string $token): array
    {
        return $this->tenancy->system('crm public link', function () use ($token): array {
            $message = $this->message($token, 'reminder');

            return $this->reminderView($message);
        });
    }

    /**
     * The customer asks to be booked: a `booking_request` task for the staff, once per reminder.
     *
     * @return array<string, mixed>
     */
    public function requestBooking(string $token, ?string $preferredDate, ?string $note): array
    {
        return $this->tenancy->system('crm public link', fn (): array => DB::transaction(function () use ($token, $preferredDate, $note): array {
            $message = Message::query()->whereKey($this->message($token, 'reminder')->id)->lockForUpdate()->firstOrFail();
            if ($message->responded_at === null) {
                $today = Calendar::toDate(CarbonImmutable::now());
                if ($preferredDate !== null && $preferredDate < $today) {
                    throw ValidationException::withMessages(['preferred_date' => 'Pick today or a later day.']);
                }
                $vehicle = $message->subject_type === 'vehicle' && $message->subject_id !== null ? Vehicle::query()->find($message->subject_id) : null;
                $account = CustomerAccount::query()->findOrFail($message->customer_account_id);
                (new CrmTask)->forceFill([
                    'organization_id' => $message->organization_id,
                    'customer_account_id' => $account->id,
                    'vehicle_id' => $vehicle?->id,
                    'title' => 'Booking request: '.($vehicle === null ? $account->display_name : "{$vehicle->plate_number} ({$account->display_name})"),
                    'details' => trim(($message->subject ?? 'Service reminder').($note === null ? '' : "\n\nCustomer's note: {$note}")),
                    'kind' => 'booking_request',
                    'due_on' => $preferredDate ?? $today,
                    'source_message_id' => $message->id,
                    'created_by_name' => $message->to_name ?? $account->display_name,
                ])->save();
                $message->forceFill(['responded_at' => CarbonImmutable::now()])->save();
            }

            return $this->reminderView($message);
        }));
    }

    /**
     * @return array<string, mixed>
     */
    public function feedback(string $token): array
    {
        return $this->tenancy->system('crm public link', fn (): array => $this->feedbackView($this->feedbackRequest($token)));
    }

    /**
     * @return array<string, mixed>
     */
    public function answerFeedback(string $token, int $score, ?string $comment): array
    {
        return $this->tenancy->system('crm public link', fn (): array => DB::transaction(function () use ($token, $score, $comment): array {
            $request = FeedbackRequest::query()->whereKey($this->feedbackRequest($token)->id)->lockForUpdate()->firstOrFail();
            if ($request->responded_at === null) {
                $now = CarbonImmutable::now();
                $request->forceFill(['score' => $score, 'comment' => $comment, 'responded_at' => $now])->save();
                Message::query()->where('id', $request->message_id)->update(['responded_at' => $now]);
            }

            return $this->feedbackView($request);
        }));
    }

    /**
     * Opt this address out, and withdraw the consent the message relied on.
     *
     * @return array<string, mixed>
     */
    public function unsubscribe(string $token, bool $confirm): array
    {
        return $this->tenancy->system('crm public link', fn (): array => DB::transaction(function () use ($token, $confirm): array {
            $message = $this->message($token, null);
            $organization = Organization::query()->findOrFail($message->organization_id);
            $address = $message->channel->normalize((string) $message->to_address);
            $suppressed = fn (): bool => MessageSuppression::query()->where('organization_id', $message->organization_id)
                ->where('channel', $message->channel->value)->where('address', $address)->exists();

            if ($confirm && ! $suppressed()) {
                (new MessageSuppression)->forceFill([
                    'organization_id' => $message->organization_id,
                    'channel' => $message->channel,
                    'address' => $address,
                    'reason' => 'unsubscribed',
                    'customer_account_id' => $message->customer_account_id,
                ])->save();
                $purpose = $message->purpose->consent();
                if ($purpose !== null) {
                    (new Consent)->forceFill([
                        'organization_id' => $message->organization_id,
                        'customer_account_id' => $message->customer_account_id,
                        'contact_id' => $message->contact_id,
                        'purpose' => $purpose,
                        'granted' => false,
                        'channel' => $message->channel === MessageChannel::Email ? ConsentChannel::Email : ConsentChannel::Sms,
                        'captured_at' => CarbonImmutable::now('UTC'),
                        'evidence' => "Unsubscribe link in message {$message->id}",
                    ])->save();
                }
            }

            return [
                'shop' => $organization->name,
                'channel' => $message->channel->value,
                'purpose' => $message->purpose->value,
                'purpose_label' => $message->purpose->label(),
                'unsubscribed' => $suppressed(),
            ];
        }));
    }

    private function message(string $token, ?string $sourceType): Message
    {
        $message = Message::query()->where('token_hash', hash('sha256', $token))
            ->when($sourceType !== null, fn ($query) => $query->where('source_type', $sourceType))->first();

        return $message ?? throw (new ModelNotFoundException)->setModel(Message::class);
    }

    private function feedbackRequest(string $token): FeedbackRequest
    {
        return FeedbackRequest::query()->where('token_hash', hash('sha256', $token))->first() ?? throw (new ModelNotFoundException)->setModel(FeedbackRequest::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function reminderView(Message $message): array
    {
        $organization = Organization::query()->findOrFail($message->organization_id);
        $branch = $message->branch_id === null ? null : Branch::query()->find($message->branch_id);
        $vehicle = $message->subject_type === 'vehicle' && $message->subject_id !== null ? Vehicle::query()->find($message->subject_id) : null;
        $online = $branch !== null && DetailingSetting::query()->where('branch_id', $branch->id)->where('public_booking_enabled', true)->exists();

        return [
            'shop' => $branch === null ? $organization->name : ($branch->brand_name ?? $branch->name),
            'shop_phone' => ($branch === null ? null : $branch->contact_phone) ?? $organization->contact_phone,
            'customer_name' => $message->to_name,
            'headline' => $message->subject ?? 'Service reminder',
            'vehicle' => $vehicle === null ? null : ['plate_number' => $vehicle->plate_number, 'label' => trim("{$vehicle->make} {$vehicle->model}")],
            // Where the customer books: the branch's own online booking page, or a request the staff call back on.
            'booking' => $branch !== null && $online ? ['mode' => 'online', 'branch_slug' => $branch->slug] : ['mode' => 'request', 'branch_slug' => null],
            'requested' => $message->responded_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function feedbackView(FeedbackRequest $request): array
    {
        $branch = Branch::query()->findOrFail($request->branch_id);
        $account = CustomerAccount::query()->findOrFail($request->customer_account_id);

        return [
            'shop' => $branch->brand_name ?? $branch->name,
            'customer_name' => $account->display_name,
            'answered' => $request->responded_at !== null,
            'score' => $request->score,
            'band' => $request->score === null ? null : Nps::band($request->score),
        ];
    }
}
