<?php

use App\Data\TelegramOutboundMessage;
use App\Data\TelegramSentMessage;
use App\Data\TelegramUpdateData;
use App\Data\ValidatedSupportDecision;
use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\SupportDecisionType;
use App\Enums\TelegramUpdateKind;
use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Exceptions\TelegramDeliveryException;
use App\Jobs\AutoCloseTicket;
use App\Jobs\DeliverTelegramMessage;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OperatorReplyService;
use App\Services\SupportDecisionService;
use App\Services\TelegramBotApiClient;
use App\Services\TelegramBotClient;
use App\Services\TelegramIngestionService;
use App\Services\TelegramMessagePresentation;
use App\Services\TicketLifecycleService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;

uses(LazilyRefreshDatabase::class);

test('ordinary replies can be queued and delivered repeatedly without resolving the ticket', function () {
    Queue::fake();
    $ticket = Ticket::factory()->create();
    $operator = User::factory()->create();
    $replies = app(OperatorReplyService::class);
    $first = $replies->create($operator, $ticket, 'Сейчас проверю');
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->times(3)->andReturn(new TelegramSentMessage(789));

    deliver($first, $client);
    $second = $replies->create($operator, $ticket, 'Проверка продолжается');
    deliver($second, $client);
    $third = $replies->create($operator, $ticket, 'Дополнительная информация');
    deliver($third, $client);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->resolved_since)->toBeNull();
    expect($ticket->messages()->where('delivery_status', DeliveryStatus::Sent)->count())->toBe(3);
    Queue::assertPushed(DeliverTelegramMessage::class, 3);
    Queue::assertNotPushed(AutoCloseTicket::class);
});

