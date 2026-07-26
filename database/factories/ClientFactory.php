<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Client\Models\Client;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Modules\Client\Models\Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fullName = fake()->name();

        return [
            'phone' => '966'.fake()->numerify('##########'), // Saudi phone format
            'full_name' => $fullName,
            'email' => fake()->unique()->safeEmail(),
            'image' => null,
            'otp_code' => null,
            'otp_expires_at' => null,
            'is_verified' => fake()->boolean(80), // 80% verified
        ];
    }

    /**
     * Indicate that the client is verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
        ]);
    }

    /**
     * Indicate that the client has completed their profile.
     */
    public function profileCompleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
        ]);
    }

    /**
     * Indicate that the client is not verified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => false,
        ]);
    }
}
