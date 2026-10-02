<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Jobs\DeliverTelegramMessage;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class OperatorReplyService
{
    public function __construct(private readonly SensitiveDataSanitizer $sanitizer) {}

    public function create(User $operator, Ticket $ticket, string $body): Message
    {
        $sanitized = $this->sanitizer->sanitize($body);

        return DB::transaction(function () use ($operator, $ticket, $sanitized): Message {
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            if ($lockedTicket->status !== TicketStatus::Open) {
                throw new DomainException('Only open tickets can receive an operator reply.');
            }

            $message = Message::query()->create([
                'participant_id' => $lockedTicket->participant_id,
                'ticket_id' => $lockedTicket->id,
                'operator_id' => $operator->id,
                'direction' => MessageDirection::Outbound,
                'author' => MessageAuthor::Operator,
                'body' => $sanitized->text,
                'delivery_status' => DeliveryStatus::Pending,
                'sensitive_data_redacted' => $sanitized->wasRedacted,
                'redaction_types' => $sanitized->redactionTypes ?: null,
            ]);

            if ($lockedTicket->first_operator_replied_at === null) {
                $lockedTicket->update(['first_operator_replied_at' => now()]);
            }

            DeliverTelegramMessage::dispatch($message->id)->afterCommit();

            return $message;
        });
    }
}
