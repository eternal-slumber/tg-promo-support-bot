<?php

use App\Data\TelegramUpdateData;
use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\TelegramUpdateKind;
use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Jobs\AutoCloseTicket;
use App\Jobs\DeliverTelegramMessage;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use App\Services\OperatorReplyService;
use App\Services\TelegramIngestionService;
use App\Services\TicketLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** Workers need committed fixtures visible on independent database connections. */
uses(DatabaseMigrations::class);

test('configured delivery and maintenance workers progress while the AI worker waits for its provider', function (string $queue, bool $development) {
    $inbound = Message::factory()->create();
    ProcessIncomingMessage::dispatch($inbound->id)->onQueue($queue);
    $ticket = Ticket::factory()->create();
    $reply = Message::factory()->for($ticket)->create([
        'participant_id' => $ticket->participant_id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    DeliverTelegramMessage::dispatch($reply->id);
    $dueTicket = Ticket::factory()->waitingForUser()->create(['waiting_since' => now()->subDay()]);
    AutoCloseTicket::dispatch($dueTicket->id, $dueTicket->waiting_since->toISOString());
    $input = new InputStream;
    $worker = telegramCapacityWorker('ai', telegramWorkerQueue('queue', $development), $input);

    try {
        $worker->start();
        expect($worker->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'llm-started')))->toBeTrue();

        telegramDeliveryWorker('success', null, telegramWorkerQueue('queue-telegram', $development))->mustRun();
        expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
        expect($worker->isRunning())->toBeTrue();
        $this->artisan('queue:work', [
            'connection' => 'database', '--queue' => telegramWorkerQueue('queue-maintenance', $development), '--once' => true, '--sleep' => 0,
        ])->assertExitCode(0);
        expect($dueTicket->refresh()->status)->toBe(TicketStatus::Closed);
        expect($worker->isRunning())->toBeTrue();
        expect(DB::table('jobs')->where('queue', $queue)->count())->toBe(1);
        expect(DB::table('jobs')->where('queue', 'maintenance')->count())->toBe(1);
        $input->write("continue\n");
        $input->close();
        expect($worker->wait())->toBe(0);
        expect($inbound->refresh()->ticket_id)->not->toBeNull();
    } finally {
        $input->close();
        $worker->stop(0, 9);
    }
})->with(['Docker AI queue' => ['ai', false], 'Docker legacy backlog' => ['default', false], 'local development' => ['ai', true]]);

