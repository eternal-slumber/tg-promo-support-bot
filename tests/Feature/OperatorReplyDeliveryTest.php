<?php

use App\Data\TelegramOutboundMessage;
use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Jobs\AutoCloseTicket;
use App\Jobs\DeliverTelegramMessage;
use App\Livewire\OperatorDashboard;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OperatorReplyService;
use App\Services\TelegramMessagePresentation;
use App\Services\TicketLifecycleService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

test('the operator panel sends an ordinary answer without resolving an open or resolved ticket', function (bool $resolved) {
    Queue::fake();
    $ticket = $resolved ? Ticket::factory()->resolved()->create() : Ticket::factory()->create();
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->assertSee('Отправить ответ')
        ->assertDontSee('Отправить и решить')
        ->set('replyBody', 'Ответ оператора')
        ->call('sendReply')
        ->assertHasNoErrors()
        ->assertSet('replyBody', '');

    $reply = Message::query()->sole();
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    Queue::assertPushed(DeliverTelegramMessage::class, fn (DeliverTelegramMessage $job): bool => $job->messageId === $reply->id);
    Queue::assertNotPushed(AutoCloseTicket::class);
})->with(['open' => false, 'resolved' => true]);

test('marking a ticket resolved is independent of the draft and delivery', function () {
    $this->freezeTime();
    Queue::fake();
    config()->set('support.ticket_auto_close_hours', 12);
    $ticket = Ticket::factory()->create();
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)->call('selectTicket', $ticket->id)
        ->assertSee('Отметить решённым')
        ->set('replyBody', 'Неотправленный черновик')
        ->call('resolveTicket')->assertHasNoErrors()
        ->assertSet('replyBody', 'Неотправленный черновик')
        ->assertDontSee('Отметить решённым')
        ->call('resolveTicket')->assertHasErrors('ticket');

    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved);
    $this->assertDatabaseCount('messages', 0);
    Queue::assertPushed(AutoCloseTicket::class, 1);
    Queue::assertPushed(AutoCloseTicket::class, fn (AutoCloseTicket $job): bool => $job->ticketId === $ticket->id
        && $job->resolvedSince === $ticket->resolved_since->toISOString()
        && $job->delay->getTimestamp() === now()->addHours(12)->getTimestamp());
    Queue::assertNotPushed(DeliverTelegramMessage::class);
});

test('requires authentication to send resolve or close a ticket', function (string $action, array $parameters) {
    Queue::fake();
    $ticket = Ticket::factory()->create();

    Livewire::test(OperatorDashboard::class)
        ->set('selectedTicketId', $ticket->id)
        ->set('replyBody', 'Ответ')
        ->call($action, ...$parameters)
        ->assertForbidden();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $this->assertDatabaseCount('messages', 0);
    Queue::assertNothingPushed();
})->with([
    'ordinary reply' => ['sendReply', []],
    'mark resolved' => ['resolveTicket', []],
    'manual close' => ['closeTicket', []],
]);

test('the explicit resolve action cannot send or reopen a closed ticket', function () {
    Queue::fake();
    $ticket = Ticket::factory()->closed()->create();
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)
        ->call('selectFilter', 'closed')
        ->call('selectTicket', $ticket->id)
        ->assertDontSee('Отметить решённым')
        ->call('resolveTicket')
        ->assertHasErrors('ticket');

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
    $this->assertDatabaseCount('messages', 0);
    Queue::assertNothingPushed();
});

test('persists a sanitized pending operator reply and queues delivery in the same transaction', function (string $body, string $expected, array $redactionTypes) {
    Queue::fake();
    $operator = User::factory()->create();
    $participant = TelegramParticipant::factory()->create();
    $ticket = Ticket::factory()->for($participant, 'participant')->create();

    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->set('replyBody', $body)
        ->call('sendReply')
        ->assertHasNoErrors()
        ->assertSet('replyBody', '');

    $reply = Message::query()->sole();

    expect($reply->author)->toBe(MessageAuthor::Operator)
        ->and($reply->direction)->toBe(MessageDirection::Outbound)
        ->and($reply->delivery_status)->toBe(DeliveryStatus::Pending)
        ->and($reply->body)->toBe($expected)
        ->and($reply->sensitive_data_redacted)->toBeTrue()
        ->and($reply->redaction_types)->toBe($redactionTypes)
        ->and($reply->operator_id)->toBe($operator->id)
        ->and($ticket->refresh()->first_operator_replied_at)->toBeNull();

    Queue::assertPushed(DeliverTelegramMessage::class, fn ($job): bool => $job->messageId === $reply->id && $job->afterCommit === false);
})->with([
    'card' => ['Переведите на карту 2200 1234 5678 9012', 'Переведите на карту [REDACTED_PAYMENT_CARD]', ['payment_card']],
    'card followed by amount' => ['Карта 4111 1111 1111 1111 100 рублей', 'Карта [REDACTED_PAYMENT_CARD] 100 рублей', ['payment_card']],
    'common secret formats' => [
        "Карта 2200\u{00A0}1234\u{00A0}5678\u{00A0}9012, код из смс: 123 456, пароль: secret word",
        'Карта [REDACTED_PAYMENT_CARD], код из смс: [REDACTED_OTP], пароль: [REDACTED_PASSWORD]',
        ['payment_card', 'otp', 'password'],
    ],
]);

