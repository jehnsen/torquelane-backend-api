<?php

declare(strict_types=1);

namespace App\Messaging;

use Illuminate\Support\Facades\Log;

/**
 * The gateway until a provider is chosen: it writes the SMS to the log and
 * sends nothing. Its messages are logged as sent because they were handed to
 * the configured gateway; nobody's phone rings.
 */
final class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $text): void
    {
        Log::info('sms.outbound', ['to' => $to, 'text' => $text]);
    }
}