test('concurrent ingestion processes cannot exceed the same participant AI quota', function () {
    $participant = TelegramParticipant::factory()->create(['telegram_user_id' => 8001, 'chat_id' => 8001]);
    $first = telegramCapacityWorker('ingest', '1');
    $second = telegramCapacityWorker('ingest', '2');
    DB::beginTransaction();
    TelegramParticipant::query()->whereKey($participant->id)->lockForUpdate()->firstOrFail();

    try {
        $first->start();
        expect($first->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ingesting')))->toBeTrue();
        $second->start();
        expect($second->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ingesting')))->toBeTrue();
        DB::commit();
        expect($first->wait())->toBe(0);
        expect($second->wait())->toBe(0);

        $this->assertDatabaseCount('telegram_updates', 2);
        expect(Message::query()->where('direction', MessageDirection::Inbound)->count())->toBe(1);
        expect(DB::table('jobs')->where('queue', 'ai')->count())->toBe(1);
        expect(DB::table('jobs')->where('queue', 'telegram')->count())->toBe(1);
        $this->assertDatabaseCount('failed_jobs', 0);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $first->stop(0, 9);
        $second->stop(0, 9);
    }
});

test('early reply keyboard feedback survives delivery retries without AI jobs or new buttons', function (string $mode, string $text, TicketStatus $expectedStatus, ?TicketCloseReason $expectedReason) {
    Http::preventStrayRequests();
    config()->set('telegram.webhook_secret', 'test-webhook-secret');
    $ticket = Ticket::factory()->create();
    $participant = $ticket->participant;
    $message = Message::factory()->for($ticket)->create([
        'participant_id' => $participant->id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    DeliverTelegramMessage::dispatch($message->id);
    $input = new InputStream;
    $worker = telegramDeliveryWorker($mode, $input);

    try {
        $worker->start();
        expect($worker->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'sending-message')))->toBeTrue();
        expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
        expect($message->refresh()->delivery_attempts)->toBe(1);
        $payload = [
            'update_id' => 9101,
            'message' => [
                'message_id' => 9101,
                'from' => ['id' => $participant->telegram_user_id],
                'chat' => ['id' => $participant->chat_id, 'type' => 'private'],
                'text' => $text,
            ],
        ];
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret');
        $this->postJson(route('telegram.webhook'), $payload)->assertOk()->assertJsonPath('status', 'accepted');
        $this->postJson(route('telegram.webhook'), $payload)->assertOk()->assertJsonPath('status', 'duplicate');
        $inbound = Message::query()->where('direction', MessageDirection::Inbound)->sole();
        expect($inbound->ticket_id)->toBe($ticket->id)->and($inbound->body)->toBe($text);
        $this->assertDatabaseCount('jobs', 1);
        $input->write("continue\n");
        $input->close();
        expect($worker->wait())->toBe(0);

        if ($mode === 'hold-failure') {
            expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
            expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
            makeTelegramDeliveryAvailable();
            telegramDeliveryWorker()->mustRun();
        }

        expect($ticket->refresh()->status)->toBe($expectedStatus)
            ->and($ticket->close_reason)->toBe($expectedReason)
            ->and($ticket->waiting_since)->toBeNull()
            ->and($message->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
        $this->assertDatabaseCount('telegram_updates', 1);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        Http::assertNothingSent();
    } finally {
        $input->close();
        $worker->stop(0, 9);
    }
})->with(['success' => ['hold'], 'retry' => ['hold-failure']])->with([
    'resolved kept for manual closure' => ['Проблема решена', TicketStatus::Open, null],
    'unresolved' => ['Не решило', TicketStatus::Open, null],
    'implicit unresolved' => ['Ошибка осталась', TicketStatus::Open, null],
]);

test('incoming questions survive delivery finalization rollback and keep the ticket open after retry', function () {
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->for($ticket)->create([
        'participant_id' => $ticket->participant_id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    $participant = $ticket->participant;
    app(TelegramIngestionService::class)->ingest(new TelegramUpdateData(92001, TelegramUpdateKind::Message, $participant->telegram_user_id, $participant->chat_id, 92001, 'Не решило'));
    $inbound = Message::query()->where('direction', MessageDirection::Inbound)->sole();
    DeliverTelegramMessage::dispatch($message->id);
    DB::statement("ALTER TABLE messages ADD CONSTRAINT reject_sent CHECK (delivery_status IS DISTINCT FROM 'sent')");

    telegramDeliveryWorker()->mustRun();

    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Pending);
    expect($inbound->refresh()->body)->toBe('Не решило');
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    expect($ticket->first_operator_replied_at)->toBeNull();
    $this->assertDatabaseCount('messages', 2);
    $this->assertDatabaseCount('jobs', 1);
    DB::statement('ALTER TABLE messages DROP CONSTRAINT reject_sent');
    makeTelegramDeliveryAvailable();

    telegramDeliveryWorker()->mustRun();

    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    expect($ticket->first_operator_replied_at?->toDateTimeString())->toBe($message->delivered_at?->toDateTimeString());
    $this->assertDatabaseCount('messages', 2);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
});

test('two workers send the same message only once', function (DeliveryStatus $status) {
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->for($ticket)->create([
        'participant_id' => $ticket->participant_id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => $status,
    ]);
    DeliverTelegramMessage::dispatch($message->id);
    DeliverTelegramMessage::dispatch($message->id);
    $input = new InputStream;
    $first = telegramDeliveryWorker('hold', $input);

    try {
        $first->start();
        expect($first->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'sending-message')))->toBeTrue();
        $second = telegramDeliveryWorker();
        $second->mustRun();

        expect($second->getOutput())->not->toContain('sending-message');
        expect($message->refresh()->delivery_attempts)->toBe(1);
        expect($message->delivery_status)->toBe($status);
        $this->assertDatabaseCount('cache_locks', 1);
        $input->write("continue\n");
        $input->close();
        expect($first->wait())->toBe(0);

        expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
        expect($ticket->refresh()->status)->toBe(TicketStatus::WaitingForUser);
        $this->assertDatabaseCount('cache_locks', 0);
        makeTelegramDeliveryAvailable();
        $retry = telegramDeliveryWorker();
        $retry->mustRun();
        expect($retry->getOutput())->not->toContain('sending-message');
        expect($message->refresh()->delivery_attempts)->toBe(1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('failed_jobs', 0);
    } finally {
        $input->close();
        $first->stop(0, 9);
    }
})->with(['pending' => [DeliveryStatus::Pending], 'manual retry' => [DeliveryStatus::Failed]]);

test('cancellation cannot overlap a worker sending an unfinished operator reply', function (DeliveryStatus $status) {
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->for($ticket)->create([
        'participant_id' => $ticket->participant_id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => $status,
    ]);
    DeliverTelegramMessage::dispatch($message->id);
    $input = new InputStream;
    $worker = telegramDeliveryWorker('hold', $input);

    try {
        $worker->start();
        expect($worker->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'sending-message')))->toBeTrue();
        expect(fn () => app(OperatorReplyService::class)->cancel($ticket, $message->id))
            ->toThrow(DomainException::class, 'Delivery is already in progress.');
        expect($message->refresh()->delivery_status)->toBe($status);
        $input->write("continue\n");
        $input->close();
        expect($worker->wait())->toBe(0);
        expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
        expect(fn () => app(OperatorReplyService::class)->cancel($ticket, $message->id))->toThrow(DomainException::class);
        expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
        $this->assertDatabaseCount('cache_locks', 0);
    } finally {
        $input->close();
        $worker->stop(0, 9);
    }
})->with(['pending' => [DeliveryStatus::Pending], 'manual retry' => [DeliveryStatus::Failed]]);

test('a queued retry skips a reply cancelled before the worker starts', function () {
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->for($ticket)->create([
        'participant_id' => $ticket->participant_id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Failed,
    ]);
    DeliverTelegramMessage::dispatch($message->id);
    app(OperatorReplyService::class)->cancel($ticket, $message->id);

    $worker = telegramDeliveryWorker();
    $worker->mustRun();

    expect($worker->getOutput())->not->toContain('sending-message');
    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Cancelled);
    expect($message->delivery_attempts)->toBe(0);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
});

