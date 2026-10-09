<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Stock on hand, with `summary` over the whole filtered set (not just this page).
 */
final class StockBalanceCollection extends ApiResourceCollection
{
    public $collects = StockBalanceResource::class;

    /**
     * @param  array{lines: int, value_cents: int, low: int, negative: int}  $summary
     */
    public function __construct($resource, private readonly array $summary)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['summary' => $this->summary];
    }
}
