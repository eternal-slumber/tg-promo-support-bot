<?php

namespace App\Services;

use App\Data\TelegramUpdateData;
use App\Enums\TelegramUpdateKind;

class TelegramUpdateParser
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function parse(array $payload): ?TelegramUpdateData
    {
        $updateId = $this->integer($payload['update_id'] ?? null);

        if ($updateId === null) {
            return null;
        }

        if (is_array($payload['message'] ?? null)) {
            $message = $payload['message'];
            $telegramUserId = $this->integer(data_get($message, 'from.id'));
            $chatId = $this->integer(data_get($message, 'chat.id'));
            $telegramMessageId = $this->integer($message['message_id'] ?? null);
            $text = $message['text'] ?? null;

            if ($telegramUserId === null || $chatId === null || $telegramMessageId === null || ! is_string($text)) {
                return null;
            }

            return new TelegramUpdateData(
                updateId: $updateId,
                kind: TelegramUpdateKind::Message,
                telegramUserId: $telegramUserId,
                chatId: $chatId,
                telegramMessageId: $telegramMessageId,
                text: $text,
            );
        }

        if (is_array($payload['callback_query'] ?? null)) {
            $callback = $payload['callback_query'];

            return new TelegramUpdateData(
                updateId: $updateId,
                kind: TelegramUpdateKind::CallbackQuery,
                telegramUserId: $this->integer(data_get($callback, 'from.id')),
                chatId: $this->integer(data_get($callback, 'message.chat.id')),
            );
        }

        return new TelegramUpdateData($updateId, TelegramUpdateKind::Unsupported);
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
