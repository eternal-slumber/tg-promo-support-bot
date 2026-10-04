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

test('sends a text message with the configured endpoint chat and text', function () {
    Http::preventStrayRequests();
    Http::fake(['https://telegram.example/bottest-token/sendMessage' => Http::response([
        'ok' => true,
        'result' => ['message_id' => 123],
    ])]);

    $sent = app(TelegramBotApiClient::class)->sendMessage(new TelegramOutboundMessage(100, 'Текст'));

    expect($sent->messageId)->toBe(123);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://telegram.example/bottest-token/sendMessage'
        && $request['chat_id'] === 100
        && $request['text'] === 'Текст');
});

test('removes a saved feedback keyboard when sending ordinary text', function () {
    Http::preventStrayRequests();
    Http::fake(['https://telegram.example/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 124]])]);

    app(TelegramBotApiClient::class)->sendMessage(new TelegramOutboundMessage(100, 'Текст'));

    Http::assertSent(fn (Request $request): bool => $request['reply_markup'] === ['remove_keyboard' => true]);
    Http::assertSentCount(1);
});

test('maps a connection failure to a safe typed error', function () {
    Http::preventStrayRequests();
    Http::fake(['https://telegram.example/bottest-token/sendMessage' => Http::failedConnection()]);

    expect(fn () => app(TelegramBotApiClient::class)->sendMessage(new TelegramOutboundMessage(100, 'Текст')))
        ->toThrow(TelegramDeliveryException::class);
});

test('excludes the bot token from connection exceptions for logs and failed jobs', function () {
    $method = 'sendMessage';
    Http::preventStrayRequests();
    Http::fake([
        'https://telegram.example/bottest-token/'.$method => Http::failedConnection(
            'Connection failed for https://telegram.example/bottest-token/'.$method,
        ),
    ]);

    try {
        app(TelegramBotApiClient::class)->sendMessage(new TelegramOutboundMessage(100, 'Текст'));

        $this->fail('Expected a Telegram delivery exception.');
    } catch (TelegramDeliveryException $exception) {
        expect($exception->safeError)->toBe('telegram_connection_failed');
        expect($exception->retryable)->toBeTrue();
        expect($exception->getPrevious())->toBeNull();
        expect((string) $exception)->not->toContain('test-token');
    }
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

test('preserves only a validated flood control delay without retaining provider content', function (mixed $retryAfter, int $expected) {
    Http::preventStrayRequests();
    Http::fake(['https://telegram.example/bottest-token/sendMessage' => Http::response([
        'ok' => false,
        'error_code' => 429,
        'description' => 'private response with test-token and a participant message',
        'parameters' => ['retry_after' => $retryAfter],
    ], 429)]);

    try {
        app(TelegramBotApiClient::class)->sendMessage(new TelegramOutboundMessage(100, 'Текст'));
        $this->fail('Expected flood control.');
    } catch (TelegramDeliveryException $exception) {
        expect($exception->safeError)->toBe('telegram_rate_limited');
        expect($exception->retryAfterSeconds)->toBe($expected);
        expect($exception->retryable)->toBeTrue();
        expect($exception->getPrevious())->toBeNull();
        expect((string) $exception)->not->toContain('test-token', 'participant message', 'private response');
    }
    Http::assertSentCount(1);
})->with([
    'one second' => [1, 1],
    'one minute' => [60, 60],
    'one day' => [86400, 86400],
    'large integer' => [PHP_INT_MAX, PHP_INT_MAX],
    'missing' => [null, 60],
    'zero' => [0, 60],
    'negative' => [-1, 60],
    'string' => ['60', 60],
    'fraction' => [1.5, 60],
    'boolean' => [true, 60],
    'array' => [[1], 60],
]);
