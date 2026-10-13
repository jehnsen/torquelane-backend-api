<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class WithholdingCertificateCollection extends ApiResourceCollection
{
    public $collects = WithholdingCertificateResource::class;
}
