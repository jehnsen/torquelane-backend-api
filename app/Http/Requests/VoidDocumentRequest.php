<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** Voiding an invoice or a payment: the reason is required and kept on the document. */
final class VoidDocumentRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:500']];
    }

    public function reason(): string
    {
        return trim($this->string('reason')->toString());
    }
}
