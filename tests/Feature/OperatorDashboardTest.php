<?php

use App\Data\ValidatedSupportDecision;
use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\SupportDecisionType;
use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Jobs\DeliverTelegramMessage;
use App\Livewire\OperatorDashboard;
use App\Models\Message;
use App\Models\SupportDecision;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\LlmDecisionValidator;
use App\Services\SupportDecisionService;
use App\Services\TicketLifecycleService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

test('shows active ticket queue and escaped chronological conversation history', function () {
    $operator = User::factory()->create();
    $participant = TelegramParticipant::factory()->create();
    $openTicket = Ticket::factory()->for($participant, 'participant')->create(['escalation_reason' => 'participant_specific']);
    $resolvedTicket = Ticket::factory()->resolved()->create();
    $closedTicket = Ticket::factory()->closed()->create();

    Message::factory()->for($participant, 'participant')->for($openTicket)->create([
        'direction' => MessageDirection::Inbound,
        'author' => MessageAuthor::Participant,
        'body' => '<script>alert(1)</script> [REDACTED_PAYMENT_CARD]',
        'created_at' => now()->subMinutes(2),
    ]);
    Message::factory()->for($participant, 'participant')->for($openTicket)->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Bot,
        'body' => 'Вопрос передан оператору.',
        'delivery_status' => DeliveryStatus::Sent,
        'created_at' => now()->subMinute(),
    ]);
    Message::factory()->for($participant, 'participant')->for($openTicket)->for($operator, 'operator')->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'body' => 'Проверяем статус.',
        'delivery_status' => DeliveryStatus::Failed,
        'last_delivery_error' => 'telegram_request_rejected',
    ]);

    $this->actingAs($operator);

    $component = Livewire::test(OperatorDashboard::class)
        ->assertSee(["#{$openTicket->id}", "#{$resolvedTicket->id}"])
        ->assertDontSee("#{$closedTicket->id}")
        ->call('selectTicket', $openTicket->id)
        ->assertSee(['<script>alert(1)</script> [REDACTED_PAYMENT_CARD]', 'Вопрос передан оператору.', 'Проверяем статус.', 'Ошибка отправки'])
        ->assertDontSeeHtml('<script>alert(1)</script>');

    expect($component->html())->toMatch('/REDACTED_PAYMENT_CARD.*Вопрос передан оператору.*Проверяем статус/s');
});

test('shows Russian message authors and delivery statuses without changing stored messages', function () {
    $this->freezeTime();
    $operator = User::factory()->create();
    $ticket = Ticket::factory()->create();
    Message::factory()->for($ticket->participant, 'participant')->for($ticket)->create(['body' => 'Вопрос участника.']);
    Message::factory()->count(4)->for($ticket->participant, 'participant')->for($ticket)->sequence(
        ['author' => MessageAuthor::Bot, 'delivery_status' => DeliveryStatus::Sent, 'delivered_at' => now()],
        ['author' => MessageAuthor::System, 'delivery_status' => DeliveryStatus::Pending],
        ['author' => MessageAuthor::Operator, 'operator_id' => $operator->id, 'delivery_status' => DeliveryStatus::Failed],
        ['author' => MessageAuthor::Operator, 'operator_id' => $operator->id, 'delivery_status' => DeliveryStatus::Cancelled],
    )->create(['direction' => MessageDirection::Outbound, 'body' => 'Ответ участнику.']);
    $storedMessages = Message::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)->call('selectTicket', $ticket->id)
        ->assertSeeText([
            'Участник · Обращение #'.$ticket->id,
            'Бот · Обращение #'.$ticket->id,
            'Оператор · Обращение #'.$ticket->id,
            'Система · Обращение #'.$ticket->id,
            'Доставка: Ожидает отправки',
            'Доставка: Отправлено',
            'Доставка: Ошибка отправки',
            'Доставка: Отменено',
        ])
        ->assertDontSeeText(['participant', 'bot', 'operator', 'system', 'pending', 'sent', 'failed', 'cancelled', 'M-Social']);

    expect(Message::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($storedMessages);
});

