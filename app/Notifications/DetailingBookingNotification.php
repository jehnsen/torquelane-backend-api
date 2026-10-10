<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The two emails a detailing customer gets, as the source sent them: "we
 * received your request" when a booking is made from the public page, and
 * "your booking is confirmed" when staff confirm it. Sent in the branch's own
 * name. Queued, so it leaves after the booking's transaction has committed.
 */
final class DetailingBookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const string RECEIVED = 'received';

    public const string CONFIRMED = 'confirmed';

    /**
     * @param  array{reference: string, customer_name: string, package: string, size: string, date: string, time: string, vehicle: string|null, total: string, shop: string, shop_phone: string|null, url: string|null}  $booking  already worded for print
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $booking,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $b = $this->booking;
        $first = trim(explode(' ', trim($b['customer_name']))[0]);
        $confirmed = $this->kind === self::CONFIRMED;

        $mail = (new MailMessage)
            ->subject($confirmed ? "Your booking at {$b['shop']} is confirmed ({$b['reference']})" : "We received your booking request ({$b['reference']})")
            ->greeting($first === '' ? 'Hello,' : "Hello {$first},")
            ->line($confirmed
                ? "Your booking at {$b['shop']} is confirmed. We look forward to seeing you."
                : "We received your request at {$b['shop']}. It is not confirmed yet: we will email you again once our team has confirmed your slot.")
            ->line("**Reference:** {$b['reference']}")
            ->line("**Package:** {$b['package']} ({$b['size']})")
            ->line("**Date:** {$b['date']}")
            ->line("**Time:** {$b['time']}");
        if ($b['vehicle'] !== null) {
            $mail->line("**Vehicle:** {$b['vehicle']}");
        }
        $mail->line("**Package price:** {$b['total']}");
        if ($b['url'] !== null) {
            $mail->action('View your booking', $b['url']);
        }
        if ($b['shop_phone'] !== null) {
            $mail->line("Need to change something? Call us on {$b['shop_phone']}.");
        }

        return $mail->salutation($b['shop']);
    }
}
