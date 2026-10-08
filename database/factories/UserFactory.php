<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Access\Role;
use App\Domain\Access\Side;
use App\Models\CustomerAccount;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Staff provider_admin of a fresh organization by default. `->role()` for
 * another staff role, `->portal($account, $role)` for a portal user.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'side' => Side::Staff,
            'role' => Role::ProviderAdmin,
            'customer_account_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'status' => User::ACTIVE,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function inOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes) => ['organization_id' => $organization->id]);
    }

    /** A staff role in the same organization. */
    public function role(Role $role): static
    {
        return $this->state(fn (array $attributes) => ['side' => $role->side(), 'role' => $role]);
    }

    /** A portal user of $account (and so of its organization). */
    public function portal(CustomerAccount $account, Role $role = Role::FleetManager): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $account->organization_id,
            'side' => Side::Portal,
            'role' => $role,
            'customer_account_id' => $account->id,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => User::DISABLED]);
    }
}
