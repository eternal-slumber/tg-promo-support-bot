<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
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
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::Resolved->value])
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

    public function resolve(Ticket $ticket): Ticket
    {
        return $this->transition($ticket, [TicketStatus::Open], TicketStatus::Resolved);
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

        $this->reopen($ticket);
        $message->update(['ticket_id' => $ticket->id]);
    }

    public function closeManually(Ticket $ticket): Ticket
    {
        return $this->transition(
            $ticket,
            [TicketStatus::Open, TicketStatus::Resolved],
            TicketStatus::Closed,
            TicketCloseReason::OperatorClosed,
        );
    }

    public function closeAutomatically(Ticket $ticket): Ticket
    {
        return $this->transition(
            $ticket,
            [TicketStatus::Resolved],
            TicketStatus::Closed,
            TicketCloseReason::AutoClosed,
        );
    }

    public function reopen(Ticket $ticket): Ticket
    {
        return DB::transaction(function () use ($ticket): Ticket {
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            if ($lockedTicket->status === TicketStatus::Closed) {
                throw new DomainException('Closed tickets cannot be reopened.');
            }

            $lockedTicket->messages()
                ->where('direction', MessageDirection::Outbound)
                ->where('author', MessageAuthor::Operator)
                ->whereIn('delivery_status', [DeliveryStatus::Pending, DeliveryStatus::Failed])
                ->where('resolves_ticket', true)
                ->update(['resolves_ticket' => false]);

            if ($lockedTicket->status === TicketStatus::Resolved) {
                $lockedTicket->update(['status' => TicketStatus::Open, 'resolved_since' => null]);
            }

            return $lockedTicket;
        });
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
                TicketStatus::Resolved => [
                    'status' => $to,
                    'resolved_since' => now(),
                    'closed_at' => null,
                    'close_reason' => null,
                ],
                TicketStatus::Closed => [
                    'status' => $to,
                    'resolved_since' => null,
                    'closed_at' => now(),
                    'close_reason' => $closeReason,
                ],
            });

            if ($to === TicketStatus::Closed) {
                $lockedTicket->messages()
                    ->where('direction', MessageDirection::Outbound)
                    ->whereIn('delivery_status', [DeliveryStatus::Pending, DeliveryStatus::Failed])
                    ->update(['delivery_status' => DeliveryStatus::Cancelled, 'resolves_ticket' => false]);
            }

            return $lockedTicket;
        });
    }
}
