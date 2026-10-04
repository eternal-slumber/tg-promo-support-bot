<?php

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
use App\Livewire\OperatorDashboard;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OperatorReplyService;
use App\Services\SupportDecisionService;
use App\Services\TelegramIngestionService;
use App\Services\TicketLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
    Queue::fake();
    Http::preventStrayRequests();
    config()->set('telegram.bot_token', 'test-context-token');
    Http::fake(['*sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1701]])]);
});

test('shows the fixed pre-ticket dialogue separately without changing the main history or message ownership', function () {
    $participant = TelegramParticipant::factory()->create();
    $question = contextInbound($participant, 'Сколько шансов за 7 йогуртов?', 17001);
    $answer = contextAnswer($question, 'За 7 подходящих йогуртов — 3 шанса.');
    $followUp = contextInbound($participant, 'Не понял ответ, позовите оператора.', 17002);
    $ticket = contextEscalate($followUp);
    $notice = $ticket->messages()->where('author', MessageAuthor::Bot)->sole();
    Message::factory()->create(['body' => 'Чужая переписка.']);
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(OperatorDashboard::class)->call('selectTicket', $ticket->id)
        ->assertSee(['Контекст до обращения', $question->body, $answer->body, $followUp->body, "Обращение №{$ticket->id} создано"])
        ->assertDontSee('Чужая переписка.');

    expect($component->viewData('contextMessages')->pluck('id')->all())->toBe([$question->id, $answer->id, $followUp->id]);
    expect($component->viewData('messages')->pluck('id')->all())->toBe([$notice->id, $followUp->id]);
    expect($component->html())->toMatch('/Контекст до обращения.*Сколько шансов.*3 шанса.*Не понял.*Обращение №'.$ticket->id.' создано/s');
    expect($question->refresh()->ticket_id)->toBeNull();
    expect($answer->refresh()->ticket_id)->toBeNull();
    expect($followUp->refresh()->ticket_id)->toBe($ticket->id);
    expect($ticket->refresh()->context_message_ids)->toBe([$question->id, $answer->id, $followUp->id]);
});

