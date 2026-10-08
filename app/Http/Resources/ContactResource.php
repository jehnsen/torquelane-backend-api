<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Contact $resource
 */
final class ContactResource extends JsonResource
{
    public function __construct(Contact $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $contact = $this->resource;

        return [
            'id' => $contact->id,
            'customer_account_id' => $contact->customer_account_id,
            'name' => $contact->name,
            'role' => $contact->role,
            'mobile' => $contact->mobile,
            'email' => $contact->email,
            'is_primary' => $contact->is_primary,
            'receives_invoices' => $contact->receives_invoices,
            'receives_reminders' => $contact->receives_reminders,
            'created_at' => $contact->created_at->toIso8601ZuluString(),
            'updated_at' => $contact->updated_at->toIso8601ZuluString(),
        ];
    }
}
