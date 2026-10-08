<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\CustomerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Always created for a given account: `Contact::factory()->for($account)`
 * does not carry the organization, so use `->forAccount($account)`.
 *
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'role' => fake()->jobTitle(),
            'mobile' => '+639'.fake()->numerify('#########'),
            'email' => fake()->safeEmail(),
            'is_primary' => false,
        ];
    }

    public function forAccount(CustomerAccount $account): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $account->organization_id,
            'customer_account_id' => $account->id,
        ]);
    }
}
