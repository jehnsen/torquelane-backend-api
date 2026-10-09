<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * A branch setting: what happens when a move would take a balance below zero.
 * `allow_and_flag` (the default) lets it through and marks the move;
 * `block` refuses it.
 */
enum NegativeStockPolicy: string
{
    case AllowAndFlag = 'allow_and_flag';
    case Block = 'block';
}
