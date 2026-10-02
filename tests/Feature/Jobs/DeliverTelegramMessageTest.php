<?php

use App\Data\TelegramOutboundMessage;
use App\Data\TelegramSentMessage;
use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Exceptions\TelegramDeliveryException;
use App\Jobs\AutoCloseTicket;
use App\Jobs\DeliverTelegramMessage;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use App\Services\TelegramBotClient;
use App\Services\TelegramMessagePresentation;
use App\Services\TicketLifecycleService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;

uses(LazilyRefreshDatabase::class);

test('never sends a legacy oversized operator reply or changes the ticket to waiting', function () {
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

test('moves an open ticket to waiting for the user after delivering an operator reply', function () {
    Queue::fake();
    config()->set('support.ticket_auto_close_hours', 12);
    $participant = TelegramParticipant::factory()->create();
    $ticket = Ticket::factory()->for($participant, 'participant')->create();
    $message = Message::factory()->for($participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andReturn(new TelegramSentMessage(789));

    deliver($message, $client);

    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Sent)
        ->and($ticket->refresh()->status->value)->toBe('waiting_for_user')
        ->and($ticket->waiting_since)->not->toBeNull();

    Queue::assertPushed(AutoCloseTicket::class, fn (AutoCloseTicket $job): bool => $job->ticketId === $ticket->id
        && $job->delay?->getTimestamp() === now()->addHours(12)->getTimestamp());
});

test('keeps an open ticket open when delivery of an operator reply fails', function () {
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
