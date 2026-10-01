<?php

namespace App\Services;

use App\Data\TelegramOutboundMessage;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Models\Message;
use Illuminate\Support\Str;

class TelegramMessagePresentation
{
    public function present(Message $message): TelegramOutboundMessage
    {
        $message->loadMissing('participant', 'ticket');
        $text = $message->body;

        if ($message->author === MessageAuthor::Operator && $message->ticket !== null) {
            $text = "Ответ оператора по обращению #{$message->ticket->id}\n\n{$message->body}";
            $quote = $this->quote($message);

            if ($quote !== null) {
                $text .= "\n\nВаш вопрос: «{$quote}»";
            }
        }

        return new TelegramOutboundMessage(
            $message->participant->chat_id,
            $text,
            $this->replyMarkup($message),
        );
    }

    /** @return array<string, mixed>|null */
    private function replyMarkup(Message $message): ?array
    {
        if ($message->author !== MessageAuthor::Operator || $message->ticket_id === null) {
            return null;
        }

        return ['inline_keyboard' => [[
            ['text' => 'Проблема решена', 'callback_data' => "resolved:{$message->ticket_id}"],
            ['text' => 'Не решило мою проблему', 'callback_data' => "unresolved:{$message->ticket_id}"],
        ]]];
    }

    private function quote(Message $message): ?string
    {
        $inbound = $message->ticket?->messages()
            ->where('direction', MessageDirection::Inbound)
            ->where('author', MessageAuthor::Participant)
            ->oldest('id')
            ->first();

        if ($inbound === null) {
            return null;
        }

        return Str::limit($this->hidePhoneNumbers($inbound->body), 120, '…');
    }

    private function hidePhoneNumbers(string $text): string
    {
        return preg_replace('/(?:\\+7|8)[\\s-]?(?:\\d[\\s-]?){10}/u', '[скрыто]', $text) ?? $text;
    }
}
