<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerAccount;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A company account. Writes no consent rows: tests that need the opening
 * consent go through the API (CreateCustomerAccount).
 *
 * @extends Factory<CustomerAccount>
 */
class CustomerAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'organization_id' => Organization::factory(),
            'account_type' => CustomerAccount::COMPANY,
            'display_name' => $name,
            'registered_name' => $name.' Inc.',
            'contact_name' => fake()->name(),
            'contact_email' => fake()->safeEmail(),
            'payment_terms_days' => 30,
            'status' => 'active',
        ];
    }

    public function inOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes) => ['organization_id' => $organization->id]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'suspended']);
    }
}
