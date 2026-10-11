<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/** How a message travels. */
enum MessageChannel: string
{
    case Email = 'email';
    case Sms = 'sms';

    public function label(): string
    {
        return $this === self::Email ? 'Email' : 'SMS';
    }

    /** An address as the suppression list keeps it: a lower-cased email, a mobile's digits. */
    public function normalize(string $address): string
    {
        $address = trim($address);

        return $this === self::Email ? mb_strtolower($address) : (string) preg_replace('/\D+/', '', $address);
    }

    public function accepts(?string $address): bool
    {
        if ($address === null || trim($address) === '') {
            return false;
        }

        return $this === self::Email
            ? filter_var(trim($address), FILTER_VALIDATE_EMAIL) !== false
            : strlen($this->normalize($address)) >= 10;
    }
}
