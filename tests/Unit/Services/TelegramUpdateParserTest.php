<?php

use App\Enums\TelegramUpdateKind;
use App\Services\TelegramUpdateParser;

test('parses a text message into a transport dto', function () {
    $update = (new TelegramUpdateParser)->parse([
        'update_id' => 100,
        'message' => [
            'message_id' => 200,
            'from' => ['id' => 300],
            'chat' => ['id' => 400],
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

test('parses callback query transport fields without applying business logic', function () {
    $update = (new TelegramUpdateParser)->parse([
        'update_id' => 101,
        'callback_query' => [
            'id' => 'callback-id',
            'from' => ['id' => 301],
            'message' => ['chat' => ['id' => 401]],
            'data' => 'resolved:1',
        ],
    ]);

    expect($update)->not->toBeNull()
        ->and($update->kind)->toBe(TelegramUpdateKind::CallbackQuery)
        ->and($update->telegramUserId)->toBe(301)
        ->and($update->chatId)->toBe(401)
        ->and($update->callbackQueryId)->toBe('callback-id')
        ->and($update->callbackData)->toBe('resolved:1')
        ->and($update->text)->toBeNull();
});

test('rejects malformed payloads and identifies unsupported updates', function () {
    $parser = new TelegramUpdateParser;

    expect($parser->parse(['message' => []]))->toBeNull()
        ->and($parser->parse(['update_id' => 102, 'inline_query' => []])?->kind)->toBe(TelegramUpdateKind::Unsupported);
});