test('shows readable ticket status and escalation reason labels without changing stored codes', function (TicketStatus $status, ?string $reason, string $statusLabel, string $reasonLabel) {
    $this->freezeTime();
    $factory = match ($status) {
        TicketStatus::Open => Ticket::factory(),
        TicketStatus::Resolved => Ticket::factory()->resolved(),
        TicketStatus::Closed => Ticket::factory()->closed(),
    };
    $ticket = $factory->create(['escalation_reason' => $reason]);
    $storedAttributes = $ticket->refresh()->getRawOriginal();
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)->call('selectFilter', 'all')
        ->assertSeeText([$statusLabel, $reasonLabel])
        ->call('selectTicket', $ticket->id)
        ->assertSeeText(['Статус: '.$statusLabel, 'Причина передачи оператору: '.$reasonLabel])
        ->assertDontSeeText(['Причина эскалации', 'open', 'resolved', 'closed', 'llm_failure', 'participant_specific', 'not_in_rules', 'mixed_request', 'unknown', 'legacy_reason'])
        ->call('$refresh')
        ->assertSeeText(['Статус: '.$statusLabel, 'Причина передачи оператору: '.$reasonLabel]);

    expect($ticket->refresh()->getRawOriginal())->toBe($storedAttributes);
})->with([
    'AI failure and open ticket' => [TicketStatus::Open, 'llm_failure', 'Открыто', 'Ошибка ИИ'],
    'participant data and resolved ticket' => [TicketStatus::Resolved, 'participant_specific', 'Решено', 'Требуется проверка данных участника'],
    'missing rule and closed ticket' => [TicketStatus::Closed, 'not_in_rules', 'Закрыто', 'Нет ответа в правилах'],
    'mixed question' => [TicketStatus::Open, 'mixed_request', 'Открыто', 'Часть вопроса требует оператора'],
    'no reason' => [TicketStatus::Open, null, 'Открыто', 'Не указана'],
    'unknown reason' => [TicketStatus::Open, 'unknown', 'Открыто', 'Не указана'],
    'unrecognized historical reason' => [TicketStatus::Open, 'legacy_reason', 'Открыто', 'Не указана'],
]);

test('shows readable close reasons without changing historical ticket data', function (?TicketCloseReason $reason, string $label) {
    $this->freezeTime();
    $ticket = Ticket::factory()->closed()->create(['close_reason' => $reason]);
    $storedAttributes = $ticket->refresh()->getRawOriginal();
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)->call('selectFilter', 'closed')->call('selectTicket', $ticket->id)
        ->assertSeeText('Способ закрытия: '.$label)
        ->assertDontSeeText(['user_confirmed', 'auto_closed', 'operator_closed'])
        ->call('$refresh')->assertSeeText('Способ закрытия: '.$label);

    expect($ticket->refresh()->getRawOriginal())->toBe($storedAttributes);
})->with([
    'participant confirmed' => [TicketCloseReason::UserConfirmed, 'Участник подтвердил решение'],
    'automatic closure' => [TicketCloseReason::AutoClosed, 'Закрыто автоматически'],
    'operator closure' => [TicketCloseReason::OperatorClosed, 'Закрыто оператором'],
    'historical ticket without a reason' => [null, 'Не указан'],
]);

test('isolates histories of sequential closed tickets for the same participant', function () {
    $this->freezeTime();
    $operator = User::factory()->create();
    $participant = TelegramParticipant::factory()->create();
    $lifecycle = app(TicketLifecycleService::class);
    $firstTicket = $lifecycle->create($participant);
    $firstQuestion = Message::factory()->for($participant, 'participant')->for($firstTicket)->create(['body' => 'Вопрос первого обращения.']);
    $firstReply = Message::factory()->for($participant, 'participant')->for($firstTicket)->for($operator, 'operator')->create([
        'body' => 'Ответ первого обращения.',
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Sent,
    ]);
    $lifecycle->closeManually($firstTicket);
    $secondTicket = $lifecycle->create($participant);
    $secondQuestion = Message::factory()->for($participant, 'participant')->for($secondTicket)->create(['body' => 'Вопрос второго обращения.']);
    $secondReply = Message::factory()->for($participant, 'participant')->for($secondTicket)->for($operator, 'operator')->create([
        'body' => 'Ответ второго обращения.',
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Sent,
    ]);
    $lifecycle->closeManually($secondTicket);
    $messageTickets = Message::query()->orderBy('id')->pluck('ticket_id', 'id')->all();
    $this->actingAs($operator);

    $component = Livewire::test(OperatorDashboard::class)->call('selectFilter', 'closed')
        ->call('selectTicket', $firstTicket->id)
        ->assertSee(['Вопрос первого обращения.', 'Ответ первого обращения.'])
        ->assertDontSee(['Вопрос второго обращения.', 'Ответ второго обращения.']);
    expect($component->viewData('messages')->pluck('id')->all())->toBe([$firstReply->id, $firstQuestion->id]);

    $component->call('selectTicket', $secondTicket->id)
        ->assertSee(['Вопрос второго обращения.', 'Ответ второго обращения.'])
        ->assertDontSee(['Вопрос первого обращения.', 'Ответ первого обращения.'])
        ->call('$refresh');
    expect($component->viewData('messages')->pluck('id')->all())->toBe([$secondReply->id, $secondQuestion->id]);
    expect(Message::query()->orderBy('id')->pluck('ticket_id', 'id')->all())->toBe($messageTickets);
    expect($firstTicket->refresh()->status)->toBe(TicketStatus::Closed);
    expect($secondTicket->refresh()->status)->toBe(TicketStatus::Closed);
});

