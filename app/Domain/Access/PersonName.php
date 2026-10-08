<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * A person's display name and its parts (../web 0007_pms_profile_fields):
 * `name` stays the derived "first last" every list shows; the profile edits
 * the parts. A username is a handle, never a credential (sign-in is by
 * email): unique per organization, case-insensitively.
 */
final class PersonName
{
    public const string USERNAME_PATTERN = '/^[A-Za-z0-9._-]{3,32}$/';

    /**
     * Everything before the first space is the first name, the rest the last
     * (empty for a one-word name).
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $name): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $space = mb_strpos($name, ' ');

        return $space === false ? [$name, ''] : [mb_substr($name, 0, $space), trim(mb_substr($name, $space + 1))];
    }

    public static function join(string $first, string $last): string
    {
        return trim(trim($first).' '.trim($last));
    }

    /** An email's local part as a username base: `fleet@actimed.ph` → `fleet`. */
    public static function usernameBase(string $email): string
    {
        $local = mb_strtolower(explode('@', $email, 2)[0]);
        $clean = (string) preg_replace('/[^a-z0-9._-]/', '', $local);

        return str_pad(mb_substr($clean, 0, 28), 3, '0');
    }

    /**
     * The first of base, base2, base3… not in `$taken` (lower-cased), the
     * tie-break ../web's backfill used.
     *
     * @param  array<string, true>  $taken
     */
    public static function freeUsername(string $base, array $taken): string
    {
        $candidate = $base;
        for ($n = 2; isset($taken[mb_strtolower($candidate)]); $n++) {
            $candidate = $base.$n;
        }

        return $candidate;
    }
}
