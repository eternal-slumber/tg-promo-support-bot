<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Exceptions\TelegramDeliveryException;
use App\Jobs\DeliverTelegramMessage;
use App\Livewire\OperatorDashboard;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TelegramBotClient;
use App\Services\TelegramMessagePresentation;
use App\Services\TicketLifecycleService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

test('another operator can cancel a permanently rejected reply and close the ticket', function () {
    Queue::fake();
    $ticket = Ticket::factory()->create();
    $reply = cancellableOperatorReply($ticket, ['delivery_status' => DeliveryStatus::Pending]);
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andThrow(new TelegramDeliveryException('telegram_request_rejected', false));
    (new DeliverTelegramMessage($reply->id))->handle($client, app(TelegramMessagePresentation::class), app(TicketLifecycleService::class));
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->assertSee('Отменить доставку')
        ->call('closeTicket')
        ->assertHasErrors('ticket')
        ->call('cancelDelivery', $reply->id)
        ->assertHasNoErrors()
        ->assertSee('Доставка: Отменено')
        ->assertDontSee('Отменить доставку')
        ->call('closeTicket')
        ->assertHasNoErrors();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
    expect($reply->refresh()->delivery_status->value)->toBe('cancelled');
    expect($reply->last_delivery_error)->toBe('telegram_request_rejected');
    Queue::assertNothingPushed();
});

test('cancelling a failed reply permits a replacement while stale delivery jobs do nothing', function () {
    Queue::fake();
    $ticket = Ticket::factory()->create();
    $reply = cancellableOperatorReply($ticket, ['delivery_attempts' => 1]);
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->call('cancelDelivery', $reply->id)
        ->set('replyBody', 'Исправленный ответ')
        ->call('sendReply')
        ->assertHasNoErrors();

    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldNotReceive('sendMessage');
    (new DeliverTelegramMessage($reply->id))->handle($client, app(TelegramMessagePresentation::class), app(TicketLifecycleService::class));

    expect($reply->refresh()->delivery_status->value)->toBe('cancelled');
    expect($reply->delivery_attempts)->toBe(1);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $replacement = Message::query()->where('id', '!=', $reply->id)->sole();
    expect($replacement->body)->toBe('Исправленный ответ');
    expect($replacement->delivery_status)->toBe(DeliveryStatus::Pending);
    Queue::assertPushed(DeliverTelegramMessage::class, fn (DeliverTelegramMessage $job): bool => $job->messageId === $replacement->id);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
});

test('refuses cancellation of terminal replies', function (DeliveryStatus $status) {
    Queue::fake();
    $ticket = Ticket::factory()->create();
    $reply = cancellableOperatorReply($ticket, ['delivery_status' => $status]);
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->call('cancelDelivery', $reply->id)
        ->assertHasErrors('ticket');

    expect($reply->refresh()->delivery_status)->toBe($status);
    Queue::assertNothingPushed();
})->with(['cancelled' => [DeliveryStatus::Cancelled], 'sent' => [DeliveryStatus::Sent]]);

test('an operator can cancel an orphaned pending reply and send a replacement', function () {
    Queue::fake();
    $ticket = Ticket::factory()->create();
    $reply = cancellableOperatorReply($ticket, ['delivery_status' => DeliveryStatus::Pending, 'delivery_attempts' => 3, 'last_delivery_error' => null]);
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->assertSee('Отменить доставку')
        ->assertDontSee('Повторить отправку')
        ->call('closeTicket')
        ->assertHasErrors('ticket')
        ->call('cancelDelivery', $reply->id)
        ->assertHasNoErrors()
        ->set('replyBody', 'Новый ответ')
        ->call('sendReply')
        ->assertHasNoErrors();

    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Cancelled);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $replacement = Message::query()->whereKeyNot($reply->id)->sole();
    expect($replacement->body)->toBe('Новый ответ');
    Queue::assertPushed(DeliverTelegramMessage::class, fn (DeliverTelegramMessage $job): bool => $job->messageId === $replacement->id);
});

test('refuses cancellation outside the selected ticket', function () {
    Queue::fake();
    $selectedTicket = Ticket::factory()->create();
    $reply = cancellableOperatorReply(Ticket::factory()->create());
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $selectedTicket->id)
        ->call('cancelDelivery', $reply->id)
        ->assertNotFound();

    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    Queue::assertNothingPushed();
});

test('refuses cancellation of non-operator or inbound messages', function (array $attributes) {
    Queue::fake();
    $ticket = Ticket::factory()->create();
    $reply = cancellableOperatorReply($ticket, $attributes);
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->call('cancelDelivery', $reply->id)
        ->assertNotFound();

    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    Queue::assertNothingPushed();
})->with([
    'bot' => [['author' => MessageAuthor::Bot]],
    'inbound' => [['direction' => MessageDirection::Inbound]],
]);

test('refuses cancellation on a closed ticket', function () {
    Queue::fake();
    $ticket = Ticket::factory()->closed()->create();
    $reply = cancellableOperatorReply($ticket);
    $this->actingAs(User::factory()->create());

    Livewire::test(OperatorDashboard::class)
        ->call('selectFilter', 'closed')
        ->call('selectTicket', $ticket->id)
        ->assertDontSee('Отменить доставку')
        ->call('cancelDelivery', $reply->id)
        ->assertHasErrors('ticket');

    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    Queue::assertNothingPushed();
});

test('requires authentication to manage a failed delivery', function (string $action) {
    $ticket = Ticket::factory()->create();
    $reply = cancellableOperatorReply($ticket);

    Livewire::test(OperatorDashboard::class)
        ->set('selectedTicketId', $ticket->id)
        ->call($action, $reply->id)
        ->assertForbidden();

    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
})->with(['cancelDelivery', 'retryDelivery']);

/** @param array<string, mixed> $attributes */
function cancellableOperatorReply(Ticket $ticket, array $attributes = []): Message
{
    return Message::factory()->for($ticket)->for(User::factory(), 'operator')->create(array_merge([
        'participant_id' => $ticket->participant_id,
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Failed,
        'last_delivery_error' => 'telegram_request_rejected',
    ], $attributes));
}
