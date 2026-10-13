<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class VendorPaymentCollection extends ApiResourceCollection
{
    public $collects = VendorPaymentResource::class;
}
