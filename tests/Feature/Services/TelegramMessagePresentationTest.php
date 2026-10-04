<?php

use App\Data\TelegramOutboundMessage;
use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use App\Services\TelegramMessagePresentation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('bounds automatic Unicode message presentation without changing stored text', function (MessageAuthor $author, int $extraCharacters) {
    $body = str_repeat('🙂', TelegramOutboundMessage::MaxTextLength + $extraCharacters);
    $message = Message::factory()->create([
        'direction' => MessageDirection::Outbound,
        'author' => $author,
        'body' => $body,
        'delivery_status' => DeliveryStatus::Pending,
    ]);

    $outbound = app(TelegramMessagePresentation::class)->present($message);

    expect(mb_strlen($outbound->text, 'UTF-8'))->toBe(TelegramOutboundMessage::MaxTextLength)
        ->and($message->refresh()->body)->toBe($body);

    if ($extraCharacters === 0) {
        expect($outbound->text)->toBe($body);
    } else {
        expect($outbound->text)->toEndWith('… [сообщение сокращено]');
    }
})->with([
    'bot at limit' => [MessageAuthor::Bot, 0],
    'bot over limit' => [MessageAuthor::Bot, 1],
    'system over limit' => [MessageAuthor::System, 1],
]);

test('presents an operator response with ticket number safe quote without a feedback keyboard', function () {
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
        ->and($outbound->text)->not->toContain('+7 910 123-45-67');
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

test('masks formatted Russian phone numbers in the complete operator reply while preserving the stored question', function (string $question, string $expectedQuote) {
    $ticket = Ticket::factory()->create();
    $inbound = Message::factory()->for($ticket->participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Inbound,
        'author' => MessageAuthor::Participant,
        'body' => $question,
    ]);
    $reply = Message::factory()->for($ticket->participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'body' => 'Проверим аккаунт.',
        'delivery_status' => DeliveryStatus::Pending,
    ]);

    $presentation = app(TelegramMessagePresentation::class);
    $outbound = $presentation->present($reply);

    expect($outbound->text)->toBe("Ответ оператора по обращению #{$ticket->id}\n\nПроверим аккаунт.\n\nВаш вопрос: «{$expectedQuote}»");
    expect($presentation->operatorReplyLimit($ticket))->toBe(TelegramOutboundMessage::MaxTextLength
        - mb_strlen("Ответ оператора по обращению #{$ticket->id}\n\n\n\nВаш вопрос: «{$expectedQuote}»", 'UTF-8'));
    expect($inbound->refresh()->body)->toBe($question);
})->with([
    'review reproduction' => ['Телефон +7 (910) 123-45-67, проверьте аккаунт.', 'Телефон [скрыто], проверьте аккаунт.'],
    'national prefix' => ['Телефон 8 (910) 123-45-67.', 'Телефон [скрыто].'],
    'compact parentheses' => ['Телефон +7(910)1234567.', 'Телефон [скрыто].'],
    'compact international' => ['Телефон +79101234567.', 'Телефон [скрыто].'],
    'international attached to text' => ['Телефон+79101234567.', 'Телефон[скрыто].'],
    'compact national' => ['Телефон 89101234567.', 'Телефон [скрыто].'],
    'space separated' => ['Телефон +7 910 123 45 67.', 'Телефон [скрыто].'],
    'hyphen separated' => ['Телефон +7-910-123-45-67.', 'Телефон [скрыто].'],
    'Unicode spaces' => ["Телефон +7\u{00A0}(910)\u{202F}123-45-67.", 'Телефон [скрыто].'],
    'Unicode hyphens' => ["Телефон +7 (910) 123\u{2011}45\u{2011}67.", 'Телефон [скрыто].'],
    'spaces within parentheses' => ['Телефон +7 ( 910 ) 123-45-67.', 'Телефон [скрыто].'],
    'multiple phones' => ['Телефоны +7 (910) 123-45-67 и 8 (999) 765-43-21.', 'Телефоны [скрыто] и [скрыто].'],
    'surrounding parentheses' => ['Контакт (+7 (910) 123-45-67), спасибо.', 'Контакт ([скрыто]), спасибо.'],
]);

test('preserves ordinary numbers and identifiers in the operator quote', function (string $question) {
    $ticket = Ticket::factory()->create();
    Message::factory()->for($ticket->participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Inbound,
        'author' => MessageAuthor::Participant,
        'body' => $question,
    ]);
    $reply = Message::factory()->for($ticket->participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'body' => 'Проверим аккаунт.',
    ]);

    $outbound = app(TelegramMessagePresentation::class)->present($reply);

    expect($outbound->text)->toBe("Ответ оператора по обращению #{$ticket->id}\n\nПроверим аккаунт.\n\nВаш вопрос: «{$question}»");
})->with([
    'ordinary values' => 'Купил 8 упаковок за 910 рублей 01.10.2026, чек 1234567.',
    'short number' => 'Код операции 8 (910) 123-45-6.',
    'long national number' => 'Номер операции 891012345678.',
    'long international number' => 'Номер операции +791012345678.',
    'embedded numeric identifier' => 'Номер операции 0189101234567.',
    'embedded alphanumeric identifier' => 'Номер операции REF89101234567.',
]);
