<?php

namespace Database\Factories;

use App\Enums\SupportDecisionType;
use App\Models\Message;
use App\Models\SupportDecision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportDecision>
 */
class SupportDecisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'type' => SupportDecisionType::Answer,
            'reason' => null,
            'answer_text' => fake()->sentence(),
            'knowledge_source_hash' => hash('sha256', 'promo-rules'),
            'structured_output' => ['type' => SupportDecisionType::Answer->value],
        ];
    }
}
