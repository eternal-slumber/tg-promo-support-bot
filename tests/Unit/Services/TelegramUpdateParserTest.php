<?php

use App\Enums\TelegramUpdateKind;
use App\Services\TelegramUpdateParser;

test('parses a text message into a transport dto', function () {
    $update = (new TelegramUpdateParser)->parse([
        'update_id' => 100,
        'message' => [
            'message_id' => 200,
            'from' => ['id' => 300],
            'chat' => ['id' => 400, 'type' => 'private'],
            'text' => 'Когда будут результаты?',
        ],
    ]);

    expect($update)->not->toBeNull()
        ->and($update->updateId)->toBe(100)
        ->and($update->kind)->toBe(TelegramUpdateKind::Message)
        ->and($update->telegramUserId)->toBe(300)
        ->and($update->chatId)->toBe(400)
        ->and($update->telegramMessageId)->toBe(200)
        ->and($update->text)->toBe('Когда будут результаты?');
});

test('identifies legacy callback queries as unsupported without carrying their data', function () {
    $update = (new TelegramUpdateParser)->parse([
        'update_id' => 101,
        'callback_query' => [
            'id' => 'callback-id',
            'from' => ['id' => 301],
            'message' => ['chat' => ['id' => 401, 'type' => 'private']],
            'data' => 'resolved:1',
        ],
    ]);

    expect($update)->not->toBeNull()
        ->and($update->kind)->toBe(TelegramUpdateKind::Unsupported)
        ->and($update->telegramUserId)->toBeNull()
        ->and($update->chatId)->toBeNull()
        ->and($update->text)->toBeNull();
});

test('rejects malformed payloads and identifies unsupported updates', function () {
    $parser = new TelegramUpdateParser;

    expect($parser->parse(['message' => []]))->toBeNull()
        ->and($parser->parse(['update_id' => 102, 'inline_query' => []])?->kind)->toBe(TelegramUpdateKind::Unsupported);
});

test('ignores messages and callbacks without an explicitly private chat', function (mixed $chatType) {
    $chat = ['id' => -400];

    if ($chatType !== null) {
        $chat['type'] = $chatType;
    }

    $parser = new TelegramUpdateParser;
    $message = $parser->parse([
        'update_id' => 103,
        'message' => [
            'message_id' => 200,
            'from' => ['id' => 300],
            'chat' => $chat,
            'text' => 'Личный вопрос',
        ],
    ]);
    $callback = $parser->parse([
        'update_id' => 104,
        'callback_query' => [
            'id' => 'callback-id',
            'from' => ['id' => 300],
            'message' => ['chat' => $chat],
            'data' => 'resolved:1',
        ],
    ]);

    expect($message?->kind)->toBe(TelegramUpdateKind::Unsupported);
    expect($callback?->kind)->toBe(TelegramUpdateKind::Unsupported);
})->with([
    'group' => ['group'],
    'supergroup' => ['supergroup'],
    'channel' => ['channel'],
    'missing type' => [null],
    'invalid type' => [['private']],
]);

test('distinguishes private non-text messages from service events without carrying their content', function (array $content, bool $isNonTextMessage) {
    $update = (new TelegramUpdateParser)->parse([
        'update_id' => 105,
        'message' => array_merge([
            'message_id' => 200,
            'chat' => ['id' => 300, 'type' => 'private'],
            'date' => 1712340000,
        ], $content),
    ]);

    expect($update?->kind)->toBe($isNonTextMessage ? TelegramUpdateKind::NonTextMessage : TelegramUpdateKind::Unsupported);
    expect($update?->updateId)->toBe(105);
    expect($update?->text)->toBeNull();
    expect($update?->telegramUserId)->toBe($isNonTextMessage ? 300 : null);
    expect($update?->chatId)->toBe($isNonTextMessage ? 300 : null);
    expect($update?->telegramMessageId)->toBe($isNonTextMessage ? 200 : null);
    expect($update?->isTextMessage())->toBeFalse();
    expect(serialize($update))->not->toContain('photo-file', 'voice-file', 'document-file', 'qwerty123');
})->with([
    'photo without a caption' => [['from' => ['id' => 300], 'photo' => [['file_id' => 'photo-file']]], true],
    'photo with caption' => [[
        'from' => ['id' => 300],
        'photo' => [['file_id' => 'photo-file', 'file_unique_id' => 'photo-id', 'width' => 320, 'height' => 240]],
        'caption' => 'пароль qwerty123',
    ], true],
    'document' => [['from' => ['id' => 300], 'document' => ['file_id' => 'document-file']], true],
    'voice' => [['from' => ['id' => 300], 'voice' => ['file_id' => 'voice-file', 'file_unique_id' => 'voice-id', 'duration' => 10]], true],
    'sticker' => [['from' => ['id' => 300], 'sticker' => ['file_id' => 'photo-file']], true],
    'video' => [['from' => ['id' => 300], 'video' => ['file_id' => 'photo-file']], true],
    'contact' => [['from' => ['id' => 300], 'contact' => ['phone_number' => '+7 999 123-45-67', 'first_name' => 'qwerty123']], true],
    'service event without sender' => [['delete_chat_photo' => true], false],
    'service event with sender' => [['from' => ['id' => 300], 'delete_chat_photo' => true], false],
]);

test('rejects damaged private message fields even when media is present', function (array $changes) {
    $update = (new TelegramUpdateParser)->parse([
        'update_id' => 106,
        'message' => array_replace([
            'message_id' => 200,
            'from' => ['id' => 300],
            'chat' => ['id' => 300, 'type' => 'private'],
            'photo' => [['file_id' => 'photo-file']],
        ], $changes),
    ]);

    expect($update)->toBeNull();
})->with([
    'null text' => [['text' => null]],
    'array text' => [['text' => ['Вопрос']]],
    'numeric text' => [['text' => 123]],
    'text without sender' => [['text' => 'Вопрос', 'from' => null]],
    'missing message id' => [['message_id' => null]],
    'invalid message id' => [['message_id' => '200']],
    'missing chat id' => [['chat' => ['type' => 'private']]],
    'invalid chat id' => [['chat' => ['id' => '300', 'type' => 'private']]],
    'media without sender' => [['from' => null]],
    'media with invalid sender' => [['from' => ['id' => '300']]],
]);
