<?php

namespace Database\Factories;

use App\Models\LegalAcceptance;
use App\Models\User;
use App\Services\Account\LegalDocuments;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
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
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Factory users have accepted the current legal documents, like everyone who has used the product.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (User $user) => app(LegalDocuments::class)->accept($user));
    }

    /** A person who hasn't accepted the current Terms / Privacy Policy yet. */
    public function withoutLegalAcceptance(): static
    {
        return $this->afterCreating(fn (User $user) => LegalAcceptance::where('user_id', $user->id)->delete());
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
}