test('a worker marks an unexpected delivery failure after exhausting its attempts', function () {
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->for($ticket)->create([
        'participant_id' => $ticket->participant_id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    DeliverTelegramMessage::dispatch($message->id);

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        telegramDeliveryWorker('unexpected-failure')->mustRun();
        expect($message->refresh()->delivery_attempts)->toBe($attempt);
        makeTelegramDeliveryAvailable();
    }

    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    expect($message->last_delivery_error)->toBe('telegram_delivery_exhausted');
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 1);
    app(OperatorReplyService::class)->cancel($ticket, $message->id);
    app(TicketLifecycleService::class)->closeManually($ticket);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
});

test('a real worker marks delivery failed after the final timeout', function () {
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->for($ticket)->create([
        'participant_id' => $ticket->participant_id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    $job = new DeliverTelegramMessage($message->id);
    $job->timeout = 1;
    dispatch($job);

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $worker = telegramDeliveryWorker('timeout');
        expect(fn () => $worker->run())->toThrow(ProcessSignaledException::class);
        expect($worker->getTermSignal())->toBe(9);
        expect($worker->getOutput())->toContain('sending-message');
        expect($message->refresh()->delivery_attempts)->toBe($attempt);
        DB::table('cache_locks')->update(['expiration' => now()->subSecond()->getTimestamp()]);
        DB::table('jobs')->whereNotNull('reserved_at')->update(['reserved_at' => now()->subSeconds((int) config('queue.connections.database.retry_after') + 1)->getTimestamp()]);
    }

    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    expect($message->last_delivery_error)->toBe('telegram_delivery_timeout');
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 1);
    app(OperatorReplyService::class)->cancel($ticket, $message->id);
    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Cancelled);
});

test('a worker marks delivery failed after exhausting attempts to persist the result', function () {
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->for($ticket)->create([
        'participant_id' => $ticket->participant_id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    DeliverTelegramMessage::dispatch($message->id);
    DB::statement("ALTER TABLE messages ADD CONSTRAINT reject_sent CHECK (delivery_status IS DISTINCT FROM 'sent')");

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $worker = telegramDeliveryWorker();
        $worker->mustRun();
        expect($worker->getOutput())->toContain('sending-message');
        expect($message->refresh()->delivery_attempts)->toBe($attempt);
        makeTelegramDeliveryAvailable();
    }

    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    expect($message->last_delivery_error)->toBe('telegram_delivery_exhausted');
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    expect($ticket->first_operator_replied_at)->toBeNull();
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 1);
    DB::statement('ALTER TABLE messages DROP CONSTRAINT reject_sent');
    app(OperatorReplyService::class)->cancel($ticket, $message->id);
    app(TicketLifecycleService::class)->closeManually($ticket);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
});

