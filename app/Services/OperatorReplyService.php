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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperatorReplyService
{
    public function __construct(
        private readonly SensitiveDataSanitizer $sanitizer,
        private readonly TelegramMessagePresentation $presentation,
    ) {}

    public function cancel(Ticket $ticket, int $messageId): void
    {
        $cancelled = Cache::store('database')->lock('telegram-delivery:'.$messageId, 50)
            ->get(function () use ($ticket, $messageId): bool {
                return DB::transaction(function () use ($ticket, $messageId): bool {
                    $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());
                    $message = $lockedTicket->messages()
                        ->where('direction', MessageDirection::Outbound)
                        ->where('author', MessageAuthor::Operator)
                        ->lockForUpdate()
                        ->findOrFail($messageId);

                    if ($lockedTicket->status !== TicketStatus::Open
                        || ! in_array($message->delivery_status, [DeliveryStatus::Pending, DeliveryStatus::Failed], true)) {
                        throw new DomainException('Only unfinished operator replies on open tickets can be cancelled.');
                    }

                    $message->update(['delivery_status' => DeliveryStatus::Cancelled]);

                    return true;
                });
            });

        if (! $cancelled) {
            throw new DomainException('Delivery is already in progress.');
        }
    }

    public function create(User $operator, Ticket $ticket, string $body): Message
    {
        $sanitized = $this->sanitizer->sanitize($body);

        return DB::transaction(function () use ($operator, $ticket, $sanitized): Message {
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            if ($lockedTicket->status !== TicketStatus::Open) {
                throw new DomainException('Only open tickets can receive an operator reply.');
            }

            if ($lockedTicket->hasUnfinishedOperatorReply()) {
                throw new DomainException('Ticket already has an unfinished operator reply.');
            }

            $limit = $this->presentation->operatorReplyLimit($lockedTicket);

            if (mb_strlen($sanitized->text, 'UTF-8') > $limit) {
                throw ValidationException::withMessages([
                    'replyBody' => "Ответ слишком длинный: максимум {$limit} символов с учётом номера обращения и цитаты. Сократите ответ.",
                ]);
            }

            $message = Message::query()->create([
                'participant_id' => $lockedTicket->participant_id,
                'ticket_id' => $lockedTicket->id,
                'operator_id' => $operator->id,
                'direction' => MessageDirection::Outbound,
                'author' => MessageAuthor::Operator,
                'body' => $sanitized->text,
                'delivery_status' => DeliveryStatus::Pending,
                'operator_input_revision' => $lockedTicket->input_revision,
                'sensitive_data_redacted' => $sanitized->wasRedacted,
                'redaction_types' => $sanitized->redactionTypes ?: null,
            ]);

            DeliverTelegramMessage::dispatch($message->id);

            return $message;
        });
    }
}
