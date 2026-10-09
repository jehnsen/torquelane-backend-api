<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class InvoiceCollection extends ApiResourceCollection
{
    public $collects = InvoiceResource::class;
}
