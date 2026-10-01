<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use App\Services\TelegramMessagePresentation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('presents an operator response with ticket number safe quote and callbacks', function () {
    $participant = TelegramParticipant::factory()->create(['chat_id' => 500]);
    $ticket = Ticket::factory()->for($participant, 'participant')->create();
    Message::factory()->for($participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Inbound,
        'author' => MessageAuthor::Participant,
        'body' => 'Доставка задерживается, карта [REDACTED_PAYMENT_CARD], телефон +7 910 123-45-67',
        'sensitive_data_redacted' => true,
    ]);
    $reply = Message::factory()->for($participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'body' => 'Проверим статус доставки.',
        'delivery_status' => DeliveryStatus::Pending,
    ]);

    $outbound = app(TelegramMessagePresentation::class)->present($reply);

    expect($outbound->chatId)->toBe(500)
        ->and($outbound->text)->toContain("Ответ оператора по обращению #{$ticket->id}", 'Проверим статус доставки.', '[REDACTED_PAYMENT_CARD]')
        ->and($outbound->text)->not->toContain('+7 910 123-45-67')
        ->and($outbound->replyMarkup['inline_keyboard'][0][0]['text'])->toBe('Проблема решена')
        ->and($outbound->replyMarkup['inline_keyboard'][0][1]['text'])->toBe('Не решило мою проблему');
});

test('limits a participant quote without exposing a phone number', function () {
    $participant = TelegramParticipant::factory()->create(['chat_id' => 500]);
    $ticket = Ticket::factory()->for($participant, 'participant')->create();
    Message::factory()->for($participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Inbound,
        'author' => MessageAuthor::Participant,
        'body' => '+7 910 123-45-67 '.str_repeat('длинный текст ', 20),
    ]);
    $reply = Message::factory()->for($participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);

    $outbound = app(TelegramMessagePresentation::class)->present($reply);

    expect($outbound->text)->not->toContain('+7 910 123-45-67')
        ->and($outbound->text)->toContain('[скрыто]', '…');
});
