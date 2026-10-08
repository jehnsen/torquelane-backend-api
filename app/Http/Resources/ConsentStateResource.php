<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Directory\ConsentState;
use App\Models\Consent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Current consent: the latest decision per purpose (null = never recorded),
 * for the account itself and for each contact with decisions of their own.
 *
 * @property ConsentState $resource
 */
final class ConsentStateResource extends JsonResource
{
    public function __construct(ConsentState $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $render = function (array $byPurpose) use ($request): array {
            $rendered = [];
            foreach ($byPurpose as $purpose => $consent) {
                $rendered[$purpose] = $consent instanceof Consent ? (new ConsentResource($consent))->resolve($request) : null;
            }

            return $rendered;
        };

        return [
            'account' => $render($this->resource->account),
            'contacts' => (object) array_map($render, $this->resource->contacts),
        ];
    }
}
