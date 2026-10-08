<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The fleet analytics' shared filters: `customer_account_id` (one account in
 * the caller's scope; a portal user's own is implied), `months` (3, 6 or 12)
 * and a PMS `status`.
 */
final class AnalyticsRequest extends ApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'customer_account_id' => ['sometimes', 'string', 'ulid'],
            'months' => ['sometimes', 'integer', 'in:3,6,12'],
            'status' => ['sometimes', 'string', 'in:all,ok,due_soon,overdue'],
        ];
    }

    public function accountId(): ?string
    {
        return $this->filled('customer_account_id') ? $this->string('customer_account_id')->lower()->toString() : null;
    }

    public function months(int $default = 12): int
    {
        return $this->integer('months', $default);
    }

    /** ok | due_soon | overdue, or null for every item. */
    public function pmsStatus(): ?string
    {
        $status = $this->string('status', 'all')->toString();

        return $status === 'all' ? null : $status;
    }
}
