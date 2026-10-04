<?php

namespace App\Jobs;

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

    public function __construct(
        public readonly int $ticketId,
        public readonly string $resolvedSince,
    ) {
        $this->onConnection('database')->onQueue('maintenance')->beforeCommit();
    }

    public function handle(TicketLifecycleService $tickets): void
    {
        DB::transaction(function () use ($tickets): void {
            $ticket = Ticket::query()->lockForUpdate()->find($this->ticketId);

            if ($ticket === null
                || ! isset($this->resolvedSince)
                || $ticket->status !== TicketStatus::Resolved
                || $ticket->resolved_since?->toISOString() !== $this->resolvedSince
                || $ticket->resolved_since->copy()->addHours((int) config('support.ticket_auto_close_hours'))->isFuture()) {
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
