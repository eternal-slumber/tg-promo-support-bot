<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\StreamHandler;

uses(LazilyRefreshDatabase::class);

test('reports database failures without SQL bindings provider details or an unsafe previous exception', function (string $exceptionClass, bool $wrapped) {
    $stream = capturePrivacyLog();
    $body = 'private_question +7 (910) 123-45-67';
    $previous = new PDOException('SQLSTATE[23505]: duplicate key DETAIL: '.$body);
    $previous->errorInfo = ['23505', 7, $body];
    $exception = new $exceptionClass('pgsql', 'insert into messages (body) values (?)', [$body], $previous);
    $reported = $wrapped ? new RuntimeException('Cannot persist '.$body, 0, $exception) : $exception;

    report($reported);

    rewind($stream);
    expect(stream_get_contents($stream))->not->toContain('private_question', '+7 (910) 123-45-67', 'insert into', 'duplicate key', 'DETAIL:')
        ->toContain('database_query_failed', 'SQLSTATE 23505', $exceptionClass);
    fclose($stream);
})->with([
    'query failure' => [QueryException::class, false],
    'unique constraint' => [UniqueConstraintViolationException::class, false],
    'wrapped query failure' => [QueryException::class, true],
]);

test('webhook persistence errors return 500 and report safe diagnostics without consuming the update', function () {
    Queue::fake();
    Http::preventStrayRequests();
    config()->set('app.debug', false);
    config()->set('telegram.webhook_secret', 'test-webhook-secret');
    $stream = capturePrivacyLog();
    $body = 'private_webhook_question +7 (910) 123-45-67';
    DB::statement('ALTER TABLE messages ADD CONSTRAINT reject_message_inserts CHECK (false)');

    $response = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
        ->postJson(route('telegram.webhook'), [
            'update_id' => 801,
            'message' => [
                'message_id' => 802,
                'from' => ['id' => 803],
                'chat' => ['id' => 803, 'type' => 'private'],
                'text' => $body,
            ],
        ]);

    $response->assertInternalServerError();
    expect($response->getContent())->not->toContain('private_webhook_question', '+7 (910) 123-45-67');
    rewind($stream);
    expect(stream_get_contents($stream))->not->toContain('private_webhook_question', '+7 (910) 123-45-67', 'insert into', 'Failing row contains')
        ->toContain('database_query_failed', 'SQLSTATE 23514');
    $this->assertDatabaseCount('messages', 0);
    $this->assertDatabaseCount('telegram_updates', 0);
    $this->assertDatabaseCount('telegram_participants', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    DB::statement('ALTER TABLE messages DROP CONSTRAINT reject_message_inserts');
    fclose($stream);
});

test('reporting still records unrelated application errors', function () {
    $stream = capturePrivacyLog();

    report(new RuntimeException('Unexpected application failure.'));

    rewind($stream);
    expect(stream_get_contents($stream))->toContain('Unexpected application failure.', RuntimeException::class);
    fclose($stream);
});

/** @return resource */
function capturePrivacyLog(): mixed
{
    $stream = fopen('php://memory', 'w+');
    config()->set('logging.channels.privacy-test', [
        'driver' => 'monolog',
        'handler' => StreamHandler::class,
        'handler_with' => ['stream' => $stream],
    ]);
    Log::setDefaultDriver('privacy-test');

    return $stream;
}
