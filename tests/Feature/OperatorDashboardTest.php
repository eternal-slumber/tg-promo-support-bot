<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Jobs\DeliverTelegramMessage;
use App\Livewire\OperatorDashboard;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

test('shows active ticket queue and escaped chronological conversation history', function () {
    $operator = User::factory()->create();
    $participant = TelegramParticipant::factory()->create();
    $openTicket = Ticket::factory()->for($participant, 'participant')->create(['escalation_reason' => 'participant_specific']);
    $waitingTicket = Ticket::factory()->waitingForUser()->create();
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
        ->assertSee(["#{$openTicket->id}", "#{$waitingTicket->id}"])
        ->assertDontSee("#{$closedTicket->id}")
        ->call('selectTicket', $openTicket->id)
        ->assertSee(['<script>alert(1)</script> [REDACTED_PAYMENT_CARD]', 'Вопрос передан оператору.', 'Проверяем статус.', 'failed'])
        ->assertDontSeeHtml('<script>alert(1)</script>');

    expect($component->html())->toMatch('/REDACTED_PAYMENT_CARD.*Вопрос передан оператору.*Проверяем статус/s');
});

test('allows an operator to close an active ticket and removes it from the queue', function () {
    $operator = User::factory()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->call('closeTicket')
        ->assertSet('selectedTicketId', null)
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

test('sorts tickets with newest first', function () {
    $operator = User::factory()->create();
    $olderTicket = Ticket::factory()->create(['created_at' => now()->subMinute()]);
    $newerTicket = Ticket::factory()->create(['created_at' => now()]);

    $this->actingAs($operator);

    $component = Livewire::test(OperatorDashboard::class);

    expect($component->html())->toMatch("/#{$newerTicket->id}.*#{$olderTicket->id}/s");
});

test('allows an operator to view closed ticket history without actions', function () {
    $operator = User::factory()->create();
    $participant = TelegramParticipant::factory()->create();
    $ticket = Ticket::factory()->for($participant, 'participant')->closed()->create([
        'close_reason' => TicketCloseReason::AutoClosed,
    ]);
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
            'auto_closed',
            'Обращение закрыто и доступно только для просмотра.',
        ])
        ->assertDontSee('Ответ участнику')
        ->assertDontSee('Закрыть обращение');
});

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
    $message = Message::factory()->for($participant, 'participant')->for($ticket)->for($operator, 'operator')->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Failed,
        'last_delivery_error' => 'telegram_request_rejected',
    ]);

    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->assertSee('telegram_request_rejected')
        ->call('retryDelivery', $message->id);

    Queue::assertPushed(DeliverTelegramMessage::class, fn ($job): bool => $job->messageId === $message->id);
    expect($ticket->refresh()->status->value)->toBe('open');
});
