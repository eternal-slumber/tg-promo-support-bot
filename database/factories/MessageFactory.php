<?php

namespace Database\Factories;

use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Models\Message;
use App\Models\TelegramParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'participant_id' => TelegramParticipant::factory(),
            'ticket_id' => null,
            'telegram_update_id' => null,
            'operator_id' => null,
            'direction' => MessageDirection::Inbound,
            'author' => MessageAuthor::Participant,
            'body' => fake()->sentence(),
            'delivery_status' => null,
            'telegram_message_id' => null,
            'delivered_at' => null,
            'delivery_attempts' => 0,
            'resolves_ticket' => false,
            'last_delivery_error' => null,
            'sensitive_data_redacted' => false,
            'redaction_types' => null,
        ];
    }
}
