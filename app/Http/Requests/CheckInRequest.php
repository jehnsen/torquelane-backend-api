<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Crm\ConsentChannel;
use App\Domain\Crm\ConsentPurpose;
use App\Models\CustomerAccount;
use Illuminate\Validation\Rule;

/**
 * GET /check-in/lookup?q=… and POST /check-in (a new customer and vehicle at
 * the counter).
 */
final class CheckInRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return ['q' => ['sometimes', 'nullable', 'string', 'max:64']];
        }

        return [
            'customer' => ['required', 'array'],
            'customer.account_type' => ['required', 'string', Rule::in([CustomerAccount::COMPANY, CustomerAccount::INDIVIDUAL])],
            'customer.display_name' => ['required_if:customer.account_type,company', 'string', 'max:255'],
            'customer.first_name' => ['required_if:customer.account_type,individual', 'nullable', 'string', 'max:255'],
            'customer.last_name' => ['required_if:customer.account_type,individual', 'nullable', 'string', 'max:255'],
            'customer.mobile' => ['sometimes', 'nullable', 'string', 'max:32'],
            'customer.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'customer.contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'consents' => ['required', 'array', 'min:1', 'max:'.count(ConsentPurpose::cases())],
            'consents.*.purpose' => ['required', 'string', 'distinct', Rule::enum(ConsentPurpose::class)],
            'consents.*.granted' => ['required', 'boolean'],
            'consents.*.channel' => ['required', 'string', Rule::enum(ConsentChannel::class)],
            'consents.*.evidence' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'vehicle' => ['required', 'array'],
            'vehicle.plate_number' => ['required', 'string', 'max:32'],
            'vehicle.vin' => ['sometimes', 'nullable', 'string', 'max:32'],
            'vehicle.make' => ['sometimes', 'nullable', 'string', 'max:64'],
            'vehicle.model' => ['sometimes', 'nullable', 'string', 'max:128'],
            'vehicle.year' => ['sometimes', 'nullable', 'integer', 'min:1950', 'max:'.((int) date('Y') + 1)],
            'vehicle.vehicle_class' => ['sometimes', 'nullable', 'string', Rule::in(['sedan', 'suv', 'pickup', 'van', 'truck'])],
            'vehicle.fuel_type' => ['sometimes', 'nullable', 'string', Rule::in(['gasoline', 'diesel', 'hybrid', 'electric'])],
            'vehicle.color' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Read at the counter, today.
            'odometer' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:99999999'],
        ];
    }

    public function lookupText(): string
    {
        return $this->string('q')->toString();
    }

    /**
     * @return array<string, mixed>
     */
    public function customer(): array
    {
        return self::stringKeyed($this->validated('customer'));
    }

    /**
     * @return list<array{purpose: string, granted: bool, channel: string, evidence: string|null}>
     */
    public function consents(): array
    {
        $out = [];
        foreach ($this->array('consents') as $decision) {
            if (is_array($decision)) {
                $out[] = [
                    'purpose' => is_string($decision['purpose'] ?? null) ? $decision['purpose'] : '',
                    'granted' => filter_var($decision['granted'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'channel' => is_string($decision['channel'] ?? null) ? $decision['channel'] : '',
                    'evidence' => is_string($decision['evidence'] ?? null) ? $decision['evidence'] : null,
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function vehicle(): array
    {
        return self::stringKeyed($this->validated('vehicle'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyed(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * @return array{value: string, read_on: null}
     */
    public function odometer(): array
    {
        return ['value' => $this->string('odometer')->toString(), 'read_on' => null];
    }
}