test('a failed reply blocks the next answer until the same reply is retried and sent', function () {
    Queue::fake();
    $ticket = Ticket::factory()->create();
    $operator = User::factory()->create();
    $replies = app(OperatorReplyService::class);
    $first = $replies->create($operator, $ticket, 'Первый ответ');
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->ordered()->andThrow(new TelegramDeliveryException('telegram_request_rejected', false));
    $client->shouldReceive('sendMessage')->twice()->ordered()->andReturn(new TelegramSentMessage(789));
    deliver($first, $client);

    expect(fn () => $replies->create($operator, $ticket, 'Продолжаем проверку'))->toThrow(ValidationException::class);
    $replies->retry($ticket, $first->id);
    expect($first->refresh()->delivery_status)->toBe(DeliveryStatus::Pending);
    deliver($first, $client);
    $second = $replies->create($operator, $ticket, 'Продолжаем проверку');
    deliver($second, $client);

    expect($first->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($second->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    Queue::assertPushed(DeliverTelegramMessage::class, 3);
    Queue::assertNotPushed(AutoCloseTicket::class);
});

test('an operator can continue a resolved conversation and invalidate its timer before delivery', function () {
    Queue::fake();
    $this->freezeTime();
    $ticket = Ticket::factory()->create();
    $operator = User::factory()->create();
    $replies = app(OperatorReplyService::class);
    $first = $replies->create($operator, $ticket, 'Решение');
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->twice()->andReturn(new TelegramSentMessage(789));
    deliver($first, $client);
    app(TicketLifecycleService::class)->resolve($ticket);
    $timer = Queue::pushed(AutoCloseTicket::class)->sole();

    $second = $replies->create($operator, $ticket, 'Ещё одно уточнение');
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $this->travel(24)->hours();
    $timer->handle(app(TicketLifecycleService::class));
    deliver($second, $client);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->resolved_since)->toBeNull()
        ->and($ticket->close_reason)->toBeNull();
    Queue::assertPushed(AutoCloseTicket::class, 1);
});

test('another operator reply is rejected while the first reply is being delivered', function () {
    Queue::fake();
    $ticket = Ticket::factory()->create();
    $operator = User::factory()->create();
    $first = app(OperatorReplyService::class)->create($operator, $ticket, 'Решение');
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andReturnUsing(function () use ($ticket, $operator): TelegramSentMessage {
        expect(fn () => app(OperatorReplyService::class)->create($operator, $ticket, 'Продолжение'))->toThrow(ValidationException::class);

        return new TelegramSentMessage(789);
    });

    deliver($first, $client);

    expect($first->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
    Queue::assertNotPushed(AutoCloseTicket::class);
});

test('closing during HTTP delivery keeps the ticket terminal after the operator reply succeeds', function () {
    Queue::fake();
    $ticket = Ticket::factory()->create();
    $reply = app(OperatorReplyService::class)->create(User::factory()->create(), $ticket, 'Решение');
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andReturnUsing(function () use ($ticket): TelegramSentMessage {
        app(TicketLifecycleService::class)->closeManually($ticket);

        return new TelegramSentMessage(789);
    });

    deliver($reply, $client);

    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->close_reason)->toBe(TicketCloseReason::OperatorClosed);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
    Queue::assertNotPushed(AutoCloseTicket::class);
});

test('never sends a legacy oversized operator reply or resolves the ticket', function () {
    Queue::fake();
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->for($ticket)->create([
        'participant_id' => $ticket->participant_id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'body' => str_repeat('я', TelegramOutboundMessage::MaxTextLength),
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldNotReceive('sendMessage');

    deliver($message, $client);

    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($message->last_delivery_error)->toBe('telegram_message_too_long')
        ->and($message->delivery_attempts)->toBe(1)
        ->and($ticket->refresh()->status->value)->toBe('open');
    Queue::assertNothingPushed();
});

test('marks a pending message sent only after Telegram accepts it', function () {
    $message = pendingOutboundMessage();
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andReturn(new TelegramSentMessage(789));

    deliver($message, $client);

    $message->refresh();

    expect($message->delivery_status)->toBe(DeliveryStatus::Sent)
        ->and($message->telegram_message_id)->toBe(789)
        ->and($message->delivered_at)->not->toBeNull()
        ->and($message->delivery_attempts)->toBe(1)
        ->and($message->last_delivery_error)->toBeNull();
});

test('keeps a rejected message for retry with a safe failure state', function () {
    $message = pendingOutboundMessage();
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andThrow(new TelegramDeliveryException('telegram_request_rejected', false));

    deliver($message, $client);

    $message->refresh();

    expect($message->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($message->delivery_attempts)->toBe(1)
        ->and($message->last_delivery_error)->toBe('telegram_request_rejected');
});

test('rethrows a temporary failure so the queue retries the same message record', function () {
    $message = pendingOutboundMessage();
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andThrow(new TelegramDeliveryException('telegram_temporary_failure', true));

    expect(fn () => deliver($message, $client))->toThrow(TelegramDeliveryException::class);

    $message->refresh();

    expect($message->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($message->delivery_attempts)->toBe(1);
});

test('does not send an already sent message again', function () {
    $message = pendingOutboundMessage(['delivery_status' => DeliveryStatus::Sent, 'telegram_message_id' => 789, 'delivered_at' => now()]);
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldNotReceive('sendMessage');

    deliver($message, $client);

    expect($message->refresh()->delivery_attempts)->toBe(0);
});

test('cancels queued bot messages for a closed ticket even when the participant has a new active ticket', function (SupportDecisionType $type, ?string $answer) {
    Queue::fake([DeliverTelegramMessage::class]);
    $question = Message::factory()->create();
    app(SupportDecisionService::class)->apply($question, new ValidatedSupportDecision($type, 'participant_specific', $answer, []), 'rules-hash');
    $ticket = Ticket::query()->sole();
    $messages = $ticket->messages()->where('direction', MessageDirection::Outbound)->get();
    $lifecycle = app(TicketLifecycleService::class);
    $lifecycle->closeManually($ticket);
    $newTicket = $lifecycle->create($ticket->participant);
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldNotReceive('sendMessage');

    foreach ($messages as $message) {
        deliver($message, $client);
        deliver($message, $client);
    }

    foreach ($messages as $message) {
        expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Cancelled)
            ->and($message->delivery_attempts)->toBe(0)
            ->and($message->telegram_message_id)->toBeNull()
            ->and($message->delivered_at)->toBeNull();
    }
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
    expect($newTicket->refresh()->status)->toBe(TicketStatus::Open);
    Queue::assertPushed(DeliverTelegramMessage::class, $type === SupportDecisionType::Mixed ? 2 : 1);
})->with([
    'escalation' => [SupportDecisionType::Escalate, null],
    'mixed answer and escalation' => [SupportDecisionType::Mixed, 'Йогурты участвуют в акции.'],
]);

test('cancels an escalation retry after the ticket closes without losing the previous failure', function () {
    Queue::fake([DeliverTelegramMessage::class]);
    $question = Message::factory()->create();
    app(SupportDecisionService::class)->failSafeEscalate($question, 'rules-hash');
    $ticket = Ticket::query()->sole();
    $notice = $ticket->messages()->where('direction', MessageDirection::Outbound)->sole();
    $failedClient = Mockery::mock(TelegramBotClient::class);
    $failedClient->shouldReceive('sendMessage')->once()->andThrow(new TelegramDeliveryException('telegram_temporary_failure', true));
    expect(fn () => deliver($notice, $failedClient))->toThrow(TelegramDeliveryException::class);
    app(TicketLifecycleService::class)->closeManually($ticket);
    $retryClient = Mockery::mock(TelegramBotClient::class);
    $retryClient->shouldNotReceive('sendMessage');

    deliver($notice, $retryClient);

    expect($notice->refresh()->delivery_status)->toBe(DeliveryStatus::Cancelled)
        ->and($notice->delivery_attempts)->toBe(1)
        ->and($notice->last_delivery_error)->toBe('telegram_temporary_failure')
        ->and($notice->telegram_message_id)->toBeNull()
        ->and($notice->delivered_at)->toBeNull();
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
});

test('cancels a stale escalation during flood control without releasing another retry', function () {
    $this->freezeTime();
    Queue::fake([DeliverTelegramMessage::class]);
    $question = Message::factory()->create();
    app(SupportDecisionService::class)->failSafeEscalate($question, 'rules-hash');
    $ticket = Ticket::query()->sole();
    $notice = $ticket->messages()->where('direction', MessageDirection::Outbound)->sole();
    config()->set('telegram.api_base_url', 'https://telegram.example');
    config()->set('telegram.bot_token', 'test-token');
    Http::preventStrayRequests();
    Http::fake(['https://telegram.example/bottest-token/sendMessage' => Http::response(['ok' => false, 'parameters' => ['retry_after' => 60]], 429)]);
    $job = (new DeliverTelegramMessage($notice->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    $job->assertReleased(60)->assertNotFailed();
    app(TicketLifecycleService::class)->closeManually($ticket);
    $retry = (new DeliverTelegramMessage($notice->id))->withFakeQueueInteractions();

    app()->call([$retry, 'handle']);

    $retry->assertNotReleased()->assertNotFailed();
    expect($notice->refresh()->delivery_status)->toBe(DeliveryStatus::Cancelled)
        ->and($notice->delivery_attempts)->toBe(1)
        ->and($notice->last_delivery_error)->toBe('telegram_rate_limited')
        ->and($notice->delivered_at)->toBeNull();
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
    Http::assertSentCount(1);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
});

test('still delivers an escalation notice while the ticket remains active', function (TicketStatus $status) {
    Queue::fake([DeliverTelegramMessage::class]);
    $question = Message::factory()->create();
    app(SupportDecisionService::class)->failSafeEscalate($question, 'rules-hash');
    $ticket = Ticket::query()->sole();
    if ($status === TicketStatus::Resolved) {
        app(TicketLifecycleService::class)->resolve($ticket);
    }
    $notice = $ticket->messages()->where('direction', MessageDirection::Outbound)->sole();
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andReturn(new TelegramSentMessage(789));

    deliver($notice, $client);

    expect($notice->refresh()->delivery_status)->toBe(DeliveryStatus::Sent)
        ->and($notice->telegram_message_id)->toBe(789)
        ->and($notice->delivered_at)->not->toBeNull();
    expect($ticket->refresh()->status)->toBe($status);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
})->with(['open' => [TicketStatus::Open], 'resolved' => [TicketStatus::Resolved]]);

test('delivering a reply after explicit resolution does not restart the auto close timer', function () {
    Queue::fake();
    $this->freezeTime();
    $ticket = Ticket::factory()->create();
    $reply = app(OperatorReplyService::class)->create(User::factory()->create(), $ticket, 'Ответ');
    app(TicketLifecycleService::class)->resolve($ticket);
    $resolvedSince = $ticket->refresh()->resolved_since->toISOString();
    $this->travel(1)->hours();
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andReturn(new TelegramSentMessage(789));

    deliver($reply, $client);

    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved);
    expect($ticket->resolved_since->toISOString())->toBe($resolvedSince);
    Queue::assertPushed(AutoCloseTicket::class, 1);
});

test('keeps an open ticket open when delivery of an operator reply fails', function () {
    Queue::fake();
    $participant = TelegramParticipant::factory()->create();
    $ticket = Ticket::factory()->for($participant, 'participant')->create();
    $message = Message::factory()->for($participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andThrow(new TelegramDeliveryException('telegram_request_rejected', false));

    deliver($message, $client);

    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($ticket->refresh()->status->value)->toBe('open');
    Queue::assertNothingPushed();
});

test('waits until the exact flood control deadline before retrying', function () {
    $this->freezeTime();
    $message = pendingOutboundMessage();
    config()->set('telegram.api_base_url', 'https://telegram.example');
    config()->set('telegram.bot_token', 'test-token');
    Http::preventStrayRequests();
    Http::fake(['https://telegram.example/bottest-token/sendMessage' => Http::sequence()
        ->push(['ok' => false, 'parameters' => ['retry_after' => 60]], 429)
        ->push(['ok' => true, 'result' => ['message_id' => 789]])]);
    $handle = function (DeliverTelegramMessage $job): void {
        $job->handle(app(TelegramBotApiClient::class), app(TelegramMessagePresentation::class), app(TicketLifecycleService::class));
    };

    $job = (new DeliverTelegramMessage($message->id))->withFakeQueueInteractions();
    $handle($job);
    $job->assertReleased(60)->assertNotFailed();
    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);

    $this->travel(59)->seconds();
    $early = (new DeliverTelegramMessage($message->id))->withFakeQueueInteractions();
    $handle($early);
    $early->assertReleased(1)->assertNotFailed();
    expect($message->refresh()->delivery_attempts)->toBe(1);
    Http::assertSentCount(1);

    $this->travel(1)->seconds();
    $ready = (new DeliverTelegramMessage($message->id))->withFakeQueueInteractions();
    $handle($ready);
    $ready->assertNotReleased()->assertNotFailed();
    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($message->delivery_attempts)->toBe(2);
    Http::assertSentCount(2);
});

test('fails safely when flood control exceeds the delivery retry window', function () {
    $message = pendingOutboundMessage();
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andThrow(new TelegramDeliveryException('telegram_rate_limited', true, PHP_INT_MAX));
    $job = (new DeliverTelegramMessage($message->id))->withFakeQueueInteractions();

    $job->handle($client, app(TelegramMessagePresentation::class), app(TicketLifecycleService::class));

    $job->assertFailedWith(TelegramDeliveryException::class)->assertNotReleased();
    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
});

function pendingOutboundMessage(array $attributes = []): Message
{
    $participant = TelegramParticipant::factory()->create();

    return Message::factory()->for($participant, 'participant')->create(array_merge([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Bot,
        'body' => 'Сообщение участнику.',
        'delivery_status' => DeliveryStatus::Pending,
    ], $attributes));
}

function deliver(Message $message, TelegramBotClient $client): void
{
    (new DeliverTelegramMessage($message->id))->handle(
        $client,
        app(TelegramMessagePresentation::class),
        app(TicketLifecycleService::class),
    );
}

test('a message between Telegram rejection and retry keeps the delivered reply ticket open', function (string $text) {
    Queue::fake();
    $ticket = Ticket::factory()->create();
    $participant = $ticket->participant;
    $reply = Message::factory()->for($ticket)->create([
        'participant_id' => $participant->id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->ordered()->andThrow(new TelegramDeliveryException('telegram_request_rejected', false));
    $client->shouldReceive('sendMessage')->once()->ordered()->andReturn(new TelegramSentMessage(790));
    deliver($reply, $client);
    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    app(TelegramIngestionService::class)->ingest(new TelegramUpdateData(11005, TelegramUpdateKind::Message, $participant->telegram_user_id, $participant->chat_id, 11005, $text));

    deliver($reply, $client);

    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Sent)
        ->and($ticket->refresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->resolved_since)->toBeNull()
        ->and($ticket->close_reason)->toBeNull();
    expect(Message::query()->where('direction', MessageDirection::Inbound)->sole()->body)->toBe($text);
    $this->assertDatabaseCount('messages', 2);
    Queue::assertNothingPushed();
})->with(['У меня ещё вопрос', 'Проблема решена']);

test('late AI attachment during Telegram delivery keeps the ticket open even when input was created before the reply', function () {
    Queue::fake();
    $participant = TelegramParticipant::factory()->create();
    $early = Message::factory()->for($participant, 'participant')->create(['ticket_id' => null]);
    $current = Message::factory()->for($participant, 'participant')->create(['ticket_id' => null]);
    $decisions = app(SupportDecisionService::class);
    $decisions->apply($current, new ValidatedSupportDecision(SupportDecisionType::Escalate, 'participant_specific', null, []), 'rules-hash');
    $ticket = Ticket::query()->sole();
    $reply = app(OperatorReplyService::class)->create(User::factory()->create(), $ticket, 'Ответ на текущий вопрос');
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andReturnUsing(function () use ($early, $ticket, $decisions): TelegramSentMessage {
        expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
        $decisions->apply($early, new ValidatedSupportDecision(SupportDecisionType::Answer, 'rule_answer', 'Устаревший ответ', []), 'rules-hash');

        return new TelegramSentMessage(791);
    });

    deliver($reply, $client);

    expect($early->refresh()->ticket_id)->toBe($ticket->id);
    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    expect($ticket->resolved_since)->toBeNull();
    expect($early->decision)->toBeNull();
    expect(Message::query()->where('body', 'Устаревший ответ')->exists())->toBeFalse();
    Queue::assertNotPushed(AutoCloseTicket::class);
    Queue::assertPushed(DeliverTelegramMessage::class, 2);
});

test('operator delivery cannot resolve a ticket with or without a new participant message', function (bool $newInput) {
    Queue::fake();
    $participant = TelegramParticipant::factory()->create();
    $question = Message::factory()->for($participant, 'participant')->create(['ticket_id' => null]);
    app(SupportDecisionService::class)->apply($question, new ValidatedSupportDecision(SupportDecisionType::Escalate, 'participant_specific', null, []), 'rules-hash');
    $ticket = Ticket::query()->sole();
    $reply = app(OperatorReplyService::class)->create(User::factory()->create(), $ticket, 'Ответ оператора');
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andReturnUsing(function () use ($newInput, $participant, $ticket): TelegramSentMessage {
        expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
        if ($newInput) {
            app(TelegramIngestionService::class)->ingest(new TelegramUpdateData(11006, TelegramUpdateKind::Message, $participant->telegram_user_id, $participant->chat_id, 11006, 'Не решило'));
        }

        return new TelegramSentMessage(792);
    });

    deliver($reply, $client);

    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($ticket->refresh()->first_operator_replied_at?->toDateTimeString())->toBe($reply->delivered_at?->toDateTimeString());
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    expect($ticket->close_reason)->toBeNull();
    Queue::assertNotPushed(AutoCloseTicket::class);
    Queue::assertPushed(DeliverTelegramMessage::class, 2);
})->with(['no new message' => false, 'new message' => true]);

test('delivers a pending operator reply after the ticket closes before the worker starts', function (bool $automatically) {
    Queue::fake();
    $this->freezeTime();
    $ticket = Ticket::factory()->create();
    $reply = app(OperatorReplyService::class)->create(User::factory()->create(), $ticket, 'Ответ до закрытия');
    $lifecycle = app(TicketLifecycleService::class);
    if ($automatically) {
        $lifecycle->resolve($ticket);
        $this->travel(24)->hours();
        Queue::pushed(AutoCloseTicket::class)->sole()->handle($lifecycle);
    } else {
        $lifecycle->closeManually($ticket);
    }
    $closedAt = $ticket->refresh()->closed_at;
    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Pending);
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andReturn(new TelegramSentMessage(789));

    deliver($reply, $client);
    deliver($reply, $client);

    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->closed_at)->toEqual($closedAt)
        ->and($ticket->first_operator_replied_at)->toEqual($reply->delivered_at);
    Queue::assertPushed(AutoCloseTicket::class, $automatically ? 1 : 0);
})->with(['manual close' => false, 'auto close' => true]);

test('legacy queued operator replies are delivered in order even after closure', function () {
    $ticket = Ticket::factory()->closed()->create();
    $replies = Message::factory()->count(2)->for($ticket->participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->twice()->andReturn(new TelegramSentMessage(789));
    $later = (new DeliverTelegramMessage($replies->last()->id))->withFakeQueueInteractions();

    $later->handle($client, app(TelegramMessagePresentation::class));
    $later->assertReleased(5);
    expect($replies->last()->refresh()->delivery_attempts)->toBe(0);
    deliver($replies->first(), $client);
    deliver($replies->last(), $client);

    expect($replies->map(fn (Message $reply) => $reply->refresh()->delivery_status)->all())->toBe([DeliveryStatus::Sent, DeliveryStatus::Sent]);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
});
