<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Crm\MessageChannel;
use App\Mail\CrmMessageMail;
use App\Messaging\SmsGateway;
use App\Models\Message;
use App\Models\MessageSuppression;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Hands one queued message to its channel and records what happened. Safe to
 * run twice: only a `queued` message whose time has come is sent, and the row
 * is locked while it is. An address that opted out since the message was
 * queued gets nothing.
 */
final class SendMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $messageId) {}

    public function handle(TenantManager $tenancy, SmsGateway $sms): void
    {
        $tenancy->system('message delivery', function () use ($sms): void {
            DB::transaction(function () use ($sms): void {
                $message = Message::query()->whereKey($this->messageId)->lockForUpdate()->first();
                $now = CarbonImmutable::now();
                if ($message === null || $message->status !== Message::QUEUED || $message->to_address === null || ($message->send_after !== null && $message->send_after > $now)) {
                    return;
                }

                $optedOut = MessageSuppression::query()->where('organization_id', $message->organization_id)
                    ->where('channel', $message->channel->value)->where('address', $message->channel->normalize($message->to_address))->exists();
                if ($optedOut) {
                    $message->forceFill(['status' => Message::SKIPPED, 'skip_reason' => 'opted_out'])->save();

                    return;
                }

                try {
                    if ($message->channel === MessageChannel::Email) {
                        Mail::to($message->to_address, $message->to_name)->send(new CrmMessageMail($message->id, $message->subject ?? '', $message->body));
                    } else {
                        $sms->send($message->to_address, $message->body);
                    }
                    $message->forceFill(['status' => Message::SENT, 'sent_at' => $now])->save();
                } catch (Throwable $e) {
                    $message->forceFill(['status' => Message::FAILED, 'failed_reason' => mb_substr($e->getMessage(), 0, 500)])->save();
                }
            });
        });
    }
}
