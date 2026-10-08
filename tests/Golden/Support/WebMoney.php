<?php

declare(strict_types=1);

namespace Tests\Golden\Support;

/** A fixture's `{ "$money": …, "cents": … }`, kept whole when a replay asserts in centavos. */
final readonly class WebMoney
{
    public function __construct(
        public float|int $money,
        public int $cents,
        public bool $subCentavo,
    ) {}
}
