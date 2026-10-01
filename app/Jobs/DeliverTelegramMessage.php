<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Exceptions\TelegramDeliveryException;
use App\Models\Message;
use App\Services\TelegramBotClient;
use App\Services\TelegramMessagePresentation;
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

    public function handle(TelegramBotClient $client, TelegramMessagePresentation $presentation): void
    {
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

        DB::transaction(function () use ($sentMessage): void {
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
        });
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
