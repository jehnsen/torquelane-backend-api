<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class AlertInteractionRequest extends ApiRequest
{
    /** The deterministic alert id formats (App\Domain\Alerts\Alerts). */
    public const string ALERT_ID = '/^(pms|wo|approval-sla|doc|licence):[0-9a-z]{26}(:[0-9a-z]{26})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'alert_ids' => ['required', 'array', 'min:1', 'max:500'],
            'alert_ids.*' => ['required', 'string', 'max:200', 'regex:'.self::ALERT_ID],
        ];
    }

    /**
     * @return list<string>
     */
    public function alertIds(): array
    {
        return array_values(array_map(fn (mixed $id): string => is_string($id) ? $id : '', $this->array('alert_ids')));
    }
}
