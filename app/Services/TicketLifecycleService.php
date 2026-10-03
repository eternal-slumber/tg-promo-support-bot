<?php

namespace App\Services;

use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use DomainException;
use Illuminate\Support\Facades\DB;

class TicketLifecycleService
{
    public const string ResolvedResponse = 'Проблема решена';

    public const string UnresolvedResponse = 'Не решило';

    public function activeFor(TelegramParticipant $participant): ?Ticket
    {
        return $participant->tickets()
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::WaitingForUser->value])
            ->oldest('id')
            ->lockForUpdate()
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

    /** The caller holds ticket/message locks in its transaction; repeated attachment is a no-op. */
    public function attachParticipantMessage(Ticket $ticket, Message $message): void
    {
        if ($message->ticket_id === $ticket->id) {
            return;
        }

        if (! $message->isInboundParticipantMessage() || $message->participant_id !== $ticket->participant_id || $message->ticket_id !== null) {
            throw new DomainException('Only unattached messages from the ticket participant can be attached.');
        }

        $message->update(['ticket_id' => $ticket->id]);
        $ticket->increment('input_revision');
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

    public function closeAutomatically(Ticket $ticket): Ticket
    {
        return $this->transition(
            $ticket,
            [TicketStatus::WaitingForUser],
            TicketStatus::Closed,
            TicketCloseReason::AutoClosed,
        );
    }

    public function markUnresolved(Ticket $ticket): Ticket
    {
        return $this->transition($ticket, [TicketStatus::WaitingForUser], TicketStatus::Open);
    }

    public function applyUserResponse(Ticket $ticket, string $text): Ticket
    {
        return trim($text) === self::ResolvedResponse
            ? $this->resolve($ticket)
            : $this->markUnresolved($ticket);
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

            if ($closeReason === TicketCloseReason::OperatorClosed && $lockedTicket->hasUnfinishedOperatorReply()) {
                throw new DomainException('Ticket cannot be closed while an operator reply is unfinished.');
            }

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
