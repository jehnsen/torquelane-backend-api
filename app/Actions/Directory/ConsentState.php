<?php

declare(strict_types=1);

namespace App\Actions\Directory;

use App\Models\Consent;

/**
 * Current consent for one account: the latest decision per purpose (null =
 * never recorded), for the account itself and for each contact with
 * decisions of their own.
 */
final readonly class ConsentState
{
    /**
     * @param  array<string, Consent|null>  $account  by purpose
     * @param  array<string, array<string, Consent|null>>  $contacts  by contact id, then purpose
     */
    public function __construct(
        public array $account,
        public array $contacts,
    ) {}
}
