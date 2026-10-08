<?php

declare(strict_types=1);

namespace App\Domain\System;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Down = 'down';
}