test('statistics use the first delivered operator reply after a failed answer is cancelled', function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0));
    $operator = User::factory()->create();
    $ticket = Ticket::factory()->create();
    $this->actingAs($operator);
    config()->set('telegram.api_base_url', 'https://telegram.example');
    config()->set('telegram.bot_token', 'test-token');
    Http::preventStrayRequests();
    Http::fake(['https://telegram.example/bottest-token/sendMessage' => Http::sequence()
        ->push(['ok' => false], 400)
        ->push(['ok' => true, 'result' => ['message_id' => 789]])
        ->push(['ok' => true, 'result' => ['message_id' => 790]])]);
    $replies = app(OperatorReplyService::class);
    $this->travel(2)->minutes();
    $cancelled = $replies->create($operator, $ticket, 'Первый ответ');
    app()->call([new DeliverTelegramMessage($cancelled->id), 'handle']);
    expect($cancelled->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    expect($ticket->refresh()->first_operator_replied_at)->toBeNull();
    $replies->cancel($ticket, $cancelled->id);

    expect($ticket->refresh()->first_operator_replied_at)->toBeNull();
    Livewire::test(OperatorDashboard::class)->assertViewHas('statistics', fn (array $statistics): bool => $statistics['average_operator_response_seconds'] === null && $statistics['operator_cancelled'] === 1
    );
    $this->travel(2)->minutes();
    $delivered = $replies->create($operator, $ticket, 'Исправленный ответ');
    $this->travel(3)->minutes();

    app()->call([new DeliverTelegramMessage($delivered->id), 'handle']);

    expect($ticket->refresh()->first_operator_replied_at?->toDateTimeString())->toBe('2026-10-03 12:07:00');
    expect($delivered->refresh()->delivered_at?->toDateTimeString())->toBe('2026-10-03 12:07:00');
    app(TicketLifecycleService::class)->reopen($ticket);
    $this->travel(3)->minutes();
    $followUp = $replies->create($operator, $ticket, 'Уточнение');
    $this->travel(2)->minutes();
    app()->call([new DeliverTelegramMessage($followUp->id), 'handle']);
    app()->call([new DeliverTelegramMessage($cancelled->id), 'handle']);
    expect($ticket->refresh()->first_operator_replied_at?->toDateTimeString())->toBe('2026-10-03 12:07:00');
    Livewire::test(OperatorDashboard::class)->assertViewHas('statistics', fn (array $statistics): bool => $statistics['average_operator_response_seconds'] === 420.0 && $statistics['operator_cancelled'] === 1
    );
    Http::assertSentCount(3);
    Queue::assertPushed(DeliverTelegramMessage::class, 3);
});

test('measures the first successful operator reply after retry and ignores later replies', function () {
    $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0));
    $operator = User::factory()->create();
    $ticket = Ticket::factory()->create();
    Queue::fake([DeliverTelegramMessage::class]);
    config()->set('telegram.api_base_url', 'https://telegram.example');
    config()->set('telegram.bot_token', 'test-token');
    Http::preventStrayRequests();
    Http::fake(['https://telegram.example/bottest-token/sendMessage' => Http::sequence()
        ->push(['ok' => false], 400)
        ->push(['ok' => true, 'result' => ['message_id' => 789]])
        ->push(['ok' => true, 'result' => ['message_id' => 790]])]);
    $replies = app(OperatorReplyService::class);
    $this->actingAs($operator);
    $this->travel(2)->minutes();
    $firstReply = $replies->create($operator, $ticket, 'Ответ с повторной доставкой');
    app()->call([new DeliverTelegramMessage($firstReply->id), 'handle']);
    expect($firstReply->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    expect($ticket->refresh()->first_operator_replied_at)->toBeNull();
    Livewire::test(OperatorDashboard::class)->assertViewHas('statistics', fn (array $statistics): bool => $statistics['average_operator_response_seconds'] === null);
    $this->travel(5)->minutes();

    app()->call([new DeliverTelegramMessage($firstReply->id), 'handle']);
    $followUp = $replies->create($operator, $ticket, 'Позднейшее уточнение');
    $this->travel(5)->minutes();
    app()->call([new DeliverTelegramMessage($followUp->id), 'handle']);

    expect($ticket->refresh()->first_operator_replied_at?->toDateTimeString())->toBe('2026-10-03 12:07:00');
    expect($firstReply->refresh()->delivered_at?->toDateTimeString())->toBe('2026-10-03 12:07:00');
    expect($followUp->refresh()->delivered_at?->toDateTimeString())->toBe('2026-10-03 12:12:00');
    Livewire::test(OperatorDashboard::class)->assertViewHas('statistics', fn (array $statistics): bool => $statistics['average_operator_response_seconds'] === 420.0);
    Http::assertSentCount(3);
    Queue::assertPushed(DeliverTelegramMessage::class, 2);
});

