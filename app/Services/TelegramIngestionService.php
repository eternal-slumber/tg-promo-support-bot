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
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramIngestionService
{
    private const string StartWarning = 'Не отправляйте данные банковских карт, пароли или коды из SMS: они не нужны для поддержки акции.';

    private const string RedactionWarning = 'Чувствительные данные скрыты и не нужны для поддержки акции.';

    private const string RateLimitWarning = 'Вы отправили слишком много вопросов. Попробуйте позже.';

    private const string NonTextMessageWarning = 'Я принимаю только текстовые сообщения. Пришлите вопрос и, если есть, текст подписи к вложению отдельным текстовым сообщением.';

    public function __construct(
        private readonly SensitiveDataSanitizer $sanitizer,
        private readonly TicketLifecycleService $tickets,
    ) {}

    public function ingest(TelegramUpdateData $update): TelegramIngestionResult
    {
        if ($update->kind === TelegramUpdateKind::Unsupported) {
            return new TelegramIngestionResult(false, true);
        }

        return DB::transaction(function () use ($update): TelegramIngestionResult {
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

            $result = $this->ingestMessage($update);

            if ($result->messageId !== null) {
                ProcessIncomingMessage::dispatch($result->messageId);
            }

            return $result;
        });
    }

    private function ingestMessage(TelegramUpdateData $update): TelegramIngestionResult
    {
        if ((! $update->isTextMessage() && $update->kind !== TelegramUpdateKind::NonTextMessage)
            || $update->telegramUserId === null || $update->chatId === null || $update->telegramMessageId === null) {
            return new TelegramIngestionResult(false, true);
        }

        $participant = $this->upsertParticipant($update->telegramUserId, $update->chatId);
        $telegramUpdate = TelegramUpdate::query()->where('update_id', $update->updateId)->firstOrFail();
        $telegramUpdate->update(['participant_id' => $participant->id]);

        $activeTicket = $this->tickets->activeFor($participant);

        if ($update->kind === TelegramUpdateKind::NonTextMessage) {
            if ($activeTicket !== null) {
                $this->tickets->reopen($activeTicket);
            }

            $this->createPendingMessage($participant, $activeTicket?->id, MessageAuthor::System, self::NonTextMessageWarning);

            return new TelegramIngestionResult(false, false);
        }

        if ($activeTicket === null && ! $this->isStartCommand($update->text) && ! $this->allowAiRequest($participant)) {
            return new TelegramIngestionResult(false, true);
        }

        $sanitized = $this->sanitizer->sanitize($update->text);
        $message = Message::query()->create([
            'participant_id' => $participant->id,
            'ticket_id' => null,
            'telegram_update_id' => $telegramUpdate->id,
            'direction' => MessageDirection::Inbound,
            'author' => MessageAuthor::Participant,
            'body' => $sanitized->text,
            'telegram_message_id' => $update->telegramMessageId,
            'sensitive_data_redacted' => $sanitized->wasRedacted,
            'redaction_types' => $sanitized->redactionTypes,
        ]);

        if ($activeTicket !== null) {
            $this->tickets->attachParticipantMessage($activeTicket, $message);
        }

        if ($sanitized->wasRedacted) {
            $this->createPendingMessage($participant, $activeTicket?->id, MessageAuthor::System, self::RedactionWarning);
        }

        if ($this->isStartCommand($update->text)) {
            $this->createPendingMessage($participant, $activeTicket?->id, MessageAuthor::System, self::StartWarning);

            return new TelegramIngestionResult(false, false);
        }

        if ($activeTicket !== null) {
            return new TelegramIngestionResult(false, false);
        }

        return new TelegramIngestionResult(false, false, $message->id);
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

        return TelegramParticipant::query()->where('telegram_user_id', $telegramUserId)->lockForUpdate()->firstOrFail();
    }

    /** The participant row lock serializes quota checks; database counters roll back with the queued work. */
    private function allowAiRequest(TelegramParticipant $participant): bool
    {
        $cache = Cache::store('telegram_limits');
        $limiter = new RateLimiter($cache);
        $limits = [
            "telegram-ai:minute:{$participant->id}" => [(int) config('llm.requests_per_minute'), 60],
            "telegram-ai:day:{$participant->id}" => [(int) config('llm.requests_per_day'), 86400],
        ];

        foreach ($limits as $key => [$maxRequests, $seconds]) {
            if ($limiter->tooManyAttempts($key, $maxRequests)) {
                $this->createPendingMessage($participant, null, MessageAuthor::System, self::RateLimitWarning);

                return false;
            }
        }

        foreach ($limits as $key => [$maxRequests, $seconds]) {
            $limiter->hit($key, $seconds);
        }

        return true;
    }

    private function createPendingMessage(
        TelegramParticipant $participant,
        ?int $ticketId,
        MessageAuthor $author,
        string $body,
    ): void {
        if (! Cache::store('telegram_limits')->add('telegram-notice:'.$participant->id.':'.hash('sha256', $body), true, 60)) {
            return;
        }

        $message = Message::query()->create([
            'participant_id' => $participant->id,
            'ticket_id' => $ticketId,
            'direction' => MessageDirection::Outbound,
            'author' => $author,
            'body' => $body,
            'delivery_status' => DeliveryStatus::Pending,
        ]);

        DeliverTelegramMessage::dispatch($message->id);
    }

    private function isStartCommand(string $text): bool
    {
        $command = mb_strtolower(strtok(trim($text), " \t\r\n") ?: '');

        return $command === '/start' || str_starts_with($command, '/start@');
    }
}
