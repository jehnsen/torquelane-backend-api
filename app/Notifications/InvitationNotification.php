<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The only place the plain invitation token ever exists outside the request
 * that created it; the database stores its SHA-256.
 */
final class InvitationNotification extends Notification
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $inviterName,
        public readonly string $token,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config()->string('app.frontend_url'), '/').'/accept-invite?token='.$this->token;

        return (new MailMessage)
            ->subject("You're invited to {$this->organizationName} on TorqueLane")
            ->line("{$this->inviterName} invited you to {$this->organizationName}.")
            ->action('Accept the invitation', $url)
            ->line('The link expires on '.$this->expiresAt->setTimezone('Asia/Manila')->format('j M Y, g:i A').' (Manila time).');
    }
}