test('displays ticket and message timestamps in Moscow time without changing stored UTC dates', function () {
    $this->freezeTime();
    $participant = TelegramParticipant::factory()->create();
    $ticket = Ticket::factory()->for($participant, 'participant')->closed()->create([
        'created_at' => '2026-10-02 22:15:00',
        'closed_at' => '2026-10-02 22:45:00',
    ]);
    $message = Message::factory()->for($participant, 'participant')->for($ticket)->create([
        'body' => 'Сообщение перед полуночью UTC.',
        'created_at' => '2026-10-02 22:30:00',
    ]);
    $ticketAttributes = $ticket->refresh()->getRawOriginal();
    $messageAttributes = $message->refresh()->getRawOriginal();
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(OperatorDashboard::class)->call('selectFilter', 'closed')->call('selectTicket', $ticket->id)
        ->assertSee(['Создано: 03.10.2026 01:15 МСК', 'Закрыто: 03.10.2026 01:45 МСК', '03.10.2026 01:30 МСК'])
        ->call('$refresh')
        ->assertSee(['Создано: 03.10.2026 01:15 МСК', 'Закрыто: 03.10.2026 01:45 МСК', '03.10.2026 01:30 МСК']);

    expect($component->viewData('selectedTicket')->created_at->toIso8601String())->toBe('2026-10-02T22:15:00+00:00');
    expect($component->viewData('selectedTicket')->closed_at->toIso8601String())->toBe('2026-10-02T22:45:00+00:00');
    expect($component->viewData('messages')->first()->created_at->toIso8601String())->toBe('2026-10-02T22:30:00+00:00');
    expect($ticket->refresh()->getRawOriginal())->toBe($ticketAttributes);
    expect($message->refresh()->getRawOriginal())->toBe($messageAttributes);
});

test('excludes unticketed pre-escalation context without reassigning messages to the ticket', function () {
    $this->freezeTime();
    Queue::fake();
    Http::preventStrayRequests();
    $participant = TelegramParticipant::factory()->create();
    $question = Message::factory()->for($participant, 'participant')->create(['body' => 'Сколько шансов дают 7 йогуртов?']);
    $decisions = app(SupportDecisionService::class);
    $decisions->apply($question, new ValidatedSupportDecision(SupportDecisionType::Answer, 'rule_answer', 'У вас будет 3 шанса.', [['rule_id' => '5.5', 'quote' => 'Каждые 2 (две) единицы участвующей продукции в одном чеке дают 1 (один) шанс в розыгрышах.']]), 'rules-hash');
    $botAnswer = Message::query()->where('author', MessageAuthor::Bot)->sole();
    $followUp = Message::factory()->for($participant, 'participant')->create(['body' => 'Не понял ответ, позовите оператора.']);
    $decisions->apply($followUp, new ValidatedSupportDecision(SupportDecisionType::Escalate, 'not_in_rules', null, []), 'rules-hash');
    $ticket = Ticket::query()->sole();
    $escalationNotice = Message::query()->where('ticket_id', $ticket->id)->where('author', MessageAuthor::Bot)->sole();
    Message::factory()->create(['body' => 'Переписка другого участника.']);
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(OperatorDashboard::class)->call('selectTicket', $ticket->id)
        ->assertSee(['Не понял ответ, позовите оператора.', 'Ваш вопрос передан оператору.'])
        ->assertDontSee(['Сколько шансов дают 7 йогуртов?', 'У вас будет 3 шанса.', 'Переписка другого участника.']);
    $component->call('$refresh');

    expect($component->viewData('messages')->pluck('id')->all())->toBe([$escalationNotice->id, $followUp->id]);
    expect($component->html())->toMatch('/Не понял ответ.*Ваш вопрос передан оператору/s');
    expect($question->refresh()->ticket_id)->toBeNull();
    expect($botAnswer->refresh()->ticket_id)->toBeNull();
    expect($followUp->refresh()->ticket_id)->toBe($ticket->id);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $this->assertDatabaseCount('tickets', 1);
    $this->assertDatabaseCount('messages', 5);
    $this->assertDatabaseCount('support_decisions', 2);
    Queue::assertPushed(DeliverTelegramMessage::class, 2);
    Http::assertNothingSent();
});

