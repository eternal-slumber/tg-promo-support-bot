<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Jobs\DeliverTelegramMessage;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use DomainException;
use Illuminate\Support\Facades\DB;

class TelegramCallbackService
{
    private const string ClarificationRequest = 'Пожалуйста, опишите, что именно не решило вашу проблему.';

    public function __construct(private readonly TicketLifecycleService $tickets) {}

    public function handle(TelegramParticipant $participant, ?string $data): void
    {
        $callback = $this->callback($data);

        if ($callback === null) {
            return;
        }

        DB::transaction(function () use ($callback, $participant): void {
            $ticket = Ticket::query()->lockForUpdate()->find($callback['ticketId']);

            if ($ticket === null || $ticket->participant_id !== $participant->id) {
                return;
            }

            try {
                if ($callback['action'] === 'resolved') {
                    $this->tickets->resolve($ticket);

                    return;
                }

                $ticket = $this->tickets->markUnresolved($ticket);
            } catch (DomainException) {
                return;
            }

            $message = Message::query()->create([
                'participant_id' => $participant->id,
                'ticket_id' => $ticket->id,
                'direction' => MessageDirection::Outbound,
                'author' => MessageAuthor::Bot,
                'body' => self::ClarificationRequest,
                'delivery_status' => DeliveryStatus::Pending,
            ]);

            DeliverTelegramMessage::dispatch($message->id)->afterCommit();
        });
    }

    /**
     * @return array{action: 'resolved'|'unresolved', ticketId: int}|null
     */
    private function callback(?string $data): ?array
    {
        if ($data === null || preg_match('/\A(?<action>resolved|unresolved):(?<ticketId>[1-9]\d*)\z/', $data, $matches) !== 1) {
            return null;
        }

        return [
            'action' => $matches['action'],
            'ticketId' => (int) $matches['ticketId'],
        ];
    }
}