test('an exhausted stale job preserves the terminal delivery status', function (DeliveryStatus $status) {
    $message = Message::factory()->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Bot,
        'delivery_status' => $status,
        'last_delivery_error' => null,
    ]);
    DeliverTelegramMessage::dispatch($message->id);
    $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['retryUntil'] = null;
    DB::table('jobs')->update(['attempts' => 3, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);

    $worker = telegramDeliveryWorker();
    $worker->mustRun();

    expect($worker->getOutput())->not->toContain('sending-message');
    expect($message->refresh()->delivery_status)->toBe($status);
    expect($message->last_delivery_error)->toBeNull();
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 1);
})->with(['sent' => [DeliveryStatus::Sent], 'cancelled' => [DeliveryStatus::Cancelled]]);

test('real worker database failures keep message bodies out of logs output and failed jobs', function () {
    $body = 'private_worker_question +7 (910) 123-45-67';
    $message = Message::factory()->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Bot,
        'delivery_status' => DeliveryStatus::Pending,
        'body' => $body,
    ]);
    DeliverTelegramMessage::dispatch($message->id);
    DB::statement("ALTER TABLE messages ADD CONSTRAINT reject_body_updates CHECK (body NOT LIKE '%reject-persistence%')");

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $worker = telegramDeliveryWorker('persistence-failure');
        $worker->setEnv(array_merge($worker->getEnv(), ['LOG_CHANNEL' => 'stderr']));
        $worker->mustRun();
        expect($worker->getErrorOutput())->not->toContain('private_worker_question', '+7 (910) 123-45-67', 'update "messages"', 'Failing row contains')
            ->toContain('database_query_failed', 'SQLSTATE 23514');
        expect($worker->getOutput())->not->toContain('private_worker_question', '+7 (910) 123-45-67');
        expect($message->refresh()->delivery_attempts)->toBe($attempt);
        makeTelegramDeliveryAvailable();
    }

    $failure = DB::table('failed_jobs')->sole();
    expect($failure->exception)->not->toContain('private_worker_question', '+7 (910) 123-45-67', 'update "messages"', 'Failing row contains')
        ->toContain('database_query_failed', 'SQLSTATE 23514', QueryException::class);
    expect($failure->payload)->not->toContain('private_worker_question', '+7 (910) 123-45-67');
    expect($message->refresh()->body)->toBe($body);
    expect($message->delivery_status)->toBe(DeliveryStatus::Failed);
    $this->assertDatabaseCount('jobs', 0);
    DB::statement('ALTER TABLE messages DROP CONSTRAINT reject_body_updates');
});

test('an operator can recover a pending reply after a killed worker leaves no job', function () {
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->for($ticket)->create([
        'participant_id' => $ticket->participant_id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    DeliverTelegramMessage::dispatch($message->id);
    $input = new InputStream;
    $worker = telegramDeliveryWorker('hold', $input);

    try {
        $worker->start();
        expect($worker->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'sending-message')))->toBeTrue();
        $worker->stop(0, 9);
        DB::table('jobs')->delete();
        expect(fn () => app(OperatorReplyService::class)->cancel($ticket, $message->id))->toThrow(DomainException::class, 'Delivery is already in progress.');
        DB::table('cache_locks')->update(['expiration' => now()->subSecond()->getTimestamp()]);

        app(OperatorReplyService::class)->cancel($ticket, $message->id);
        app(TicketLifecycleService::class)->closeManually($ticket);

        expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Cancelled);
        expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
    } finally {
        $input->close();
        $worker->stop(0, 9);
    }
});