test('excludes earlier ticket history and rejects its delivery actions from the selected ticket', function (string $action) {
    Queue::fake();
    $participant = TelegramParticipant::factory()->create();
    $previousTicket = Ticket::factory()->for($participant, 'participant')->closed()->create();
    $ticket = Ticket::factory()->for($participant, 'participant')->create();
    $previousReply = Message::factory()->for($participant, 'participant')->for($previousTicket)->create([
        'author' => MessageAuthor::Operator, 'direction' => MessageDirection::Outbound,
        'delivery_status' => DeliveryStatus::Failed, 'body' => 'Предыдущий ответ оператора.',
    ]);
    $currentReply = Message::factory()->for($participant, 'participant')->for($ticket)->create([
        'author' => MessageAuthor::Operator, 'direction' => MessageDirection::Outbound,
        'delivery_status' => DeliveryStatus::Failed, 'body' => 'Ответ текущего обращения.',
    ]);
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)->call('selectTicket', $ticket->id)
        ->assertSee('Ответ текущего обращения.')
        ->assertDontSee(['Предыдущий ответ оператора.', "Обращение #{$previousTicket->id}"])
        ->assertDontSeeHtml('wire:click="retryDelivery('.$previousReply->id.')"')
        ->assertDontSeeHtml('wire:click="cancelDelivery('.$previousReply->id.')"')
        ->assertSeeHtml('wire:click="retryDelivery('.$currentReply->id.')"')
        ->assertSeeHtml('wire:click="cancelDelivery('.$currentReply->id.')"')
        ->call($action, $previousReply->id)->assertNotFound();

    expect($previousReply->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    expect($currentReply->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    expect($previousTicket->refresh()->status)->toBe(TicketStatus::Closed);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $this->assertDatabaseCount('messages', 2);
    Queue::assertNothingPushed();
})->with(['retry' => ['retryDelivery'], 'cancel' => ['cancelDelivery']]);

test('allows an operator to close an active ticket and removes it from the queue', function () {
    $operator = User::factory()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->set('replyBody', 'Черновик закрываемого обращения')
        ->call('closeTicket')
        ->assertSet('selectedTicketId', null)
        ->assertSet('replyBody', '')
        ->assertDontSee("#{$ticket->id}");

    expect($ticket->refresh()->status->value)->toBe('closed')
        ->and($ticket->close_reason)->toBe(TicketCloseReason::OperatorClosed);
});

test('shows closed tickets only in the closed filter', function () {
    $operator = User::factory()->create();
    $openTicket = Ticket::factory()->create();
    $closedTicket = Ticket::factory()->closed()->create();

    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)
        ->assertSee("#{$openTicket->id}")
        ->assertDontSee("#{$closedTicket->id}")
        ->call('selectFilter', 'closed')
        ->assertSee("#{$closedTicket->id}")
        ->assertDontSee("#{$openTicket->id}");
});

test('clears the draft when selecting another participant and prevents sending the old reply', function () {
    Queue::fake();
    $operator = User::factory()->create();
    $firstTicket = Ticket::factory()->create();
    $secondTicket = Ticket::factory()->create();
    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $firstTicket->id)
        ->call('sendReply')
        ->assertHasErrors(['replyBody' => 'required'])
        ->set('replyBody', 'Персональная информация первого участника')
        ->call('selectTicket', $secondTicket->id)
        ->assertSet('selectedTicketId', $secondTicket->id)
        ->assertSet('replyBody', '')
        ->assertHasNoErrors()
        ->call('sendReply')
        ->assertHasErrors(['replyBody' => 'required']);

    $this->assertDatabaseCount('messages', 0);
    Queue::assertNothingPushed();
});

test('clears the draft and validation errors when selecting a filter', function (string $filter) {
    $operator = User::factory()->create();
    $ticket = Ticket::factory()->create();
    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->call('sendReply')
        ->assertHasErrors(['replyBody' => 'required'])
        ->set('replyBody', 'Персональный черновик')
        ->call('selectFilter', $filter)
        ->assertSet('filter', $filter)
        ->assertSet('selectedTicketId', null)
        ->assertSet('replyBody', '')
        ->assertHasNoErrors();
})->with(['active', 'closed', 'all']);

test('keeps the draft when reselecting the same ticket or rejecting a selection', function () {
    $operator = User::factory()->create();
    $ticket = Ticket::factory()->create();
    $closedTicket = Ticket::factory()->closed()->create();
    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->set('replyBody', 'Черновик текущего обращения')
        ->call('selectTicket', $ticket->id)
        ->assertSet('replyBody', 'Черновик текущего обращения')
        ->call('selectTicket', $closedTicket->id)
        ->assertHasErrors('ticket')
        ->assertSet('selectedTicketId', $ticket->id)
        ->assertSet('replyBody', 'Черновик текущего обращения')
        ->call('selectFilter', 'invalid')
        ->assertSet('filter', 'active')
        ->assertSet('selectedTicketId', $ticket->id)
        ->assertSet('replyBody', 'Черновик текущего обращения');
});

