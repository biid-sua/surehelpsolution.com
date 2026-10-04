<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            // Valid US numbers (555-01xx is reserved for fiction but not "valid"; use a real area code range).
            'phone' => '(512) 555-'.str_pad((string) fake()->unique()->numberBetween(1000, 9999), 4, '0', STR_PAD_LEFT),
            'email' => fake()->unique()->safeEmail(),
            'status' => CustomerStatus::Lead,
            'source' => 'manual',
        ];
    }
}
