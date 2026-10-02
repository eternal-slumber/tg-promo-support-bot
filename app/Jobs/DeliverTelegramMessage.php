<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Exceptions\TelegramDeliveryException;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\TelegramBotClient;
use App\Services\TelegramMessagePresentation;
use App\Services\TicketLifecycleService;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DeliverTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 40;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

    public function __construct(public readonly int $messageId) {}

    public function handle(
        TelegramBotClient $client,
        TelegramMessagePresentation $presentation,
        TicketLifecycleService $ticketLifecycle,
    ): void {
        $message = DB::transaction(function (): ?Message {
            $lockedMessage = Message::query()
                ->with(['participant', 'ticket'])
                ->lockForUpdate()
                ->find($this->messageId);

            if ($lockedMessage === null
                || $lockedMessage->direction !== MessageDirection::Outbound
                || $lockedMessage->delivery_status === DeliveryStatus::Sent
                || ! in_array($lockedMessage->delivery_status, [DeliveryStatus::Pending, DeliveryStatus::Failed], true)) {
                return null;
            }

            if ($lockedMessage->author === MessageAuthor::Operator
                && $lockedMessage->ticket?->status !== TicketStatus::Open) {
                return null;
            }

            $lockedMessage->increment('delivery_attempts');

            return $lockedMessage->fresh(['participant', 'ticket']);
        });

        if ($message === null) {
            return;
        }

        try {
            $sentMessage = $client->sendMessage($presentation->present($message));
        } catch (TelegramDeliveryException $exception) {
            $this->markFailed($exception->safeError);

            if ($exception->retryable) {
                throw $exception;
            }

            return;
        }

        $waitingTicket = DB::transaction(function () use ($sentMessage, $ticketLifecycle): ?Ticket {
            $lockedMessage = Message::query()->with('ticket')->lockForUpdate()->findOrFail($this->messageId);

            if ($lockedMessage->delivery_status === DeliveryStatus::Sent) {
                return null;
            }

            $lockedMessage->update([
                'delivery_status' => DeliveryStatus::Sent,
                'telegram_message_id' => $sentMessage->messageId,
                'delivered_at' => now(),
                'last_delivery_error' => null,
            ]);

            if ($lockedMessage->author === MessageAuthor::Operator && $lockedMessage->ticket !== null) {
                try {
                    return $ticketLifecycle->waitForUser($lockedMessage->ticket)->fresh();
                } catch (DomainException) {
                    return null;
                }
            }

            return null;
        });

        if ($waitingTicket !== null && $waitingTicket->waiting_since !== null) {
            AutoCloseTicket::dispatch($waitingTicket->id, $waitingTicket->waiting_since->toISOString())
                ->delay(now()->addHours((int) config('support.ticket_auto_close_hours')));
        }
    }

    private function markFailed(string $safeError): void
    {
        DB::transaction(function () use ($safeError): void {
            $lockedMessage = Message::query()->lockForUpdate()->findOrFail($this->messageId);

            if ($lockedMessage->delivery_status === DeliveryStatus::Sent) {
                return;
            }

            $lockedMessage->update([
                'delivery_status' => DeliveryStatus::Failed,
                'last_delivery_error' => $safeError,
            ]);
        });
    }
}