test('sorts tickets with newest first', function () {
    $operator = User::factory()->create();
    $olderTicket = Ticket::factory()->create(['created_at' => now()->subMinute()]);
    $newerTicket = Ticket::factory()->create(['created_at' => now()]);

    $this->actingAs($operator);

    $component = Livewire::test(OperatorDashboard::class);

    expect($component->html())->toMatch("/#{$newerTicket->id}.*#{$olderTicket->id}/s");
});

test('allows an operator to view closed ticket history with a readable closure reason and without actions', function (TicketCloseReason $closeReason, string $label) {
    $operator = User::factory()->create();
    $participant = TelegramParticipant::factory()->create();
    $ticket = Ticket::factory()->for($participant, 'participant')->closed($closeReason)->create();
    Message::factory()->for($participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Inbound,
        'author' => MessageAuthor::Participant,
        'body' => 'История закрытого обращения.',
    ]);

    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)
        ->call('selectFilter', 'closed')
        ->call('selectTicket', $ticket->id)
        ->assertSee([
            "#{$ticket->id}",
            (string) $participant->telegram_user_id,
            'История закрытого обращения.',
            'Способ закрытия: '.$label,
            'Обращение закрыто и доступно только для просмотра.',
        ])
        ->assertDontSeeText(['operator_closed', 'auto_closed', 'user_confirmed'])
        ->assertDontSee('Ответ участнику')
        ->assertDontSee('Закрыть обращение');
})->with([
    'operator closed' => [TicketCloseReason::OperatorClosed, 'Закрыто оператором'],
    'automatically closed' => [TicketCloseReason::AutoClosed, 'Закрыто автоматически'],
    'historical user confirmation' => [TicketCloseReason::UserConfirmed, 'Участник подтвердил решение'],
]);

test('does not allow reply or close actions for a closed ticket', function () {
    $operator = User::factory()->create();
    $ticket = Ticket::factory()->closed()->create();

    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)
        ->call('selectFilter', 'closed')
        ->call('selectTicket', $ticket->id)
        ->set('replyBody', 'Недопустимый ответ')
        ->call('sendReply')
        ->assertHasErrors('replyBody')
        ->call('closeTicket')
        ->assertHasErrors('ticket');

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and(Message::query()->where('ticket_id', $ticket->id)->count())->toBe(0);
});

test('queues a retry for a failed operator reply without changing the ticket state', function () {
    Queue::fake();
    $operator = User::factory()->create();
    $participant = TelegramParticipant::factory()->create();
    $ticket = Ticket::factory()->for($participant, 'participant')->create();
    $message = Message::factory()->for($participant, 'participant')->for(User::factory(), 'operator')->for($ticket)->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Failed,
        'last_delivery_error' => 'telegram_request_rejected',
    ]);

    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->assertSee('telegram_request_rejected')
        ->assertSee('Повторить отправку')
        ->call('retryDelivery', $message->id);

    Queue::assertPushed(DeliverTelegramMessage::class, fn ($job): bool => $job->messageId === $message->id);
    expect($ticket->refresh()->status->value)->toBe('open');
});

test('bounds every ticket filter and navigates without skipping tickets when new ones arrive', function (string $filter) {
    $this->freezeTime();
    $factory = Ticket::factory()->count(41);
    $tickets = ($filter === 'closed' ? $factory->closed() : $factory)->create();
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(OperatorDashboard::class)->call('selectFilter', $filter);
    $page = $component->viewData('tickets');
    expect($page)->toBeInstanceOf(CursorPaginator::class);
    expect($page->pluck('id')->all())->toBe($tickets->pluck('id')->reverse()->take(20)->values()->all());
    preg_match_all('/wire:key="ticket-\d+"/', $component->html(), $buttons);
    expect($buttons[0])->toHaveCount(20);
    $component->assertSeeHtml("setPage('{$page->nextCursor()->encode()}', 'ticketsCursor')");

    $component->call('selectTicket', $tickets->last()->id)->set('replyBody', 'Черновик выбранного обращения');
    $component->call('setPage', $page->nextCursor()->encode(), 'ticketsCursor');
    $secondPageIds = $component->viewData('tickets')->pluck('id')->all();
    expect($secondPageIds)->toBe($tickets->pluck('id')->reverse()->slice(20, 20)->values()->all());

    Ticket::factory()->closed()->create();
    Ticket::factory()->create();
    $component->call('$refresh')
        ->assertSet('selectedTicketId', $tickets->last()->id)
        ->assertSet('replyBody', 'Черновик выбранного обращения');
    expect($component->viewData('tickets')->pluck('id')->all())->toBe($secondPageIds);

    $component->call('setPage', $component->viewData('tickets')->nextCursor()->encode(), 'ticketsCursor');
    expect($component->viewData('tickets')->pluck('id')->all())->toBe([$tickets->first()->id]);

    $tickets->first()->delete();
    $component->call('$refresh')->assertSeeHtml("resetPage('ticketsCursor')");
    expect($component->viewData('tickets')->count())->toBe(0);
    $component->call('resetPage', 'ticketsCursor')
        ->assertSet('selectedTicketId', $tickets->last()->id)
        ->assertSet('replyBody', 'Черновик выбранного обращения');
    expect($component->viewData('tickets')->count())->toBe(20);

    $component->call('selectFilter', $filter)->assertSet('selectedTicketId', null)->assertSet('replyBody', '');
    expect($component->viewData('tickets')->onFirstPage())->toBeTrue();
})->with(['active', 'closed', 'all']);

