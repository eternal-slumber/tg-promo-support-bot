<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\TicketLifecycleService;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class AutoCloseTicket implements ShouldQueue
{
    use Queueable;

    public ?int $replyMessageId = null;

    public function __construct(
        public readonly int $ticketId,
        public readonly string $resolvedSince,
        ?int $replyMessageId = null,
    ) {
        $this->replyMessageId = $replyMessageId;
        $this->onConnection('database')->onQueue('maintenance')->beforeCommit();
    }

    public function handle(TicketLifecycleService $tickets): void
    {
        DB::transaction(function () use ($tickets): void {
            $ticket = Ticket::query()->lockForUpdate()->find($this->ticketId);

            if ($ticket === null
                || ! isset($this->resolvedSince)
                || $ticket->status !== TicketStatus::Resolved
                || $ticket->resolved_since?->toISOString() !== $this->resolvedSince) {
                return;
            }

            if ($this->replyMessageId !== null && $ticket->messages()
                ->where('direction', MessageDirection::Outbound)
                ->where('author', MessageAuthor::Operator)
                ->where('delivery_status', DeliveryStatus::Sent)
                ->latest('id')
                ->value('id') !== $this->replyMessageId) {
                return;
            }

            try {
                $tickets->closeAutomatically($ticket);
            } catch (DomainException) {
                return;
            }
        });
    }
}
