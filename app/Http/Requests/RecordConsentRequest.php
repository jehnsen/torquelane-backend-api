<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Crm\ConsentChannel;
use App\Domain\Crm\ConsentPurpose;
use Illuminate\Validation\Rule;

final class RecordConsentRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'purpose' => ['required', 'string', Rule::enum(ConsentPurpose::class)],
            'granted' => ['required', 'boolean'],
            'channel' => ['required', 'string', Rule::enum(ConsentChannel::class)],
            'contact_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'evidence' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'captured_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * @return array{purpose: string, granted: bool, channel: string, contact_id: string|null, evidence: string|null, captured_at: string|null}
     */
    public function decision(): array
    {
        return [
            'purpose' => $this->string('purpose')->toString(),
            'granted' => $this->boolean('granted'),
            'channel' => $this->string('channel')->toString(),
            'contact_id' => $this->filled('contact_id') ? $this->string('contact_id')->lower()->toString() : null,
            'evidence' => $this->filled('evidence') ? $this->string('evidence')->toString() : null,
            'captured_at' => $this->filled('captured_at') ? $this->string('captured_at')->toString() : null,
        ];
    }
}
