<?php

use App\Data\TelegramOutboundMessage;
use App\Exceptions\TelegramDeliveryException;
use App\Services\TelegramBotApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('telegram.api_base_url', 'https://telegram.example');
    config()->set('telegram.bot_token', 'test-token');
});

test('guards the Telegram Unicode text limit before HTTP', function (int $extraCharacters) {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
    $outbound = new TelegramOutboundMessage(100, str_repeat('🙂', TelegramOutboundMessage::MaxTextLength + $extraCharacters));

    if ($extraCharacters > 0) {
        expect(fn () => app(TelegramBotApiClient::class)->sendMessage($outbound))
            ->toThrow(TelegramDeliveryException::class, 'telegram_message_too_long');
        Http::assertNothingSent();

        return;
    }

    expect(app(TelegramBotApiClient::class)->sendMessage($outbound)->messageId)->toBe(123);
    Http::assertSent(fn (Request $request): bool => $request['text'] === $outbound->text);
})->with(['at limit' => [0], 'over limit' => [1]]);

test('sends a text message with optional inline keyboard', function () {
    Http::preventStrayRequests();
    Http::fake(['https://telegram.example/bottest-token/sendMessage' => Http::response([
        'ok' => true,
        'result' => ['message_id' => 123],
    ])]);

    $sent = app(TelegramBotApiClient::class)->sendMessage(new TelegramOutboundMessage(100, 'Текст', [
        'inline_keyboard' => [[['text' => 'Проблема решена', 'callback_data' => 'resolved:1']]],
    ]));

    expect($sent->messageId)->toBe(123);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://telegram.example/bottest-token/sendMessage'
        && $request['chat_id'] === 100
        && $request['text'] === 'Текст'
        && $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] === 'resolved:1');
});

test('acknowledges a callback query', function () {
    Http::preventStrayRequests();
    Http::fake(['https://telegram.example/bottest-token/answerCallbackQuery' => Http::response(['ok' => true, 'result' => true])]);

    app(TelegramBotApiClient::class)->acknowledgeCallback('callback-id');

    Http::assertSent(fn (Request $request): bool => $request['callback_query_id'] === 'callback-id');
});

test('maps a connection failure to a safe typed error', function () {
    Http::preventStrayRequests();
    Http::fake(['https://telegram.example/bottest-token/sendMessage' => Http::failedConnection()]);

    expect(fn () => app(TelegramBotApiClient::class)->sendMessage(new TelegramOutboundMessage(100, 'Текст')))
        ->toThrow(TelegramDeliveryException::class);
});

test('maps rate limit and server errors to safe typed errors', function (int $status) {
    Http::preventStrayRequests();
    Http::fake(['https://telegram.example/bottest-token/sendMessage' => Http::response([], $status)]);

    expect(fn () => app(TelegramBotApiClient::class)->sendMessage(new TelegramOutboundMessage(100, 'Текст')))
        ->toThrow(TelegramDeliveryException::class);
})->with([
    'rate limit' => [429],
    'server error' => [503],
]);

test('maps an invalid provider body to a safe typed error', function () {
    Http::preventStrayRequests();
    Http::fake(['https://telegram.example/bottest-token/sendMessage' => Http::response(['ok' => false])]);

    expect(fn () => app(TelegramBotApiClient::class)->sendMessage(new TelegramOutboundMessage(100, 'Текст')))
        ->toThrow(TelegramDeliveryException::class);
});