test('paginates only the selected ticket history and keeps cursors independent from the queue and other tickets', function () {
    $this->freezeTime();
    $ticket = Ticket::factory()->create();
    $previousTicket = Ticket::factory()->for($ticket->participant, 'participant')->closed()->create();
    $messages = Message::factory()->count(111)->for($ticket->participant, 'participant')->for($ticket)
        ->sequence(fn ($sequence) => [
            'body' => sprintf('Сообщение %03d.', $sequence->index),
        ])
        ->create();
    Message::factory()->count(51)->for($ticket->participant, 'participant')->for($previousTicket)
        ->create(['body' => 'История предыдущего обращения.']);
    Message::factory()->count(51)->for($ticket->participant, 'participant')
        ->create(['body' => 'Контекст без обращения.']);
    $otherTicket = Ticket::factory()->create();
    Message::factory()->for($otherTicket->participant, 'participant')->for($otherTicket)->create(['body' => 'История другого участника.']);
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(OperatorDashboard::class)->call('selectTicket', $ticket->id);
    $page = $component->viewData('messages');
    expect($page)->toBeInstanceOf(CursorPaginator::class);
    expect($page->pluck('id')->all())->toBe($messages->pluck('id')->reverse()->take(50)->values()->all());
    expect($component->viewData('selectedTicket')->relationLoaded('messages'))->toBeFalse();
    expect($component->html())->toMatch('/Сообщение 061\..*Сообщение 110\./s');
    $component->assertDontSee(['Сообщение 060.', 'История предыдущего обращения.', 'Контекст без обращения.', 'История другого участника.'])
        ->assertSeeHtml("setPage('{$page->nextCursor()->encode()}', 'messagesCursor')");

    $queueCursor = $component->get('paginators.ticketsCursor');
    $component->set('replyBody', 'Черновик текущего участника')
        ->call('setPage', $page->nextCursor()->encode(), 'messagesCursor');
    expect($component->viewData('messages')->pluck('id')->all())->toBe($messages->pluck('id')->reverse()->slice(50, 50)->values()->all());
    expect($component->html())->toMatch('/Сообщение 011\..*Сообщение 060\./s');
    $component->assertDontSee('Сообщение 061.')
        ->assertSet('replyBody', 'Черновик текущего участника')->assertSet('paginators.ticketsCursor', $queueCursor);

    $component->call('selectTicket', $ticket->id)->assertSee('Сообщение 011.');
    $component->call('setPage', $component->viewData('messages')->nextCursor()->encode(), 'messagesCursor');
    expect($component->viewData('messages')->pluck('id')->all())->toBe($messages->pluck('id')->reverse()->slice(100)->values()->all());
    expect($component->html())->toMatch('/Сообщение 000\..*Сообщение 010\./s');
    $component->assertDontSee('Сообщение 011.');

    $component->call('setPage', $component->viewData('messages')->previousCursor()->encode(), 'messagesCursor');
    expect($component->html())->toMatch('/Сообщение 011\..*Сообщение 060\./s');
    $component->call('selectTicket', $otherTicket->id)->assertSet('replyBody', '')
        ->assertSee('История другого участника.')->assertDontSee('Сообщение 011.');
    expect($component->viewData('messages')->onFirstPage())->toBeTrue();
});

