<?php

namespace Database\Factories;

use App\Models\TelegramParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramParticipant>
 */
class TelegramParticipantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_user_id' => fake()->unique()->numberBetween(1, 2_000_000_000),
            'chat_id' => fake()->numberBetween(1, 2_000_000_000),
        ];
    }
}
