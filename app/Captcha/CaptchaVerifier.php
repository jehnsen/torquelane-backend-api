<?php

declare(strict_types=1);

namespace App\Captcha;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The public booking form's captcha. Off until configured
 * (`services.captcha.driver`): with no driver every request passes, and the
 * public profile tells the page there is nothing to render. With `turnstile`
 * the token the page sends is checked with Cloudflare before a booking is taken.
 */
final class CaptchaVerifier
{
    public const string TURNSTILE = 'turnstile';

    private const string TURNSTILE_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function driver(): ?string
    {
        $driver = config('services.captcha.driver');

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    /**
     * What the page needs to draw the challenge; `provider` null means none.
     *
     * @return array{provider: string|null, site_key: string|null}
     */
    public function client(): array
    {
        $siteKey = config('services.captcha.site_key');

        return ['provider' => $this->driver(), 'site_key' => $this->driver() !== null && is_string($siteKey) ? $siteKey : null];
    }

    public function passes(?string $token, ?string $ip): bool
    {
        if ($this->driver() === null) {
            return true;
        }
        $secret = config('services.captcha.secret');
        if ($token === null || $token === '' || ! is_string($secret) || $secret === '') {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::TURNSTILE_URL, ['secret' => $secret, 'response' => $token, 'remoteip' => $ip]);

            return $response->successful() && $response->json('success') === true;
        } catch (Throwable) {
            // The check could not be made: refuse rather than wave a bot through.
            return false;
        }
    }
}