test('refreshes incoming messages and delivery states without discarding the current draft', function () {
    $ticket = Ticket::factory()->create();
    $reply = Message::factory()->for($ticket->participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    $this->actingAs(User::factory()->create());
    $component = Livewire::test(OperatorDashboard::class)->call('selectTicket', $ticket->id)
        ->set('replyBody', 'Личный черновик')->assertSeeHtml('wire:poll.15s.visible');
    $newTicket = Ticket::factory()->create();
    Message::factory()->for($ticket->participant, 'participant')->for($ticket)->create(['body' => 'Новое сообщение участника.']);
    $reply->update(['delivery_status' => DeliveryStatus::Failed, 'last_delivery_error' => 'telegram_delivery_exhausted']);

    $component->call('$refresh')->assertSet('selectedTicketId', $ticket->id)->assertSet('replyBody', 'Личный черновик')
        ->assertSee(["#{$newTicket->id}", 'Новое сообщение участника.', 'telegram_delivery_exhausted', 'Повторить отправку']);
});

test('returns to recent history after sending a reply from an older history page', function () {
    Queue::fake([DeliverTelegramMessage::class]);
    $this->freezeTime();
    $ticket = Ticket::factory()->create();
    Message::factory()->count(51)->for($ticket->participant, 'participant')->for($ticket)->create(['body' => 'История участника.']);
    $this->actingAs(User::factory()->create());
    $component = Livewire::test(OperatorDashboard::class)->call('selectTicket', $ticket->id);
    $component->call('setPage', $component->viewData('messages')->nextCursor()->encode(), 'messagesCursor')
        ->set('replyBody', 'Новый ответ оператора.')
        ->call('sendReply')->assertHasNoErrors()->assertSet('replyBody', '')->assertSee('Новый ответ оператора.');

    expect($component->viewData('messages')->onFirstPage())->toBeTrue();
    $this->assertDatabaseHas('messages', ['ticket_id' => $ticket->id, 'body' => 'Новый ответ оператора.', 'delivery_status' => 'pending']);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
});

test('keeps queue and conversation row queries bounded without offsets or unused relationships', function () {
    $ticket = Ticket::factory()->create();
    Message::factory()->count(51)->for($ticket->participant, 'participant')->for($ticket)->create();
    $this->actingAs(User::factory()->create());
    DB::enableQueryLog();

    try {
        Livewire::test(OperatorDashboard::class)->call('selectTicket', $ticket->id)->call('$refresh');
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_starts_with($query, 'select'));
        $messageQueries = $queries->filter(fn (string $query): bool => str_contains($query, 'from "messages"') && ! str_contains(strtolower($query), 'count('));
        $ticketQueries = $queries->filter(fn (string $query): bool => str_contains($query, 'from "tickets"') && str_contains($query, 'limit'));

        expect($messageQueries)->not->toBeEmpty();
        foreach ($messageQueries as $query) {
            expect($query)->toContain('limit 51')->not->toContain('offset', 'count(');
        }
        foreach ($ticketQueries as $query) {
            expect($query)->not->toContain('offset', 'count(');
        }
        expect($queries->filter(fn (string $query): bool => str_contains($query, 'from "users"')))->toBeEmpty();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('uses index scans for the queue and history when the archive grows', function () {
    $this->freezeTime();
    $activeTickets = Ticket::factory()->count(21)->create(['created_at' => now()->subDay()]);
    $archiveParticipant = TelegramParticipant::factory()->create();
    $archive = Ticket::factory()->count(1000)->for($archiveParticipant, 'participant')->closed()->create();
    Message::factory()->count(1500)->for($archiveParticipant, 'participant')->for($archive->first())->create();
    Message::factory()->count(51)->for($activeTickets->first()->participant, 'participant')->for($activeTickets->first())->create();
    $this->actingAs(User::factory()->create());
    DB::statement('ANALYZE tickets');
    DB::statement('ANALYZE messages');
    DB::enableQueryLog();

    try {
        $component = Livewire::test(OperatorDashboard::class)->call('selectTicket', $activeTickets->first()->id);
        $component->call('setPage', $component->viewData('tickets')->nextCursor()->encode(), 'ticketsCursor');
        $component->call('setPage', $component->viewData('messages')->nextCursor()->encode(), 'messagesCursor');
        $component->call('selectFilter', 'closed')->call('selectFilter', 'all');
        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'select') &&
            (str_contains($query['query'], 'limit 21') || str_contains($query['query'], 'limit 51'))
        );
        DB::disableQueryLog();

        expect($queries)->not->toBeEmpty();
        foreach ($queries as $query) {
            $plan = DB::selectOne('EXPLAIN (ANALYZE, FORMAT JSON) '.$query['query'], $query['bindings']);
            $json = $plan->{'QUERY PLAN'};
            expect($json)->toContain('Index Scan')->not->toContain('Seq Scan', '"Node Type": "Sort"');
        }
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('statistics exclude refusals and distinguish prepared grounded answers from delivery', function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->freezeTime();
    $this->actingAs(User::factory()->create());
    $decisions = app(SupportDecisionService::class);
    foreach ([
        ['decision' => 'answer', 'reason' => 'rule_answer', 'answer' => 'Деньгами заменить приз нельзя.', 'evidence' => [['rule_id' => '7.4', 'quote' => 'Денежная замена не предусмотрена.']]],
        ['decision' => 'refuse', 'reason' => 'prompt_injection', 'answer' => 'Я не могу выполнить этот запрос.', 'evidence' => []],
    ] as $output) {
        $decision = app(LlmDecisionValidator::class)->validate($output, '7.4. Денежная замена не предусмотрена.');
        foreach (DeliveryStatus::cases() as $status) {
            $question = Message::factory()->create();
            $decisions->apply($question, $decision, 'rules-hash');
            Message::query()->where('participant_id', $question->participant_id)->where('direction', MessageDirection::Outbound)->sole()->update([
                'delivery_status' => $status,
                'delivered_at' => $status === DeliveryStatus::Sent ? now() : null,
            ]);
        }
    }
    $mixed = Message::factory()->create();
    $decisions->apply($mixed, new ValidatedSupportDecision(SupportDecisionType::Mixed, 'mixed_request', 'Часть ответа.', []), 'rules-hash');
    Message::query()->where('participant_id', $mixed->participant_id)->where('direction', MessageDirection::Outbound)->update([
        'delivery_status' => DeliveryStatus::Sent,
        'delivered_at' => now(),
    ]);
    $escalated = Message::factory()->create();
    $decisions->failSafeEscalate($escalated, 'rules-hash');
    Message::query()->where('participant_id', $escalated->participant_id)->where('direction', MessageDirection::Outbound)->update(['delivery_status' => DeliveryStatus::Cancelled]);
    $first = Ticket::factory()->create(['created_at' => now()->subMinutes(10), 'first_operator_replied_at' => now()->subMinutes(8)]);
    $second = Ticket::factory()->closed()->create(['created_at' => now()->subMinutes(10), 'first_operator_replied_at' => now()->subMinutes(4)]);
    foreach ([$first, $second] as $ticket) {
        Message::factory()->for($ticket->participant, 'participant')->for($ticket)->create([
            'direction' => MessageDirection::Outbound,
            'author' => MessageAuthor::Operator,
            'delivery_status' => DeliveryStatus::Sent,
            'delivered_at' => $ticket->first_operator_replied_at,
        ]);
    }
    $unanswered = Ticket::factory()->create(['created_at' => now()->subHour()]);
    Message::factory()->count(2)->for($unanswered->participant, 'participant')->for($unanswered)->sequence(
        ['delivery_status' => DeliveryStatus::Pending],
        ['delivery_status' => DeliveryStatus::Cancelled],
    )->create(['direction' => MessageDirection::Outbound, 'author' => MessageAuthor::Operator]);
    Message::factory()->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::System,
        'delivery_status' => DeliveryStatus::Sent,
        'delivered_at' => now(),
    ]);
    Message::factory()->count(3)->for($first)->create(['participant_id' => $first->participant_id]);
    $expected = [
        'bot_resolved' => 1, 'bot_prepared' => 4, 'bot_pending' => 1, 'bot_failed' => 1, 'bot_cancelled' => 1,
        'escalated' => 5, 'average_operator_response_seconds' => 240.0, 'operator_cancelled' => 1,
    ];
    $counts = [Message::query()->count(), Ticket::query()->count(), SupportDecision::query()->count()];

    Livewire::test(OperatorDashboard::class)->assertViewHas('statistics', $expected)
        ->call('$refresh')->assertViewHas('statistics', $expected)
        ->assertSee(['4,0 мин', 'Доставлено ответов по правилам без оператора', 'Подготовлено: 4', 'Отменено ответов оператора: 1']);

    expect([Message::query()->count(), Ticket::query()->count(), SupportDecision::query()->count()])->toBe($counts);
    Http::assertNothingSent();
    Queue::assertPushed(DeliverTelegramMessage::class, 11);
});

test('does not count a sent bot answer without a successful delivery timestamp as resolved', function () {
    $this->freezeTime();
    $question = Message::factory()->create();
    Queue::fake([DeliverTelegramMessage::class]);
    app(SupportDecisionService::class)->apply($question, new ValidatedSupportDecision(
        SupportDecisionType::Answer, 'rule_answer', 'Кефир не участвует.', [['rule_id' => '4.2', 'quote' => 'Кефир не участвует.']],
    ), 'rules-hash');
    $answer = Message::query()->where('author', MessageAuthor::Bot)->sole();
    $answer->update(['delivery_status' => DeliveryStatus::Sent]);
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)->assertViewHas('statistics', [
        'bot_resolved' => 0, 'bot_prepared' => 1, 'bot_pending' => 0, 'bot_failed' => 0, 'bot_cancelled' => 0,
        'escalated' => 0, 'average_operator_response_seconds' => null, 'operator_cancelled' => 0,
    ]);

    expect($answer->refresh()->delivered_at)->toBeNull();
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
});
