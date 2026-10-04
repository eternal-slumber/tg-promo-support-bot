<?php

use App\Data\TelegramSentMessage;
use App\Data\TelegramUpdateData;
use App\Data\ValidatedSupportDecision;
use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\SupportDecisionType;
use App\Enums\TelegramUpdateKind;
use App\Enums\TicketStatus;
use App\Jobs\AutoCloseTicket;
use App\Jobs\DeliverTelegramMessage;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OperatorReplyService;
use App\Services\SupportDecisionService;
use App\Services\TelegramBotClient;
use App\Services\TelegramIngestionService;
use App\Services\TelegramMessagePresentation;
use App\Services\TicketLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config()->set('queue.default', 'database');
    Http::preventStrayRequests();
});

test('stores incoming work in the message transaction without duplicate jobs on webhook retry', function () {
    config()->set('queue.default', 'sync');
    config()->set('queue.connections.database.after_commit', true);
    $update = new TelegramUpdateData(1001, TelegramUpdateKind::Message, 2001, 2001, 3001, 'Вопрос');

    $result = app(TelegramIngestionService::class)->ingest($update);
    $retry = app(TelegramIngestionService::class)->ingest($update);

    $this->assertDatabaseCount('messages', 1);
    $this->assertDatabaseCount('jobs', 1);
    $job = unserialize(json_decode(DB::table('jobs')->sole()->payload, true, 512, JSON_THROW_ON_ERROR)['data']['command']);
    expect($job)->toBeInstanceOf(ProcessIncomingMessage::class);
    expect($job->messageId)->toBe($result->messageId);
    expect($retry->duplicate)->toBeTrue();
});

test('rolls back persisted work along with an outer application transaction', function () {
    $update = new TelegramUpdateData(1003, TelegramUpdateKind::Message, 2003, 2003, 3003, 'Вопрос');

    expect(fn () => DB::transaction(function () use ($update): void {
        app(TelegramIngestionService::class)->ingest($update);
        $this->assertDatabaseCount('jobs', 1);

        throw new RuntimeException('Abort transaction before commit.');
    }))->toThrow(RuntimeException::class, 'Abort transaction before commit.');

    $this->assertDatabaseCount('telegram_updates', 0);
    $this->assertDatabaseCount('telegram_participants', 0);
    $this->assertDatabaseCount('messages', 0);
    $this->assertDatabaseCount('jobs', 0);
});

test('rolls back incoming data when the queue insert fails and accepts a webhook retry', function (TelegramUpdateKind $kind) {
    DB::statement('ALTER TABLE jobs ADD CONSTRAINT reject_job_inserts CHECK (false)');
    $update = new TelegramUpdateData(1002, $kind, 2002, 2002, 3002, $kind === TelegramUpdateKind::Message ? 'Вопрос' : null);

    expect(fn () => app(TelegramIngestionService::class)->ingest($update))->toThrow(QueryException::class);

    $this->assertDatabaseCount('telegram_updates', 0);
    $this->assertDatabaseCount('telegram_participants', 0);
    $this->assertDatabaseCount('messages', 0);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('cache', 0);
    DB::statement('ALTER TABLE jobs DROP CONSTRAINT reject_job_inserts');

    expect(app(TelegramIngestionService::class)->ingest($update)->duplicate)->toBeFalse();
    $this->assertDatabaseCount('messages', 1);
    $this->assertDatabaseCount('jobs', 1);
})->with(['text message' => [TelegramUpdateKind::Message], 'non-text fallback' => [TelegramUpdateKind::NonTextMessage]]);

test('rolls back an operator reply when delivery cannot be queued', function () {
    $operator = User::factory()->create();
    $ticket = Ticket::factory()->resolved()->create();
    DB::statement('ALTER TABLE jobs ADD CONSTRAINT reject_job_inserts CHECK (false)');

    expect(fn () => app(OperatorReplyService::class)->create($operator, $ticket, 'Ответ'))->toThrow(QueryException::class);

    $this->assertDatabaseCount('messages', 0);
    $this->assertDatabaseCount('jobs', 0);
    expect($ticket->refresh()->first_operator_replied_at)->toBeNull();
    expect($ticket->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->resolved_since)->not->toBeNull();
});