test('delivery resumes after a killed worker lock expires', function () {
    $message = Message::factory()->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Bot,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    DeliverTelegramMessage::dispatch($message->id);
    DeliverTelegramMessage::dispatch($message->id);
    $input = new InputStream;
    $first = telegramDeliveryWorker('hold', $input);

    try {
        $first->start();
        expect($first->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'sending-message')))->toBeTrue();
        $first->stop(0, 9);
        $this->assertDatabaseCount('cache_locks', 1);
        $blocked = telegramDeliveryWorker();
        $blocked->mustRun();
        expect($blocked->getOutput())->not->toContain('sending-message');

        DB::table('cache_locks')->update(['expiration' => now()->subSecond()->getTimestamp()]);
        makeTelegramDeliveryAvailable();
        $recovery = telegramDeliveryWorker();
        $recovery->mustRun();

        expect($recovery->getOutput())->toContain('sending-message');
        expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
        expect($message->delivery_attempts)->toBe(2);
        $this->assertDatabaseCount('cache_locks', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    } finally {
        $input->close();
        $first->stop(0, 9);
    }
});

test('releases the lock after a transport failure so a queue retry can send', function () {
    $message = Message::factory()->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Bot,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    DeliverTelegramMessage::dispatch($message->id);

    telegramDeliveryWorker('temporary-failure')->mustRun();

    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    $this->assertDatabaseCount('cache_locks', 0);
    makeTelegramDeliveryAvailable();
    telegramDeliveryWorker()->mustRun();

    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($message->delivery_attempts)->toBe(2);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
});

function makeTelegramDeliveryAvailable(): void
{
    DB::table('jobs')->whereNull('reserved_at')->where('payload', 'like', '%DeliverTelegramMessage%')
        ->update(['available_at' => now()->getTimestamp()]);
}

test('real workers respect flood control across retries duplicates and manual retry jobs', function (MessageAuthor $author) {
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->for($ticket->participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Outbound,
        'author' => $author,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    DeliverTelegramMessage::dispatch($message->id);

    $before = now()->getTimestamp();
    telegramDeliveryWorker('flood-control')->mustRun();
    $after = now()->getTimestamp();
    $queued = DB::table('jobs')->where('queue', 'telegram')->sole();
    expect($queued->available_at)->toBeGreaterThanOrEqual($before + 60)->toBeLessThanOrEqual($after + 60);
    expect($message->refresh()->last_delivery_error)->toBe('telegram_rate_limited');
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $this->assertDatabaseCount('failed_jobs', 0);
    $this->assertDatabaseCount('cache_locks', 0);

    DeliverTelegramMessage::dispatch($message->id);
    $early = telegramDeliveryWorker();
    $early->mustRun();
    expect($early->getOutput())->not->toContain('sending-message');
    expect($message->refresh()->delivery_attempts)->toBe(1);
    $this->assertDatabaseCount('jobs', 2);

    for ($release = 0; $release < 4; $release++) {
        makeTelegramDeliveryAvailable();
        $early = telegramDeliveryWorker();
        $early->mustRun();
        expect($early->getOutput())->not->toContain('sending-message');
    }
    expect($message->refresh()->delivery_attempts)->toBe(1);
    $this->assertDatabaseCount('failed_jobs', 0);

    Cache::store('telegram_limits')->forget('telegram-delivery-retry:'.$message->id);
    makeTelegramDeliveryAvailable();
    telegramDeliveryWorker()->mustRun();
    telegramDeliveryWorker()->mustRun();
    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($message->delivery_attempts)->toBe(2);
    expect($ticket->refresh()->status)->toBe($author === MessageAuthor::Operator ? TicketStatus::WaitingForUser : TicketStatus::Open);
    expect(DB::table('jobs')->where('queue', 'telegram')->count())->toBe(0);
    expect(DB::table('jobs')->where('queue', 'maintenance')->count())->toBe($author === MessageAuthor::Operator ? 1 : 0);
    $this->assertDatabaseCount('failed_jobs', 0);
})->with(['operator' => [MessageAuthor::Operator], 'bot' => [MessageAuthor::Bot], 'system' => [MessageAuthor::System]]);

test('a worker terminates an undelivered message after its fixed retry deadline', function () {
    $message = Message::factory()->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::System,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    DeliverTelegramMessage::dispatch($message->id);
    $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['retryUntil'])->toBeGreaterThan(now()->addHours(23)->getTimestamp());
    $payload['retryUntil'] = now()->subSecond()->getTimestamp();
    DB::table('jobs')->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);

    $worker = telegramDeliveryWorker();
    $worker->mustRun();

    expect($worker->getOutput())->not->toContain('sending-message');
    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    expect($message->last_delivery_error)->toBe('telegram_delivery_exhausted');
    expect($message->delivery_attempts)->toBe(0);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 1);
});

