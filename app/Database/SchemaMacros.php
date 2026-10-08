<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use LogicException;

/**
 * Column helpers that encode the money and quantity conventions.
 *
 *     $table->cents('subtotal_cents');   // bigint, minor units
 *     $table->quantity('quantity');      // numeric(14,3)
 *     $table->rate('labour_hours');      // numeric(14,3)
 *
 * Migrations that have run call these, so their output must never change:
 * a new convention gets a new macro name (R10).
 */
final class SchemaMacros
{
    public const DECIMAL_PRECISION = 14;

    public const DECIMAL_SCALE = 3;

    public static function register(): void
    {
        Blueprint::macro('cents', function (string $column): ColumnDefinition {
            if (! str_ends_with($column, '_cents')) {
                throw new LogicException("Money column [{$column}] must be named *_cents.");
            }

            /** @var Blueprint $this */
            return $this->bigInteger($column);
        });

        Blueprint::macro('quantity', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->decimal($column, SchemaMacros::DECIMAL_PRECISION, SchemaMacros::DECIMAL_SCALE);
        });

        Blueprint::macro('rate', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->decimal($column, SchemaMacros::DECIMAL_PRECISION, SchemaMacros::DECIMAL_SCALE);
        });
    }
}
