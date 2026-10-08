<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class InvitationCollection extends ApiResourceCollection
{
    public $collects = InvitationResource::class;
}
