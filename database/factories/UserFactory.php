<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
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
            'role' => UserRole::Client,
            'phone' => fake()->numerify('(##) 9####-####'),
            'is_active' => true,
            'email_verified_at' => now(),
            // Precisam estar aqui, não só no default da coluna: create()
            // não busca de volta o valor que o MySQL aplicou — sem isto, o
            // objeto em memória logo após o factory fica com o atributo
            // simplesmente ausente (null), mesmo a linha no banco estando 1/0.
            'notify_email_enabled' => true,
            'notify_push_enabled' => false,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
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

    public function consultant(): static
    {
        return $this->state(fn () => ['role' => UserRole::Consultant]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
