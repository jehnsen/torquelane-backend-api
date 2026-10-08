<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base FormRequest for list endpoints: validates `page`, `per_page` and
 * `cursor`. Subclasses add their filters with `array_merge(parent::rules(), …)`.
 */
class PaginatedRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:1024'],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', self::DEFAULT_PER_PAGE);
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }
}