test('the next context starts after closure even in the same second and excludes late answers to old questions', function () {
    $participant = TelegramParticipant::factory()->create();
    $questionA = contextInbound($participant, 'Вопрос до A.', 17101);
    $answerA = contextAnswer($questionA, 'Ответ до A.');
    $triggerA = contextInbound($participant, 'Оператор для A.', 17102);
    $ticketA = contextEscalate($triggerA);
    $operator = User::factory()->create();
    $replyA = app(OperatorReplyService::class)->create($operator, $ticketA, 'Ответ в A.');
    app(TicketLifecycleService::class)->closeManually($ticketA);
    app()->call([new DeliverTelegramMessage($replyA->id), 'handle']);
    $questionB = contextInbound($participant, 'Новый вопрос до B.', 17103);
    $answerB = contextAnswer($questionB, 'Новый ответ до B.');
    $lateAnswer = Message::factory()->for($participant, 'participant')->create([
        'source_message_id' => $questionA->id, 'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Bot, 'body' => 'Запоздалый ответ до A.',
        'delivery_status' => DeliveryStatus::Sent, 'delivered_at' => now(),
    ]);
    $triggerB = contextInbound($participant, 'Оператор для B.', 17104);
    $ticketB = contextEscalate($triggerB);
    app(TicketLifecycleService::class)->closeManually($ticketB);
    $ownership = Message::query()->orderBy('id')->pluck('ticket_id', 'id')->all();
    $this->actingAs($operator);

    $component = Livewire::test(OperatorDashboard::class)->call('selectFilter', 'closed')
        ->call('selectTicket', $ticketA->id)
        ->assertSee([$questionA->body, $answerA->body, $triggerA->body, $replyA->body, "Обращение №{$ticketA->id} закрыто оператором"])
        ->assertDontSee([$questionB->body, $answerB->body, $triggerB->body, $lateAnswer->body]);
    expect($component->viewData('messages')->pluck('ticket_id')->unique()->all())->toBe([$ticketA->id]);
    $component->call('selectTicket', $ticketB->id)->call('$refresh')
        ->assertSee([$questionB->body, $answerB->body, $triggerB->body])
        ->assertDontSee([$questionA->body, $answerA->body, $triggerA->body, $replyA->body, $lateAnswer->body]);
    expect($component->viewData('contextMessages')->pluck('id')->all())->toBe([$questionB->id, $answerB->id, $triggerB->id]);
    expect($component->viewData('messages')->pluck('ticket_id')->unique()->all())->toBe([$ticketB->id]);
    expect(Message::query()->orderBy('id')->pluck('ticket_id', 'id')->all())->toBe($ownership);
    expect($ticketA->refresh()->status)->toBe(TicketStatus::Closed);
    expect($replyA->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
});

test('context membership does not grow after creation or later delivery and excludes messages after the escalation source', function () {
    $participant = TelegramParticipant::factory()->create();
    $question = contextInbound($participant, 'Первый вопрос.', 17201);
    app(SupportDecisionService::class)->apply($question, new ValidatedSupportDecision(SupportDecisionType::Answer, 'rule_answer', 'Ещё не доставленный ответ.', []), 'rules-hash');
    $pending = Message::query()->where('source_message_id', $question->id)->sole();
    $trigger = contextInbound($participant, 'Позовите оператора.', 17202);
    $laterQuestion = contextInbound($participant, 'Сообщение после просьбы.', 17203);
    $ticket = contextEscalate($trigger);
    $ids = $ticket->context_message_ids;
    app()->call([new DeliverTelegramMessage($pending->id), 'handle']);
    contextInbound($participant, 'Сообщение в ticket.', 17204);
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(OperatorDashboard::class)->call('selectTicket', $ticket->id)->call('$refresh');

    expect($ids)->toBe([$question->id, $trigger->id]);
    expect($component->viewData('contextMessages')->pluck('id')->all())->toBe($ids);
    expect($ticket->refresh()->context_message_ids)->toBe($ids);
    expect($pending->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($laterQuestion->refresh()->ticket_id)->toBeNull();
});

test('context uses sanitized escaped message bodies and excludes operator system and undelivered bot messages', function () {
    $participant = TelegramParticipant::factory()->create();
    $question = contextInbound($participant, '<script>alert(1)</script> Номер карты: 4111 1111 1111 1111', 17301);
    Message::factory()->for($participant, 'participant')->create([
        'direction' => MessageDirection::Outbound, 'author' => MessageAuthor::System,
        'body' => 'Служебное уведомление.', 'delivery_status' => DeliveryStatus::Sent, 'delivered_at' => now(),
    ]);
    Message::factory()->for($participant, 'participant')->create([
        'direction' => MessageDirection::Outbound, 'author' => MessageAuthor::Operator,
        'body' => 'Ответ вне ticket.', 'delivery_status' => DeliveryStatus::Sent, 'delivered_at' => now(),
    ]);
    Message::factory()->count(3)->for($participant, 'participant')->sequence(
        ['delivery_status' => DeliveryStatus::Pending],
        ['delivery_status' => DeliveryStatus::Failed],
        ['delivery_status' => DeliveryStatus::Cancelled],
    )->create(['source_message_id' => $question->id, 'direction' => MessageDirection::Outbound, 'author' => MessageAuthor::Bot, 'body' => 'Не полученный ответ.']);
    $trigger = contextInbound($participant, 'Оператор.', 17302);
    $ticket = contextEscalate($trigger);
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)->call('selectTicket', $ticket->id)
        ->assertSee($question->body)
        ->assertDontSee(['4111 1111 1111 1111', 'Служебное уведомление.', 'Ответ вне ticket.', 'Не полученный ответ.'])
        ->assertDontSeeHtml('<script>alert(1)</script>');

    expect($question->sensitive_data_redacted)->toBeTrue();
    expect($ticket->context_message_ids)->toBe([$question->id, $trigger->id]);
});

test('all bounded context is saved and paginated independently of the ticket history', function () {
    $participant = TelegramParticipant::factory()->create();
    $questions = Message::factory()->count(60)->for($participant, 'participant')->create();
    $trigger = contextInbound($participant, 'Последняя просьба оператору.', 17401);
    $ticket = contextEscalate($trigger);
    $this->actingAs(User::factory()->create());
    $component = Livewire::test(OperatorDashboard::class)->call('selectTicket', $ticket->id);
    $mainIds = $component->viewData('messages')->pluck('id')->all();
    $context = $component->viewData('contextMessages');
    expect($ticket->context_message_ids)->toHaveCount(61);
    expect($context->pluck('id')->all())->toBe($questions->take(50)->pluck('id')->all());

    $component->call('setPage', $context->nextCursor()->encode(), 'contextCursor')->assertSee($trigger->body);

    expect($component->viewData('contextMessages')->pluck('id')->all())->toBe([...$questions->skip(50)->pluck('id')->all(), $trigger->id]);
    expect($component->viewData('messages')->pluck('id')->all())->toBe($mainIds);
    $otherTicket = Ticket::factory()->create();
    $component->call('selectTicket', $otherTicket->id);
    expect($component->viewData('contextMessages'))->toBeEmpty();
});

test('historical closure timestamps conservatively exclude the old unticketed dialogue', function () {
    $participant = TelegramParticipant::factory()->create();
    $oldQuestion = contextInbound($participant, 'Старый вопрос.', 17501);
    contextAnswer($oldQuestion, 'Старый ответ.');
    $previous = Ticket::factory()->for($participant, 'participant')->closed()->create();
    $this->travel(1)->seconds();
    $question = contextInbound($participant, 'Новый вопрос.', 17502);
    $answer = contextAnswer($question, 'Новый ответ.');
    $trigger = contextInbound($participant, 'Новый оператор.', 17503);

    $ticket = contextEscalate($trigger);

    expect($ticket->context_message_ids)->toBe([$question->id, $answer->id, $trigger->id]);
    expect($previous->refresh()->context_message_ids)->toBeNull();
});

test('closing sends exactly one numbered system notice without changing terminal status', function (bool $automatic, string $suffix) {
    $ticket = Ticket::factory()->resolved()->create();
    if ($automatic) {
        $this->travel((int) config('support.ticket_auto_close_hours'))->hours();
        $timer = new AutoCloseTicket($ticket->id, $ticket->resolved_since->toISOString());
        $timer->handle(app(TicketLifecycleService::class));
        $timer->handle(app(TicketLifecycleService::class));
    } else {
        app(TicketLifecycleService::class)->closeManually($ticket);
        expect(fn () => app(TicketLifecycleService::class)->closeManually($ticket))->toThrow(DomainException::class);
    }
    $notice = $ticket->messages()->where('ticket_event', Message::TicketClosedEvent)->sole();
    $this->actingAs(User::factory()->create());
    Livewire::test(OperatorDashboard::class)->call('selectFilter', 'closed')->call('selectTicket', $ticket->id)
        ->assertSee("Обращение №{$ticket->id} закрыто {$suffix}");
    app()->call([new DeliverTelegramMessage($notice->id), 'handle']);
    app()->call([new DeliverTelegramMessage($notice->id), 'handle']);

    expect($notice->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($notice->author)->toBe(MessageAuthor::System);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
    expect($ticket->first_operator_replied_at)->toBeNull();
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request['text'], "Обращение №{$ticket->id} закрыто {$suffix}")
        && str_contains($request['text'], 'новый вопрос'));
    Http::assertSentCount(1);
})->with(['manual' => [false, 'оператором'], 'automatic' => [true, 'автоматически']]);

