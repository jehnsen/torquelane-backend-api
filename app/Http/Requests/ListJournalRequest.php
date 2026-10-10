<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Ledger\LedgerEvent;
use Illuminate\Validation\Rule;

/** The journal browser's filters: a date range, an account, an event, a branch, a source document, a search. */
final class ListJournalRequest extends LedgerRangeRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'account_id' => ['sometimes', 'string', 'ulid'],
            'event' => ['sometimes', 'string', Rule::in(array_map(fn (LedgerEvent $e): string => $e->value, LedgerEvent::cases()))],
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'source_id' => ['sometimes', 'string', 'ulid'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);
    }

    /**
     * @return array{from?: string, to?: string, account_id?: string, event?: string, branch_id?: string, q?: string, source_id?: string}
     */
    public function filters(): array
    {
        $filters = [];
        // A journal browsed with no dates shows everything; the range applies only when asked for.
        foreach (['from', 'to'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = Input::string($this->input($key));
            }
        }
        foreach (['account_id', 'branch_id', 'source_id'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = $this->string($key)->lower()->toString();
            }
        }
        foreach (['event', 'q'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = trim($this->string($key)->toString());
            }
        }

        return $filters;
    }
}
