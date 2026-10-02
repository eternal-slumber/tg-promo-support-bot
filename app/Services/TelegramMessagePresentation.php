<?php

namespace App\Services;

use App\Data\TelegramOutboundMessage;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Support\Str;

class TelegramMessagePresentation
{
    public function present(Message $message): TelegramOutboundMessage
    {
        $message->loadMissing('participant', 'ticket');
        $text = $message->body;

        if ($message->author === MessageAuthor::Operator && $message->ticket !== null) {
            $text = $this->operatorReplyText($message->ticket, $message->body);
        } elseif ($message->author !== MessageAuthor::Operator && mb_strlen($text, 'UTF-8') > TelegramOutboundMessage::MaxTextLength) {
            $suffix = '… [сообщение сокращено]';
            $text = mb_substr($text, 0, TelegramOutboundMessage::MaxTextLength - mb_strlen($suffix, 'UTF-8'), 'UTF-8').$suffix;
        }

        return new TelegramOutboundMessage(
            $message->participant->chat_id,
            $text,
            $this->replyMarkup($message),
        );
    }

    public function operatorReplyLimit(Ticket $ticket): int
    {
        return TelegramOutboundMessage::MaxTextLength - mb_strlen($this->operatorReplyText($ticket, ''), 'UTF-8');
    }

    private function operatorReplyText(Ticket $ticket, string $body): string
    {
        $text = "Ответ оператора по обращению #{$ticket->id}\n\n{$body}";
        $quote = $this->quote($ticket);

        if ($quote !== null) {
            $text .= "\n\nВаш вопрос: «{$quote}»";
        }

        return $text;
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

    private function quote(Ticket $ticket): ?string
    {
        $inbound = $ticket->messages()
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
