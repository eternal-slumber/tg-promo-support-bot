<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
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

test('persists a sanitized pending operator reply and dispatches delivery after commit', function () {
    Queue::fake();
    $operator = User::factory()->create();
    $participant = TelegramParticipant::factory()->create();
    $ticket = Ticket::factory()->for($participant, 'participant')->create();

    $this->actingAs($operator);

    Livewire::test(OperatorDashboard::class)
        ->call('selectTicket', $ticket->id)
        ->set('replyBody', 'Переведите на карту 2200 1234 5678 9012')
        ->call('sendReply')
        ->assertHasNoErrors()
        ->assertSet('replyBody', '');

    $reply = Message::query()->sole();

    expect($reply->author)->toBe(MessageAuthor::Operator)
        ->and($reply->direction)->toBe(MessageDirection::Outbound)
        ->and($reply->delivery_status)->toBe(DeliveryStatus::Pending)
        ->and($reply->body)->toBe('Переведите на карту [REDACTED_PAYMENT_CARD]')
        ->and($reply->sensitive_data_redacted)->toBeTrue()
        ->and($reply->redaction_types)->toBe(['payment_card'])
        ->and($reply->operator_id)->toBe($operator->id)
        ->and($ticket->refresh()->first_operator_replied_at)->not->toBeNull();

    Queue::assertPushed(DeliverTelegramMessage::class, fn ($job): bool => $job->messageId === $reply->id && $job->afterCommit === true);
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
