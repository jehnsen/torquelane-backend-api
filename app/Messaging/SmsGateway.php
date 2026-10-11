<?php

declare(strict_types=1);

namespace App\Messaging;

/** Where an SMS is handed off. Bind a provider's implementation in the container; the default only logs. */
interface SmsGateway
{
    public function send(string $to, string $text): void;
}
