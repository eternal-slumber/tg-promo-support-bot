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
        $answer = fake()->sentence();

        return [
            'message_id' => Message::factory(),
            'type' => SupportDecisionType::Answer,
            'reason' => 'rule_answer',
            'answer_text' => $answer,
            'knowledge_source_hash' => hash('sha256', 'promo-rules'),
            'structured_output' => [
                'decision' => SupportDecisionType::Answer->value,
                'reason' => 'rule_answer',
                'answer' => $answer,
                'evidence' => [['rule_id' => '7.4', 'quote' => 'Выплата денежного эквивалента призов и замена призов другими не производятся.']],
            ],
        ];
    }
}
