<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class PaymentCollection extends ApiResourceCollection
{
    public $collects = PaymentResource::class;
}
