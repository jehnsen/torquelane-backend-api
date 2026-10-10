<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Actions\Detailing\RecordInspection;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;
use App\Models\DetailingDamagePoint;
use App\Models\DetailingInspection;
use Illuminate\Validation\Rule;

/**
 * A vehicle's inspection at a stage of the booking. A damage point is a place
 * on the top-down outline, in thousandths of its width (x) and length (y).
 */
final class RecordInspectionRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'stage' => ['required', 'string', Rule::in(DetailingInspection::STAGES)],
            'overall_rating' => ['required', 'integer', 'between:1,10'],
            'exterior_rating' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'interior_rating' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'exterior_condition' => ['sometimes', 'nullable', 'string', Rule::in(DetailingInspection::CONDITIONS)],
            'interior_condition' => ['sometimes', 'nullable', 'string', Rule::in(DetailingInspection::CONDITIONS)],
            'mileage' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999999'],
            'confirm_mileage' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'damage_points' => ['sometimes', 'array', 'max:'.RecordInspection::MAX_DAMAGE_POINTS],
            'damage_points.*.x_permille' => ['required', 'integer', 'between:0,1000'],
            'damage_points.*.y_permille' => ['required', 'integer', 'between:0,1000'],
            'damage_points.*.kind' => ['sometimes', 'nullable', 'string', Rule::in(DetailingDamagePoint::KINDS)],
            'damage_points.*.note' => ['required', 'string', 'max:500'],
        ];
    }

    public function stage(): string
    {
        return Input::string($this->validated('stage'));
    }

    /**
     * @return array{overall_rating: int, exterior_rating: int|null, interior_rating: int|null, exterior_condition: string|null, interior_condition: string|null, mileage: int|null, confirm_mileage: bool, notes: string|null, damage_points?: list<array{x_permille: int, y_permille: int, kind?: string|null, note: string}>}
     */
    public function inspection(): array
    {
        $data = [
            'overall_rating' => Input::int($this->validated('overall_rating')),
            'exterior_rating' => $this->filled('exterior_rating') ? Input::int($this->validated('exterior_rating')) : null,
            'interior_rating' => $this->filled('interior_rating') ? Input::int($this->validated('interior_rating')) : null,
            'exterior_condition' => Input::text($this->validated('exterior_condition')),
            'interior_condition' => Input::text($this->validated('interior_condition')),
            'mileage' => $this->filled('mileage') ? Input::int($this->validated('mileage')) : null,
            'confirm_mileage' => $this->boolean('confirm_mileage'),
            'notes' => Input::text($this->validated('notes')),
        ];
        if ($this->has('damage_points')) {
            $data['damage_points'] = [];
            foreach (Input::rows($this->validated('damage_points')) as $point) {
                $data['damage_points'][] = [
                    'x_permille' => Input::int($point['x_permille'] ?? 0),
                    'y_permille' => Input::int($point['y_permille'] ?? 0),
                    'kind' => Input::text($point['kind'] ?? null),
                    'note' => Input::string($point['note'] ?? ''),
                ];
            }
        }

        return $data;
    }
}
