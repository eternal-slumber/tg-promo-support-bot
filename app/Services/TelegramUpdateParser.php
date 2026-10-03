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

            if (data_get($message, 'chat.type') !== 'private') {
                return new TelegramUpdateData($updateId, TelegramUpdateKind::Unsupported);
            }

            $chatId = $this->integer(data_get($message, 'chat.id'));
            $telegramMessageId = $this->integer($message['message_id'] ?? null);

            if ($chatId === null || $telegramMessageId === null) {
                return null;
            }

            $telegramUserId = $this->integer(data_get($message, 'from.id'));

            if (! array_key_exists('text', $message)) {
                if (! array_any([
                    'animation', 'audio', 'document', 'live_photo', 'paid_media', 'photo', 'sticker', 'story',
                    'video', 'video_note', 'voice', 'rich_message', 'checklist', 'contact', 'dice', 'game',
                    'poll', 'venue', 'location', 'invoice', 'giveaway', 'giveaway_winners', 'passport_data',
                ], fn (string $field): bool => is_array($message[$field] ?? null))) {
                    return new TelegramUpdateData($updateId, TelegramUpdateKind::Unsupported);
                }

                if ($telegramUserId === null) {
                    return null;
                }

                return new TelegramUpdateData(
                    updateId: $updateId,
                    kind: TelegramUpdateKind::NonTextMessage,
                    telegramUserId: $telegramUserId,
                    chatId: $chatId,
                    telegramMessageId: $telegramMessageId,
                );
            }

            $text = $message['text'] ?? null;

            if ($telegramUserId === null || ! is_string($text)) {
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

        return new TelegramUpdateData($updateId, TelegramUpdateKind::Unsupported);
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
