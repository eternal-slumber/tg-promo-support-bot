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
use DateTimeInterface;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeliverTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 40;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

    public function __construct(public readonly int $messageId)
    {
        $this->onConnection('database')->onQueue('telegram')->beforeCommit();
    }

    /** The queue payload freezes this deadline; rate-limit releases do not consume the exception budget. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(
        TelegramBotClient $client,
        TelegramMessagePresentation $presentation,
        TicketLifecycleService $ticketLifecycle,
    ): void {
        $completed = Cache::store('database')->lock('telegram-delivery:'.$this->messageId, $this->timeout + 10)
            ->get(function () use ($client, $presentation, $ticketLifecycle): bool {
                $this->deliver($client, $presentation, $ticketLifecycle);

                return true;
            });

        if (! $completed) {
            $this->release(5);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $safeError = match (true) {
            $exception instanceof TelegramDeliveryException => $exception->safeError,
            $exception instanceof TimeoutExceededException => 'telegram_delivery_timeout',
            default => 'telegram_delivery_exhausted',
        };

        $this->markFailed($safeError);
    }

    private function deliver(
        TelegramBotClient $client,
        TelegramMessagePresentation $presentation,
        TicketLifecycleService $ticketLifecycle,
    ): void {
        $message = DB::transaction(function (): ?Message {
            $queuedMessage = Message::query()->find($this->messageId);

            if ($queuedMessage?->ticket_id !== null) {
                Ticket::query()->lockForUpdate()->find($queuedMessage->ticket_id);
            }

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

            if ($lockedMessage->ticket?->status === TicketStatus::Closed) {
                $lockedMessage->update(['delivery_status' => DeliveryStatus::Cancelled]);

                return null;
            }

            if ($lockedMessage->author === MessageAuthor::Operator
                && $lockedMessage->ticket === null) {
                return null;
            }

            $retryIn = (int) Cache::store('telegram_limits')->get('telegram-delivery-retry:'.$this->messageId, 0) - now()->getTimestamp();

            if ($retryIn > 0) {
                $this->release($retryIn);

                return null;
            }

            $lockedMessage->increment('delivery_attempts');

            return $lockedMessage->fresh(['participant', 'ticket']);
        });

        if ($message === null) {
            return;
        }

        try {
            $outbound = $presentation->present($message);

            if ($outbound->exceedsTextLimit()) {
                throw new TelegramDeliveryException('telegram_message_too_long', false);
            }

            $sentMessage = $client->sendMessage($outbound);
        } catch (TelegramDeliveryException $exception) {
            if ($exception->retryAfterSeconds !== null) {
                if ($exception->retryAfterSeconds > 86400) {
                    $this->markFailed($exception->safeError);
                    $this->fail($exception);

                    return;
                }

                DB::transaction(function () use ($exception): void {
                    Cache::store('telegram_limits')->put(
                        'telegram-delivery-retry:'.$this->messageId,
                        now()->getTimestamp() + $exception->retryAfterSeconds,
                        $exception->retryAfterSeconds,
                    );
                    $this->markFailed($exception->safeError);
                });
                $this->release($exception->retryAfterSeconds);

                return;
            }

            $this->markFailed($exception->safeError);

            if ($exception->retryable) {
                throw $exception;
            }

            return;
        }

        DB::transaction(function () use ($message, $sentMessage, $ticketLifecycle): void {
            $ticket = $message->ticket_id === null ? null : Ticket::query()->lockForUpdate()->find($message->ticket_id);
            $lockedMessage = Message::query()->lockForUpdate()->findOrFail($this->messageId);

            if ($lockedMessage->delivery_status === DeliveryStatus::Sent) {
                return;
            }

            $lockedMessage->update([
                'delivery_status' => DeliveryStatus::Sent,
                'telegram_message_id' => $sentMessage->messageId,
                'delivered_at' => now(),
                'last_delivery_error' => null,
            ]);

            if ($lockedMessage->author === MessageAuthor::Operator && $ticket !== null) {
                if ($ticket->first_operator_replied_at === null) {
                    $ticket->update(['first_operator_replied_at' => $lockedMessage->delivered_at]);
                }

                if (! $lockedMessage->resolves_ticket) {
                    return;
                }

                try {
                    $resolvedTicket = $ticketLifecycle->resolve($ticket);
                } catch (DomainException) {
                    return;
                }

                AutoCloseTicket::dispatch($resolvedTicket->id, $resolvedTicket->resolved_since->toISOString(), $lockedMessage->id)
                    ->delay($resolvedTicket->resolved_since->copy()->addHours((int) config('support.ticket_auto_close_hours')));
            }
        });
    }

    private function markFailed(string $safeError): void
    {
        Message::query()
            ->whereKey($this->messageId)
            ->where('direction', MessageDirection::Outbound)
            ->whereIn('delivery_status', [DeliveryStatus::Pending, DeliveryStatus::Failed])
            ->update([
                'delivery_status' => DeliveryStatus::Failed,
                'last_delivery_error' => $safeError,
            ]);
    }
}