function telegramDeliveryWorker(string $mode = 'success', ?InputStream $input = null, string $queue = 'telegram'): Process
{
    $code = <<<'PHP'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    config()->set('telegram.api_base_url', 'https://telegram.example');
    config()->set('telegram.bot_token', 'test-token');
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake([
        'https://telegram.example/bottest-token/sendMessage' => function () use ($argv) {
            echo "sending-message\n";
            flush();
            if (in_array($argv[1], ['hold', 'hold-failure'], true)) {
                fgets(STDIN);
            }
            if ($argv[1] === 'unexpected-failure') {
                throw new RuntimeException('Unexpected transport error.');
            }
            if ($argv[1] === 'timeout') {
                sleep(5);
            }
            if ($argv[1] === 'persistence-failure') {
                $message = App\Models\Message::query()->sole();
                $message->update(['body' => $message->body.' reject-persistence']);
            }
            if ($argv[1] === 'flood-control') {
                return Illuminate\Support\Facades\Http::response([
                    'ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 60],
                ], 429);
            }
            return Illuminate\Support\Facades\Http::response(
                ['ok' => true, 'result' => ['message_id' => 789]],
                in_array($argv[1], ['temporary-failure', 'hold-failure'], true) ? 503 : 200,
            );
        },
    ]);
    $options = ['connection' => 'database', '--queue' => $argv[2], '--sleep' => 0, '--tries' => 3];
    if ($argv[1] === 'persistence-failure') {
        $options['--json'] = true;
    }
    $options[$argv[1] === 'timeout' ? '--max-jobs' : '--once'] = $argv[1] === 'timeout' ? 1 : true;
    exit($kernel->call('queue:work', $options));
    PHP;

    return new Process([PHP_BINARY, '-r', $code, $mode, $queue], base_path(), telegramTestWorkerEnvironment(), $input, 10);
}

/** @return array<string, string> */
function telegramTestWorkerEnvironment(): array
{
    return [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => config('database.connections.pgsql.host'),
        'DB_PORT' => (string) config('database.connections.pgsql.port'),
        'DB_DATABASE' => 'tg_promo_test',
        'DB_USERNAME' => config('database.connections.pgsql.username'),
        'DB_PASSWORD' => config('database.connections.pgsql.password'),
        'DB_URL' => '',
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'database',
    ];
}

function telegramWorkerQueue(string $service, bool $development): string
{
    $command = $development
        ? explode(' ', collect(DevCommands::commands())->firstWhere('name', $service)['command'])
        : Yaml::parseFile(base_path('compose.yaml'))['services'][$service]['command'];

    foreach ($command as $argument) {
        if (str_starts_with($argument, '--queue=')) {
            return substr($argument, strlen('--queue='));
        }
    }

    throw new RuntimeException('Worker must declare its queue.');
}

function telegramCapacityWorker(string $mode, string $argument, ?InputStream $input = null): Process
{
    $code = <<<'PHP'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    Illuminate\Support\Facades\Http::preventStrayRequests();
    if ($argv[1] === 'ingest') {
        config()->set('llm.requests_per_minute', 1);
        config()->set('llm.requests_per_day', 10);
        echo "ingesting\n";
        flush();
        $app->make(App\Services\TelegramIngestionService::class)->ingest(new App\Data\TelegramUpdateData(
            (int) $argv[2], App\Enums\TelegramUpdateKind::Message, 8001, 8001, (int) $argv[2], 'Вопрос',
        ));
        exit(0);
    }
    config()->set('llm.endpoint', 'https://llm.example/v1/chat/completions');
    Illuminate\Support\Facades\Http::fake([
        'https://llm.example/v1/chat/completions' => function () {
            echo "llm-started\n";
            flush();
            fgets(STDIN);
            return Illuminate\Support\Facades\Http::response(['choices' => [['message' => ['content' => json_encode([
                'decision' => 'escalate', 'reason' => 'participant_specific', 'answer' => null, 'evidence' => [],
            ], JSON_THROW_ON_ERROR)]]]]);
        },
    ]);
    exit($kernel->call('queue:work', ['connection' => 'database', '--queue' => $argv[2], '--once' => true, '--sleep' => 0]));
    PHP;

    return new Process([PHP_BINARY, '-r', $code, $mode, $argument], base_path(), telegramTestWorkerEnvironment(), $input, 10);
}
