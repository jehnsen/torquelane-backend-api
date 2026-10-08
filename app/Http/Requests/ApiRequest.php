<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for write requests. Shape and type only (R3): authorization is the
 * policy's job, and anything that depends on stored state is the Action's.
 */
abstract class ApiRequest extends FormRequest
{
    public const string TIN = '/^\d{3}-\d{3}-\d{3}$/';

    public const string HEX_COLOUR = '/^#[0-9a-fA-F]{6}$/';

    public function authorize(): bool
    {
        return true;
    }

    /** `required` on create (POST), `sometimes` on update. */
    protected function presence(): string
    {
        return $this->isMethod('POST') ? 'required' : 'sometimes';
    }

    protected function tenant(): ?TenantContext
    {
        return app(TenantManager::class)->context();
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected static function brandingRules(): array
    {
        return [
            'logo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'brand_color' => ['sometimes', 'nullable', 'string', 'regex:'.self::HEX_COLOUR],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected static function themeTokenRules(): array
    {
        return [
            'theme_tokens' => ['sometimes', 'nullable', 'array', 'max:64'],
            'theme_tokens.*' => ['string', 'max:128'],
        ];
    }
}