test('rolls back a support decision when its outbound message cannot be queued', function () {
    $message = Message::factory()->create(['direction' => MessageDirection::Inbound, 'author' => MessageAuthor::Participant]);
    DB::statement('ALTER TABLE jobs ADD CONSTRAINT reject_job_inserts CHECK (false)');

    expect(fn () => app(SupportDecisionService::class)->apply(
        $message,
        new ValidatedSupportDecision(SupportDecisionType::Escalate, 'llm_failure', null, []),
        'rules-hash',
    ))->toThrow(QueryException::class);

    $this->assertDatabaseCount('support_decisions', 0);
    $this->assertDatabaseCount('tickets', 0);
    $this->assertDatabaseCount('messages', 1);
    $this->assertDatabaseCount('jobs', 0);
    expect($message->refresh()->ticket_id)->toBeNull();
});

test('rolls back participant text and reopening together when inbound persistence fails', function () {
    $ticket = Ticket::factory()->resolved()->create();
    $reply = Message::factory()->for($ticket)->create([
        'participant_id' => $ticket->participant_id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Sent,
        'delivered_at' => now(),
    ]);
    $resolvedSince = $ticket->resolved_since->toISOString();
    DB::statement("ALTER TABLE messages ADD CONSTRAINT reject_feedback CHECK (direction <> 'inbound')");
    $update = new TelegramUpdateData(1030, TelegramUpdateKind::Message, $ticket->participant->telegram_user_id, $ticket->participant->chat_id, 3030, 'Не решило');

    expect(fn () => app(TelegramIngestionService::class)->ingest($update))
        ->toThrow(QueryException::class);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved);
    expect($ticket->resolved_since->toISOString())->toBe($resolvedSince);
    $this->assertDatabaseCount('messages', 1);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('telegram_updates', 0);
    DB::statement('ALTER TABLE messages DROP CONSTRAINT reject_feedback');

    expect(app(TelegramIngestionService::class)->ingest($update)->duplicate)->toBeFalse();
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $this->assertDatabaseCount('messages', 2);
    $this->assertDatabaseCount('jobs', 0);
});

test('persists delayed auto close alongside explicit resolution and makes it available only after the timeout', function () {
    $this->travelTo(Carbon::parse('2026-10-02 12:00:00 UTC'));
    config()->set('support.ticket_auto_close_hours', 24);
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->for($ticket)->create([
        'participant_id' => $ticket->participant_id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andReturn(new TelegramSentMessage(789));

    (new DeliverTelegramMessage($message->id))->handle($client, app(TelegramMessagePresentation::class), app(TicketLifecycleService::class));

    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    app(TicketLifecycleService::class)->resolve($ticket);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved);
    $record = DB::table('jobs')->sole();
    $job = unserialize(json_decode($record->payload, true, 512, JSON_THROW_ON_ERROR)['data']['command']);
    expect($job)->toBeInstanceOf(AutoCloseTicket::class);
    expect($job->ticketId)->toBe($ticket->id);
    expect($job->resolvedSince)->toBe($ticket->resolved_since->toISOString());
    expect($record->available_at)->toBe(1791028800);
    expect(Queue::connection('database')->pop('maintenance'))->toBeNull();
    $this->travel(24)->hours();
    $reserved = Queue::connection('database')->pop('maintenance');
    expect($reserved)->not->toBeNull();
    $reserved->fire();
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
});

test('rolls back explicit resolution when auto close cannot be queued without undoing delivery', function () {
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->for($ticket)->create([
        'participant_id' => $ticket->participant_id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andReturn(new TelegramSentMessage(789));
    (new DeliverTelegramMessage($message->id))->handle($client, app(TelegramMessagePresentation::class));
    DB::statement('ALTER TABLE jobs ADD CONSTRAINT reject_job_inserts CHECK (false)');

    expect(fn () => app(TicketLifecycleService::class)->resolve($ticket))->toThrow(QueryException::class);

    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($message->telegram_message_id)->toBe(789);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    expect($ticket->resolved_since)->toBeNull();
    $this->assertDatabaseCount('jobs', 0);
});

test('rolls back a failed operator reply retry when delivery cannot be queued', function () {
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->for($ticket->participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Outbound, 'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Failed,
    ]);
    DB::statement('ALTER TABLE jobs ADD CONSTRAINT reject_job_inserts CHECK (false)');

    expect(fn () => app(OperatorReplyService::class)->retry($ticket, $message->id))->toThrow(QueryException::class);

    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    $this->assertDatabaseCount('messages', 1);
    $this->assertDatabaseCount('jobs', 0);
});