test('closure notifications preserve the existing cancellation and closed-ticket delivery guards', function () {
    $ticket = Ticket::factory()->create();
    app(TicketLifecycleService::class)->closeManually($ticket);
    $notice = $ticket->messages()->where('ticket_event', Message::TicketClosedEvent)->sole();
    $stale = Message::factory()->count(3)->for($ticket)->for($ticket->participant, 'participant')->sequence(
        ['author' => MessageAuthor::Bot, 'ticket_event' => null],
        ['author' => MessageAuthor::System, 'ticket_event' => null],
        ['author' => MessageAuthor::Bot, 'ticket_event' => Message::TicketClosedEvent],
    )->create(['direction' => MessageDirection::Outbound, 'delivery_status' => DeliveryStatus::Pending, 'body' => 'Устаревшая эскалация.']);
    foreach ($stale as $message) {
        app()->call([new DeliverTelegramMessage($message->id), 'handle']);
        expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Cancelled);
    }
    app()->call([new DeliverTelegramMessage($notice->id), 'handle']);

    expect($notice->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request['text'] === 'Устаревшая эскалация.');
});

test('cancelled or premature closure notices are not delivered', function (bool $closed) {
    $ticket = $closed ? Ticket::factory()->closed()->create() : Ticket::factory()->create();
    $notice = Message::factory()->for($ticket)->for($ticket->participant, 'participant')->create([
        'author' => MessageAuthor::System, 'direction' => MessageDirection::Outbound,
        'ticket_event' => Message::TicketClosedEvent,
        'delivery_status' => $closed ? DeliveryStatus::Cancelled : DeliveryStatus::Pending,
    ]);

    app()->call([new DeliverTelegramMessage($notice->id), 'handle']);

    expect($notice->refresh()->delivery_status)->toBe(DeliveryStatus::Cancelled);
    expect($notice->delivery_attempts)->toBe(0);
    Http::assertNothingSent();
})->with(['cancelled notice' => true, 'open ticket' => false]);

