<?php

namespace Database\Factories;

use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
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
            'status' => TicketStatus::Open,
            'escalation_reason' => 'unknown',
            'first_operator_replied_at' => null,
            'resolved_since' => null,
            'closed_at' => null,
            'close_reason' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Resolved,
            'resolved_since' => now(),
        ]);
    }

    public function closed(TicketCloseReason $reason = TicketCloseReason::OperatorClosed): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Closed,
            'resolved_since' => null,
            'closed_at' => now(),
            'close_reason' => $reason,
        ]);
    }
}
