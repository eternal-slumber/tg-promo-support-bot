<?php

namespace Database\Factories;

use App\Models\TelegramParticipant;
use App\Models\TelegramUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramUpdate>
 */
class TelegramUpdateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'update_id' => fake()->unique()->numberBetween(1, 2_000_000_000),
            'participant_id' => TelegramParticipant::factory(),
            'kind' => 'message',
            'received_at' => now(),
        ];
    }
}
