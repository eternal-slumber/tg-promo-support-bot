<?php

namespace App\Services;

use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use DomainException;
use Illuminate\Support\Facades\DB;

class TicketLifecycleService
{
    public function activeFor(TelegramParticipant $participant): ?Ticket
    {
        return $participant->tickets()
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::WaitingForUser->value])
            ->oldest('id')
            ->first();
    }

    public function create(TelegramParticipant $participant, ?string $escalationReason = null): Ticket
    {
        return DB::transaction(function () use ($participant, $escalationReason): Ticket {
            $lockedParticipant = TelegramParticipant::query()
                ->lockForUpdate()
                ->findOrFail($participant->getKey());

            if ($this->activeFor($lockedParticipant) !== null) {
                throw new DomainException('Participant already has an active ticket.');
            }

            return $lockedParticipant->tickets()->create([
                'status' => TicketStatus::Open,
                'escalation_reason' => $escalationReason,
            ]);
        });
    }

    public function waitForUser(Ticket $ticket): Ticket
    {
        return $this->transition($ticket, [TicketStatus::Open], TicketStatus::WaitingForUser);
    }

    public function closeManually(Ticket $ticket): Ticket
    {
        return $this->transition(
            $ticket,
            [TicketStatus::Open, TicketStatus::WaitingForUser],
            TicketStatus::Closed,
            TicketCloseReason::OperatorClosed,
        );
    }

    public function resolve(Ticket $ticket): Ticket
    {
        return $this->transition(
            $ticket,
            [TicketStatus::WaitingForUser],
            TicketStatus::Closed,
            TicketCloseReason::UserConfirmed,
        );
    }

    public function markUnresolved(Ticket $ticket): Ticket
    {
        return $this->transition($ticket, [TicketStatus::WaitingForUser], TicketStatus::Open);
    }

    /**
     * @param  list<TicketStatus>  $allowedFrom
     */
    private function transition(
        Ticket $ticket,
        array $allowedFrom,
        TicketStatus $to,
        ?TicketCloseReason $closeReason = null,
    ): Ticket {
        return DB::transaction(function () use ($ticket, $allowedFrom, $to, $closeReason): Ticket {
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            if (! in_array($lockedTicket->status, $allowedFrom, true)
                || ! $lockedTicket->status->canTransitionTo($to)) {
                throw new DomainException("Ticket cannot transition from {$lockedTicket->status->value} to {$to->value}.");
            }

            $lockedTicket->update(match ($to) {
                TicketStatus::Open => [
                    'status' => $to,
                    'waiting_since' => null,
                    'closed_at' => null,
                    'close_reason' => null,
                ],
                TicketStatus::WaitingForUser => [
                    'status' => $to,
                    'waiting_since' => now(),
                    'closed_at' => null,
                    'close_reason' => null,
                ],
                TicketStatus::Closed => [
                    'status' => $to,
                    'waiting_since' => null,
                    'closed_at' => now(),
                    'close_reason' => $closeReason,
                ],
            });

            return $lockedTicket;
        });
    }
}
