<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

/** A vehicle's size bucket (the source's `cars.size`); packages and add-ons are priced per bucket. */
enum SizeClass: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
    case Xl = 'xl';

    public function label(): string
    {
        return match ($this) {
            self::Small => 'Small',
            self::Medium => 'Medium',
            self::Large => 'Large',
            self::Xl => 'XL',
        };
    }

    /**
     * The size a source package name ends in: "The Essential (M)" → medium.
     * Null for a name with no size suffix (a size-agnostic package).
     */
    public static function fromNameSuffix(string $name): ?self
    {
        if (preg_match('/\((S|M|L|XL)\)\s*$/i', $name, $m) !== 1) {
            return null;
        }

        return match (strtoupper($m[1])) {
            'S' => self::Small,
            'M' => self::Medium,
            'L' => self::Large,
            default => self::Xl,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $size): string => $size->value, self::cases());
    }
}