test('closure and cancellation roll back if the numbered notification cannot be queued', function () {
    Queue::fake()->except(DeliverTelegramMessage::class);
    $ticket = Ticket::factory()->create();
    $stale = Message::factory()->for($ticket)->for($ticket->participant, 'participant')->create([
        'direction' => MessageDirection::Outbound, 'author' => MessageAuthor::Bot, 'delivery_status' => DeliveryStatus::Pending,
    ]);
    DB::statement('ALTER TABLE jobs ADD CONSTRAINT reject_closure_job CHECK (false)');

    expect(fn () => app(TicketLifecycleService::class)->closeManually($ticket))->toThrow(QueryException::class);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    expect($ticket->closed_at)->toBeNull();
    expect($stale->refresh()->delivery_status)->toBe(DeliveryStatus::Pending);
    $this->assertDatabaseCount('messages', 1);
    $this->assertDatabaseCount('jobs', 0);
});

function contextInbound(TelegramParticipant $participant, string $body, int $updateId): Message
{
    app(TelegramIngestionService::class)->ingest(new TelegramUpdateData(
        $updateId, TelegramUpdateKind::Message, $participant->telegram_user_id, $participant->chat_id, $updateId, $body,
    ));

    return $participant->messages()->where('direction', MessageDirection::Inbound)->where('telegram_message_id', $updateId)->sole();
}

function contextAnswer(Message $question, string $body): Message
{
    app(SupportDecisionService::class)->apply($question, new ValidatedSupportDecision(SupportDecisionType::Answer, 'rule_answer', $body, []), 'rules-hash');
    $answer = Message::query()->where('source_message_id', $question->id)->sole();
    app()->call([new DeliverTelegramMessage($answer->id), 'handle']);

    return $answer->refresh();
}

function contextEscalate(Message $question): Ticket
{
    app(SupportDecisionService::class)->apply($question, new ValidatedSupportDecision(SupportDecisionType::Escalate, 'not_in_rules', null, []), 'rules-hash');

    return $question->refresh()->ticket;
}