test('preserves operator instructions about passwords and sms codes', function () {
    Queue::fake();
    $ticket = Ticket::factory()->create();
    $body = 'Измените пароль в личном кабинете. Если код из смс не приходит, проверьте указанный телефон.';
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->set('replyBody', $body)
        ->call('sendReply')
        ->assertHasNoErrors();

    $reply = Message::query()->sole();
    expect($reply->body)->toBe($body)
        ->and($reply->sensitive_data_redacted)->toBeFalse()
        ->and($reply->redaction_types)->toBeNull()
        ->and(app(TelegramMessagePresentation::class)->present($reply)->text)->toContain($body);
    Queue::assertPushed(DeliverTelegramMessage::class, fn (DeliverTelegramMessage $job): bool => $job->messageId === $reply->id);
});

test('does not create an operator reply for a closed ticket', function () {
    $operator = User::factory()->create();
    $ticket = Ticket::factory()->closed()->create();

    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->assertHasErrors(['ticket']);

    expect(Message::query()->count())->toBe(0);
});

test('validates Unicode operator replies against the rendered message budget', function (int $extraCharacters) {
    Queue::fake();
    $operator = User::factory()->create();
    $participant = TelegramParticipant::factory()->create();
    $ticket = Ticket::factory()->for($participant, 'participant')->create();
    Message::factory()->for($participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Inbound,
        'author' => MessageAuthor::Participant,
        'body' => 'Исходный вопрос.',
    ]);
    $overhead = mb_strlen("Ответ оператора по обращению #{$ticket->id}\n\n\n\nВаш вопрос: «Исходный вопрос.»", 'UTF-8');
    $body = str_repeat('🙂', TelegramOutboundMessage::MaxTextLength - $overhead + $extraCharacters);
    $this->actingAs($operator);

    $component = Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->set('replyBody', $body)
        ->call('sendReply');

    if ($extraCharacters > 0) {
        $component->assertHasErrors(['replyBody'])->assertSee('Ответ слишком длинный');
        expect(Message::query()->where('author', MessageAuthor::Operator)->count())->toBe(0)
            ->and($ticket->refresh()->first_operator_replied_at)->toBeNull();
        Queue::assertNothingPushed();

        return;
    }

    $component->assertHasNoErrors();
    $reply = Message::query()->where('author', MessageAuthor::Operator)->sole();
    $outbound = app(TelegramMessagePresentation::class)->present($reply);
    expect(mb_strlen($outbound->text, 'UTF-8'))->toBe(TelegramOutboundMessage::MaxTextLength);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
})->with(['at limit' => [0], 'over limit' => [1]]);

test('pending and failed operator replies block the next reply until explicit cancellation', function (DeliveryStatus $status) {
    Queue::fake();
    $ticket = Ticket::factory()->create();
    $operator = User::factory()->create();
    $reply = app(OperatorReplyService::class)->create($operator, $ticket, 'Первый ответ');
    $reply->update(['delivery_status' => $status]);
    $this->actingAs($operator);

    $panel = Livewire::test(OperatorDashboard::class)->call('selectTicket', $ticket->id)
        ->assertViewHas('hasUnfinishedReply', true)
        ->assertSee('Отменить доставку')
        ->set('replyBody', 'Второй ответ')
        ->call('sendReply')->assertHasErrors('replyBody')
        ->assertSet('replyBody', 'Второй ответ');
    $this->assertDatabaseCount('messages', 1);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);

    $panel->call('cancelDelivery', $reply->id)->assertHasNoErrors()
        ->assertViewHas('hasUnfinishedReply', false)
        ->call('sendReply')->assertHasNoErrors();

    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Cancelled);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $this->assertDatabaseCount('messages', 2);
    Queue::assertPushed(DeliverTelegramMessage::class, 2);
})->with(['pending' => DeliveryStatus::Pending, 'failed' => DeliveryStatus::Failed]);

test('closed tickets allow retry or cancellation of existing replies without reopening', function (string $action) {
    Queue::fake();
    $ticket = Ticket::factory()->create();
    $operator = User::factory()->create();
    $reply = app(OperatorReplyService::class)->create($operator, $ticket, 'Ответ до закрытия');
    $reply->update(['delivery_status' => DeliveryStatus::Failed]);
    app(TicketLifecycleService::class)->closeManually($ticket);
    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)->call('selectFilter', 'closed')->call('selectTicket', $ticket->id)
        ->assertSee(['Повторить отправку', 'Отменить доставку'])
        ->call($action, $reply->id)->assertHasNoErrors();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
    expect($reply->refresh()->delivery_status)->toBe($action === 'retryDelivery' ? DeliveryStatus::Pending : DeliveryStatus::Cancelled);
    Queue::assertPushed(DeliverTelegramMessage::class, $action === 'retryDelivery' ? 2 : 1);
})->with(['retry' => 'retryDelivery', 'cancel' => 'cancelDelivery']);
