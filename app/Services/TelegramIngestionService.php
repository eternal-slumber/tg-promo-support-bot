<?php

namespace App\Services;

use App\Data\TelegramIngestionResult;
use App\Data\TelegramUpdateData;
use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\TelegramUpdateKind;
use App\Jobs\DeliverTelegramMessage;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\TelegramUpdate;
use Illuminate\Support\Facades\DB;

class TelegramIngestionService
{
    private const string StartWarning = 'Не отправляйте данные банковских карт, пароли или коды из SMS: они не нужны для поддержки акции.';

    private const string RedactionWarning = 'Чувствительные данные скрыты и не нужны для поддержки акции.';

    public function __construct(
        private readonly SensitiveDataSanitizer $sanitizer,
        private readonly TicketLifecycleService $tickets,
    ) {}

    public function ingest(TelegramUpdateData $update): TelegramIngestionResult
    {
        if ($update->kind === TelegramUpdateKind::Unsupported) {
            return new TelegramIngestionResult(false, true);
        }

        $result = DB::transaction(function () use ($update): TelegramIngestionResult {
            $now = now();
            $inserted = TelegramUpdate::query()->insertOrIgnore([
                'update_id' => $update->updateId,
                'kind' => $update->kind->value,
                'received_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 0) {
                return new TelegramIngestionResult(true, false);
            }

            if ($update->kind === TelegramUpdateKind::CallbackQuery) {
                $this->attachCallbackParticipant($update);

                return new TelegramIngestionResult(false, false);
            }

            return $this->ingestTextMessage($update);
        });

        if ($result->messageId !== null) {
            ProcessIncomingMessage::dispatch($result->messageId)->afterCommit();
        }

        return $result;
    }

    private function ingestTextMessage(TelegramUpdateData $update): TelegramIngestionResult
    {
        if (! $update->isTextMessage() || $update->telegramUserId === null || $update->chatId === null || $update->telegramMessageId === null) {
            return new TelegramIngestionResult(false, true);
        }

        $participant = $this->upsertParticipant($update->telegramUserId, $update->chatId);
        $telegramUpdate = TelegramUpdate::query()->where('update_id', $update->updateId)->firstOrFail();
        $telegramUpdate->update(['participant_id' => $participant->id]);

        $sanitized = $this->sanitizer->sanitize($update->text);
        $activeTicket = $this->tickets->activeFor($participant);
        $message = Message::query()->create([
            'participant_id' => $participant->id,
            'ticket_id' => $activeTicket?->id,
            'telegram_update_id' => $telegramUpdate->id,
            'direction' => MessageDirection::Inbound,
            'author' => MessageAuthor::Participant,
            'body' => $sanitized->text,
            'telegram_message_id' => $update->telegramMessageId,
            'sensitive_data_redacted' => $sanitized->wasRedacted,
            'redaction_types' => $sanitized->redactionTypes,
        ]);

        if ($sanitized->wasRedacted) {
            $this->createPendingMessage($participant, $activeTicket?->id, MessageAuthor::System, self::RedactionWarning);
        }

        if ($this->isStartCommand($update->text)) {
            $this->createPendingMessage($participant, $activeTicket?->id, MessageAuthor::Bot, self::StartWarning);

            return new TelegramIngestionResult(false, false);
        }

        if ($activeTicket !== null) {
            return new TelegramIngestionResult(false, false);
        }

        return new TelegramIngestionResult(false, false, $message->id);
    }

    private function attachCallbackParticipant(TelegramUpdateData $update): void
    {
        if ($update->telegramUserId === null || $update->chatId === null) {
            return;
        }

        $participant = $this->upsertParticipant($update->telegramUserId, $update->chatId);

        TelegramUpdate::query()
            ->where('update_id', $update->updateId)
            ->update(['participant_id' => $participant->id]);
    }

    private function upsertParticipant(int $telegramUserId, int $chatId): TelegramParticipant
    {
        $now = now();

        TelegramParticipant::query()->upsert(
            [[
                'telegram_user_id' => $telegramUserId,
                'chat_id' => $chatId,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['telegram_user_id'],
            ['chat_id', 'updated_at'],
        );

        return TelegramParticipant::query()->where('telegram_user_id', $telegramUserId)->firstOrFail();
    }

    private function createPendingMessage(
        TelegramParticipant $participant,
        ?int $ticketId,
        MessageAuthor $author,
        string $body,
    ): void {
        $message = Message::query()->create([
            'participant_id' => $participant->id,
            'ticket_id' => $ticketId,
            'direction' => MessageDirection::Outbound,
            'author' => $author,
            'body' => $body,
            'delivery_status' => DeliveryStatus::Pending,
        ]);

        DeliverTelegramMessage::dispatch($message->id)->afterCommit();
    }

    private function isStartCommand(string $text): bool
    {
        $command = mb_strtolower(strtok(trim($text), " \t\r\n") ?: '');

        return $command === '/start' || str_starts_with($command, '/start@');
    }
}
