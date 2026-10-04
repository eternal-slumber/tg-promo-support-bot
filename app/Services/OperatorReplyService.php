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
        private readonly TicketLifecycleService $tickets,
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

                    if (! in_array($message->delivery_status, [DeliveryStatus::Pending, DeliveryStatus::Failed], true)) {
                        throw new DomainException('Only unfinished operator replies can be cancelled.');
                    }

                    $message->update(['delivery_status' => DeliveryStatus::Cancelled]);

                    return true;
                });
            });

        if (! $cancelled) {
            throw new DomainException('Delivery is already in progress.');
        }
    }

    public function retry(Ticket $ticket, int $messageId): void
    {
        $retried = Cache::store('database')->lock('telegram-delivery:'.$messageId, 50)
            ->get(function () use ($ticket, $messageId): bool {
                return DB::transaction(function () use ($ticket, $messageId): bool {
                    $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());
                    $message = $lockedTicket->messages()
                        ->where('direction', MessageDirection::Outbound)
                        ->where('author', MessageAuthor::Operator)
                        ->lockForUpdate()
                        ->findOrFail($messageId);

                    if ($message->delivery_status !== DeliveryStatus::Failed) {
                        throw new DomainException('Only failed operator replies can be retried.');
                    }

                    $message->update(['delivery_status' => DeliveryStatus::Pending]);
                    DeliverTelegramMessage::dispatch($message->id);

                    return true;
                });
            });

        if (! $retried) {
            throw new DomainException('Delivery is already in progress.');
        }
    }

    public function create(User $operator, Ticket $ticket, string $body): Message
    {
        $sanitized = $this->sanitizer->sanitize($body);

        return DB::transaction(function () use ($operator, $ticket, $sanitized): Message {
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            if ($lockedTicket->status === TicketStatus::Closed) {
                throw new DomainException('Closed tickets cannot receive an operator reply.');
            }

            if ($lockedTicket->messages()
                ->where('direction', MessageDirection::Outbound)
                ->where('author', MessageAuthor::Operator)
                ->whereIn('delivery_status', [DeliveryStatus::Pending, DeliveryStatus::Failed])
                ->exists()) {
                throw ValidationException::withMessages([
                    'replyBody' => 'Дождитесь отправки предыдущего ответа или отмените его. Для ответа с ошибкой выберите «Повторить» или «Отменить».',
                ]);
            }

            $limit = $this->presentation->operatorReplyLimit($lockedTicket);

            if (mb_strlen($sanitized->text, 'UTF-8') > $limit) {
                throw ValidationException::withMessages([
                    'replyBody' => "Ответ слишком длинный: максимум {$limit} символов с учётом номера обращения и цитаты. Сократите ответ.",
                ]);
            }

            $this->tickets->reopen($lockedTicket);

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

            DeliverTelegramMessage::dispatch($message->id);

            return $message;
        });
    }
}
